<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\RemoteRepositories\Concerns;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use NuiMarkets\LaravelSharedUtils\Exceptions\CachedLookupFailureException;
use NuiMarkets\LaravelSharedUtils\Exceptions\RemoteServiceException;
use NuiMarkets\LaravelSharedUtils\Logging\LogFields;
use NuiMarkets\LaravelSharedUtils\RemoteRepositories\Concerns\CachesFailedLookups;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;

class CachesFailedLookupsTest extends TestCase
{
    use CachesFailedLookups;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['app.remote_repository.failure_cache_ttl' => 120]);
        config(['app.remote_repository.failure_cache_ttl_by_category' => []]);
    }

    /**
     * Override to provide a short repository name for tests.
     */
    protected function getRepositoryShortName(): string
    {
        return 'testrepository';
    }

    // ========================================================================
    // Cache Key Generation Tests
    // ========================================================================

    public function test_generates_consistent_cache_key_for_same_inputs(): void
    {
        $key1 = $this->buildFailureCacheKey('lookup_type', ['id1', 'id2']);
        $key2 = $this->buildFailureCacheKey('lookup_type', ['id1', 'id2']);

        $this->assertEquals($key1, $key2);
    }

    public function test_generates_different_cache_keys_for_different_lookup_types(): void
    {
        $key1 = $this->buildFailureCacheKey('relationship', ['id1']);
        $key2 = $this->buildFailureCacheKey('organisation', ['id1']);

        $this->assertNotEquals($key1, $key2);
    }

    public function test_generates_different_cache_keys_for_different_identifiers(): void
    {
        $key1 = $this->buildFailureCacheKey('lookup', ['id1', 'id2']);
        $key2 = $this->buildFailureCacheKey('lookup', ['id3', 'id4']);

        $this->assertNotEquals($key1, $key2);
    }

    public function test_handles_special_characters_in_identifiers(): void
    {
        $identifiers = [
            'abc:def:123',
            'uuid-with-special:chars!@#$%',
            '123e4567-e89b-12d3-a456-426614174000',
        ];

        $key = $this->buildFailureCacheKey('lookup', $identifiers);

        $this->assertMatchesRegularExpression('/^remote_failure:testrepository:lookup:[a-f0-9]{32}$/', $key);
    }

    public function test_handles_empty_identifiers(): void
    {
        $key = $this->buildFailureCacheKey('lookup', []);

        $this->assertMatchesRegularExpression('/^remote_failure:testrepository:lookup:[a-f0-9]{32}$/', $key);
    }

    public function test_cache_key_format_is_correct(): void
    {
        $key = $this->buildFailureCacheKey('relationship', ['org1', 'org2']);

        $this->assertStringStartsWith('remote_failure:testrepository:relationship:', $key);
        $this->assertMatchesRegularExpression('/^remote_failure:testrepository:relationship:[a-f0-9]{32}$/', $key);
    }

    // ========================================================================
    // HTTP Status Extraction Tests
    // ========================================================================

    public function test_extracts_http_status_from_guzzle_client_exception(): void
    {
        $request = new Request('GET', 'http://example.com');
        $response = new Response(404, [], 'Not found');
        $exception = new RequestException('Not found', $request, $response);

        $status = $this->extractHttpStatus($exception);

        $this->assertEquals(404, $status);
    }

    public function test_extracts_http_status_from_guzzle_server_exception(): void
    {
        $request = new Request('GET', 'http://example.com');
        $response = new Response(500, [], 'Internal server error');
        $exception = new RequestException('Server error', $request, $response);

        $status = $this->extractHttpStatus($exception);

        $this->assertEquals(500, $status);
    }

    public function test_returns_null_for_connect_exception(): void
    {
        $request = new Request('GET', 'http://example.com');
        $exception = new ConnectException('Connection refused', $request);

        $status = $this->extractHttpStatus($exception);

        $this->assertNull($status);
    }

    public function test_returns_null_for_generic_exception(): void
    {
        $exception = new \RuntimeException('Generic error');

        $status = $this->extractHttpStatus($exception);

        $this->assertNull($status);
    }

    public function test_extracts_status_from_exception_code_when_valid_http_range(): void
    {
        // When there's no Guzzle exception in the chain, the code falls back to exception code
        // RemoteServiceException with 404 code should use that code as HTTP status
        $exception = new \RuntimeException('Error', 404);

        $status = $this->extractHttpStatus($exception);

        $this->assertEquals(404, $status);
    }

    public function test_ignores_exception_code_outside_http_range(): void
    {
        $exception = new \RuntimeException('Error', 1);

        $status = $this->extractHttpStatus($exception);

        $this->assertNull($status);
    }

    public function test_extracts_http_status_from_wrapped_guzzle_exception(): void
    {
        $request = new Request('GET', 'http://example.com');
        $response = new Response(404, [], 'Not found');
        $guzzleException = new RequestException('Not found', $request, $response);

        // Wrap in a plain exception (not HttpException) so we traverse to Guzzle
        $wrappedException = new \RuntimeException('Wrapped error', 0, $guzzleException);

        $status = $this->extractHttpStatus($wrappedException);

        // Should find 404 from inner Guzzle exception
        $this->assertEquals(404, $status);
    }

    public function test_traverses_exception_chain_to_find_guzzle_exception(): void
    {
        $request = new Request('GET', 'http://example.com');
        $response = new Response(503, [], 'Service unavailable');
        $guzzleException = new RequestException('Service unavailable', $request, $response);

        // Multiple levels of wrapping with plain exceptions (not HttpException)
        $level1 = new \RuntimeException('Level 1', 0, $guzzleException);
        $level2 = new \RuntimeException('Level 2', 0, $level1);
        $level3 = new \RuntimeException('Level 3', 0, $level2);

        $status = $this->extractHttpStatus($level3);

        $this->assertEquals(503, $status);
    }

    public function test_extracts_http_status_from_http_exception(): void
    {
        // RemoteServiceException is an HttpException and stores status via getStatusCode()
        $exception = new RemoteServiceException('Error', 404);

        $status = $this->extractHttpStatus($exception);

        $this->assertEquals(404, $status);
    }

    public function test_http_exception_status_takes_precedence_over_wrapped_guzzle(): void
    {
        $request = new Request('GET', 'http://example.com');
        $response = new Response(500, [], 'Server error');
        $guzzleException = new RequestException('Server error', $request, $response);

        // RemoteServiceException wrapping Guzzle - the HTTP exception status takes precedence
        $wrappedException = new RemoteServiceException('Wrapped error', 502, $guzzleException);

        $status = $this->extractHttpStatus($wrappedException);

        // Should return 502 from the HttpException, not 500 from Guzzle
        $this->assertEquals(502, $status);
    }

    public function test_respects_max_depth_when_traversing_exception_chain(): void
    {
        $request = new Request('GET', 'http://example.com');
        $response = new Response(404, [], 'Not found');
        $guzzleException = new RequestException('Not found', $request, $response);

        // Create a chain deeper than max depth (5)
        $current = $guzzleException;
        for ($i = 0; $i < 10; $i++) {
            $current = new \RuntimeException("Level $i", 0, $current);
        }

        $status = $this->extractHttpStatus($current);

        // Should not find the Guzzle exception (too deep)
        $this->assertNull($status);
    }

    // ========================================================================
    // Failure Classification Tests
    // ========================================================================

    public function test_classifies_404_as_not_found(): void
    {
        $exception = new \RuntimeException('Error');

        $category = $this->classifyFailure($exception, 404);

        $this->assertEquals('not_found', $category);
    }

    public function test_classifies_401_as_auth_error(): void
    {
        $exception = new \RuntimeException('Error');

        $category = $this->classifyFailure($exception, 401);

        $this->assertEquals('auth_error', $category);
    }

    public function test_classifies_403_as_auth_error(): void
    {
        $exception = new \RuntimeException('Error');

        $category = $this->classifyFailure($exception, 403);

        $this->assertEquals('auth_error', $category);
    }

    public function test_classifies_429_as_rate_limited(): void
    {
        $exception = new \RuntimeException('Error');

        $category = $this->classifyFailure($exception, 429);

        $this->assertEquals('rate_limited', $category);
    }

    public function test_classifies_500_as_server_error(): void
    {
        $exception = new \RuntimeException('Error');

        $category = $this->classifyFailure($exception, 500);

        $this->assertEquals('server_error', $category);
    }

    public function test_classifies_503_as_server_error(): void
    {
        $exception = new \RuntimeException('Error');

        $category = $this->classifyFailure($exception, 503);

        $this->assertEquals('server_error', $category);
    }

    public function test_classifies_400_as_client_error(): void
    {
        $exception = new \RuntimeException('Error');

        $category = $this->classifyFailure($exception, 400);

        $this->assertEquals('client_error', $category);
    }

    public function test_classifies_timeout_from_connect_exception(): void
    {
        $request = new Request('GET', 'http://example.com');
        $exception = new ConnectException('cURL error 28: Operation timed out', $request);

        $category = $this->classifyFailure($exception, null);

        $this->assertEquals('timeout', $category);
    }

    public function test_classifies_connection_error_from_connect_exception(): void
    {
        $request = new Request('GET', 'http://example.com');
        $exception = new ConnectException('cURL error 7: Connection refused', $request);

        $category = $this->classifyFailure($exception, null);

        $this->assertEquals('connection_error', $category);
    }

    public function test_classifies_wrapped_timeout_from_connect_exception(): void
    {
        $request = new Request('GET', 'http://example.com');
        $connectException = new ConnectException('cURL error 28: Operation timed out', $request);
        $wrappedException = new RemoteServiceException('Wrapped', 503, $connectException);

        $category = $this->classifyFailure($wrappedException, null);

        $this->assertEquals('timeout', $category);
    }

    public function test_classifies_unknown_for_generic_exception_without_status(): void
    {
        $exception = new \RuntimeException('Generic error');

        $category = $this->classifyFailure($exception, null);

        $this->assertEquals('unknown', $category);
    }

    // ========================================================================
    // Category-Specific TTL Tests
    // ========================================================================

    public function test_uses_category_ttl_when_configured(): void
    {
        config(['app.remote_repository.failure_cache_ttl_by_category' => [
            'not_found' => 600,
        ]]);

        $ttl = $this->getFailureCacheTtlForCategory('not_found');

        $this->assertEquals(600, $ttl);
    }

    public function test_uses_default_ttl_for_unconfigured_category(): void
    {
        config(['app.remote_repository.failure_cache_ttl' => 120]);
        config(['app.remote_repository.failure_cache_ttl_by_category' => [
            'not_found' => 600,
        ]]);

        $ttl = $this->getFailureCacheTtlForCategory('server_error');

        $this->assertEquals(120, $ttl);
    }

    public function test_uses_default_ttl_when_no_category_config(): void
    {
        config(['app.remote_repository.failure_cache_ttl' => 120]);
        config(['app.remote_repository.failure_cache_ttl_by_category' => []]);

        $ttl = $this->getFailureCacheTtlForCategory('not_found');

        $this->assertEquals(120, $ttl);
    }

    public function test_different_categories_get_different_ttls(): void
    {
        config(['app.remote_repository.failure_cache_ttl_by_category' => [
            'not_found' => 600,
            'timeout' => 30,
            'server_error' => 120,
        ]]);

        $this->assertEquals(600, $this->getFailureCacheTtlForCategory('not_found'));
        $this->assertEquals(30, $this->getFailureCacheTtlForCategory('timeout'));
        $this->assertEquals(120, $this->getFailureCacheTtlForCategory('server_error'));
    }

    // ========================================================================
    // Failure Caching Tests
    // ========================================================================

    public function test_caches_failure_with_http_status_and_category(): void
    {
        $request = new Request('GET', 'http://example.com');
        $response = new Response(404, [], 'Not found');
        $exception = new RequestException('Not found', $request, $response);

        Log::shouldReceive('warning')->once();

        $this->cacheLookupFailure('test_lookup', $exception, 'id1', 'id2');

        $cached = $this->getCachedFailureData('test_lookup', 'id1', 'id2');

        $this->assertNotNull($cached);
        $this->assertEquals(404, $cached['http_status']);
        $this->assertEquals('not_found', $cached['failure_category']);
    }

    public function test_caches_failure_with_null_status_for_timeout(): void
    {
        $request = new Request('GET', 'http://example.com');
        $exception = new ConnectException('cURL error 28: Operation timed out', $request);

        Log::shouldReceive('warning')->once();

        $this->cacheLookupFailure('test_lookup', $exception, 'id1');

        $cached = $this->getCachedFailureData('test_lookup', 'id1');

        $this->assertNull($cached['http_status']);
        $this->assertEquals('timeout', $cached['failure_category']);
    }

    public function test_caches_failure_uses_category_specific_ttl(): void
    {
        config(['app.remote_repository.failure_cache_ttl_by_category' => [
            'not_found' => 600,
        ]]);

        $request = new Request('GET', 'http://example.com');
        $response = new Response(404, [], 'Not found');
        $exception = new RequestException('Not found', $request, $response);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message, $context) {
                return $context[LogFields::CACHE_TTL] === 600;
            });

        $this->cacheLookupFailure('test_lookup', $exception, 'id1');
    }

    public function test_caches_failure_with_correct_structure(): void
    {
        // Use a Guzzle exception so HTTP status extraction works correctly
        $request = new Request('GET', 'http://example.com');
        $response = new Response(500, [], 'Server error');
        $exception = new RequestException('Test error message', $request, $response);

        Log::shouldReceive('warning')->once();

        $this->cacheLookupFailure('test_lookup', $exception, 'id1', 'id2');

        $cached = $this->getCachedFailureData('test_lookup', 'id1', 'id2');

        $this->assertNotNull($cached);
        $this->assertEquals(RequestException::class, $cached['exception_class']);
        $this->assertEquals('Test error message', $cached['exception_message']);
        $this->assertEquals(500, $cached['http_status']);
        $this->assertEquals('server_error', $cached['failure_category']);
        $this->assertEquals(['id1', 'id2'], $cached['identifiers']);
        $this->assertEquals('test_lookup', $cached['lookup_type']);
        $this->assertEquals(static::class, $cached['repository']);
        $this->assertArrayHasKey('cached_at', $cached);
    }

    // ========================================================================
    // Cached Failure Detection Tests
    // ========================================================================

    public function test_does_not_throw_when_no_cached_failure(): void
    {
        $this->throwIfCachedLookupFailed('nonexistent', 'id1', 'id2');

        $this->assertTrue(true);
    }

    public function test_throws_cached_lookup_failure_exception_when_cached(): void
    {
        $request = new Request('GET', 'http://example.com');
        $response = new Response(404, [], 'Not found');
        $originalException = new RequestException('Original error', $request, $response);

        Log::shouldReceive('warning')->once();

        $this->cacheLookupFailure('relationship', $originalException, 'org1', 'org2');

        Log::shouldReceive('info')->once();

        $this->expectException(CachedLookupFailureException::class);

        $this->throwIfCachedLookupFailed('relationship', 'org1', 'org2');
    }

    public function test_exception_contains_http_status(): void
    {
        $request = new Request('GET', 'http://example.com');
        $response = new Response(404, [], 'Not found');
        $exception = new RequestException('Error', $request, $response);

        Log::shouldReceive('warning')->once();
        Log::shouldReceive('info')->once();

        $this->cacheLookupFailure('lookup', $exception, 'id1');

        try {
            $this->throwIfCachedLookupFailed('lookup', 'id1');
            $this->fail('Expected CachedLookupFailureException to be thrown');
        } catch (CachedLookupFailureException $e) {
            $this->assertEquals(404, $e->getHttpStatus());
        }
    }

    public function test_exception_contains_failure_category(): void
    {
        $request = new Request('GET', 'http://example.com');
        $response = new Response(404, [], 'Not found');
        $exception = new RequestException('Error', $request, $response);

        Log::shouldReceive('warning')->once();
        Log::shouldReceive('info')->once();

        $this->cacheLookupFailure('lookup', $exception, 'id1');

        try {
            $this->throwIfCachedLookupFailed('lookup', 'id1');
            $this->fail('Expected CachedLookupFailureException to be thrown');
        } catch (CachedLookupFailureException $e) {
            $this->assertEquals('not_found', $e->getFailureCategory());
        }
    }

    public function test_exception_convenience_methods_work(): void
    {
        $request = new Request('GET', 'http://example.com');
        $response = new Response(404, [], 'Not found');
        $exception = new RequestException('Error', $request, $response);

        Log::shouldReceive('warning')->once();
        Log::shouldReceive('info')->once();

        $this->cacheLookupFailure('lookup', $exception, 'id1');

        try {
            $this->throwIfCachedLookupFailed('lookup', 'id1');
            $this->fail('Expected CachedLookupFailureException to be thrown');
        } catch (CachedLookupFailureException $e) {
            $this->assertTrue($e->isNotFound());
            $this->assertFalse($e->isServerError());
            $this->assertFalse($e->isTransient());
        }
    }

    public function test_transient_failures_are_detected(): void
    {
        $request = new Request('GET', 'http://example.com');
        $exception = new ConnectException('cURL error 28: Operation timed out', $request);

        Log::shouldReceive('warning')->once();
        Log::shouldReceive('info')->once();

        $this->cacheLookupFailure('lookup', $exception, 'id1');

        try {
            $this->throwIfCachedLookupFailed('lookup', 'id1');
            $this->fail('Expected CachedLookupFailureException to be thrown');
        } catch (CachedLookupFailureException $e) {
            $this->assertTrue($e->isTransient());
            $this->assertFalse($e->isNotFound());
        }
    }

    // ========================================================================
    // Cache Invalidation Tests
    // ========================================================================

    public function test_clear_removes_specific_cached_failure(): void
    {
        $exception = new RemoteServiceException('Error', 500);

        Log::shouldReceive('warning')->once();
        Log::shouldReceive('debug')->once();

        $this->cacheLookupFailure('lookup', $exception, 'id1', 'id2');

        $this->assertNotNull($this->getCachedFailureData('lookup', 'id1', 'id2'));

        $this->clearCachedLookupFailure('lookup', 'id1', 'id2');

        $this->assertNull($this->getCachedFailureData('lookup', 'id1', 'id2'));
    }

    public function test_clear_does_not_affect_other_cache_entries(): void
    {
        $exception = new RemoteServiceException('Error', 500);

        Log::shouldReceive('warning')->twice();
        Log::shouldReceive('debug')->once();

        $this->cacheLookupFailure('lookup1', $exception, 'id1');
        $this->cacheLookupFailure('lookup2', $exception, 'id2');

        $this->clearCachedLookupFailure('lookup1', 'id1');

        $this->assertNull($this->getCachedFailureData('lookup1', 'id1'));
        $this->assertNotNull($this->getCachedFailureData('lookup2', 'id2'));
    }

    public function test_clear_is_idempotent(): void
    {
        Log::shouldReceive('debug')->twice();

        $this->clearCachedLookupFailure('nonexistent', 'id1');
        $this->clearCachedLookupFailure('nonexistent', 'id1');

        $this->assertTrue(true);
    }

    // ========================================================================
    // Logging Tests
    // ========================================================================

    public function test_logs_http_status_and_category_on_cache_hit(): void
    {
        $request = new Request('GET', 'http://example.com');
        $response = new Response(404, [], 'Not found');
        $exception = new RequestException('Test error', $request, $response);

        Log::shouldReceive('warning')->once();

        $this->cacheLookupFailure('relationship', $exception, 'org1', 'org2');

        Log::shouldReceive('info')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'Remote lookup cache hit - returning cached failure'
                    && $context['http_status'] === 404
                    && $context['failure_category'] === 'not_found';
            });

        try {
            $this->throwIfCachedLookupFailed('relationship', 'org1', 'org2');
        } catch (CachedLookupFailureException $e) {
            // Expected
        }
    }

    public function test_logs_http_status_and_category_on_cache_store(): void
    {
        $request = new Request('GET', 'http://example.com');
        $response = new Response(500, [], 'Server error');
        $exception = new RequestException('Test error', $request, $response);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'Remote lookup failed - caching failure'
                    && $context['http_status'] === 500
                    && $context['failure_category'] === 'server_error';
            });

        $this->cacheLookupFailure('relationship', $exception, 'org1', 'org2');
    }

    // ========================================================================
    // Default Behavior Tests
    // ========================================================================

    public function test_works_without_category_ttl_config(): void
    {
        config(['app.remote_repository.failure_cache_ttl' => 120]);
        config(['app.remote_repository.failure_cache_ttl_by_category' => null]);

        $request = new Request('GET', 'http://example.com');
        $response = new Response(404, [], 'Not found');
        $exception = new RequestException('Error', $request, $response);

        Log::shouldReceive('warning')->once();

        $this->cacheLookupFailure('lookup', $exception, 'id1');

        $cached = $this->getCachedFailureData('lookup', 'id1');
        $this->assertNotNull($cached);
    }

    public function test_default_ttl_used_when_no_config(): void
    {
        config(['app.remote_repository.failure_cache_ttl' => null]);
        config(['app.remote_repository.failure_cache_ttl_by_category' => null]);

        $ttl = $this->getFailureCacheTtl();

        $this->assertEquals(120, $ttl); // Default 2 minutes
    }
}
