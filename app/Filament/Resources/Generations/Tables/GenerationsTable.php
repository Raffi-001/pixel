<?php

declare(strict_types=1);

namespace App\Filament\Resources\Generations\Tables;

use App\Enums\GenerationQuality;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class GenerationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user:id,name,email'))
            ->defaultSort('created_at', 'desc')
            ->poll('10s')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Created (UTC)')
                    ->dateTime()
                    ->timezone('UTC')
                    ->sortable(),
                TextColumn::make('user.email')
                    ->label('User')
                    ->searchable(),
                TextColumn::make('prompt')
                    ->limit(50)
                    ->tooltip(fn (Generation $record): string => $record->prompt)
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (GenerationStatus $state): string => $state->label())
                    ->color(fn (GenerationStatus $state): string => match ($state) {
                        GenerationStatus::Succeeded => 'success',
                        GenerationStatus::Failed => 'danger',
                        GenerationStatus::Processing => 'warning',
                        GenerationStatus::Queued => 'info',
                        GenerationStatus::Pending => 'gray',
                    })
                    ->description(fn (Generation $record): ?string => $record->error_code),
                TextColumn::make('quality')
                    ->formatStateUsing(fn (Generation $record): string => $record->quality->label().' · '.$record->size->value)
                    ->toggleable(),
                TextColumn::make('estimated_max_nano')
                    ->label('Held (max)')
                    ->formatStateUsing(fn (int $state): string => Money::format($state))
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('provider_cost_nano')
                    ->label('Provider cost')
                    ->formatStateUsing(fn (int $state): string => '$'.Money::toDecimal($state))
                    ->placeholder('—')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('charged_nano')
                    ->label('Charged')
                    ->formatStateUsing(fn (int $state): string => '$'.Money::toDecimal($state))
                    ->placeholder('—')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('completed_at')
                    ->label('Completed (UTC)')
                    ->dateTime()
                    ->timezone('UTC')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('provider_id')
                    ->label('Pixelforge id')
                    ->copyable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(GenerationStatus::cases())->mapWithKeys(fn (GenerationStatus $status): array => [$status->value => $status->label()])->all()),
                SelectFilter::make('quality')
                    ->options(collect(GenerationQuality::cases())->mapWithKeys(fn (GenerationQuality $quality): array => [$quality->value => $quality->label()])->all()),
                SelectFilter::make('user')
                    ->relationship('user', 'email')
                    ->searchable(),
            ]);
    }
}
