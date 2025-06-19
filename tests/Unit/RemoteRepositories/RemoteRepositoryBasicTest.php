<?php

namespace Nuimarkets\LaravelSharedUtils\Tests\Unit\RemoteRepositories;

use Nuimarkets\LaravelSharedUtils\RemoteRepositories\RemoteRepository;
use Nuimarkets\LaravelSharedUtils\Support\SimpleDocument;
use Nuimarkets\LaravelSharedUtils\Tests\TestCase;
use Swis\JsonApi\Client\Interfaces\DocumentClientInterface;
use Swis\JsonApi\Client\Interfaces\ItemInterface;
use Swis\JsonApi\Client\Item;

class RemoteRepositoryBasicTest extends TestCase
{
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
            'query'
        ];
        
        foreach ($requiredMethods as $method) {
            $this->assertTrue($reflection->hasMethod($method), "Method '$method' not found");
        }
    }

    public function test_uses_profiling_trait()
    {
        $reflection = new \ReflectionClass(RemoteRepository::class);
        $traits = $reflection->getTraitNames();
        
        $this->assertContains('Nuimarkets\LaravelSharedUtils\Support\ProfilingTrait', $traits);
    }

    public function test_make_request_body_creates_simple_document()
    {
        $testData = ['name' => 'Test Product', 'price' => 100];
        
        $mockClient = $this->createMock(DocumentClientInterface::class);
        $mockMachineTokenService = $this->createMockMachineTokenService();
        
        $repository = $this->createConcreteRepository($mockClient, $mockMachineTokenService);
        $result = $repository->makeRequestBody($testData);
        
        $this->assertInstanceOf(SimpleDocument::class, $result);
        $this->assertEquals('array', $result->getData()->getType());
    }

    public function test_has_id_method_works()
    {
        $mockClient = $this->createMock(DocumentClientInterface::class);
        $mockMachineTokenService = $this->createMockMachineTokenService();
        
        $repository = $this->createConcreteRepository($mockClient, $mockMachineTokenService);
        
        // Add an item to the internal collection
        $mockItem = $this->createMock(ItemInterface::class);
        $mockItem->method('getId')->willReturn('test-id');
        $repository->query()->put('test-id', $mockItem);
        
        $this->assertTrue($repository->hasId('test-id'));
        $this->assertFalse($repository->hasId('non-existent-id'));
    }

    public function test_query_returns_collection()
    {
        $mockClient = $this->createMock(DocumentClientInterface::class);
        $mockMachineTokenService = $this->createMockMachineTokenService();
        
        $repository = $this->createConcreteRepository($mockClient, $mockMachineTokenService);
        $collection = $repository->query();
        
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $collection);
    }

    public function test_find_by_id_without_retrieve_returns_cached_item()
    {
        $mockClient = $this->createMock(DocumentClientInterface::class);
        $mockMachineTokenService = $this->createMockMachineTokenService();
        
        $repository = $this->createConcreteRepository($mockClient, $mockMachineTokenService);
        
        $mockItem = $this->createMock(ItemInterface::class);
        $repository->query()->put('cached-id', $mockItem);
        
        $result = $repository->findByIdWithoutRetrieve('cached-id');
        
        $this->assertSame($mockItem, $result);
    }

    public function test_allowed_get_request_validates_url_length()
    {
        $mockClient = $this->createMock(DocumentClientInterface::class);
        $mockClient->method('getBaseUri')->willReturn('https://api.example.com');
        
        $mockMachineTokenService = $this->createMockMachineTokenService();
        
        // Create repository that won't bypass client initialization
        $repository = new class($mockClient, $mockMachineTokenService) extends RemoteRepository {
            public function __construct($client, $machineTokenService)
            {
                // Force client initialization for this test
                $this->client = $client;
                $this->headers = [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json', 
                    'Authorization' => 'Bearer ' . $machineTokenService->getToken()
                ];
                $this->data = new \Illuminate\Support\Collection();
            }
            
            protected function filter(array $data) { return $data; }
        };
        
        // Test short URL (should be allowed)
        $this->assertTrue($repository->allowedGetRequest('/short'));
        
        // Test very long URL (should be rejected) - make it longer than 2048 chars
        $longUrl = '/api/' . str_repeat('very-long-segment-that-exceeds-url-limits/', 200);
        $this->assertFalse($repository->allowedGetRequest($longUrl));
    }

    public function test_configuration_fallback_methods_exist()
    {
        $reflection = new \ReflectionClass(RemoteRepository::class);
        
        $this->assertTrue($reflection->hasMethod('getConfiguredBaseUri'));
        $this->assertTrue($reflection->hasMethod('getBaseUrlLength'));
    }

    private function createMockMachineTokenService()
    {
        return new class {
            public function getToken()
            {
                return 'test-token';
            }
        };
    }

    private function createConcreteRepository($client, $machineTokenService)
    {
        return new class($client, $machineTokenService) extends RemoteRepository {
            protected function filter(array $data) 
            {
                return $data;
            }
        };
    }
}