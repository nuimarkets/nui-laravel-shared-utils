<?php

namespace NuiMarkets\LaravelSharedUtils\Testing;

trait JsonApiAssertions
{
    /**
     * Assert that the response has validation errors for the given fields in JSON API format
     *
     * @param  \Illuminate\Testing\TestResponse  $response
     * @param  array  $fields
     * @return void
     */
    protected function assertHasValidationErrors($response, array $fields): void
    {
        $response->assertStatus(422);
        
        $data = $response->json();
        
        // Ensure we have the expected JSON API error structure
        $this->assertArrayHasKey('errors', $data, 'Response should have errors array');
        $this->assertIsArray($data['errors'], 'Errors should be an array');
        
        // Extract field names from JSON API error format
        $errorFields = [];
        foreach ($data['errors'] as $error) {
            if (isset($error['source']['pointer'])) {
                // Convert "/data/attributes/fieldName" to "fieldName"
                $pointer = $error['source']['pointer'];
                if (preg_match('#/data/attributes/(.+)#', $pointer, $matches)) {
                    $errorFields[] = $matches[1];
                }
            }
        }
        
        // Check that all expected fields have validation errors
        foreach ($fields as $field) {
            $this->assertContains(
                $field, 
                $errorFields, 
                "Expected validation error for field '{$field}' but it was not found. Available error fields: " . implode(', ', $errorFields)
            );
        }
    }
}