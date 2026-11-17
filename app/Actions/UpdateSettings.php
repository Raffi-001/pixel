<?php

declare(strict_types=1);

namespace App\Actions;

use App\Services\AppSettings;
use Illuminate\Support\Facades\DB;

final readonly class UpdateSettings
{
    public function __construct(private AppSettings $settings) {}

    /**
     * @param  list<int>  $creditPacks
     */
    public function handle(int $markupBps, int $signupBonusNano, array $creditPacks, bool $acceptingGenerations): void
    {
        $packs = array_values(array_unique(array_filter($creditPacks, fn (int $amount): bool => $amount > 0)));
        sort($packs);

        DB::transaction(function () use ($markupBps, $signupBonusNano, $packs, $acceptingGenerations): void {
            $this->settings->set('markup_bps', max(0, $markupBps));
            $this->settings->set('signup_bonus_nano', max(0, $signupBonusNano));
            $this->settings->set('credit_packs', $packs);
            $this->settings->set('accepting_generations', $acceptingGenerations);
        });
    }
}
