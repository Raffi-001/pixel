<?php

declare(strict_types=1);

use App\Enums\CreditTransactionType;
use App\Jobs\SubmitGenerationJob;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    fakePixelforge();
    Queue::fake();
});

it('creates one generation and one hold for a double-clicked submit', function (): void {
    $user = User::factory()->withBalance(1_000_000_000)->create();
    $request = ['prompt' => 'A cat', 'size' => '1024x1024', 'quality' => 'low', 'reference_images' => [], 'submission_key' => '7d1d2a52-0e2f-4b8b-9c3e-1d2f3a4b5c6d'];

    $this->actingAs($user)->post(route('generations.store'), $request)->assertRedirect();
    $this->actingAs($user)->post(route('generations.store'), $request)->assertRedirect();

    expect($user->generations()->count())->toBe(1)
        ->and(CreditTransaction::query()->where('type', CreditTransactionType::Hold)->count())->toBe(1);

    Queue::assertPushed(SubmitGenerationJob::class, 1);
});

it('treats a new submission key as a new generation', function (): void {
    $user = User::factory()->withBalance(1_000_000_000)->create();
    $request = ['prompt' => 'A cat', 'size' => '1024x1024', 'quality' => 'low', 'reference_images' => []];

    $this->actingAs($user)->post(route('generations.store'), [...$request, 'submission_key' => '7d1d2a52-0e2f-4b8b-9c3e-1d2f3a4b5c6d']);
    $this->actingAs($user)->post(route('generations.store'), [...$request, 'submission_key' => '8e2e3b63-1f30-4c9c-8d4f-2e3f4b5c6d7e']);

    expect($user->generations()->count())->toBe(2);
});

it('scopes submission keys to the user', function (): void {
    $request = ['prompt' => 'A cat', 'size' => '1024x1024', 'quality' => 'low', 'reference_images' => [], 'submission_key' => '7d1d2a52-0e2f-4b8b-9c3e-1d2f3a4b5c6d'];

    $this->actingAs(User::factory()->withBalance(1_000_000_000)->create())->post(route('generations.store'), $request);
    $this->actingAs(User::factory()->withBalance(1_000_000_000)->create())->post(route('generations.store'), $request);

    expect(Generation::query()->count())->toBe(2);
});

it('retries a failed generation only once', function (): void {
    $user = User::factory()->withBalance(1_000_000_000)->create();
    $failed = Generation::factory()->for($user)->failed()->create();

    $this->actingAs($user)->post(route('generations.retry', $failed));
    $this->actingAs($user)->post(route('generations.retry', $failed));

    expect($failed->retries()->count())->toBe(1)
        ->and(CreditTransaction::query()->where('type', CreditTransactionType::Hold)->count())->toBe(1);

    $this->actingAs($user)
        ->get(route('generations.index', ['status' => 'failed']))
        ->assertInertia(fn (Assert $page): Assert => $page->where('generations.data.0.was_retried', true));
});
