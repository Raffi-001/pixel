<?php

declare(strict_types=1);

use App\Actions\CreateGeneration;
use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Enums\GenerationStatus;
use App\Filament\Pages\Settings;
use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use App\Models\User;
use App\Services\PixelforgeCapacity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 20:48:00', 'UTC'));
});

it('pauses until midnight UTC when Pixelforge reports its daily limit', function (): void {
    $generation = Generation::factory()->create();
    fakePixelforge(['*/v1/generations' => Http::response(['error' => ['code' => 'daily_limit_exceeded', 'message' => 'Quota used.']], 429)]);

    app()->call(new SubmitGenerationJob($generation)->handle(...));

    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Failed)
        ->error_code->toBe('daily_limit_exceeded')
        ->error_message->toContain('resumes at 00:00 UTC (3 hours 12 minutes from now)')
        ->and(resolve(PixelforgeCapacity::class)->pausedUntil()?->toIso8601String())->toBe('2026-11-18T00:00:00+00:00');
});

it('fails waiting generations for free without calling Pixelforge while paused', function (): void {
    resolve(PixelforgeCapacity::class)->pauseUntilNextUtcDay();
    $generation = Generation::factory()->create();
    Http::fake();

    app()->call(new SubmitGenerationJob($generation)->handle(...));

    expect($generation->fresh()?->error_code)->toBe('daily_limit_exceeded');
    Http::assertNothingSent();
});

it('refuses new generations while paused and tells the page', function (): void {
    resolve(PixelforgeCapacity::class)->pauseUntilNextUtcDay();
    $user = User::factory()->withBalance(1_000_000_000)->create();

    expect(fn (): Generation => resolve(CreateGeneration::class)->handle($user, 'A cat', GenerationSize::Square, GenerationQuality::Low))
        ->toThrow(ValidationException::class, 'daily capacity is used up');

    fakePixelforge();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('capacity.paused_until', '2026-11-18T00:00:00+00:00'));
});

it('resumes on its own at midnight UTC', function (): void {
    $capacity = resolve(PixelforgeCapacity::class);
    $capacity->pauseUntilNextUtcDay();

    $this->travelTo(CarbonImmutable::parse('2026-11-18 00:00:01', 'UTC'));

    expect($capacity->isPaused())->toBeFalse()
        ->and($capacity->pausedMessage())->toContain('resumes at 00:00 UTC');
});

it('lets an admin resume early', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    resolve(PixelforgeCapacity::class)->pauseUntilNextUtcDay();

    Livewire::test(Settings::class)
        ->assertActionVisible('resumeGenerating')
        ->callAction('resumeGenerating')
        ->assertNotified('Generating resumed');

    expect(resolve(PixelforgeCapacity::class)->isPaused())->toBeFalse();

    Livewire::test(Settings::class)->assertActionHidden('resumeGenerating');
});
