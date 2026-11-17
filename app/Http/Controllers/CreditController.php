<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\PurchaseCredits;
use App\Http\Requests\PurchaseCreditsRequest;
use App\Models\CreditTransaction;
use App\Models\User;
use App\Services\Money;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final readonly class CreditController
{
    public function index(#[CurrentUser] User $user): Response
    {
        $transactions = $user->creditTransactions()
            ->latest()
            ->orderByDesc('id')
            ->paginate(50)
            ->through(fn (CreditTransaction $transaction): array => [
                'id' => $transaction->id,
                'generation_id' => $transaction->generation_id,
                'type' => $transaction->type->value,
                'type_label' => $transaction->type->label(),
                'description' => $transaction->description,
                'amount' => Money::format($transaction->amount_nano),
                'amount_exact' => Money::toDecimal($transaction->amount_nano),
                'is_credit' => $transaction->amount_nano >= 0,
                'balance_after' => Money::format($transaction->balance_after_nano),
                'created_at' => $transaction->created_at->toIso8601String(),
            ]);

        return Inertia::render('credits/Index', [
            'transactions' => $transactions,
        ]);
    }

    public function store(PurchaseCreditsRequest $request, #[CurrentUser] User $user, PurchaseCredits $action): RedirectResponse
    {
        $transaction = $action->handle($user, $request->integer('amount'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Added :amount to your balance.', ['amount' => Money::format($transaction->amount_nano)])]);

        return back();
    }
}
