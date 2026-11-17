<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Actions\FetchProviderUsage;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The UTC date range picked in the spend report's filters, defaulting to the last 30 days.
 */
final readonly class ReportRange
{
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
    ) {}

    /**
     * @param  array<string, mixed>|null  $filters
     */
    public static function fromFilters(?array $filters): self
    {
        $to = self::parse($filters['to'] ?? null) ?? CarbonImmutable::now('UTC')->startOfDay();
        $from = self::parse($filters['from'] ?? null) ?? $to->subDays(29);

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        if ($from->diffInDays($to) >= FetchProviderUsage::MAX_DAYS) {
            $from = $to->subDays(FetchProviderUsage::MAX_DAYS - 1);
        }

        return new self($from, $to);
    }

    private static function parse(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, 'UTC')->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}
