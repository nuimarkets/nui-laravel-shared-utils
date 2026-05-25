<?php

namespace NuiMarkets\LaravelSharedUtils\Tests;

use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * Store the latest HTTP response for test assertions.
     */
    public static $latestResponse;

    /**
     * Setup the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();
    }

    /**
     * Get package providers.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array
     */
    protected function getPackageProviders($app)
    {
        return [];
    }

    /**
     * Define environment setup.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function getEnvironmentSetUp($app)
    {
        // Setup default config values
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    /**
     * Helper to dump output for debugging tests
     *
     * @param  mixed  $data
     */
    protected function dump_output($data, ?string $label = null): void
    {
        if ($label) {
            echo "\n=== $label ===\n";
        }
        print_r($data);
        echo "\n";
    }
}
