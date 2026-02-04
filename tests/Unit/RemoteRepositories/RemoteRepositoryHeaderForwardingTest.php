<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\RemoteRepositories;

use Illuminate\Http\Request;
use NuiMarkets\LaravelSharedUtils\Contracts\HeaderResolverInterface;
use NuiMarkets\LaravelSharedUtils\RemoteRepositories\RemoteRepository;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;
use NuiMarkets\LaravelSharedUtils\Tests\Utils\RemoteRepositoryTestHelpers;

class RemoteRepositoryHeaderForwardingTest extends TestCase
{
    use RemoteRepositoryTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRemoteRepositoryConfig();
    }

    protected function tearDown(): void
    {
        // Clean up any registered test resolvers
        parent::tearDown();
    }

    protected function getHeadersFromRepository(RemoteRepository $repository): array
    {
        $reflection = new \ReflectionClass(RemoteRepository::class);
        $headersProperty = $reflection->getProperty('headers');
        $headersProperty->setAccessible(true);

        return $headersProperty->getValue($repository);
    }

    // ==================== Passthrough Headers ====================

    public function test_passthrough_header_is_forwarded_when_present_in_request()
    {
        config(['app.remote_repository.passthrough_headers' => ['X-Custom-Header']]);

        $request = Request::create('/api/test', 'GET');
        $request->headers->set('X-Custom-Header', 'test-value');
        $this->app->instance('request', $request);

        $repository = $this->createTestRepositoryWithTokenTrigger();
        $repository->triggerTokenLoad();

        $headers = $this->getHeadersFromRepository($repository);

        $this->assertArrayHasKey('X-Custom-Header', $headers);
        $this->assertEquals('test-value', $headers['X-Custom-Header']);
    }

    public function test_passthrough_header_is_not_added_when_not_present_in_request()
    {
        config(['app.remote_repository.passthrough_headers' => ['X-Custom-Header']]);

        $request = Request::create('/api/test', 'GET');
        $this->app->instance('request', $request);

        $repository = $this->createTestRepositoryWithTokenTrigger();
        $repository->triggerTokenLoad();

        $headers = $this->getHeadersFromRepository($repository);

        $this->assertArrayNotHasKey('X-Custom-Header', $headers);
    }

    public function test_multiple_passthrough_headers_are_forwarded()
    {
        config(['app.remote_repository.passthrough_headers' => ['X-Custom-Header', 'X-Another-Header']]);

        $request = Request::create('/api/test', 'GET');
        $request->headers->set('X-Custom-Header', 'value-one');
        $request->headers->set('X-Another-Header', 'value-two');
        $this->app->instance('request', $request);

        $repository = $this->createTestRepositoryWithTokenTrigger();
        $repository->triggerTokenLoad();

        $headers = $this->getHeadersFromRepository($repository);

        $this->assertArrayHasKey('X-Custom-Header', $headers);
        $this->assertEquals('value-one', $headers['X-Custom-Header']);
        $this->assertArrayHasKey('X-Another-Header', $headers);
        $this->assertEquals('value-two', $headers['X-Another-Header']);
    }

    public function test_passthrough_headers_with_empty_config()
    {
        config(['app.remote_repository.passthrough_headers' => []]);

        $request = Request::create('/api/test', 'GET');
        $request->headers->set('X-Custom-Header', 'test-value');
        $this->app->instance('request', $request);

        $repository = $this->createTestRepositoryWithTokenTrigger();
        $repository->triggerTokenLoad();

        $headers = $this->getHeadersFromRepository($repository);

        $this->assertArrayNotHasKey('X-Custom-Header', $headers);
    }

    public function test_passthrough_headers_defaults_to_empty_when_not_configured()
    {
        // Ensure config key doesn't exist
        config(['app.remote_repository.passthrough_headers' => null]);

        $request = Request::create('/api/test', 'GET');
        $request->headers->set('X-Custom-Header', 'test-value');
        $this->app->instance('request', $request);

        $repository = $this->createTestRepositoryWithTokenTrigger();
        $repository->triggerTokenLoad();

        $headers = $this->getHeadersFromRepository($repository);

        $this->assertArrayNotHasKey('X-Custom-Header', $headers);
    }

    // ==================== Contextual Headers ====================

    public function test_contextual_header_is_forwarded_when_present_in_request()
    {
        config(['app.remote_repository.contextual_headers' => [
            'X-Context-Header' => TestHeaderResolver::class,
        ]]);

        $request = Request::create('/api/test', 'GET');
        $request->headers->set('X-Context-Header', 'value-from-request');
        $this->app->instance('request', $request);

        $repository = $this->createTestRepositoryWithTokenTrigger();
        $repository->triggerTokenLoad();

        $headers = $this->getHeadersFromRepository($repository);

        $this->assertArrayHasKey('X-Context-Header', $headers);
        $this->assertEquals('value-from-request', $headers['X-Context-Header']);
    }

    public function test_contextual_header_uses_resolver_when_not_in_request()
    {
        // Register the resolver in the container
        $this->app->bind(TestHeaderResolver::class, function () {
            return new TestHeaderResolver('resolved-value-123');
        });

        config(['app.remote_repository.contextual_headers' => [
            'X-Context-Header' => TestHeaderResolver::class,
        ]]);

        $request = Request::create('/api/test', 'GET');
        // Don't set X-Context-Header header
        $this->app->instance('request', $request);

        $repository = $this->createTestRepositoryWithTokenTrigger();
        $repository->triggerTokenLoad();

        $headers = $this->getHeadersFromRepository($repository);

        $this->assertArrayHasKey('X-Context-Header', $headers);
        $this->assertEquals('resolved-value-123', $headers['X-Context-Header']);
    }

    public function test_contextual_header_prefers_request_over_resolver()
    {
        // Register the resolver in the container
        $this->app->bind(TestHeaderResolver::class, function () {
            return new TestHeaderResolver('resolved-value-123');
        });

        config(['app.remote_repository.contextual_headers' => [
            'X-Context-Header' => TestHeaderResolver::class,
        ]]);

        $request = Request::create('/api/test', 'GET');
        $request->headers->set('X-Context-Header', 'value-from-request');
        $this->app->instance('request', $request);

        $repository = $this->createTestRepositoryWithTokenTrigger();
        $repository->triggerTokenLoad();

        $headers = $this->getHeadersFromRepository($repository);

        // Should use request value, not resolver
        $this->assertArrayHasKey('X-Context-Header', $headers);
        $this->assertEquals('value-from-request', $headers['X-Context-Header']);
    }

    public function test_contextual_header_skipped_when_resolver_class_does_not_exist()
    {
        config(['app.remote_repository.contextual_headers' => [
            'X-Context-Header' => 'NonExistentResolverClass',
        ]]);

        $request = Request::create('/api/test', 'GET');
        $this->app->instance('request', $request);

        $repository = $this->createTestRepositoryWithTokenTrigger();
        $repository->triggerTokenLoad();

        $headers = $this->getHeadersFromRepository($repository);

        $this->assertArrayNotHasKey('X-Context-Header', $headers);
    }

    public function test_contextual_header_skipped_when_resolver_does_not_implement_interface()
    {
        // Register a resolver that doesn't implement the interface
        $this->app->bind(InvalidResolver::class, function () {
            return new InvalidResolver();
        });

        config(['app.remote_repository.contextual_headers' => [
            'X-Context-Header' => InvalidResolver::class,
        ]]);

        $request = Request::create('/api/test', 'GET');
        $this->app->instance('request', $request);

        $repository = $this->createTestRepositoryWithTokenTrigger();
        $repository->triggerTokenLoad();

        $headers = $this->getHeadersFromRepository($repository);

        $this->assertArrayNotHasKey('X-Context-Header', $headers);
    }

    public function test_contextual_header_skipped_when_resolver_returns_null()
    {
        // Register the resolver that returns null
        $this->app->bind(TestHeaderResolver::class, function () {
            return new TestHeaderResolver(null);
        });

        config(['app.remote_repository.contextual_headers' => [
            'X-Context-Header' => TestHeaderResolver::class,
        ]]);

        $request = Request::create('/api/test', 'GET');
        $this->app->instance('request', $request);

        $repository = $this->createTestRepositoryWithTokenTrigger();
        $repository->triggerTokenLoad();

        $headers = $this->getHeadersFromRepository($repository);

        $this->assertArrayNotHasKey('X-Context-Header', $headers);
    }

    public function test_multiple_contextual_headers_are_processed()
    {
        $this->app->bind(TestHeaderResolver::class, function () {
            return new TestHeaderResolver('resolved-value-one');
        });
        $this->app->bind(AnotherTestResolver::class, function () {
            return new AnotherTestResolver('resolved-value-two');
        });

        config(['app.remote_repository.contextual_headers' => [
            'X-Context-Header' => TestHeaderResolver::class,
            'X-Secondary-Header' => AnotherTestResolver::class,
        ]]);

        $request = Request::create('/api/test', 'GET');
        $this->app->instance('request', $request);

        $repository = $this->createTestRepositoryWithTokenTrigger();
        $repository->triggerTokenLoad();

        $headers = $this->getHeadersFromRepository($repository);

        $this->assertArrayHasKey('X-Context-Header', $headers);
        $this->assertEquals('resolved-value-one', $headers['X-Context-Header']);
        $this->assertArrayHasKey('X-Secondary-Header', $headers);
        $this->assertEquals('resolved-value-two', $headers['X-Secondary-Header']);
    }

    public function test_contextual_headers_with_null_resolver_class()
    {
        config(['app.remote_repository.contextual_headers' => [
            'X-Context-Header' => null,
        ]]);

        $request = Request::create('/api/test', 'GET');
        $request->headers->set('X-Context-Header', 'value-from-request');
        $this->app->instance('request', $request);

        $repository = $this->createTestRepositoryWithTokenTrigger();
        $repository->triggerTokenLoad();

        $headers = $this->getHeadersFromRepository($repository);

        // Should still passthrough even with null resolver
        $this->assertArrayHasKey('X-Context-Header', $headers);
        $this->assertEquals('value-from-request', $headers['X-Context-Header']);
    }

    public function test_contextual_headers_defaults_to_empty_when_not_configured()
    {
        config(['app.remote_repository.contextual_headers' => null]);

        $this->app->bind(TestHeaderResolver::class, function () {
            return new TestHeaderResolver('resolved-value');
        });

        $request = Request::create('/api/test', 'GET');
        $this->app->instance('request', $request);

        $repository = $this->createTestRepositoryWithTokenTrigger();
        $repository->triggerTokenLoad();

        $headers = $this->getHeadersFromRepository($repository);

        $this->assertArrayNotHasKey('X-Context-Header', $headers);
    }

    // ==================== Combined Passthrough and Contextual ====================

    public function test_passthrough_and_contextual_headers_work_together()
    {
        $this->app->bind(TestHeaderResolver::class, function () {
            return new TestHeaderResolver('resolved-value');
        });

        config([
            'app.remote_repository.passthrough_headers' => ['X-Custom-Header'],
            'app.remote_repository.contextual_headers' => [
                'X-Context-Header' => TestHeaderResolver::class,
            ],
        ]);

        $request = Request::create('/api/test', 'GET');
        $request->headers->set('X-Custom-Header', 'passthrough-value');
        $this->app->instance('request', $request);

        $repository = $this->createTestRepositoryWithTokenTrigger();
        $repository->triggerTokenLoad();

        $headers = $this->getHeadersFromRepository($repository);

        $this->assertArrayHasKey('X-Custom-Header', $headers);
        $this->assertEquals('passthrough-value', $headers['X-Custom-Header']);
        $this->assertArrayHasKey('X-Context-Header', $headers);
        $this->assertEquals('resolved-value', $headers['X-Context-Header']);
    }
}

// Test resolver that implements the interface
class TestHeaderResolver implements HeaderResolverInterface
{
    public function __construct(private ?string $value) {}

    public function resolve(): ?string
    {
        return $this->value;
    }
}

// Another test resolver for multi-header tests
class AnotherTestResolver implements HeaderResolverInterface
{
    public function __construct(private ?string $value) {}

    public function resolve(): ?string
    {
        return $this->value;
    }
}

// Test class that does NOT implement HeaderResolverInterface
class InvalidResolver
{
    public function resolve(): ?string
    {
        return 'should-not-be-used';
    }
}