<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\FailGeneration;
use App\Actions\SubmitGeneration;
use App\Enums\GenerationStatus;
use App\Exceptions\PixelforgeException;
use App\Models\Generation;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Our queue in front of Pixelforge. Its limits are per account (shared by every user), so a generation
 * waits here while Pixelforge is busy, instead of failing.
 */
#[MaxExceptions(5)]
#[Tries(0)]
final class SubmitGenerationJob implements ShouldQueue
{
    use Queueable;

    /** Pixelforge allows 3 queued or processing generations per account. */
    public const int MAX_IN_FLIGHT = 3;

    /** Pixelforge allows 60 requests per minute per account; keep a little headroom for polling. */
    public const int MAX_SUBMISSIONS_PER_MINUTE = 50;

    public const int WAIT_MINUTES = 30;

    public function __construct(public Generation $generation)
    {
        $this->afterCommit();
    }

    /**
     * Unexpected errors are retried: resending is safe because the idempotency key is our generation id.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 15, 30, 60];
    }

    public function retryUntil(): DateTimeInterface
    {
        return $this->generation->created_at->addMinutes(self::WAIT_MINUTES);
    }

    public function handle(SubmitGeneration $submit): void
    {
        $generation = $this->generation->refresh();

        if ($generation->status !== GenerationStatus::Pending) {
            return;
        }

        $inFlight = Generation::query()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->count();

        if ($inFlight >= self::MAX_IN_FLIGHT) {
            $this->release(5);

            return;
        }

        if (RateLimiter::tooManyAttempts('pixelforge-submissions', self::MAX_SUBMISSIONS_PER_MINUTE)) {
            $this->release(RateLimiter::availableIn('pixelforge-submissions') + 1);

            return;
        }

        RateLimiter::increment('pixelforge-submissions');

        try {
            $submit->handle($generation);
        } catch (PixelforgeException $pixelforgeException) {
            $this->release($pixelforgeException->retryAfterSeconds ?? 10);
        }
    }

    /**
     * We gave up. If Pixelforge never accepted the generation it fails for free and the user can retry.
     * If it did accept it, the outcome is Pixelforge's to decide, and polling will settle it.
     */
    public function failed(?Throwable $exception = null): void
    {
        $generation = $this->generation->fresh();

        if ($generation === null || $generation->provider_id !== null) {
            return;
        }

        $timedOut = $generation->created_at->addMinutes(self::WAIT_MINUTES)->isPast();

        resolve(FailGeneration::class)->handle(
            $generation,
            $timedOut ? 'queue_timeout' : 'submission_error',
            $timedOut
                ? 'Pixelforge stayed busy for too long, so this generation was not sent. You were not charged; please retry.'
                : 'Something went wrong while sending this generation to Pixelforge. You were not charged; please retry.',
        );
    }
}
