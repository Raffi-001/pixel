<?php

declare(strict_types=1);

use App\Enums\CreditTransactionType;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A pending generation whose hold has already been taken from the user's balance.
 */
function pendingGeneration(): Generation
{
    $user = User::factory()->withBalance(1_000_000_000 - 90_000_000)->create();
    $generation = Generation::factory()->for($user)->create();
    $generation->creditTransactions()->create([
        'user_id' => $user->id,
        'type' => CreditTransactionType::Hold,
        'amount_nano' => -90_000_000,
        'balance_after_nano' => $user->balance_nano,
        'description' => 'Hold',
    ]);

    return $generation;
}

/**
 * Runs the job with a fake queue job attached, so we can see whether it was released back onto the queue.
 */
function runSubmitJob(Generation $generation): Job
{
    $queueJob = Mockery::mock(Job::class)->shouldIgnoreMissing();
    $job = new SubmitGenerationJob($generation);
    $job->setJob($queueJob);

    app()->call($job->handle(...));

    return $queueJob;
}

it('sends the generation with our id as idempotency key and metadata', function (): void {
    $generation = pendingGeneration();
    fakePixelforge(['*/v1/generations' => Http::response(pixelforgeGeneration($generation, 'queued', ['id' => 'gen_ABC']), 202)]);

    runSubmitJob($generation);

    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Queued)
        ->provider_id->toBe('gen_ABC')
        ->submitted_at->not->toBeNull();

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Idempotency-Key', $generation->id)
        && $request->hasHeader('Authorization', 'Bearer pf_test_key')
        && $request['metadata'] === ['generation_id' => $generation->id, 'user_id' => $generation->user_id, 'app_url' => config('app.url')]
        && ! array_key_exists('status', $request->data()));
});

it('waits in the queue when Pixelforge is at its concurrency limit', function (): void {
    $generation = pendingGeneration();
    fakePixelforge(['*/v1/generations' => Http::response(['error' => ['type' => 'rate_limit_error', 'code' => 'concurrency_limit_exceeded', 'message' => 'Too many.']], 429, ['Retry-After' => '5'])]);

    $queueJob = runSubmitJob($generation);

    $queueJob->shouldHaveReceived('release')->with(5);
    expect($generation->fresh()?->status)->toBe(GenerationStatus::Pending);
});

it('waits without calling Pixelforge while three generations are already in flight', function (): void {
    Generation::factory()->count(3)->submitted(GenerationStatus::Processing)->create();
    $generation = pendingGeneration();
    fakePixelforge();

    $queueJob = runSubmitJob($generation);

    $queueJob->shouldHaveReceived('release')->with(5);
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/generations'));
});

it('retries the same idempotent request when the connection fails', function (): void {
    $generation = pendingGeneration();
    fakePixelforge(['*/v1/generations' => fn (): never => throw new ConnectionException('timed out')]);

    $queueJob = runSubmitJob($generation);

    $queueJob->shouldHaveReceived('release')->with(10);
    expect($generation->fresh()?->status)->toBe(GenerationStatus::Pending);
});

it('fails for free when Pixelforge rejects the request', function (string $code, int $status, string $message): void {
    $generation = pendingGeneration();
    fakePixelforge(['*/v1/generations' => Http::response(['error' => ['type' => 'x', 'code' => $code, 'message' => 'Rejected.']], $status)]);

    runSubmitJob($generation);

    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Failed)
        ->error_code->toBe($code)
        ->error_message->toContain($message)
        ->charged_nano->toBeNull()
        ->and($generation->user->fresh()?->balance_nano)->toBe(1_000_000_000);
})->with([
    'content policy' => ['content_policy_violation', 400, 'content policy'],
    'overloaded' => ['overloaded', 503, 'overloaded'],
    'daily limit' => ['daily_limit_exceeded', 429, 'daily capacity is used up'],
    'validation' => ['validation_failed', 422, 'Rejected.'],
]);

it('does nothing for a generation that has already been submitted', function (): void {
    $generation = Generation::factory()->submitted()->create();
    fakePixelforge();

    runSubmitJob($generation);

    Http::assertNothingSent();
});

it('gives up for free after waiting too long', function (): void {
    $generation = pendingGeneration();
    $this->travel(31)->minutes();

    new SubmitGenerationJob($generation)->failed();

    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Failed)
        ->error_code->toBe('queue_timeout')
        ->and($generation->user->fresh()?->balance_nano)->toBe(1_000_000_000)
        ->and(new SubmitGenerationJob($generation)->retryUntil()->getTimestamp())->toBe($generation->created_at->addMinutes(30)->getTimestamp());
});

it('fails for free when sending keeps erroring', function (): void {
    $generation = pendingGeneration();

    new SubmitGenerationJob($generation)->failed(new RuntimeException('database is locked'));

    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Failed)
        ->error_code->toBe('submission_error')
        ->and(new SubmitGenerationJob($generation)->backoff())->toBe([5, 15, 30, 60]);
});

it('leaves a generation Pixelforge already accepted for polling to settle', function (): void {
    $generation = Generation::factory()->submitted()->create();

    new SubmitGenerationJob($generation)->failed(new RuntimeException('database is locked'));

    expect($generation->fresh()?->status)->toBe(GenerationStatus::Queued);
});

it('gives up quietly when the generation no longer exists', function (): void {
    $generation = Generation::factory()->create();
    $job = new SubmitGenerationJob($generation);
    $generation->delete();

    $job->failed();

    expect(Generation::query()->count())->toBe(0);
});
