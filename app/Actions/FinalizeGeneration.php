<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\CreditTransactionType;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Money;

/**
 * Settles the money for a generation that just reached a final status:
 * the hold is always released, and only a success is charged, at its actual cost.
 * Callers must hold a lock on the generation and have checked the transition is allowed.
 */
final readonly class FinalizeGeneration
{
    public function __construct(private RecordCreditTransaction $recordTransaction) {}

    public function handle(Generation $generation): void
    {
        $user = $generation->loadMissing('user')->user;

        $this->recordTransaction->handle($user, CreditTransactionType::HoldRelease, $generation->held_nano, 'Hold released', $generation);

        if ($generation->status === GenerationStatus::Succeeded && $generation->charged_nano !== null) {
            $this->recordTransaction->handle(
                $user,
                CreditTransactionType::Charge,
                -$generation->charged_nano,
                'Generation cost '.Money::format($generation->charged_nano),
                $generation,
            );
        }
    }
}
