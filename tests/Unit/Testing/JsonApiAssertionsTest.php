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

    public function test_assertHasValidationErrors_passes_with_correct_fields()
    {
        $responseData = [
            'errors' => [
                [
                    'detail' => 'The field is required.',
                    'source' => [
                        'pointer' => '/data/attributes/email'
                    ]
                ],
                [
                    'detail' => 'The name field is required.',
                    'source' => [
                        'pointer' => '/data/attributes/name'
                    ]
                ]
            ]
        ];
        
        $response = new TestResponse(new JsonResponse($responseData, 422));
        
        // This should pass without throwing an exception
        $this->assertHasValidationErrors($response, ['email', 'name']);
        
        // Also test with subset of fields
        $this->assertHasValidationErrors($response, ['email']);
    }

    public function test_assertHasValidationErrors_fails_when_field_not_present()
    {
        $responseData = [
            'errors' => [
                [
                    'detail' => 'The field is required.',
                    'source' => [
                        'pointer' => '/data/attributes/email'
                    ]
                ]
            ]
        ];
        
        $response = new TestResponse(new JsonResponse($responseData, 422));
        
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage("Expected validation error for field 'name' but it was not found");
        
        $this->assertHasValidationErrors($response, ['email', 'name']);
    }

    public function test_assertHasValidationErrors_handles_nested_field_names()
    {
        $responseData = [
            'errors' => [
                [
                    'detail' => 'The data.items.0.productId field is required.',
                    'source' => [
                        'pointer' => '/data/attributes/data.items.0.productId'
                    ]
                ],
                [
                    'detail' => 'The buyerOrgIds.1 field is invalid.',
                    'source' => [
                        'pointer' => '/data/attributes/buyerOrgIds.1'
                    ]
                ]
            ]
        ];
        
        $response = new TestResponse(new JsonResponse($responseData, 422));
        
        $this->assertHasValidationErrors($response, ['data.items.0.productId', 'buyerOrgIds.1']);
    }

    public function test_assertHasValidationErrors_fails_on_wrong_status_code()
    {
        $responseData = ['message' => 'Success'];
        $response = new TestResponse(new JsonResponse($responseData, 200));
        
        $this->expectException(AssertionFailedError::class);
        
        $this->assertHasValidationErrors($response, ['email']);
    }

    public function test_assertHasValidationErrors_fails_when_errors_array_missing()
    {
        $responseData = ['message' => 'Validation failed'];
        $response = new TestResponse(new JsonResponse($responseData, 422));
        
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Response should have errors array');
        
        $this->assertHasValidationErrors($response, ['email']);
    }
}