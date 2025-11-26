<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Utils;

use Mockery;
use NuiMarkets\LaravelSharedUtils\Contracts\MachineTokenServiceInterface;
use NuiMarkets\LaravelSharedUtils\RemoteRepositories\RemoteRepository;
use Swis\JsonApi\Client\ErrorCollection;
use Swis\JsonApi\Client\Interfaces\DocumentClientInterface;
use Swis\JsonApi\Client\Interfaces\DocumentInterface;

/**
 * Shared test helpers for RemoteRepository tests.
 *
 * Provides factory methods for creating mocks and test doubles to reduce
 * duplication across test files.
 */
trait RemoteRepositoryTestHelpers
{
    /**
     * Set up the default base URI configuration for tests.
     */
    protected function setUpRemoteRepositoryConfig(string $baseUri = 'https://test.example.com'): void
    {
        config(['app.remote_repository.base_uri' => $baseUri]);
    }

    /**
     * Create a mock MachineTokenServiceInterface that returns a fixed token.
     */
    protected function createMockTokenService(string $token = 'test-token'): MachineTokenServiceInterface
    {
        return new class($token) implements MachineTokenServiceInterface
        {
            public function __construct(private string $token) {}

            public function getToken(): string
            {
                return $this->token;
            }
        };
    }

    /**
     * Create a mock MachineTokenServiceInterface that throws an exception.
     */
    protected function createFailingTokenService(string $message = 'Token service unavailable'): MachineTokenServiceInterface
    {
        return new class($message) implements MachineTokenServiceInterface
        {
            public function __construct(private string $message) {}

            public function getToken(): string
            {
                throw new \Exception($this->message);
            }
        };
    }

    /**
     * Create a mock MachineTokenServiceInterface that returns an empty/whitespace token.
     */
    protected function createEmptyTokenService(): MachineTokenServiceInterface
    {
        return new class implements MachineTokenServiceInterface
        {
            public function getToken(): string
            {
                return '   ';
            }
        };
    }

    /**
     * Create a mock DocumentClientInterface with setBaseUri expectation.
     */
    protected function createMockClient(): DocumentClientInterface
    {
        $mockClient = $this->createMock(DocumentClientInterface::class);
        $mockClient->expects($this->any())
            ->method('setBaseUri')
            ->with($this->isType('string'));

        return $mockClient;
    }

    /**
     * Create a concrete RemoteRepository instance for testing.
     */
    protected function createTestRepository(?DocumentClientInterface $client = null, ?MachineTokenServiceInterface $tokenService = null): RemoteRepository
    {
        $client = $client ?? $this->createMockClient();
        $tokenService = $tokenService ?? $this->createMockTokenService();

        return new class($client, $tokenService) extends RemoteRepository
        {
            protected function filter(array $data)
            {
                return $data;
            }
        };
    }

    /**
     * Create a concrete RemoteRepository with a public method to trigger token loading.
     */
    protected function createTestRepositoryWithTokenTrigger(?DocumentClientInterface $client = null, ?MachineTokenServiceInterface $tokenService = null): RemoteRepository
    {
        $client = $client ?? $this->createMockClient();
        $tokenService = $tokenService ?? $this->createMockTokenService();

        return new class($client, $tokenService) extends RemoteRepository
        {
            protected function filter(array $data)
            {
                return $data;
            }

            public function triggerTokenLoad(): void
            {
                $this->ensureTokenLoaded();
            }
        };
    }

    /**
     * Create a concrete RemoteRepository with public access to get/post methods.
     */
    protected function createTestRepositoryWithPublicMethods(?DocumentClientInterface $client = null, ?MachineTokenServiceInterface $tokenService = null): RemoteRepository
    {
        $client = $client ?? $this->createMockClient();
        $tokenService = $tokenService ?? $this->createMockTokenService();

        return new class($client, $tokenService) extends RemoteRepository
        {
            protected function filter(array $data)
            {
                return $data;
            }

            public function publicGet(string $url)
            {
                return $this->get($url);
            }

            public function publicPost(string $url, \Swis\JsonApi\Client\Interfaces\ItemDocumentInterface $data)
            {
                return $this->post($url, $data);
            }
        };
    }

    /**
     * Create a mock DocumentInterface with errors.
     */
    protected function createErrorResponse(int $statusCode, string $body, ?ErrorCollection $errors = null): DocumentInterface
    {
        $httpResponse = Mockery::mock(\Psr\Http\Message\ResponseInterface::class);
        $httpResponse->shouldReceive('getStatusCode')->andReturn($statusCode);
        $httpResponse->shouldReceive('getBody')->andReturn(\GuzzleHttp\Psr7\Utils::streamFor($body));

        $response = Mockery::mock(DocumentInterface::class);
        $response->shouldReceive('hasErrors')->andReturn(true);
        $response->shouldReceive('getResponse')->andReturn($httpResponse);
        $response->shouldReceive('getErrors')->andReturn($errors ?? $this->createErrorCollection('Error detail'));

        return $response;
    }

    /**
     * Create a mock DocumentInterface with null HTTP response.
     */
    protected function createNullHttpResponse(): DocumentInterface
    {
        $response = Mockery::mock(DocumentInterface::class);
        $response->shouldReceive('hasErrors')->andReturn(true);
        $response->shouldReceive('getErrors')->andReturn(new ErrorCollection);
        $response->shouldReceive('getResponse')->andReturn(null);

        return $response;
    }

    /**
     * Create a mock DocumentInterface for successful responses.
     */
    protected function createSuccessResponse(): DocumentInterface
    {
        $response = Mockery::mock(DocumentInterface::class);
        $response->shouldReceive('hasErrors')->andReturn(false);

        return $response;
    }

    /**
     * Create an ErrorCollection with a single error.
     */
    protected function createErrorCollection(string $detail): ErrorCollection
    {
        $errorCollection = new ErrorCollection;
        $error = new \Swis\JsonApi\Client\Error(
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
