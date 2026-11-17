<?php

declare(strict_types=1);

use App\Actions\CreateUser;
use App\Enums\CreditTransactionType;
use App\Services\AppSettings;

it('gives new users the configured welcome bonus', function (): void {
    $user = resolve(CreateUser::class)->handle(['name' => 'New', 'email' => 'new@example.com'], 'password');

    expect($user->fresh()?->balance_nano)->toBe(1_000_000_000)
        ->and($user->creditTransactions()->sole()->type)->toBe(CreditTransactionType::Bonus);
});

it('skips the bonus when it is set to zero', function (): void {
    resolve(AppSettings::class)->set('signup_bonus_nano', 0);

    $user = resolve(CreateUser::class)->handle(['name' => 'New', 'email' => 'new@example.com'], 'password');

    expect($user->fresh()?->balance_nano)->toBe(0)
        ->and($user->creditTransactions()->count())->toBe(0);
});
