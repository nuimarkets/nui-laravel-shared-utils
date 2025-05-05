<?php

namespace Nuimarkets\LaravelSharedUtils\Testing;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

/**
 * Create Application Trait
 */
trait CreatesApplication
{
    /**
     * Creates the application.
     *
     * @return Application
     */
    public function createApplication(): Application
    {
        $app = require base_path('bootstrap/app.php');

        $app->loadEnvironmentFrom('.env.testing');

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
