<?php

declare(strict_types=1);

namespace App\Filament\Resources\Generations\Pages;

use App\Filament\Resources\Generations\GenerationResource;
use Filament\Resources\Pages\ListRecords;

final class ListGenerations extends ListRecords
{
    protected static string $resource = GenerationResource::class;
}
