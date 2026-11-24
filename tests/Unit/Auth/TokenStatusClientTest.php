<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\Auth;

use Closure;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Cache\Events\RetrievingManyKeys;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\MockInterface;
use NuiMarkets\LaravelSharedUtils\Auth\BoundedBodyStream;
use NuiMarkets\LaravelSharedUtils\Auth\TokenStatus;
use NuiMarkets\LaravelSharedUtils\Auth\TokenStatusClient;
use NuiMarkets\LaravelSharedUtils\Services\MachineTokenService;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;
use NuiMarkets\LaravelSharedUtils\Tests\Utils\ThrowingCacheStore;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class TokenStatusClientTest extends TestCase
{
    private const STATUS_URL = 'https://issuer.test/api/tokens/status';

    private const TOKEN_URL = 'https://issuer.test/oauth/token';

    private const JTI = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718';

    private const T0 = 1_800_000_000;

    /** A token valid for another day. */
    private int $exp;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::createFromTimestamp(self::T0));
        $this->exp = self::T0 + 86400;

        Config::set('cache.stores.token_status', ['driver' => 'array']);
        Config::set('token_revocation', require __DIR__.'/../../../config/token_revocation.php');
        Config::set('token_revocation.url', self::STATUS_URL);

        Config::set('machine_token.redis_key', 'machine_token');
        Config::set('machine_token.time_before_expire', 300);
        Config::set('machine_token.url', self::TOKEN_URL);
        Config::set('machine_token.client_id', 'client-id');
        Config::set('machine_token.secret', 'client-secret');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function machineTokens(string $token = 'machine-token'): MockInterface
    {
        $mock = Mockery::mock(MachineTokenService::class);
        $mock->shouldReceive('getToken')->andReturn($token)->byDefault();
        $mock->shouldReceive('invalidate')->byDefault();

        return $mock;
    }

    private function client(?MockInterface $machineTokens = null): TokenStatusClient
    {
        return new TokenStatusClient($machineTokens ?? $this->machineTokens());
    }

    private function realClient(): TokenStatusClient
    {
        return new TokenStatusClient(new MachineTokenService);
    }

    private function fakeStatus(bool $active): void
    {
        Http::fake([self::STATUS_URL => Http::response(['active' => $active])]);
    }

    private function advance(float $seconds): void
    {
        Carbon::setTestNow(Carbon::now()->addMilliseconds((int) round($seconds * 1000)));
    }

    private function statusCalls(): int
    {
        return Http::recorded(fn (Request $request) => $request->url() === self::STATUS_URL)->count();
    }

    private function entry(): mixed
    {
        return Cache::store('token_status')->get(TokenStatusClient::KEY_PREFIX.self::JTI);
    }

    // Results

    public function test_active_answer_is_active_and_cached()
    {
        $this->fakeStatus(true);

        $check = $this->client()->check(self::JTI, $this->exp);

        $this->assertSame(TokenStatus::Active, $check->status);
        $this->assertFalse($check->cacheHit);
        $this->assertNull($check->reason);
        $this->assertSame(['status' => 'active', 'active_until' => (float) self::T0 + 30], $this->entry());
    }

    public function test_inactive_answer_is_revoked_and_cached()
    {
        $this->fakeStatus(false);

        $this->assertSame(TokenStatus::Revoked, $this->client()->status(self::JTI, $this->exp));
        $this->assertSame(['status' => 'revoked'], $this->entry());
    }

    public function test_server_error_is_unavailable_and_caches_nothing()
    {
        Http::fake([self::STATUS_URL => Http::response(['message' => 'Server Error'], 500)]);

        $check = $this->client()->check(self::JTI, $this->exp);

        $this->assertSame(TokenStatus::Unavailable, $check->status);
        $this->assertSame('http_500', $check->reason);
        $this->assertNull($this->entry());
    }

    public function test_status_call_sends_the_jti_with_the_machine_token_inside_the_budget()
    {
        $options = null;
        Http::fake([self::STATUS_URL => function (Request $request, array $sent) use (&$options) {
            $options = $sent;

            return Http::response(['active' => true]);
        }]);

        $this->client($this->machineTokens('svc-token'))->status(self::JTI, $this->exp);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === self::STATUS_URL
            && $request->data() === ['jti' => self::JTI]
            && $request->hasHeader('Authorization', 'Bearer svc-token')
            && $request->hasHeader('Accept', 'application/json'));
        $this->assertEquals(1.0, $options['connect_timeout']);
        $this->assertEquals(2.0, $options['timeout']);
        $this->assertFalse($options['allow_redirects']);
        $this->assertInstanceOf(BoundedBodyStream::class, $options['sink']);
    }

    // Answers that are not answers

    public function test_connection_exception_is_unavailable()
    {
        Http::fake([self::STATUS_URL => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);

        $check = $this->client()->check(self::JTI, $this->exp);

        $this->assertSame(TokenStatus::Unavailable, $check->status);
        $this->assertSame('connection', $check->reason);
    }

    public function test_non_200_success_is_unavailable()
    {
        Http::fake([self::STATUS_URL => Http::response(['active' => false], 201)]);

        $this->assertSame(TokenStatus::Unavailable, $this->client()->status(self::JTI, $this->exp));
        $this->assertNull($this->entry());
    }

    public function test_not_found_is_unavailable_not_revoked()
    {
        Http::fake([self::STATUS_URL => Http::response(['message' => 'Not Found'], 404)]);

        $this->assertSame(TokenStatus::Unavailable, $this->client()->status(self::JTI, $this->exp));
    }

    public function test_200_without_active_is_unavailable()
    {
        Http::fake([self::STATUS_URL => Http::response(['revoked' => true])]);

        $check = $this->client()->check(self::JTI, $this->exp);

        $this->assertSame(TokenStatus::Unavailable, $check->status);
        $this->assertSame('malformed', $check->reason);
        $this->assertNull($this->entry());
    }

    public static function nonBooleanActive(): array
    {
        return [
            'string false' => ['false'],
            'zero' => [0],
            'null' => [null],
            'string no' => ['no'],
        ];
    }

    #[DataProvider('nonBooleanActive')]
    public function test_200_with_non_boolean_active_is_unavailable(mixed $value)
    {
        Http::fake([self::STATUS_URL => Http::response(['active' => $value])]);

        $check = $this->client()->check(self::JTI, $this->exp);

        $this->assertSame(TokenStatus::Unavailable, $check->status);
        $this->assertSame('malformed', $check->reason);
    }

    public function test_200_with_non_json_body_is_unavailable()
    {
        Http::fake([self::STATUS_URL => Http::response('<html>gateway</html>')]);

        $this->assertSame(TokenStatus::Unavailable, $this->client()->status(self::JTI, $this->exp));
    }

    public function test_body_over_the_cap_is_unavailable()
    {
        Http::fake([self::STATUS_URL => Http::response('{"active":false,"pad":"'.str_repeat('x', 1024).'"}')]);

        $check = $this->client()->check(self::JTI, $this->exp);

        $this->assertSame(TokenStatus::Unavailable, $check->status);
        $this->assertSame('malformed', $check->reason);
    }

    public function test_overflow_without_a_transport_error_is_not_read_as_an_answer()
    {
        // Guzzle's PHP stream handler stops copying on a refused write and returns
        // what it has, so the response holds a prefix that parses on its own.
        Http::fake([self::STATUS_URL => function (Request $request, array $options) {
            $options['sink']->write('{"active":false}');
            $options['sink']->write(str_repeat('x', 2000));

            return Http::response('{"active":false}');
        }]);

        $check = $this->client()->check(self::JTI, $this->exp);

        $this->assertSame(TokenStatus::Unavailable, $check->status);
        $this->assertSame('oversized', $check->reason);
        $this->assertNull($this->entry());
    }

    public function test_redirect_is_not_followed_and_is_unavailable()
    {
        Http::fake([
            self::STATUS_URL => Http::response('', 302, ['Location' => 'https://elsewhere.test/status']),
            'https://elsewhere.test/*' => Http::response(['active' => false]),
        ]);

        $check = $this->client()->check(self::JTI, $this->exp);

        $this->assertSame(TokenStatus::Unavailable, $check->status);
        $this->assertSame('http_302', $check->reason);
        Http::assertSentCount(1);
    }

    public function test_missing_url_is_unavailable_without_a_call()
    {
        Config::set('token_revocation.url', null);
        Http::fake();

        $check = $this->client()->check(self::JTI, $this->exp);

        $this->assertSame(TokenStatus::Unavailable, $check->status);
        $this->assertSame('not_configured', $check->reason);
        Http::assertNothingSent();
    }

    public function test_empty_jti_is_unavailable_without_a_call()
    {
        Http::fake();

        $check = $this->client()->check('', $this->exp);

        $this->assertSame(TokenStatus::Unavailable, $check->status);
        $this->assertSame('missing_jti', $check->reason);
        Http::assertNothingSent();
    }

    // Cache hit, miss and lapse

    public function test_second_check_within_the_window_is_a_hit_without_a_call()
    {
        $this->fakeStatus(true);
        $client = $this->client();

        $client->status(self::JTI, $this->exp);
        $this->advance(29);
        $check = $client->check(self::JTI, $this->exp);

        $this->assertSame(TokenStatus::Active, $check->status);
        $this->assertTrue($check->cacheHit);
        $this->assertSame(1, $this->statusCalls());
    }

    public function test_revoked_entry_is_a_hit_without_a_call()
    {
        Cache::store('token_status')->put(TokenStatusClient::KEY_PREFIX.self::JTI, ['status' => 'revoked'], 600);
        Http::fake();

        $check = $this->client()->check(self::JTI, $this->exp);

        $this->assertSame(TokenStatus::Revoked, $check->status);
        $this->assertTrue($check->cacheHit);
        Http::assertNothingSent();
    }

    public function test_active_entry_past_active_until_is_a_miss()
    {
        // The cache entry outlives active_until here; the entry's own field decides.
        Cache::store('token_status')->put(TokenStatusClient::KEY_PREFIX.self::JTI, [
            'status' => 'active',
            'active_until' => (float) self::T0,
        ], 30);
        $this->fakeStatus(false);

        $check = $this->client()->check(self::JTI, $this->exp);

        $this->assertSame(TokenStatus::Revoked, $check->status);
        $this->assertFalse($check->cacheHit);
        $this->assertSame(1, $this->statusCalls());
    }

    public function test_malformed_entry_is_a_miss()
    {
        Cache::store('token_status')->put(TokenStatusClient::KEY_PREFIX.self::JTI, 'revoked', 600);
        $this->fakeStatus(true);

        $this->assertSame(TokenStatus::Active, $this->client()->status(self::JTI, $this->exp));
        $this->assertSame(1, $this->statusCalls());
    }

    // Writes: set-if-absent, t0 anchoring, expiry

    public function test_positive_answer_never_replaces_a_newer_revoked_entry()
    {
        $key = TokenStatusClient::KEY_PREFIX.self::JTI;

        // While this check waits on the issuer, a concurrent check stores `revoked`.
        Http::fake([self::STATUS_URL => function () use ($key) {
            Cache::store('token_status')->put($key, ['status' => 'revoked'], 600);

            return Http::response(['active' => true]);
        }]);

        $this->assertSame(TokenStatus::Active, $this->client()->status(self::JTI, $this->exp));
        $this->assertSame(['status' => 'revoked'], $this->entry());

        Http::fake();
        $this->assertSame(TokenStatus::Revoked, $this->client()->status(self::JTI, $this->exp));
    }

    public function test_active_until_is_anchored_to_when_the_check_started()
    {
        // The issuer takes 1.5 s to answer.
        Http::fake([self::STATUS_URL => function () {
            $this->advance(1.5);

            return Http::response(['active' => true]);
        }]);
        $client = $this->client();

        $client->status(self::JTI, $this->exp);
        $this->assertSame((float) self::T0 + 30, $this->entry()['active_until']);

        Carbon::setTestNow(Carbon::createFromTimestampMs(self::T0 * 1000 + 29_400));
        $this->assertTrue($client->check(self::JTI, $this->exp)->cacheHit);

        // By t0 + 30 the answer is no longer trusted, however late it was written.
        Carbon::setTestNow(Carbon::createFromTimestamp(self::T0 + 30));
        $this->assertFalse($client->check(self::JTI, $this->exp)->cacheHit);
        $this->assertSame(2, $this->statusCalls());
    }

    public function test_slow_answer_does_not_block_the_refresh_after_it_lapses()
    {
        // Only the first answer is slow, so its entry is what the refresh meets.
        $calls = 0;
        Http::fake([self::STATUS_URL => function () use (&$calls) {
            if (++$calls === 1) {
                $this->advance(1.5);
            }

            return Http::response(['active' => true]);
        }]);
        $client = $this->client();

        $client->status(self::JTI, $this->exp);
        Carbon::setTestNow(Carbon::createFromTimestamp(self::T0 + 30));
        $client->status(self::JTI, $this->exp);

        // The refresh at t0 + 30 was stored, so the next check is a hit.
        $this->advance(0.5);
        $this->assertTrue($client->check(self::JTI, $this->exp)->cacheHit);
        $this->assertSame(2, $this->statusCalls());
    }

    public function test_active_entry_ttl_is_capped_by_the_token_expiry()
    {
        $this->fakeStatus(true);

        $this->client()->status(self::JTI, self::T0 + 10);

        $this->advance(9.9);
        $this->assertNotNull($this->entry());
        $this->advance(0.1);
        $this->assertNull($this->entry());
    }

    public function test_expired_token_skips_the_positive_write()
    {
        $this->fakeStatus(true);

        $this->assertSame(TokenStatus::Active, $this->client()->status(self::JTI, self::T0));
        $this->assertNull($this->entry());
    }

    public function test_revoked_entry_is_kept_until_grace_past_expiry()
    {
        $this->fakeStatus(false);

        $this->client()->status(self::JTI, self::T0 + 600);

        Carbon::setTestNow(Carbon::createFromTimestamp(self::T0 + 659));
        $this->assertSame(['status' => 'revoked'], $this->entry());
        Carbon::setTestNow(Carbon::createFromTimestamp(self::T0 + 660));
        $this->assertNull($this->entry());
    }

    public function test_revoked_entry_for_an_expired_token_is_kept_for_the_grace()
    {
        $this->fakeStatus(false);

        $this->client()->status(self::JTI, self::T0 - 30);

        $this->advance(59);
        $this->assertSame(['status' => 'revoked'], $this->entry());
        $this->advance(1);
        $this->assertNull($this->entry());
    }

    public function test_revoked_answer_overwrites_an_active_entry()
    {
        Cache::store('token_status')->put(TokenStatusClient::KEY_PREFIX.self::JTI, [
            'status' => 'active',
            'active_until' => (float) self::T0 - 1,
        ], 30);
        $this->fakeStatus(false);

        $this->client()->status(self::JTI, $this->exp);

        $this->assertSame(['status' => 'revoked'], $this->entry());
    }

    // Breaker

    public function test_unavailable_opens_the_breaker_and_suppresses_calls()
    {
        Http::fake([self::STATUS_URL => Http::response('', 503)]);
        $client = $this->client();

        $client->status(self::JTI, $this->exp);
        $this->advance(9.9);
        $check = $client->check('other-jti', $this->exp);

        $this->assertSame(TokenStatus::Unavailable, $check->status);
        $this->assertSame('breaker_open', $check->reason);
        $this->assertSame(1, $this->statusCalls());

        $this->advance(0.1);
        $client->status(self::JTI, $this->exp);
        $this->assertSame(2, $this->statusCalls());
    }

    /** A response the sink cut off past the cap, as Guzzle's PHP stream handler returns it. */
    private static function overflowing(int $status): Closure
    {
        return function (Request $request, array $options) use ($status) {
            $options['sink']->write(str_repeat('x', TokenStatusClient::MAX_BODY_BYTES + 1));

            return Http::response('{"active":false}', $status);
        };
    }

    /** A transfer cURL aborted when the sink refused the body, after the status line. */
    private static function aborted(int $status): Closure
    {
        return function (Request $request, array $options) use ($status) {
            $options['sink']->write(str_repeat('x', TokenStatusClient::MAX_BODY_BYTES + 1));

            throw new GuzzleRequestException(
                'cURL error 23: Failure writing output to destination',
                new PsrRequest('POST', self::STATUS_URL),
                new PsrResponse($status, [], '<html>')
            );
        };
    }

    /** @return array<string, array{Closure, string}> */
    public static function issuerFailures(): array
    {
        $refused = fn () => Http::response(['message' => 'Unauthenticated.'], 401);

        return [
            'connection failure' => [fn () => throw new ConnectionException('cURL error 6: Could not resolve host'), 'connection'],
            'timeout on the status call' => [fn () => throw new ConnectionException('cURL error 28: Operation timed out'), 'connection'],
            'connection lost after the status line' => [fn () => throw new GuzzleRequestException(
                'cURL error 56: Recv failure: Connection reset by peer',
                new PsrRequest('POST', self::STATUS_URL),
                new PsrResponse(401)
            ), 'connection'],
            'server error' => [fn () => Http::response('', 500), 'http_500'],
            'bad gateway' => [fn () => Http::response('', 502), 'http_502'],
            'rate limited' => [fn () => Http::response('', 429), 'http_429'],
            'redirect' => [fn () => Http::response('', 302, ['Location' => 'https://elsewhere.test/']), 'http_302'],
            'success other than 200' => [fn () => Http::response('', 204), 'http_204'],
            '200 without a boolean active' => [fn () => Http::response(['active' => 'yes']), 'malformed'],
            'oversized 200' => [self::overflowing(200), 'oversized'],
            'oversized 200 cut off by cURL' => [self::aborted(200), 'oversized'],
            'oversized gateway page' => [self::overflowing(502), 'http_502'],
            'oversized gateway page cut off by cURL' => [self::aborted(503), 'http_503'],
            'oversized redirect cut off by cURL' => [self::aborted(302), 'http_302'],
            'refused after the retry' => [$refused, 'http_401'],
            'oversized 401, retried and refused again' => [self::overflowing(401), 'http_401'],
        ];
    }

    #[DataProvider('issuerFailures')]
    public function test_issuer_failure_opens_the_breaker(Closure $answer, string $reason)
    {
        Http::fake([self::STATUS_URL => $answer]);
        $client = $this->client();

        $check = $client->check(self::JTI, $this->exp);

        $this->assertSame(TokenStatus::Unavailable, $check->status);
        $this->assertSame($reason, $check->reason);
        $this->assertNotNull(Cache::store('token_status')->get(TokenStatusClient::BREAKER_KEY));
        $calls = $this->statusCalls();
        $this->assertSame('breaker_open', $client->check('other-jti', $this->exp)->reason);
        $this->assertSame($calls, $this->statusCalls());
    }

    /** @return array<string, array{Closure|null, Closure|null, string, int}> */
    public static function localOrPerTokenOutcomes(): array
    {
        return [
            'no machine token' => [null, fn () => throw new RuntimeException('Could not get new machine token.'), 'machine_token', 0],
            'budget spent before the call' => [null, function () {
                Carbon::setTestNow(Carbon::now()->addMilliseconds(2500));

                return 'late-token';
            }, 'deadline', 0],
            'bad request' => [fn () => Http::response('', 400), null, 'http_400', 1],
            'forbidden' => [fn () => Http::response('', 403), null, 'http_403', 1],
            'not found' => [fn () => Http::response('', 404), null, 'http_404', 1],
            'unprocessable' => [fn () => Http::response('', 422), null, 'http_422', 1],
            'oversized refusal' => [self::overflowing(422), null, 'http_422', 1],
            'oversized refusal cut off by cURL' => [self::aborted(400), null, 'http_400', 1],
        ];
    }

    #[DataProvider('localOrPerTokenOutcomes')]
    public function test_local_or_per_token_outcome_leaves_the_breaker_shut(?Closure $answer, ?Closure $token, string $reason, int $calls)
    {
        Http::fake([self::STATUS_URL => $answer ?? fn () => Http::response(['active' => true])]);
        $machineTokens = $this->machineTokens();

        if ($token !== null) {
            $machineTokens->shouldReceive('getToken')->andReturnUsing($token);
        }

        $client = $this->client($machineTokens);

        $check = $client->check(self::JTI, $this->exp);

        $this->assertSame(TokenStatus::Unavailable, $check->status);
        $this->assertSame($reason, $check->reason);
        $this->assertSame($calls, $this->statusCalls());
        $this->assertNull(Cache::store('token_status')->get(TokenStatusClient::BREAKER_KEY));
        $this->assertNotSame('breaker_open', $client->check('other-jti', $this->exp)->reason);
    }

    public function test_oversized_401_drops_the_machine_token_and_retries()
    {
        $machineTokens = $this->machineTokens();
        $machineTokens->shouldReceive('invalidate')->once();
        $refusal = self::aborted(401);
        $attempts = 0;
        Http::fake([self::STATUS_URL => function (Request $request, array $options) use ($refusal, &$attempts) {
            return ++$attempts === 1 ? $refusal($request, $options) : Http::response(['active' => false]);
        }]);

        $this->assertSame(TokenStatus::Revoked, $this->client($machineTokens)->status(self::JTI, $this->exp));
        $this->assertSame(2, $this->statusCalls());
    }

    public function test_breaker_seconds_zero_never_suppresses_a_call()
    {
        Config::set('token_revocation.breaker_seconds', 0);
        Http::fake([self::STATUS_URL => Http::response('', 503)]);
        $client = $this->client();

        $client->status(self::JTI, $this->exp);
        $check = $client->check('other-jti', $this->exp);

        $this->assertSame('http_503', $check->reason);
        $this->assertSame(2, $this->statusCalls());
        $this->assertNull(Cache::store('token_status')->get(TokenStatusClient::BREAKER_KEY));
    }

    public function test_cached_answers_are_served_while_the_breaker_is_open()
    {
        Cache::store('token_status')->put(TokenStatusClient::KEY_PREFIX.self::JTI, ['status' => 'revoked'], 600);
        Cache::store('token_status')->put(TokenStatusClient::BREAKER_KEY, 1, 10);
        Http::fake();

        $this->assertSame(TokenStatus::Revoked, $this->client()->status(self::JTI, $this->exp));
        Http::assertNothingSent();
    }

    public function test_breaker_open_check_does_not_extend_the_breaker()
    {
        Http::fake([self::STATUS_URL => Http::response('', 503)]);
        $client = $this->client();

        $client->status(self::JTI, $this->exp);
        $this->advance(9);
        $client->status(self::JTI, $this->exp);
        $this->advance(1);

        $this->assertNull(Cache::store('token_status')->get(TokenStatusClient::BREAKER_KEY));
    }

    // Cache failures

    public function test_throwing_cache_store_still_makes_the_call()
    {
        ThrowingCacheStore::register();
        Config::set('cache.stores.token_status', ['driver' => 'throwing']);
        $this->fakeStatus(false);

        $check = $this->client()->check(self::JTI, $this->exp);

        $this->assertSame(TokenStatus::Revoked, $check->status);
        $this->assertTrue($check->cacheError);
        $this->assertSame(1, $this->statusCalls());
    }

    public function test_throwing_cache_store_skips_the_breaker()
    {
        ThrowingCacheStore::register();
        Config::set('cache.stores.token_status', ['driver' => 'throwing']);
        Http::fake([self::STATUS_URL => Http::response('', 503)]);
        $client = $this->client();

        $client->status(self::JTI, $this->exp);
        $client->status(self::JTI, $this->exp);

        $this->assertSame(2, $this->statusCalls());
    }

    public function test_undefined_cache_store_still_makes_the_call()
    {
        Config::set('cache.stores.token_status', null);
        $this->fakeStatus(true);

        $check = $this->client()->check(self::JTI, $this->exp);

        $this->assertSame(TokenStatus::Active, $check->status);
        $this->assertTrue($check->cacheError);
    }

    public function test_both_caches_throwing_with_the_real_machine_token_service_still_enforces()
    {
        ThrowingCacheStore::register();
        Config::set('cache.stores.token_status', ['driver' => 'throwing']);
        Config::set('cache.stores.broken', ['driver' => 'throwing']);
        Config::set('cache.default', 'broken');
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'fresh-token', 'expires_in' => 3600]),
            self::STATUS_URL => Http::response(['active' => false]),
        ]);

        $this->assertSame(TokenStatus::Revoked, $this->realClient()->status(self::JTI, $this->exp));
        Http::assertSent(fn (Request $request) => $request->url() === self::STATUS_URL
            && $request->hasHeader('Authorization', 'Bearer fresh-token'));
        $this->assertGreaterThan(0, ThrowingCacheStore::$calls);
    }

    // The service's own machine token

    public function test_refused_machine_token_is_dropped_refetched_and_the_call_retried()
    {
        Cache::put('machine_token', ['token' => 'revoked-token', 'expiry' => Carbon::now()->addDays(30)->toIso8601String()]);
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'new-token', 'expires_in' => 3600]),
            self::STATUS_URL => fn (Request $request) => $request->hasHeader('Authorization', 'Bearer new-token')
                ? Http::response(['active' => false])
                : Http::response(['message' => 'Unauthenticated.'], 401),
        ]);

        $this->assertSame(TokenStatus::Revoked, $this->realClient()->status(self::JTI, $this->exp));
        $this->assertSame(2, $this->statusCalls());
        $this->assertSame('new-token', Cache::get('machine_token')['token']);
    }

    public function test_late_refusal_uses_the_replacement_another_process_cached()
    {
        Cache::put('machine_token', ['token' => 'revoked-token', 'expiry' => Carbon::now()->addDays(30)->toIso8601String()]);
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'own-token', 'expires_in' => 3600]),
            self::STATUS_URL => function (Request $request) {
                if ($request->hasHeader('Authorization', 'Bearer replacement-token')) {
                    return Http::response(['active' => true]);
                }

                // Another process refreshed the shared token while this call ran.
                Cache::put('machine_token', ['token' => 'replacement-token', 'expiry' => Carbon::now()->addDays(30)->toIso8601String()]);

                return Http::response(['message' => 'Unauthenticated.'], 401);
            },
        ]);

        $this->assertSame(TokenStatus::Active, $this->realClient()->status(self::JTI, $this->exp));
        $this->assertSame(2, $this->statusCalls());
        Http::assertNotSent(fn (Request $request) => $request->url() === self::TOKEN_URL);
        $this->assertSame('replacement-token', Cache::get('machine_token')['token']);
    }

    public function test_refusal_during_a_token_endpoint_outage_keeps_the_shared_token()
    {
        Cache::put('machine_token', ['token' => 'shared-token', 'expiry' => Carbon::now()->addDays(30)->toIso8601String()]);
        Http::fake([
            self::TOKEN_URL => Http::response(['error' => 'unavailable'], 503),
            self::STATUS_URL => Http::response(['message' => 'Unauthenticated.'], 401),
        ]);

        $check = $this->realClient()->check(self::JTI, $this->exp);

        $this->assertSame(TokenStatus::Unavailable, $check->status);
        $this->assertSame('machine_token', $check->reason);
        $this->assertSame(1, $this->statusCalls());
        $this->assertSame('shared-token', Cache::get('machine_token')['token']);
    }

    public function test_refusal_of_a_stale_replacement_opens_the_breaker_for_one_period()
    {
        Cache::put('machine_token', ['token' => 'refused-token', 'expiry' => Carbon::now()->addDays(30)->toIso8601String()]);
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'fresh-token', 'expires_in' => 3600]),
            self::STATUS_URL => function (Request $request) {
                if ($request->hasHeader('Authorization', 'Bearer fresh-token')) {
                    return Http::response(['active' => false]);
                }

                if ($request->hasHeader('Authorization', 'Bearer refused-token')) {
                    // Another process cached a replacement the route refuses as well.
                    Cache::put('machine_token', ['token' => 'stale-token', 'expiry' => Carbon::now()->addDays(30)->toIso8601String()]);
                }

                return Http::response(['message' => 'Unauthenticated.'], 401);
            },
        ]);
        $client = $this->realClient();

        $this->assertSame('http_401', $client->check(self::JTI, $this->exp)->reason);
        $this->advance(5);
        $this->assertSame('breaker_open', $client->check('other-jti', $this->exp)->reason);
        $this->advance(5);

        $this->assertSame(TokenStatus::Revoked, $client->status(self::JTI, $this->exp));
        $this->assertSame(4, $this->statusCalls());
        Http::assertSentCount(5);
        $this->assertSame('fresh-token', Cache::get('machine_token')['token']);
        $this->assertNull(Cache::store('token_status')->get(TokenStatusClient::BREAKER_KEY));
    }

    public function test_forbidden_is_unavailable_without_a_refetch()
    {
        Cache::put('machine_token', ['token' => 'valid-token', 'expiry' => Carbon::now()->addDays(30)->toIso8601String()]);
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'new-token', 'expires_in' => 3600]),
            self::STATUS_URL => Http::response(['message' => 'Forbidden'], 403),
        ]);

        $check = $this->realClient()->check(self::JTI, $this->exp);

        $this->assertSame(TokenStatus::Unavailable, $check->status);
        $this->assertSame('http_403', $check->reason);
        $this->assertSame(1, $this->statusCalls());
        Http::assertNotSent(fn (Request $request) => $request->url() === self::TOKEN_URL);
        $this->assertSame('valid-token', Cache::get('machine_token')['token']);
        $this->assertNull(Cache::store('token_status')->get(TokenStatusClient::BREAKER_KEY));
    }

    public function test_second_refusal_is_unavailable()
    {
        $machineTokens = $this->machineTokens();
        $machineTokens->shouldReceive('invalidate')->once();
        // The clock moves with each call, so a retry loop without its limit
        // ends at the deadline instead of hanging.
        Http::fake([self::STATUS_URL => function () {
            $this->advance(0.1);

            return Http::response(['message' => 'Unauthenticated.'], 401);
        }]);

        $check = $this->client($machineTokens)->check(self::JTI, $this->exp);

        $this->assertSame(TokenStatus::Unavailable, $check->status);
        $this->assertSame('http_401', $check->reason);
        $this->assertSame(2, $this->statusCalls());
    }

    public function test_invalidate_failure_still_retries()
    {
        $machineTokens = $this->machineTokens();
        $machineTokens->shouldReceive('invalidate')->andThrow(new RuntimeException('cache down'));
        Http::fakeSequence(self::STATUS_URL)
            ->push(['message' => 'Unauthenticated.'], 401)
            ->push(['active' => true]);

        $this->assertSame(TokenStatus::Active, $this->client($machineTokens)->status(self::JTI, $this->exp));
    }

    public function test_machine_token_acquisition_throwing_is_unavailable_without_a_call()
    {
        $machineTokens = $this->machineTokens();
        $machineTokens->shouldReceive('getToken')->andThrow(new RuntimeException('Could not get new machine token.'));
        Http::fake();

        $check = $this->client($machineTokens)->check(self::JTI, $this->exp);

        $this->assertSame(TokenStatus::Unavailable, $check->status);
        $this->assertSame('machine_token', $check->reason);
        Http::assertNothingSent();
    }

    public function test_machine_token_acquisition_throwing_an_error_is_unavailable()
    {
        $machineTokens = $this->machineTokens();
        $machineTokens->shouldReceive('getToken')->andThrow(new \TypeError('bad config'));
        Http::fake();

        $check = $this->client($machineTokens)->check(self::JTI, $this->exp);

        $this->assertSame(TokenStatus::Unavailable, $check->status);
        $this->assertSame('machine_token', $check->reason);
        $this->assertNull(Cache::store('token_status')->get(TokenStatusClient::BREAKER_KEY));
    }

    public function test_time_spent_reading_the_status_cache_comes_out_of_the_budget()
    {
        Event::listen(RetrievingManyKeys::class, fn () => $this->advance(0.5));
        $timeout = null;
        Http::fake([self::STATUS_URL => function (Request $request, array $options) use (&$timeout) {
            $timeout = $options['timeout'];

            return Http::response(['active' => true]);
        }]);

        $this->client()->status(self::JTI, $this->exp);

        $this->assertEqualsWithDelta(1.5, $timeout, 0.001);
    }

    public function test_a_jti_named_like_the_breaker_cannot_open_it()
    {
        $this->fakeStatus(false);
        $client = $this->client();

        $client->status('breaker', $this->exp);
        $check = $client->check(self::JTI, $this->exp);

        $this->assertNotSame('breaker_open', $check->reason);
        $this->assertSame(2, $this->statusCalls());
    }

    public function test_machine_token_acquisition_stalling_past_the_budget_is_unavailable_without_a_call()
    {
        $machineTokens = $this->machineTokens();
        $machineTokens->shouldReceive('getToken')->andReturnUsing(function () {
            $this->advance(2.5);

            return 'late-token';
        });
        Http::fake();

        $check = $this->client($machineTokens)->check(self::JTI, $this->exp);

        $this->assertSame(TokenStatus::Unavailable, $check->status);
        $this->assertSame('deadline', $check->reason);
        Http::assertNothingSent();
    }

    public function test_machine_token_and_status_call_share_the_budget()
    {
        $budgets = [];
        $machineTokens = $this->machineTokens();
        $machineTokens->shouldReceive('getToken')->andReturnUsing(function (?float $budget) use (&$budgets) {
            $budgets[] = $budget;
            $this->advance(0.5);

            return 'svc-token';
        });
        $timeout = null;
        Http::fake([self::STATUS_URL => function (Request $request, array $options) use (&$timeout) {
            $timeout = $options['timeout'];

            return Http::response(['active' => true]);
        }]);

        $this->client($machineTokens)->status(self::JTI, $this->exp);

        $this->assertEqualsWithDelta(2.0, $budgets[0], 0.001);
        $this->assertEqualsWithDelta(1.5, $timeout, 0.001);
    }

    public function test_real_machine_token_fetch_is_bounded_by_the_remaining_budget()
    {
        $tokenOptions = null;
        Http::fake([
            self::TOKEN_URL => function (Request $request, array $options) use (&$tokenOptions) {
                $tokenOptions = $options;

                return Http::response(['access_token' => 'fresh-token', 'expires_in' => 3600]);
            },
            self::STATUS_URL => Http::response(['active' => true]),
        ]);

        $this->realClient()->status(self::JTI, $this->exp);

        $this->assertEqualsWithDelta(2.0, $tokenOptions['timeout'], 0.001);
        $this->assertLessThanOrEqual(2.0, $tokenOptions['connect_timeout']);
    }

    public function test_stalled_refresh_inside_the_window_still_gets_a_status_answer()
    {
        // The service reads expiry against time(), which the test clock does not move.
        Cache::put('machine_token', ['token' => 'current-token', 'expiry' => date(DATE_ATOM, time() + 200)]);
        $tokenTimeout = null;
        $statusTimeout = null;
        Http::fake([
            self::TOKEN_URL => function (Request $request, array $options) use (&$tokenTimeout) {
                $tokenTimeout = $options['timeout'];
                $this->advance($options['timeout']);

                throw new ConnectionException('cURL error 28: Operation timed out');
            },
            self::STATUS_URL => function (Request $request, array $options) use (&$statusTimeout) {
                $statusTimeout = $options['timeout'];

                return $request->hasHeader('Authorization', 'Bearer current-token')
                    ? Http::response(['active' => false])
                    : Http::response(['message' => 'Unauthenticated.'], 401);
            },
        ]);

        $check = $this->realClient()->check(self::JTI, $this->exp);

        $this->assertSame(TokenStatus::Revoked, $check->status);
        $this->assertEqualsWithDelta(1.0, $tokenTimeout, 0.001);
        $this->assertEqualsWithDelta(1.0, $statusTimeout, 0.001);
    }

    public function test_status_call_after_a_slow_token_gets_only_the_remaining_budget_for_connecting()
    {
        $machineTokens = $this->machineTokens();
        $machineTokens->shouldReceive('getToken')->andReturnUsing(function () {
            $this->advance(1.4);

            return 'svc-token';
        });
        $options = null;
        Http::fake([self::STATUS_URL => function (Request $request, array $sent) use (&$options) {
            $options = $sent;

            return Http::response(['active' => true]);
        }]);

        $this->client($machineTokens)->status(self::JTI, $this->exp);

        $this->assertEqualsWithDelta(0.6, $options['connect_timeout'], 0.001);
        $this->assertEqualsWithDelta(0.6, $options['timeout'], 0.001);
    }
}
