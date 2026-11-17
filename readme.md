# Pixel

Image generation on top of [Pixelforge](https://pixelforge.promptedit.dev/docs): users write a prompt, see what it can cost, and pay Pixelforge's exact cost plus a markup once it finishes. Failed generations are free.

**Live:** https://pixel.rulr.dev

| Account | Email | Password |
|---|---|---|
| User | `demo@example.com` | `password` |
| Admin | `admin@example.com` | `password` (also opens `/admin`) |

The login page lists both accounts with copy buttons.

## What's in it

- **Generate**: prompt, size, quality and up to 4 reference image URLs, with a live price range before you submit.
- **History**: every generation with its live status, estimated and actual price, and the image. Recent generations sit under the prompt box; the full history has filters.
- **Credits**: a balance, a fake "Buy credits" button, and a ledger of every change to the balance.
- **Admin** (Filament):
  - **Spend report**: credit spend, provider cost and profit per UTC day and per user per day, checked against Pixelforge's `GET /v1/usage`.
  - **Reconciliation**: one day compared generation by generation with Pixelforge, every difference explained.
  - **Users**: balances, total spend, granting credits, the admin flag.
  - **Generations**: every generation, read-only.
  - **Settings**: markup, sign-up bonus, credit packs, per-user concurrency, a pause switch.

Stack: Laravel 13, Inertia 3, Vue 3 and Filament 5, on [Nuno Maduro's strict starter kit](https://github.com/nunomaduro/laravel-starter-kit-inertia-vue) (PHPStan at max level, 100% type coverage, 100% code coverage, Rector, Pint). Business logic lives in single-purpose classes in `app/Actions`.

## How a generation flows

```
Submit ─► CreateGeneration ─────► hold the maximum price ─► SubmitGenerationJob (our queue)
              (idempotent per submission key)                    │  waits for: 3 per account, N per user,
                                                                 │  50 req/min, daily quota
                                                                 ▼
                                                     POST /v1/generations (Idempotency-Key = our id)
                                                                 │
                    webhook ─► VerifyPixelforgeSignature ─► HandlePixelforgeWebhook ─┐
           poll (every min) ─► SyncPendingGenerations ─────────────────────────────┤
                                                                                   ▼
                                                                    ApplyPixelforgeGeneration
                                                     statuses only move forward; on the final status:
                                                     FinalizeGeneration ─► release the hold, charge the actual cost
```

## Decisions

The brief leaves several details open on purpose. These are the choices I made and why.

### Money

- **Unit: integer nanodollars** (1 USD = 10⁹). That's the precision Pixelforge reports costs in (`"0.046125000"`). Every amount is an exact integer from the API to the ledger, and the maths uses `BcMath\Number`, so nothing passes through a float.
- **Markup:** added once to a generation's total cost and **rounded up to the next nanodollar**. The rounding is always in the operator's favour and never more than $0.000000001 per generation. I don't round to cents: a 2-cent minimum on a $0.03 image would be a ~60% overcharge.
- **Display:** amounts show up to 6 decimals (`$0.065684`); the exact value is in the tooltip and the admin panel.
- **The estimate is a range, from Pixelforge's published rules:**
  - output and image tokens are exact;
  - text tokens are `ceil(characters / 4)` ±15%;
  - reasoning tokens fall within the quality's minimum and maximum.

  Rates come from `GET /v1/pricing` (cached for 5 minutes), never hardcoded.
- **Hold the maximum:** submitting holds the most the generation can cost. When it finishes the hold is released, and the **actual** cost is charged only on success. So a user can never be charged more than they could afford, and failures are free.
- **Actual cost:** the generation's own `usage` × its own `rates`, which Pixelforge fixes when the generation is created, so a later price change doesn't affect it.
- **A ledger, not just a balance:** every change (purchase, bonus, hold, release, charge) is a `credit_transactions` row with the balance after it. A unique index on `(generation_id, type)` makes a second charge for the same generation impossible at the database level.
- **Markup is snapshotted** on each generation, so changing it in Settings only affects new generations.

### Correctness under retries and duplicates

- **Submitting is idempotent.** The form sends a one-time `submission_key`, so a double-click, a resent request or a second tab returns the first generation instead of holding credits twice. Each failed generation can be retried once; retrying it again returns the existing retry.
- **Sending to Pixelforge is idempotent.** Our generation id is the `Idempotency-Key`, so resending after a timeout or a lost response can't create or bill a second generation.
- **Webhooks:**
  - The HMAC-SHA256 signature is checked against the raw body, in constant time.
  - Timestamps older than 5 minutes are rejected.
  - Each event id is stored once, in the same transaction that applies it. If applying fails, the event isn't marked as seen and Pixelforge's retry is processed.
- **Statuses only move forward** (waiting → queued → processing → succeeded/failed). A late `processing` after `succeeded` is ignored, and money moves only on the single transition into a final status.
- **A webhook can arrive before our POST returns.** We create our record before calling Pixelforge and match webhooks on our own id in `metadata`.

### Webhooks *and* polling

Webhooks are the primary path. Pixelforge doesn't guarantee delivery, though, so `pixelforge:sync` runs every minute and polls generations that haven't updated for over a minute, at most 10 per run. When webhooks work it sends almost no requests. Both paths go through the same idempotent `ApplyPixelforgeGeneration`.

### Pixelforge's limits

Pixelforge's limits apply to the whole account (3 generations in flight, 60 requests/min, 500 generations/UTC day), so with many users they're hit routinely. Nothing fails because Pixelforge is busy:

- **Our queue waits instead.** Rate-limit and concurrency rejections, and lost connections, put the job back in the queue for `Retry-After` seconds. We also check the 3-in-flight and 50/min limits ourselves before calling. After 30 minutes of waiting, the generation fails for free.
- **Fairness:** one user can have at most 2 generations running (an admin setting), so nobody blocks everyone else. Waiting cards say why: "2 ahead in line" or "Waiting for your other generations to finish".
- **Daily limit:** when Pixelforge reports it, we pause until 00:00 UTC. New submissions are refused with the resume time, waiting generations fail for free, the Generate page shows a banner, and an admin can resume early.
- **No automatic retries of failed generations.** `content_policy_violation`, `overloaded`, `generation_failed` and `internal_error` all fail for free with a clear message and a Retry button. The user decides whether to spend again.
- **Unexpected errors** (e.g. a database hiccup) are retried with backoff, which is safe because of the idempotency key. A generation Pixelforge has already accepted is never failed locally; polling settles it.

### Reporting and reconciliation

- **A generation counts on the UTC day it completed**, as in `GET /v1/usage`, not the day it was created. Only successes have a cost.
- **Use one API key per environment.** Each key has its own webhook secret and its own `/v1/usage`, so the deployed app's numbers can match Pixelforge exactly.
- **The spend report** compares each day's provider cost with `/v1/usage`. **Reconciliation** (click a day) pages through `GET /v1/generations` and classifies every difference:
  - billed but unknown to us;
  - cost mismatch;
  - billed, but we marked it failed and didn't charge;
  - our record is behind (with a Re-sync button);
  - recorded by us but not billed that day.

### Product

- **Images** are Pixelforge's signed URLs, which expire after 24 hours. Expired ones show "Image link expired" (the brief allows this).
- **New users** get $1 of credits, configurable. "Buy credits" offers configurable packs and takes no payment.
- **The admin panel** has no separate login: admins sign in through the app.
- **Live status** comes from Inertia polling every 3 seconds, only while something is in flight.

## Running locally

Requires PHP 8.5, Composer, Bun, and a Pixelforge API key (create a separate key for local development).

```bash
composer install && bun install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
bun run build
```

In `.env`, set `PIXELFORGE_API_KEY`, and `PIXELFORGE_WEBHOOK_SECRET` from the same key. Then run the app with a queue worker and the scheduler:

```bash
composer dev                  # server, queue worker, logs, Vite
php artisan schedule:work     # polling fallback for missed webhooks
```

For webhooks, expose the app with a tunnel (`expose share http://localhost:8000`) and set the key's webhook URL to `https://<tunnel>/webhooks/pixelforge`.

Prompt keywords to try: `[fail]`, `[slow]`, `[duplicate]`, `[blocked]`, `[overloaded]`, `[succeed]`.

## Production

- **Queue worker:** `php artisan queue:work`, kept alive by Supervisor or similar.
- **Scheduler:** a cron entry running `php artisan schedule:run` every minute.
- **Webhook:** set the production key's webhook URL to `https://<domain>/webhooks/pixelforge`.

## Tests

```bash
composer test          # lint, PHPStan (max), type coverage (100%), tests with 100% coverage
```

The tests fake Pixelforge (`tests/Pest.php` has pricing, generation and signed-webhook helpers) and cover the money paths end to end: holds, exact charges, duplicate and out-of-order webhooks, a webhook before the POST returns, every rejection type, limits and fairness, the daily pause, reconciliation and the admin pages.

## What I'd do next

- Copy finished images to our own storage so history outlives Pixelforge's 24-hour links.
- Push live status over websockets (Reverb) instead of polling.
- Notify users when a generation finishes while they're on another page.
- Show margin by quality and size in the spend report.
