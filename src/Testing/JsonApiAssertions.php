<?php

namespace NuiMarkets\LaravelSharedUtils\Testing;

use Illuminate\Testing\TestResponse;

trait JsonApiAssertions
{
    /**
     * Assert that the response has validation errors for the given fields in JSON API format
     *
     * This method validates that:
     * - Response has 422 status (Unprocessable Entity)
     * - Response has proper JSON:API Content-Type header
     * - Response contains valid JSON:API error structure
     * - All specified fields have corresponding validation errors
     */
    protected function assertHasValidationErrors(TestResponse $response, array $fields): void
    {
        // Must be a validation failure
        $response->assertStatus(422);

        // Validate JSON:API content type
        $contentType = $response->headers->get('Content-Type');
        $this->assertNotNull($contentType, 'Missing Content-Type header');

        // Parse and validate JSON:API error structure
        $json = $response->json();
        $this->assertIsArray($json, 'Response is not valid JSON.');
        $this->assertArrayHasKey('errors', $json, 'JSON:API "errors" member missing.');
        $this->assertIsArray($json['errors'], 'JSON:API "errors" must be an array.');

        // Extract field names from various error sources
        $fieldsInErrors = $this->extractFieldsFromErrors($json['errors'], $fields);

        // Verify all expected fields have validation errors
        foreach ($fields as $field) {
            $this->assertContains(
                $field,
                $fieldsInErrors,
                "Missing validation error for field: {$field}. Available error fields: ".implode(', ', $fieldsInErrors)
            );
        }
    }

    /**
     * Extract field names from JSON:API errors using multiple sources
     */
    private function extractFieldsFromErrors(array $errors, array $expectedFields): array
    {
        $fieldsInErrors = [];

        foreach ($errors as $error) {
            if (! is_array($error)) {
                continue;
            }

            // Extract from source.parameter (query parameters)
            $fieldsInErrors = array_merge($fieldsInErrors, $this->extractFromSourceParameter($error));

            // Extract from source.pointer (JSON pointers)
            $fieldsInErrors = array_merge($fieldsInErrors, $this->extractFromSourcePointer($error));

            // Extract from meta.field
            $fieldsInErrors = array_merge($fieldsInErrors, $this->extractFromMetaField($error));

            // Fallback: search for field names in error detail
            $fieldsInErrors = array_merge($fieldsInErrors, $this->extractFromDetailFallback($error, $expectedFields));
        }

        return array_values(array_unique($fieldsInErrors));
    }

    /**
     * Extract field name from source.parameter
     */
    private function extractFromSourceParameter(array $error): array
    {
        $source = $error['source'] ?? null;
        if (is_array($source) && ! empty($source['parameter']) && is_string($source['parameter'])) {
            return [$source['parameter']];
        }

        return [];
    }

    /**
     * Extract field name from source.pointer, handling nested paths
     */
    private function extractFromSourcePointer(array $error): array
    {
        $source = $error['source'] ?? null;
        if (! is_array($source) || empty($source['pointer']) || ! is_string($source['pointer'])) {
            return [];
        }

        $pointer = $source['pointer'];

        // Handle various JSON pointer patterns
        if (preg_match('~^/data/attributes/?(.*)$~', $pointer, $matches)) {
            $path = $matches[1];
            if ($path === '') {
                return ['data'];
            }

            // Convert nested paths like "items/0/productId" to "items.0.productId"
            return [str_replace('/', '.', $path)];
        }

        if ($pointer === '/data') {
            return ['data'];
        }

        return [];
    }

    /**
     * Extract field name from meta.field
     */
    private function extractFromMetaField(array $error): array
    {
        if (! empty($error['meta']['field']) && is_string($error['meta']['field'])) {
            return [$error['meta']['field']];
        }

        return [];
    }

    /**
     * Fallback: search for field names in error detail text
     */
    private function extractFromDetailFallback(array $error, array $expectedFields): array
    {
        $fields = [];
        if (! empty($error['detail']) && is_string($error['detail'])) {
            foreach ($expectedFields as $field) {
                if (stripos($error['detail'], $field) !== false) {
                    $fields[] = $field;
                }
            }
        }

        return $fields;
    }
}
