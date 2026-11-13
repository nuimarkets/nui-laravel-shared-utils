<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Feature;

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
}
