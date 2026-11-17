<?php

declare(strict_types=1);

use App\Services\Money;

it('prices tokens exactly at rates per million', function (): void {
    expect(Money::tokenCost(115, '5.00'))->toBe(575_000)
        ->and(Money::tokenCost(1_530, '10.00'))->toBe(15_300_000)
        ->and(Money::tokenCost(6_450, '40.00'))->toBe(258_000_000)
        ->and(Money::tokenCost(8_000, '12.50'))->toBe(100_000_000)
        ->and(Money::tokenCost(1, '0.0001'))->toBe(1);
});

it('adds markup, rounding up to a whole nanodollar', function (): void {
    expect(Money::withMarkup(373_875_000, 2500))->toBe(467_343_750)
        ->and(Money::withMarkup(1, 2500))->toBe(2)
        ->and(Money::withMarkup(4, 2500))->toBe(5)
        ->and(Money::withMarkup(100, 0))->toBe(100);
});

it('converts between decimals and nanodollars', function (): void {
    expect(Money::fromDecimal('0.046125000'))->toBe(46_125_000)
        ->and(Money::fromDecimal('10'))->toBe(10_000_000_000)
        ->and(Money::toDecimal(373_875_000))->toBe('0.373875000')
        ->and(Money::toDecimal(-5))->toBe('-0.000000005');
});

it('formats amounts for people', function (): void {
    expect(Money::format(467_343_750))->toBe('$0.467344')
        ->and(Money::format(5_000_000_000))->toBe('$5.00')
        ->and(Money::format(1_234_500_000_000))->toBe('$1,234.50')
        ->and(Money::format(-78_512_500))->toBe('-$0.078513')
        ->and(Money::formatBps(2500))->toBe('25')
        ->and(Money::formatBps(1250))->toBe('12.5');
});

it('refuses numbers that are not decimals', function (): void {
    Money::number('abc');
})->throws(InvalidArgumentException::class);
