<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\RemoteRepositories;

use NuiMarkets\LaravelSharedUtils\Contracts\MachineTokenServiceInterface;
use NuiMarkets\LaravelSharedUtils\Support\SimpleDocument;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;
use NuiMarkets\LaravelSharedUtils\Tests\Utils\RemoteRepositoryTestHelpers;
use Swis\JsonApi\Client\Item;

class RemoteRepositoryLazyLoadingTest extends TestCase
{
    use RemoteRepositoryTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRemoteRepositoryConfig();
    }

    public function test_token_not_loaded_on_construction()
    {
        $mockClient = $this->createMockClient();

        $mockMachineTokenService = $this->createMock(MachineTokenServiceInterface::class);
        $mockMachineTokenService->expects($this->never())
            ->method('getToken');

        // Create repository - token should NOT be loaded yet
        $this->createTestRepository($mockClient, $mockMachineTokenService);

        // Test passes if getToken() was never called during construction
    }

    public function test_token_loaded_on_first_get_request()
    {
        $mockClient = $this->createMockClient();
        $mockClient->expects($this->once())
            ->method('get')
            ->willReturn($this->createSuccessResponse());

        $mockMachineTokenService = $this->createMock(MachineTokenServiceInterface::class);
        $mockMachineTokenService->expects($this->once())
            ->method('getToken')
            ->willReturn('test-token');

        $repository = $this->createTestRepositoryWithPublicMethods($mockClient, $mockMachineTokenService);

        // Make first request - token should be loaded exactly once
        $repository->publicGet('/test');
    }

    public function test_token_loaded_on_first_post_request()
    {
        $mockClient = $this->createMockClient();
        $mockClient->expects($this->once())
            ->method('post')
            ->willReturn($this->createSuccessResponse());

        $mockMachineTokenService = $this->createMock(MachineTokenServiceInterface::class);
        $mockMachineTokenService->expects($this->once())
            ->method('getToken')
            ->willReturn('test-token');

        $repository = $this->createTestRepositoryWithPublicMethods($mockClient, $mockMachineTokenService);

        // Create mock document
        Item::unguard();
        $item = new Item(['test' => 'data']);
        $item->setType('array');
        $document = new SimpleDocument;
        $document->setData($item);
        Item::reguard();

        // Make first request - token should be loaded exactly once
        $repository->publicPost('/test', $document);
    }

    public function test_token_loaded_only_once_for_multiple_requests()
    {
        $mockClient = $this->createMockClient();
        $mockClient->expects($this->exactly(3))
            ->method('get')
            ->willReturn($this->createSuccessResponse());

        $mockMachineTokenService = $this->createMock(MachineTokenServiceInterface::class);
        $mockMachineTokenService->expects($this->once()) // Should only be called once
            ->method('getToken')
            ->willReturn('test-token');

        $repository = $this->createTestRepositoryWithPublicMethods($mockClient, $mockMachineTokenService);

        // Make multiple requests - token should only be loaded once
        $repository->publicGet('/test1');
        $repository->publicGet('/test2');
        $repository->publicGet('/test3');
    }

    public function test_authorization_header_set_on_first_request()
    {
        $mockClient = $this->createMockClient();
        $successResponse = $this->createSuccessResponse();

        $capturedHeaders = null;
        $mockClient->expects($this->once())
            ->method('get')
            ->willReturnCallback(function ($url, $headers) use (&$capturedHeaders, $successResponse) {
                $capturedHeaders = $headers;

                return $successResponse;
            });

        $tokenService = $this->createMockTokenService('lazy-loaded-token');
        $repository = $this->createTestRepositoryWithPublicMethods($mockClient, $tokenService);

        // Make request
        $repository->publicGet('/test');

        // Verify Authorization header was set with the token
        $this->assertArrayHasKey('Authorization', $capturedHeaders);
        $this->assertEquals('Bearer lazy-loaded-token', $capturedHeaders['Authorization']);
    }

    public function test_lazy_loading_preserves_trace_headers()
    {
        $mockClient = $this->createMockClient();
        $successResponse = $this->createSuccessResponse();

        $capturedHeaders = null;
        $mockClient->expects($this->once())
            ->method('get')
            ->willReturnCallback(function ($url, $headers) use (&$capturedHeaders, $successResponse) {
                $capturedHeaders = $headers;

                return $successResponse;
            });

        // Set up request with trace headers
        $this->app['request']->headers->set('X-Amzn-Trace-Id', 'Root=1-67890-abc123');
        $this->app['request']->headers->set('X-Request-ID', 'req-12345');

        $repository = $this->createTestRepositoryWithPublicMethods($mockClient);

        // Make request
        $repository->publicGet('/test');

        // Verify trace headers were preserved
        $this->assertArrayHasKey('X-Amzn-Trace-Id', $capturedHeaders);
        $this->assertEquals('Root=1-67890-abc123', $capturedHeaders['X-Amzn-Trace-Id']);
        $this->assertArrayHasKey('X-Request-ID', $capturedHeaders);
        $this->assertEquals('req-12345', $capturedHeaders['X-Request-ID']);
    }

    public function test_repository_can_be_instantiated_without_immediate_token_service_call()
    {
        $mockClient = $this->createMockClient();

        $mockMachineTokenService = $this->createMock(MachineTokenServiceInterface::class);
        $mockMachineTokenService->expects($this->never())
            ->method('getToken');

        // Create repository
        $repository = $this->createTestRepository($mockClient, $mockMachineTokenService);

        // Use repository for non-request operations - token should NOT be loaded
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $repository->query());
    }
}
