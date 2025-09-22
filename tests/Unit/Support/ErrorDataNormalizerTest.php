<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\Support;

use Exception;
use NuiMarkets\LaravelSharedUtils\Support\ErrorDataNormalizer;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;

/**
 * Unit tests for ErrorDataNormalizer.
 *
 * Tests the normalization logic in isolation to ensure proper
 * transformation of various input types to JSON:API format.
 */
class ErrorDataNormalizerTest extends TestCase
{
    private ErrorDataNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new ErrorDataNormalizer();
    }

    /** @test */
    public function test_normalizes_null_input()
    {
        $result = $this->normalizer->normalize(null);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertEquals([['detail' => 'No error data provided']], $result['errors']);
    }

    /** @test */
    public function test_normalizes_string_input()
    {
        $result = $this->normalizer->normalize('Simple error message');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertEquals([['detail' => 'Simple error message']], $result['errors']);
    }

    /** @test */
    public function test_normalizes_empty_string()
    {
        $result = $this->normalizer->normalize('');

        $this->assertEquals([['detail' => '']], $result['errors']);
    }

    /** @test */
    public function test_normalizes_boolean_false()
    {
        $result = $this->normalizer->normalize(false);

        $this->assertEquals([['detail' => 'false']], $result['errors']);
    }

    /** @test */
    public function test_normalizes_boolean_true()
    {
        $result = $this->normalizer->normalize(true);

        $this->assertEquals([['detail' => '1']], $result['errors']);
    }

    /** @test */
    public function test_normalizes_integer()
    {
        $result = $this->normalizer->normalize(500);

        $this->assertEquals([['detail' => '500']], $result['errors']);
    }

    /** @test */
    public function test_normalizes_exception()
    {
        $exception = new Exception('Test error', 500);
        $result = $this->normalizer->normalize($exception);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertCount(1, $result['errors']);

        $error = $result['errors'][0];
        $this->assertEquals('Exception', $error['title']);
        $this->assertEquals('Test error', $error['detail']);
        $this->assertEquals('500', $error['code']);
        $this->assertArrayHasKey('meta', $error);
        $this->assertArrayHasKey('file', $error['meta']);
        $this->assertArrayHasKey('line', $error['meta']);
        $this->assertArrayHasKey('trace', $error['meta']);
    }

    /** @test */
    public function test_normalizes_array_with_message_key()
    {
        $input = ['message' => 'Error occurred'];
        $result = $this->normalizer->normalize($input);

        $this->assertEquals([['detail' => 'Error occurred']], $result['errors']);
    }

    /** @test */
    public function test_normalizes_array_with_error_key()
    {
        $input = ['error' => 'Something went wrong'];
        $result = $this->normalizer->normalize($input);

        $this->assertEquals([['detail' => 'Something went wrong']], $result['errors']);
    }

    /** @test */
    public function test_normalizes_laravel_validation_errors()
    {
        $input = [
            'errors' => [
                'email' => ['Email is required', 'Email must be valid'],
                'name' => ['Name is required']
            ]
        ];

        $result = $this->normalizer->normalize($input);

        $this->assertCount(3, $result['errors']);

        // Check first email error
        $this->assertEquals('Email is required', $result['errors'][0]['detail']);
        $this->assertEquals(['pointer' => '/data/attributes/email'], $result['errors'][0]['source']);

        // Check second email error
        $this->assertEquals('Email must be valid', $result['errors'][1]['detail']);
        $this->assertEquals(['pointer' => '/data/attributes/email'], $result['errors'][1]['source']);

        // Check name error
        $this->assertEquals('Name is required', $result['errors'][2]['detail']);
        $this->assertEquals(['pointer' => '/data/attributes/name'], $result['errors'][2]['source']);
    }

    /** @test */
    public function test_normalizes_malformed_errors_key()
    {
        $input = ['errors' => 'Database connection failed'];
        $result = $this->normalizer->normalize($input);

        $this->assertEquals([['detail' => 'Database connection failed']], $result['errors']);
    }

    /** @test */
    public function test_normalizes_null_errors_key()
    {
        $input = ['errors' => null];
        $result = $this->normalizer->normalize($input);

        $this->assertEquals([['detail' => 'Error data was null']], $result['errors']);
    }

    /** @test */
    public function test_normalizes_array_of_scalars()
    {
        $input = ['errors' => ['Error 1', 'Error 2']];
        $result = $this->normalizer->normalize($input);

        $this->assertCount(2, $result['errors']);
        $this->assertEquals([['detail' => 'Error 1'], ['detail' => 'Error 2']], $result['errors']);
    }

    /** @test */
    public function test_all_outputs_use_arrays_not_objects()
    {
        $testCases = [
            'string' => 'Simple error',
            'exception' => new Exception('Test', 500),
            'array_message' => ['message' => 'Error'],
            'laravel_validation' => ['errors' => ['field' => ['Error message']]],
            'malformed' => ['errors' => 'String error'],
        ];

        foreach ($testCases as $name => $input) {
            $result = $this->normalizer->normalize($input);

            $this->assertIsArray($result, "Case {$name}: Result should be array");
            $this->assertIsArray($result['errors'], "Case {$name}: Errors should be array");

            foreach ($result['errors'] as $index => $error) {
                $this->assertIsArray($error, "Case {$name}[{$index}]: Error should be array, not object");

                // Check nested structures are also arrays
                if (isset($error['meta'])) {
                    $this->assertIsArray($error['meta'], "Case {$name}[{$index}]: Meta should be array, not object");
                }
                if (isset($error['source'])) {
                    $this->assertIsArray($error['source'], "Case {$name}[{$index}]: Source should be array, not object");
                }
            }
        }
    }
}