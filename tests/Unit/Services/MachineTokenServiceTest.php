<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use NuiMarkets\LaravelSharedUtils\Services\MachineTokenService;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;
use RuntimeException;

class MachineTokenServiceTest extends TestCase
{
    private MachineTokenService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cache.default', 'array');
        Cache::flush();

        Config::set('machine_token.redis_key', 'test_machine_token');
        Config::set('machine_token.time_before_expire', 300);
        Config::set('machine_token.url', 'https://auth.test.com/token');
        Config::set('machine_token.client_id', 'test_client_id');
        Config::set('machine_token.secret', 'test_client_secret');

        $this->service = new MachineTokenService;
    }

    protected function tearDown(): void
    {
        Cache::flush();

        parent::tearDown();
    }

    public function test_get_token_returns_cached_token_without_http_request()
    {
        $cachedToken = 'cached_jwt_token_67890';
        $expiry = Carbon::now()->addHours(2)->toIso8601String();

        Cache::put('test_machine_token', [
            'token' => $cachedToken,
            'expiry' => $expiry,
        ]);

        Http::fake();

        $token = $this->service->getToken();

        $this->assertEquals($cachedToken, $token);
        Http::assertNothingSent();
    }

    public function test_token_inside_refresh_window_retrieves_new_token()
    {
        $oldToken = 'old_jwt_token_11111';
        $newToken = 'new_jwt_token_22222';
        $nearExpiryTime = Carbon::now()->addSeconds(200)->toIso8601String();

        Cache::put('test_machine_token', [
            'token' => $oldToken,
            'expiry' => $nearExpiryTime,
        ]);

        Http::fake([
            'https://auth.test.com/token' => Http::response([
                'access_token' => $newToken,
                'expires_in' => 3600,
            ], 200),
        ]);

        $token = $this->service->getToken();

        $this->assertEquals($newToken, $token);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://auth.test.com/token' &&
                   $request['grant_type'] === 'client_credentials' &&
                   $request['client_id'] === 'test_client_id' &&
                   $request['client_secret'] === 'test_client_secret';
        });
    }

    public function test_mild_panic_returns_old_token_and_logs_error()
    {
        $oldToken = 'old_but_valid_token_66666';
        $expiry = Carbon::now()->addSeconds(200)->toIso8601String();

        Cache::put('test_machine_token', [
            'token' => $oldToken,
            'expiry' => $expiry,
        ]);

        Http::fake([
            'https://auth.test.com/token' => Http::response(['error' => 'service_unavailable'], 500),
        ]);

        Log::shouldReceive('error')->once()->with(
            'Could not get a new machine token. Current one expires: '.$expiry,
            Mockery::on(function ($context) use ($expiry) {
                return $context['error_type'] === 'machine_token_refresh_failed' &&
                       $context['feature'] === 'machine_token' &&
                       $context['action'] === 'token_refresh_failed' &&
                       $context['token_expiry'] === $expiry;
            })
        );
        Log::shouldReceive('warning')->never();

        $token = $this->service->getToken();

        $this->assertEquals($oldToken, $token);
    }

    public function test_full_panic_throws_when_refresh_fails_without_old_token()
    {
        Http::fake([
            'https://auth.test.com/token' => Http::response(['error' => 'service_unavailable'], 500),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not get new machine token.');

        $this->service->getToken();
    }

    public function test_missing_configuration_logs_warning_and_throws_without_http_request()
    {
        Config::set('machine_token.url', null);

        Http::fake();

        Log::shouldReceive('warning')->once()->with(
            'Machine token configuration incomplete',
            Mockery::on(function ($context) {
                return $context['error_type'] === 'machine_token_config_missing' &&
                       $context['feature'] === 'machine_token' &&
                       $context['action'] === 'config_missing' &&
                       $context['has_url'] === false &&
                       $context['has_client_id'] === true &&
                       $context['has_secret'] === true;
            })
        );
        Log::shouldReceive('error')->never();

        try {
            $this->service->getToken();
            $this->fail('Expected missing configuration to throw.');
        } catch (RuntimeException $e) {
            $this->assertSame('Could not get new machine token.', $e->getMessage());
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_malformed_token_response_missing_access_token_triggers_panic()
    {
        Http::fake([
            'https://auth.test.com/token' => Http::response([
                'expires_in' => 3600,
            ], 200),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not get new machine token.');

        $this->service->getToken();
    }

    public function test_malformed_token_response_missing_expires_in_triggers_panic()
    {
        Http::fake([
            'https://auth.test.com/token' => Http::response([
                'access_token' => 'new_jwt_token_99999',
            ], 200),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not get new machine token.');

        $this->service->getToken();
    }

    public function test_connection_failure_logs_warning_and_throws_without_old_token()
    {
        Http::fake(function () {
            throw new \Exception('connection refused');
        });

        Log::shouldReceive('warning')->once()->with(
            'Failed to retrieve token from machine token service: connection refused',
            Mockery::on(function ($context) {
                return $context['error_type'] === 'machine_token_connection_failed' &&
                       $context['feature'] === 'machine_token' &&
                       $context['action'] === 'connection_failed' &&
                       $context['error'] === 'connection refused';
            })
        );
        Log::shouldReceive('error')->never();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not get new machine token.');

        $this->service->getToken();
    }
}
