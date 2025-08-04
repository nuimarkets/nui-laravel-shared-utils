<?php

namespace NuiMarkets\LaravelSharedUtils\Exceptions;

/**
 * Exception thrown when remote service calls fail
 *
 * This exception is used to represent failures when calling external services.
 * It properly returns HTTP 502 (Bad Gateway) or 503 (Service Unavailable) status codes
 * instead of the generic 500 error.
 *
 * @example
 * // Bad Gateway - remote service returned an error
 * throw new RemoteServiceException('Remote API returned error', 502);
 *
 * // Service Unavailable - remote service is down/timeout
 * throw new RemoteServiceException('Service timeout', 503);
 */
class RemoteServiceException extends BaseHttpRequestException
{
    /**
     * Create a new RemoteServiceException instance
     *
     * @param  string  $message  The error message
     * @param  int  $statusCode  HTTP status code (default: 502 Bad Gateway)
     * @param  \Throwable|null  $previous  The previous exception for chaining
     * @param  array  $tags  Tags for Sentry categorization
     * @param  array  $extra  Extra context data for logging/Sentry
     */
    public function __construct(
        string $message,
        int $statusCode = 502,
        ?\Throwable $previous = null,
        array $tags = [],
        array $extra = []
    ) {
        parent::__construct($message, $statusCode, $previous, $tags, $extra);
    }
}
