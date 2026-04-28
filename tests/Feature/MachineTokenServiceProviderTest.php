<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Feature;

use NuiMarkets\LaravelSharedUtils\Contracts\MachineTokenServiceInterface;
use NuiMarkets\LaravelSharedUtils\Providers\MachineTokenServiceProvider;
use NuiMarkets\LaravelSharedUtils\Services\MachineTokenService;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;

class MachineTokenServiceProviderTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [
            MachineTokenServiceProvider::class,
        ];
    }

    public function test_provider_merges_default_config()
    {
        $this->assertEquals('machine_token', config('machine_token.redis_key'));
        $this->assertEquals(7 * 24 * 3600, config('machine_token.time_before_expire'));
    }

    public function test_provider_binds_interface_to_singleton_service()
    {
        $first = app(MachineTokenServiceInterface::class);
        $second = app(MachineTokenServiceInterface::class);

        $this->assertInstanceOf(MachineTokenService::class, $first);
        $this->assertSame($first, $second);
        $this->assertSame(app(MachineTokenService::class), $first);
    }
}
