<?php

declare(strict_types=1);

use App\Enums\CreditTransactionType;
use App\Enums\GenerationStatus;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Testing\TestResponse;

/**
 * A generation that is running at Pixelforge, with $0.09 held from a $1.00 balance.
 */
function runningGeneration(GenerationStatus $status = GenerationStatus::Queued): Generation
{
    $user = User::factory()->withBalance(910_000_000)->create();
    $generation = Generation::factory()->for($user)->submitted($status)->create();
    $generation->creditTransactions()->create([
        'user_id' => $user->id,
        'type' => CreditTransactionType::Hold,
        'amount_nano' => -90_000_000,
        'balance_after_nano' => 910_000_000,
        'description' => 'Hold',
    ]);

    return $generation;
}

/** 12 text, 1100 output and 1500 reasoning tokens: $0.06281 at cost, $0.0785125 with the 25% markup. */
const PROVIDER_COST = 62_810_000;
const CHARGED = 78_512_500;

it('rejects requests without a valid signature', function (): void {
    $generation = runningGeneration();

    sendPixelforgeWebhook('generation.succeeded', pixelforgeGeneration($generation, 'succeeded'), secret: 'whsec_wrong')
        ->assertBadRequest();

    $this->postJson(route('webhooks.pixelforge'), ['id' => 'evt_1'])->assertBadRequest();

    expect($generation->fresh()?->status)->toBe(GenerationStatus::Queued)
        ->and(WebhookEvent::query()->count())->toBe(0);
});

it('rejects replays with an old timestamp', function (): void {
    $generation = runningGeneration();

    sendPixelforgeWebhook('generation.succeeded', pixelforgeGeneration($generation, 'succeeded'), timestamp: now()->subMinutes(6)->getTimestamp())
        ->assertBadRequest();

    expect($generation->fresh()?->status)->toBe(GenerationStatus::Queued);
});

it('charges the exact actual cost plus markup once a generation succeeds', function (): void {
    $generation = runningGeneration();

    sendPixelforgeWebhook('generation.succeeded', pixelforgeGeneration($generation, 'succeeded'))->assertNoContent();

    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Succeeded)
        ->provider_cost_nano->toBe(PROVIDER_COST)
        ->charged_nano->toBe(CHARGED)
        ->reasoning_tokens->toBe(1500)
        ->image_url->toContain('pixelforge.promptedit.dev/images')
        ->completed_at->toDateTimeString()->toBe('2026-11-17 12:00:20');

    expect($generation->user->fresh()?->balance_nano)->toBe(1_000_000_000 - CHARGED);
});

it('records an event delivered twice only once', function (): void {
    $generation = runningGeneration();
    $object = pixelforgeGeneration($generation, 'succeeded');

    sendPixelforgeWebhook('generation.succeeded', $object, eventId: 'evt_DUP')->assertNoContent();
    sendPixelforgeWebhook('generation.succeeded', $object, eventId: 'evt_DUP')->assertNoContent();

    expect(WebhookEvent::query()->count())->toBe(1)
        ->and(CreditTransaction::query()->where('type', CreditTransactionType::Charge)->count())->toBe(1)
        ->and($generation->user->fresh()?->balance_nano)->toBe(1_000_000_000 - CHARGED);
});

it('charges once even when the same outcome arrives in different events', function (): void {
    $generation = runningGeneration();
    $object = pixelforgeGeneration($generation, 'succeeded');

    sendPixelforgeWebhook('generation.succeeded', $object, eventId: 'evt_A')->assertNoContent();
    sendPixelforgeWebhook('generation.succeeded', $object, eventId: 'evt_B')->assertNoContent();

    expect(CreditTransaction::query()->where('type', CreditTransactionType::Charge)->count())->toBe(1)
        ->and(CreditTransaction::query()->where('type', CreditTransactionType::HoldRelease)->count())->toBe(1)
        ->and($generation->user->fresh()?->balance_nano)->toBe(1_000_000_000 - CHARGED);
});

it('never moves a generation back when events arrive out of order', function (): void {
    $generation = runningGeneration();

    sendPixelforgeWebhook('generation.succeeded', pixelforgeGeneration($generation, 'succeeded'))->assertNoContent();
    sendPixelforgeWebhook('generation.processing', pixelforgeGeneration($generation, 'processing'))->assertNoContent();

    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Succeeded)
        ->charged_nano->toBe(CHARGED);
});

it('moves through processing to succeeded', function (): void {
    $generation = runningGeneration();

    sendPixelforgeWebhook('generation.processing', pixelforgeGeneration($generation, 'processing'))->assertNoContent();
    expect($generation->fresh()?->status)->toBe(GenerationStatus::Processing)
        ->and($generation->user->fresh()?->balance_nano)->toBe(910_000_000);

    sendPixelforgeWebhook('generation.succeeded', pixelforgeGeneration($generation, 'succeeded'))->assertNoContent();
    expect($generation->fresh()?->status)->toBe(GenerationStatus::Succeeded);
});

it('makes failed generations free', function (): void {
    $generation = runningGeneration(GenerationStatus::Processing);

    sendPixelforgeWebhook('generation.failed', pixelforgeGeneration($generation, 'failed'))->assertNoContent();

    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Failed)
        ->error_code->toBe('generation_failed')
        ->charged_nano->toBeNull()
        ->and($generation->user->fresh()?->balance_nano)->toBe(1_000_000_000);
});

it('ignores a success arriving after a failure', function (): void {
    $generation = runningGeneration(GenerationStatus::Processing);

    sendPixelforgeWebhook('generation.failed', pixelforgeGeneration($generation, 'failed'))->assertNoContent();
    sendPixelforgeWebhook('generation.succeeded', pixelforgeGeneration($generation, 'succeeded'))->assertNoContent();

    expect($generation->fresh()?->status)->toBe(GenerationStatus::Failed)
        ->and($generation->user->fresh()?->balance_nano)->toBe(1_000_000_000);
});

it('handles a webhook that arrives before our create request has returned', function (): void {
    $user = User::factory()->withBalance(910_000_000)->create();
    $generation = Generation::factory()->for($user)->create(['provider_id' => null]);

    sendPixelforgeWebhook('generation.processing', pixelforgeGeneration($generation, 'processing', ['id' => 'gen_EARLY']))->assertNoContent();

    expect($generation->fresh())
        ->provider_id->toBe('gen_EARLY')
        ->status->toBe(GenerationStatus::Processing);
});

it('bills at the rates fixed on the generation, not the current ones', function (): void {
    $generation = runningGeneration();
    $rates = ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '80.00', 'reasoning_tokens' => '12.50'];

    sendPixelforgeWebhook('generation.succeeded', pixelforgeGeneration($generation, 'succeeded', ['rates' => $rates]))->assertNoContent();

    expect($generation->fresh())
        ->provider_cost_nano->toBe(PROVIDER_COST + 44_000_000)
        ->rates->toBe($rates);
});

it('acknowledges pings and events for unknown generations', function (): void {
    sendPixelforgeWebhook('ping', [])->assertNoContent();
    sendPixelforgeWebhook('generation.succeeded', ['id' => 'gen_UNKNOWN', 'status' => 'succeeded', 'metadata' => []])->assertNoContent();

    expect(WebhookEvent::query()->count())->toBe(1);
});

it('lets Pixelforge retry when a success arrives without usage', function (): void {
    $generation = runningGeneration();

    $this->withoutExceptionHandling();

    expect(fn (): TestResponse => sendPixelforgeWebhook('generation.succeeded', pixelforgeGeneration($generation, 'succeeded', ['usage' => null]), eventId: 'evt_BROKEN'))
        ->toThrow(UnexpectedValueException::class);

    expect(WebhookEvent::query()->count())->toBe(0)
        ->and($generation->fresh()?->status)->toBe(GenerationStatus::Queued);
});
