<?php

declare(strict_types=1);

use App\Enums\CreditTransactionType;
use App\Models\User;
use App\Services\AppSettings;
use Inertia\Testing\AssertableInertia as Assert;

it('adds a credit pack to the balance', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('credits.store'), ['amount' => 10])
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', 'Added $10.00 to your balance.');

    expect($user->fresh()?->balance_nano)->toBe(10_000_000_000)
        ->and($user->creditTransactions()->sole()->type)->toBe(CreditTransactionType::Purchase);
});

it('only sells the configured packs', function (): void {
    resolve(AppSettings::class)->set('credit_packs', [20]);

    $this->actingAs(User::factory()->create())
        ->post(route('credits.store'), ['amount' => 10])
        ->assertSessionHasErrors('amount');
});

it('shows the ledger', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)->post(route('credits.store'), ['amount' => 5]);

    $this->actingAs($user)
        ->get(route('credits.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('credits/Index')
            ->has('transactions.data', 1)
            ->where('transactions.data.0.amount', '$5.00')
            ->where('transactions.data.0.balance_after', '$5.00')
            ->where('credits.balance', '$5.00')
            ->where('credits.packs', [5, 10, 25]));
});
