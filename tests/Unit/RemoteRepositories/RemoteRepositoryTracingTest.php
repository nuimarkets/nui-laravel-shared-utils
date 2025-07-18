<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\RemoteRepositories;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use NuiMarkets\LaravelSharedUtils\Contracts\MachineTokenServiceInterface;
use NuiMarkets\LaravelSharedUtils\RemoteRepositories\RemoteRepository;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;
use Swis\JsonApi\Client\Interfaces\DocumentClientInterface;
use Swis\JsonApi\Client\Interfaces\DocumentInterface;

class RemoteRepositoryTracingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Set a default base URI for tests
        config(['app.remote_repository.base_uri' => 'https://test.example.com']);
    }

    public function test_does_not_include_request_id_header_when_not_available()
    {
        $mockClient = $this->createMock(DocumentClientInterface::class);
        $mockTokenService = $this->createMockTokenService();
        
        $repository = new TestRemoteRepositoryTracing($mockClient, $mockTokenService);
        
        // Use reflection to access protected headers property
        $reflection = new \ReflectionClass(RemoteRepository::class);
        $headersProperty = $reflection->getProperty('headers');
        $headersProperty->setAccessible(true);
        $headers = $headersProperty->getValue($repository);
        
        $this->assertArrayNotHasKey('X-Request-ID', $headers);
        $this->assertArrayNotHasKey('X-Correlation-ID', $headers);
    }
    
    public function test_extracts_request_id_from_request_headers_as_fallback()
    {
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('X-Request-ID', 'fallback-request-456');
        $this->app->instance('request', $request);
        
        $mockClient = $this->createMock(DocumentClientInterface::class);
        $mockTokenService = $this->createMockTokenService();
        
        $repository = new TestRemoteRepositoryTracing($mockClient, $mockTokenService);
        
        // Use reflection to access protected headers property
        $reflection = new \ReflectionClass(RemoteRepository::class);
        $headersProperty = $reflection->getProperty('headers');
        $headersProperty->setAccessible(true);
        $headers = $headersProperty->getValue($repository);
        
        $this->assertArrayHasKey('X-Request-ID', $headers);
        $this->assertEquals('fallback-request-456', $headers['X-Request-ID']);
    }
    
    public function test_propagates_full_xray_trace_header_for_distributed_tracing()
    {
        $fullTraceHeader = 'Root=1-67a92466-4b6aa15a05ffcd4c510de968;Parent=53995c3f42cd8ad8;Sampled=1';
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('X-Amzn-Trace-Id', $fullTraceHeader);
        $this->app->instance('request', $request);
        
        $mockClient = $this->createMock(DocumentClientInterface::class);
        $mockTokenService = $this->createMockTokenService();
        
        $repository = new TestRemoteRepositoryTracing($mockClient, $mockTokenService);
        
        // Use reflection to access protected headers property
        $reflection = new \ReflectionClass(RemoteRepository::class);
        $headersProperty = $reflection->getProperty('headers');
        $headersProperty->setAccessible(true);
        $headers = $headersProperty->getValue($repository);
        
        // Should propagate full X-Ray header for AWS trace continuity
        $this->assertArrayHasKey('X-Amzn-Trace-Id', $headers);
        $this->assertEquals($fullTraceHeader, $headers['X-Amzn-Trace-Id']);
        
        // Should also set correlation ID with extracted trace ID
        $this->assertArrayHasKey('X-Correlation-ID', $headers);
        $this->assertEquals('1-67a92466-4b6aa15a05ffcd4c510de968', $headers['X-Correlation-ID']);
    }
    
    public function test_handles_malformed_trace_id_in_request_headers()
    {
        $malformedHeader = 'MalformedTraceId';
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('X-Amzn-Trace-Id', $malformedHeader);
        $this->app->instance('request', $request);
        
        $mockClient = $this->createMock(DocumentClientInterface::class);
        $mockTokenService = $this->createMockTokenService();
        
        $repository = new TestRemoteRepositoryTracing($mockClient, $mockTokenService);
        
        // Use reflection to access protected headers property
        $reflection = new \ReflectionClass(RemoteRepository::class);
        $headersProperty = $reflection->getProperty('headers');
        $headersProperty->setAccessible(true);
        $headers = $headersProperty->getValue($repository);
        
        // Should propagate malformed header as-is for X-Ray
        $this->assertArrayHasKey('X-Amzn-Trace-Id', $headers);
        $this->assertEquals($malformedHeader, $headers['X-Amzn-Trace-Id']);
        
        // Should also set correlation ID (malformed headers become fallback values)
        $this->assertArrayHasKey('X-Correlation-ID', $headers);
        $this->assertEquals($malformedHeader, $headers['X-Correlation-ID']);
    }
    
    protected function createMockTokenService(): MachineTokenServiceInterface
    {
        $mock = $this->createMock(MachineTokenServiceInterface::class);
        $mock->method('getToken')->willReturn('test-token-123');
        return $mock;
    }
}

/**
 * Concrete implementation for testing RemoteRepository tracing functionality
 */
class TestRemoteRepositoryTracing extends RemoteRepository
{
    protected function filter(array $data)
    {
        // Simple implementation for testing
        return $data;
    }
}