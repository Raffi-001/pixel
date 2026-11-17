<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\ApplyPixelforgeGeneration;
use App\Actions\ReconcileProviderDay;
use App\Exceptions\PixelforgeException;
use App\Models\Generation;
use App\Services\Money;
use App\Services\Pixelforge\PixelforgeClient;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Url;
use Throwable;

/**
 * Generation-by-generation comparison of one UTC day with Pixelforge, to explain any mismatch in the spend report.
 */
final class Reconciliation extends Page implements HasTable
{
    use InteractsWithTable;

    #[Url]
    public ?string $date = null;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?int $navigationSort = 1;

    public function content(Schema $schema): Schema
    {
        $report = $this->report();

        return $schema->components([
            Text::make(match (true) {
                ! $report['available'] => 'Pixelforge could not be reached, so nothing could be compared.',
                default => sprintf(
                    'Pixelforge billed $%s on %s; we recorded $%s. %d generation(s) match exactly, %d need attention.%s%s',
                    Money::toDecimal($report['pixelforge_cost_nano']),
                    $this->day()->toDateString(),
                    Money::toDecimal($report['our_cost_nano']),
                    $report['matched'],
                    count($report['rows']),
                    $report['other_environments'] > 0 ? sprintf(
                        ' %d generation(s) ($%s) from other environments on this Pixelforge account are left out.',
                        $report['other_environments'],
                        Money::toDecimal($report['other_environments_cost_nano']),
                    ) : '',
                    $report['complete'] ? '' : ' Only the most recent generations were checked, so this day may be incomplete.',
                ),
            }),
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Differences')
            ->records(fn (): array => $this->rows())
            ->paginated(false)
            ->emptyStateHeading('Everything matches')
            ->emptyStateDescription('Every generation Pixelforge billed on this day is recorded at exactly the same cost.')
            ->filters([
                Filter::make('day')
                    ->schema([
                        DatePicker::make('date')
                            ->label('Day (UTC)')
                            ->native(false)
                            ->maxDate(now('UTC'))
                            ->default(fn (): string => $this->day()->toDateString())
                            ->afterStateUpdated(fn (?string $state): string => $this->date = $state ?? now('UTC')->toDateString())
                            ->live(),
                    ]),
            ], layout: FiltersLayout::AboveContent)
            ->columns([
                TextColumn::make('verdict')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'out_of_date' ? 'warning' : 'danger')
                    ->formatStateUsing(fn (string $state): string => ReconcileProviderDay::describe($state))
                    ->wrap(),
                TextColumn::make('provider_id')->label('Pixelforge id')->fontFamily('mono')->copyable(),
                TextColumn::make('user')->placeholder('—'),
                TextColumn::make('prompt')->limit(40)->placeholder('—'),
                TextColumn::make('pixelforge_status')->label('Pixelforge'),
                TextColumn::make('pixelforge_cost_nano')->label('Pixelforge cost')->alignEnd()->fontFamily('mono')
                    ->formatStateUsing(fn (int $state): string => '$'.Money::toDecimal($state))->placeholder('—'),
                TextColumn::make('our_status')->label('Ours')->placeholder('—'),
                TextColumn::make('our_cost_nano')->label('Our cost')->alignEnd()->fontFamily('mono')
                    ->formatStateUsing(fn (int $state): string => '$'.Money::toDecimal($state))->placeholder('—'),
            ])
            ->recordActions([
                Action::make('resync')
                    ->label('Re-sync')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->visible(fn (array $record): bool => $record['can_resync'] === true)
                    ->action(function (array $record, PixelforgeClient $pixelforge, ApplyPixelforgeGeneration $apply): void {
                        $providerId = is_string($record['provider_id'] ?? null) ? $record['provider_id'] : '';

                        try {
                            $updated = $apply->handle($pixelforge->getGeneration($providerId));
                        } catch (PixelforgeException $pixelforgeException) {
                            Notification::make()->title('Pixelforge: '.$pixelforgeException->getMessage())->danger()->send();

                            return;
                        }

                        Cache::forget('pixelforge.reconcile.'.$this->day()->toDateString());

                        Notification::make()
                            ->title($updated instanceof Generation ? 'Generation updated to '.$updated->status->label() : 'No local generation matches this Pixelforge id')
                            ->status($updated instanceof Generation ? 'success' : 'warning')
                            ->send();
                    }),
            ]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rows(): array
    {
        $rows = [];

        foreach ($this->report()['rows'] as $row) {
            $rows[$row['verdict'].'|'.$row['provider_id']] = $row;
        }

        return $rows;
    }

    /**
     * @return array{available: bool, complete: bool, pixelforge_cost_nano: int, our_cost_nano: int, matched: int, other_environments: int, other_environments_cost_nano: int, rows: list<array{provider_id: string, prompt: string, user: string|null, pixelforge_status: string, pixelforge_cost_nano: int|null, our_status: string|null, our_cost_nano: int|null, verdict: string, can_resync: bool}>}
     */
    private function report(): array
    {
        return resolve(ReconcileProviderDay::class)->handle($this->day());
    }

    private function day(): CarbonImmutable
    {
        try {
            $day = CarbonImmutable::parse($this->date ?? 'today', 'UTC')->startOfDay();
        } catch (Throwable) {
            $day = CarbonImmutable::now('UTC')->startOfDay();
        }

        return $day->isFuture() ? CarbonImmutable::now('UTC')->startOfDay() : $day;
    }
}
