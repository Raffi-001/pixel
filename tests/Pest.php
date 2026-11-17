<?php

declare(strict_types=1);

use App\Models\Generation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        Str::createRandomStringsNormally();
        Str::createUuidsNormally();
        Http::preventStrayRequests();
        Process::preventStrayProcesses();
        Sleep::fake();

        config([
            'services.pixelforge.api_key' => 'pf_test_key',
            'services.pixelforge.webhook_secret' => 'whsec_test',
        ]);

        $this->freezeTime();
    })
    ->in('Browser', 'Feature', 'Unit');

expect()->extend('toBeOne', fn () => $this->toBe(1));

/**
 * Pixelforge's published pricing, as returned by GET /v1/pricing.
 *
 * @return array<string, mixed>
 */
function pixelforgePricing(): array
{
    return [
        'object' => 'pricing',
        'currency' => 'usd',
        'unit' => 'per_1m_tokens',
        'rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'tokens' => [
            'characters_per_text_token' => 4,
            'text_token_variance' => 0.15,
            'per_reference_image' => 765,
            'output' => [
                'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
            ],
            'reasoning' => [
                'low' => ['min' => 0, 'max' => 500],
                'medium' => ['min' => 200, 'max' => 2000],
                'high' => ['min' => 1000, 'max' => 8000],
            ],
        ],
    ];
}

/**
 * Fakes Pixelforge: pricing is always available, plus any extra routes given.
 *
 * @param  array<string, mixed>  $routes
 */
function fakePixelforge(array $routes = []): void
{
    Http::fake([
        'pixelforge.promptedit.dev/v1/pricing' => Http::response(pixelforgePricing()),
        ...$routes,
    ]);
}

/**
 * A Pixelforge generation object for one of our generations.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pixelforgeGeneration(Generation $generation, string $status, array $overrides = []): array
{
    $succeeded = $status === 'succeeded';

    return array_replace([
        'id' => $generation->provider_id ?? 'gen_TEST'.mb_strtoupper(mb_substr(md5($generation->id), 0, 20)),
        'object' => 'generation',
        'status' => $status,
        'prompt' => $generation->prompt,
        'size' => $generation->size->value,
        'quality' => $generation->quality->value,
        'reference_images' => $generation->reference_images,
        'metadata' => ['generation_id' => $generation->id, 'user_id' => $generation->user_id],
        'image_url' => $succeeded ? 'https://pixelforge.promptedit.dev/images/test.png?signature=abc' : null,
        'image_url_expires_at' => $succeeded ? '2026-11-18T12:00:00Z' : null,
        'usage' => $succeeded ? [
            'input_text_tokens' => 12,
            'input_image_tokens' => 0,
            'output_tokens' => 1100,
            'reasoning_tokens' => 1500,
            'total_tokens' => 2612,
        ] : null,
        'rates' => pixelforgePricing()['rates'],
        'error' => $status === 'failed' ? ['code' => 'generation_failed', 'message' => 'The model could not produce an image.'] : null,
        'created_at' => '2026-11-17T12:00:00Z',
        'started_at' => in_array($status, ['processing', 'succeeded', 'failed'], true) ? '2026-11-17T12:00:02Z' : null,
        'completed_at' => in_array($status, ['succeeded', 'failed'], true) ? '2026-11-17T12:00:20Z' : null,
    ], $overrides);
}

/**
 * Sends a webhook signed the way Pixelforge signs them.
 *
 * @param  array<string, mixed>  $object
 */
function sendPixelforgeWebhook(string $type, array $object, ?string $eventId = null, ?int $timestamp = null, string $secret = 'whsec_test'): TestResponse
{
    $payload = json_encode([
        'id' => $eventId ?? 'evt_'.Str::random(20),
        'object' => 'event',
        'type' => $type,
        'created_at' => now()->toIso8601String(),
        'data' => ['object' => $object],
    ], JSON_THROW_ON_ERROR);

    $timestamp ??= now()->getTimestamp();
    $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

    return test()->call('POST', route('webhooks.pixelforge'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1={$signature}",
    ], $payload);
}
