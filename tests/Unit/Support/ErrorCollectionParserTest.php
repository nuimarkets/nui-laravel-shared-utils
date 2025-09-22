<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\Support;

use Exception;
use NuiMarkets\LaravelSharedUtils\Support\ErrorCollectionParser;
use NuiMarkets\LaravelSharedUtils\Support\ErrorDataNormalizer;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;
use Swis\JsonApi\Client\ErrorCollection;
use Swis\JsonApi\Client\Parsers\ErrorParser;
use Swis\JsonApi\Client\Parsers\LinksParser;
use Swis\JsonApi\Client\Parsers\MetaParser;

/**
 * End-to-end tests for ErrorCollectionParser.
 *
 * These tests verify that the parser correctly integrates with the
 * ErrorDataNormalizer and produces valid ErrorCollection objects.
 */
class ErrorCollectionParserTest extends TestCase
{
    private ErrorCollectionParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $metaParser = new MetaParser();
        $linksParser = new LinksParser($metaParser);
        $errorParser = new ErrorParser($linksParser, $metaParser);
        $this->parser = new ErrorCollectionParser($errorParser);
    }

    /** @test */
    public function test_can_instantiate_error_collection_parser()
    {
        $this->assertInstanceOf(ErrorCollectionParser::class, $this->parser);
        $this->assertInstanceOf(\Swis\JsonApi\Client\Parsers\ErrorCollectionParser::class, $this->parser);
    }

    /** @test */
    public function test_extends_base_error_collection_parser()
    {
        $this->assertInstanceOf(\Swis\JsonApi\Client\Parsers\ErrorCollectionParser::class, $this->parser);
        $this->assertTrue(method_exists($this->parser, 'parse'));
    }

    /** @test */
    public function test_can_inject_custom_normalizer()
    {
        $customNormalizer = new ErrorDataNormalizer();
        $metaParser = new MetaParser();
        $linksParser = new LinksParser($metaParser);
        $errorParser = new ErrorParser($linksParser, $metaParser);

        $parser = new ErrorCollectionParser($errorParser, $customNormalizer);

        $this->assertInstanceOf(ErrorCollectionParser::class, $parser);
    }

    /** @test */
    public function test_parses_string_input_to_error_collection()
    {
        $result = $this->parser->parse('Simple error message');

        $this->assertInstanceOf(ErrorCollection::class, $result);
        $this->assertCount(1, $result);

        $error = $result->first();
        $this->assertEquals('Simple error message', $error->getDetail());
    }

    /** @test */
    public function test_parses_exception_to_error_collection()
    {
        $exception = new Exception('Test error', 500);
        $result = $this->parser->parse($exception);

        $this->assertInstanceOf(ErrorCollection::class, $result);
        $this->assertCount(1, $result);

        $error = $result->first();
        $this->assertEquals('Test error', $error->getDetail());
        $this->assertEquals('Exception', $error->getTitle());
        $this->assertEquals('500', $error->getCode());

        // Check meta data
        $meta = $error->getMeta();
        $this->assertNotNull($meta);
        $this->assertNotNull($meta->file);
        $this->assertNotNull($meta->line);
        $this->assertNotNull($meta->trace);
    }

    /** @test */
    public function test_parses_null_input_to_error_collection()
    {
        $result = $this->parser->parse(null);

        $this->assertInstanceOf(ErrorCollection::class, $result);
        $this->assertCount(1, $result);

        $error = $result->first();
        $this->assertEquals('No error data provided', $error->getDetail());
    }

    /** @test */
    public function test_parses_boolean_false_to_error_collection()
    {
        $result = $this->parser->parse(false);

        $this->assertInstanceOf(ErrorCollection::class, $result);
        $this->assertCount(1, $result);

        $error = $result->first();
        $this->assertEquals('false', $error->getDetail());
    }

    /** @test */
    public function test_parses_integer_to_error_collection()
    {
        $result = $this->parser->parse(500);

        $this->assertInstanceOf(ErrorCollection::class, $result);
        $this->assertCount(1, $result);

        $error = $result->first();
        $this->assertEquals('500', $error->getDetail());
    }

    /** @test */
    public function test_parses_array_with_message_key()
    {
        $input = ['message' => 'Error occurred'];
        $result = $this->parser->parse($input);

        $this->assertInstanceOf(ErrorCollection::class, $result);
        $this->assertCount(1, $result);

        $error = $result->first();
        $this->assertEquals('Error occurred', $error->getDetail());
    }

    /** @test */
    public function test_parses_laravel_validation_errors()
    {
        $input = [
            'errors' => [
                'email' => ['Email is required', 'Email must be valid'],
                'name' => ['Name is required']
            ]
        ];

        $result = $this->parser->parse($input);

        $this->assertInstanceOf(ErrorCollection::class, $result);
        $this->assertCount(3, $result);

        $errors = $result->toArray();

        // Check first email error
        $this->assertEquals('Email is required', $errors[0]->getDetail());
        $this->assertEquals('/data/attributes/email', $errors[0]->getSource()->getPointer());

        // Check second email error
        $this->assertEquals('Email must be valid', $errors[1]->getDetail());
        $this->assertEquals('/data/attributes/email', $errors[1]->getSource()->getPointer());

        // Check name error
        $this->assertEquals('Name is required', $errors[2]->getDetail());
        $this->assertEquals('/data/attributes/name', $errors[2]->getSource()->getPointer());
    }

    /** @test */
    public function test_parses_malformed_errors_key()
    {
        $input = ['errors' => 'Database connection failed'];
        $result = $this->parser->parse($input);

        $this->assertInstanceOf(ErrorCollection::class, $result);
        $this->assertCount(1, $result);

        $error = $result->first();
        $this->assertEquals('Database connection failed', $error->getDetail());
    }

    /** @test */
    public function test_parses_array_of_string_errors()
    {
        $input = ['errors' => ['Error 1', 'Error 2', 'Error 3']];
        $result = $this->parser->parse($input);

        $this->assertInstanceOf(ErrorCollection::class, $result);
        $this->assertCount(3, $result);

        $errors = $result->toArray();
        $this->assertEquals('Error 1', $errors[0]->getDetail());
        $this->assertEquals('Error 2', $errors[1]->getDetail());
        $this->assertEquals('Error 3', $errors[2]->getDetail());
    }

    /** @test */
    public function test_parses_valid_json_api_errors_unchanged()
    {
        // Test with proper JSON:API structure with 'errors' wrapper
        $input = [
            'errors' => [
                [
                    'detail' => 'Already valid error',
                    'title' => 'Validation Error',
                    'status' => '422'
                ]
            ]
        ];

        $result = $this->parser->parse($input);

        $this->assertInstanceOf(ErrorCollection::class, $result);
        $this->assertCount(1, $result);

        $error = $result->first();
        $this->assertEquals('Already valid error', $error->getDetail());
        $this->assertEquals('Validation Error', $error->getTitle());
        $this->assertEquals('422', $error->getStatus());
    }

    /** @test */
    public function test_integration_with_actual_json_api_structure()
    {
        // Test that we can handle a real-world JSON:API error response
        $realWorldInput = [
            'errors' => [
                [
                    'id' => 'error-1',
                    'status' => '422',
                    'code' => 'VALIDATION_FAILED',
                    'title' => 'Validation Error',
                    'detail' => 'The email field is required.',
                    'source' => ['pointer' => '/data/attributes/email'],
                    'meta' => ['timestamp' => '2024-01-01T00:00:00Z']
                ]
            ]
        ];

        $result = $this->parser->parse($realWorldInput);

        $this->assertInstanceOf(ErrorCollection::class, $result);
        $this->assertCount(1, $result);

        $error = $result->first();
        $this->assertEquals('The email field is required.', $error->getDetail());
        $this->assertEquals('Validation Error', $error->getTitle());
        $this->assertEquals('422', $error->getStatus());
        $this->assertEquals('VALIDATION_FAILED', $error->getCode());
        $this->assertEquals('error-1', $error->getId());
        $this->assertEquals('/data/attributes/email', $error->getSource()->getPointer());

        $meta = $error->getMeta();
        $this->assertEquals('2024-01-01T00:00:00Z', $meta->timestamp);
    }
}