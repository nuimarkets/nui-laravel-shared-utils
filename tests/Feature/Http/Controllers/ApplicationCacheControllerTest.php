<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Feature\Http\Controllers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use NuiMarkets\LaravelSharedUtils\Http\Controllers\ApplicationCacheController;
use NuiMarkets\LaravelSharedUtils\Http\Controllers\ClearLadaAndResponseCacheController;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;

/**
 * Tests for ApplicationCacheController + the ?include=app delegation
 * from ClearLadaAndResponseCacheController.
 *
 * Uses the array cache driver so tests don't depend on Redis being up.
 * The driver class name is verified in the response payload as a regression
 * guard against accidentally flushing a misconfigured store.
 */
class ApplicationCacheControllerTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['router']->get('/forget', [ApplicationCacheController::class, 'forget']);
        $app['router']->get('/clear-cache', [ClearLadaAndResponseCacheController::class, 'clearCache']);

        putenv('APP_ENV=local');
        $app['config']->set('app.env', 'local');

        // Use array driver so the cache facade itself doesn't need Redis.
        // The /clear-cache delegation tests still touch
        // Redis::connection('default') via the sibling controller, those
        // skip themselves if Redis is unreachable (skipIfNoRedis).
        $app['config']->set('cache.default', 'array');

        $app['config']->set('database.redis.client', 'phpredis');
        $app['config']->set('database.redis.default', [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => 1,
        ]);
    }

    /**
     * Skip when no Redis is reachable. Used by the three tests that hit
     * /clear-cache, which routes through ClearLadaAndResponseCacheController
     * and lazily resolves Redis::connection('default').
     */
    protected function skipIfNoRedis(): void
    {
        try {
            Redis::connection('default')->ping();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis not available: '.$e->getMessage());
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        putenv('APP_ENV=local');
        $_ENV['APP_ENV'] = 'local';
        $_SERVER['APP_ENV'] = 'local';

        // Spy logs so the controller's Log::info() calls don't try to write
        // to disk. Keeps tests portable to sandboxes / CI runners with a
        // read-only filesystem under vendor/.
        Log::spy();

        Cache::store()->flush();
    }

    protected function tearDown(): void
    {
        Cache::store()->flush();

        parent::tearDown();
    }

    /** @test */
    public function forget_removes_a_known_key()
    {
        Cache::put('lookup:countries:all', ['NZ', 'AU'], 600);
        $this->assertTrue(Cache::has('lookup:countries:all'));

        $response = $this->get('/forget?key=lookup:countries:all');

        $response->assertStatus(200);
        $response->assertJsonPath('detail.key', 'lookup:countries:all');
        $response->assertJsonPath('detail.existed_before', true);
        $response->assertJsonPath('detail.forgotten', true);
        $response->assertJsonPath('detail.cache_store', 'array');
        $this->assertFalse(Cache::has('lookup:countries:all'));
    }

    /** @test */
    public function forget_reports_existed_before_false_when_key_missing()
    {
        $response = $this->get('/forget?key=cache:absent:all');

        $response->assertStatus(200);
        $response->assertJsonPath('detail.existed_before', false);
        $response->assertJsonPath('detail.forgotten', false);
        // Message disambiguates the silent-no-op from a real forget.
        $response->assertJsonPath('message', 'Application cache key was already absent');
    }

    /** @test */
    public function forget_returns_422_when_key_missing()
    {
        $response = $this->get('/forget');

        $response->assertStatus(422);
        $response->assertJson([
            'status' => 'invalid',
            'message' => 'Missing required query parameter: key',
        ]);
    }

    /** @test */
    public function forget_returns_422_when_key_blank()
    {
        $response = $this->get('/forget?key=');

        $response->assertStatus(422);
    }

    /** @test */
    public function forget_returns_401_in_production_without_token()
    {
        putenv('APP_ENV=production');
        $_ENV['APP_ENV'] = 'production';
        $_SERVER['APP_ENV'] = 'production';

        $response = $this->get('/forget?key=anything');

        $response->assertStatus(401);
        $response->assertJson([
            'status' => 'restricted',
            'message' => 'Not available',
        ]);

        putenv('APP_ENV=local');
        $_ENV['APP_ENV'] = 'local';
        $_SERVER['APP_ENV'] = 'local';
    }

    /** @test */
    public function forget_allows_access_in_production_with_valid_token()
    {
        putenv('APP_ENV=production');
        $_ENV['APP_ENV'] = 'production';
        $_SERVER['APP_ENV'] = 'production';

        putenv('CLEAR_CACHE_TOKEN=secret_token_xyz');
        $_ENV['CLEAR_CACHE_TOKEN'] = 'secret_token_xyz';

        Cache::put('something', 'value', 600);

        $response = $this->get('/forget?key=something&token=secret_token_xyz');

        $response->assertStatus(200);
        $response->assertJsonPath('detail.forgotten', true);

        putenv('APP_ENV=local');
        $_ENV['APP_ENV'] = 'local';
        $_SERVER['APP_ENV'] = 'local';
        putenv('CLEAR_CACHE_TOKEN');
        unset($_ENV['CLEAR_CACHE_TOKEN']);
    }

    /** @test */
    public function clear_cache_with_include_app_flushes_application_cache()
    {
        $this->skipIfNoRedis();

        Cache::put('lookup:countries:all', ['NZ'], 600);
        Cache::put('lookup:currencies:all', ['NZD'], 600);
        $this->assertTrue(Cache::has('lookup:countries:all'));

        $response = $this->get('/clear-cache?include=app');

        $response->assertStatus(200);
        $response->assertJsonPath('detail.app_cache.flushed', true);
        $response->assertJsonPath('detail.app_cache.cache_store', 'array');
        $this->assertFalse(Cache::has('lookup:countries:all'));
        $this->assertFalse(Cache::has('lookup:currencies:all'));
    }

    /** @test */
    public function clear_cache_without_include_app_does_not_flush_application_cache()
    {
        $this->skipIfNoRedis();

        Cache::put('lookup:countries:all', ['NZ'], 600);

        $response = $this->get('/clear-cache');

        $response->assertStatus(200);
        $this->assertArrayNotHasKey(
            'app_cache',
            $response->json('detail') ?? [],
            'detail.app_cache must be absent when ?include=app is not set.'
        );
        $this->assertTrue(
            Cache::has('lookup:countries:all'),
            'Application cache must not be flushed when ?include=app is absent (regression guard).'
        );
    }

    /** @test */
    public function clear_cache_with_include_app_returns_401_when_unauthorized()
    {
        // No skipIfNoRedis() here: the auth gate returns 401 before
        // clearCache() ever touches Redis::connection('default'), so this
        // test must run even when Redis is unavailable.
        putenv('APP_ENV=production');
        $_ENV['APP_ENV'] = 'production';
        $_SERVER['APP_ENV'] = 'production';

        Cache::put('lookup:countries:all', ['NZ'], 600);

        $response = $this->get('/clear-cache?include=app');

        $response->assertStatus(401);
        $this->assertTrue(
            Cache::has('lookup:countries:all'),
            'Application cache must not be touched on a 401 response.'
        );

        putenv('APP_ENV=local');
        $_ENV['APP_ENV'] = 'local';
        $_SERVER['APP_ENV'] = 'local';
    }
}
