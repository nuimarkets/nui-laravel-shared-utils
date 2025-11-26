<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\Testing;

use NuiMarkets\LaravelSharedUtils\Testing\JsonApiAssertions;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;
use NuiMarkets\LaravelSharedUtils\Tests\Utils\JsonApiResponseTestHelpers;
use PHPUnit\Framework\AssertionFailedError;

class JsonApiAssertionsTest extends TestCase
{
    use JsonApiAssertions;
    use JsonApiResponseTestHelpers;

    public function test_assert_has_validation_errors_passes_with_correct_fields()
    {
        $response = $this->createJsonApiValidationResponse(['email', 'name']);

        // This should pass without throwing an exception
        $this->assertHasValidationErrors($response, ['email', 'name']);

        // Also test with subset of fields
        $this->assertHasValidationErrors($response, ['email']);
    }

    public function test_assert_has_validation_errors_fails_when_field_not_present()
    {
        $response = $this->createSingleFieldErrorResponse('email');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Missing validation error for field: name. Available error fields:');

        $this->assertHasValidationErrors($response, ['email', 'name']);
    }

    public function test_assert_has_validation_errors_handles_nested_field_names()
    {
        $response = $this->createNestedFieldErrorResponse(['data.items.0.productId', 'buyerOrgIds.1']);

        $this->assertHasValidationErrors($response, ['data.items.0.productId', 'buyerOrgIds.1']);
    }

    public function test_assert_has_validation_errors_fails_on_wrong_status_code()
    {
        $response = $this->createJsonApiSuccessResponse();

        $this->expectException(AssertionFailedError::class);

        $this->assertHasValidationErrors($response, ['email']);
    }

    public function test_assert_has_validation_errors_fails_when_errors_array_missing()
    {
        $response = $this->createResponseWithoutErrors();

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('JSON:API "errors" member missing.');

        $this->assertHasValidationErrors($response, ['email']);
    }

    public function test_assertions_work_with_base_error_handler_format()
    {
        $response = $this->createBaseErrorHandlerValidationResponse([
            'email' => 'The email field is required.',
            'name' => 'The name field is required.',
        ]);

        // Should pass with BaseErrorHandler format
        $this->assertHasValidationErrors($response, ['email', 'name']);
    }

    public function test_assertions_work_with_base_error_handler_format_partial_match()
    {
        $response = $this->createBaseErrorHandlerValidationResponse([
            'email' => 'The email field must be a valid email address.',
            'password' => 'The password field must be at least 8 characters.',
        ]);

        // Should pass when checking for subset of fields
        $this->assertHasValidationErrors($response, ['email']);
        $this->assertHasValidationErrors($response, ['password']);
        $this->assertHasValidationErrors($response, ['email', 'password']);
    }

    public function test_assertions_fail_appropriately_with_base_error_handler_format()
    {
        $response = $this->createBaseErrorHandlerValidationResponse([
            'email' => 'The email field is required.',
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Missing validation error for field: missing_field');

        $this->assertHasValidationErrors($response, ['email', 'missing_field']);
    }

    public function test_assertions_handle_multiple_errors_per_field_base_error_handler()
    {
        $response = $this->createMultipleErrorsForFieldResponse('password', [
            'The password field is required.',
            'The password field must be at least 8 characters.',
            'The password field confirmation does not match.',
        ]);

        // Should still work with multiple errors for same field
        $this->assertHasValidationErrors($response, ['password']);
    }

    public function test_content_type_validation_accepts_both_formats()
    {
        // Test with standard JSON:API Content-Type
        $response1 = $this->createSingleFieldErrorResponse('email');
        $this->assertHasValidationErrors($response1, ['email']);

        // Test with regular JSON Content-Type (BaseErrorHandler)
        $response2 = $this->createBaseErrorHandlerValidationResponse(['email' => 'Required']);
        $this->assertHasValidationErrors($response2, ['email']);
    }
}
