<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Actions\BuildSpendReport;
use App\Actions\FetchProviderUsage;
use App\Services\Money;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;

final class DailySpendTable extends TableWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Per day (UTC, by completion date)')
            ->description("Provider cost is checked against Pixelforge's GET /v1/usage for this API key.")
            ->records(fn (): array => $this->rows())
            ->paginated(false)
            ->columns([
                TextColumn::make('date'),
                TextColumn::make('generations')->alignEnd(),
                TextColumn::make('provider_cost')->label('Provider cost')->alignEnd()->fontFamily('mono'),
                TextColumn::make('reported_cost')->label('Pixelforge reports')->alignEnd()->fontFamily('mono')->placeholder('unavailable'),
                IconColumn::make('matches')->label('Match')->boolean()->placeholder('—'),
                TextColumn::make('charged')->label('Credit spend')->alignEnd(),
                TextColumn::make('profit')->alignEnd(),
            ]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rows(): array
    {
        $range = ReportRange::fromFilters($this->pageFilters);
        $report = resolve(BuildSpendReport::class)->handle($range->from, $range->to);
        $usage = resolve(FetchProviderUsage::class)->handle($range->from, $range->to);

        $rows = [];

        foreach ($report['days'] as $day) {
            $reported = $usage === null ? null : ($usage[$day['date']]['cost_nano'] ?? 0);

            $rows[$day['date']] = [
                'date' => $day['date'],
                'generations' => $day['generations'],
                'provider_cost' => '$'.Money::toDecimal($day['provider_cost_nano']),
                'reported_cost' => $reported === null ? null : '$'.Money::toDecimal($reported),
                'matches' => $reported === null ? null : $reported === $day['provider_cost_nano'],
                'charged' => Money::format($day['charged_nano']),
                'profit' => Money::format($day['profit_nano']),
            ];
        }

        return $rows;
    }
}
