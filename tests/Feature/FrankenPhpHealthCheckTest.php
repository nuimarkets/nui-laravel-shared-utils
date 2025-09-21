<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Feature;

use NuiMarkets\LaravelSharedUtils\Http\Controllers\HealthCheckController;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;

class FrankenPhpHealthCheckTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Set environment to allow detailed info
        config(['app.env' => 'local']);
    }

    /** @test */
    public function test_php_environment_includes_frankenphp_info_when_running_under_frankenphp()
    {
        // Mock php_sapi_name to return 'frankenphp'
        $controller = new class extends HealthCheckController {
            protected function getPhpEnvironment(): array
            {
                // Override the method to simulate FrankenPHP environment
                $environment = [
                    'php_version' => phpversion(),
                    'php_sapi' => 'frankenphp', // Simulate FrankenPHP SAPI
                    'memory_limit' => ini_get('memory_limit'),
                    'max_execution_time' => ini_get('max_execution_time').' seconds',
                    'loaded_extensions' => implode(', ', get_loaded_extensions()),
                    'php_ini_paths' => [
                        'loaded_php_ini' => php_ini_loaded_file(),
                        'additional_ini_files' => php_ini_scanned_files(),
                    ],
                    'realpath_cache_size' => ini_get('realpath_cache_size'),
                    'output_buffering' => ini_get('output_buffering'),
                    'zend_enable_gc' => ini_get('zend.enable_gc'),
                ];

                // Add FrankenPHP-specific information since we're simulating FrankenPHP
                if ($environment['php_sapi'] === 'frankenphp') {
                    $environment['frankenphp'] = $this->getFrankenPhpInfo();
                }

                return $environment;
            }

            // Override to simulate FrankenPHP environment
            protected function getFrankenPhpInfo(): array
            {
                return [
                    'detected' => true,
                    'sapi' => 'frankenphp',
                    'server_software' => 'FrankenPHP/1.0.0',
                    'available_functions' => ['frankenphp_request_headers'],
                ];
            }

            // Override database checks to avoid failures in test environment
            protected function checkMysql(string $connectionName): array
            {
                return [
                    'status' => 'ok',
                    'duration' => '5.00ms',
                    'message' => "MySQL [{$connectionName}] connection successful",
                ];
            }

            protected function checkPostgres(string $connectionName): array
            {
                return [
                    'status' => 'ok',
                    'duration' => '5.00ms',
                    'message' => "PostgreSQL [{$connectionName}] connection successful",
                ];
            }
        };

        $response = $controller->detailed();
        $data = $response->getData(true);

        // Debug output to see what's failing
        if ($response->getStatusCode() !== 200) {
            dump($data);
        }

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('ok', $data['status']);
        $this->assertArrayHasKey('php', $data['checks']);
        $this->assertEquals('frankenphp', $data['checks']['php']['php_sapi']);
        $this->assertArrayHasKey('frankenphp', $data['checks']['php']);
        $this->assertTrue($data['checks']['php']['frankenphp']['detected']);
        $this->assertEquals('frankenphp', $data['checks']['php']['frankenphp']['sapi']);
        $this->assertEquals('FrankenPHP/1.0.0', $data['checks']['php']['frankenphp']['server_software']);
        $this->assertContains('frankenphp_request_headers', $data['checks']['php']['frankenphp']['available_functions']);
    }

    /** @test */
    public function test_php_environment_does_not_include_frankenphp_info_when_not_running_under_frankenphp()
    {
        $controller = new class extends HealthCheckController {
            // Override database checks to avoid failures in test environment
            protected function checkMysql(string $connectionName): array
            {
                return [
                    'status' => 'ok',
                    'duration' => '5.00ms',
                    'message' => "MySQL [{$connectionName}] connection successful",
                ];
            }

            protected function checkPostgres(string $connectionName): array
            {
                return [
                    'status' => 'ok',
                    'duration' => '5.00ms',
                    'message' => "PostgreSQL [{$connectionName}] connection successful",
                ];
            }
        };

        $response = $controller->detailed();
        $data = $response->getData(true);

        // Debug output to see what's failing
        if ($response->getStatusCode() !== 200) {
            dump($data);
        }

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('ok', $data['status']);
        $this->assertArrayHasKey('php', $data['checks']);

        // Should not contain FrankenPHP info when not running under FrankenPHP
        $this->assertArrayNotHasKey('frankenphp', $data['checks']['php']);

        // Should still have regular PHP SAPI info
        $this->assertArrayHasKey('php_sapi', $data['checks']['php']);
        $this->assertEquals(php_sapi_name(), $data['checks']['php']['php_sapi']);
    }
}