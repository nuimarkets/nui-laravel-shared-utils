<?php

namespace Nuimarkets\LaravelSharedUtils\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Request Metrics
 */
class RequestMetrics
{
    public function handle(Request $request, Closure $next)
    {
        // Mark start time
        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        // Handle the request
        $response = $next($request);

        // Skip logging on "/" it's used for health check
        if ($request->path() == "/")
            return $response;

        // Calculate metrics
        $duration = microtime(true) - $startTime;
        $memoryUsage = memory_get_usage() - $startMemory;
        $peakMemory = memory_get_peak_usage(true);

        // Log the metrics
        Log::info('Request end', [
            'path' => $request->path(),
            'method' => $request->method(),
            'route' => $request->route()?->getName(),
            'status' => $response->getStatusCode(),
            'duration_ms' => round($duration * 1000, 2),
            'memory_usage' => $this->formatBytes($memoryUsage),
            'peak_memory' => $this->formatBytes($peakMemory),
        ]);

        return $response;
    }

    private function formatBytes($bytes)
    {
        if ($bytes > 1024 * 1024) {
            return round($bytes / 1024 / 1024, 2) . 'MB';
        }
        if ($bytes > 1024) {
            return round($bytes / 1024, 2) . 'KB';
        }
        return $bytes . 'B';
    }
}