<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\Testing;

use Illuminate\Support\Facades\Http;
use NuiMarkets\LaravelSharedUtils\Contracts\MachineTokenServiceInterface;
use NuiMarkets\LaravelSharedUtils\Services\MachineTokenService;
use NuiMarkets\LaravelSharedUtils\Testing\MocksMachineTokenService;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;

class MocksMachineTokenServiceTest extends TestCase
{
    use MocksMachineTokenService;

    public function test_trait_binds_default_token()
    {
        $this->mockMachineTokenService();

        $this->assertEquals('fake-token', app(MachineTokenServiceInterface::class)->getToken());
    }

    public function test_trait_also_binds_concrete_class()
    {
        $this->mockMachineTokenService('concrete-token');

        $this->assertEquals('concrete-token', app(MachineTokenService::class)->getToken());
    }

    public function test_trait_binds_custom_token()
    {
        $this->mockMachineTokenService('custom-token');

        $this->assertEquals('custom-token', app(MachineTokenServiceInterface::class)->getToken());
    }

    public function test_trait_does_not_make_real_http_request()
    {
        Http::fake();

        $this->mockMachineTokenService();

        app(MachineTokenServiceInterface::class)->getToken();

        Http::assertNothingSent();
    }
}
