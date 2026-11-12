<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\Auth;

use Illuminate\Log\LogManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use NuiMarkets\LaravelSharedUtils\Auth\TokenRevocationGuard;
use NuiMarkets\LaravelSharedUtils\Auth\TokenStatus;
use NuiMarkets\LaravelSharedUtils\Auth\TokenStatusCheck;
use NuiMarkets\LaravelSharedUtils\Auth\TokenStatusClient;
use NuiMarkets\LaravelSharedUtils\Services\MachineTokenService;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;
use NuiMarkets\LaravelSharedUtils\Tests\Utils\ThrowingCacheStore;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class TokenRevocationGuardTest extends TestCase
{
    private const STATUS_URL = 'https://issuer.test/api/tokens/status';

    private const JTI = 'f00dfeed';

    private const T0 = 1_800_000_000;

    private const CLAIMS = [
        'jti' => self::JTI,
        'sub' => '42',
        'user_id' => 42,
        'aud' => 'client-7',
        'grant_type' => 'personal_access',
        'scopes' => ['*'],
    ];

    /** @var list<array{level: string, message: string, context: array}> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::createFromTimestamp(self::T0));

        Config::set('app.name', 'orders-service');
        Config::set('cache.stores.token_status', ['driver' => 'array']);
        Config::set('token_revocation', require __DIR__.'/../../../config/token_revocation.php');
        Config::set('token_revocation.url', self::STATUS_URL);
        Config::set('token_revocation.sample_rate', 0.0);

        $this->logged = [];
        Log::spy();
        Log::shouldReceive('log')->andReturnUsing(function (string $level, string $message, array $context = []) {
            $this->logged[] = ['level' => $level, 'message' => $message, 'context' => $context];
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function clientReturning(TokenStatus $status, ?string $reason = null, bool $cacheHit = false): MockInterface
    {
        $client = Mockery::mock(TokenStatusClient::class);
        $client->shouldReceive('check')->andReturn(new TokenStatusCheck($status, $cacheHit, $reason, 1.25));

        return $client;
    }

    private function guard(MockInterface|TokenStatusClient $client): TokenRevocationGuard
    {
        return new TokenRevocationGuard($client);
    }

    /** @return list<array{level: string, message: string, context: array}> */
    private function lines(string $action): array
    {
        return array_values(array_filter($this->logged, fn ($line) => ($line['context']['action'] ?? null) === $action));
    }

    private function machineTokens(): MockInterface
    {
        $mock = Mockery::mock(MachineTokenService::class);
        $mock->shouldReceive('getToken')->andReturn('svc-token');

        return $mock;
    }

    // Decisions: every mode, fail setting and result

    public static function decisions(): array
    {
        return [
            'shadow, open, active' => ['shadow', 'open', TokenStatus::Active, false, null],
            'shadow, open, revoked' => ['shadow', 'open', TokenStatus::Revoked, false, 'would_reject'],
            'shadow, open, unavailable' => ['shadow', 'open', TokenStatus::Unavailable, false, null],
            'shadow, closed, active' => ['shadow', 'closed', TokenStatus::Active, false, null],
            'shadow, closed, revoked' => ['shadow', 'closed', TokenStatus::Revoked, false, 'would_reject'],
            'shadow, closed, unavailable' => ['shadow', 'closed', TokenStatus::Unavailable, false, 'would_reject'],
            'enforce, open, active' => ['enforce', 'open', TokenStatus::Active, false, null],
            'enforce, open, revoked' => ['enforce', 'open', TokenStatus::Revoked, true, 'rejected'],
            'enforce, open, unavailable' => ['enforce', 'open', TokenStatus::Unavailable, false, null],
            'enforce, closed, active' => ['enforce', 'closed', TokenStatus::Active, false, null],
            'enforce, closed, revoked' => ['enforce', 'closed', TokenStatus::Revoked, true, 'rejected'],
            'enforce, closed, unavailable' => ['enforce', 'closed', TokenStatus::Unavailable, true, 'rejected'],
        ];
    }

    #[DataProvider('decisions')]
    public function test_decision(string $mode, string $fail, TokenStatus $status, bool $reject, ?string $rejectLine)
    {
        Config::set('token_revocation.mode', $mode);
        Config::set('token_revocation.fail', $fail);
        $reason = $status === TokenStatus::Unavailable ? 'http_503' : null;

        $this->assertSame($reject, $this->guard($this->clientReturning($status, $reason))->shouldReject(self::JTI, self::T0 + 60, self::CLAIMS));

        $this->assertCount($rejectLine === 'would_reject' ? 1 : 0, $this->lines('would_reject'));
        $this->assertCount($rejectLine === 'rejected' ? 1 : 0, $this->lines('rejected'));
    }

    public function test_off_makes_no_cache_read_and_no_call()
    {
        Config::set('token_revocation.mode', 'off');
        ThrowingCacheStore::register();
        Config::set('cache.stores.token_status', ['driver' => 'throwing']);
        Config::set('cache.default', 'token_status');
        Http::fake();
        $client = Mockery::mock(TokenStatusClient::class);
        $client->shouldNotReceive('check');

        $this->assertFalse($this->guard($client)->shouldReject(self::JTI, self::T0 + 60, self::CLAIMS));

        $this->assertSame(0, ThrowingCacheStore::$calls);
        Http::assertNothingSent();
        $this->assertSame([], $this->logged);
    }

    public function test_unset_mode_is_off()
    {
        Config::set('token_revocation.mode', null);
        $client = Mockery::mock(TokenStatusClient::class);
        $client->shouldNotReceive('check');

        $this->assertFalse($this->guard($client)->shouldReject(self::JTI, self::T0 + 60, self::CLAIMS));
        $this->assertSame([], $this->logged);
    }

    public function test_settings_are_case_and_space_insensitive()
    {
        Config::set('token_revocation.mode', ' Enforce ');
        Config::set('token_revocation.fail', 'CLOSED');

        $this->assertTrue($this->guard($this->clientReturning(TokenStatus::Unavailable, 'connection'))->shouldReject(self::JTI, self::T0 + 60, self::CLAIMS));
    }

    public function test_unrecognised_mode_is_off_and_warned()
    {
        Config::set('token_revocation.mode', 'enforced');
        $client = Mockery::mock(TokenStatusClient::class);
        $client->shouldNotReceive('check');

        $this->assertFalse($this->guard($client)->shouldReject(self::JTI, self::T0 + 60, self::CLAIMS));

        $warning = $this->lines('invalid_setting');
        $this->assertCount(1, $warning);
        $this->assertSame('warning', $warning[0]['level']);
        $this->assertSame('enforced', $warning[0]['context']['mode_setting']);
    }

    public function test_unrecognised_fail_setting_is_open_and_warned()
    {
        Config::set('token_revocation.mode', 'enforce');
        Config::set('token_revocation.fail', 'close');

        $this->assertFalse($this->guard($this->clientReturning(TokenStatus::Unavailable, 'connection'))->shouldReject(self::JTI, self::T0 + 60, self::CLAIMS));
        $this->assertCount(1, $this->lines('invalid_setting'));
    }

    public function test_client_throwing_is_unavailable_under_the_fail_setting()
    {
        Config::set('token_revocation.mode', 'enforce');
        $client = Mockery::mock(TokenStatusClient::class);
        $client->shouldReceive('check')->andThrow(new RuntimeException('boom'));

        Config::set('token_revocation.fail', 'open');
        $this->assertFalse($this->guard($client)->shouldReject(self::JTI, self::T0 + 60, self::CLAIMS));

        Config::set('token_revocation.fail', 'closed');
        $this->assertTrue($this->guard($client)->shouldReject(self::JTI, self::T0 + 60, self::CLAIMS));
    }

    public function test_failing_logger_does_not_change_the_decision()
    {
        Config::set('token_revocation.mode', 'enforce');
        Config::set('token_revocation.sample_rate', 1.0);
        $failing = Mockery::spy(LogManager::class);
        $failing->shouldReceive('log')->andThrow(new RuntimeException('log handler down'));
        Log::swap($failing);

        $this->assertTrue($this->guard($this->clientReturning(TokenStatus::Revoked))->shouldReject(self::JTI, self::T0 + 60, self::CLAIMS));
    }

    public function test_real_client_with_inactive_answer_rejects_in_enforce()
    {
        Config::set('token_revocation.mode', 'enforce');
        Http::fake([self::STATUS_URL => Http::response(['active' => false])]);

        $guard = $this->guard(new TokenStatusClient($this->machineTokens()));

        $this->assertTrue($guard->shouldReject(self::JTI, self::T0 + 60, self::CLAIMS));
        Http::assertSentCount(1);
    }

    // Telemetry

    public function test_reject_lines_carry_the_caller_claims()
    {
        Config::set('token_revocation.mode', 'shadow');

        $this->guard($this->clientReturning(TokenStatus::Revoked))->shouldReject(self::JTI, self::T0 + 60, self::CLAIMS);

        $line = $this->lines('would_reject')[0];
        $this->assertSame('warning', $line['level']);
        $this->assertStringContainsString('would reject', $line['message']);
        $expected = [
            'aud' => 'client-7',
            'grant_type' => 'personal_access',
            'jti' => self::JTI,
            'mode' => 'shadow',
            'result' => 'revoked',
            'sub' => '42',
            'user_id' => 42,
            'validator' => 'orders-service',
        ];
        $actual = array_intersect_key($line['context'], $expected);
        ksort($actual);
        $this->assertSame($expected, $actual);
        $this->assertArrayNotHasKey('scopes', $line['context']);
    }

    public function test_rejected_line_in_enforce()
    {
        Config::set('token_revocation.mode', 'enforce');

        $this->guard($this->clientReturning(TokenStatus::Revoked))->shouldReject(self::JTI, self::T0 + 60, ['aud' => ['a', 'b'], 'sub' => new \stdClass]);

        $line = $this->lines('rejected')[0];
        $this->assertStringContainsString('rejected', $line['message']);
        $this->assertSame(['a', 'b'], $line['context']['aud']);
        $this->assertNull($line['context']['sub']);
        $this->assertNull($line['context']['grant_type']);
        $this->assertSame(self::JTI, $line['context']['jti']);
    }

    public function test_startup_line_once_per_process()
    {
        Config::set('token_revocation.mode', 'shadow');
        Config::set('token_revocation.fail', 'open');
        $client = $this->clientReturning(TokenStatus::Active);

        $this->guard($client)->shouldReject(self::JTI, self::T0 + 60, self::CLAIMS);
        // A second guard instance stands in for the next request under a per-request runtime.
        $this->guard($client)->shouldReject(self::JTI, self::T0 + 60, self::CLAIMS);

        $startup = $this->lines('startup');
        $this->assertCount(1, $startup);
        $this->assertSame('info', $startup[0]['level']);
        $this->assertSame([
            'mode' => 'shadow',
            'fail' => 'open',
            'url_host' => 'issuer.test',
            'cache_store' => 'token_status',
            'cache_store_defined' => true,
        ], array_intersect_key($startup[0]['context'], array_flip(['mode', 'fail', 'url_host', 'cache_store', 'cache_store_defined'])));

        $this->travel(TokenRevocationGuard::STARTUP_INTERVAL)->seconds();
        $this->guard($client)->shouldReject(self::JTI, self::T0 + 60, self::CLAIMS);
        $this->assertCount(2, $this->lines('startup'));
    }

    public function test_startup_line_reports_an_undefined_store()
    {
        Config::set('token_revocation.mode', 'shadow');
        Config::set('cache.stores.token_status', null);

        $this->guard($this->clientReturning(TokenStatus::Active))->shouldReject(self::JTI, self::T0 + 60, self::CLAIMS);

        $this->assertFalse($this->lines('startup')[0]['context']['cache_store_defined']);
    }

    public function test_sampled_check_line_has_result_cache_and_latency()
    {
        Config::set('token_revocation.mode', 'shadow');
        Config::set('token_revocation.sample_rate', 1.0);

        $this->guard($this->clientReturning(TokenStatus::Active, cacheHit: true))->shouldReject(self::JTI, self::T0 + 60, self::CLAIMS);

        $line = $this->lines('check')[0];
        $this->assertSame('info', $line['level']);
        $this->assertSame('active', $line['context']['result']);
        $this->assertSame('hit', $line['context']['cache']);
        $this->assertSame(1.25, $line['context']['latency_ms']);
    }

    public function test_no_check_line_at_zero_sample_rate()
    {
        Config::set('token_revocation.mode', 'shadow');
        Config::set('token_revocation.sample_rate', 0.0);

        $this->guard($this->clientReturning(TokenStatus::Active))->shouldReject(self::JTI, self::T0 + 60, self::CLAIMS);

        $this->assertSame([], $this->lines('check'));
    }

    public function test_unavailable_warning_is_rate_limited()
    {
        Config::set('token_revocation.mode', 'enforce');
        $client = $this->clientReturning(TokenStatus::Unavailable, 'http_503');

        $this->guard($client)->shouldReject(self::JTI, self::T0 + 60, self::CLAIMS);
        $this->guard($client)->shouldReject(self::JTI, self::T0 + 60, self::CLAIMS);

        $warnings = $this->lines('unavailable');
        $this->assertCount(1, $warnings);
        $this->assertSame('warning', $warnings[0]['level']);
        $this->assertSame('http_503', $warnings[0]['context']['reason']);
        $this->assertTrue($warnings[0]['context']['admitted']);

        $this->travel(TokenRevocationGuard::WARNING_INTERVAL)->seconds();
        $this->guard($client)->shouldReject(self::JTI, self::T0 + 60, self::CLAIMS);
        $this->assertCount(2, $this->lines('unavailable'));
    }

    public function test_breaker_open_warning_is_rate_limited_separately()
    {
        Config::set('token_revocation.mode', 'enforce');
        Config::set('token_revocation.fail', 'closed');

        $this->guard($this->clientReturning(TokenStatus::Unavailable, 'http_503'))->shouldReject(self::JTI, self::T0 + 60, self::CLAIMS);
        $this->guard($this->clientReturning(TokenStatus::Unavailable, 'breaker_open'))->shouldReject(self::JTI, self::T0 + 60, self::CLAIMS);
        $this->guard($this->clientReturning(TokenStatus::Unavailable, 'breaker_open'))->shouldReject(self::JTI, self::T0 + 60, self::CLAIMS);

        $this->assertCount(1, $this->lines('unavailable'));
        $breaker = $this->lines('breaker_open');
        $this->assertCount(1, $breaker);
        $this->assertFalse($breaker[0]['context']['admitted']);
    }

    public function test_rate_limit_falls_back_to_logging_when_the_store_fails()
    {
        Config::set('token_revocation.mode', 'enforce');
        ThrowingCacheStore::register();
        Config::set('cache.stores.token_status', ['driver' => 'throwing']);
        $client = $this->clientReturning(TokenStatus::Unavailable, 'connection');
        $guard = $this->guard($client);

        $guard->shouldReject(self::JTI, self::T0 + 60, self::CLAIMS);
        $guard->shouldReject(self::JTI, self::T0 + 60, self::CLAIMS);

        // Once per guard instance (one request under a per-request runtime).
        $this->assertCount(1, $this->lines('startup'));
        $this->assertCount(1, $this->lines('unavailable'));
    }
}
