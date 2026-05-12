<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Feature;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use NuiMarkets\LaravelSharedUtils\Auth\JWTUser;
use NuiMarkets\LaravelSharedUtils\Http\Middleware\IdempotencyMiddleware;
use NuiMarkets\LaravelSharedUtils\Providers\IdempotencyServiceProvider;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;
use NuiMarkets\LaravelSharedUtils\Tests\Utils\FakeRedisConnection;

class IdempotencyMiddlewareTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [
            IdempotencyServiceProvider::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('idempotency.enabled', true);
        Redis::swap(new class(new FakeRedisConnection)
        {
            public function __construct(private FakeRedisConnection $connection) {}

            public function connection(string $name = 'default'): FakeRedisConnection
            {
                return $this->connection;
            }
        });

        $this->app->instance('idempotency.calls', 0);

        Route::post('/idempotent', function () {
            $this->app->instance('idempotency.calls', $this->app->make('idempotency.calls') + 1);

            return response()->json(['calls' => $this->app->make('idempotency.calls')]);
        })->middleware([AuthenticateIdempotencyTestUser::class, IdempotencyMiddleware::class]);

        Route::post('/idempotent-validation', function () {
            $this->app->instance('idempotency.calls', $this->app->make('idempotency.calls') + 1);

            return response()->json(['error' => 'invalid', 'calls' => $this->app->make('idempotency.calls')], 422);
        })->middleware([AuthenticateIdempotencyTestUser::class, IdempotencyMiddleware::class]);
    }

    public function test_stub_route_replays_without_invoking_controller_again(): void
    {
        $first = $this->postJson('/idempotent', ['product' => 'beef'], ['Idempotency-Key' => 'feature-key']);
        $this->app->terminate();

        $second = $this->postJson('/idempotent', ['product' => 'beef'], ['Idempotency-Key' => 'feature-key']);

        $first->assertOk()->assertJson(['calls' => 1]);
        $second->assertOk()->assertHeader('X-Idempotency-Replay', '1')->assertJson(['calls' => 1]);
        $this->assertSame(1, $this->app->make('idempotency.calls'));
    }

    public function test_422_route_replays_without_invoking_controller_again(): void
    {
        $first = $this->postJson('/idempotent-validation', ['product' => 'beef'], ['Idempotency-Key' => 'feature-422']);
        $this->app->terminate();

        $second = $this->postJson('/idempotent-validation', ['product' => 'beef'], ['Idempotency-Key' => 'feature-422']);

        $first->assertStatus(422)->assertJson(['calls' => 1]);
        $second->assertStatus(422)->assertHeader('X-Idempotency-Replay', '1')->assertJson(['calls' => 1]);
        $this->assertSame(1, $this->app->make('idempotency.calls'));
    }
}

class AuthenticateIdempotencyTestUser
{
    public function handle(Request $request, Closure $next)
    {
        $request->setUserResolver(fn () => new JWTUser('feature-user', 'feature-org', 'buyer'));

        return $next($request);
    }
}
