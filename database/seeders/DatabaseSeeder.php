<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\RecordCreditTransaction;
use App\Enums\CreditTransactionType;
use App\Models\User;
use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(RecordCreditTransaction $recordTransaction): void
    {
        $accounts = [
            ['name' => 'Demo User', 'email' => 'demo@example.com', 'is_admin' => false],
            ['name' => 'Admin', 'email' => 'admin@example.com', 'is_admin' => true],
        ];

        foreach ($accounts as $account) {
            if (User::query()->where('email', $account['email'])->exists()) {
                continue;
            }

            $user = User::factory()->withoutTwoFactor()->create([...$account, 'password' => 'password']);

            $recordTransaction->handle($user, CreditTransactionType::Bonus, 10_000_000_000, 'Demo credits');
        }
    }
}
