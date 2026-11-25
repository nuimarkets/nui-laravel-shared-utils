<?php

namespace NuiMarkets\LaravelSharedUtils\Providers;

use Illuminate\Support\ServiceProvider;
use NuiMarkets\LaravelSharedUtils\Auth\TokenRevocationGuard;
use NuiMarkets\LaravelSharedUtils\Auth\TokenStatusClient;

class TokenRevocationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/token_revocation.php', 'token_revocation');

        // The status call authenticates with this service's machine token.
        $this->app->register(MachineTokenServiceProvider::class);

        $this->app->singleton(TokenStatusClient::class);
        $this->app->singleton(TokenRevocationGuard::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/token_revocation.php' => config_path('token_revocation.php'),
            ], 'token-revocation-config');
        }
    }
}
