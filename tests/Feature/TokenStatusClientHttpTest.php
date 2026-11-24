<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Mockery;
use NuiMarkets\LaravelSharedUtils\Auth\TokenStatus;
use NuiMarkets\LaravelSharedUtils\Auth\TokenStatusCheck;
use NuiMarkets\LaravelSharedUtils\Auth\TokenStatusClient;
use NuiMarkets\LaravelSharedUtils\Services\MachineTokenService;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * The status call through Laravel's real HTTP stack against a local server.
 * Http::fake() responses never reach the response sink or the transport's
 * timeouts, so the body cap and the deadline are only proven here.
 */
class TokenStatusClientHttpTest extends TestCase
{
    private static ?Process $server = null;

    private static string $serverUrl = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($probe, false);
        fclose($probe);
        $port = (int) substr($address, strrpos($address, ':') + 1);

        // Workers, so the stalled request does not hold up the tests after it.
        self::$server = new Process(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__.'/../Utils/token-status-server.php'],
            null,
            ['PHP_CLI_SERVER_WORKERS' => '4']
        );
        self::$server->start();
        self::$serverUrl = "http://127.0.0.1:{$port}";

        $deadline = microtime(true) + 5;

        while (microtime(true) < $deadline) {
            $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);

            if ($connection) {
                fclose($connection);

                return;
            }

            usleep(50_000);
        }

        throw new RuntimeException('Local status server did not start: '.self::$server->getErrorOutput());
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop(0);
        self::$server = null;

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cache.stores.token_status', ['driver' => 'array']);
        Config::set('token_revocation', require __DIR__.'/../../config/token_revocation.php');
    }

    private function check(string $path): TokenStatusCheck
    {
        Config::set('token_revocation.url', self::$serverUrl.$path);

        $machineTokens = Mockery::mock(MachineTokenService::class);
        $machineTokens->shouldReceive('getToken')->andReturn('svc-token');

        return (new TokenStatusClient($machineTokens))->check('jti-'.$path, time() + 3600);
    }

    public function test_inactive_answer_through_the_real_transport_is_revoked()
    {
        $check = $this->check('/status/inactive');

        $this->assertSame(TokenStatus::Revoked, $check->status);
    }

    public function test_body_that_inflates_past_the_cap_aborts_the_transfer()
    {
        $check = $this->check('/status/inflating');

        $this->assertSame(TokenStatus::Unavailable, $check->status);
        $this->assertSame('oversized', $check->reason);
    }

    public function test_declared_body_past_the_cap_aborts_the_transfer()
    {
        $check = $this->check('/status/large');

        $this->assertSame(TokenStatus::Unavailable, $check->status);
        $this->assertSame('oversized', $check->reason);
    }

    public function test_overflow_ends_the_call_without_waiting_for_the_rest()
    {
        $started = microtime(true);

        $check = $this->check('/status/large-then-stall');

        $this->assertSame('oversized', $check->reason);
        $this->assertLessThan(1.0, microtime(true) - $started);
    }

    public function test_oversized_gateway_error_keeps_its_status_and_opens_the_breaker()
    {
        $check = $this->check('/status/large-502');

        $this->assertSame(TokenStatus::Unavailable, $check->status);
        $this->assertSame('http_502', $check->reason);
        $this->assertNotNull(Cache::store('token_status')->get(TokenStatusClient::BREAKER_KEY));
    }

    public function test_oversized_401_is_retried_and_leaves_the_breaker_shut()
    {
        Config::set('token_revocation.url', self::$serverUrl.'/status/large-401');
        $machineTokens = Mockery::mock(MachineTokenService::class);
        $machineTokens->shouldReceive('getToken')->andReturn('svc-token');
        $machineTokens->shouldReceive('invalidate')->once();

        $check = (new TokenStatusClient($machineTokens))->check('jti-large-401', time() + 3600);

        $this->assertSame(TokenStatus::Unavailable, $check->status);
        $this->assertSame('http_401', $check->reason);
        $this->assertNull(Cache::store('token_status')->get(TokenStatusClient::BREAKER_KEY));
    }

    public function test_stalled_answer_is_unavailable_within_the_budget()
    {
        Config::set('token_revocation.timeout', 1.0);
        $started = microtime(true);

        $check = $this->check('/status/stall');

        $this->assertSame(TokenStatus::Unavailable, $check->status);
        $this->assertSame('connection', $check->reason);
        $this->assertLessThan(1.5, microtime(true) - $started);
    }

    public function test_redirect_is_not_followed_by_the_real_transport()
    {
        $check = $this->check('/status/redirect');

        $this->assertSame(TokenStatus::Unavailable, $check->status);
        $this->assertSame('http_302', $check->reason);
    }

    public function test_dripping_answer_is_unavailable_within_the_budget()
    {
        Config::set('token_revocation.timeout', 1.0);
        $started = microtime(true);

        $check = $this->check('/status/drip');

        $this->assertSame(TokenStatus::Unavailable, $check->status);
        $this->assertSame('connection', $check->reason);
        $this->assertLessThan(1.5, microtime(true) - $started);
    }

    public function test_dripping_token_endpoint_is_unavailable_within_the_budget()
    {
        Config::set('token_revocation.timeout', 1.0);
        Config::set('token_revocation.url', self::$serverUrl.'/status/inactive');
        Config::set('machine_token.url', self::$serverUrl.'/token/drip');
        Config::set('machine_token.client_id', 'client');
        Config::set('machine_token.secret', 'secret');
        Config::set('machine_token.redis_key', 'machine_token');
        Config::set('machine_token.time_before_expire', 300);
        $started = microtime(true);

        $check = (new TokenStatusClient(new MachineTokenService))->check('jti-token-drip', time() + 3600);

        $this->assertSame(TokenStatus::Unavailable, $check->status);
        $this->assertSame('machine_token', $check->reason);
        $this->assertLessThan(1.5, microtime(true) - $started);
    }

    public function test_stalled_refresh_still_leaves_time_for_the_status_call()
    {
        Config::set('token_revocation.timeout', 1.0);
        Config::set('token_revocation.url', self::$serverUrl.'/status/inactive');
        Config::set('machine_token.url', self::$serverUrl.'/token/drip');
        Config::set('machine_token.client_id', 'client');
        Config::set('machine_token.secret', 'secret');
        Config::set('machine_token.redis_key', 'machine_token');
        Config::set('machine_token.time_before_expire', 300);
        // Inside the refresh window, and still valid.
        Cache::put('machine_token', ['token' => 'current-token', 'expiry' => Carbon::now()->addSeconds(200)->toIso8601String()]);
        $started = microtime(true);

        $check = (new TokenStatusClient(new MachineTokenService))->check('jti-refresh-drip', time() + 3600);

        $this->assertSame(TokenStatus::Revoked, $check->status);
        $this->assertLessThan(1.5, microtime(true) - $started);
    }

    /**
     * Both budgeted calls rely on cURL's limit on the whole transfer. Guzzle's
     * PHP stream handler, its choice when cURL is missing, limits each read
     * only, so the drip tests above would run past the budget there.
     */
    public function test_the_package_requires_the_transport_that_enforces_the_budget()
    {
        $composer = json_decode(file_get_contents(__DIR__.'/../../composer.json'), true);

        $this->assertArrayHasKey('ext-curl', $composer['require']);
    }
}
