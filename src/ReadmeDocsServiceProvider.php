<?php

namespace Nuimarkets\LaravelSharedUtils;

use Illuminate\Support\ServiceProvider;
use Nuimarkets\LaravelSharedUtils\Console\Commands\GenerateReadmeDocsCommand;

class ReadmeDocsServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateReadmeDocsCommand::class,
            ]);
        }
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }
}