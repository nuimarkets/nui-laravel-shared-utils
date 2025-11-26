<?php

namespace NuiMarkets\LaravelSharedUtils\RemoteRepositories\Concerns;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use NuiMarkets\LaravelSharedUtils\Exceptions\CachedLookupFailureException;
use NuiMarkets\LaravelSharedUtils\Logging\LogFields;
use NuiMarkets\LaravelSharedUtils\RemoteRepositories\FailureCategory;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Trait for caching lookup failures in remote repositories.
 *
 * This trait provides a generic pattern for caching failed remote service lookups
 * to prevent cascading timeouts during service outages. When a lookup fails (timeout,
 * 500 error, etc.), subsequent requests for the same lookup will receive a cached
 * failure response instead of retrying the expensive call.
 *
 * The trait supports HTTP status-aware caching with different TTLs for different
 * failure categories:
 * - not_found (404): Long TTL - resource doesn't exist
 * - auth_error (401/403): Long TTL - credentials/permissions won't self-resolve
 * - rate_limited (429): Short TTL - honor rate limiting
 * - server_error (5xx): Medium TTL - server problems
 * - timeout: Short TTL - often transient
 * - connection_error: Short TTL - network issues
 *
 * @see FailureCategory for failure category constants
 *
 * @example Basic usage in a RemoteRepository
 * ```php
 * use NuiMarkets\LaravelSharedUtils\RemoteRepositories\Concerns\CachesFailedLookups;
 *
 * class OrganisationRepository extends UuidValidatingRemoteRepository
 * {
 *     use CachesFailedLookups;
 *
 *     public function getRelationship($firstOrgUuid, $secondOrgUuid)
 *     {
 *         // Check for cached failure first
 *         $this->throwIfCachedLookupFailed('relationship', $firstOrgUuid, $secondOrgUuid);
 *
 *         try {
 *             $res = $this->get("v4/organisations/{$firstOrgUuid}/linked/{$secondOrgUuid}");
 *             return $this->handleResponse($res);
 *         } catch (\Exception $e) {
 *             // Cache the failure for subsequent requests
 *             $this->cacheLookupFailure('relationship', $e, $firstOrgUuid, $secondOrgUuid);
 *             throw $e;
 *         }
 *     }
 * }
 * ```
 * @example Handling cached failures gracefully
 * ```php
 * try {
 *     $rel = $this->getRelationship($firstOrgUuid, $secondOrgUuid);
 * } catch (CachedLookupFailureException $e) {
 *     // This is a cached failure - service was recently unavailable
 *     Log::debug('Using fallback due to cached failure', ['cached_at' => $e->getCachedAt()]);
 *     return $defaultValue;
 * } catch (\Exception $e) {
 *     // This is a new/real failure
 *     throw $e;
 * }
 * ```
 * @example Manual cache invalidation after creating a resource
 * ```php
 * public function createRelationship($firstOrgUuid, $secondOrgUuid, $data)
 * {
 *     $res = $this->post("v4/organisations/{$firstOrgUuid}/linked/{$secondOrgUuid}", $data);
 *
 *     // Clear any cached failure now that relationship exists
 *     $this->clearCachedLookupFailure('relationship', $firstOrgUuid, $secondOrgUuid);
 *
 *     return $this->handleResponse($res);
 * }
 * ```
 */
trait CachesFailedLookups
{
    /**
     * Maximum depth to traverse exception chain when extracting HTTP status.
     */
    private const MAX_EXCEPTION_CHAIN_DEPTH = 5;

    /**
     * Check if lookup has a cached failure and throw if so.
     *
     * Call this method at the start of lookup methods to short-circuit
     * requests that are known to have failed recently.
     *
     * @param  string  $lookupType  Type of lookup (e.g., 'relationship', 'organisation')
     * @param  string  ...$identifiers  Variable number of identifiers for the lookup
     *
     * @throws CachedLookupFailureException if a cached failure exists
     */
    protected function throwIfCachedLookupFailed(string $lookupType, string ...$identifiers): void
    {
        $cacheKey = $this->buildFailureCacheKey($lookupType, $identifiers);
        $cachedData = Cache::get($cacheKey);

        if ($cachedData === null) {
            return;
        }

        // Log cache hit
        Log::info('Remote lookup cache hit - returning cached failure', [
            LogFields::FEATURE => 'remote_repository',
            LogFields::ACTION => 'lookup_failure.cache_hit',
            LogFields::CACHE_HIT => true,
            LogFields::CACHE_KEY => $cacheKey,
            LogFields::API_SERVICE => $this->getRepositoryShortName(),
            LogFields::ENTITY_TYPE => $lookupType,
            LogFields::ENTITY_ID => implode(',', $identifiers),
            LogFields::ERROR_TYPE => $cachedData['exception_class'] ?? 'unknown',
            LogFields::ERROR_MESSAGE => $cachedData['exception_message'] ?? 'unknown',
            'http_status' => $cachedData['http_status'] ?? null,
            'failure_category' => $cachedData['failure_category'] ?? 'unknown',
            'cached_at' => $cachedData['cached_at'] ?? null,
        ]);

        throw CachedLookupFailureException::fromCachedData($cachedData);
    }

    /**
     * Cache a lookup failure with error context.
     *
     * Call this method in the catch block of lookup methods to cache
     * the failure for subsequent requests. The TTL is automatically
     * determined based on the HTTP status code and failure category.
     *
     * @param  string  $lookupType  Type of lookup (e.g., 'relationship', 'organisation')
     * @param  \Throwable  $exception  The exception that caused the failure
     * @param  string  ...$identifiers  Variable number of identifiers for the lookup
     */
    protected function cacheLookupFailure(string $lookupType, \Throwable $exception, string ...$identifiers): void
    {
        $cacheKey = $this->buildFailureCacheKey($lookupType, $identifiers);

        // Extract HTTP status and classify the failure
        $httpStatus = $this->extractHttpStatus($exception);
        $failureCategory = $this->classifyFailure($exception, $httpStatus);
        $ttl = $this->getFailureCacheTtlForCategory($failureCategory);

        $cachedData = [
            'cached_at' => now()->toIso8601String(),
            'exception_class' => get_class($exception),
            'exception_message' => $exception->getMessage(),
            'exception_code' => $exception->getCode(),
            'http_status' => $httpStatus,
            'failure_category' => $failureCategory,
            'repository' => static::class,
            'lookup_type' => $lookupType,
            'identifiers' => $identifiers,
        ];

        Cache::put($cacheKey, $cachedData, $ttl);

        Log::warning('Remote lookup failed - caching failure', [
            LogFields::FEATURE => 'remote_repository',
            LogFields::ACTION => 'lookup_failure.cached',
            LogFields::CACHE_KEY => $cacheKey,
            LogFields::CACHE_TTL => $ttl,
            LogFields::API_SERVICE => $this->getRepositoryShortName(),
            LogFields::ENTITY_TYPE => $lookupType,
            LogFields::ENTITY_ID => implode(',', $identifiers),
            LogFields::ERROR_TYPE => get_class($exception),
            LogFields::ERROR_MESSAGE => $exception->getMessage(),
            LogFields::ERROR_CODE => $exception->getCode(),
            'http_status' => $httpStatus,
            'failure_category' => $failureCategory,
        ]);
    }

    /**
     * Clear a specific cached failure.
     *
     * Call this method after a successful create/update that would
     * invalidate the cached failure (e.g., after creating a relationship
     * that previously didn't exist).
     *
     * @param  string  $lookupType  Type of lookup (e.g., 'relationship', 'organisation')
     * @param  string  ...$identifiers  Variable number of identifiers for the lookup
     */
    protected function clearCachedLookupFailure(string $lookupType, string ...$identifiers): void
    {
        $cacheKey = $this->buildFailureCacheKey($lookupType, $identifiers);

        Cache::forget($cacheKey);

        Log::debug('Remote lookup cache cleared', [
            LogFields::FEATURE => 'remote_repository',
            LogFields::ACTION => 'lookup_failure.cache_cleared',
            LogFields::CACHE_KEY => $cacheKey,
            LogFields::API_SERVICE => $this->getRepositoryShortName(),
            LogFields::ENTITY_TYPE => $lookupType,
            LogFields::ENTITY_ID => implode(',', $identifiers),
        ]);
    }

    /**
     * Get cached failure data for debugging.
     *
     * @param  string  $lookupType  Type of lookup (e.g., 'relationship', 'organisation')
     * @param  string  ...$identifiers  Variable number of identifiers for the lookup
     * @return array|null The cached failure data, or null if no cache exists
     */
    protected function getCachedFailureData(string $lookupType, string ...$identifiers): ?array
    {
        $cacheKey = $this->buildFailureCacheKey($lookupType, $identifiers);

        return Cache::get($cacheKey);
    }

    /**
     * Extract HTTP status code from an exception.
     *
     * Traverses the exception chain to find Guzzle exceptions that contain
     * the actual HTTP response status code. This handles cases where
     * exceptions are wrapped (e.g., RemoteServiceException wraps Guzzle).
     *
     * @param  \Throwable  $exception  The exception to extract status from
     * @return int|null The HTTP status code, or null if not available
     */
    protected function extractHttpStatus(\Throwable $exception): ?int
    {
        // Traverse exception chain to find Guzzle exception
        $current = $exception;
        $maxDepth = self::MAX_EXCEPTION_CHAIN_DEPTH;

        while ($current !== null && $maxDepth > 0) {
            // Guzzle RequestException has response with status
            if ($current instanceof RequestException) {
                $response = $current->getResponse();
                if ($response !== null) {
                    return $response->getStatusCode();
                }
            }

            // ConnectException (timeout, DNS failure) - no HTTP status
            if ($current instanceof ConnectException) {
                return null; // Network-level failure, not HTTP
            }

            // HttpException (Laravel/Symfony) - use getStatusCode()
            if ($current instanceof HttpExceptionInterface) {
                return $current->getStatusCode();
            }

            $current = $current->getPrevious();
            $maxDepth--;
        }

        // Fall back to exception code if it looks like HTTP status
        $code = $exception->getCode();
        if (is_int($code) && $code >= 400 && $code <= 599) {
            return $code;
        }

        return null;
    }

    /**
     * Find the root Guzzle exception in the exception chain.
     *
     * @param  \Throwable  $exception  The exception to search
     * @return \Throwable|null The Guzzle exception, or null if not found
     */
    protected function findGuzzleException(\Throwable $exception): ?\Throwable
    {
        $current = $exception;
        $maxDepth = self::MAX_EXCEPTION_CHAIN_DEPTH;

        while ($current !== null && $maxDepth > 0) {
            if ($current instanceof GuzzleException) {
                return $current;
            }
            $current = $current->getPrevious();
            $maxDepth--;
        }

        return null;
    }

    /**
     * Classify a failure into a category based on HTTP status and exception type.
     *
     * @param  \Throwable  $exception  The exception to classify
     * @param  int|null  $httpStatus  The HTTP status code (if available)
     * @return string The failure category constant (one of FailureCategory::*)
     */
    protected function classifyFailure(\Throwable $exception, ?int $httpStatus): string
    {
        // Network-level failures (no HTTP response)
        if ($httpStatus === null) {
            // Find the Guzzle exception in the chain for classification
            $guzzleException = $this->findGuzzleException($exception);

            if ($guzzleException instanceof ConnectException) {
                $message = $guzzleException->getMessage();
                if (str_contains($message, 'timed out') || str_contains($message, 'cURL error 28')) {
                    return FailureCategory::TIMEOUT;
                }

                return FailureCategory::CONNECTION_ERROR;
            }

            return FailureCategory::UNKNOWN;
        }

        // HTTP status-based classification
        return match (true) {
            $httpStatus === 404 => FailureCategory::NOT_FOUND,
            $httpStatus === 401 || $httpStatus === 403 => FailureCategory::AUTH_ERROR,
            $httpStatus === 429 => FailureCategory::RATE_LIMITED,
            $httpStatus >= 500 && $httpStatus < 600 => FailureCategory::SERVER_ERROR,
            $httpStatus >= 400 && $httpStatus < 500 => FailureCategory::CLIENT_ERROR,
            default => FailureCategory::UNKNOWN,
        };
    }

    /**
     * Get the TTL for a specific failure category.
     *
     * @param  string  $category  The failure category
     * @return int TTL in seconds
     */
    protected function getFailureCacheTtlForCategory(string $category): int
    {
        // Check category-specific TTL first
        $categoryTtls = config('app.remote_repository.failure_cache_ttl_by_category', []);
        if (isset($categoryTtls[$category])) {
            return (int) $categoryTtls[$category];
        }

        // Fall back to default
        return $this->getFailureCacheTtl();
    }

    /**
     * Build the cache key for a lookup failure.
     *
     * Format: remote_failure:{repository_short_name}:{lookup_type}:{hash_of_identifiers}
     *
     * @param  string  $lookupType  Type of lookup
     * @param  array<string>  $identifiers  Array of identifiers
     * @return string The cache key
     */
    protected function buildFailureCacheKey(string $lookupType, array $identifiers): string
    {
        $repositoryName = $this->getRepositoryShortName();
        $identifierHash = md5(implode(':', $identifiers));

        return "remote_failure:{$repositoryName}:{$lookupType}:{$identifierHash}";
    }

    /**
     * Get the short name of the repository for cache keys.
     *
     * Override this method to customize the repository name used in cache keys.
     *
     * @return string The short name (e.g., 'organisationrepository')
     */
    protected function getRepositoryShortName(): string
    {
        // Get the class name without namespace and convert to lowercase
        $className = (new \ReflectionClass($this))->getShortName();

        return strtolower($className);
    }

    /**
     * Get the default TTL for failure cache entries.
     *
     * Override this method to customize the default TTL for specific repositories.
     *
     * @return int TTL in seconds
     */
    protected function getFailureCacheTtl(): int
    {
        return (int) (config('app.remote_repository.failure_cache_ttl') ?? 120);
    }
}
