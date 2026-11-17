<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\CreditTransactionType;
use App\Models\CreditTransaction;
use App\Models\User;
use App\Services\Money;

/**
 * Lets an operator add (or, with a negative amount, remove) credits by hand.
 */
final readonly class GrantCredits
{
    public function __construct(private RecordCreditTransaction $recordTransaction) {}

    public function handle(User $user, int $amountNano, string $reason): CreditTransaction
    {
        $description = ($amountNano >= 0 ? 'Granted ' : 'Removed ').Money::format(abs($amountNano)).' by an admin';

        return $this->recordTransaction->handle(
            $user,
            CreditTransactionType::Bonus,
            $amountNano,
            mb_trim($reason) === '' ? $description : $description.': '.mb_trim($reason),
        );
    }
}
