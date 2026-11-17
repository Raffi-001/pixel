<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CreditTransactionType;
use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditTransaction>
 */
final class CreditTransactionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'generation_id' => null,
            'type' => CreditTransactionType::Purchase,
            'amount_nano' => 5_000_000_000,
            'balance_after_nano' => 5_000_000_000,
            'description' => 'Bought $5.00 of credits',
        ];
    }
}
