<?php

namespace NuiMarkets\LaravelSharedUtils\Http\Controllers\Traits;

/**
 * Shared authorization gate for cache-management endpoints.
 *
 * Allows access when:
 * - APP_ENV is in the allowed list (local, development), OR
 * - The request carries a ?token= matching CLEAR_CACHE_TOKEN env var.
 *
 * Used by ClearLadaAndResponseCacheController and ApplicationCacheController
 * so both endpoints share one auth contract.
 */
trait AuthorizesCacheOperations
{
    protected function isAuthorizedForDetailedInfo(): bool
    {
        $allowedEnvs = ['local', 'development'];
        $hasValidToken = request()->has('token') &&
            request()->get('token') === env('CLEAR_CACHE_TOKEN');

        return in_array(env('APP_ENV'), $allowedEnvs) || $hasValidToken;
    }
}
