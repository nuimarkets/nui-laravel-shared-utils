<?php

namespace NuiMarkets\LaravelSharedUtils\Support;

use Illuminate\Support\Facades\Log;

trait ProfilingTrait
{
    private static array $timings = [];

    private static float $requestStartTime = 0.0;

    public static function initProfiling(): void
    {
        self::$timings = [];
        self::$requestStartTime = microtime(true);
    }

    protected function profileStart(string $method): float
    {
        // Ensure requestStartTime is set if somehow initProfiling wasn't called
        if (self::$requestStartTime === 0.0) {
            self::$requestStartTime = microtime(true);
        }

        return microtime(true);
    }

    protected function profileEnd(string $method, float $startTime): void
    {
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
        // Guard against logTimings being called before initProfiling eg tests without profiling or no timings
        if (self::$requestStartTime === 0.0) {
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
}
