<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\RemoteRepositories;

use NuiMarkets\LaravelSharedUtils\RemoteRepositories\FailureCategory;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;

class FailureCategoryTest extends TestCase
{
    public function test_constants_have_expected_values(): void
    {
        $this->assertEquals('not_found', FailureCategory::NOT_FOUND);
        $this->assertEquals('auth_error', FailureCategory::AUTH_ERROR);
        $this->assertEquals('rate_limited', FailureCategory::RATE_LIMITED);
        $this->assertEquals('server_error', FailureCategory::SERVER_ERROR);
        $this->assertEquals('timeout', FailureCategory::TIMEOUT);
        $this->assertEquals('connection_error', FailureCategory::CONNECTION_ERROR);
        $this->assertEquals('client_error', FailureCategory::CLIENT_ERROR);
        $this->assertEquals('unknown', FailureCategory::UNKNOWN);
    }

    public function test_transient_categories_contains_expected_values(): void
    {
        $this->assertContains(FailureCategory::TIMEOUT, FailureCategory::TRANSIENT_CATEGORIES);
        $this->assertContains(FailureCategory::CONNECTION_ERROR, FailureCategory::TRANSIENT_CATEGORIES);
        $this->assertContains(FailureCategory::SERVER_ERROR, FailureCategory::TRANSIENT_CATEGORIES);
        $this->assertContains(FailureCategory::RATE_LIMITED, FailureCategory::TRANSIENT_CATEGORIES);
    }

    public function test_transient_categories_does_not_contain_non_transient(): void
    {
        $this->assertNotContains(FailureCategory::NOT_FOUND, FailureCategory::TRANSIENT_CATEGORIES);
        $this->assertNotContains(FailureCategory::AUTH_ERROR, FailureCategory::TRANSIENT_CATEGORIES);
        $this->assertNotContains(FailureCategory::CLIENT_ERROR, FailureCategory::TRANSIENT_CATEGORIES);
        $this->assertNotContains(FailureCategory::UNKNOWN, FailureCategory::TRANSIENT_CATEGORIES);
    }

    /**
     * @dataProvider transientCategoriesProvider
     */
    public function test_is_transient_returns_true_for_transient_categories(string $category): void
    {
        $this->assertTrue(FailureCategory::isTransient($category));
    }

    public static function transientCategoriesProvider(): array
    {
        return [
            'timeout' => [FailureCategory::TIMEOUT],
            'connection_error' => [FailureCategory::CONNECTION_ERROR],
            'server_error' => [FailureCategory::SERVER_ERROR],
            'rate_limited' => [FailureCategory::RATE_LIMITED],
        ];
    }

    /**
     * @dataProvider nonTransientCategoriesProvider
     */
    public function test_is_transient_returns_false_for_non_transient_categories(string $category): void
    {
        $this->assertFalse(FailureCategory::isTransient($category));
    }

    public static function nonTransientCategoriesProvider(): array
    {
        return [
            'not_found' => [FailureCategory::NOT_FOUND],
            'auth_error' => [FailureCategory::AUTH_ERROR],
            'client_error' => [FailureCategory::CLIENT_ERROR],
            'unknown' => [FailureCategory::UNKNOWN],
            'arbitrary_string' => ['some_random_category'],
        ];
    }
}
