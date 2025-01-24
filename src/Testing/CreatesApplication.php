<?php

namespace Nuimarkets\LaravelSharedUtils\Testing;

use Illuminate\Contracts\Console\Kernel;

/**
 * Create Application Trait
 */
trait CreatesApplication
{
    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        $app = require base_path('bootstrap/app.php');

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
