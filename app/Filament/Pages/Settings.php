<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\UpdateSettings;
use App\Services\AppSettings;
use App\Services\Money;
use App\Services\PixelforgeCapacity;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * @property-read Schema $form
 */
final class Settings extends Page
{
    /** @var array<string, mixed>|null */
    public ?array $data = [];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?int $navigationSort = 10;

    public function mount(AppSettings $settings): void
    {
        $this->form->fill([
            'markup_percent' => Money::formatBps($settings->markupBps()),
            'signup_bonus' => Money::toDecimal($settings->signupBonusNano()),
            'credit_packs' => array_map(strval(...), $settings->creditPacks()),
            'accepting_generations' => $settings->acceptingGenerations(),
            'max_in_flight_per_user' => $settings->maxInFlightPerUser(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Pricing')
                    ->description('Changes apply to new generations only. Each generation keeps the markup it was created with.')
                    ->schema([
                        TextInput::make('markup_percent')
                            ->label('Markup on Pixelforge cost')
                            ->suffix('%')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(1000)
                            ->step(0.01)
                            ->required(),
                    ]),
                Section::make('Credits')
                    ->schema([
                        TextInput::make('signup_bonus')
                            ->label('Free credits for new users')
                            ->prefix('$')
                            ->numeric()
                            ->minValue(0)
                            ->required(),
                        TagsInput::make('credit_packs')
                            ->label('Credit packs users can buy')
                            ->helperText('Whole dollar amounts, e.g. 5, 10, 25.')
                            ->nestedRecursiveRules(['integer', 'min:1', 'max:1000'])
                            ->required(),
                    ]),
                Section::make('Operations')
                    ->schema([
                        TextInput::make('max_in_flight_per_user')
                            ->label('Generations running at once per user')
                            ->helperText('Pixelforge runs 3 at a time for the whole app. Keeping this below 3 stops one user from blocking everyone else.')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(3)
                            ->required(),
                        Toggle::make('accepting_generations')
                            ->label('Accept new generations')
                            ->helperText('Turn off to pause new submissions, for example during a Pixelforge incident. Queued generations still finish.'),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('settings-form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label('Save settings')->submit('save'),
                    ]),
                ]),
        ]);
    }

    public function save(UpdateSettings $updateSettings): void
    {
        /** @var array{markup_percent: numeric-string|int|float, signup_bonus: numeric-string|int|float, credit_packs: list<string>, accepting_generations: bool, max_in_flight_per_user: int|numeric-string} $data */
        $data = $this->form->getState();

        $updateSettings->handle(
            markupBps: (int) Money::number((string) $data['markup_percent'])->mul(100)->round(0)->value,
            signupBonusNano: Money::fromDecimal((string) $data['signup_bonus']),
            creditPacks: array_map(intval(...), $data['credit_packs']),
            acceptingGenerations: $data['accepting_generations'],
            maxInFlightPerUser: (int) $data['max_in_flight_per_user'],
        );

        Notification::make()->title('Settings saved')->success()->send();
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('resumeGenerating')
                ->label('Resume generating')
                ->icon(Heroicon::OutlinedPlay)
                ->color('warning')
                ->visible(fn (PixelforgeCapacity $capacity): bool => $capacity->isPaused())
                ->requiresConfirmation()
                ->modalHeading('Resume before midnight UTC?')
                ->modalDescription(fn (PixelforgeCapacity $capacity): string => 'Pixelforge reported its daily limit, so new generations are paused until '.$capacity->pausedUntil()?->format('H:i').' UTC. Resume only if you know the quota has been reset.')
                ->action(function (PixelforgeCapacity $capacity): void {
                    $capacity->resume();

                    Notification::make()->title('Generating resumed')->success()->send();
                }),
        ];
    }
}
