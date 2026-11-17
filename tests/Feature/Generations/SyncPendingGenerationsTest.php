<?php

declare(strict_types=1);

use App\Enums\GenerationStatus;
use App\Models\Generation;
use Illuminate\Support\Facades\Http;

it('polls generations whose webhooks have not arrived', function (): void {
    $stale = Generation::factory()->submitted(GenerationStatus::Processing)->create(['provider_id' => 'gen_STALE', 'updated_at' => now()->subMinutes(5)]);
    $fresh = Generation::factory()->submitted(GenerationStatus::Processing)->create(['provider_id' => 'gen_FRESH']);

    fakePixelforge(['*/v1/generations/gen_STALE' => Http::response(pixelforgeGeneration($stale, 'succeeded'))]);

    $this->artisan('pixelforge:sync')->expectsOutputToContain('Checked 1 generation(s)')->assertSuccessful();

    expect($stale->fresh()?->status)->toBe(GenerationStatus::Succeeded)
        ->and($fresh->fresh()?->status)->toBe(GenerationStatus::Processing);
});

it('stops polling when Pixelforge rate limits us', function (): void {
    Generation::factory()->count(2)->submitted()->create(['updated_at' => now()->subMinutes(5)]);
    fakePixelforge(['*/v1/generations/*' => Http::response(['error' => ['code' => 'rate_limit_exceeded', 'message' => 'Slow down.']], 429, ['Retry-After' => '30'])]);

    $this->artisan('pixelforge:sync')->assertSuccessful();

    Http::assertSentCount(1);
});

it('keeps polling other generations when one cannot be fetched', function (): void {
    Generation::factory()->count(2)->submitted()->create(['updated_at' => now()->subMinutes(5)]);
    fakePixelforge(['*/v1/generations/*' => Http::response(['error' => ['code' => 'not_found', 'message' => 'No such generation.']], 404)]);

    $this->artisan('pixelforge:sync')->assertSuccessful();

    Http::assertSentCount(2);
});
