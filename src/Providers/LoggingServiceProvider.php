<?php

namespace NuiMarkets\LaravelSharedUtils\Providers;

use Illuminate\Support\ServiceProvider;
use NuiMarkets\LaravelSharedUtils\Http\Middleware\RequestLoggingMiddleware;
use NuiMarkets\LaravelSharedUtils\Logging\Processors\AddTargetProcessor;

/**
 * Service provider for easy integration of logging components.
 * 
 * This provider:
 * - Publishes configuration files
 * - Registers logging middleware
 * - Configures Monolog processors
 * - Provides easy setup for services
 */
class LoggingServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        // Merge default config
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/logging-utils.php',
            'logging-utils'
        );
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        // Publish configuration
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/logging-utils.php' => config_path('logging-utils.php'),
            ], 'logging-utils-config');
        }

        // Configure Monolog if enabled
        if (config('logging-utils.processors.add_target.enabled', true)) {
            $this->configureMonologProcessor();
        }

        // Register middleware alias if enabled
        if (config('logging-utils.middleware.request_logging.enabled', false)) {
            $this->registerMiddlewareAlias();
        }
    }

    /**
     * Configure Monolog to use the AddTargetProcessor.
     *
     * @return void
     */
    protected function configureMonologProcessor()
    {
        // Get the default logging channel
        $defaultChannel = config('logging.default', 'stack');
        $channelConfig = config("logging.channels.{$defaultChannel}");

        // Only proceed if it's a channel that supports tap
        if (!isset($channelConfig['driver']) || !in_array($channelConfig['driver'], ['single', 'daily', 'stack'])) {
            return;
        }

        // Add our custom tap to the channel configuration
        $tapClass = config('logging-utils.processors.monolog_customizer', 
            \NuiMarkets\LaravelSharedUtils\Logging\CustomizeMonoLog::class);

        config([
            "logging.channels.{$defaultChannel}.tap" => array_merge(
                (array) config("logging.channels.{$defaultChannel}.tap", []),
                [$tapClass]
            )
        ]);
    }

    /**
     * Register middleware alias for easy usage.
     *
     * @return void
     */
    protected function registerMiddlewareAlias()
    {
        $router = $this->app['router'];
        
        // Get the middleware class from config
        $middlewareClass = config('logging-utils.middleware.request_logging.class');
        
        if ($middlewareClass && class_exists($middlewareClass)) {
            $router->aliasMiddleware('log.requests', $middlewareClass);
        }
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [
            'logging-utils',
        ];
    }
}