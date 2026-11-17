<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\CreditTransactionType;
use App\Models\CreditTransaction;
use App\Models\User;
use App\Services\Money;

/**
 * A fake purchase: no payment is taken.
 */
final readonly class PurchaseCredits
{
    public function __construct(private RecordCreditTransaction $recordTransaction) {}

    public function handle(User $user, int $dollars): CreditTransaction
    {
        $amount = $dollars * Money::NANOS_PER_DOLLAR;

        return $this->recordTransaction->handle($user, CreditTransactionType::Purchase, $amount, 'Bought '.Money::format($amount).' of credits');
    }
}
