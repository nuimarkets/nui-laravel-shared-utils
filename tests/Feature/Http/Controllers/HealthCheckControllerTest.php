<?php

namespace Nuimarkets\LaravelSharedUtils\Tests\Feature\Http\Controllers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Nuimarkets\LaravelSharedUtils\Http\Controllers\HealthCheckController;
use Nuimarkets\LaravelSharedUtils\Tests\TestCase;

class HealthCheckControllerTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);
        
        // Set up routes for testing
        $app['router']->get('/healthcheck', [HealthCheckController::class, 'detailed']);
        
        // Configure test storage
        $app['config']->set('filesystems.default', 'local');
        $app['config']->set('filesystems.disks.local', [
            'driver' => 'local',
            'root' => storage_path('app'),
        ]);
        
        // Set environment to local to allow detailed health check
        $app['config']->set('app.env', 'local');
        
        // Configure only SQLite for testing to avoid external DB connection errors
        // Note: HealthCheckController only supports MySQL and PostgreSQL, so we'll use SQLite
        // for the actual testing but the health check won't test it
        $app['config']->set('database.connections', [
            'testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ]
        ]);
        $app['config']->set('database.default', 'testing');
    }

    public function test_health_check_returns_success_response()
    {
        $response = $this->get('/healthcheck');

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'ok',
        ]);
        
        $data = $response->json();
        $this->assertArrayHasKey('checks', $data);
        $this->assertArrayHasKey('timestamp', $data);
        $this->assertArrayHasKey('environment', $data);
        $this->assertArrayHasKey('cache', $data['checks']);
        $this->assertArrayHasKey('storage', $data['checks']);
        $this->assertArrayHasKey('php', $data['checks']);
        
        // Verify all basic checks are OK
        $this->assertEquals('ok', $data['checks']['cache']['status']);
        $this->assertEquals('ok', $data['checks']['storage']['status']);
        $this->assertArrayHasKey('php_version', $data['checks']['php']);
    }

    public function test_php_environment_check()
    {
        $response = $this->get('/healthcheck');

        $data = $response->json();
        $phpCheck = $data['checks']['php'] ?? null;
        
        $this->assertNotNull($phpCheck);
        $this->assertArrayHasKey('php_version', $phpCheck);
        $this->assertArrayHasKey('memory_limit', $phpCheck);
        $this->assertArrayHasKey('php_sapi', $phpCheck);
        $this->assertArrayHasKey('max_execution_time', $phpCheck);
    }

    public function test_cache_check()
    {
        // Ensure cache is working
        Cache::put('test', 'value', 60);
        
        $response = $this->get('/healthcheck');

        $data = $response->json();
        $cacheCheck = $data['checks']['cache'] ?? null;
        
        $this->assertNotNull($cacheCheck);
        $this->assertEquals('ok', $cacheCheck['status']);
        $this->assertArrayHasKey('message', $cacheCheck);
        $this->assertArrayHasKey('duration', $cacheCheck);
    }

    public function test_storage_check()
    {
        $response = $this->get('/healthcheck');

        $data = $response->json();
        $storageCheck = $data['checks']['storage'] ?? null;
        
        $this->assertNotNull($storageCheck);
        $this->assertEquals('ok', $storageCheck['status']);
        $this->assertArrayHasKey('message', $storageCheck);
        $this->assertArrayHasKey('duration', $storageCheck);
    }

    public function test_database_check_skipped_for_sqlite()
    {
        $response = $this->get('/healthcheck');

        $data = $response->json();
        
        // The health controller should skip SQLite connections and testing connections
        // so there should be no database checks in the response
        foreach ($data['checks'] as $checkName => $check) {
            $this->assertNotContains($checkName, ['testing', 'sqlite', 'sqlite_main']);
        }
        
        // But the overall health check should still be OK since other checks pass
        $this->assertEquals('ok', $data['status']);
    }

    public function test_health_check_shows_all_checks_structure()
    {
        $response = $this->get('/healthcheck');
        
        // Uncomment to see full health check output during development
        // $this->dump_output($response->json(), 'Health Check Response');
        
        $data = $response->json();
        
        // Verify all expected sections exist
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('checks', $data);
        $this->assertArrayHasKey('timestamp', $data);
        $this->assertArrayHasKey('environment', $data);
        $this->assertArrayHasKey('cache', $data['checks']);
        $this->assertArrayHasKey('storage', $data['checks']);
        $this->assertArrayHasKey('php', $data['checks']);
        $this->assertArrayHasKey('opCache', $data['checks']);
        
        // Optional checks - may or may not be present
        // RabbitMQ check is only added if the class exists
        if (class_exists('PhpAmqpLib\Connection\AMQPStreamConnection')) {
            $this->assertArrayHasKey('rabbitmq', $data['checks']);
        }
    }
}