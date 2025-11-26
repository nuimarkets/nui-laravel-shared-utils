<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\RemoteRepositories;

use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Log;
use Mockery;
use NuiMarkets\LaravelSharedUtils\Exceptions\RemoteServiceException;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;
use Psr\Http\Message\ResponseInterface;
use Swis\JsonApi\Client\Interfaces\DocumentInterface;

/**
 * Tests for HTTP status code preservation in RemoteRepository.
 *
 * These tests verify that RemoteRepository preserves the original HTTP status
 * codes from remote services instead of wrapping all errors as 502.
 */
class RemoteRepositoryStatusCodeTest extends TestCase
{
    use RemoteRepositoryTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRemoteRepositoryConfig();
    }

    // ========================================================================
    // handleResponse() Status Code Preservation Tests
    // ========================================================================

    public function test_handle_response_preserves_404_status_code(): void
    {
        Log::shouldReceive('error')->once();

        $repository = $this->createTestRepository();
        $response = $this->createErrorResponse(404, '{"errors": [{"detail": "Not found"}]}');

        try {
            $repository->handleResponse($response);
            $this->fail('Expected RemoteServiceException');
        } catch (RemoteServiceException $e) {
            $this->assertEquals(404, $e->getStatusCode());
            $this->assertStringContainsString('Not found', $e->getMessage());
        }
    }

    public function test_handle_response_preserves_500_status_code(): void
    {
        Log::shouldReceive('error')->once();

        $repository = $this->createTestRepository();
        $response = $this->createErrorResponse(500, '{"errors": [{"detail": "Internal server error"}]}');

        try {
            $repository->handleResponse($response);
            $this->fail('Expected RemoteServiceException');
        } catch (RemoteServiceException $e) {
            $this->assertEquals(500, $e->getStatusCode());
        }
    }

    public function test_handle_response_preserves_503_status_code(): void
    {
        Log::shouldReceive('error')->once();

        $repository = $this->createTestRepository();
        $response = $this->createErrorResponse(503, '{"errors": [{"detail": "Service unavailable"}]}');

        try {
            $repository->handleResponse($response);
            $this->fail('Expected RemoteServiceException');
        } catch (RemoteServiceException $e) {
            $this->assertEquals(503, $e->getStatusCode());
        }
    }

    public function test_handle_response_preserves_400_status_code(): void
    {
        Log::shouldReceive('error')->once();

        $repository = $this->createTestRepository();
        $response = $this->createErrorResponse(400, '{"errors": [{"detail": "Bad request"}]}');

        try {
            $repository->handleResponse($response);
            $this->fail('Expected RemoteServiceException');
        } catch (RemoteServiceException $e) {
            $this->assertEquals(400, $e->getStatusCode());
        }
    }

    public function test_handle_response_preserves_401_status_code(): void
    {
        Log::shouldReceive('error')->once();

        $repository = $this->createTestRepository();
        $response = $this->createErrorResponse(401, '{"errors": [{"detail": "Unauthorized"}]}');

        try {
            $repository->handleResponse($response);
            $this->fail('Expected RemoteServiceException');
        } catch (RemoteServiceException $e) {
            $this->assertEquals(401, $e->getStatusCode());
        }
    }

    public function test_handle_response_preserves_403_status_code(): void
    {
        Log::shouldReceive('error')->once();

        $repository = $this->createTestRepository();
        $response = $this->createErrorResponse(403, '{"errors": [{"detail": "Forbidden"}]}');

        try {
            $repository->handleResponse($response);
            $this->fail('Expected RemoteServiceException');
        } catch (RemoteServiceException $e) {
            $this->assertEquals(403, $e->getStatusCode());
        }
    }

    public function test_handle_response_preserves_429_status_code(): void
    {
        Log::shouldReceive('error')->once();

        $repository = $this->createTestRepository();
        $response = $this->createErrorResponse(429, '{"errors": [{"detail": "Too many requests"}]}');

        try {
            $repository->handleResponse($response);
            $this->fail('Expected RemoteServiceException');
        } catch (RemoteServiceException $e) {
            $this->assertEquals(429, $e->getStatusCode());
        }
    }

    // ========================================================================
    // Edge Case Tests
    // ========================================================================

    public function test_handle_response_uses_502_for_null_http_response(): void
    {
        Log::shouldReceive('error')->once();

        $repository = $this->createTestRepository();
        $response = $this->createNullHttpResponse();

        try {
            $repository->handleResponse($response);
            $this->fail('Expected RemoteServiceException');
        } catch (RemoteServiceException $e) {
            $this->assertEquals(502, $e->getStatusCode());
            $this->assertStringContainsString('No response available', $e->getMessage());
        }
    }

    public function test_handle_response_uses_502_for_2xx_status_with_errors(): void
    {
        Log::shouldReceive('warning')->once();
        Log::shouldReceive('error')->once();

        $repository = $this->createTestRepository();
        $response = $this->createErrorResponse(200, '{"errors": [{"detail": "Weird error"}]}');

        try {
            $repository->handleResponse($response);
            $this->fail('Expected RemoteServiceException');
        } catch (RemoteServiceException $e) {
            // 2xx with errors is invalid - should use 502
            $this->assertEquals(502, $e->getStatusCode());
        }
    }

    public function test_handle_response_uses_502_for_invalid_status_code(): void
    {
        Log::shouldReceive('error')->once();

        $repository = $this->createTestRepository();

        // Create response with invalid status code (999)
        $httpResponse = Mockery::mock(ResponseInterface::class);
        $httpResponse->shouldReceive('getStatusCode')->andReturn(999);
        $httpResponse->shouldReceive('getBody')->andReturn(Utils::streamFor('{"errors": [{"detail": "Error"}]}'));

        $response = Mockery::mock(DocumentInterface::class);
        $response->shouldReceive('hasErrors')->andReturn(true);
        $response->shouldReceive('getResponse')->andReturn($httpResponse);
        $response->shouldReceive('getErrors')->andReturn($this->createErrorCollection('Error'));

        try {
            $repository->handleResponse($response);
            $this->fail('Expected RemoteServiceException');
        } catch (RemoteServiceException $e) {
            $this->assertEquals(502, $e->getStatusCode());
        }
    }

    public function test_handle_response_logs_status_code(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'Remote service error'
                    && isset($context['api.status'])
                    && $context['api.status'] === 404;
            });

        $repository = $this->createTestRepository();
        $response = $this->createErrorResponse(404, '{"errors": [{"detail": "Not found"}]}');

        try {
            $repository->handleResponse($response);
        } catch (RemoteServiceException $e) {
            // Expected
        }
    }

    public function test_handle_response_does_not_include_endpoint_in_log(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->withArgs(function ($message, $context) {
                // handleResponse() should NOT include api.endpoint since it doesn't know the URL
                return $message === 'Remote service error'
                    && ! isset($context['api.endpoint']);
            });

        $repository = $this->createTestRepository();
        $response = $this->createErrorResponse(404, '{"errors": [{"detail": "Not found"}]}');

        try {
            $repository->handleResponse($response);
        } catch (RemoteServiceException $e) {
            // Expected
        }
    }

    // ========================================================================
    // handleApiErrors() / get() Endpoint Logging Tests
    // ========================================================================

    public function test_get_includes_endpoint_in_error_log(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'Remote service error'
                    && isset($context['api.endpoint'])
                    && $context['api.endpoint'] === '/test/endpoint';
            });

        $mockClient = $this->createMockClient();
        $mockClient->expects($this->once())
            ->method('get')
            ->with('/test/endpoint', $this->anything())
            ->willReturn($this->createErrorResponse(404, '{"errors": [{"detail": "Not found"}]}'));

        $repository = $this->createTestRepository($mockClient);

        try {
            $repository->get('/test/endpoint');
            $this->fail('Expected RemoteServiceException');
        } catch (RemoteServiceException $e) {
            // get() wraps all exceptions with 503 after retry exhaustion
            $this->assertEquals(503, $e->getStatusCode());
        }
    }

    public function test_get_includes_endpoint_in_null_response_error_log(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'Remote service error: No HTTP response available'
                    && isset($context['api.endpoint'])
                    && $context['api.endpoint'] === '/test/null-response';
            });

        $mockClient = $this->createMockClient();
        $mockClient->expects($this->once())
            ->method('get')
            ->willReturn($this->createNullHttpResponse());

        $repository = $this->createTestRepository($mockClient);

        try {
            $repository->get('/test/null-response');
            $this->fail('Expected RemoteServiceException');
        } catch (RemoteServiceException $e) {
            // get() wraps all exceptions with 503 after retry exhaustion
            $this->assertEquals(503, $e->getStatusCode());
        }
    }

    public function test_handle_response_null_response_does_not_include_endpoint(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'Remote service error: No HTTP response available'
                    && ! isset($context['api.endpoint']);
            });

        $repository = $this->createTestRepository();
        $response = $this->createNullHttpResponse();

        try {
            $repository->handleResponse($response);
            $this->fail('Expected RemoteServiceException');
        } catch (RemoteServiceException $e) {
            $this->assertEquals(502, $e->getStatusCode());
        }
    }
}
