<?php

namespace NuiMarkets\LaravelSharedUtils\Providers;

use Illuminate\Support\ServiceProvider;
use NuiMarkets\LaravelSharedUtils\Contracts\MachineTokenServiceInterface;
use NuiMarkets\LaravelSharedUtils\Services\MachineTokenService;

class MachineTokenServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/machine_token.php', 'machine_token');

        $this->app->singleton(MachineTokenService::class);
        $this->app->singleton(MachineTokenServiceInterface::class, function ($app): MachineTokenService {
            return $app->make(MachineTokenService::class);
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/machine_token.php' => config_path('machine_token.php'),
            ], 'machine-token-config');
        }
    }
}
