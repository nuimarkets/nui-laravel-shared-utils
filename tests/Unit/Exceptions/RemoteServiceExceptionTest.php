<?php

namespace Nuimarkets\LaravelSharedUtils\Tests\Unit\Exceptions;

use Nuimarkets\LaravelSharedUtils\Exceptions\RemoteServiceException;
use Nuimarkets\LaravelSharedUtils\Tests\TestCase;

class RemoteServiceExceptionTest extends TestCase
{
    public function test_can_create_exception_with_message()
    {
        $exception = new RemoteServiceException('Test error message');

        $this->assertInstanceOf(RemoteServiceException::class, $exception);
        $this->assertEquals('Test error message', $exception->getMessage());
        $this->assertEquals(0, $exception->getCode());
    }

    public function test_can_create_exception_with_message_and_code()
    {
        $exception = new RemoteServiceException('Server error', 500);

        $this->assertEquals('Server error', $exception->getMessage());
        $this->assertEquals(500, $exception->getCode());
    }

    public function test_can_create_exception_with_previous_exception()
    {
        $previousException = new \Exception('Original error');
        $exception = new RemoteServiceException('Wrapped error', 500, $previousException);

        $this->assertEquals('Wrapped error', $exception->getMessage());
        $this->assertEquals(500, $exception->getCode());
        $this->assertSame($previousException, $exception->getPrevious());
    }

    public function test_extends_exception_class()
    {
        $exception = new RemoteServiceException('Test');

        $this->assertInstanceOf(\Exception::class, $exception);
    }

    public function test_typical_usage_scenarios()
    {
        // Scenario 1: Validation exception wrapping
        $validationException = new \Exception('Invalid JSON format');
        $exception = new RemoteServiceException('Remote service returned invalid response format', 0, $validationException);

        $this->assertEquals('Remote service returned invalid response format', $exception->getMessage());
        $this->assertSame($validationException, $exception->getPrevious());

        // Scenario 2: Network timeout
        $exception = new RemoteServiceException('Error getting response from remote server: Connection timeout', 500);

        $this->assertEquals('Error getting response from remote server: Connection timeout', $exception->getMessage());
        $this->assertEquals(500, $exception->getCode());

        // Scenario 3: API error response
        $exception = new RemoteServiceException('Error calling service. Returned: {"error": "Unauthorized"}');

        $this->assertStringContainsString('Unauthorized', $exception->getMessage());
    }
}
