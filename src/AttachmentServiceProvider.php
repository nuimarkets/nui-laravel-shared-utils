<?php

namespace NuiMarkets\LaravelSharedUtils;

use Illuminate\Support\ServiceProvider;

/**
 * Service provider for attachment components.
 * Registers migrations for consuming services.
 */
class AttachmentServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        // Load migrations from package
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Publish migrations for customization
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'attachments-migrations');
        }
    }

    /**
     * Register any package services.
     */
    public function register(): void
    {
        // No services to register
    }
}
