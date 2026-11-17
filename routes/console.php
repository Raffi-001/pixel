<?php

declare(strict_types=1);

use App\Actions\SyncPendingGenerations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('pixelforge:sync', function (SyncPendingGenerations $sync): void {
    $this->info("Checked {$sync->handle()} generation(s) with Pixelforge.");
})->purpose('Poll Pixelforge for generations whose webhooks have not arrived');

Schedule::command('pixelforge:sync')->everyMinute()->withoutOverlapping();
