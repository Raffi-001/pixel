<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Actions\BuildSpendReport;
use App\Services\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;

final class UserSpendTable extends TableWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Per user per day (UTC)')
            ->records(fn (): array => $this->rows())
            ->paginated(false)
            ->emptyStateHeading('No successful generations in this range')
            ->columns([
                TextColumn::make('date'),
                TextColumn::make('user')->description(fn (array $record): string => is_string($record['email'] ?? null) ? $record['email'] : ''),
                TextColumn::make('generations')->alignEnd(),
                TextColumn::make('provider_cost')->label('Provider cost')->alignEnd()->fontFamily('mono'),
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
        $rows = [];

        foreach (resolve(BuildSpendReport::class)->handle($range->from, $range->to)['users'] as $row) {
            $rows[$row['date'].'|'.$row['user_id']] = [
                'date' => $row['date'],
                'user' => $row['name'],
                'email' => $row['email'],
                'generations' => $row['generations'],
                'provider_cost' => '$'.Money::toDecimal($row['provider_cost_nano']),
                'charged' => Money::format($row['charged_nano']),
                'profit' => Money::format($row['profit_nano']),
            ];
        }

        return $rows;
    }
}
