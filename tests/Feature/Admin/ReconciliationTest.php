<?php

declare(strict_types=1);

use App\Actions\ReconcileProviderDay;
use App\Enums\GenerationStatus;
use App\Filament\Pages\Reconciliation;
use App\Models\Generation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 15:00:00', 'UTC'));
});

/**
 * A Pixelforge generation object in the list, completed on 17 November.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function listed(Generation $generation, string $status, array $overrides = []): array
{
    return pixelforgeGeneration($generation, $status, ['created_at' => '2026-11-17T12:00:00Z', ...$overrides]);
}

/**
 * The scenario: one of each kind of difference, plus a match. 12 text, 1100 output and 1500 reasoning tokens cost $0.06281.
 *
 * @return array<string, Generation>
 */
function reconciliationScenario(): array
{
    $user = User::factory()->create(['email' => 'alice@example.com']);

    $generations = [
        'match' => Generation::factory()->for($user)->succeeded(62_810_000)->create(['provider_id' => 'gen_MATCH', 'completed_at' => '2026-11-17 12:00:20']),
        'cost' => Generation::factory()->for($user)->succeeded(60_000_000)->create(['provider_id' => 'gen_COST', 'completed_at' => '2026-11-17 12:00:20']),
        'failedHere' => Generation::factory()->for($user)->submitted()->failed('queue_timeout', 'Gave up.')->create(['provider_id' => 'gen_FAILEDHERE']),
        'behind' => Generation::factory()->for($user)->submitted(GenerationStatus::Processing)->create(['provider_id' => 'gen_BEHIND']),
        'notBilled' => Generation::factory()->for($user)->succeeded(10_000_000)->create(['provider_id' => 'gen_NOTBILLED', 'completed_at' => '2026-11-17 09:00:00']),
        'unlinked' => Generation::factory()->for($user)->create(['provider_id' => null]),
    ];

    $old = Generation::factory()->make(['id' => 'old']);

    fakePixelforge([
        '*/v1/generations?limit=100' => Http::response(['object' => 'list', 'has_more' => true, 'next_cursor' => 'page2', 'data' => [
            listed($generations['match'], 'succeeded'),
            listed($generations['cost'], 'succeeded'),
            listed($generations['failedHere'], 'succeeded'),
            listed($generations['behind'], 'succeeded'),
        ]]),
        '*/v1/generations?limit=100&cursor=page2' => Http::response(['object' => 'list', 'has_more' => true, 'next_cursor' => 'page3', 'data' => [
            listed($generations['unlinked'], 'succeeded', ['id' => 'gen_UNLINKED']),
            listed($old, 'succeeded', ['id' => 'gen_OLD', 'created_at' => '2026-11-15T12:00:00Z', 'metadata' => []]),
        ]]),
        '*/v1/generations/gen_BEHIND' => Http::response(listed($generations['behind'], 'succeeded')),
    ]);

    return $generations;
}

it('explains every difference with Pixelforge for a day', function (): void {
    reconciliationScenario();

    $report = resolve(ReconcileProviderDay::class)->handle(CarbonImmutable::parse('2026-11-17'));

    expect($report)
        ->available->toBeTrue()
        ->complete->toBeTrue()
        ->matched->toBe(1)
        ->pixelforge_cost_nano->toBe(5 * 62_810_000)
        ->our_cost_nano->toBe(62_810_000 + 60_000_000 + 10_000_000)
        ->and(array_column($report['rows'], 'verdict', 'provider_id'))->toBe([
            'gen_COST' => 'cost_mismatch',
            'gen_FAILEDHERE' => 'not_charged',
            'gen_BEHIND' => 'out_of_date',
            'gen_UNLINKED' => 'out_of_date',
            'gen_NOTBILLED' => 'not_billed',
        ]);

    Http::assertSentCount(2);
});

it('flags billed generations we have never seen', function (): void {
    $stranger = Generation::factory()->make(['id' => 'not-ours']);
    fakePixelforge(['*/v1/generations*' => Http::response(['has_more' => false, 'data' => [listed($stranger, 'succeeded', ['id' => 'gen_STRANGER', 'metadata' => []])]])]);

    $report = resolve(ReconcileProviderDay::class)->handle(CarbonImmutable::parse('2026-11-17'));

    expect($report['rows'][0])
        ->verdict->toBe('missing_locally')
        ->can_resync->toBeTrue()
        ->user->toBeNull();
});

it('says when the day could not be fully checked', function (): void {
    $generation = Generation::factory()->make(['id' => 'x']);
    fakePixelforge(['*/v1/generations*' => Http::response(['has_more' => true, 'next_cursor' => 'more', 'data' => [listed($generation, 'failed', ['id' => 'gen_X']), 'garbage']])]);

    expect(resolve(ReconcileProviderDay::class)->handle(CarbonImmutable::parse('2026-11-17'))['complete'])->toBeFalse();
    Http::assertSentCount(ReconcileProviderDay::MAX_PAGES);
});

it('reports Pixelforge being unreachable', function (): void {
    fakePixelforge(['*/v1/generations*' => Http::response([], 500)]);

    expect(resolve(ReconcileProviderDay::class)->handle(CarbonImmutable::parse('2026-11-17'))['available'])->toBeFalse();
});

it('describes every verdict', function (string $verdict, string $text): void {
    expect(ReconcileProviderDay::describe($verdict))->toContain($text);
})->with([
    ['missing_locally', 'no record'],
    ['cost_mismatch', 'different cost'],
    ['not_charged', 'did not charge'],
    ['out_of_date', 'not caught up'],
    ['not_billed', 'did not bill'],
    ['other', 'other'],
]);

it('shows the differences and re-syncs a generation that fell behind', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $generations = reconciliationScenario();

    Livewire::withQueryParams(['date' => '2026-11-17'])
        ->test(Reconciliation::class)
        ->assertSee('1 generation(s) match exactly, 5 need attention')
        ->assertSee('gen_NOTBILLED')
        ->assertSee('alice@example.com')
        ->callTableAction('resync', 'out_of_date|gen_BEHIND')
        ->assertNotified('Generation updated to Succeeded');

    expect($generations['behind']->fresh()?->status)->toBe(GenerationStatus::Succeeded);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/v1/generations/gen_BEHIND'));
});

it('reports when a re-sync finds nothing to update or Pixelforge fails', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $stranger = Generation::factory()->make(['id' => 'not-ours']);
    fakePixelforge([
        '*/v1/generations?limit=100' => Http::response(['has_more' => false, 'data' => [listed($stranger, 'succeeded', ['id' => 'gen_STRANGER', 'metadata' => []])]]),
        '*/v1/generations/gen_STRANGER' => Http::sequence()
            ->push(listed($stranger, 'succeeded', ['id' => 'gen_STRANGER', 'metadata' => []]))
            ->push(['error' => ['code' => 'not_found', 'message' => 'No such generation.']], 404),
    ]);

    $page = Livewire::test(Reconciliation::class)
        ->callTableAction('resync', 'missing_locally|gen_STRANGER')
        ->assertNotified('No local generation matches this Pixelforge id');

    $page->callTableAction('resync', 'missing_locally|gen_STRANGER')
        ->assertNotified('Pixelforge: No such generation.');
});

it('handles unreachable Pixelforge, odd dates and the future', function (string $date, string $expected): void {
    $this->actingAs(User::factory()->admin()->create());
    fakePixelforge(['*/v1/generations*' => Http::response([], 500)]);

    Livewire::withQueryParams(['date' => $date])
        ->test(Reconciliation::class)
        ->assertSee($expected);
})->with([
    'unreachable' => ['2026-11-17', 'could not be reached'],
    'not a date' => ['nonsense', 'could not be reached'],
    'future' => ['2027-01-01', 'could not be reached'],
]);

it('links each day of the spend report to its reconciliation', function (): void {
    expect(Reconciliation::getUrl(['date' => '2026-11-17']))->toEndWith('/admin/reconciliation?date=2026-11-17');
});

it('flags generations Pixelforge failed while we still show them running', function (): void {
    $running = Generation::factory()->submitted(GenerationStatus::Processing)->create(['provider_id' => 'gen_FAILEDTHERE']);
    $settled = Generation::factory()->failed()->create(['provider_id' => 'gen_SETTLED']);
    fakePixelforge(['*/v1/generations*' => Http::response(['has_more' => false, 'data' => [
        listed($running, 'failed'),
        listed($settled, 'failed'),
    ]])]);

    $report = resolve(ReconcileProviderDay::class)->handle(CarbonImmutable::parse('2026-11-17'));

    expect($report['rows'])->toHaveCount(1)
        ->and($report['rows'][0])
        ->provider_id->toBe('gen_FAILEDTHERE')
        ->verdict->toBe('out_of_date')
        ->pixelforge_cost_nano->toBeNull();
});

it('leaves out generations from other environments on the same Pixelforge account', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $ours = Generation::factory()->succeeded(62_810_000)->create(['provider_id' => 'gen_OURS', 'completed_at' => '2026-11-17 12:00:20']);
    $elsewhere = Generation::factory()->make(['id' => 'elsewhere']);
    fakePixelforge(['*/v1/generations*' => Http::response(['has_more' => false, 'data' => [
        listed($ours, 'succeeded', ['metadata' => ['generation_id' => $ours->id, 'app_url' => config('app.url')]]),
        listed($elsewhere, 'succeeded', ['id' => 'gen_LOCAL', 'metadata' => ['generation_id' => 'elsewhere', 'app_url' => 'http://localhost:8000/other']]),
        listed($elsewhere, 'failed', ['id' => 'gen_LOCALFAILED', 'metadata' => ['generation_id' => 'elsewhere-2', 'app_url' => 'http://localhost:8000/other']]),
    ]])]);

    $report = resolve(ReconcileProviderDay::class)->handle(CarbonImmutable::parse('2026-11-17'));

    expect($report)
        ->matched->toBe(1)
        ->rows->toBe([])
        ->pixelforge_cost_nano->toBe(62_810_000)
        ->other_environments->toBe(1)
        ->other_environments_cost_nano->toBe(62_810_000);

    Livewire::withQueryParams(['date' => '2026-11-17'])
        ->test(Reconciliation::class)
        ->assertSee('1 generation(s) ($0.062810000) from other environments on this Pixelforge account are left out.');
});
