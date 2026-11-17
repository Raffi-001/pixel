<?php

declare(strict_types=1);

use App\Enums\CreditTransactionType;
use App\Enums\GenerationStatus;
use App\Filament\Pages\Settings;
use App\Filament\Resources\Generations\Pages\ListGenerations;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Generation;
use App\Models\User;
use App\Services\AppSettings;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
});

it('lists users with balance and spend, and grants credits', function (): void {
    $user = User::factory()->withBalance(2_500_000_000)->create(['name' => 'Spender']);
    Generation::factory()->for($user)->succeeded(60_000_000)->create();

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$user])
        ->assertSee('$2.50')
        ->assertSee('$0.075')
        ->callTableAction('grantCredits', $user, ['amount' => '1.25', 'reason' => 'Support'])
        ->assertHasNoTableActionErrors();

    expect($user->fresh()?->balance_nano)->toBe(3_750_000_000)
        ->and($user->creditTransactions()->sole())
        ->type->toBe(CreditTransactionType::Bonus)
        ->description->toBe('Granted $1.25 by an admin: Support');
});

it('can remove credits', function (): void {
    $user = User::factory()->withBalance(2_000_000_000)->create();

    Livewire::test(ListUsers::class)->callTableAction('grantCredits', $user, ['amount' => '-0.5', 'reason' => '']);

    expect($user->fresh()?->balance_nano)->toBe(1_500_000_000)
        ->and($user->creditTransactions()->sole()->description)->toBe('Removed $0.50 by an admin');
});

it('makes a user an admin', function (): void {
    $user = User::factory()->create();

    Livewire::test(EditUser::class, ['record' => $user->id])
        ->fillForm(['is_admin' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->fresh()?->is_admin)->toBeTrue();
});

it('lists every generation', function (): void {
    $generations = collect([
        Generation::factory()->succeeded()->create(),
        Generation::factory()->failed()->create(),
        Generation::factory()->create(),
        Generation::factory()->submitted()->create(),
        Generation::factory()->submitted(GenerationStatus::Processing)->create(),
    ]);

    Livewire::test(ListGenerations::class)
        ->assertCanSeeTableRecords($generations)
        ->filterTable('status', 'failed')
        ->assertCanSeeTableRecords($generations->where('status', GenerationStatus::Failed));
});

it('updates the settings', function (): void {
    Livewire::test(Settings::class)
        ->assertSchemaStateSet(['markup_percent' => '25', 'signup_bonus' => '1.000000000', 'credit_packs' => ['5', '10', '25'], 'accepting_generations' => true])
        ->fillForm([
            'markup_percent' => '12.5',
            'signup_bonus' => '2.50',
            'credit_packs' => ['50', '20', '20'],
            'accepting_generations' => false,
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Settings saved');

    $settings = resolve(AppSettings::class);

    expect($settings->markupBps())->toBe(1250)
        ->and($settings->signupBonusNano())->toBe(2_500_000_000)
        ->and($settings->creditPacks())->toBe([20, 50])
        ->and($settings->acceptingGenerations())->toBeFalse();
});
