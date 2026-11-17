<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PixelforgeClient::class, fn (Application $app): PixelforgeClient => new PixelforgeClient(
            baseUrl: $app->make(Repository::class)->string('services.pixelforge.base_url'),
            apiKey: $app->make(Repository::class)->string('services.pixelforge.api_key'),
        ));
    }
}
