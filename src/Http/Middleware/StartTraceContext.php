<?php

namespace NuiMarkets\LaravelSharedUtils\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use NuiMarkets\LaravelSharedUtils\Support\TraceContext;

/**
 * The HTTP entry for TraceContext: continues the request's inbound
 * X-Amzn-Trace-Id, or mints a root when it has no valid one.
 *
 * TracingServiceProvider prepends it to the global middleware stack, so it runs
 * for every request before anything that reads the context. It writes no log
 * fields: the request's own log lines are RequestLoggingMiddleware's.
 */
class StartTraceContext
{
    public function __construct(private readonly TraceContext $context) {}

    public function handle(Request $request, Closure $next)
    {
        $this->context->startFromHeader($request->headers->get(TraceContext::HEADER));

        return $next($request);
    }
}
