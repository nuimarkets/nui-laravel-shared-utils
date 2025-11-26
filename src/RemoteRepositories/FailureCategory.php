<?php

namespace NuiMarkets\LaravelSharedUtils\RemoteRepositories;

/**
 * Constants for failure category classification in remote repository caching.
 *
 * These categories are used by CachesFailedLookups trait to determine
 * appropriate TTL values for cached failures based on the type of error.
 */
final class FailureCategory
{
    /**
     * Resource not found (HTTP 404).
     * Typically cached longer since the resource won't magically appear.
     */
    public const NOT_FOUND = 'not_found';

    /**
     * Authentication or authorization error (HTTP 401, 403).
     * Cached longer since credentials/permissions won't self-resolve.
     */
    public const AUTH_ERROR = 'auth_error';

    /**
     * Rate limiting error (HTTP 429).
     * Cached briefly to honor rate limiting.
     */
    public const RATE_LIMITED = 'rate_limited';

    /**
     * Server error (HTTP 5xx).
     * Medium cache duration for server-side issues.
     */
    public const SERVER_ERROR = 'server_error';

    /**
     * Request timeout (cURL error 28, operation timed out).
     * Short cache duration since timeouts are often transient.
     */
    public const TIMEOUT = 'timeout';

    /**
     * Connection error (DNS failure, connection refused, etc.).
     * Short cache duration since network issues are often transient.
     */
    public const CONNECTION_ERROR = 'connection_error';

    /**
     * Other client errors (HTTP 4xx except 401, 403, 404, 429).
     * Cached longer since bad request data won't self-fix.
     */
    public const CLIENT_ERROR = 'client_error';

    /**
     * Unknown or unclassified failure.
     * Uses default TTL.
     */
    public const UNKNOWN = 'unknown';

    /**
     * All transient failure categories that might resolve themselves.
     */
    public const TRANSIENT_CATEGORIES = [
        self::TIMEOUT,
        self::CONNECTION_ERROR,
        self::SERVER_ERROR,
        self::RATE_LIMITED,
    ];

    /**
     * Check if a category represents a transient failure.
     *
     * @param  string  $category  The failure category to check
     * @return bool True if the failure is likely transient
     */
    public static function isTransient(string $category): bool
    {
        return in_array($category, self::TRANSIENT_CATEGORIES, true);
    }
}
