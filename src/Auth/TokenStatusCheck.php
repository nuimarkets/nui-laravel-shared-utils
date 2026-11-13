<?php

namespace NuiMarkets\LaravelSharedUtils\Auth;

/**
 * One TokenStatusClient check: the result plus what the guard logs about it.
 */
final class TokenStatusCheck
{
    /**
     * @param  bool  $cacheHit  The result came from the cache, with no call.
     * @param  string|null  $reason  Why the result is `unavailable`: not_configured,
     *                               missing_jti, breaker_open, machine_token, deadline,
     *                               connection, oversized, http_<status>, malformed or
     *                               unexpected.
     * @param  bool  $cacheError  The cache store could not be read, so the check
     *                            called the issuer without the cache or the breaker.
     */
    public function __construct(
        public readonly TokenStatus $status,
        public readonly bool $cacheHit,
        public readonly ?string $reason,
        public readonly float $latencyMs,
        public readonly bool $cacheError = false,
    ) {}
}
