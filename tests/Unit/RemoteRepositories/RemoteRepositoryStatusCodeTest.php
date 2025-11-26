<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\RemoteRepositories;

use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Log;
use Mockery;
use NuiMarkets\LaravelSharedUtils\Contracts\MachineTokenServiceInterface;
use NuiMarkets\LaravelSharedUtils\Exceptions\RemoteServiceException;
use NuiMarkets\LaravelSharedUtils\RemoteRepositories\RemoteRepository;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;
use Psr\Http\Message\ResponseInterface;
use Swis\JsonApi\Client\Error as JsonApiError;
use Swis\JsonApi\Client\ErrorCollection;
use Swis\JsonApi\Client\Interfaces\DocumentClientInterface;
use Swis\JsonApi\Client\Interfaces\DocumentInterface;

/**
 * Tests for HTTP status code preservation in RemoteRepository.
 *
 * These tests verify that RemoteRepository preserves the original HTTP status
 * codes from remote services instead of wrapping all errors as 502.
 */
class RemoteRepositoryStatusCodeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.remote_repository.base_uri' => 'https://test.example.com']);
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

        $response = Mockery::mock(DocumentInterface::class);
        $response->shouldReceive('hasErrors')->andReturn(true);
        $response->shouldReceive('getResponse')->andReturn(null);

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

    // ========================================================================
    // Helper Methods
    // ========================================================================

    private function createTestRepository(): RemoteRepository
    {
        $mockClient = $this->createMock(DocumentClientInterface::class);
        $mockClient->expects($this->any())
            ->method('setBaseUri')
            ->with($this->isType('string'));

        $mockMachineTokenService = new class implements MachineTokenServiceInterface
        {
            public function getToken(): string
            {
                return 'test-token';
            }
        };

        return new class($mockClient, $mockMachineTokenService) extends RemoteRepository
        {
            protected function filter(array $data)
            {
                return $data;
            }
        };
    }

    private function createErrorResponse(int $statusCode, string $body): DocumentInterface
    {
        $httpResponse = Mockery::mock(ResponseInterface::class);
        $httpResponse->shouldReceive('getStatusCode')->andReturn($statusCode);
        $httpResponse->shouldReceive('getBody')->andReturn(Utils::streamFor($body));

        $response = Mockery::mock(DocumentInterface::class);
        $response->shouldReceive('hasErrors')->andReturn(true);
        $response->shouldReceive('getResponse')->andReturn($httpResponse);
        $response->shouldReceive('getErrors')->andReturn($this->createErrorCollection('Error detail'));

        return $response;
    }

    private function createErrorCollection(string $detail): ErrorCollection
    {
        $errorCollection = new ErrorCollection;
        $error = new JsonApiError(
            null, // id
            null, // links
            null, // status
            null, // code
            null, // title
            $detail, // detail
            null, // source
            null  // meta
        );
        $errorCollection->push($error);

        return $errorCollection;
    }
}
