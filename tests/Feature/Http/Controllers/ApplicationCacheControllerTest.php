<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Feature\Http\Controllers;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use NuiMarkets\LaravelSharedUtils\Auth\CacheAbility;
use NuiMarkets\LaravelSharedUtils\Auth\JWTUser;
use NuiMarkets\LaravelSharedUtils\Http\Controllers\ApplicationCacheController;
use NuiMarkets\LaravelSharedUtils\Http\Controllers\ClearLadaAndResponseCacheController;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for ApplicationCacheController + the ?include=app delegation
 * from ClearLadaAndResponseCacheController.
 *
 * Auth contract via AuthorizesCacheOperations: a request passes with
 * ?token=$VALID_TOKEN, or as a signed-in user the CacheAbility::MANAGE gate
 * allows. Tests without a signed-in user exercise the token path alone.
 *
 * Uses the array cache driver so tests don't depend on Redis being up.
 * The driver class name is verified in the response payload as a regression
 * guard against accidentally flushing a misconfigured store.
 */
class ApplicationCacheControllerTest extends TestCase
{
    private const VALID_TOKEN = 'secret_token_xyz';

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['router']->get('/forget', [ApplicationCacheController::class, 'forget']);
        $app['router']->get('/clear-cache', [ClearLadaAndResponseCacheController::class, 'clearCache']);

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

        // Configure the token once; token-path tests pass ?token=.
        config()->set('app.clear_cache_token', self::VALID_TOKEN);

        // Spy logs so the controller's Log::info() calls don't try to write
        // to disk. Keeps tests portable to sandboxes / CI runners with a
        // read-only filesystem under vendor/.
        //
        // Stub channel() to return the spy: PHP deprecations raised mid-request
        // (common on the prefer-lowest dependency floor) are routed by Laravel
        // through $logger->channel('deprecations')->warning(), which would
        // otherwise call warning() on the spy's null channel() and 500.
        Log::spy()->shouldReceive('channel')->andReturnSelf();

        Cache::store()->flush();
    }

    protected function tearDown(): void
    {
        Cache::store()->flush();

        parent::tearDown();
    }

    #[Test]
    public function forget_removes_a_known_key()
    {
        Cache::put('lookup:countries:all', ['NZ', 'AU'], 600);
        $this->assertTrue(Cache::has('lookup:countries:all'));

        $response = $this->get('/forget?key=lookup:countries:all&token='.self::VALID_TOKEN);

        $response->assertStatus(200);
        $response->assertJsonPath('detail.key', 'lookup:countries:all');
        $response->assertJsonPath('detail.existed_before', true);
        $response->assertJsonPath('detail.forgotten', true);
        $response->assertJsonPath('detail.cache_store', 'array');
        $this->assertFalse(Cache::has('lookup:countries:all'));
    }

    #[Test]
    public function forget_reports_existed_before_false_when_key_missing()
    {
        $response = $this->get('/forget?key=cache:absent:all&token='.self::VALID_TOKEN);

        $response->assertStatus(200);
        $response->assertJsonPath('detail.existed_before', false);
        $response->assertJsonPath('detail.forgotten', false);
        // Message disambiguates the silent-no-op from a real forget.
        $response->assertJsonPath('message', 'Application cache key was already absent');
    }

    #[Test]
    public function forget_returns_422_when_key_missing()
    {
        $response = $this->get('/forget?token='.self::VALID_TOKEN);

        $response->assertStatus(422);
        $response->assertJson([
            'status' => 'invalid',
            'message' => 'Missing required query parameter: key',
        ]);
    }

    #[Test]
    public function forget_returns_422_when_key_blank()
    {
        $response = $this->get('/forget?key=&token='.self::VALID_TOKEN);

        $response->assertStatus(422);
    }

    #[Test]
    public function forget_returns_401_without_token()
    {
        $response = $this->get('/forget?key=anything');

        $response->assertStatus(401);
        $response->assertJson([
            'status' => 'restricted',
            'message' => 'Not available',
        ]);
    }

    #[Test]
    public function forget_returns_401_with_wrong_token()
    {
        $response = $this->get('/forget?key=anything&token=not-the-real-token');

        $response->assertStatus(401);
    }

    #[Test]
    public function forget_returns_401_when_clear_cache_token_unconfigured()
    {
        // Wipe the configured token: even a request carrying any token must fail.
        config()->set('app.clear_cache_token', null);

        $response = $this->get('/forget?key=anything&token=anything');

        $response->assertStatus(401);
    }

    #[Test]
    public function forget_allows_a_user_the_gate_allows_without_a_token()
    {
        Cache::put('lookup:countries:all', ['NZ'], 600);
        $this->allowCacheGate(true);
        $this->signIn(new GenericUser(['id' => 42]));

        $response = $this->get('/forget?key=lookup:countries:all');

        $response->assertStatus(200);
        $response->assertJsonPath('detail.forgotten', true);
        $this->assertFalse(Cache::has('lookup:countries:all'));
    }

    #[Test]
    public function forget_returns_401_for_a_user_the_gate_denies_without_a_token()
    {
        Cache::put('lookup:countries:all', ['NZ'], 600);
        $this->allowCacheGate(false);
        $this->signIn(new GenericUser(['id' => 42]));

        $response = $this->get('/forget?key=lookup:countries:all');

        $response->assertStatus(401);
        $response->assertJson([
            'status' => 'restricted',
            'message' => 'Not available',
        ]);
        $this->assertTrue(Cache::has('lookup:countries:all'));
    }

    #[Test]
    public function forget_allows_a_user_the_gate_denies_with_a_valid_token()
    {
        $this->allowCacheGate(false);
        $this->signIn(new GenericUser(['id' => 42]));

        $response = $this->get('/forget?key=anything&token='.self::VALID_TOKEN);

        $response->assertStatus(200);
    }

    #[Test]
    public function forget_leaves_a_user_to_the_token_when_no_gate_is_defined()
    {
        $this->signIn(new GenericUser(['id' => 42]));

        $this->get('/forget?key=anything')->assertStatus(401);
        $this->get('/forget?key=anything&token='.self::VALID_TOKEN)->assertStatus(200);
    }

    #[Test]
    public function forget_returns_401_without_asking_the_gate_when_the_guard_resolves_a_non_object()
    {
        Gate::define(CacheAbility::MANAGE, fn ($user) => $user->isAllowed());
        $this->signIn(false);

        $this->get('/forget?key=anything')->assertStatus(401);
        $this->get('/forget?key=anything&token='.self::VALID_TOKEN)->assertStatus(200);
    }

    #[Test]
    public function forget_allows_a_user_the_gate_allows_when_the_token_is_unconfigured()
    {
        config()->set('app.clear_cache_token', null);
        $this->allowCacheGate(true);
        $this->signIn(new GenericUser(['id' => 42]));

        $response = $this->get('/forget?key=anything');

        $response->assertStatus(200);
    }

    #[Test]
    public function forget_logs_the_auth_identifier_of_an_authenticatable_user()
    {
        $this->allowCacheGate(true);
        $this->signIn(new GenericUser(['id' => 42]));

        $this->get('/forget?key=anything')->assertStatus(200);

        $this->assertForgetLogged(['authorized_by' => 'user', 'user_id' => 42]);
    }

    #[Test]
    public function forget_logs_the_public_id_of_a_token_decoded_user()
    {
        $this->allowCacheGate(true);
        $this->signIn(new JWTUser(id: 'user-7', org_id: 'org-1', role: 'admin'));

        $this->get('/forget?key=anything')->assertStatus(200);

        $this->assertForgetLogged(['authorized_by' => 'user', 'user_id' => 'user-7']);
    }

    #[Test]
    public function forget_logs_a_user_with_no_identifier_without_one()
    {
        $this->allowCacheGate(true);
        $this->signIn(new \stdClass);

        $this->get('/forget?key=anything')->assertStatus(200);

        $this->assertForgetLogged(['authorized_by' => 'user']);
    }

    #[Test]
    public function forget_logs_a_token_call_without_the_token()
    {
        $this->get('/forget?key=anything&token='.self::VALID_TOKEN)->assertStatus(200);

        $this->assertForgetLogged(['authorized_by' => 'token']);
    }

    #[Test]
    public function clear_cache_with_include_app_logs_the_user_on_the_flush()
    {
        $this->skipIfNoRedis();

        $this->allowCacheGate(true);
        $this->signIn(new GenericUser(['id' => 42]));

        $response = $this->get('/clear-cache?include=app');

        $response->assertStatus(200);
        $response->assertJsonPath('detail.app_cache.flushed', true);
        $this->assertArrayNotHasKey('authorized_by', $response->json('detail.app_cache'));
        Log::shouldHaveReceived('info')
            ->withArgs(fn ($message, $context = []) => $message === 'Application cache flushed'
                && ($context['authorized_by'] ?? null) === 'user'
                && ($context['user_id'] ?? null) === 42)
            ->once();
    }

    #[Test]
    public function clear_cache_with_include_app_flushes_application_cache()
    {
        $this->skipIfNoRedis();

        Cache::put('lookup:countries:all', ['NZ'], 600);
        Cache::put('lookup:currencies:all', ['NZD'], 600);
        $this->assertTrue(Cache::has('lookup:countries:all'));

        $response = $this->get('/clear-cache?include=app&token='.self::VALID_TOKEN);

        $response->assertStatus(200);
        $response->assertJsonPath('detail.app_cache.flushed', true);
        $response->assertJsonPath('detail.app_cache.cache_store', 'array');
        $this->assertFalse(Cache::has('lookup:countries:all'));
        $this->assertFalse(Cache::has('lookup:currencies:all'));
    }

    #[Test]
    public function clear_cache_without_include_app_does_not_flush_application_cache()
    {
        $this->skipIfNoRedis();

        Cache::put('lookup:countries:all', ['NZ'], 600);

        $response = $this->get('/clear-cache?token='.self::VALID_TOKEN);

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

    #[Test]
    public function clear_cache_with_include_app_returns_401_without_token()
    {
        // No skipIfNoRedis() here: the auth gate returns 401 before
        // clearCache() ever touches Redis::connection('default'), so this
        // test must run even when Redis is unavailable.
        Cache::put('lookup:countries:all', ['NZ'], 600);

        $response = $this->get('/clear-cache?include=app');

        $response->assertStatus(401);
        $this->assertTrue(
            Cache::has('lookup:countries:all'),
            'Application cache must not be touched on a 401 response.'
        );
    }

    private function allowCacheGate(bool $allow): void
    {
        Gate::define(CacheAbility::MANAGE, fn ($user) => $allow);
    }

    /**
     * Resolve request()->user() to $user through a request guard, the way a
     * consumer's bearer-token guard would. A guard may resolve a user that is
     * not Authenticatable (a token-decoded user object), or a non-object such
     * as false, which actingAs() would refuse.
     */
    private function signIn(mixed $user): void
    {
        Auth::viaRequest('cache-test', fn () => $user);
        config()->set('auth.guards.cache-test', ['driver' => 'cache-test']);
        Auth::shouldUse('cache-test');
    }

    /**
     * Exactly one forget success log, whose audit fields are exactly
     * $expected (so a missing or extra user_id fails), with no token value.
     *
     * @param  array<string, mixed>  $expected
     */
    private function assertForgetLogged(array $expected): void
    {
        Log::shouldHaveReceived('info')
            ->withArgs(function ($message, $context = []) use ($expected) {
                $audit = array_intersect_key($context, array_flip(['authorized_by', 'user_id']));

                return str_starts_with($message, 'Application cache key')
                    && $audit === $expected
                    && ! str_contains(json_encode($context), self::VALID_TOKEN);
            })
            ->once();
    }
}
