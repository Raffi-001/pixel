<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\CreditTransactionType;
use App\Models\User;
use App\Services\AppSettings;
use App\Services\Money;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

final readonly class CreateUser
{
    public function __construct(
        private RecordCreditTransaction $recordTransaction,
        private AppSettings $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(array $attributes, #[SensitiveParameter] string $password): User
    {
        return DB::transaction(function () use ($attributes, $password): User {
            $user = User::query()->create([
                ...$attributes,
                'password' => $password,
            ]);

            $bonus = $this->settings->signupBonusNano();

            if ($bonus > 0) {
                $this->recordTransaction->handle($user, CreditTransactionType::Bonus, $bonus, 'Welcome bonus of '.Money::format($bonus));
            }

            event(new Registered($user));

            return $user;
        });
    }
}
