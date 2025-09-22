<?php

namespace NuiMarkets\LaravelSharedUtils\Support;

use Illuminate\Support\Facades\Log;

trait ProfilingTrait
{
    private static array $timings = [];

    private static float $requestStartTime = 0.0;

    public static function initProfiling(): void
    {
        if (!self::isProfilingEnabled()) {
            return;
        }

        self::$timings = [];
        self::$requestStartTime = microtime(true);
    }

    protected function profileStart(string $method): float
    {
        if (!self::isProfilingEnabled()) {
            return 0.0;
        }

        // Ensure requestStartTime is set if somehow initProfiling wasn't called
        if (self::$requestStartTime === 0.0) {
            self::$requestStartTime = microtime(true);
        }

        return microtime(true);
    }

    protected function profileEnd(string $method, float $startTime): void
    {
        if (!self::isProfilingEnabled() || $startTime === 0.0) {
            return;
        }

        $duration = microtime(true) - $startTime;
        $className = get_class($this);

        if (! isset(self::$timings[$className])) {
            self::$timings[$className] = [
                'total_time' => 0,
                'calls' => [],
            ];
        }

        self::$timings[$className]['total_time'] += $duration;
        self::$timings[$className]['calls'][] = [
            'method' => $method,
            'duration' => $duration,
        ];
    }

    public static function logTimings(): void
    {
        // Guard against logTimings being called before initProfiling or when profiling disabled
        if (!self::isProfilingEnabled() || self::$requestStartTime === 0.0) {
            return;
        }

        $totalRequestTime = microtime(true) - self::$requestStartTime;

        foreach (self::$timings as $className => $timing) {
            $percentage = round(($timing['total_time'] / $totalRequestTime) * 100);

            Log::debug('Remote repository timing', [
                'class' => $className,
                'total_seconds' => round($timing['total_time'], 3),
                'request_percentage' => $percentage.'%',
                'calls' => count($timing['calls']),
                'calls_breakdown' => collect($timing['calls'])
                    ->map(fn ($call) => [
                        'method' => $call['method'],
                        'seconds' => round($call['duration'], 3),
                    ])
                    ->toArray(),
            ]);
        }
    }

    /**
     * Check if profiling is enabled via configuration
     */
    private static function isProfilingEnabled(): bool
    {
        return config('logging-utils.remote_repository.enable_profiling', false);
    }
}
