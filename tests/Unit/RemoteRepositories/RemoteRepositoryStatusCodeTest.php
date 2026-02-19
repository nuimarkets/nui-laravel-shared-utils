<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\RemoteRepositories;

use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Log;
use Mockery;
use NuiMarkets\LaravelSharedUtils\Exceptions\RemoteServiceException;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;
use NuiMarkets\LaravelSharedUtils\Tests\Utils\RemoteRepositoryTestHelpers;
use Psr\Http\Message\ResponseInterface;
use Swis\JsonApi\Client\Interfaces\DocumentInterface;

/**
 * Tests for HTTP status code preservation in RemoteRepository.
 *
 * These tests verify that RemoteRepository preserves the original HTTP status
 * codes from remote services instead of wrapping all errors as 502.
 *
 * Note: throwRemoteServiceError() no longer logs directly — it creates a
 * RemoteServiceException with structured context via fromRemoteResponse().
 * Logging responsibility is with the caller or error handler.
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
        $repository = $this->createTestRepository();
        $response = $this->createErrorResponse(404, '{"errors": [{"detail": "Not found"}]}', $this->createErrorCollection('Not found'));

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

    // ========================================================================
    // Exception Context Tests (replaces Log::error assertions)
    // ========================================================================

    public function test_handle_response_exception_carries_status_code_in_extra(): void
    {
        $repository = $this->createTestRepository();
        $response = $this->createErrorResponse(404, '{"errors": [{"detail": "Not found"}]}');

        try {
            $repository->handleResponse($response);
            $this->fail('Expected RemoteServiceException');
        } catch (RemoteServiceException $e) {
            $extra = $e->getExtra();
            $this->assertEquals(404, $extra['api.status']);
        }
    }

    public function test_handle_response_exception_does_not_include_endpoint_when_called_directly(): void
    {
        $repository = $this->createTestRepository();
        $response = $this->createErrorResponse(404, '{"errors": [{"detail": "Not found"}]}');

        try {
            $repository->handleResponse($response);
            $this->fail('Expected RemoteServiceException');
        } catch (RemoteServiceException $e) {
            // handleResponse() passes null endpoint, which becomes 'unknown'
            $extra = $e->getExtra();
            $this->assertEquals('unknown', $extra['api.endpoint']);
        }
    }

    // ========================================================================
    // get() Endpoint Context Tests
    // ========================================================================

    public function test_get_propagates_4xx_directly_without_wrapping(): void
    {
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
            // 4xx propagates directly — not wrapped as 503
            $this->assertEquals(404, $e->getStatusCode());
            $this->assertNull($e->getPrevious());
            $this->assertEquals('/test/endpoint', $e->getExtra()['api.endpoint']);
        }
    }

    public function test_get_does_not_retry_4xx_errors(): void
    {
        $mockClient = $this->createMockClient();
        // Should only be called once — 422 should not be retried
        $mockClient->expects($this->once())
            ->method('get')
            ->willReturn($this->createErrorResponse(422, '{"errors": [{"detail": "Validation failed"}]}'));

        $repository = $this->createTestRepository($mockClient);

        try {
            $repository->get('/test/validation');
            $this->fail('Expected RemoteServiceException');
        } catch (RemoteServiceException $e) {
            $this->assertEquals(422, $e->getStatusCode());
        }
    }

    public function test_get_preserves_5xx_status_after_retry_exhaustion(): void
    {
        $mockClient = $this->createMockClient();
        $mockClient->expects($this->once())
            ->method('get')
            ->willReturn($this->createNullHttpResponse());

        $repository = $this->createTestRepository($mockClient);

        try {
            $repository->get('/test/null-response');
            $this->fail('Expected RemoteServiceException');
        } catch (RemoteServiceException $e) {
            // Null response gets 502 from throwRemoteServiceError, propagated directly
            $this->assertEquals(502, $e->getStatusCode());
            $this->assertEquals('/test/null-response', $e->getExtra()['api.endpoint']);
        }
    }

    public function test_handle_response_null_response_uses_unknown_endpoint(): void
    {
        $repository = $this->createTestRepository();
        $response = $this->createNullHttpResponse();

        try {
            $repository->handleResponse($response);
            $this->fail('Expected RemoteServiceException');
        } catch (RemoteServiceException $e) {
            $this->assertEquals(502, $e->getStatusCode());
            $this->assertEquals('unknown', $e->getExtra()['api.endpoint']);
        }
    }

    // ========================================================================
    // Factory Method Integration Tests
    // ========================================================================

    public function test_exception_from_handle_response_has_service_name(): void
    {
        $repository = $this->createTestRepository();
        $response = $this->createErrorResponse(400, '{"errors": [{"detail": "Bad request"}]}');

        try {
            $repository->handleResponse($response);
            $this->fail('Expected RemoteServiceException');
        } catch (RemoteServiceException $e) {
            // The anonymous test class name is used as service name
            $this->assertNotEmpty($e->getRemoteService());
            $this->assertNotEmpty($e->getExtra()['api.service']);
        }
    }

    public function test_exception_from_handle_response_has_error_details(): void
    {
        $repository = $this->createTestRepository();
        $response = $this->createErrorResponse(400, '{"errors": [{"detail": "Bad request"}]}');

        try {
            $repository->handleResponse($response);
            $this->fail('Expected RemoteServiceException');
        } catch (RemoteServiceException $e) {
            $this->assertNotEmpty($e->getRemoteErrors());
            $this->assertStringContainsString('Remote service error (400)', $e->getMessage());
        }
    }

    public function test_exception_message_is_clean_not_raw_json(): void
    {
        $repository = $this->createTestRepository();
        $response = $this->createErrorResponse(400, '{"meta":{"message":"There was a problem"},"errors":[{"detail":"No address found"}]}');

        try {
            $repository->handleResponse($response);
            $this->fail('Expected RemoteServiceException');
        } catch (RemoteServiceException $e) {
            // Message should NOT contain raw JSON
            $this->assertStringNotContainsString('{"meta":', $e->getMessage());
            $this->assertStringNotContainsString('Returned:', $e->getMessage());
            // Should be a clean, structured message
            $this->assertStringContainsString('Remote service error (400)', $e->getMessage());
        }
    }
}
