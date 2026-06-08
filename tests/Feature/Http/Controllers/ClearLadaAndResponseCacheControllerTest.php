<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Feature\Http\Controllers;

use Illuminate\Support\Facades\Redis;
use NuiMarkets\LaravelSharedUtils\Http\Controllers\ClearLadaAndResponseCacheController;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;

/**
 * Tests for ClearLadaAndResponseCacheController.
 *
 * Auth contract is token-only via AuthorizesCacheOperations: every
 * authorized request carries ?token=$VALID_TOKEN, every unauthorized
 * request omits it (or sends a wrong value).
 *
 * NOTE: Full integration tests with actual Lada cache counting were performed manually
 * in connect-order (Laravel 8) and connect-product (Laravel 9) and verified to work correctly.
 *
 * These unit tests focus on:
 * - Controller response structure
 * - Authorization logic
 * - Behavior when dependencies (Lada/ResponseCache) are not installed
 *
 * @see Manual verification in connect-order:8081 and connect-product:8082
 */
class ClearLadaAndResponseCacheControllerTest extends TestCase
{
    private const VALID_TOKEN = 'secret_token_123';

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        // Set up route for testing
        $app['router']->get('/clear-cache', [ClearLadaAndResponseCacheController::class, 'clearCache']);

        // Configure Redis with test prefix
        $app['config']->set('database.redis.client', 'phpredis');
        $app['config']->set('database.redis.options.prefix', 'test_prefix_');
        $app['config']->set('database.redis.default', [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => 1, // Use DB 1 for tests
        ]);

        // Configure lada-cache prefix
        $app['config']->set('lada-cache.prefix', 'lada:');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Token-only auth: configure once, every authorized test passes ?token=.
        config()->set('app.clear_cache_token', self::VALID_TOKEN);

        // Clear Redis test database before each test
        try {
            Redis::connection('default')->select(1);
            Redis::connection('default')->flushdb();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis not available: '.$e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        // Clean up Redis after each test
        try {
            Redis::connection('default')->select(1);
            Redis::connection('default')->flushdb();
        } catch (\Throwable $e) {
            // Ignore cleanup errors
        }

        parent::tearDown();
    }

    /** @test */
    public function it_returns_correct_response_structure()
    {
        $response = $this->get('/clear-cache?token='.self::VALID_TOKEN);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'message',
            'detail' => [
                'duration_ms',
                'db_index',
                'prefix',
                'summary' => [
                    'lada_cache' => [
                        'total_keys' => ['before', 'after', 'cleared'],
                        'cached_queries' => ['before', 'after', 'cleared'],
                        'tag_keys' => ['before', 'after', 'cleared'],
                    ],
                    'response_cache' => [
                        'cleared',
                        'note',
                    ],
                ],
            ],
        ]);
    }

    /** @test */
    public function it_returns_zeros_when_no_cache_libraries_installed()
    {
        // Without Lada/ResponseCache installed, should return 0s
        $response = $this->get('/clear-cache?token='.self::VALID_TOKEN);

        $response->assertStatus(200);

        $data = $response->json('detail.summary.lada_cache');
        $this->assertEquals(0, $data['total_keys']['before']);
        $this->assertEquals(0, $data['total_keys']['after']);
        $this->assertEquals(0, $data['total_keys']['cleared']);
        $this->assertEquals(0, $data['cached_queries']['before']);
        $this->assertEquals(0, $data['tag_keys']['before']);
    }

    /** @test */
    public function it_returns_correct_redis_prefix_in_response()
    {
        $response = $this->get('/clear-cache?token='.self::VALID_TOKEN);

        $response->assertStatus(200);
        $this->assertEquals('test_prefix_', $response->json('detail.prefix'));
    }

    /** @test */
    public function it_includes_duration_in_response()
    {
        $response = $this->get('/clear-cache?token='.self::VALID_TOKEN);

        $response->assertStatus(200);
        $this->assertIsNumeric($response->json('detail.duration_ms'));
        $this->assertGreaterThanOrEqual(0, $response->json('detail.duration_ms'));
    }

    /** @test */
    public function it_includes_correct_message_when_cache_not_available()
    {
        $response = $this->get('/clear-cache?token='.self::VALID_TOKEN);

        $response->assertStatus(200);
        $this->assertEquals(
            'Cache clearing skipped. Lada and response caching not found',
            $response->json('message')
        );
    }

    /** @test */
    public function it_restricts_access_without_token()
    {
        $response = $this->get('/clear-cache');

        $response->assertStatus(401);
        $response->assertJson([
            'status' => 'restricted',
            'message' => 'Not available',
        ]);
    }

    /** @test */
    public function it_denies_access_with_invalid_token()
    {
        $response = $this->get('/clear-cache?token=wrong_token');

        $response->assertStatus(401);
    }

    /** @test */
    public function it_denies_access_when_clear_cache_token_unconfigured()
    {
        // Wipe the configured token: even a request carrying any token must fail.
        config()->set('app.clear_cache_token', null);

        $response = $this->get('/clear-cache?token=anything');

        $response->assertStatus(401);
    }

    /** @test */
    public function it_returns_response_cache_status()
    {
        $response = $this->get('/clear-cache?token='.self::VALID_TOKEN);

        $response->assertStatus(200);

        $responseCache = $response->json('detail.summary.response_cache');
        $this->assertEquals('not available', $responseCache['cleared']);
        $this->assertStringContainsString('Laravel cache tags', $responseCache['note']);
    }

    /**
     * Exercises the SCAN helper directly against a real Redis. The controller
     * gates it behind class_exists('Spiritix\LadaCache\Cache'), which is not
     * installed in this package's test suite, so we invoke the private helper to
     * verify cursor/prefix handling, query-vs-tag classification, count-only vs
     * delete modes, and that unrelated keys survive.
     *
     * @test
     */
    public function it_scans_and_clears_only_lada_keys()
    {
        $connection = Redis::connection('default');

        // Lada query results: strings keyed {prefix}lada:{md5}.
        $connection->set('lada:'.md5('query-one'), 'cached-result-1');
        $connection->set('lada:'.md5('query-two'), 'cached-result-2');

        // Lada invalidation tag: a set keyed {prefix}lada:tags:database:...
        $connection->sadd('lada:tags:database:Orders:table_specific:orders', 'lada:'.md5('query-one'));

        // Non-lada key that must survive the sweep.
        $connection->set('responsecache:keep', 'do-not-delete');

        $controller = new ClearLadaAndResponseCacheController;
        $countLadaKeys = new \ReflectionMethod($controller, 'countLadaKeys');
        $countLadaKeys->setAccessible(true);
        $clearLadaKeys = new \ReflectionMethod($controller, 'clearLadaKeys');
        $clearLadaKeys->setAccessible(true);

        // countLadaKeys must not remove anything.
        [$queries, $tags] = $countLadaKeys->invoke($controller, $connection, 'test_prefix_', 'lada:');
        $this->assertSame(2, $queries);
        $this->assertSame(1, $tags);
        $this->assertSame(1, $connection->exists('lada:'.md5('query-one')));

        // clearLadaKeys reports the same counts and removes them.
        [$queries, $tags] = $clearLadaKeys->invoke($controller, $connection, 'test_prefix_', 'lada:');
        $this->assertSame(2, $queries);
        $this->assertSame(1, $tags);

        $this->assertSame(0, $connection->exists('lada:'.md5('query-one')));
        $this->assertSame(0, $connection->exists('lada:'.md5('query-two')));
        $this->assertSame(0, $connection->exists('lada:tags:database:Orders:table_specific:orders'));
        $this->assertSame(1, $connection->exists('responsecache:keep'));

        // A follow-up count returns zero - the honest "after" the controller reports.
        [$queries, $tags] = $countLadaKeys->invoke($controller, $connection, 'test_prefix_', 'lada:');
        $this->assertSame(0, $queries);
        $this->assertSame(0, $tags);
    }

    /**
     * Seeds more than SCAN_COUNT (1000) keys so the sweep spans multiple cursor
     * iterations and triggers the mid-loop UNLINK batch flush, not just the
     * trailing remainder. Guards the batching boundary the small test misses.
     *
     * @test
     */
    public function it_clears_lada_keys_across_multiple_scan_batches()
    {
        $connection = Redis::connection('default');

        $total = 1100;
        $connection->pipeline(function ($pipe) use ($total) {
            for ($i = 0; $i < $total; $i++) {
                $pipe->set('lada:'.md5('bulk-'.$i), '1');
            }
        });

        $controller = new ClearLadaAndResponseCacheController;
        $clearLadaKeys = new \ReflectionMethod($controller, 'clearLadaKeys');
        $clearLadaKeys->setAccessible(true);

        [$queries, $tags] = $clearLadaKeys->invoke($controller, $connection, 'test_prefix_', 'lada:');

        $this->assertSame($total, $queries);
        $this->assertSame(0, $tags);

        // Nothing matching the lada pattern survives the multi-batch sweep.
        $this->assertSame([], $connection->keys('lada:*'));
    }
}
