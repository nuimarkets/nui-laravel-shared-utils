<?php

namespace NuiMarkets\LaravelSharedUtils\Http\Controllers\Traits;

/**
 * Shared authorization gate for cache-management endpoints.
 *
 * Token-only: the request must carry `?token=` matching
 * `config('app.clear_cache_token')`. Consumers wire this in their
 * `config/app.php` as `'clear_cache_token' => env('CLEAR_CACHE_TOKEN')`
 * so the value survives `php artisan config:cache`. Set the env var in
 * every environment the endpoint is reachable from (including local `.env`).
 *
 * Used by ClearLadaAndResponseCacheController and ApplicationCacheController
 * so both endpoints share one auth contract.
 */
trait AuthorizesCacheOperations
{
    protected function isAuthorizedForDetailedInfo(): bool
    {
        $configured = config('app.clear_cache_token');

        if (! is_string($configured) || $configured === '') {
            return false;
        }

        return hash_equals($configured, (string) request()->get('token', ''));
    }
}
