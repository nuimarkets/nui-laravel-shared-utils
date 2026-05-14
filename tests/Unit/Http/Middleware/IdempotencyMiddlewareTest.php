<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Mockery;
use NuiMarkets\LaravelSharedUtils\Auth\JWTUser;
use NuiMarkets\LaravelSharedUtils\Http\Middleware\IdempotencyMiddleware;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;
use NuiMarkets\LaravelSharedUtils\Tests\Utils\FakeRedisConnection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IdempotencyMiddlewareTest extends TestCase
{
    private FakeRedisConnection $redis;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 5, 12, 12, 0, 0));
        config()->set('idempotency', [
            'enabled' => true,
            'redis_connection' => 'default',
            'key_prefix' => 'idem:v1',
            'ttl_header' => 600,
            'ttl_body_hash' => 30,
            'lock_ttl' => 60,
            'header_name' => 'Idempotency-Key',
            'header_max_length' => 255,
            'retry_after_seconds' => 5,
            'max_response_bytes' => 262144,
            'replayable_status_codes' => [200, 201, 202, 204, 422],
            'no_body_status_codes' => [204],
            'replayable_content_types' => [
                'application/json',
                'application/vnd.api+json',
                'text/plain',
            ],
            'replay_headers_allowlist' => [
                'content-type',
                'cache-control',
                'etag',
                'location',
            ],
            'body_hash_skip_content_types' => [
                'multipart/form-data',
                'application/octet-stream',
            ],
            'metrics_namespace' => 'Test/Idempotency',
            'metric_names' => [
                'cache_hit' => 'IdempotencyCacheHits',
                'conflict' => 'IdempotencyConflicts',
                'fail_open' => 'IdempotencyFailOpenEvents',
            ],
        ]);

        $this->bindRedis();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();

        parent::tearDown();
    }

    public function test_disabled_passes_through_without_redis_call(): void
    {
        config()->set('idempotency.enabled', false);
        $this->bindFailingRedis('Redis should not be called');

        $called = false;
        $response = $this->handle($this->request(), function () use (&$called) {
            $called = true;

            return response('ok', 200, ['Content-Type' => 'text/plain']);
        });

        $this->assertTrue($called);
        $this->assertSame('ok', $response->getContent());
    }

    public function test_unauthenticated_passes_through(): void
    {
        $request = Request::create('/orders', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"x":1}');
        $called = false;

        $this->handle($request, function () use (&$called) {
            $called = true;

            return response()->json(['ok' => true]);
        });

        $this->assertTrue($called);
        $this->assertSame([], $this->redis->keys());
    }

    public function test_read_methods_pass_through(): void
    {
        foreach (['GET', 'HEAD', 'OPTIONS'] as $method) {
            $called = false;

            $this->handle($this->request(method: $method), function () use (&$called) {
                $called = true;

                return response('', 204);
            });

            $this->assertTrue($called, $method.' should pass through');
        }

        $this->assertSame([], $this->redis->keys());
    }

    public function test_malformed_headers_return_400_without_body_hash_fallback(): void
    {
        $cases = [
            '',
            str_repeat('a', 256),
            'has space',
            "has\ncontrol",
        ];

        foreach ($cases as $header) {
            $request = $this->request(headers: ['Idempotency-Key' => $header]);
            $called = false;
            $response = $this->handle($request, function () use (&$called) {
                $called = true;

                return response()->json(['ok' => true]);
            });

            $this->assertFalse($called);
            $this->assertSame(400, $response->getStatusCode());
            $this->assertSame('idempotency_key_invalid', json_decode($response->getContent(), true)['error']);
        }

        $this->assertSame([], $this->redis->keys());
    }

    public function test_header_request_writes_complete_payload_after_termination(): void
    {
        $response = $this->handle($this->request(headers: ['Idempotency-Key' => 'abc-123']), function () {
            return response()->json(['created' => true], 201, ['Location' => '/orders/1']);
        });

        $this->assertSame(201, $response->getStatusCode());
        $key = $this->redis->keys()[0];
        $this->assertSame('inflight', $this->redis->payload($key)['state']);

        $this->app->terminate();

        $payload = $this->redis->payload($key);
        $this->assertSame('complete', $payload['state']);
        $this->assertSame(201, $payload['status']);
        $this->assertSame('{"created":true}', base64_decode($payload['body_b64']));
        $this->assertSame('/orders/1', $payload['headers']['location']);
        $this->assertSame(600, $this->redis->ttlFor($key));
    }

    public function test_422_response_is_cached_and_replayed(): void
    {
        $this->handle($this->request(headers: ['Idempotency-Key' => 'validation-key']), function () {
            return response()->json(['errors' => ['bad']], 422);
        });
        $this->app->terminate();

        $called = false;
        $response = $this->handle($this->request(headers: ['Idempotency-Key' => 'validation-key']), function () use (&$called) {
            $called = true;

            return response()->json(['errors' => ['rerun']], 422);
        });

        $this->assertFalse($called);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('1', $response->headers->get('X-Idempotency-Replay'));
        $this->assertSame(['errors' => ['bad']], json_decode($response->getContent(), true));
    }

    public function test_no_content_response_without_content_type_is_cached_and_replayed(): void
    {
        $this->handle($this->request(headers: ['Idempotency-Key' => 'no-content-key']), function () {
            return new \Illuminate\Http\Response('', 204);
        });
        $this->app->terminate();

        $called = false;
        $response = $this->handle($this->request(headers: ['Idempotency-Key' => 'no-content-key']), function () use (&$called) {
            $called = true;

            return response('rerun', 200, ['Content-Type' => 'text/plain']);
        });

        $this->assertFalse($called);
        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('', $response->getContent());
        $this->assertSame('1', $response->headers->get('X-Idempotency-Replay'));
    }

    public function test_non_replayable_status_codes_delete_inflight_key(): void
    {
        foreach ([401, 403, 404, 500] as $status) {
            Log::spy();
            $this->bindRedis();

            $this->handle($this->request(headers: ['Idempotency-Key' => 'status-'.$status]), function () use ($status) {
                return response()->json(['status' => $status], $status);
            });
            $this->app->terminate();

            $this->assertSame([], $this->redis->keys());
            Log::shouldHaveReceived('info')->with('idempotency.skip_cache', Mockery::on(
                fn (array $context): bool => ($context['skip_reason'] ?? null) === 'status_code'
            ));
        }
    }

    public function test_complete_payload_replays_allowlisted_headers_only(): void
    {
        $this->handle($this->request(headers: ['Idempotency-Key' => 'replay-key']), function () {
            return response('created', 201, [
                'Content-Type' => 'text/plain',
                'Location' => '/orders/1',
                'Set-Cookie' => 'secret=1',
                'X-Request-ID' => 'request-1',
            ]);
        });
        $this->app->terminate();

        $response = $this->handle($this->request(headers: ['Idempotency-Key' => 'replay-key']), function () {
            return response('rerun', 201, ['Content-Type' => 'text/plain']);
        });

        $this->assertSame('created', $response->getContent());
        $this->assertSame('/orders/1', $response->headers->get('Location'));
        $this->assertSame('1', $response->headers->get('X-Idempotency-Replay'));
        $this->assertSame('201', $response->headers->get('X-Idempotency-Original-Status'));
        $this->assertFalse($response->headers->has('Set-Cookie'));
        $this->assertFalse($response->headers->has('X-Request-ID'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_replay_logs_cache_hit_metric_context(): void
    {
        $this->handle($this->request(headers: ['Idempotency-Key' => 'metric-replay-key']), function () {
            return response()->json(['created' => true]);
        });
        $this->app->terminate();

        Log::spy();

        $response = $this->handle($this->request(headers: ['Idempotency-Key' => 'metric-replay-key']), function () {
            return response()->json(['rerun' => true]);
        });

        $this->assertSame('1', $response->headers->get('X-Idempotency-Replay'));
        Log::shouldHaveReceived('info')->with('idempotency.replay', Mockery::on(
            fn (array $context): bool => ($context['idempotency_metric_namespace'] ?? null) === 'Test/Idempotency'
                && ($context['idempotency_metric_name'] ?? null) === 'IdempotencyCacheHits'
                && ($context['idempotency_metric_value'] ?? null) === 1
                && ($context['idempotency_metric_unit'] ?? null) === 'Count'
        ));
    }

    public function test_same_header_with_different_body_returns_conflict_in_plain_and_jsonapi_shapes(): void
    {
        $this->handle($this->request(body: '{"a":1}', headers: ['Idempotency-Key' => 'conflict-key']), function () {
            return response()->json(['ok' => true]);
        });
        $this->app->terminate();

        Log::spy();

        $plain = $this->handle($this->request(body: '{"a":2}', headers: ['Idempotency-Key' => 'conflict-key']), fn () => response()->json(['rerun' => true]));
        $jsonApi = $this->handle($this->request(body: '{"a":2}', headers: [
            'Idempotency-Key' => 'conflict-key',
            'Accept' => 'application/vnd.api+json',
        ]), fn () => response()->json(['rerun' => true]));

        $this->assertSame(422, $plain->getStatusCode());
        $this->assertSame('idempotency_key_conflict', json_decode($plain->getContent(), true)['error']);
        $this->assertSame('idempotency_key_conflict', json_decode($jsonApi->getContent(), true)['errors'][0]['code']);
        Log::shouldHaveReceived('info')->with('idempotency.conflict', Mockery::on(
            fn (array $context): bool => ($context['idempotency_metric_namespace'] ?? null) === 'Test/Idempotency'
                && ($context['idempotency_metric_name'] ?? null) === 'IdempotencyConflicts'
                && ($context['idempotency_metric_value'] ?? null) === 1
                && ($context['idempotency_metric_unit'] ?? null) === 'Count'
        ));
    }

    public function test_inflight_lock_returns_409_with_computed_retry_after(): void
    {
        $this->handle($this->request(headers: ['Idempotency-Key' => 'inflight-key']), function () {
            return response()->json(['slow' => true]);
        });

        Carbon::setTestNow(now()->addSeconds(15));
        $response = $this->handle($this->request(headers: ['Idempotency-Key' => 'inflight-key']), fn () => response()->json(['rerun' => true]));

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('45', $response->headers->get('Retry-After'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_body_hash_path_uses_30_second_ttl(): void
    {
        $this->handle($this->request(headers: []), fn () => response()->json(['ok' => true]));
        $this->app->terminate();

        $key = $this->redis->keys()[0];
        $this->assertStringContainsString(':body_hash:', $key);
        $this->assertSame(30, $this->redis->ttlFor($key));
    }

    public function test_binary_response_body_replays_byte_identical(): void
    {
        $bytes = "abc\xff\x00xyz";

        $this->handle($this->request(headers: ['Idempotency-Key' => 'binary-key']), fn () => response($bytes, 200, ['Content-Type' => 'text/plain']));
        $this->app->terminate();

        $response = $this->handle($this->request(headers: ['Idempotency-Key' => 'binary-key']), fn () => response('rerun', 200, ['Content-Type' => 'text/plain']));

        $this->assertSame($bytes, $response->getContent());
    }

    public function test_zero_string_request_body_does_not_hash_as_empty_body(): void
    {
        $this->handle($this->request(body: '0', headers: ['Idempotency-Key' => 'zero-body-key']), fn () => response()->json(['ok' => true]));
        $this->app->terminate();

        $response = $this->handle($this->request(body: '', headers: ['Idempotency-Key' => 'zero-body-key']), fn () => response()->json(['rerun' => true]));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('idempotency_key_conflict', json_decode($response->getContent(), true)['error']);
    }

    public function test_zero_string_response_body_replays_byte_identical(): void
    {
        $this->handle($this->request(headers: ['Idempotency-Key' => 'zero-response-key']), fn () => response('0', 200, ['Content-Type' => 'text/plain']));
        $this->app->terminate();

        $response = $this->handle($this->request(headers: ['Idempotency-Key' => 'zero-response-key']), fn () => response('rerun', 200, ['Content-Type' => 'text/plain']));

        $this->assertSame('0', $response->getContent());
    }

    public function test_cache_keys_include_unit_separators_to_prevent_actor_collisions(): void
    {
        $this->handle($this->request(user: new JWTUser('user12', 'org3', 'buyer'), headers: ['Idempotency-Key' => 'key']), fn () => response()->json(['ok' => true]));
        $this->handle($this->request(user: new JWTUser('user1', 'org23', 'buyer'), headers: ['Idempotency-Key' => 'key']), fn () => response()->json(['ok' => true]));

        $this->assertCount(2, $this->redis->keys());
    }

    public function test_same_header_and_body_from_different_users_do_not_replay(): void
    {
        $this->handle($this->request(user: new JWTUser('user-a', 'org', 'buyer'), headers: ['Idempotency-Key' => 'shared']), fn () => response()->json(['user' => 'a']));
        $this->app->terminate();

        $called = false;
        $this->handle($this->request(user: new JWTUser('user-b', 'org', 'buyer'), headers: ['Idempotency-Key' => 'shared']), function () use (&$called) {
            $called = true;

            return response()->json(['user' => 'b']);
        });

        $this->assertTrue($called);
        $this->assertCount(2, $this->redis->keys());
    }

    public function test_same_body_on_different_route_parameters_does_not_body_hash_replay(): void
    {
        $this->handle($this->request(path: '/orders/1', headers: []), fn () => response()->json(['order' => 1]));
        $this->app->terminate();

        $called = false;
        $this->handle($this->request(path: '/orders/2', headers: []), function () use (&$called) {
            $called = true;

            return response()->json(['order' => 2]);
        });

        $this->assertTrue($called);
        $this->assertCount(2, $this->redis->keys());
    }

    public function test_same_named_route_and_body_with_different_query_does_not_body_hash_replay(): void
    {
        $first = $this->request(path: '/orders/1?include=lines', headers: []);
        $first->setRouteResolver(fn () => new class
        {
            public function getName(): string
            {
                return 'orders.show';
            }

            public function parameters(): array
            {
                return ['order' => '1'];
            }
        });

        $this->handle($first, fn () => response()->json(['include' => 'lines']));
        $this->app->terminate();

        $second = $this->request(path: '/orders/1?include=charges', headers: []);
        $second->setRouteResolver(fn () => new class
        {
            public function getName(): string
            {
                return 'orders.show';
            }

            public function parameters(): array
            {
                return ['order' => '1'];
            }
        });

        $called = false;
        $this->handle($second, function () use (&$called) {
            $called = true;

            return response()->json(['include' => 'charges']);
        });

        $this->assertTrue($called);
        $this->assertCount(2, $this->redis->keys());
    }

    public function test_lock_expiry_race_skips_complete_write(): void
    {
        Log::spy();
        config()->set('idempotency.lock_ttl', 1);

        $this->handle($this->request(headers: ['Idempotency-Key' => 'slow-key']), fn () => response()->json(['ok' => true]));
        Carbon::setTestNow(now()->addSeconds(2));
        $this->app->terminate();

        $this->assertSame([], $this->redis->keys());
        Log::shouldHaveReceived('warning')->with('idempotency.lock_expired_before_complete', Mockery::type('array'));
    }

    public function test_malformed_existing_payload_fails_open(): void
    {
        $request = $this->request(headers: ['Idempotency-Key' => 'manual']);
        $this->handle($request, fn () => response()->json(['seed' => true]));

        $key = $this->redis->keys()[0];
        $this->redis->set($key, 'not-json', 'EX', 60);

        $called = false;
        $response = $this->handle($request, function () use (&$called) {
            $called = true;

            return response()->json(['fresh' => true]);
        });

        $this->assertTrue($called);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_multipart_without_header_passes_through_but_with_header_caches(): void
    {
        $this->handle($this->request(headers: ['Content-Type' => 'multipart/form-data; boundary=x']), fn () => response()->json(['pass' => true]));
        $this->assertSame([], $this->redis->keys());

        $this->handle($this->request(headers: [
            'Content-Type' => 'multipart/form-data; boundary=x',
            'Idempotency-Key' => 'multipart-key',
        ]), fn () => response()->json(['cache' => true]));
        $this->app->terminate();

        $this->assertCount(1, $this->redis->keys());
    }

    public function test_oversize_content_type_and_streamed_responses_are_not_cached(): void
    {
        $cases = [
            'oversize' => function () {
                config()->set('idempotency.max_response_bytes', 3);

                return response('large', 200, ['Content-Type' => 'text/plain']);
            },
            'content_type' => fn () => response('<html></html>', 200, ['Content-Type' => 'text/html']),
            'streamed' => fn () => new StreamedResponse(fn () => print 'stream', 200, ['Content-Type' => 'text/plain']),
        ];

        foreach ($cases as $reason => $factory) {
            Log::spy();
            config()->set('idempotency.max_response_bytes', 262144);
            $this->bindRedis();

            $this->handle($this->request(headers: ['Idempotency-Key' => 'skip-'.$reason]), $factory);
            $this->app->terminate();

            $this->assertSame([], $this->redis->keys());
            Log::shouldHaveReceived('info')->with('idempotency.skip_cache', Mockery::on(
                fn (array $context): bool => ($context['skip_reason'] ?? null) === $reason
            ));
        }
    }

    public function test_redis_exception_fails_open(): void
    {
        Log::spy();
        $this->bindFailingRedis('redis down');

        $called = false;
        $response = $this->handle($this->request(headers: ['Idempotency-Key' => 'redis-down']), function () use (&$called) {
            $called = true;

            return response()->json(['fresh' => true]);
        });

        $this->assertTrue($called);
        $this->assertSame(200, $response->getStatusCode());
        Log::shouldHaveReceived('warning')->with('idempotency.fail_open', Mockery::on(
            fn (array $context): bool => ($context['idempotency_metric_namespace'] ?? null) === 'Test/Idempotency'
                && ($context['idempotency_metric_name'] ?? null) === 'IdempotencyFailOpenEvents'
                && ($context['idempotency_metric_value'] ?? null) === 1
                && ($context['idempotency_metric_unit'] ?? null) === 'Count'
        ));
    }

    private function bindRedis(?FakeRedisConnection $redis = null): FakeRedisConnection
    {
        $this->redis = $redis ?? new FakeRedisConnection;

        Redis::swap(new class($this->redis)
        {
            public function __construct(private FakeRedisConnection $connection) {}

            public function connection(string $name = 'default'): FakeRedisConnection
            {
                return $this->connection;
            }
        });

        return $this->redis;
    }

    private function bindFailingRedis(string $message): void
    {
        Redis::swap(new class($message)
        {
            public function __construct(private string $message) {}

            public function connection(?string $name = null): void
            {
                throw new \RuntimeException($this->message);
            }
        });
    }

    private function handle(Request $request, callable $next): \Symfony\Component\HttpFoundation\Response
    {
        return (new IdempotencyMiddleware)->handle($request, $next);
    }

    private function request(
        string $method = 'POST',
        string $path = '/orders/1',
        string $body = '{"product":"beef"}',
        array $headers = ['Idempotency-Key' => 'test-key'],
        ?JWTUser $user = null,
    ): Request {
        $server = ['CONTENT_TYPE' => 'application/json'];

        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'content-type') {
                $server['CONTENT_TYPE'] = $value;
            } elseif (strtolower($name) === 'accept') {
                $server['HTTP_ACCEPT'] = $value;
            } else {
                $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
            }
        }

        $request = Request::create($path, $method, [], [], [], $server, $body);
        $request->setUserResolver(fn () => $user ?? new JWTUser('user-1', 'org-1', 'buyer'));

        return $request;
    }
}
