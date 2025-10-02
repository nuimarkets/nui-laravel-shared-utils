<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Feature\Http\Controllers;

use Illuminate\Support\Facades\Redis;
use NuiMarkets\LaravelSharedUtils\Http\Controllers\ClearLadaAndResponseCacheController;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;

/**
 * Tests for ClearLadaAndResponseCacheController
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
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        // Set up route for testing
        $app['router']->get('/clear-cache', [ClearLadaAndResponseCacheController::class, 'clearCache']);

        // Set environment to local to allow access without token
        // Note: Controller uses env() not config(), so we need to set the env var
        putenv('APP_ENV=local');
        $app['config']->set('app.env', 'local');

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

        // Set APP_ENV for controller authorization check
        // Must be done in setUp() as env() reads at runtime
        putenv('APP_ENV=local');
        $_ENV['APP_ENV'] = 'local';
        $_SERVER['APP_ENV'] = 'local';

        // Clear Redis test database before each test
        try {
            Redis::connection('default')->select(1);
            Redis::connection('default')->flushdb();
        } catch (\Exception $e) {
            $this->markTestSkipped('Redis not available: '.$e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        // Clean up Redis after each test
        try {
            Redis::connection('default')->select(1);
            Redis::connection('default')->flushdb();
        } catch (\Exception $e) {
            // Ignore cleanup errors
        }

        parent::tearDown();
    }

    /** @test */
    public function it_returns_correct_response_structure()
    {
        $response = $this->get('/clear-cache');

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
        $response = $this->get('/clear-cache');

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
        $response = $this->get('/clear-cache');

        $response->assertStatus(200);
        $this->assertEquals('test_prefix_', $response->json('detail.prefix'));
    }

    /** @test */
    public function it_includes_duration_in_response()
    {
        $response = $this->get('/clear-cache');

        $response->assertStatus(200);
        $this->assertIsNumeric($response->json('detail.duration_ms'));
        $this->assertGreaterThanOrEqual(0, $response->json('detail.duration_ms'));
    }

    /** @test */
    public function it_includes_correct_message_when_cache_not_available()
    {
        $response = $this->get('/clear-cache');

        $response->assertStatus(200);
        $this->assertEquals(
            'Cache clearing skipped. Lada and response caching not found',
            $response->json('message')
        );
    }

    /** @test */
    public function it_restricts_access_in_production_without_token()
    {
        // Change environment to production
        putenv('APP_ENV=production');
        $_ENV['APP_ENV'] = 'production';
        $_SERVER['APP_ENV'] = 'production';

        $response = $this->get('/clear-cache');

        $response->assertStatus(401);
        $response->assertJson([
            'status' => 'restricted',
            'message' => 'Not available',
        ]);

        // Reset to local
        putenv('APP_ENV=local');
        $_ENV['APP_ENV'] = 'local';
        $_SERVER['APP_ENV'] = 'local';
    }

    /** @test */
    public function it_allows_access_in_production_with_valid_token()
    {
        // Change environment to production
        putenv('APP_ENV=production');
        $_ENV['APP_ENV'] = 'production';
        $_SERVER['APP_ENV'] = 'production';

        // Set a token
        putenv('CLEAR_CACHE_TOKEN=secret_token_123');
        $_ENV['CLEAR_CACHE_TOKEN'] = 'secret_token_123';

        $response = $this->get('/clear-cache?token=secret_token_123');

        $response->assertStatus(200);
        $response->assertJsonStructure(['message', 'detail']);

        // Clean up
        putenv('CLEAR_CACHE_TOKEN');
        putenv('APP_ENV=local');
        $_ENV['APP_ENV'] = 'local';
        $_SERVER['APP_ENV'] = 'local';
        unset($_ENV['CLEAR_CACHE_TOKEN']);
    }

    /** @test */
    public function it_allows_access_in_development_environment()
    {
        putenv('APP_ENV=development');
        $_ENV['APP_ENV'] = 'development';
        $_SERVER['APP_ENV'] = 'development';

        $response = $this->get('/clear-cache');

        $response->assertStatus(200);

        // Reset
        putenv('APP_ENV=local');
        $_ENV['APP_ENV'] = 'local';
        $_SERVER['APP_ENV'] = 'local';
    }

    /** @test */
    public function it_denies_access_with_invalid_token()
    {
        putenv('APP_ENV=production');
        $_ENV['APP_ENV'] = 'production';
        $_SERVER['APP_ENV'] = 'production';

        putenv('CLEAR_CACHE_TOKEN=correct_token');
        $_ENV['CLEAR_CACHE_TOKEN'] = 'correct_token';

        $response = $this->get('/clear-cache?token=wrong_token');

        $response->assertStatus(401);

        // Clean up
        putenv('APP_ENV=local');
        $_ENV['APP_ENV'] = 'local';
        $_SERVER['APP_ENV'] = 'local';
        putenv('CLEAR_CACHE_TOKEN');
        unset($_ENV['CLEAR_CACHE_TOKEN']);
    }

    /** @test */
    public function it_returns_response_cache_status()
    {
        $response = $this->get('/clear-cache');

        $response->assertStatus(200);

        $responseCache = $response->json('detail.summary.response_cache');
        $this->assertEquals('not available', $responseCache['cleared']);
        $this->assertStringContainsString('Laravel cache tags', $responseCache['note']);
    }
}
