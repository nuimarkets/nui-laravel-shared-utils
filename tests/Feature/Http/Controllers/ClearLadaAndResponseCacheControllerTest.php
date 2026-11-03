<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Feature\Http\Controllers;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use NuiMarkets\LaravelSharedUtils\Auth\CacheAbility;
use NuiMarkets\LaravelSharedUtils\Http\Controllers\ClearLadaAndResponseCacheController;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for ClearLadaAndResponseCacheController.
 *
 * Auth contract via AuthorizesCacheOperations: a request passes with
 * ?token=$VALID_TOKEN, or as a signed-in user the CacheAbility::MANAGE gate
 * allows. Tests without a signed-in user exercise the token path alone.
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

        // Configure the token once; token-path tests pass ?token=.
        config()->set('app.clear_cache_token', self::VALID_TOKEN);

        // Spy logs so the audit-field tests can read the success log. Stub
        // channel() to return the spy: deprecations raised mid-request are
        // routed through $logger->channel('deprecations'), which would
        // otherwise hit the spy's null channel() and 500.
        Log::spy()->shouldReceive('channel')->andReturnSelf();

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

    #[Test]
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

    #[Test]
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

    #[Test]
    public function it_returns_correct_redis_prefix_in_response()
    {
        $response = $this->get('/clear-cache?token='.self::VALID_TOKEN);

        $response->assertStatus(200);
        $this->assertEquals('test_prefix_', $response->json('detail.prefix'));
    }

    #[Test]
    public function it_includes_duration_in_response()
    {
        $response = $this->get('/clear-cache?token='.self::VALID_TOKEN);

        $response->assertStatus(200);
        $this->assertIsNumeric($response->json('detail.duration_ms'));
        $this->assertGreaterThanOrEqual(0, $response->json('detail.duration_ms'));
    }

    #[Test]
    public function it_includes_correct_message_when_cache_not_available()
    {
        $response = $this->get('/clear-cache?token='.self::VALID_TOKEN);

        $response->assertStatus(200);
        $this->assertEquals(
            'Cache clearing skipped. Lada and response caching not found',
            $response->json('message')
        );
    }

    #[Test]
    public function it_restricts_access_without_token()
    {
        $response = $this->get('/clear-cache');

        $response->assertStatus(401);
        $response->assertJson([
            'status' => 'restricted',
            'message' => 'Not available',
        ]);
    }

    #[Test]
    public function it_denies_access_with_invalid_token()
    {
        $response = $this->get('/clear-cache?token=wrong_token');

        $response->assertStatus(401);
    }

    #[Test]
    public function it_denies_access_when_clear_cache_token_unconfigured()
    {
        // Wipe the configured token: even a request carrying any token must fail.
        config()->set('app.clear_cache_token', null);

        $response = $this->get('/clear-cache?token=anything');

        $response->assertStatus(401);
    }

    #[Test]
    public function it_allows_a_user_the_gate_allows_without_a_token()
    {
        $this->allowCacheGate(true);
        $this->signIn(new GenericUser(['id' => 42]));

        $response = $this->get('/clear-cache');

        $response->assertStatus(200);
    }

    #[Test]
    public function it_restricts_a_user_the_gate_denies_without_a_token()
    {
        $this->allowCacheGate(false);
        $this->signIn(new GenericUser(['id' => 42]));

        $response = $this->get('/clear-cache');

        $response->assertStatus(401);
        $response->assertJson([
            'status' => 'restricted',
            'message' => 'Not available',
        ]);
    }

    #[Test]
    public function it_restricts_a_session_user_the_gate_allows_without_a_bearer()
    {
        $this->allowCacheGate(true);
        $this->actingAs(new GenericUser(['id' => 42]), 'web');

        $this->get('/clear-cache')->assertStatus(401);
        $this->withToken('')->get('/clear-cache')->assertStatus(401);
        $this->get('/clear-cache?token='.self::VALID_TOKEN)->assertStatus(200);
    }

    #[Test]
    public function it_allows_a_user_the_gate_denies_with_a_valid_token()
    {
        $this->allowCacheGate(false);
        $this->signIn(new GenericUser(['id' => 42]));

        $response = $this->get('/clear-cache?token='.self::VALID_TOKEN);

        $response->assertStatus(200);
    }

    #[Test]
    public function it_leaves_a_user_to_the_token_when_no_gate_is_defined()
    {
        $this->signIn(new GenericUser(['id' => 42]));

        $this->get('/clear-cache')->assertStatus(401);
        $this->get('/clear-cache?token='.self::VALID_TOKEN)->assertStatus(200);
    }

    #[Test]
    public function it_allows_a_user_the_gate_allows_when_the_token_is_unconfigured()
    {
        config()->set('app.clear_cache_token', '');
        $this->allowCacheGate(true);
        $this->signIn(new GenericUser(['id' => 42]));

        $response = $this->get('/clear-cache');

        $response->assertStatus(200);
    }

    #[Test]
    public function it_logs_the_user_who_cleared_the_cache()
    {
        $this->allowCacheGate(true);
        $this->signIn(new GenericUser(['id' => 42]));

        $response = $this->get('/clear-cache');

        $response->assertStatus(200);
        Log::shouldHaveReceived('info')
            ->withArgs(fn ($message, $context = []) => $message === 'Cache clearing skipped. Lada and response caching not found'
                && ($context['authorized_by'] ?? null) === 'user'
                && ($context['user_id'] ?? null) === 42)
            ->once();
        $this->assertArrayNotHasKey(
            'authorized_by',
            $response->json('detail'),
            'The audit fields belong in the log, not the response.'
        );
    }

    #[Test]
    public function it_logs_a_token_clear_without_the_token()
    {
        $this->get('/clear-cache?token='.self::VALID_TOKEN)->assertStatus(200);

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($message, $context = []) => ($context['authorized_by'] ?? null) === 'token'
                && ! array_key_exists('user_id', $context)
                && ! str_contains(json_encode($context), self::VALID_TOKEN))
            ->once();
    }

    #[Test]
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
     */
    #[Test]
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
     */
    #[Test]
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

    private function allowCacheGate(bool $allow): void
    {
        Gate::define(CacheAbility::MANAGE, fn ($user) => $allow);
    }

    /**
     * Resolve request()->user() to $user through a request guard and send a
     * bearer header, the way a consumer's bearer-token guard would.
     */
    private function signIn(object $user): void
    {
        $this->withToken('cache-test-bearer');
        Auth::viaRequest('cache-test', fn () => $user);
        config()->set('auth.guards.cache-test', ['driver' => 'cache-test']);
        Auth::shouldUse('cache-test');
    }
}
