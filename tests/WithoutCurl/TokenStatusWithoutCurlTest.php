<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\WithoutCurl;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use NuiMarkets\LaravelSharedUtils\Auth\TokenRevocationGuard;
use NuiMarkets\LaravelSharedUtils\Auth\TokenStatus;
use NuiMarkets\LaravelSharedUtils\Auth\TokenStatusClient;
use NuiMarkets\LaravelSharedUtils\Services\MachineTokenService;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;
use RuntimeException;

/**
 * Budgeted calls in a process where ext-curl is loaded but curl_exec and
 * curl_multi_exec are both disabled, so Guzzle would fall back to its PHP
 * stream handler, which bounds each read rather than the whole transfer.
 *
 * The Unit and Feature suites do not include this directory: the
 * WithoutCurlTransportTest feature test runs it in a child process started
 * with those functions disabled.
 */
class TokenStatusWithoutCurlTest extends TestCase
{
    private const STATUS_URL = 'https://issuer.test/api/tokens/status';

    private const TOKEN_URL = 'https://issuer.test/oauth/token';

    protected function setUp(): void
    {
        parent::setUp();

        if (function_exists('curl_exec') || function_exists('curl_multi_exec')) {
            $this->markTestSkipped('Needs curl_exec and curl_multi_exec disabled; WithoutCurlTransportTest runs it that way.');
        }

        Config::set('cache.default', 'array');
        Config::set('cache.stores.token_status', ['driver' => 'array']);
        Config::set('token_revocation', require __DIR__.'/../../config/token_revocation.php');
        Config::set('token_revocation.url', self::STATUS_URL);
        Config::set('token_revocation.sample_rate', 0.0);
        Config::set('machine_token.redis_key', 'machine_token');
        Config::set('machine_token.time_before_expire', 300);
        Config::set('machine_token.url', self::TOKEN_URL);
        Config::set('machine_token.client_id', 'client');
        Config::set('machine_token.secret', 'secret');
    }

    private function clientWithoutTokenCalls(): TokenStatusClient
    {
        $machineTokens = Mockery::mock(MachineTokenService::class);
        $machineTokens->shouldNotReceive('getToken');

        return new TokenStatusClient($machineTokens);
    }

    public function test_the_process_has_the_extension_but_not_its_transfer_functions()
    {
        $this->assertTrue(extension_loaded('curl'));
        $this->assertFalse(function_exists('curl_exec'));
        $this->assertFalse(function_exists('curl_multi_exec'));
    }

    public function test_status_miss_makes_no_call_and_is_unavailable()
    {
        Http::fake();

        $check = $this->clientWithoutTokenCalls()->check('jti-miss', time() + 3600);

        $this->assertSame(TokenStatus::Unavailable, $check->status);
        $this->assertSame('curl_unavailable', $check->reason);
        Http::assertNothingSent();
        $this->assertNull(Cache::store('token_status')->get(TokenStatusClient::BREAKER_KEY));
    }

    public function test_cached_answer_is_still_served()
    {
        Http::fake();
        Cache::store('token_status')->put(TokenStatusClient::KEY_PREFIX.'jti-hit', ['status' => TokenStatus::Revoked->value], 60);

        $check = $this->clientWithoutTokenCalls()->check('jti-hit', time() + 3600);

        $this->assertSame(TokenStatus::Revoked, $check->status);
        $this->assertTrue($check->cacheHit);
        Http::assertNothingSent();
    }

    public function test_guard_warns_with_the_reason_and_applies_the_fail_setting()
    {
        Http::fake();
        Config::set('token_revocation.mode', 'enforce');
        Config::set('token_revocation.fail', 'closed');
        $logged = [];
        Log::spy();
        Log::shouldReceive('log')->andReturnUsing(function (string $level, string $message, array $context = []) use (&$logged) {
            $logged[] = $context;
        });

        $reject = (new TokenRevocationGuard($this->clientWithoutTokenCalls()))->shouldReject('jti-guard', time() + 3600, ['jti' => 'jti-guard']);

        $this->assertTrue($reject);
        $warnings = array_values(array_filter($logged, fn ($context) => ($context['action'] ?? null) === 'unavailable'));
        $this->assertCount(1, $warnings);
        $this->assertSame('curl_unavailable', $warnings[0]['reason']);
        Http::assertNothingSent();
    }

    public function test_budgeted_token_fetch_makes_no_call()
    {
        Http::fake();
        Log::shouldReceive('warning')->once()->with(
            'Machine token fetch skipped: no cURL transport to bound it by the caller\'s time budget',
            Mockery::on(fn ($context) => $context['action'] === 'curl_unavailable' && $context['feature'] === 'machine_token')
        );

        try {
            (new MachineTokenService)->getToken(1.0);
            $this->fail('Expected a budgeted fetch without cURL and without an old token to throw.');
        } catch (RuntimeException $e) {
            $this->assertSame('Could not get new machine token.', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_token_fetch_without_a_budget_is_unchanged()
    {
        Http::fake([self::TOKEN_URL => Http::response(['access_token' => 'plain-token', 'expires_in' => 3600])]);

        $this->assertSame('plain-token', (new MachineTokenService)->getToken());
    }
}
