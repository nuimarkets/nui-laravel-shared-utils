<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Utils;

use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;

/**
 * Shared test helpers for creating JSON:API formatted responses.
 *
 * Provides factory methods for creating JSON:API error responses and validation
 * responses to reduce duplication across tests.
 */
trait JsonApiResponseTestHelpers
{
    /**
     * Create a JSON:API validation error response.
     *
     * @param  array  $fieldErrors  Array of field names that have errors, or field => message pairs
     * @param  int  $statusCode  HTTP status code
     * @param  string  $contentType  Content-Type header value
     */
    protected function createJsonApiValidationResponse(
        array $fieldErrors,
        int $statusCode = 422,
        string $contentType = 'application/vnd.api+json'
    ): TestResponse {
        $errors = [];

        foreach ($fieldErrors as $key => $value) {
            // Support both ['field1', 'field2'] and ['field1' => 'Error message']
            if (is_int($key)) {
                $field = $value;
                $message = "The {$field} field is required.";
            } else {
                $field = $key;
                $message = $value;
            }

            $errors[] = [
                'detail' => $message,
                'source' => [
                    'pointer' => "/data/attributes/{$field}",
                ],
            ];
        }

        return $this->createJsonApiErrorResponse($errors, $statusCode, $contentType);
    }

    /**
     * Create a JSON:API error response with custom error objects.
     *
     * @param  array  $errors  Array of error objects
     * @param  int  $statusCode  HTTP status code
     * @param  string  $contentType  Content-Type header value
     */
    protected function createJsonApiErrorResponse(
        array $errors,
        int $statusCode = 422,
        string $contentType = 'application/vnd.api+json'
    ): TestResponse {
        $responseData = ['errors' => $errors];

        $jsonResponse = new JsonResponse($responseData, $statusCode);
        $jsonResponse->header('Content-Type', $contentType);

        return new TestResponse($jsonResponse);
    }

    /**
     * Create a BaseErrorHandler-style JSON:API validation response.
     *
     * This matches the format produced by BaseErrorHandler for validation errors.
     *
     * @param  array  $fieldErrors  Array of field => message pairs
     * @param  int  $statusCode  HTTP status code
     */
    protected function createBaseErrorHandlerValidationResponse(
        array $fieldErrors,
        int $statusCode = 422
    ): TestResponse {
        $errors = [];

        foreach ($fieldErrors as $field => $message) {
            // Support both ['field1', 'field2'] and ['field1' => 'Error message']
            if (is_int($field)) {
                $field = $message;
                $message = "The {$field} field is required.";
            }

            $errors[] = [
                'status' => (string) $statusCode,
                'title' => 'Validation Error',
                'detail' => "{$field}: {$message}",
                'source' => ['pointer' => "/data/attributes/{$field}"],
            ];
        }

        $responseData = [
            'meta' => [
                'message' => 'Validation Failed',
                'status' => $statusCode,
            ],
            'errors' => $errors,
        ];

        $jsonResponse = new JsonResponse($responseData, $statusCode);
        $jsonResponse->header('Content-Type', 'application/json');

        return new TestResponse($jsonResponse);
    }

    /**
     * Create a simple JSON:API error response with a single error.
     *
     * @param  string  $field  Field name
     * @param  string  $message  Error message
     * @param  int  $statusCode  HTTP status code
     * @param  string  $contentType  Content-Type header value
     */
    protected function createSingleFieldErrorResponse(
        string $field,
        string $message = 'The field is required.',
        int $statusCode = 422,
        string $contentType = 'application/vnd.api+json'
    ): TestResponse {
        return $this->createJsonApiErrorResponse([
            [
                'detail' => $message,
                'source' => [
                    'pointer' => "/data/attributes/{$field}",
                ],
            ],
        ], $statusCode, $contentType);
    }

    /**
     * Create a JSON:API response with nested field errors.
     *
     * @param  array  $nestedFields  Array of nested field paths (e.g., ['data.items.0.productId'])
     * @param  int  $statusCode  HTTP status code
     */
    protected function createNestedFieldErrorResponse(
        array $nestedFields,
        int $statusCode = 422
    ): TestResponse {
        $errors = [];

        foreach ($nestedFields as $key => $value) {
            if (is_int($key)) {
                $field = $value;
                $message = "The {$field} field is required.";
            } else {
                $field = $key;
                $message = $value;
            }

            $errors[] = [
                'detail' => $message,
                'source' => [
                    'pointer' => "/data/attributes/{$field}",
                ],
            ];
        }

        return $this->createJsonApiErrorResponse($errors, $statusCode);
    }

    /**
     * Create a success response (non-error).
     *
     * @param  array  $data  Response data
     * @param  int  $statusCode  HTTP status code
     */
    protected function createJsonApiSuccessResponse(
        array $data = ['success' => true],
        int $statusCode = 200
    ): TestResponse {
        $jsonResponse = new JsonResponse($data, $statusCode);

        return new TestResponse($jsonResponse);
    }

    /**
     * Create a response with missing errors array (for testing edge cases).
     *
     * @param  array  $data  Response data (without 'errors' key)
     * @param  int  $statusCode  HTTP status code
     * @param  string  $contentType  Content-Type header value
     */
    protected function createResponseWithoutErrors(
        array $data = ['message' => 'Validation failed'],
        int $statusCode = 422,
        string $contentType = 'application/vnd.api+json'
    ): TestResponse {
        $jsonResponse = new JsonResponse($data, $statusCode);
        $jsonResponse->header('Content-Type', $contentType);

        return new TestResponse($jsonResponse);
    }

    /**
     * Create a response with multiple errors for the same field.
     *
     * @param  string  $field  Field name
     * @param  array  $messages  Array of error messages
     * @param  int  $statusCode  HTTP status code
     */
    protected function createMultipleErrorsForFieldResponse(
        string $field,
        array $messages,
        int $statusCode = 422
    ): TestResponse {
        $errors = [];

        foreach ($messages as $message) {
            $errors[] = [
                'status' => (string) $statusCode,
                'title' => 'Validation Error',
                'detail' => "{$field}: {$message}",
                'source' => ['pointer' => "/data/attributes/{$field}"],
            ];
        }

        $responseData = [
            'meta' => [
                'message' => 'Validation Failed',
                'status' => $statusCode,
            ],
            'errors' => $errors,
        ];

        $jsonResponse = new JsonResponse($responseData, $statusCode);
        $jsonResponse->header('Content-Type', 'application/json');

        return new TestResponse($jsonResponse);
    }
}
