<?php

namespace NuiMarkets\LaravelSharedUtils\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Clear Lada and Response Cache
 */
class ClearLadaAndResponseCacheController extends Controller
{
    protected function isAuthorizedForDetailedInfo(): bool
    {
        $allowedEnvs = ['local', 'development'];
        $hasValidToken = request()->has('token') &&
            request()->get('token') === env('CLEAR_CACHE_TOKEN');

        return in_array(env('APP_ENV'), $allowedEnvs) || $hasValidToken;
    }

    /**
     * clears lada-cache
     */
    public function clearCache(): JsonResponse
    {

        if (! $this->isAuthorizedForDetailedInfo()) {
            return new JsonResponse([
                'status' => 'restricted',
                'message' => 'Not available',
            ], 401);
        }

        $connection = Redis::connection('default');

        $dbIndex = config('database.redis.default.database');

        $prefix = config('database.redis.options.prefix');
        $ladaPrefix = config('lada-cache.prefix', 'lada:');

        // Laravel's Redis connection automatically adds the prefix when using keys()
        // So we only search for the lada prefix pattern
        $ladaPattern = $ladaPrefix.'*';

        $ladaAvailable = class_exists('Spiritix\LadaCache\Cache');
        $responseCacheAvailable = class_exists('Spatie\ResponseCache\Facades\ResponseCache');

        // Get lada key counts before clearing (queries + tags)
        $ladaKeysBefore = $ladaAvailable
            ? $connection->keys($ladaPattern)
            : [];

        // Separate query cache from tag keys
        $ladaQueriesBefore = array_filter($ladaKeysBefore, function ($key) use ($prefix, $ladaPrefix) {
            // Tags are stored as sets with format: {prefix}lada:tag:{table}
            // Queries are stored as strings with format: {prefix}lada:query:{hash}
            $keyWithoutPrefix = str_replace($prefix.$ladaPrefix, '', $key);

            return ! str_starts_with($keyWithoutPrefix, 'tag:');
        });

        $ladaTagsBefore = array_filter($ladaKeysBefore, function ($key) use ($prefix, $ladaPrefix) {
            $keyWithoutPrefix = str_replace($prefix.$ladaPrefix, '', $key);

            return str_starts_with($keyWithoutPrefix, 'tag:');
        });

        $start = microtime(true);

        if ($ladaAvailable) {
            Artisan::call('lada-cache:flush');
        }

        if ($responseCacheAvailable) {
            $responseCache = app('Spatie\ResponseCache\ResponseCache');
            $responseCache->clear();
        }

        $durationMs = round((microtime(true) - $start) * 1000, 2);

        // Get lada key counts after clearing
        $ladaKeysAfter = $ladaAvailable
            ? $connection->keys($ladaPattern)
            : [];

        $ladaQueriesAfter = array_filter($ladaKeysAfter, function ($key) use ($prefix, $ladaPrefix) {
            $keyWithoutPrefix = str_replace($prefix.$ladaPrefix, '', $key);

            return ! str_starts_with($keyWithoutPrefix, 'tag:');
        });

        $ladaTagsAfter = array_filter($ladaKeysAfter, function ($key) use ($prefix, $ladaPrefix) {
            $keyWithoutPrefix = str_replace($prefix.$ladaPrefix, '', $key);

            return str_starts_with($keyWithoutPrefix, 'tag:');
        });

        $msg = 'Cache clearing skipped. Lada and response caching not found';

        if ($ladaAvailable && $responseCacheAvailable) {
            $msg = 'Lada cache and response cache cleared';
        } elseif ($ladaAvailable) {
            $msg = 'Lada cache cleared';
        } elseif ($responseCacheAvailable) {
            $msg = 'Response cache cleared';
        }

        $detail = [
            'duration_ms' => $durationMs,
            'db_index' => $dbIndex,
            'prefix' => $prefix,

            'summary' => [
                'lada_cache' => [
                    'total_keys' => [
                        'before' => count($ladaKeysBefore),
                        'after' => count($ladaKeysAfter),
                        'cleared' => count($ladaKeysBefore) - count($ladaKeysAfter),
                    ],
                    'cached_queries' => [
                        'before' => count($ladaQueriesBefore),
                        'after' => count($ladaQueriesAfter),
                        'cleared' => count($ladaQueriesBefore) - count($ladaQueriesAfter),
                    ],
                    'tag_keys' => [
                        'before' => count($ladaTagsBefore),
                        'after' => count($ladaTagsAfter),
                        'cleared' => count($ladaTagsBefore) - count($ladaTagsAfter),
                    ],
                ],
                'response_cache' => [
                    'cleared' => $responseCacheAvailable ? 'yes' : 'not available',
                    'note' => 'Response cache uses Laravel cache tags - exact key counts not available',
                ],
            ],
        ];

        Log::info($msg, $detail);

        return new JsonResponse([
            'message' => $msg,
            'detail' => $detail,
        ]);

    }
}
