<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Remembers when Pixelforge's daily generation quota (500 per UTC day, shared by every user) has run out,
 * so we stop sending requests that can only fail and tell users when generating resumes.
 */
final class PixelforgeCapacity
{
    private const string KEY = 'pixelforge.daily-limit-until';

    public function pausedUntil(): ?CarbonImmutable
    {
        $until = Cache::get(self::KEY);

        if (! is_string($until)) {
            return null;
        }

        $until = CarbonImmutable::parse($until);

        return $until->isFuture() ? $until : null;
    }

    public function isPaused(): bool
    {
        return $this->pausedUntil() instanceof CarbonImmutable;
    }

    /**
     * The quota resets at midnight UTC.
     */
    public function pauseUntilNextUtcDay(): CarbonImmutable
    {
        $until = CarbonImmutable::now('UTC')->addDay()->startOfDay();

        Cache::put(self::KEY, $until->toIso8601String(), $until);

        return $until;
    }

    public function resume(): void
    {
        Cache::forget(self::KEY);
    }

    public function pausedMessage(): string
    {
        $until = $this->pausedUntil() ?? CarbonImmutable::now('UTC')->addDay()->startOfDay();

        return "Pixelforge's daily capacity is used up. Generating resumes at 00:00 UTC (".$until->diffForHumans(syntax: CarbonImmutable::DIFF_RELATIVE_TO_NOW, parts: 2).'). You were not charged.';
    }
}
