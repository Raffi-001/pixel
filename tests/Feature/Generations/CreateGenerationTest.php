<?php

declare(strict_types=1);

use App\Actions\CreateGeneration;
use App\Enums\CreditTransactionType;
use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use App\Models\User;
use App\Services\AppSettings;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    fakePixelforge();
    Queue::fake();
});

it('holds the maximum possible price and queues the generation', function (): void {
    $user = User::factory()->withBalance(1_000_000_000)->create();

    $generation = resolve(CreateGeneration::class)->handle($user, str_repeat('a', 400), GenerationSize::Landscape, GenerationQuality::High, [
        'https://example.com/a.png',
        'https://example.com/b.png',
    ]);

    // The worked example from the Pixelforge docs: at most $0.373875 at cost, $0.46734375 with the 25% markup.
    expect($generation->status)->toBe(GenerationStatus::Pending)
        ->and($generation->estimated_max_nano)->toBe(467_343_750)
        ->and($generation->held_nano)->toBe(467_343_750)
        ->and($generation->markup_bps)->toBe(2500)
        ->and($user->fresh()?->balance_nano)->toBe(1_000_000_000 - 467_343_750);

    expect($generation->creditTransactions()->sole())
        ->type->toBe(CreditTransactionType::Hold)
        ->amount_nano->toBe(-467_343_750);

    Queue::assertPushed(SubmitGenerationJob::class, fn (SubmitGenerationJob $job): bool => $job->generation->is($generation));
});

it('refuses a generation the user cannot afford in the worst case', function (): void {
    $user = User::factory()->withBalance(80_000_000)->create();

    expect(fn (): Generation => resolve(CreateGeneration::class)->handle($user, 'A cat', GenerationSize::Square, GenerationQuality::Medium))
        ->toThrow(ValidationException::class, 'can cost up to $0.086269');

    expect(Generation::query()->count())->toBe(0)
        ->and($user->fresh()?->balance_nano)->toBe(80_000_000);

    Queue::assertNothingPushed();
});

it('refuses new generations while paused by an admin', function (): void {
    resolve(AppSettings::class)->set('accepting_generations', false);
    $user = User::factory()->withBalance(1_000_000_000)->create();

    expect(fn (): Generation => resolve(CreateGeneration::class)->handle($user, 'A cat', GenerationSize::Square, GenerationQuality::Low))
        ->toThrow(ValidationException::class, 'paused');
});

it('uses the markup configured at creation time', function (): void {
    resolve(AppSettings::class)->set('markup_bps', 5000);
    $user = User::factory()->withBalance(1_000_000_000)->create();

    $generation = resolve(CreateGeneration::class)->handle($user, 'A cat', GenerationSize::Square, GenerationQuality::Medium);

    // Medium square at most: 1100 output + 2000 reasoning + 3 text tokens (2 estimated, +15%) = $0.069015, +50%.
    expect($generation->markup_bps)->toBe(5000)
        ->and($generation->estimated_max_nano)->toBe(103_522_500);
});
