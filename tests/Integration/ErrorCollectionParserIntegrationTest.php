<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Integration;

use Exception;
use NuiMarkets\LaravelSharedUtils\Support\ErrorCollectionParser;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;
use Swis\JsonApi\Client\ErrorCollection;
use Swis\JsonApi\Client\Parsers\ErrorParser;
use Swis\JsonApi\Client\Parsers\LinksParser;
use Swis\JsonApi\Client\Parsers\MetaParser;

/**
 * Integration tests for ErrorCollectionParser end-to-end functionality.
 * 
 * Tests complete error parsing workflows with the actual Swis JSON API client,
 * including real-world error scenarios and edge cases.
 */
class ErrorCollectionParserIntegrationTest extends TestCase
{
    private ErrorCollectionParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Setup full parser with proper Swis dependencies
        $metaParser = new MetaParser();
        $linksParser = new LinksParser($metaParser);
        $errorParser = new ErrorParser($linksParser, $metaParser);
        $this->parser = new ErrorCollectionParser($errorParser);
    }

    /** @test */
    public function test_end_to_end_parsing_workflow_with_valid_json_api_errors()
    {
        $fixtures = $this->loadFixtures('valid-json-api-errors.json');
        
        foreach ($fixtures as $fixture) {
            $response = $fixture['response'];
            
            try {
                $errorCollection = $this->parser->parse($response);
                
                // Verify we get a proper ErrorCollection
                $this->assertInstanceOf(ErrorCollection::class, $errorCollection);
                $this->assertGreaterThan(0, $errorCollection->count());
                
                // Verify errors maintain their structure
                foreach ($errorCollection as $error) {
                    $this->assertInstanceOf(\Swis\JsonApi\Client\Error::class, $error);
                }
                
            } catch (\Exception $e) {
                $this->fail("Failed to parse fixture '{$fixture['name']}': " . $e->getMessage());
            }
        }
    }
    
    /** @test */
    public function test_end_to_end_parsing_workflow_with_malformed_errors()
    {
        $fixtures = $this->loadFixtures('malformed-error-responses.json');
        
        foreach ($fixtures as $fixture) {
            $response = $fixture['response'];
            
            try {
                $errorCollection = $this->parser->parse($response);
                
                // Should successfully parse and normalize malformed errors
                $this->assertInstanceOf(ErrorCollection::class, $errorCollection);
                $this->assertGreaterThan(0, $errorCollection->count());
                
                // All errors should be valid Error objects
                foreach ($errorCollection as $error) {
                    $this->assertInstanceOf(\Swis\JsonApi\Client\Error::class, $error);
                    
                    // Should have at least a detail field
                    $this->assertNotEmpty(
                        $error->getDetail(),
                        "Fixture '{$fixture['name']}' should produce error with detail"
                    );
                }
                
            } catch (\Exception $e) {
                $this->fail("Failed to parse malformed fixture '{$fixture['name']}': " . $e->getMessage());
            }
        }
    }
    
    /** @test */
    public function test_end_to_end_parsing_workflow_with_edge_cases()
    {
        $fixtures = $this->loadFixtures('edge-case-responses.json');
        
        foreach ($fixtures as $fixture) {
            $response = $fixture['response'];
            
            try {
                $errorCollection = $this->parser->parse($response);
                
                // Should handle all edge cases gracefully
                $this->assertInstanceOf(ErrorCollection::class, $errorCollection);
                
                // Even edge cases should produce at least one error
                $this->assertGreaterThan(
                    0,
                    $errorCollection->count(),
                    "Fixture '{$fixture['name']}' should produce at least one error"
                );
                
            } catch (\Exception $e) {
                $this->fail("Failed to parse edge case fixture '{$fixture['name']}': " . $e->getMessage());
            }
        }
    }
    
    /** @test */
    public function test_string_input_end_to_end()
    {
        $stringError = "Database connection failed";
        
        $errorCollection = $this->parser->parse($stringError);
        
        $this->assertInstanceOf(ErrorCollection::class, $errorCollection);
        $this->assertCount(1, $errorCollection);
        
        $error = $errorCollection->first();
        $this->assertEquals($stringError, $error->getDetail());
    }
    
    /** @test */
    public function test_exception_input_end_to_end()
    {
        $exception = new Exception("Test error message", 500);
        
        $errorCollection = $this->parser->parse($exception);
        
        $this->assertInstanceOf(ErrorCollection::class, $errorCollection);
        $this->assertCount(1, $errorCollection);
        
        $error = $errorCollection->first();
        $this->assertEquals("Test error message", $error->getDetail());
        $this->assertEquals("Exception", $error->getTitle());
        $this->assertEquals("500", $error->getCode());
        
        // Should have metadata
        $meta = $error->getMeta();
        $this->assertNotNull($meta);
        $this->assertArrayHasKey('file', $meta->toArray());
        $this->assertArrayHasKey('line', $meta->toArray());
        $this->assertArrayHasKey('trace', $meta->toArray());
    }
    
    /** @test */
    public function test_complex_nested_error_structures()
    {
        $complexError = [
            'status' => 'error',
            'data' => [
                'validation_errors' => [
                    'email' => 'Invalid email format',
                    'password' => 'Password too weak'
                ],
                'system_error' => 'Database unavailable'
            ],
            'metadata' => [
                'request_id' => 'req_123456',
                'timestamp' => '2025-09-10T05:45:00Z'
            ]
        ];
        
        $errorCollection = $this->parser->parse($complexError);
        
        // Should normalize complex structure into valid ErrorCollection
        $this->assertInstanceOf(ErrorCollection::class, $errorCollection);
        $this->assertGreaterThan(0, $errorCollection->count());
        
        foreach ($errorCollection as $error) {
            $this->assertInstanceOf(\Swis\JsonApi\Client\Error::class, $error);
        }
    }
    
    /** @test */
    public function test_large_error_dataset_performance()
    {
        // Create a large array of errors to test performance
        $largeErrorSet = [
            'errors' => []
        ];
        
        for ($i = 0; $i < 100; $i++) {
            $largeErrorSet['errors'][] = "Error number {$i}";
        }
        
        $startTime = microtime(true);
        
        $errorCollection = $this->parser->parse($largeErrorSet);
        
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        
        // Should complete within reasonable time (1 second)
        $this->assertLessThan(1.0, $duration, 'Large error set parsing should complete within 1 second');
        
        // Should produce correct number of errors
        $this->assertInstanceOf(ErrorCollection::class, $errorCollection);
        $this->assertCount(100, $errorCollection);
    }
    
    /** @test */
    public function test_backward_compatibility_with_base_parser()
    {
        // Test that enhanced parser doesn't break existing Swis functionality
        $validSwisError = [
            'errors' => [
                [
                    'status' => '422',
                    'title' => 'Validation Error',
                    'detail' => 'The name field is required',
                    'source' => ['pointer' => '/data/attributes/name']
                ]
            ]
        ];
        
        $errorCollection = $this->parser->parse($validSwisError);
        
        // Should work exactly like base parser
        $this->assertInstanceOf(ErrorCollection::class, $errorCollection);
        $this->assertCount(1, $errorCollection);
        
        $error = $errorCollection->first();
        $this->assertEquals('422', $error->getStatus());
        $this->assertEquals('Validation Error', $error->getTitle());
        $this->assertEquals('The name field is required', $error->getDetail());
        
        // Source should be properly parsed
        $source = $error->getSource();
        $this->assertNotNull($source);
        $this->assertEquals('/data/attributes/name', $source->getPointer());
    }
    
    /** @test */
    public function test_error_collection_basic_functionality()
    {
        $inputError = [
            'errors' => [
                [
                    'title' => 'Test Error',
                    'detail' => 'This is a test error',
                    'code' => 'TEST_001'
                ]
            ]
        ];
        
        $errorCollection = $this->parser->parse($inputError);
        
        // Test that we get a valid ErrorCollection with accessible data
        $this->assertInstanceOf(ErrorCollection::class, $errorCollection);
        $this->assertCount(1, $errorCollection);
        
        $error = $errorCollection->first();
        $this->assertEquals('Test Error', $error->getTitle());
        $this->assertEquals('This is a test error', $error->getDetail());
        $this->assertEquals('TEST_001', $error->getCode());
    }
    
    /**
     * Load test fixtures from JSON files
     */
    private function loadFixtures(string $filename): array
    {
        $path = __DIR__ . '/Fixtures/' . $filename;
        
        if (!file_exists($path)) {
            $this->fail("Fixture file not found: {$path}");
        }
        
        $content = file_get_contents($path);
        $fixtures = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->fail("Invalid JSON in fixture file {$filename}: " . json_last_error_msg());
        }
        
        return $fixtures;
    }
}