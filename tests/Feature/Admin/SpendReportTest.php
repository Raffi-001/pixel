<?php

declare(strict_types=1);

use App\Actions\BuildSpendReport;
use App\Actions\FetchProviderUsage;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\DailySpendTable;
use App\Filament\Widgets\ReportRange;
use App\Filament\Widgets\SpendTotals;
use App\Filament\Widgets\UserSpendTable;
use App\Models\Generation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 15:00:00', 'UTC'));
});

it('groups spend, cost and profit by the UTC day each generation completed', function (): void {
    $alice = User::factory()->create(['name' => 'Alice']);
    $bob = User::factory()->create(['name' => 'Bob']);

    Generation::factory()->for($alice)->succeeded(60_000_000)->create(['created_at' => '2026-11-15 23:59:50', 'completed_at' => '2026-11-16 00:00:10']);
    Generation::factory()->for($alice)->succeeded(40_000_000)->create(['completed_at' => '2026-11-16 12:00:00']);
    Generation::factory()->for($bob)->succeeded(10_000_001)->create(['completed_at' => '2026-11-17 01:00:00']);
    Generation::factory()->for($bob)->failed()->create(['completed_at' => '2026-11-17 02:00:00']);
    Generation::factory()->for($bob)->succeeded(99_000_000)->create(['completed_at' => '2026-11-10 12:00:00']);

    $report = resolve(BuildSpendReport::class)->handle(CarbonImmutable::parse('2026-11-15'), CarbonImmutable::parse('2026-11-17'));

    expect($report['days'])->toBe([
        ['date' => '2026-11-17', 'generations' => 1, 'provider_cost_nano' => 10_000_001, 'charged_nano' => 12_500_002, 'profit_nano' => 2_500_001],
        ['date' => '2026-11-16', 'generations' => 2, 'provider_cost_nano' => 100_000_000, 'charged_nano' => 125_000_000, 'profit_nano' => 25_000_000],
        ['date' => '2026-11-15', 'generations' => 0, 'provider_cost_nano' => 0, 'charged_nano' => 0, 'profit_nano' => 0],
    ])->and($report['totals'])->toBe(['generations' => 3, 'provider_cost_nano' => 110_000_001, 'charged_nano' => 137_500_002, 'profit_nano' => 27_500_001])
        ->and(array_column($report['users'], 'name'))->toBe(['Bob', 'Alice']);
});

it('reads what Pixelforge billed per day', function (): void {
    fakePixelforge(['*/v1/usage*' => Http::response(['object' => 'usage', 'data' => [
        ['date' => '2026-11-16', 'generations' => 2, 'cost_usd' => '0.100000000'],
        ['date' => '2026-11-17', 'generations' => 1, 'cost_usd' => '0.010000001'],
        'garbage',
    ]])]);

    expect(resolve(FetchProviderUsage::class)->handle(CarbonImmutable::parse('2026-11-16'), CarbonImmutable::parse('2026-11-17')))->toBe([
        '2026-11-16' => ['generations' => 2, 'cost_nano' => 100_000_000],
        '2026-11-17' => ['generations' => 1, 'cost_nano' => 10_000_001],
    ]);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'start_date=2026-11-16&end_date=2026-11-17'));
});

it('reports usage as unavailable when Pixelforge cannot be reached', function (): void {
    fakePixelforge(['*/v1/usage*' => Http::response(['error' => ['code' => 'invalid_api_key', 'message' => 'Bad key.']], 401)]);

    expect(resolve(FetchProviderUsage::class)->handle(CarbonImmutable::parse('2026-11-16'), CarbonImmutable::parse('2026-11-17')))->toBeNull();
});

it('clamps the report range to what Pixelforge can report', function (): void {
    $range = ReportRange::fromFilters(['from' => '2026-01-01', 'to' => '2026-11-17']);
    expect($range->from->toDateString())->toBe('2026-08-18')
        ->and($range->to->toDateString())->toBe('2026-11-17');

    $swapped = ReportRange::fromFilters(['from' => '2026-11-17', 'to' => 'not a date']);
    expect($swapped->from->toDateString())->toBe('2026-11-17');

    $default = ReportRange::fromFilters(null);
    expect($default->from->toDateString())->toBe('2026-10-19')
        ->and($default->to->toDateString())->toBe('2026-11-17');

    $reversed = ReportRange::fromFilters(['from' => '2026-11-17', 'to' => '2026-11-10']);
    expect($reversed->from->toDateString())->toBe('2026-11-10');
});

it('lets admins, and only admins, open the panel', function (): void {
    $this->get('/admin')->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();

    fakePixelforge(['*/v1/usage*' => Http::response(['object' => 'usage', 'data' => []])]);

    $this->actingAs(User::factory()->admin()->create())->get('/admin')->assertOk()->assertSee('Spend report');
});

it('shows totals and whether provider costs match Pixelforge', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    Generation::factory()->succeeded(60_000_000)->create(['completed_at' => '2026-11-17 01:00:00']);

    fakePixelforge(['*/v1/usage*' => Http::response(['object' => 'usage', 'data' => [
        ['date' => '2026-11-17', 'generations' => 1, 'cost_usd' => '0.060000000'],
    ]])]);

    Livewire::test(SpendTotals::class)
        ->assertSee('$0.075')
        ->assertSee('Matches Pixelforge exactly')
        ->assertSee('20% of credit spend');

    Livewire::test(DailySpendTable::class)
        ->assertSee('2026-11-17')
        ->assertSee('$0.060000000');

    Livewire::test(UserSpendTable::class)
        ->assertSee('$0.075');

    Livewire::test(Dashboard::class)->assertOk();
});

it('flags a mismatch with Pixelforge', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    Generation::factory()->succeeded(60_000_000)->create(['completed_at' => '2026-11-17 01:00:00']);

    fakePixelforge(['*/v1/usage*' => Http::response(['object' => 'usage', 'data' => [
        ['date' => '2026-11-17', 'generations' => 1, 'cost_usd' => '0.070000000'],
    ]])]);

    Livewire::test(SpendTotals::class)->assertSee('Pixelforge reports $0.070000000');
});

it('says when Pixelforge usage is unavailable', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    fakePixelforge(['*/v1/usage*' => Http::response([], 500)]);

    Livewire::test(SpendTotals::class)->assertSee('Pixelforge usage unavailable')->assertSee('0% of credit spend');
});
