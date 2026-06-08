<?php

namespace NuiMarkets\LaravelSharedUtils\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use NuiMarkets\LaravelSharedUtils\Http\Controllers\Traits\AuthorizesCacheOperations;

/**
 * Clear Lada and Response Cache
 */
class ClearLadaAndResponseCacheController extends Controller
{
    use AuthorizesCacheOperations;

    /**
     * Keys scanned (and deleted) per SCAN iteration. Bounds both the cursor
     * batch size and the UNLINK argument list.
     */
    private const SCAN_COUNT = 1000;

    /**
     * clears lada-cache and response-cache. With ?include=app, also flushes
     * the Laravel application cache store (Cache::flush()) and appends an
     * `app_cache` block to the detail payload.
     */
    public function clearCache(Request $request): JsonResponse
    {

        if (! $this->isAuthorizedForDetailedInfo()) {
            return new JsonResponse([
                'status' => 'restricted',
                'message' => 'Not available',
            ], 401);
        }

        $connection = Redis::connection('default');

        $dbIndex = config('database.redis.default.database');

        // Cast: the prefix is optional, and a null would trip str_starts_with()
        // in the scan helpers under PHP 8.x. An empty string disables stripping.
        $prefix = (string) config('database.redis.options.prefix');
        $ladaPrefix = config('lada-cache.prefix', 'lada:');

        $ladaAvailable = class_exists('Spiritix\LadaCache\Cache');
        $responseCacheAvailable = class_exists('Spatie\ResponseCache\Facades\ResponseCache');

        $start = microtime(true);

        $queriesBefore = 0;
        $tagsBefore = 0;
        $queriesCleared = 0;
        $tagsCleared = 0;
        $queriesAfter = 0;
        $tagsAfter = 0;

        if ($ladaAvailable) {
            // Three independent snapshots so every figure is a real measurement:
            // count what exists, the sweep's own removal count, then count what
            // remains. Under concurrent writes these need not reconcile exactly
            // (before - after == cleared), but none is inferred from another.
            [$queriesBefore, $tagsBefore] = $this->countLadaKeys($connection, $prefix, $ladaPrefix);
            [$queriesCleared, $tagsCleared] = $this->clearLadaKeys($connection, $prefix, $ladaPrefix);
            [$queriesAfter, $tagsAfter] = $this->countLadaKeys($connection, $prefix, $ladaPrefix);
        }

        if ($responseCacheAvailable) {
            $responseCache = app('Spatie\ResponseCache\ResponseCache');
            $responseCache->clear();
        }

        $durationMs = round((microtime(true) - $start) * 1000, 2);

        $totalBefore = $queriesBefore + $tagsBefore;
        $totalCleared = $queriesCleared + $tagsCleared;
        $totalAfter = $queriesAfter + $tagsAfter;

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
                        'before' => $totalBefore,
                        'after' => $totalAfter,
                        'cleared' => $totalCleared,
                    ],
                    'cached_queries' => [
                        'before' => $queriesBefore,
                        'after' => $queriesAfter,
                        'cleared' => $queriesCleared,
                    ],
                    'tag_keys' => [
                        'before' => $tagsBefore,
                        'after' => $tagsAfter,
                        'cleared' => $tagsCleared,
                    ],
                ],
                'response_cache' => [
                    'cleared' => $responseCacheAvailable ? 'yes' : 'not available',
                    'note' => 'Response cache uses Laravel cache tags - exact key counts not available',
                ],
            ],
        ];

        if ($request->query('include') === 'app') {
            $detail['app_cache'] = app(ApplicationCacheController::class)
                ->flushAppCache();
        }

        Log::info($msg, $detail);

        return new JsonResponse([
            'message' => $msg,
            'detail' => $detail,
        ]);

    }

    /**
     * Delete every lada-cache key via UNLINK and return how many of each type
     * were swept (SCAN may surface a key twice; UNLINK on an already-removed
     * key is a no-op, so a rare duplicate is counted but not double-deleted).
     *
     * Used in place of the package's KEYS-based flush(), which blocks
     * single-threaded Redis for an O(keyspace) scan on every call. The cursor
     * SCAN underneath yields between batches, so the server is never frozen;
     * UNLINK frees memory off the main thread.
     *
     * @return array{0:int,1:int} [queryCount, tagCount]
     */
    private function clearLadaKeys($connection, string $prefix, string $ladaPrefix): array
    {
        $tagMarker = $ladaPrefix.'tags:';
        $queryCount = 0;
        $tagCount = 0;
        $batch = [];

        foreach ($this->scanLadaKeys($connection, $prefix, $ladaPrefix) as $ladaKey) {
            str_starts_with($ladaKey, $tagMarker) ? $tagCount++ : $queryCount++;

            $batch[] = $ladaKey;

            if (count($batch) >= self::SCAN_COUNT) {
                $connection->unlink(...$batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $connection->unlink(...$batch);
        }

        return [$queryCount, $tagCount];
    }

    /**
     * Count lada-cache keys by type without modifying Redis. Used for the
     * post-clear "after" figure.
     *
     * @return array{0:int,1:int} [queryCount, tagCount]
     */
    private function countLadaKeys($connection, string $prefix, string $ladaPrefix): array
    {
        $tagMarker = $ladaPrefix.'tags:';
        $queryCount = 0;
        $tagCount = 0;

        foreach ($this->scanLadaKeys($connection, $prefix, $ladaPrefix) as $ladaKey) {
            str_starts_with($ladaKey, $tagMarker) ? $tagCount++ : $queryCount++;
        }

        return [$queryCount, $tagCount];
    }

    /**
     * Walk all lada-cache keys with a non-blocking cursor SCAN, yielding each in
     * its lada-relative form (connection prefix stripped).
     *
     * Lada caches query results as `{prefix}lada:{md5}` strings and invalidation
     * tags as `{prefix}lada:tags:...` sets, so a yielded key starting with
     * `{ladaPrefix}tags:` is a tag and the rest are queries. SCAN may surface the
     * same key twice, which callers tolerate (UNLINK is idempotent).
     *
     * @return \Generator<string>
     */
    private function scanLadaKeys($connection, string $prefix, string $ladaPrefix): \Generator
    {
        // phpredis does not auto-prefix a SCAN MATCH (keys() does), so add it here.
        $pattern = $prefix.$ladaPrefix.'*';

        // Must start null, not 0 - phpredis returns nothing for an integer-0 cursor.
        $cursor = null;

        do {
            $response = $connection->scan($cursor, ['match' => $pattern, 'count' => self::SCAN_COUNT]);

            if ($response === false) {
                break;
            }

            [$cursor, $keys] = $response;

            foreach ((array) $keys as $key) {
                // Normalise to the lada-relative form: results may or may not carry
                // the connection prefix, and UNLINK re-applies it.
                yield ($prefix !== '' && str_starts_with($key, $prefix))
                    ? substr($key, strlen($prefix))
                    : $key;
            }
        } while ((int) $cursor !== 0);
    }
}
