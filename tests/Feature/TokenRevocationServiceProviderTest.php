<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Feature;

use NuiMarkets\LaravelSharedUtils\Auth\TokenRevocationGuard;
use NuiMarkets\LaravelSharedUtils\Auth\TokenStatusClient;
use NuiMarkets\LaravelSharedUtils\Providers\TokenRevocationServiceProvider;
use NuiMarkets\LaravelSharedUtils\Services\MachineTokenService;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;

class TokenRevocationServiceProviderTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [
            TokenRevocationServiceProvider::class,
        ];
    }

    public function test_provider_merges_default_config()
    {
        $this->assertNull(config('token_revocation.url'));
        $this->assertSame('token_status', config('token_revocation.store'));
        $this->assertSame(30, config('token_revocation.active_ttl'));
        $this->assertSame(60, config('token_revocation.revoked_grace'));
        $this->assertSame(1.0, config('token_revocation.connect_timeout'));
        $this->assertSame(2.0, config('token_revocation.timeout'));
        $this->assertSame(10, config('token_revocation.breaker_seconds'));
        $this->assertSame('off', config('token_revocation.mode'));
        $this->assertSame('open', config('token_revocation.fail'));
        $this->assertSame(0.01, config('token_revocation.sample_rate'));
    }

    public function test_provider_binds_singletons_on_the_shared_machine_token_service()
    {
        $this->assertSame(app(TokenRevocationGuard::class), app(TokenRevocationGuard::class));
        $this->assertSame(app(TokenStatusClient::class), app(TokenStatusClient::class));
        $this->assertSame(app(MachineTokenService::class), app(MachineTokenService::class));
        $this->assertSame('machine_token', config('machine_token.redis_key'));
    }

    public function test_default_guard_is_off()
    {
        $this->assertFalse(app(TokenRevocationGuard::class)->shouldReject('jti', time() + 60, []));
    }
}
