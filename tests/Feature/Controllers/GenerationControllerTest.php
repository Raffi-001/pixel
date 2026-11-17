<?php

declare(strict_types=1);

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    fakePixelforge();
    Queue::fake();
});

it('shows the generator with recent generations', function (): void {
    $user = User::factory()->create();
    Generation::factory()->for($user)->count(9)->create();
    Generation::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('generations/Create')
            ->has('generations.data', 8)
            ->where('hasMore', true)
            ->has('sizes', 3)
            ->has('qualities', 3)
            ->where('credits.is_admin', false));
});

it('estimates the price range', function (): void {
    $this->actingAs(User::factory()->create())
        ->postJson(route('generations.estimate'), [
            'prompt' => '',
            'size' => '1024x1024',
            'quality' => 'low',
            'reference_images' => ['https://example.com/a.png'],
        ])
        ->assertOk()
        ->assertJson([
            'min_price' => '$0.023563',
            'max_price' => '$0.031375',
            'min_price_nano' => 23_562_500,
            'max_price_nano' => 31_375_000,
            'markup_percent' => '25',
            'tokens' => ['input_text' => '0', 'input_image' => '765', 'output' => '280', 'reasoning' => '0–500'],
        ]);
});

it('creates a generation', function (): void {
    $user = User::factory()->withBalance(1_000_000_000)->create();

    $this->actingAs($user)
        ->from(route('dashboard'))
        ->post(route('generations.store'), [
            'prompt' => 'A lighthouse at dawn',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => [],
        ])
        ->assertRedirect(route('dashboard'))
        ->assertInertiaFlash('toast.message', 'Generation queued.');

    expect($user->generations()->sole())
        ->prompt->toBe('A lighthouse at dawn')
        ->status->toBe(GenerationStatus::Pending);

    Queue::assertPushed(SubmitGenerationJob::class);
});

it('validates the request', function (): void {
    $this->actingAs(User::factory()->withBalance(1_000_000_000)->create())
        ->post(route('generations.store'), [
            'prompt' => '',
            'size' => '512x512',
            'quality' => 'ultra',
            'reference_images' => ['ftp://example.com/a.png', 'https://a.test/1', 'https://a.test/2', 'https://a.test/3', 'https://a.test/4'],
        ])
        ->assertSessionHasErrors(['prompt', 'size', 'quality', 'reference_images', 'reference_images.0']);
});

it('explains when the user cannot afford a generation', function (): void {
    $this->actingAs(User::factory()->create())
        ->post(route('generations.store'), ['prompt' => 'A cat', 'size' => '1024x1024', 'quality' => 'low', 'reference_images' => []])
        ->assertSessionHasErrors(['balance']);

    expect(Generation::query()->count())->toBe(0);
});

it('retries a failed generation as a new one', function (): void {
    $user = User::factory()->withBalance(1_000_000_000)->create();
    $failed = Generation::factory()->for($user)->failed()->create(['prompt' => 'Try again', 'reference_images' => ['https://example.com/a.png']]);

    $this->actingAs($user)
        ->post(route('generations.retry', $failed))
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', 'Retry queued.');

    expect($user->generations()->where('retry_of_id', $failed->id)->sole())
        ->prompt->toBe('Try again')
        ->reference_images->toBe(['https://example.com/a.png'])
        ->status->toBe(GenerationStatus::Pending);
});

it('only retries failed generations', function (): void {
    $user = User::factory()->withBalance(1_000_000_000)->create();
    $succeeded = Generation::factory()->for($user)->succeeded()->create();

    $this->actingAs($user)
        ->post(route('generations.retry', $succeeded))
        ->assertSessionHasErrors(['generation']);
});

it('does not let users retry other users generations', function (): void {
    $failed = Generation::factory()->failed()->create();

    $this->actingAs(User::factory()->withBalance(1_000_000_000)->create())
        ->post(route('generations.retry', $failed))
        ->assertNotFound();
});

it('lists the full history with a status filter', function (): void {
    $user = User::factory()->create();
    Generation::factory()->for($user)->succeeded()->create(['image_url_expires_at' => now()->subMinute()]);
    Generation::factory()->for($user)->failed()->count(2)->create();

    $this->actingAs($user)
        ->get(route('generations.index', ['status' => 'succeeded']))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('generations/Index')
            ->where('status', 'succeeded')
            ->has('generations.data', 1)
            ->where('generations.data.0.image_url', null)
            ->where('generations.data.0.image_expired', true)
            ->where('generations.data.0.charged', '$0.075')
            ->has('statuses', 3));
});

it('groups waiting, queued and processing generations as in progress', function (): void {
    $user = User::factory()->create();
    Generation::factory()->for($user)->create();
    Generation::factory()->for($user)->submitted()->create();
    Generation::factory()->for($user)->submitted(GenerationStatus::Processing)->create();
    Generation::factory()->for($user)->succeeded()->create();

    $this->actingAs($user)
        ->get(route('generations.index', ['status' => 'in_progress']))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('status', 'in_progress')
            ->has('generations.data', 3)
            ->where('statuses.0', ['value' => 'in_progress', 'label' => 'In progress']));

    $this->actingAs($user)
        ->get(route('generations.index', ['status' => 'nonsense']))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('status', null)
            ->has('generations.data', 4));
});
