<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\Auth;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\MockInterface;
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
        $this->assertSame(TokenStatusClient::MAX_BODY_BYTES, $options['curl'][CURLOPT_MAXFILESIZE]);
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

        Carbon::setTestNow(Carbon::createFromTimestampMs(self::T0 * 1000 + 29_900));
        $this->assertTrue($client->check(self::JTI, $this->exp)->cacheHit);

        // 30 s after t0 the entry is lapsed, though its cache TTL (from the write at t0 + 1.5) is not.
        Carbon::setTestNow(Carbon::createFromTimestamp(self::T0 + 30));
        $this->assertNotNull($this->entry());
        $this->assertFalse($client->check(self::JTI, $this->exp)->cacheHit);
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

    public static function refusals(): array
    {
        return ['401' => [401], '403' => [403]];
    }

    #[DataProvider('refusals')]
    public function test_refused_machine_token_is_dropped_refetched_and_the_call_retried(int $refusal)
    {
        Cache::put('machine_token', ['token' => 'revoked-token', 'expiry' => Carbon::now()->addDays(30)->toIso8601String()]);
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'new-token', 'expires_in' => 3600]),
            self::STATUS_URL => fn (Request $request) => $request->hasHeader('Authorization', 'Bearer new-token')
                ? Http::response(['active' => false])
                : Http::response(['message' => 'Unauthenticated.'], $refusal),
        ]);

        $this->assertSame(TokenStatus::Revoked, $this->realClient()->status(self::JTI, $this->exp));
        $this->assertSame(2, $this->statusCalls());
        $this->assertSame('new-token', Cache::get('machine_token')['token']);
    }

    public function test_second_refusal_is_unavailable()
    {
        $machineTokens = $this->machineTokens();
        $machineTokens->shouldReceive('invalidate')->once();
        Http::fake([self::STATUS_URL => Http::response(['message' => 'Unauthenticated.'], 401)]);

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

        $this->assertSame(TokenStatus::Unavailable, $this->client($machineTokens)->status(self::JTI, $this->exp));
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
