<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Actions\BuildSpendReport;
use App\Actions\FetchProviderUsage;
use App\Services\Money;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

final class SpendTotals extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $range = ReportRange::fromFilters($this->pageFilters);
        $totals = resolve(BuildSpendReport::class)->handle($range->from, $range->to)['totals'];
        $usage = resolve(FetchProviderUsage::class)->handle($range->from, $range->to);

        $reported = $usage === null ? null : array_sum(array_column($usage, 'cost_nano'));
        $margin = $totals['charged_nano'] > 0 ? round($totals['profit_nano'] / $totals['charged_nano'] * 100, 1) : 0;

        return [
            Stat::make('Credit spend', Money::format($totals['charged_nano']))
                ->description($totals['generations'].' successful generations'),
            Stat::make('Provider cost', '$'.Money::toDecimal($totals['provider_cost_nano']))
                ->description(match (true) {
                    $reported === null => 'Pixelforge usage unavailable',
                    $reported === $totals['provider_cost_nano'] => 'Matches Pixelforge exactly',
                    default => 'Pixelforge reports $'.Money::toDecimal($reported),
                })
                ->color($reported === null ? 'gray' : ($reported === $totals['provider_cost_nano'] ? 'success' : 'danger')),
            Stat::make('Profit', Money::format($totals['profit_nano']))
                ->description($margin.'% of credit spend')
                ->color('success'),
        ];
    }
}
