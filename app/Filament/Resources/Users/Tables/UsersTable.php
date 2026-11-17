<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Tables;

use App\Actions\GrantCredits;
use App\Enums\GenerationStatus;
use App\Models\User;
use App\Services\Money;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withCount('generations')
                ->withSum(['generations as spent_nano' => fn (Builder $query): Builder => $query->where('status', GenerationStatus::Succeeded)], 'charged_nano'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('Email address')
                    ->searchable()
                    ->copyable(),
                IconColumn::make('is_admin')
                    ->label('Admin')
                    ->boolean(),
                TextColumn::make('balance_nano')
                    ->label('Balance')
                    ->formatStateUsing(fn (int $state): string => Money::format($state))
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('spent_nano')
                    ->label('Spent')
                    ->formatStateUsing(fn (mixed $state): string => Money::format(is_numeric($state) ? (int) $state : 0))
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('generations_count')
                    ->label('Generations')
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('created_at')
                    ->label('Joined')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('grantCredits')
                    ->label('Credits')
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->modalHeading('Grant or remove credits')
                    ->schema([
                        TextInput::make('amount')
                            ->label('Amount (USD)')
                            ->helperText('Use a negative amount to remove credits.')
                            ->numeric()
                            ->required()
                            ->notIn(['0']),
                        TextInput::make('reason')
                            ->maxLength(200),
                    ])
                    ->action(function (User $record, array $data, GrantCredits $grantCredits): void {
                        $amount = is_scalar($data['amount'] ?? null) ? (string) $data['amount'] : '0';
                        $reason = is_string($data['reason'] ?? null) ? $data['reason'] : '';
                        $grantCredits->handle($record, Money::fromDecimal($amount), $reason);

                        Notification::make()->title('Balance updated')->success()->send();
                    }),
                EditAction::make(),
            ]);
    }
}
