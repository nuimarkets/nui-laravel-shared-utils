<?php

namespace Nuimarkets\LaravelSharedUtils\Http\Controllers;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

/**
 * Detailed Health Checks
 */
class HealthCheckController extends Controller
{
    /**
     * Default timeout for checks in seconds
     */
    protected const DEFAULT_TIMEOUT = 5;

    protected function isAuthorizedForDetailedInfo(): bool
    {
        // Example implementation - update this logic to suit your security requirements.
        $allowedEnvs = ['local', 'development'];
        $hasValidToken = request()->has('token') &&
                         request()->get('token') === env('HEALTH_CHECK_DETAILED_TOKEN');

        return in_array(env('APP_ENV'), $allowedEnvs) || $hasValidToken;
    }

    /**
     * Perform all health checks and return results
     */
    public function detailed(): JsonResponse
    {

        if (!$this->isAuthorizedForDetailedInfo()) {
            return new JsonResponse([
                'status' => 'restricted',
                'message' => 'Access to detailed information is restricted',
            ], 401);
        }

        $checks = [
            'mysql' => $this->checkMysql(),
            'redis' => $this->checkRedis(),
            'queue' => $this->checkQueue(),
            'storage' => $this->checkStorage(),
            'cache' => $this->checkCache(),
            'opCache' => $this->checkOpCache(),
            'php' => $this->getPhpEnvironment(),
        ];

        // Only add RabbitMQ check if the package is available
        if (class_exists('PhpAmqpLib\Connection\AMQPStreamConnection')) {
            $checks['rabbitmq'] = $this->checkRabbitMQ();
        }

        $results = [
            'status' => 'ok',
            'timestamp' => now()->toIso8601String(),
            'environment' => $this->getEnvironment(),
            'checks' => $checks,
        ];

        // If any check failed, set overall status to error
        if (in_array('error', array_column($results['checks'], 'status'))) {
            $results['status'] = 'error';
            return new JsonResponse($results, 503);
        }

        return new JsonResponse($results, 200);
    }

    /**
     * Check MySQL connection
     */
    protected function checkMysql(): array
    {
        try {
            $start = microtime(true);
            DB::connection()->getPdo();
            $duration = microtime(true) - $start;

            return [
                'status' => 'ok',
                'duration' => round($duration * 1000, 2) . 'ms',
                'message' => 'MySQL connection successful',
            ];
        } catch (Exception $e) {
            Log::error('Health check failed: MySQL connection error', [
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'error',
                'message' => 'MySQL connection failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Check Redis connection
     */
    protected function checkRedis(): array
    {
        try {
            $start = microtime(true);
            $testKey = 'health_check_test';

            Redis::set($testKey, 'test', 'EX', 10);
            $value = Redis::get($testKey);
            Redis::del($testKey);

            $duration = microtime(true) - $start;

            if ($value !== 'test') {
                throw new Exception('Redis write/read test failed');
            }

            return [
                'status' => 'ok',
                'duration' => round($duration * 1000, 2) . 'ms',
                'message' => 'Redis connection successful',
            ];
        } catch (Exception $e) {
            Log::error('Health check failed: Redis connection error', [
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'error',
                'message' => 'Redis connection failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Check Queue connection
     */
    protected function checkQueue(): array
    {
        try {
            $start = microtime(true);
            Queue::size();
            $duration = microtime(true) - $start;

            return [
                'status' => 'ok',
                'duration' => round($duration * 1000, 2) . 'ms',
                'message' => 'Queue connection successful',
            ];
        } catch (Exception $e) {
            Log::error('Health check failed: Queue connection error', [
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'error',
                'message' => 'Queue connection failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Check Storage (filesystem) access
     */
    protected function checkStorage(): array
    {
        try {
            $start = microtime(true);
            $testFile = 'health_check_test.txt';

            Storage::put($testFile, 'test');
            $content = Storage::get($testFile);
            Storage::delete($testFile);

            $duration = microtime(true) - $start;

            if ($content !== 'test') {
                throw new Exception('Storage write/read test failed');
            }

            return [
                'status' => 'ok',
                'duration' => round($duration * 1000, 2) . 'ms',
                'message' => 'Storage access successful',
            ];
        } catch (Exception $e) {
            Log::error('Health check failed: Storage access error', [
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'error',
                'message' => 'Storage access failed: ' . $e->getMessage(),
            ];
        }
    }


    protected function checkRabbitMQ(): array
    {

        $connection = null;
        $channel = null;

        try {
            $start = microtime(true);

            $connection = new \PhpAmqpLib\Connection\AMQPStreamConnection(
                config('rabbit.host'),
                config('rabbit.port', 5672),
                config('rabbit.username'),
                config('rabbit.password'),
                config('rabbit.vhost', '/'),
                false,
                'AMQPLAIN',
                null,
                'en_US',
                3.0,
                3.0,
                null,
                false,
                0,
            );

            if (!$connection->isConnected()) {
                throw new Exception('Failed to establish connection');
            }

            // Create channel and enable publish confirmations
            $channel = $connection->channel();
            $channel->confirm_select();

            if (!$channel->is_open()) {
                throw new Exception('Channel failed to open');
            }

            // Simple connection check only - no message publishing
            $duration = microtime(true) - $start;

            return [
                'status' => 'ok',
                'duration' => round($duration * 1000, 2) . 'ms',
                'message' => 'RabbitMQ connection successful',
                'details' => [
                    'host' => config('rabbit.host'),
                    'vhost' => config('rabbit.vhost'),
                    'connection_state' => 'connected',
                ],
            ];

        } catch (AMQPIOException $e) {
            Log::error('Health check failed: RabbitMQ connection error', [
                'error' => $e->getMessage(),
                'connection_details' => [
                    'host' => config('rabbit.host'),
                    'port' => config('rabbit.port'),
                    'username' => config('rabbit.username'),
                    'vhost' => config('rabbit.vhost'),
                ],
            ]);

            return [
                'status' => 'error',
                'message' => 'RabbitMQ connection failed: ' . $e->getMessage(),
            ];

        } catch (Exception $e) {
            Log::error('Health check failed: RabbitMQ error', [
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'error',
                'message' => 'RabbitMQ check failed: ' . $e->getMessage(),
            ];

        } finally {
            // Clean up
            try {
                if ($channel && $channel->is_open()) {
                    $channel->close();
                }
                if ($connection && $connection->isConnected()) {
                    $connection->close();
                }
            } catch (Exception $e) {
                Log::warning('RabbitMQ cleanup warning: ' . $e->getMessage());
            }
        }
    }

    /**
     * Check Cache functionality
     */
    protected function checkCache(): array
    {
        try {
            $start = microtime(true);
            $testKey = 'health_check_test';

            Cache::put($testKey, 'test', 10);
            $value = Cache::get($testKey);
            Cache::forget($testKey);

            $duration = microtime(true) - $start;

            if ($value !== 'test') {
                throw new Exception('Cache write/read test failed');
            }

            return [
                'status' => 'ok',
                'duration' => round($duration * 1000, 2) . 'ms',
                'message' => 'Cache system operational',
            ];
        } catch (Exception $e) {
            Log::error('Health check failed: Cache system error', [
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'error',
                'message' => 'Cache system failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Get PHP runtime and environment details
     */
    protected function getPhpEnvironment(): array
    {
        return [
            'php_version' => phpversion(),
            'php_sapi' => php_sapi_name(),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time') . ' seconds',
            'loaded_extensions' => implode(', ', get_loaded_extensions()),
            'php_ini_paths' => [
                'loaded_php_ini' => php_ini_loaded_file(),
                'additional_ini_files' => php_ini_scanned_files(),
            ],
            'realpath_cache_size' => ini_get('realpath_cache_size'),
            'output_buffering' => ini_get('output_buffering'),
            'zend_enable_gc' => ini_get('zend.enable_gc'),
        ];
    }

    /**
     * Check Cache functionality
     */
    protected function checkOpCache(): array
    {
        // Check if OPcache is enabled
        if (function_exists('opcache_get_configuration') && function_exists('opcache_get_status')) {
            $opcacheConfig = opcache_get_configuration();
            $opcacheStatus = opcache_get_status();

            return [
                'status' => 'ok',
                'message' => 'OPcache operational',
                'memory_usage' => [
                    'used_memory' => $this->formatBytes($opcacheStatus['memory_usage']['used_memory'] ?? 0),
                    'free_memory' => $this->formatBytes($opcacheStatus['memory_usage']['free_memory'] ?? 0),
                    'wasted_memory' => $this->formatBytes($opcacheStatus['memory_usage']['wasted_memory'] ?? 0),
                    'current_wasted_percentage' => ($opcacheStatus['memory_usage']['current_wasted_percentage'] ?? 0) . '%',
                ],
                'hit_rate' => ($opcacheStatus['opcache_statistics']['hits'] ?? 0) /
                    (($opcacheStatus['opcache_statistics']['hits'] ?? 0) +
                        ($opcacheStatus['opcache_statistics']['misses'] ?? 1)) * 100 . '%',
                'configuration' => [
                    'memory_consumption' => $this->formatBytes($opcacheConfig['directives']['opcache.memory_consumption'] ?? 0),
                    'interned_strings_buffer' => ($opcacheConfig['directives']['opcache.interned_strings_buffer'] ?? 0) . 'MB',
                    'max_accelerated_files' => $opcacheConfig['directives']['opcache.max_accelerated_files'] ?? 0,
                    'revalidate_freq' => $opcacheConfig['directives']['opcache.revalidate_freq'] ?? 0,
                    'fast_shutdown' => (bool) ($opcacheConfig['directives']['opcache.fast_shutdown'] ?? false),
                    'enable_cli' => (bool) ($opcacheConfig['directives']['opcache.enable_cli'] ?? false),
                ],
            ];
        } else {
            return [
                'status' => 'error',
                'message' => 'OPcache extension not available',
            ];
        }

    }

    private function formatBytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * Get environment configuration
     */
    protected function getEnvironment(): array
    {

        return [
            'APP_ENV' => env('APP_ENV'),
            'DB_CONNECTION' => env('DB_CONNECTION'),
            'DB_DATABASE' => env('DB_DATABASE'),
            'QUEUE_CONNECTION' => env('QUEUE_CONNECTION'),
            'CACHE_DRIVER' => env('CACHE_DRIVER'),
            'RABBITMQ_HOST' => env('RABBITMQ_HOST'),
            'GIT_COMMIT' => env('GIT_COMMIT'),
            'GIT_BRANCH' => env('GIT_BRANCH'),
            'GIT_TAG' => env('GIT_TAG'),

        ];

    }
}
