<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Widgets\DailySpendTable;
use App\Filament\Widgets\SpendTotals;
use App\Filament\Widgets\UserSpendTable;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * The operators' spend report: what users were charged, what Pixelforge charged us, and the profit.
 */
final class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static ?string $title = 'Spend report';

    protected static ?string $navigationLabel = 'Spend report';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            DatePicker::make('from')
                ->label('From (UTC)')
                ->default(now('UTC')->subDays(29)->toDateString())
                ->native(false)
                ->maxDate(now('UTC')),
            DatePicker::make('to')
                ->label('To (UTC)')
                ->default(now('UTC')->toDateString())
                ->native(false)
                ->maxDate(now('UTC')),
        ]);
    }

    public function getWidgets(): array
    {
        return [
            SpendTotals::class,
            DailySpendTable::class,
            UserSpendTable::class,
        ];
    }

    public function getColumns(): int
    {
        return 1;
    }
}
