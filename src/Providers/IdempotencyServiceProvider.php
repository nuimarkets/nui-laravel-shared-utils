<?php

namespace NuiMarkets\LaravelSharedUtils\Providers;

use Illuminate\Support\ServiceProvider;

class IdempotencyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/idempotency.php', 'idempotency');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/idempotency.php' => config_path('idempotency.php'),
            ], 'idempotency-config');
        }
    }
}
