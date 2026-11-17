<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\CreditTransactionType;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The only place a balance changes: every change is a ledger row, and the balance is kept next to it.
 * The ledger's unique (generation, type) index makes a second hold, release or charge for a generation impossible.
 */
final readonly class RecordCreditTransaction
{
    public function handle(User $user, CreditTransactionType $type, int $amountNano, string $description, ?Generation $generation = null): CreditTransaction
    {
        return DB::transaction(function () use ($user, $type, $amountNano, $description, $generation): CreditTransaction {
            /** @var User $locked */
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $balance = $locked->balance_nano + $amountNano;

            $locked->forceFill(['balance_nano' => $balance])->save();
            $user->forceFill(['balance_nano' => $balance])->syncOriginalAttribute('balance_nano');

            return CreditTransaction::query()->create([
                'user_id' => $locked->id,
                'generation_id' => $generation?->id,
                'type' => $type,
                'amount_nano' => $amountNano,
                'balance_after_nano' => $balance,
                'description' => $description,
            ]);
        });
    }
}
