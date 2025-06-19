<?php

namespace Nuimarkets\LaravelSharedUtils\Http\Controllers;

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

        $ladaPattern = 'lada:*';
        $responseCachePattern = 'responsecache:*';

        $ladaKeysBefore = $connection->command('KEYS', [$ladaPattern]);
        $responseKeysBefore = $connection->command('KEYS', [$responseCachePattern]);

        $start = microtime(true);

        $ladaAvailable = class_exists('Spiritix\LadaCache\Cache');
        $responseCacheAvailable = class_exists('Spatie\ResponseCache\Facades\ResponseCache');

        if ($ladaAvailable) {
            Artisan::call('lada-cache:flush');
        }

        if ($responseCacheAvailable) {
            $responseCache = app('Spatie\ResponseCache\ResponseCache');
            $responseCache->clear();
        }

        $durationMs = round((microtime(true) - $start) * 1000, 2);

        $ladaKeysAfter = $connection->command('KEYS', [$ladaPattern]);
        $responseKeysAfter = $connection->command('KEYS', [$responseCachePattern]);

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
                    'before' => count($ladaKeysBefore),
                    'after' => count($ladaKeysAfter),
                    'cleared' => count($ladaKeysBefore) - count($ladaKeysAfter),
                ],
                'response_cache' => [
                    'before' => count($responseKeysBefore),
                    'after' => count($responseKeysAfter),
                    'cleared' => count($responseKeysBefore) - count($responseKeysAfter),
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
