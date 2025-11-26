<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\RemoteRepositories;

use NuiMarkets\LaravelSharedUtils\Contracts\MachineTokenServiceInterface;
use NuiMarkets\LaravelSharedUtils\RemoteRepositories\RemoteRepository;
use NuiMarkets\LaravelSharedUtils\Support\SimpleDocument;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;
use NuiMarkets\LaravelSharedUtils\Tests\Utils\RemoteRepositoryTestHelpers;
use Swis\JsonApi\Client\Interfaces\DocumentClientInterface;
use Swis\JsonApi\Client\Interfaces\ItemInterface;
use Swis\JsonApi\Client\Item;

class RemoteRepositoryBasicTest extends TestCase
{
    use RemoteRepositoryTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRemoteRepositoryConfig();
    }

    public function test_remote_repository_class_exists()
    {
        $this->assertTrue(class_exists(RemoteRepository::class));
    }

    public function test_remote_repository_is_abstract()
    {
        $reflection = new \ReflectionClass(RemoteRepository::class);
        $this->assertTrue($reflection->isAbstract());
    }

    public function test_remote_repository_has_required_methods()
    {
        $reflection = new \ReflectionClass(RemoteRepository::class);

        $requiredMethods = [
            'get',
            'getUserUrl',
            'post',
            'cache',
            'cacheOne',
            'findById',
            'findByIds',
            'makeRequestBody',
            'allowedGetRequest',
            'hasId',
            'handleResponse',
            'query',
        ];

        foreach ($requiredMethods as $method) {
            $this->assertTrue($reflection->hasMethod($method), "Method '$method' not found");
        }
    }

    public function test_uses_profiling_trait()
    {
        $reflection = new \ReflectionClass(RemoteRepository::class);
        $traits = $reflection->getTraitNames();

        $this->assertContains('NuiMarkets\LaravelSharedUtils\Support\ProfilingTrait', $traits);
    }

    public function test_make_request_body_creates_simple_document()
    {
        $testData = ['name' => 'Test Product', 'price' => 100];

        $repository = $this->createTestRepository();
        $result = $repository->makeRequestBody($testData);

        $this->assertInstanceOf(SimpleDocument::class, $result);
        $this->assertEquals('array', $result->getData()->getType());
    }

    public function test_make_request_body_handles_object_data()
    {
        $testData = (object) ['name' => 'Test Product', 'price' => 100];

        $repository = $this->createTestRepository();
        $result = $repository->makeRequestBody($testData);

        $this->assertInstanceOf(SimpleDocument::class, $result);
        $this->assertEquals('array', $result->getData()->getType());
    }

    public function test_make_request_body_throws_exception_for_invalid_data_type()
    {
        $repository = $this->createTestRepository();

        $this->expectException(\NuiMarkets\LaravelSharedUtils\Exceptions\RemoteServiceException::class);
        $this->expectExceptionMessage('Failed to create request body: Data must be an array or object, string given');

        $repository->makeRequestBody('invalid string data');
    }

    public function test_make_request_body_throws_exception_for_null_data()
    {
        $repository = $this->createTestRepository();

        $this->expectException(\NuiMarkets\LaravelSharedUtils\Exceptions\RemoteServiceException::class);
        $this->expectExceptionMessage('Failed to create request body: Data must be an array or object, NULL given');

        $repository->makeRequestBody(null);
    }

    public function test_make_request_body_reguards_on_exception()
    {
        // Create a test repository that can verify guard state
        $repository = new class($this->createMockClient(), $this->createMockTokenService()) extends RemoteRepository
        {
            protected function filter(array $data)
            {
                return $data;
            }

            public function isItemUnguarded(): bool
            {
                // Access the protected static property via reflection
                $reflection = new \ReflectionClass(Item::class);
                $property = $reflection->getProperty('unguarded');
                $property->setAccessible(true);

                return $property->getValue();
            }
        };

        // Verify Item is guarded before the call
        $this->assertFalse($repository->isItemUnguarded());

        try {
            // This should throw an exception
            $repository->makeRequestBody('invalid data');
        } catch (\Exception $e) {
            // Expected exception
        }

        // Verify Item is still guarded after the exception
        $this->assertFalse($repository->isItemUnguarded());
    }

    public function test_has_id_method_works()
    {
        $repository = $this->createTestRepository();

        // Add an item to the internal collection
        $mockItem = $this->createMock(ItemInterface::class);
        $mockItem->method('getId')->willReturn('test-id');
        $repository->query()->put('test-id', $mockItem);

        $this->assertTrue($repository->hasId('test-id'));
        $this->assertFalse($repository->hasId('non-existent-id'));
    }

    public function test_query_returns_collection()
    {
        $repository = $this->createTestRepository();
        $collection = $repository->query();

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $collection);
    }

    public function test_find_by_id_without_retrieve_returns_cached_item()
    {
        $repository = $this->createTestRepository();

        $mockItem = $this->createMock(ItemInterface::class);
        $repository->query()->put('cached-id', $mockItem);

        $result = $repository->findByIdWithoutRetrieve('cached-id');

        $this->assertSame($mockItem, $result);
    }

    public function test_allowed_get_request_validates_url_length()
    {
        $mockClient = $this->createMockClient();
        $mockClient->method('getBaseUri')->willReturn('https://api.example.com');

        $repository = $this->createTestRepository($mockClient);

        // Test short URL (should be allowed)
        $this->assertTrue($repository->allowedGetRequest('/short'));

        // Test very long URL (should be rejected) - make it longer than 2048 chars
        $longUrl = '/api/'.str_repeat('very-long-segment-that-exceeds-url-limits/', 200);
        $this->assertFalse($repository->allowedGetRequest($longUrl));
    }

    public function test_allowed_get_request_validates_malicious_patterns()
    {
        $mockClient = $this->createMockClient();
        $mockClient->method('getBaseUri')->willReturn('https://api.example.com');

        $repository = $this->createTestRepository($mockClient);

        // Test directory traversal attempts
        $this->assertFalse($repository->allowedGetRequest('/api/../../../etc/passwd'));
        $this->assertFalse($repository->allowedGetRequest('/api/..\\..\\windows\\system32'));
        $this->assertFalse($repository->allowedGetRequest('/api/%2e%2e%2f%2e%2e%2f'));
        $this->assertFalse($repository->allowedGetRequest('/api/..%2f..%2f'));

        // Test null byte injection
        $this->assertFalse($repository->allowedGetRequest("/api/file.php\0.txt"));

        // Test script injection attempts
        $this->assertFalse($repository->allowedGetRequest('/api/<script>alert(1)</script>'));
        $this->assertFalse($repository->allowedGetRequest('/api/javascript:alert(1)'));
        $this->assertFalse($repository->allowedGetRequest('/api/data:text/html,<script>alert(1)</script>'));

        // Test double encoding
        $this->assertFalse($repository->allowedGetRequest('/api/%252e%252e%252f'));

        // Test empty and whitespace paths
        $this->assertFalse($repository->allowedGetRequest(''));
        $this->assertFalse($repository->allowedGetRequest('   '));

        // Test consecutive slashes (not at protocol start)
        $this->assertFalse($repository->allowedGetRequest('/api//users'));

        // Test valid paths that should pass
        $this->assertTrue($repository->allowedGetRequest('/api/users'));
        $this->assertTrue($repository->allowedGetRequest('/api/users/123'));
        $this->assertTrue($repository->allowedGetRequest('/api/users?page=1&limit=10'));
        $this->assertTrue($repository->allowedGetRequest('/api/users/search?q=test%20user'));
        $this->assertTrue($repository->allowedGetRequest('/api/v1/users.json'));
        $this->assertTrue($repository->allowedGetRequest('/api/users/[id]'));
    }

    public function test_is_valid_url_path_method_exists()
    {
        $repository = new class($this->createMockClient(), $this->createMockTokenService()) extends RemoteRepository
        {
            protected function filter(array $data)
            {
                return $data;
            }

            public function test_is_valid_url_path(string $path): bool
            {
                return $this->isValidUrlPath($path);
            }
        };

        // Test the method exists and is callable
        $this->assertTrue(method_exists($repository, 'test_is_valid_url_path'));
    }

    public function test_configuration_fallback_methods_exist()
    {
        $reflection = new \ReflectionClass(RemoteRepository::class);

        $this->assertTrue($reflection->hasMethod('getConfiguredBaseUri'));
        $this->assertTrue($reflection->hasMethod('getBaseUrlLength'));
    }

    public function test_is_recoverable_error_method_exists()
    {
        $reflection = new \ReflectionClass(RemoteRepository::class);
        $this->assertTrue($reflection->hasMethod('isRecoverableError'));
    }

    public function test_recoverable_error_patterns_can_be_configured()
    {
        $repository = new class($this->createMockClient(), $this->createMockTokenService()) extends RemoteRepository
        {
            protected function filter(array $data)
            {
                return $data;
            }

            // Make isRecoverableError public for testing
            public function isRecoverableError(string $errorMessage): bool
            {
                return parent::isRecoverableError($errorMessage);
            }
        };

        // Test with custom patterns
        $repository->setRecoverableErrorPatterns([
            'Custom error pattern',
            'Another recoverable error',
        ]);

        $this->assertTrue($repository->isRecoverableError('This is a Custom error pattern in the message'));
        $this->assertTrue($repository->isRecoverableError('Another recoverable error occurred'));
        $this->assertFalse($repository->isRecoverableError('This is not a recoverable error'));
    }

    public function test_recoverable_error_patterns_uses_config_default()
    {
        $repository = new class($this->createMockClient(), $this->createMockTokenService()) extends RemoteRepository
        {
            protected function filter(array $data)
            {
                return $data;
            }

            // Make isRecoverableError public for testing
            public function isRecoverableError(string $errorMessage): bool
            {
                return parent::isRecoverableError($errorMessage);
            }
        };

        // Test with default pattern from getRecoverableErrorPatterns
        $this->assertTrue($repository->isRecoverableError('Duplicate active delivery address codes found for customer'));
        $this->assertFalse($repository->isRecoverableError('Some other error'));
    }

    public function test_get_configured_base_uri_throws_exception_with_detailed_message()
    {
        // Clear all possible config values
        config(['app.remote_repository.base_uri' => null]);
        config(['jsonapi.base_uri' => null]);
        config(['pxc.base_api_uri' => null]);
        config(['remote.base_uri' => null]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No remote service base URI configured. Checked the following config keys: app.remote_repository.base_uri, jsonapi.base_uri, pxc.base_api_uri, remote.base_uri. Please set one of these configuration values.');

        new class($this->createMockClient(), $this->createMockTokenService()) extends RemoteRepository
        {
            protected function filter(array $data)
            {
                return $data;
            }
        };
    }

    public function test_get_configured_base_uri_uses_first_available_config()
    {
        $mockClient = $this->createMock(DocumentClientInterface::class);

        // Set different values for each config key
        config(['app.remote_repository.base_uri' => 'https://app.example.com']);
        config(['jsonapi.base_uri' => 'https://jsonapi.example.com']);
        config(['pxc.base_api_uri' => 'https://pxc.example.com']);
        config(['remote.base_uri' => 'https://remote.example.com']);

        // Test that constructor uses the new standardized config
        $mockClient->expects($this->once())
            ->method('setBaseUri')
            ->with('https://app.example.com');

        $repository = new class($mockClient, $this->createMockTokenService()) extends RemoteRepository
        {
            protected function filter(array $data)
            {
                return $data;
            }

            public function getConfiguredBaseUri(): string
            {
                return parent::getConfiguredBaseUri();
            }
        };

        // Should use the new config location
        $this->assertEquals('https://app.example.com', $repository->getConfiguredBaseUri());
    }

    public function test_recoverable_error_returns_document_interface()
    {
        $mockClient = $this->createMockClient();

        // Mock a response with a recoverable error
        $mockResponse = $this->createMock(\Swis\JsonApi\Client\Interfaces\DocumentInterface::class);

        $mockError = new \Swis\JsonApi\Client\Error(
            null, // id
            null, // links
            null, // status
            null, // code
            null, // title
            'Duplicate active delivery address codes found for customer 123' // detail
        );

        $errorCollection = new \Swis\JsonApi\Client\ErrorCollection;
        $errorCollection->push($mockError);

        $mockResponse->method('hasErrors')->willReturn(true);
        $mockResponse->method('getErrors')->willReturn($errorCollection);

        $mockClient->expects($this->once())
            ->method('get')
            ->willReturn($mockResponse);

        $repository = $this->createTestRepositoryWithPublicMethods($mockClient);

        $result = $repository->publicGet('/test-url');

        // Verify it returns a DocumentInterface
        $this->assertInstanceOf(\Swis\JsonApi\Client\Interfaces\DocumentInterface::class, $result);
        $this->assertTrue($result->hasErrors());
        $this->assertEquals('recoverable_error', $result->getErrors()->first()->getCode());
        $this->assertStringContainsString('Duplicate active delivery address codes found', $result->getErrors()->first()->getDetail());
    }

    public function test_get_configured_base_uri_uses_legacy_fallback_when_new_config_missing()
    {
        $mockClient = $this->createMock(DocumentClientInterface::class);

        // Clear new config but set legacy configs
        config(['app.remote_repository.base_uri' => null]);
        config(['jsonapi.base_uri' => 'https://jsonapi.example.com']);
        config(['pxc.base_api_uri' => 'https://pxc.example.com']);
        config(['remote.base_uri' => 'https://remote.example.com']);

        // Test that constructor uses the first legacy fallback
        $mockClient->expects($this->once())
            ->method('setBaseUri')
            ->with('https://jsonapi.example.com');

        $repository = new class($mockClient, $this->createMockTokenService()) extends RemoteRepository
        {
            protected function filter(array $data)
            {
                return $data;
            }

            public function getConfiguredBaseUri(): string
            {
                return parent::getConfiguredBaseUri();
            }
        };

        // Should use the first legacy fallback (jsonapi.base_uri)
        $this->assertEquals('https://jsonapi.example.com', $repository->getConfiguredBaseUri());
    }

    public function test_constructor_validates_machine_token_service()
    {
        // Test with non-interface implementation - PHP will throw TypeError
        $this->expectException(\TypeError::class);

        new class($this->createMockClient(), 'invalid') extends RemoteRepository
        {
            protected function filter(array $data)
            {
                return $data;
            }
        };
    }

    public function test_constructor_validates_machine_token_service_has_get_token_method()
    {
        $mockClient = $this->createMock(DocumentClientInterface::class);
        $mockClient->expects($this->never())->method('setBaseUri');

        $invalidService = new \stdClass;

        // Interface type hint ensures getToken() method exists at compile time
        $this->expectException(\TypeError::class);

        new class($mockClient, $invalidService) extends RemoteRepository
        {
            protected function filter(array $data)
            {
                return $data;
            }
        };
    }

    public function test_token_retrieval_failure_occurs_on_first_request()
    {
        $failingTokenService = $this->createFailingTokenService('Token service unavailable');

        // Constructor should NOT throw exception anymore (lazy loading)
        $repository = $this->createTestRepositoryWithTokenTrigger($this->createMockClient(), $failingTokenService);

        // Exception should be thrown when token is actually needed
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to retrieve token from machine token service: Token service unavailable');

        $repository->triggerTokenLoad();
    }

    public function test_token_validation_occurs_on_first_request()
    {
        $emptyTokenService = $this->createEmptyTokenService();

        // Constructor should NOT throw exception anymore (lazy loading)
        $repository = $this->createTestRepositoryWithTokenTrigger($this->createMockClient(), $emptyTokenService);

        // Exception should be thrown when token is actually needed
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Machine token service returned invalid token. Expected non-empty string, got: empty string');

        $repository->triggerTokenLoad();
    }

    public function test_token_type_validation_occurs_on_first_request()
    {
        // Test with non-string token - TypeError from return type gets caught and wrapped
        $mockMachineTokenService = new class implements MachineTokenServiceInterface
        {
            public function getToken(): string
            {
                return ['invalid' => 'token'];
            }
        };

        // Constructor should NOT throw exception anymore (lazy loading)
        $repository = $this->createTestRepositoryWithTokenTrigger($this->createMockClient(), $mockMachineTokenService);

        // Exception should be thrown when token is actually needed
        $this->expectException(\TypeError::class);

        $repository->triggerTokenLoad();
    }
}
