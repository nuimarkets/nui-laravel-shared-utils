<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\Services;

use Illuminate\Log\LogManager;
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

    private function fakeTokenEndpoint(string ...$tokens): void
    {
        $sequence = Http::fakeSequence('https://auth.test.com/token');

        foreach ($tokens as $token) {
            $sequence->push(['access_token' => $token, 'expires_in' => 3600]);
        }
    }

    /** Expects one warning with this message, feature, action and error type. */
    private function expectWarning(string $message, string $action, string $errorType = 'machine_token_cache_failed'): void
    {
        Log::shouldReceive('warning')->once()->with($message, Mockery::on(
            fn ($context) => $context['feature'] === 'machine_token'
                && $context['action'] === $action
                && $context['error_type'] === $errorType
        ));
    }

    public function test_cache_read_failure_fetches_a_token_directly()
    {
        $this->expectWarning('Machine token cache read failed: Connection refused', 'cache_read_failed');
        Cache::shouldReceive('flush'); // tearDown
        Cache::shouldReceive('get')->once()->andThrow(new RuntimeException('Connection refused'));
        Cache::shouldReceive('put')->once();
        $this->fakeTokenEndpoint('direct_token');

        $this->assertSame('direct_token', $this->service->getToken());
    }

    public function test_cache_write_failure_keeps_the_fresh_token_in_memory()
    {
        $this->expectWarning('Machine token cache write failed: Connection refused', 'cache_write_failed');
        Cache::shouldReceive('flush'); // tearDown
        Cache::shouldReceive('get')->once()->andReturn(null);
        Cache::shouldReceive('put')->once()->andThrow(new RuntimeException('Connection refused'));
        $this->fakeTokenEndpoint('fresh_token', 'second_token');

        $this->assertSame('fresh_token', $this->service->getToken());
        $this->assertSame('fresh_token', $this->service->getToken());
        Http::assertSentCount(1);
    }

    public function test_invalidate_drops_the_memory_and_cache_copies()
    {
        $this->fakeTokenEndpoint('first_token', 'second_token');

        $this->assertSame('first_token', $this->service->getToken());
        $this->service->invalidate();

        $this->assertNull(Cache::get('test_machine_token'));
        $this->assertSame('second_token', $this->service->getToken());
        $this->assertSame('second_token', Cache::get('test_machine_token')['token']);
    }

    public function test_invalidate_skips_a_cache_copy_it_could_not_forget()
    {
        $this->expectWarning('Machine token cache forget failed: Connection refused', 'cache_forget_failed');
        Cache::shouldReceive('flush'); // tearDown
        Cache::shouldReceive('get')->andReturn([
            'token' => 'refused_token',
            'expiry' => Carbon::now()->addDays(30)->toIso8601String(),
        ]);
        Cache::shouldReceive('forget')->once()->andThrow(new RuntimeException('Connection refused'));
        Cache::shouldReceive('put')->once();
        $this->fakeTokenEndpoint('replacement_token');

        $this->assertSame('refused_token', $this->service->getToken());
        $this->service->invalidate();

        $this->assertSame('replacement_token', $this->service->getToken());
    }

    public function test_invalidate_drops_the_old_token_fallback()
    {
        Cache::put('test_machine_token', [
            'token' => 'refused_token',
            'expiry' => Carbon::now()->addSeconds(200)->toIso8601String(),
        ]);
        Http::fake(['https://auth.test.com/token' => Http::response(['error' => 'unavailable'], 500)]);

        // Inside the refresh window a failed refresh falls back to the old token...
        $this->assertSame('refused_token', $this->service->getToken());

        // ...but not once the caller has said that token was refused.
        $this->service->invalidate();
        $this->expectException(RuntimeException::class);
        $this->service->getToken();
    }

    public function test_budget_bounds_the_token_fetch()
    {
        $options = null;
        Http::fake(['https://auth.test.com/token' => function ($request, array $sent) use (&$options) {
            $options = $sent;

            return Http::response(['access_token' => 'bounded_token', 'expires_in' => 3600]);
        }]);

        $this->assertSame('bounded_token', $this->service->getToken(1.25));
        $this->assertEqualsWithDelta(1.25, $options['timeout'], 0.05);
        $this->assertSame($options['timeout'], $options['connect_timeout']);
        $this->assertFalse($options['allow_redirects']);
    }

    public function test_time_spent_reading_the_cache_comes_out_of_the_budget()
    {
        Carbon::setTestNow(Carbon::now());
        Cache::shouldReceive('flush'); // tearDown
        Cache::shouldReceive('get')->once()->andReturnUsing(function () {
            Carbon::setTestNow(Carbon::now()->addMilliseconds(1500));

            return null;
        });
        Cache::shouldReceive('put')->once();
        $options = null;
        Http::fake(['https://auth.test.com/token' => function ($request, array $sent) use (&$options) {
            $options = $sent;

            return Http::response(['access_token' => 'late_token', 'expires_in' => 3600]);
        }]);

        try {
            $this->assertSame('late_token', $this->service->getToken(2.0));
        } finally {
            Carbon::setTestNow();
        }

        $this->assertEqualsWithDelta(0.5, $options['timeout'], 0.001);
    }

    public function test_cache_read_that_spends_the_budget_makes_no_fetch()
    {
        Carbon::setTestNow(Carbon::now());
        Cache::shouldReceive('flush'); // tearDown
        Cache::shouldReceive('get')->once()->andReturnUsing(function () {
            Carbon::setTestNow(Carbon::now()->addSeconds(3));

            return null;
        });
        Http::fake();

        try {
            $this->service->getToken(2.0);
            $this->fail('Expected a spent budget without an old token to throw.');
        } catch (RuntimeException $e) {
            $this->assertSame('Could not get new machine token.', $e->getMessage());
        } finally {
            Carbon::setTestNow();
        }

        Http::assertNothingSent();
    }

    public function test_budgeted_fetch_does_not_follow_a_redirect()
    {
        Http::fake([
            'https://auth.test.com/token' => Http::response('', 302, ['Location' => 'https://auth.test.com/elsewhere']),
            'https://auth.test.com/elsewhere' => Http::response(['access_token' => 'redirected_token', 'expires_in' => 3600]),
        ]);

        $this->expectException(RuntimeException::class);

        try {
            $this->service->getToken(2.0);
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_unbudgeted_fetch_keeps_the_client_redirect_default()
    {
        $options = null;
        Http::fake(['https://auth.test.com/token' => function ($request, array $sent) use (&$options) {
            $options = $sent;

            return Http::response(['access_token' => 'default_token', 'expires_in' => 3600]);
        }]);

        $this->service->getToken();

        $this->assertNotFalse($options['allow_redirects'] ?? null);
    }

    public function test_failing_logger_does_not_undo_a_cache_fallback()
    {
        $failing = Mockery::spy(LogManager::class);
        $failing->shouldReceive('warning')->andThrow(new RuntimeException('log handler down'));
        Log::swap($failing);
        Cache::shouldReceive('flush'); // tearDown
        Cache::shouldReceive('get')->once()->andReturn(null);
        Cache::shouldReceive('put')->once()->andThrow(new RuntimeException('Connection refused'));
        Cache::shouldReceive('forget')->once()->andThrow(new RuntimeException('Connection refused'));
        $this->fakeTokenEndpoint('fresh_token', 'second_token');

        $this->assertSame('fresh_token', $this->service->getToken());
        $this->assertSame('fresh_token', $this->service->getToken());
        Http::assertSentCount(1);

        $this->service->invalidate();
        $failing->shouldHaveReceived('warning')->twice();
    }

    public function test_without_a_budget_the_fetch_keeps_the_client_defaults()
    {
        $options = null;
        Http::fake(['https://auth.test.com/token' => function ($request, array $sent) use (&$options) {
            $options = $sent;

            return Http::response(['access_token' => 'default_token', 'expires_in' => 3600]);
        }]);

        $this->service->getToken();

        $this->assertSame(30, $options['timeout']);
    }

    public function test_spent_budget_makes_no_fetch()
    {
        $this->expectWarning("Machine token fetch skipped: the caller's time budget is spent", 'budget_spent', 'machine_token_budget_spent');
        Http::fake();

        try {
            $this->service->getToken(0.001);
            $this->fail('Expected a spent budget without an old token to throw.');
        } catch (RuntimeException $e) {
            $this->assertSame('Could not get new machine token.', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_budget_does_not_apply_to_a_cached_token()
    {
        Cache::put('test_machine_token', [
            'token' => 'cached_token',
            'expiry' => Carbon::now()->addDays(30)->toIso8601String(),
        ]);
        Http::fake();

        $this->assertSame('cached_token', $this->service->getToken(0.001));
        Http::assertNothingSent();
    }
}
