<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Utils;

use Closure;
use Illuminate\Http\Request;
use NuiMarkets\LaravelSharedUtils\Support\TraceContext;

/**
 * Global middleware standing in for a service's own logging middleware, which
 * the service lists in its HTTP kernel before any provider boots. Records the
 * root it saw, so a test can show the trace was started before it ran.
 */
class TraceContextReadingMiddleware
{
    public static ?string $sawRoot = null;

    public function handle(Request $request, Closure $next)
    {
        self::$sawRoot = app(TraceContext::class)->current();

        return $next($request);
    }
}
