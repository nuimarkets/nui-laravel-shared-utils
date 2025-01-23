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

    /**
     * Perform all health checks and return results
     */
    public function detailed(): JsonResponse
    {
        $checks = [
            'mysql' => $this->checkMysql(),
            'redis' => $this->checkRedis(),
            'queue' => $this->checkQueue(),
            'storage' => $this->checkStorage(),
            'cache' => $this->checkCache(),
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

            $connection = new AMQPStreamConnection(
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


        ];

    }
}
