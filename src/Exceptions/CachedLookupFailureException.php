<?php

namespace NuiMarkets\LaravelSharedUtils\Exceptions;

use NuiMarkets\LaravelSharedUtils\RemoteRepositories\FailureCategory;

/**
 * Exception thrown when a lookup fails due to a cached failure.
 *
 * This exception indicates that a remote service lookup was not attempted
 * because a recent failure for the same lookup has been cached. This prevents
 * cascading timeouts during service outages.
 *
 * The exception provides rich context about the original failure, including:
 * - The repository and lookup type that failed
 * - The identifiers that were being looked up
 * - The original exception class and message
 * - The HTTP status code of the original failure
 * - The failure category (not_found, timeout, server_error, etc.)
 * - When the failure was cached
 *
 * @example Catching cached failures
 * ```php
 * try {
 *     $data = $repository->getRelationship($org1, $org2);
 * } catch (CachedLookupFailureException $e) {
 *     // Service was recently unavailable, use fallback
 *     Log::info('Using fallback', ['cached_at' => $e->getCachedAt()]);
 *     return $defaultValue;
 * } catch (\Exception $e) {
 *     // Real failure, handle normally
 *     throw $e;
 * }
 * ```
 * @example Handling specific failure types
 * ```php
 * try {
 *     $data = $repository->getRelationship($org1, $org2);
 * } catch (CachedLookupFailureException $e) {
 *     if ($e->isNotFound()) {
 *         // Resource genuinely doesn't exist
 *         return null;
 *     }
 *     if ($e->isTransient()) {
 *         // Temporary failure - might retry later
 *         throw $e;
 *     }
 * }
 * ```
 */
class CachedLookupFailureException extends \RuntimeException
{
    /**
     * HTTP status code for this exception type.
     */
    public const HTTP_STATUS_CODE = 503;

    private readonly string $repository;

    private readonly string $lookupType;

    /** @var array<string> */
    private readonly array $identifiers;

    private readonly string $originalExceptionClass;

    private readonly string $originalExceptionMessage;

    private readonly string $cachedAt;

    private readonly ?int $httpStatus;

    private readonly string $failureCategory;

    /**
     * Private constructor - use fromCachedData() instead.
     */
    private function __construct(
        string $message,
        string $repository,
        string $lookupType,
        array $identifiers,
        string $originalExceptionClass,
        string $originalExceptionMessage,
        string $cachedAt,
        ?int $httpStatus,
        string $failureCategory
    ) {
        parent::__construct($message, self::HTTP_STATUS_CODE);

        $this->repository = $repository;
        $this->lookupType = $lookupType;
        $this->identifiers = $identifiers;
        $this->originalExceptionClass = $originalExceptionClass;
        $this->originalExceptionMessage = $originalExceptionMessage;
        $this->cachedAt = $cachedAt;
        $this->httpStatus = $httpStatus;
        $this->failureCategory = $failureCategory;
    }

    /**
     * Create exception from cached failure data.
     *
     * @param  array  $cachedData  The cached failure data structure
     * @return static
     */
    public static function fromCachedData(array $cachedData): self
    {
        $repository = $cachedData['repository'] ?? 'unknown';
        $lookupType = $cachedData['lookup_type'] ?? 'unknown';
        $identifiers = $cachedData['identifiers'] ?? [];
        $exceptionClass = $cachedData['exception_class'] ?? 'unknown';
        $exceptionMessage = $cachedData['exception_message'] ?? 'unknown';
        $cachedAt = $cachedData['cached_at'] ?? 'unknown';
        $httpStatus = $cachedData['http_status'] ?? null;
        $failureCategory = $cachedData['failure_category'] ?? FailureCategory::UNKNOWN;

        // Extract short repository name for message
        $shortRepository = class_exists($repository)
            ? (new \ReflectionClass($repository))->getShortName()
            : $repository;

        // Build descriptive message with HTTP status if available
        $identifierStr = implode(', ', $identifiers);
        $statusStr = $httpStatus !== null ? " (HTTP {$httpStatus})" : '';
        $message = sprintf(
            'Cached lookup failure: %s::%s(%s) - original error%s: %s: %s [cached at %s]',
            $shortRepository,
            $lookupType,
            $identifierStr,
            $statusStr,
            $exceptionClass,
            $exceptionMessage,
            $cachedAt
        );

        return new self(
            $message,
            $repository,
            $lookupType,
            $identifiers,
            $exceptionClass,
            $exceptionMessage,
            $cachedAt,
            $httpStatus,
            $failureCategory
        );
    }

    /**
     * Get the HTTP status code for this exception.
     *
     * @return int Always returns 503 (Service Unavailable)
     */
    public function getHttpStatusCode(): int
    {
        return self::HTTP_STATUS_CODE;
    }

    /**
     * Get the full repository class name.
     *
     * @return string The repository class name
     */
    public function getRepository(): string
    {
        return $this->repository;
    }

    /**
     * Get the type of lookup that failed.
     *
     * @return string The lookup type (e.g., 'relationship')
     */
    public function getLookupType(): string
    {
        return $this->lookupType;
    }

    /**
     * Get the identifiers for the failed lookup.
     *
     * @return array<string> The identifiers
     */
    public function getIdentifiers(): array
    {
        return $this->identifiers;
    }

    /**
     * Get the original exception class that caused the failure.
     *
     * @return string The original exception class name
     */
    public function getOriginalExceptionClass(): string
    {
        return $this->originalExceptionClass;
    }

    /**
     * Get the original exception message.
     *
     * @return string The original exception message
     */
    public function getOriginalExceptionMessage(): string
    {
        return $this->originalExceptionMessage;
    }

    /**
     * Get when the failure was cached.
     *
     * @return string ISO 8601 timestamp
     */
    public function getCachedAt(): string
    {
        return $this->cachedAt;
    }

    /**
     * Get the HTTP status code from the original failure.
     *
     * @return int|null The HTTP status code, or null if not available (e.g., network error)
     */
    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    /**
     * Get the failure category.
     *
     * @return string One of the FailureCategory::* constants
     */
    public function getFailureCategory(): string
    {
        return $this->failureCategory;
    }

    /**
     * Check if the original failure was a 404 Not Found.
     *
     * @return bool True if the resource was not found
     */
    public function isNotFound(): bool
    {
        return $this->failureCategory === FailureCategory::NOT_FOUND;
    }

    /**
     * Check if the original failure was a server error (5xx).
     *
     * @return bool True if the failure was a server error
     */
    public function isServerError(): bool
    {
        return $this->failureCategory === FailureCategory::SERVER_ERROR;
    }

    /**
     * Check if the original failure was an authentication/authorization error.
     *
     * @return bool True if the failure was auth-related (401/403)
     */
    public function isAuthError(): bool
    {
        return $this->failureCategory === FailureCategory::AUTH_ERROR;
    }

    /**
     * Check if the original failure was a rate limiting error.
     *
     * @return bool True if the failure was due to rate limiting (429)
     */
    public function isRateLimited(): bool
    {
        return $this->failureCategory === FailureCategory::RATE_LIMITED;
    }

    /**
     * Check if the original failure was likely transient.
     *
     * Transient failures are those that might resolve themselves:
     * - Timeouts
     * - Connection errors
     * - Server errors (5xx)
     * - Rate limiting
     *
     * @return bool True if the failure is likely transient
     */
    public function isTransient(): bool
    {
        return FailureCategory::isTransient($this->failureCategory);
    }
}
