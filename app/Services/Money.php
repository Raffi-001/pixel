<?php

declare(strict_types=1);

namespace App\Services;

use BcMath\Number;
use InvalidArgumentException;

/**
 * All money is stored as integer nanodollars (1 USD = 1,000,000,000), the precision
 * Pixelforge reports costs in, so amounts are exact and never touch floating point.
 */
final class Money
{
    public const int NANOS_PER_DOLLAR = 1_000_000_000;

    public static function fromDecimal(string $dollars): int
    {
        return (int) self::number($dollars)->mul(self::NANOS_PER_DOLLAR)->round(0)->value;
    }

    public static function toDecimal(int $nanos): string
    {
        return new Number($nanos)->div(self::NANOS_PER_DOLLAR, 9)->value;
    }

    /**
     * The cost of a number of tokens at a rate in US dollars per 1M tokens, rounded up to a whole nanodollar.
     */
    public static function tokenCost(int $tokens, string $ratePerMillion): int
    {
        return (int) new Number($tokens)
            ->mul(self::number($ratePerMillion))
            ->mul(1000)
            ->ceil()
            ->value;
    }

    /**
     * Adds a markup in basis points (2500 = 25%), rounding up to a whole nanodollar.
     */
    public static function withMarkup(int $nanos, int $markupBps): int
    {
        return intdiv(($nanos * (10_000 + $markupBps)) + 9_999, 10_000);
    }

    /**
     * A human-readable amount: at least 2 decimals, up to 6, trailing zeros trimmed.
     */
    public static function format(int $nanos): string
    {
        $negative = $nanos < 0;
        $rounded = new Number(abs($nanos))->div(self::NANOS_PER_DOLLAR, 9)->round(6)->value;
        [$whole, $fraction] = explode('.', $rounded.'.');
        $fraction = mb_str_pad(mb_rtrim($fraction, '0'), 2, '0');

        return ($negative ? '-' : '').'$'.number_format((int) $whole).'.'.$fraction;
    }

    /**
     * Basis points as a percentage string: 2500 => "25", 1250 => "12.5".
     */
    public static function formatBps(int $bps): string
    {
        return mb_rtrim(mb_rtrim(number_format($bps / 100, 2, '.', ''), '0'), '.');
    }

    /**
     * Parses an exact decimal string, such as a Pixelforge rate.
     */
    public static function number(string $decimal): Number
    {
        throw_unless(is_numeric($decimal), InvalidArgumentException::class, "[{$decimal}] is not a decimal number.");

        return new Number($decimal);
    }
}
