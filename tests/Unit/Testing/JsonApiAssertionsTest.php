<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\Testing;

use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use NuiMarkets\LaravelSharedUtils\Testing\JsonApiAssertions;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;
use PHPUnit\Framework\AssertionFailedError;

class JsonApiAssertionsTest extends TestCase
{
    use JsonApiAssertions;

    public function test_assert_has_validation_errors_passes_with_correct_fields()
    {
        $responseData = [
            'errors' => [
                [
                    'detail' => 'The field is required.',
                    'source' => [
                        'pointer' => '/data/attributes/email',
                    ],
                ],
                [
                    'detail' => 'The name field is required.',
                    'source' => [
                        'pointer' => '/data/attributes/name',
                    ],
                ],
            ],
        ];

        $jsonResponse = new JsonResponse($responseData, 422);
        $jsonResponse->header('Content-Type', 'application/vnd.api+json');
        $response = new TestResponse($jsonResponse);

        // This should pass without throwing an exception
        $this->assertHasValidationErrors($response, ['email', 'name']);

        // Also test with subset of fields
        $this->assertHasValidationErrors($response, ['email']);
    }

    public function test_assert_has_validation_errors_fails_when_field_not_present()
    {
        $responseData = [
            'errors' => [
                [
                    'detail' => 'The field is required.',
                    'source' => [
                        'pointer' => '/data/attributes/email',
                    ],
                ],
            ],
        ];

        $jsonResponse = new JsonResponse($responseData, 422);
        $jsonResponse->header('Content-Type', 'application/vnd.api+json');
        $response = new TestResponse($jsonResponse);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Missing validation error for field: name. Available error fields:');

        $this->assertHasValidationErrors($response, ['email', 'name']);
    }

    public function test_assert_has_validation_errors_handles_nested_field_names()
    {
        $responseData = [
            'errors' => [
                [
                    'detail' => 'The data.items.0.productId field is required.',
                    'source' => [
                        'pointer' => '/data/attributes/data.items.0.productId',
                    ],
                ],
                [
                    'detail' => 'The buyerOrgIds.1 field is invalid.',
                    'source' => [
                        'pointer' => '/data/attributes/buyerOrgIds.1',
                    ],
                ],
            ],
        ];

        $jsonResponse = new JsonResponse($responseData, 422);
        $jsonResponse->header('Content-Type', 'application/vnd.api+json');
        $response = new TestResponse($jsonResponse);

        $this->assertHasValidationErrors($response, ['data.items.0.productId', 'buyerOrgIds.1']);
    }

    public function test_assert_has_validation_errors_fails_on_wrong_status_code()
    {
        $responseData = ['message' => 'Success'];
        $response = new TestResponse(new JsonResponse($responseData, 200));

        $this->expectException(AssertionFailedError::class);

        $this->assertHasValidationErrors($response, ['email']);
    }

    public function test_assert_has_validation_errors_fails_when_errors_array_missing()
    {
        $responseData = ['message' => 'Validation failed'];
        $jsonResponse = new JsonResponse($responseData, 422);
        $jsonResponse->header('Content-Type', 'application/vnd.api+json');
        $response = new TestResponse($jsonResponse);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('JSON:API "errors" member missing.');

        $this->assertHasValidationErrors($response, ['email']);
    }

    public function test_assertions_work_with_base_error_handler_format()
    {
        // Mock response with BaseErrorHandler JSON:API format (application/json Content-Type)
        $responseData = [
            'meta' => [
                'message' => 'Validation Failed',
                'status' => 422,
            ],
            'errors' => [
                [
                    'status' => '422',
                    'title' => 'Validation Error',
                    'detail' => 'email: The email field is required.',
                    'source' => ['pointer' => '/data/attributes/email'],
                ],
                [
                    'status' => '422',
                    'title' => 'Validation Error',
                    'detail' => 'name: The name field is required.',
                    'source' => ['pointer' => '/data/attributes/name'],
                ],
            ],
        ];

        $jsonResponse = new JsonResponse($responseData, 422);
        $jsonResponse->header('Content-Type', 'application/json'); // Note: application/json, not vnd.api+json
        $response = new TestResponse($jsonResponse);

        // Should pass with BaseErrorHandler format
        $this->assertHasValidationErrors($response, ['email', 'name']);
    }

    public function test_assertions_work_with_base_error_handler_format_partial_match()
    {
        // Mock response with BaseErrorHandler JSON:API format
        $responseData = [
            'meta' => [
                'message' => 'Validation Failed',
                'status' => 422,
            ],
            'errors' => [
                [
                    'status' => '422',
                    'title' => 'Validation Error',
                    'detail' => 'email: The email field must be a valid email address.',
                    'source' => ['pointer' => '/data/attributes/email'],
                ],
                [
                    'status' => '422',
                    'title' => 'Validation Error',
                    'detail' => 'password: The password field must be at least 8 characters.',
                    'source' => ['pointer' => '/data/attributes/password'],
                ],
            ],
        ];

        $jsonResponse = new JsonResponse($responseData, 422);
        $jsonResponse->header('Content-Type', 'application/json');
        $response = new TestResponse($jsonResponse);

        // Should pass when checking for subset of fields
        $this->assertHasValidationErrors($response, ['email']);
        $this->assertHasValidationErrors($response, ['password']);
        $this->assertHasValidationErrors($response, ['email', 'password']);
    }

    public function test_assertions_fail_appropriately_with_base_error_handler_format()
    {
        $responseData = [
            'meta' => [
                'message' => 'Validation Failed',
                'status' => 422,
            ],
            'errors' => [
                [
                    'status' => '422',
                    'title' => 'Validation Error',
                    'detail' => 'email: The email field is required.',
                    'source' => ['pointer' => '/data/attributes/email'],
                ],
            ],
        ];

        $jsonResponse = new JsonResponse($responseData, 422);
        $jsonResponse->header('Content-Type', 'application/json');
        $response = new TestResponse($jsonResponse);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Missing validation error for field: missing_field');

        $this->assertHasValidationErrors($response, ['email', 'missing_field']);
    }

    public function test_assertions_handle_multiple_errors_per_field_base_error_handler()
    {
        // Test when BaseErrorHandler creates multiple error objects for same field
        $responseData = [
            'meta' => [
                'message' => 'Validation Failed',
                'status' => 422,
            ],
            'errors' => [
                [
                    'status' => '422',
                    'title' => 'Validation Error',
                    'detail' => 'password: The password field is required.',
                    'source' => ['pointer' => '/data/attributes/password'],
                ],
                [
                    'status' => '422',
                    'title' => 'Validation Error',
                    'detail' => 'password: The password field must be at least 8 characters.',
                    'source' => ['pointer' => '/data/attributes/password'],
                ],
                [
                    'status' => '422',
                    'title' => 'Validation Error',
                    'detail' => 'password: The password field confirmation does not match.',
                    'source' => ['pointer' => '/data/attributes/password'],
                ],
            ],
        ];

        $jsonResponse = new JsonResponse($responseData, 422);
        $jsonResponse->header('Content-Type', 'application/json');
        $response = new TestResponse($jsonResponse);

        // Should still work with multiple errors for same field
        $this->assertHasValidationErrors($response, ['password']);
    }

    public function test_content_type_validation_accepts_both_formats()
    {
        $responseData = [
            'errors' => [
                [
                    'detail' => 'The field is required.',
                    'source' => ['pointer' => '/data/attributes/email'],
                ],
            ],
        ];

        // Test with standard JSON:API Content-Type
        $jsonResponse1 = new JsonResponse($responseData, 422);
        $jsonResponse1->header('Content-Type', 'application/vnd.api+json');
        $response1 = new TestResponse($jsonResponse1);
        $this->assertHasValidationErrors($response1, ['email']);

        // Test with regular JSON Content-Type (BaseErrorHandler)
        $jsonResponse2 = new JsonResponse($responseData, 422);
        $jsonResponse2->header('Content-Type', 'application/json');
        $response2 = new TestResponse($jsonResponse2);
        $this->assertHasValidationErrors($response2, ['email']);
    }
}
