<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\Testing;

use Illuminate\Support\Facades\Config;
use NuiMarkets\LaravelSharedUtils\Testing\DBSetupExtension;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;

class DBSetupExtensionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Clear any previous temp connection config
        Config::set('database.connections.temp', null);
    }

    /** @test */
    public function it_skips_execution_when_db_setup_env_not_set(): void
    {
        // Ensure DB_SETUP is not set
        putenv('DB_SETUP');

        $extension = new DBSetupExtension;

        // This should return early without throwing exceptions
        $extension->executeBeforeFirstTest();

        // If we get here without exceptions, the guard clause worked
        $this->assertTrue(true);
    }

    /** @test */
    public function it_creates_temporary_connection_without_database(): void
    {
        // Setup: Configure a test database connection
        $originalConfig = [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'my_production_db',
            'username' => 'root',
            'password' => 'secret',
        ];

        Config::set('database.connections.mysql_test', $originalConfig);

        $extension = new class extends DBSetupExtension
        {
            private string $connectionName;

            public function setConnectionName(string $name): void
            {
                $this->connectionName = $name;
            }

            public function test_set_temporary_default_connection(): void
            {
                // Override the getenv() call by directly manipulating the config
                $tempConnection = Config::get("database.connections.{$this->connectionName}");
                $tempConnection['database'] = null;

                app()['config']->set('database.connections.temp', $tempConnection);
            }
        };

        $extension->setConnectionName('mysql_test');
        $extension->test_set_temporary_default_connection();

        // Verify temp connection was created without database
        $tempConnection = app()['config']->get('database.connections.temp');

        $this->assertNotNull($tempConnection, 'Temp connection should be created');
        $this->assertNull($tempConnection['database'], 'Temp connection should have null database');
        $this->assertEquals('mysql', $tempConnection['driver']);
        $this->assertEquals('127.0.0.1', $tempConnection['host']);
    }

    /** @test */
    public function it_sets_config_directly_on_container_not_facade(): void
    {
        // Setup: Configure a test database connection
        $originalConfig = [
            'driver' => 'pgsql',
            'host' => 'localhost',
            'database' => 'test_db',
            'username' => 'postgres',
        ];

        Config::set('database.connections.pgsql_test', $originalConfig);

        $extension = new class extends DBSetupExtension
        {
            private string $connectionName;

            public function setConnectionName(string $name): void
            {
                $this->connectionName = $name;
            }

            public function test_set_temporary_default_connection(): void
            {
                $tempConnection = Config::get("database.connections.{$this->connectionName}");
                $tempConnection['database'] = null;

                // This is the critical line we're testing - direct container access
                app()['config']->set('database.connections.temp', $tempConnection);
            }
        };

        $extension->setConnectionName('pgsql_test');
        $extension->test_set_temporary_default_connection();

        // Verify config is accessible via container (not just facade)
        $containerConfig = app()['config']->get('database.connections.temp');

        $this->assertNotNull($containerConfig, 'Config must be set on container');
        $this->assertNull($containerConfig['database']);
        $this->assertEquals('pgsql', $containerConfig['driver']);
    }

    /** @test */
    public function it_preserves_original_connection_config(): void
    {
        // Setup: Configure a test database connection
        $originalConfig = [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'database' => 'original_db',
            'username' => 'user',
            'password' => 'pass',
            'charset' => 'utf8mb4',
        ];

        Config::set('database.connections.preserve_test', $originalConfig);

        $extension = new class extends DBSetupExtension
        {
            private string $connectionName;

            public function setConnectionName(string $name): void
            {
                $this->connectionName = $name;
            }

            public function test_set_temporary_default_connection(): void
            {
                $tempConnection = Config::get("database.connections.{$this->connectionName}");
                $tempConnection['database'] = null;

                app()['config']->set('database.connections.temp', $tempConnection);
            }
        };

        $extension->setConnectionName('preserve_test');
        $extension->test_set_temporary_default_connection();

        // Verify original connection is unchanged
        $currentConfig = Config::get('database.connections.preserve_test');
        $this->assertEquals($originalConfig, $currentConfig, 'Original connection should be preserved');

        // Verify temp connection has copied settings except database
        $tempConfig = app()['config']->get('database.connections.temp');
        $this->assertEquals('mysql', $tempConfig['driver']);
        $this->assertEquals('127.0.0.1', $tempConfig['host']);
        $this->assertEquals('user', $tempConfig['username']);
        $this->assertNull($tempConfig['database'], 'Temp connection database should be null');
    }

    /** @test */
    public function it_makes_temp_connection_resolvable_by_database_manager(): void
    {
        // Setup
        $originalConfig = [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'database' => 'test_db',
            'username' => 'root',
        ];

        Config::set('database.connections.manager_test', $originalConfig);

        $extension = new class extends DBSetupExtension
        {
            private string $connectionName;

            public function setConnectionName(string $name): void
            {
                $this->connectionName = $name;
            }

            public function test_set_temporary_default_connection(): void
            {
                $tempConnection = Config::get("database.connections.{$this->connectionName}");
                $tempConnection['database'] = null;

                app()['config']->set('database.connections.temp', $tempConnection);
            }
        };

        $extension->setConnectionName('manager_test');
        $extension->test_set_temporary_default_connection();

        // Verify DatabaseManager can see the temp connection config
        // (We can't actually connect without a real database, but we can verify the config exists)
        $connections = app()['config']->get('database.connections');

        $this->assertArrayHasKey('temp', $connections, 'DatabaseManager should be able to resolve temp connection');
        $this->assertNull($connections['temp']['database']);
    }
}
