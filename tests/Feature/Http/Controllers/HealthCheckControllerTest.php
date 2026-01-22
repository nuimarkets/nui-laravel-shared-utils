<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Feature\Http\Controllers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use NuiMarkets\LaravelSharedUtils\Http\Controllers\HealthCheckController;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;

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
            ],
        ]);
        $app['config']->set('database.default', 'testing');

        // Configure queue to use sync driver for testing (no external queue needed)
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('queue.connections.sync', [
            'driver' => 'sync',
        ]);

        // Configure cache to use array driver for testing (no external cache needed)
        $app['config']->set('cache.default', 'array');
        $app['config']->set('cache.stores.array', [
            'driver' => 'array',
        ]);

        // Don't configure Redis - let the health check detect it's not available and skip it
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

        // These checks should always be present
        $this->assertArrayHasKey('cache', $data['checks']);
        $this->assertArrayHasKey('storage', $data['checks']);
        $this->assertArrayHasKey('php', $data['checks']);
        $this->assertArrayHasKey('opCache', $data['checks']);

        // Verify all basic checks are OK
        $this->assertEquals('ok', $data['checks']['cache']['status']);
        $this->assertEquals('ok', $data['checks']['storage']['status']);
        $this->assertArrayHasKey('php_version', $data['checks']['php']);

        // Redis and Queue checks are optional and may not be present in testing environment
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

        // These checks should always be present
        $this->assertArrayHasKey('cache', $data['checks']);
        $this->assertArrayHasKey('storage', $data['checks']);
        $this->assertArrayHasKey('php', $data['checks']);
        $this->assertArrayHasKey('opCache', $data['checks']);

        // Optional checks - may or may not be present
        // Redis check is only added if Redis is configured and available
        // Queue check is only added if queue driver is not 'sync'
        // RabbitMQ check is only added if the class exists
        if (class_exists('PhpAmqpLib\Connection\AMQPStreamConnection')) {
            $this->assertArrayHasKey('rabbitmq', $data['checks']);
        }
    }

    public function test_rabbitmq_check_skipped_when_host_empty()
    {
        // RabbitMQ library must be available for this test
        if (! class_exists('PhpAmqpLib\Connection\AMQPStreamConnection')) {
            $this->markTestSkipped('PhpAmqpLib not installed');
        }

        config(['rabbit.host' => '']);

        $response = $this->get('/healthcheck');

        $data = $response->json();
        $rabbitmqCheck = $data['checks']['rabbitmq'] ?? null;

        $this->assertNotNull($rabbitmqCheck, 'RabbitMQ check should be present');
        $this->assertEquals('skipped', $rabbitmqCheck['status']);
        $this->assertEquals('RabbitMQ not configured', $rabbitmqCheck['message']);
        $this->assertEquals('not_configured', $rabbitmqCheck['connection']['state']);
    }

    public function test_rabbitmq_check_skipped_when_host_null()
    {
        // RabbitMQ library must be available for this test
        if (! class_exists('PhpAmqpLib\Connection\AMQPStreamConnection')) {
            $this->markTestSkipped('PhpAmqpLib not installed');
        }

        config(['rabbit.host' => null]);

        $response = $this->get('/healthcheck');

        $data = $response->json();
        $rabbitmqCheck = $data['checks']['rabbitmq'] ?? null;

        $this->assertNotNull($rabbitmqCheck, 'RabbitMQ check should be present');
        $this->assertEquals('skipped', $rabbitmqCheck['status']);
    }
}
