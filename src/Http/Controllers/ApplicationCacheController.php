<?php

namespace NuiMarkets\LaravelSharedUtils\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use NuiMarkets\LaravelSharedUtils\Http\Controllers\Traits\AuthorizesCacheOperations;

/**
 * Operator-callable endpoints for the Laravel application cache store
 * (the store backing Cache::remember() / Cache::get() calls).
 *
 * Complements ClearLadaAndResponseCacheController, which only targets
 * the lada-cache and Spatie ResponseCache layers. Long-lived
 * application-cache keys previously had no recovery path other than
 * waiting for natural TTL.
 *
 * Two surfaces:
 *  - GET ?action=forget&key=X    surgical, removes one key
 *  - GET ?action=clear-cache&include=app    bulk, flushes the whole store
 *    (delegated from ClearLadaAndResponseCacheController)
 */
class ApplicationCacheController extends Controller
{
    use AuthorizesCacheOperations;

    /**
     * Force-expire a single Cache::remember() key.
     *
     * Returns 401 if unauthorised, 422 if `key` query param missing.
     */
    public function forget(Request $request): JsonResponse
    {
        if (! $this->isAuthorizedForDetailedInfo()) {
            return new JsonResponse([
                'status' => 'restricted',
                'message' => 'Not available',
            ], 401);
        }

        $key = $request->query('key');

        if (! is_string($key) || $key === '') {
            return new JsonResponse([
                'status' => 'invalid',
                'message' => 'Missing required query parameter: key',
            ], 422);
        }

        $start = microtime(true);
        $store = Cache::store();
        $existedBefore = $store->has($key);
        $forgotten = $store->forget($key);
        $durationMs = round((microtime(true) - $start) * 1000, 2);

        $detail = [
            'key' => $key,
            'cache_store' => config('cache.default'),
            'driver' => $this->describeDriver($store),
            'existed_before' => $existedBefore,
            'forgotten' => $forgotten,
            'duration_ms' => $durationMs,
        ];

        $message = $forgotten
            ? 'Application cache key forgotten'
            : 'Application cache key was already absent';

        // Log the SHA-256 of the key, not the raw key. Cache keys can embed
        // user identifiers or tokens and the audit log may ship to wider
        // log surfaces than the immediate operator. The raw key stays in
        // the HTTP response for the caller's own correlation.
        $logContext = $detail;
        $logContext['key_hash'] = hash('sha256', $key);
        unset($logContext['key']);

        Log::info($message, $logContext);

        return new JsonResponse([
            'message' => $message,
            'detail' => $detail,
        ]);
    }

    /**
     * Flush the entire default cache store. Reachable only via
     * ClearLadaAndResponseCacheController::clearCache when the operator
     * passes ?include=app, never registered as its own route.
     *
     * Returns the detail block so the caller can merge it into its own response.
     *
     * @return array<string, mixed>
     */
    public function flushAppCache(): array
    {
        $start = microtime(true);
        $store = Cache::store();
        $flushed = $store->flush();
        $durationMs = round((microtime(true) - $start) * 1000, 2);

        $detail = [
            'flushed' => $flushed,
            'cache_store' => config('cache.default'),
            'driver' => $this->describeDriver($store),
            'duration_ms' => $durationMs,
        ];

        if ($flushed) {
            Log::info('Application cache flushed', $detail);
        } else {
            Log::warning('Application cache flush failed', $detail);
        }

        return $detail;
    }

    /**
     * Identify the underlying cache driver class so operators can confirm
     * they did not just flush an `array` driver in a misconfigured env.
     */
    protected function describeDriver(\Illuminate\Contracts\Cache\Repository $store): string
    {
        $store = method_exists($store, 'getStore') ? $store->getStore() : $store;

        return get_class($store);
    }
}
