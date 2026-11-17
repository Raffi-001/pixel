<?php

declare(strict_types=1);

use App\Actions\DescribeQueuePositions;
use App\Actions\UpdateSettings;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use App\Models\User;
use App\Services\AppSettings;
use App\Services\PixelforgeCapacity;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Http;

it('keeps a user from taking every Pixelforge slot', function (): void {
    $user = User::factory()->create();
    Generation::factory()->for($user)->count(2)->submitted(GenerationStatus::Processing)->create();
    $generation = Generation::factory()->for($user)->create();
    fakePixelforge();

    $queueJob = Mockery::mock(Job::class)->shouldIgnoreMissing();
    $job = new SubmitGenerationJob($generation);
    $job->setJob($queueJob);

    app()->call($job->handle(...));

    $queueJob->shouldHaveReceived('release')->with(5);
    Http::assertNothingSent();
});

it('lets another user use the free slot', function (): void {
    Generation::factory()->count(2)->submitted(GenerationStatus::Processing)->create(['user_id' => User::factory()->create()->id]);
    $generation = Generation::factory()->create();
    fakePixelforge(['*/v1/generations' => Http::response(pixelforgeGeneration($generation, 'queued'), 202)]);

    app()->call(new SubmitGenerationJob($generation)->handle(...));

    expect($generation->fresh()?->status)->toBe(GenerationStatus::Queued);
});

it('explains to each user why their generation is waiting', function (): void {
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    Generation::factory()->for($alice)->count(2)->submitted(GenerationStatus::Processing)->create();
    $aliceWaiting = Generation::factory()->for($alice)->create(['created_at' => now()->subMinutes(3)]);
    $bobFirst = Generation::factory()->for($bob)->create(['created_at' => now()->subMinutes(2)]);
    $bobSecond = Generation::factory()->for($bob)->create(['created_at' => now()->subMinute()]);
    $done = Generation::factory()->for($bob)->succeeded()->create();

    $generations = collect([$aliceWaiting, $bobFirst, $bobSecond, $done]);
    resolve(DescribeQueuePositions::class)->handle($generations);

    expect($aliceWaiting->getAttribute('waiting_reason'))->toBe('Waiting for your other generations to finish')
        ->and($bobFirst->getAttribute('queue_position'))->toBe(2)
        ->and($bobFirst->getAttribute('waiting_reason'))->toBe('1 ahead in line')
        ->and($bobSecond->getAttribute('waiting_reason'))->toBe('2 ahead in line')
        ->and($done->getAttributes())->not->toHaveKey('waiting_reason');

    resolve(DescribeQueuePositions::class)->handle(collect([$aliceWaiting->fresh()]));
});

it('says when a generation is next in line or paused', function (): void {
    $generation = Generation::factory()->create();

    resolve(DescribeQueuePositions::class)->handle(collect([$generation]));
    expect($generation->getAttribute('waiting_reason'))->toBe('Next in line');

    resolve(PixelforgeCapacity::class)->pauseUntilNextUtcDay();
    resolve(DescribeQueuePositions::class)->handle(collect([$generation]));

    expect($generation->getAttribute('waiting_reason'))->toBe('Paused until 00:00 UTC (Pixelforge daily limit)');
});

it('shows the waiting reason on the cards', function (): void {
    $user = User::factory()->create();
    Generation::factory()->for($user)->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('generations.data.0.queue_position', 1)
            ->where('generations.data.0.waiting_reason', 'Next in line'));
});

it("keeps the per-user limit between 1 and Pixelforge's limit", function (): void {
    resolve(UpdateSettings::class)->handle(2500, 0, [5], true, 9);
    expect(resolve(AppSettings::class)->maxInFlightPerUser())->toBe(3);

    resolve(UpdateSettings::class)->handle(2500, 0, [5], true, 0);
    expect(resolve(AppSettings::class)->maxInFlightPerUser())->toBe(1);
});
