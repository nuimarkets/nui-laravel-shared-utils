<?php

namespace Nuimarkets\LaravelSharedUtils\Providers;

use Illuminate\Support\ServiceProvider;
use Nuimarkets\LaravelSharedUtils\Support\IncludesParser;

/**
 * IncludesParserServiceProvider
 * 
 * Registers the IncludesParser as a singleton in the Laravel container
 * and optionally sets up global default includes for Connect Platform services.
 */
class IncludesParserServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(IncludesParser::class, function ($app) {
            return new IncludesParser($app['request']);
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Set up global default includes if configured
        $defaultIncludes = config('includes_parser.default_includes', []);
        
        if (!empty($defaultIncludes)) {
            $parser = $this->app->make(IncludesParser::class);
            foreach ($defaultIncludes as $include) {
                $parser->addDefaultInclude($include);
            }
        }

        // Set up global disabled includes if configured  
        $disabledIncludes = config('includes_parser.disabled_includes', []);
        
        if (!empty($disabledIncludes)) {
            $parser = $this->app->make(IncludesParser::class);
            foreach ($disabledIncludes as $include) {
                $parser->addDisabledInclude($include);
            }
        }
    }
}