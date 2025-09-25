<?php

namespace NuiMarkets\LaravelSharedUtils\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use NuiMarkets\LaravelSharedUtils\Auth\JWTUser;
use NuiMarkets\LaravelSharedUtils\Logging\LogFields;

/**
 * Base middleware for adding request context to all logs.
 * Services can extend this class to add service-specific context.
 *
 * This middleware automatically adds:
 * - Request ID (from header or generated)
 * - Request method, path, and IP
 * - User context (if authenticated)
 * - Custom context via extending classes
 */
abstract class RequestLoggingMiddleware
{
    /**
     * The header name to check for existing request ID.
     */
    protected string $requestIdHeader = 'X-Request-ID';

    /**
     * Whether to add the request ID to response headers.
     */
    protected bool $addRequestIdToResponse = true;

    /**
     * Whether to add the trace ID to response headers.
     */
    protected bool $addTraceIdToResponse = true;

    /**
     * Paths to exclude from logging (empty by default).
     * Services can override this property to customize excluded paths.
     */
    protected array $excludedPaths = [];

    /**
     * Whether to include request payload in request start logs.
     * Disabled by default for security - only enable when needed.
     */
    protected bool $logRequestPayload = false;

    /**
     * Handle an incoming request and set logging context.
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Skip excluded paths (configurable) - supports exact matches, prefixes, and globs
        $requestPath = '/'.ltrim($request->path(), '/');
        $excluded = array_map(static fn ($path) => '/'.ltrim($path, '/'), $this->excludedPaths);

        foreach ($excluded as $pattern) {
            if ($requestPath === $pattern
                || \Illuminate\Support\Str::is($pattern, $requestPath)
                || \Illuminate\Support\Str::startsWith($requestPath, rtrim($pattern, '/*'))
            ) {
                return $next($request);
            }
        }

        // Mark performance start time
        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        // Generate or retrieve request ID
        $requestId = $this->getRequestId($request);

        // Build base context for this request
        $context = $this->buildBaseContext($request, $requestId);

        // Add user context if authenticated
        $context = $this->addUserContext($request, $context);

        // Add service-specific context
        $context = $this->addServiceContext($request, $context);

        // Set the context for all logs in this request
        Log::withContext($context);

        // Log the request start if enabled
        $this->logRequestStart($request, $context);

        // Continue processing the request
        $response = $next($request);

        // Add request ID to response headers if enabled
        if ($this->addRequestIdToResponse && $response) {
            $response->headers->set($this->requestIdHeader, $requestId);
        }

        // Add trace ID to response headers if enabled
        if ($this->addTraceIdToResponse && $response) {
            $traceId = $this->extractTraceId($request);
            if ($traceId) {
                $response->headers->set('X-Trace-ID', $traceId);
            }
        }

        // Calculate performance metrics
        $duration = microtime(true) - $startTime;
        $memoryUsage = memory_get_usage() - $startMemory;
        $peakMemory = memory_get_peak_usage(true);

        // Log the request completion with metrics
        $this->logRequestComplete($request, $response, $context, [
            'duration_ms' => round($duration * 1000, 2),
            'memory_usage_mb' => round($memoryUsage / (1024 * 1024), 2),
            'peak_memory_mb' => round($peakMemory / (1024 * 1024), 2),
        ]);

        return $response;
    }

    /**
     * Get or generate a request ID.
     */
    protected function getRequestId(Request $request): string
    {
        return $request->headers->get($this->requestIdHeader, Str::uuid()->toString());
    }

    /**
     * Extract and normalize AWS X-Ray trace ID from request headers.
     */
    protected function extractTraceId(Request $request): ?string
    {
        $traceHeader = $request->headers->get('X-Amzn-Trace-Id');

        if (! $traceHeader) {
            return null;
        }

        // AWS X-Ray trace ID format: "Root=1-67a92466-4b6aa15a05ffcd4c510de968;Parent=53995c3f42cd8ad8;Sampled=1"
        // Extract just the trace ID part (remove "Root=" prefix and any additional segments)
        if (preg_match('/Root=([^;]+)/', $traceHeader, $matches)) {
            return $matches[1];
        }

        return $traceHeader;
    }

    /**
     * Build the base logging context.
     */
    protected function buildBaseContext(Request $request, string $requestId): array
    {
        return [
            LogFields::REQUEST_ID => $requestId,
            LogFields::TRACE_ID => $this->extractTraceId($request),
            LogFields::TRACE_ID_HEADER => $request->headers->get('X-Amzn-Trace-Id'),
            LogFields::REQUEST_METHOD => $request->method(),
            LogFields::REQUEST_PATH => $request->path(),
            LogFields::REQUEST_IP => $request->ip(),
            LogFields::REQUEST_USER_AGENT => $request->userAgent(),
        ];
    }

    /**
     * Add user context to the logging context.
     */
    protected function addUserContext(Request $request, array $context): array
    {
        try {
            if ($user = $request->user()) {
                // Use REQUEST_ constants since this data comes from JWT/session
                $context[LogFields::REQUEST_USER_ID] = $this->getUserId($user);
                $context[LogFields::REQUEST_ORG_ID] = $this->getUserOrgId($user);

                // Add additional user fields if available from JWT/session context
                if ($email = $this->getUserEmail($user)) {
                    $context[LogFields::REQUEST_USER_EMAIL] = $email;
                }

                if ($userType = $this->getUserType($user)) {
                    $context[LogFields::REQUEST_USER_TYPE] = $userType;
                }

                if ($orgName = $this->getUserOrgName($user)) {
                    $context[LogFields::REQUEST_ORG_NAME] = $orgName;
                }

                if ($orgType = $this->getUserOrgType($user)) {
                    $context[LogFields::REQUEST_ORG_TYPE] = $orgType;
                }
            }
        } catch (\Exception $e) {
            // Auth guard not configured or other auth issues - continue without user context
            // This commonly happens in test environments
        }

        return $context;
    }

    /**
     * Add service-specific context to the logging context.
     * Services should override this method to add custom context.
     */
    abstract protected function addServiceContext(Request $request, array $context): array;

    /**
     * Log the start of a request.
     * Provides default implementation - override if needed.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function logRequestStart(Request $request, array $context): void
    {
        if (! $this->shouldLogRequestStart()) {
            return;
        }

        $logData = [
            LogFields::TARGET => $this->getServiceName(),
            LogFields::FEATURE => 'requests',
            LogFields::ACTION => 'request.start',
            LogFields::REQUEST_METHOD => $request->method(),
            LogFields::REQUEST_PATH => $request->path(),
            LogFields::REQUEST_IP => $request->ip(),
            LogFields::REQUEST_USER_AGENT => $request->userAgent(),
            'route_name' => $request->route() ? $request->route()->getName() : 'unknown',
        ];

        // Add payload for POST/PUT/PATCH/DELETE requests (only if enabled)
        if ($this->logRequestPayload && in_array($request->method(), ['POST', 'PATCH', 'PUT', 'DELETE'])) {
            // Exclude files and binary content; limit payload size
            $payload = $request->except(array_keys($request->allFiles()));
            // Optionally trim large string fields
            array_walk_recursive($payload, static function (&$v) {
                if (is_string($v) && strlen($v) > 2000) {
                    $v = substr($v, 0, 2000).'…';
                }
            });
            $logData[LogFields::REQUEST_BODY] = $payload;
        }

        Log::info('Request start', $logData);
    }

    /**
     * Whether to log request start. Override to control logging behavior.
     */
    protected function shouldLogRequestStart(): bool
    {
        return true;
    }

    /**
     * Log the completion of a request.
     * Provides default implementation - override if needed.
     *
     * @param  mixed  $response
     * @param  array  $metrics  Performance metrics
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function logRequestComplete(Request $request, $response, array $context, array $metrics = []): void
    {
        if (! $this->shouldLogRequestComplete()) {
            return;
        }

        $statusCode = ($response && method_exists($response, 'getStatusCode'))
            ? $response->getStatusCode()
            : 0;

        $responseSize = 0;
        if ($response && isset($response->headers)) {
            $len = $response->headers->get('Content-Length');
            if (is_numeric($len)) {
                $responseSize = (int) $len;
            }
        }

        if ($responseSize === 0 && $response && method_exists($response, 'getContent')) {
            $content = $response->getContent();
            $responseSize = is_string($content) ? strlen($content) : 0; // avoid TypeError on streamed responses
        }

        Log::info('Request complete', [
            LogFields::TARGET => $this->getServiceName(),
            LogFields::FEATURE => 'requests',
            LogFields::ACTION => 'request.complete',
            'request' => [
                'method' => $request->method(),
                'path' => $request->path(),
                'route_name' => $request->route() ? $request->route()->getName() : 'unknown',
            ],
            'response' => [
                LogFields::STATUS => $statusCode,
                LogFields::RESPONSE_SIZE_BYTES => $responseSize,
            ],
            'performance' => $metrics,
        ]);
    }

    /**
     * Whether to log request completion. Override to control logging behavior.
     */
    protected function shouldLogRequestComplete(): bool
    {
        return true;
    }

    /**
     * Get the service name for logging. Override in child classes.
     */
    protected function getServiceName(): string
    {
        return 'service';
    }

    /**
     * Get the user ID from the authenticated user.
     * Services can override this to match their user model.
     *
     * @param  JWTUser|mixed  $user
     * @return mixed
     */
    protected function getUserId($user)
    {
        return $user->id ?? null;
    }

    /**
     * Get the organization ID from the authenticated user.
     * Services can override this to match their user model.
     *
     * @param  JWTUser|mixed  $user
     * @return mixed
     */
    protected function getUserOrgId($user)
    {
        return $user->org_id ?? null;
    }

    /**
     * Get the email from the authenticated user.
     * Services can override this to match their user model.
     *
     * @param  JWTUser|mixed  $user
     */
    protected function getUserEmail($user): ?string
    {
        return $user->email ?? null;
    }

    /**
     * Get the user type from the authenticated user.
     * Services can override this to match their user model.
     *
     * @param  JWTUser|mixed  $user
     */
    protected function getUserType($user): ?string
    {
        return $user->role ?? null;
    }

    /**
     * Get the organization name from the authenticated user.
     * Services can override this to match their user model.
     *
     * @param  JWTUser|mixed  $user
     */
    protected function getUserOrgName($user): ?string
    {
        return $user->org_name ?? null;
    }

    /**
     * Get the organization type from the authenticated user.
     * Services can override this to match their user model.
     *
     * @param  JWTUser|mixed  $user
     */
    protected function getUserOrgType($user): ?string
    {
        return $user->org_type ?? null;
    }

    /**
     * Configure the middleware from an array of options.
     */
    public function configure(array $options): self
    {
        if (isset($options['request_id_header'])) {
            $this->requestIdHeader = $options['request_id_header'];
        }

        if (isset($options['add_request_id_to_response'])) {
            $this->addRequestIdToResponse = (bool) $options['add_request_id_to_response'];
        }

        if (isset($options['add_trace_id_to_response'])) {
            $this->addTraceIdToResponse = (bool) $options['add_trace_id_to_response'];
        }

        if (isset($options['excluded_paths'])) {
            $this->excludedPaths = (array) $options['excluded_paths'];
        }

        if (isset($options['log_request_payload'])) {
            $this->logRequestPayload = (bool) $options['log_request_payload'];
        }

        return $this;
    }
}
