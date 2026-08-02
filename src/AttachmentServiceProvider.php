<?php

namespace NuiMarkets\LaravelSharedUtils;

use Illuminate\Support\ServiceProvider;

/**
 * Service provider for attachment components.
 * Registers migrations and attachment config for consuming services.
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

        // Publish migrations and config for customization
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'attachments-migrations');

            $this->publishes([
                __DIR__.'/../config/attachments.php' => config_path('attachments.php'),
            ], 'attachments-config');
        }
    }

    /**
     * Register any package services.
     */
    public function register(): void
    {
        // Inert defaults: malware_scan.enabled is false unless the consumer
        // turns it on, so merging this config changes no behavior by itself.
        $this->mergeConfigFrom(__DIR__.'/../config/attachments.php', 'attachments');
    }
}
