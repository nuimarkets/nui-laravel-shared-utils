<?php

namespace NuiMarkets\LaravelSharedUtils\Testing;

use Mockery;
use Mockery\MockInterface;
use NuiMarkets\LaravelSharedUtils\Contracts\MachineTokenServiceInterface;
use NuiMarkets\LaravelSharedUtils\Services\MachineTokenService;

trait MocksMachineTokenService
{
    protected function mockMachineTokenService(string $token = 'fake-token'): MockInterface
    {
        $mockMachineTokenService = Mockery::mock(MachineTokenService::class);
        $mockMachineTokenService->shouldReceive('getToken')->andReturn($token)->byDefault();

        // Bind to both the interface and the concrete so consumers that resolve
        // either get the mock. The shared provider exposes both bindings.
        app()->instance(MachineTokenServiceInterface::class, $mockMachineTokenService);
        app()->instance(MachineTokenService::class, $mockMachineTokenService);

        return $mockMachineTokenService;
    }
}
