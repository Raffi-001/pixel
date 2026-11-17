<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Operator-editable settings, managed from the admin panel.
 */
final class AppSettings
{
    public const int DEFAULT_MARKUP_BPS = 2500;

    public const int DEFAULT_SIGNUP_BONUS_NANO = 1_000_000_000;

    /** @var list<int> */
    public const array DEFAULT_CREDIT_PACKS = [5, 10, 25];

    /** Pixelforge runs 3 generations at once for the whole account; this keeps one user from taking all of them. */
    public const int DEFAULT_MAX_IN_FLIGHT_PER_USER = 2;

    public function markupBps(): int
    {
        $value = $this->get('markup_bps');

        return is_int($value) ? $value : self::DEFAULT_MARKUP_BPS;
    }

    public function signupBonusNano(): int
    {
        $value = $this->get('signup_bonus_nano');

        return is_int($value) ? $value : self::DEFAULT_SIGNUP_BONUS_NANO;
    }

    /**
     * @return list<int> whole US dollar amounts users can buy
     */
    public function creditPacks(): array
    {
        $value = $this->get('credit_packs');

        if (! is_array($value) || $value === []) {
            return self::DEFAULT_CREDIT_PACKS;
        }

        return array_values(array_filter($value, is_int(...)));
    }

    public function maxInFlightPerUser(): int
    {
        $value = $this->get('max_in_flight_per_user');

        return is_int($value) && $value > 0 ? $value : self::DEFAULT_MAX_IN_FLIGHT_PER_USER;
    }

    public function acceptingGenerations(): bool
    {
        $value = $this->get('accepting_generations');

        return is_bool($value) ? $value : true;
    }

    public function set(string $key, mixed $value): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);

        Cache::forget('settings');
    }

    private function get(string $key): mixed
    {
        /** @var array<string, mixed> $settings */
        $settings = Cache::rememberForever('settings', fn (): array => Setting::query()->pluck('value', 'key')->all());

        return $settings[$key] ?? null;
    }
}
