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
}
