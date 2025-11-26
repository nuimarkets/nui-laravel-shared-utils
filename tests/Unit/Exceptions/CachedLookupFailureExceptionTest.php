<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\Exceptions;

use NuiMarkets\LaravelSharedUtils\Exceptions\CachedLookupFailureException;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;

class CachedLookupFailureExceptionTest extends TestCase
{
    public function test_exception_has_503_status_code(): void
    {
        $exception = CachedLookupFailureException::fromCachedData([
            'repository' => 'TestRepository',
            'lookup_type' => 'relationship',
            'identifiers' => ['id1'],
            'exception_class' => 'RuntimeException',
            'exception_message' => 'Test error',
            'cached_at' => '2025-11-27T12:34:56+00:00',
            'http_status' => 404,
            'failure_category' => 'not_found',
        ]);

        $this->assertEquals(503, $exception->getHttpStatusCode());
        $this->assertEquals(503, $exception->getCode());
    }

    public function test_exception_message_includes_http_status(): void
    {
        $exception = CachedLookupFailureException::fromCachedData([
            'repository' => 'App\\Repositories\\OrganisationRepository',
            'lookup_type' => 'relationship',
            'identifiers' => ['uuid1', 'uuid2'],
            'exception_class' => 'GuzzleHttp\\Exception\\ClientException',
            'exception_message' => 'Client error: 404 Not Found',
            'cached_at' => '2025-11-27T12:34:56+00:00',
            'http_status' => 404,
            'failure_category' => 'not_found',
        ]);

        $message = $exception->getMessage();

        $this->assertStringContainsString('HTTP 404', $message);
    }

    public function test_exception_accessors_return_new_fields(): void
    {
        $exception = CachedLookupFailureException::fromCachedData([
            'repository' => 'TestRepository',
            'lookup_type' => 'organisation',
            'identifiers' => ['uuid1'],
            'exception_class' => 'RuntimeException',
            'exception_message' => 'Error',
            'cached_at' => '2025-11-27T12:34:56+00:00',
            'http_status' => 500,
            'failure_category' => 'server_error',
        ]);

        $this->assertEquals(500, $exception->getHttpStatus());
        $this->assertEquals('server_error', $exception->getFailureCategory());
    }

    public function test_is_not_found_returns_true_for_404(): void
    {
        $exception = CachedLookupFailureException::fromCachedData([
            'repository' => 'TestRepository',
            'lookup_type' => 'test',
            'identifiers' => [],
            'exception_class' => 'Exception',
            'exception_message' => 'Error',
            'cached_at' => 'now',
            'http_status' => 404,
            'failure_category' => 'not_found',
        ]);

        $this->assertTrue($exception->isNotFound());
        $this->assertFalse($exception->isServerError());
    }

    public function test_is_server_error_returns_true_for_5xx(): void
    {
        $exception = CachedLookupFailureException::fromCachedData([
            'repository' => 'TestRepository',
            'lookup_type' => 'test',
            'identifiers' => [],
            'exception_class' => 'Exception',
            'exception_message' => 'Error',
            'cached_at' => 'now',
            'http_status' => 500,
            'failure_category' => 'server_error',
        ]);

        $this->assertTrue($exception->isServerError());
        $this->assertFalse($exception->isNotFound());
    }

    public function test_is_auth_error_returns_true_for_401_403(): void
    {
        $exception = CachedLookupFailureException::fromCachedData([
            'repository' => 'TestRepository',
            'lookup_type' => 'test',
            'identifiers' => [],
            'exception_class' => 'Exception',
            'exception_message' => 'Error',
            'cached_at' => 'now',
            'http_status' => 403,
            'failure_category' => 'auth_error',
        ]);

        $this->assertTrue($exception->isAuthError());
    }

    public function test_is_rate_limited_returns_true_for_429(): void
    {
        $exception = CachedLookupFailureException::fromCachedData([
            'repository' => 'TestRepository',
            'lookup_type' => 'test',
            'identifiers' => [],
            'exception_class' => 'Exception',
            'exception_message' => 'Error',
            'cached_at' => 'now',
            'http_status' => 429,
            'failure_category' => 'rate_limited',
        ]);

        $this->assertTrue($exception->isRateLimited());
    }

    public function test_is_transient_returns_true_for_transient_categories(): void
    {
        $transientCategories = ['timeout', 'connection_error', 'server_error', 'rate_limited'];

        foreach ($transientCategories as $category) {
            $exception = CachedLookupFailureException::fromCachedData([
                'repository' => 'TestRepository',
                'lookup_type' => 'test',
                'identifiers' => [],
                'exception_class' => 'Exception',
                'exception_message' => 'Error',
                'cached_at' => 'now',
                'http_status' => null,
                'failure_category' => $category,
            ]);

            $this->assertTrue($exception->isTransient(), "Expected isTransient() to be true for category: $category");
        }
    }

    public function test_is_transient_returns_false_for_non_transient_categories(): void
    {
        $nonTransientCategories = ['not_found', 'auth_error', 'client_error', 'unknown'];

        foreach ($nonTransientCategories as $category) {
            $exception = CachedLookupFailureException::fromCachedData([
                'repository' => 'TestRepository',
                'lookup_type' => 'test',
                'identifiers' => [],
                'exception_class' => 'Exception',
                'exception_message' => 'Error',
                'cached_at' => 'now',
                'http_status' => null,
                'failure_category' => $category,
            ]);

            $this->assertFalse($exception->isTransient(), "Expected isTransient() to be false for category: $category");
        }
    }

    public function test_handles_missing_new_fields_gracefully(): void
    {
        $exception = CachedLookupFailureException::fromCachedData([
            'repository' => 'TestRepository',
            'lookup_type' => 'test',
            'identifiers' => [],
            'exception_class' => 'Exception',
            'exception_message' => 'Error',
            'cached_at' => 'now',
            // Missing http_status and failure_category
        ]);

        $this->assertNull($exception->getHttpStatus());
        $this->assertEquals('unknown', $exception->getFailureCategory());
        $this->assertFalse($exception->isNotFound());
        $this->assertFalse($exception->isTransient());
    }
}
