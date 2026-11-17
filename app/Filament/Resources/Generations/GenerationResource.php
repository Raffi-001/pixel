<?php

declare(strict_types=1);

namespace App\Filament\Resources\Generations;

use App\Filament\Resources\Generations\Pages\ListGenerations;
use App\Filament\Resources\Generations\Tables\GenerationsTable;
use App\Models\Generation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * A read-only view of every generation, for support and auditing.
 */
final class GenerationResource extends Resource
{
    protected static ?string $model = Generation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static ?int $navigationSort = 3;

    public static function table(Table $table): Table
    {
        return GenerationsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGenerations::route('/'),
        ];
    }
}
