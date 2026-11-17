<?php

declare(strict_types=1);

use App\Actions\FailGeneration;
use App\Actions\SubmitGeneration;
use App\Actions\VerifyPixelforgeSignature;
use App\Enums\CreditTransactionType;
use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Enums\GenerationStatus;
use App\Filament\Resources\Generations\GenerationResource;
use App\Filament\Resources\Users\UserResource;
use App\Jobs\SubmitGenerationJob;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\PixelforgePricing;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

it('never fails a generation that already finished', function (): void {
    $generation = Generation::factory()->succeeded()->create();

    resolve(FailGeneration::class)->handle($generation, 'queue_timeout', 'Too late.');

    expect($generation->fresh()?->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->creditTransactions()->count())->toBe(0);
});

it('does not resubmit a generation that was already sent', function (): void {
    Http::fake();

    resolve(SubmitGeneration::class)->handle(Generation::factory()->submitted()->create());

    Http::assertNothingSent();
});

it('rejects every signature when no secret is configured', function (): void {
    $timestamp = (string) now()->getTimestamp();

    expect(resolve(VerifyPixelforgeSignature::class)->handle('{}', "t={$timestamp},v1=".hash_hmac('sha256', $timestamp.'.{}', ''), ''))->toBeFalse()
        ->and(resolve(VerifyPixelforgeSignature::class)->handle('{}', 'garbage', 'whsec_test'))->toBeFalse();
});

it("keeps our own submission rate below Pixelforge's limit", function (): void {
    Http::fake();
    $generation = Generation::factory()->create();

    for ($attempt = 0; $attempt < SubmitGenerationJob::MAX_SUBMISSIONS_PER_MINUTE; $attempt++) {
        RateLimiter::increment('pixelforge-submissions');
    }

    $queueJob = Mockery::mock(Job::class)->shouldIgnoreMissing();
    $job = new SubmitGenerationJob($generation);
    $job->setJob($queueJob);

    app()->call($job->handle(...));

    $queueJob->shouldHaveReceived('release');
    Http::assertNothingSent();
});

it('labels every enum case', function (): void {
    expect(array_map(fn (CreditTransactionType $type): string => $type->label(), CreditTransactionType::cases()))
        ->toBe(['Purchase', 'Bonus', 'Hold', 'Hold released', 'Charge'])
        ->and(GenerationQuality::High->label())->toBe('High')
        ->and(GenerationSize::Portrait->label())->toBe('Portrait (1024×1536)');
});

it('links ledger rows to their user and generation', function (): void {
    $generation = Generation::factory()->create();
    $transaction = CreditTransaction::factory()->for($generation->user)->create(['generation_id' => $generation->id]);

    expect($transaction->user->is($generation->user))->toBeTrue()
        ->and($transaction->generation?->is($generation))->toBeTrue();
});

it('does not let admins create or delete records that carry money', function (): void {
    expect(UserResource::canCreate())->toBeFalse()
        ->and(UserResource::canDelete(User::factory()->create()))->toBeFalse()
        ->and(GenerationResource::canCreate())->toBeFalse();
});

it('treats a missing text token variance as exact', function (): void {
    $data = pixelforgePricing();
    unset($data['tokens']['text_token_variance']);

    expect(PixelforgePricing::fromArray($data)->textTokenVariance())->toBe('0');
});

it('refuses incomplete pricing', function (): void {
    $data = pixelforgePricing();
    unset($data['rates']['output_tokens']);

    PixelforgePricing::fromArray($data);
})->throws(UnexpectedValueException::class, 'output_tokens');
