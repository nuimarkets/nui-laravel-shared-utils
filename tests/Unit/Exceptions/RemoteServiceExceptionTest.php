<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\Exceptions;

use NuiMarkets\LaravelSharedUtils\Exceptions\BaseHttpRequestException;
use NuiMarkets\LaravelSharedUtils\Exceptions\RemoteServiceException;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RemoteServiceExceptionTest extends TestCase
{
    public function test_can_create_exception_with_message()
    {
        $exception = new RemoteServiceException('Test error message');

        $this->assertInstanceOf(RemoteServiceException::class, $exception);
        $this->assertEquals('Test error message', $exception->getMessage());
        // Now defaults to 502 (Bad Gateway) instead of 0
        $this->assertEquals(502, $exception->getStatusCode());
    }

    public function test_can_create_exception_with_message_and_code()
    {
        $exception = new RemoteServiceException('Server error', 503);

        $this->assertEquals('Server error', $exception->getMessage());
        $this->assertEquals(503, $exception->getStatusCode());
    }

    public function test_can_create_exception_with_previous_exception()
    {
        $previousException = new \Exception('Original error');
        $exception = new RemoteServiceException('Wrapped error', 503, $previousException);

        $this->assertEquals('Wrapped error', $exception->getMessage());
        $this->assertEquals(503, $exception->getStatusCode());
        $this->assertSame($previousException, $exception->getPrevious());
    }

    public function test_extends_base_http_request_exception()
    {
        $exception = new RemoteServiceException('Test');

        $this->assertInstanceOf(BaseHttpRequestException::class, $exception);
        $this->assertInstanceOf(HttpException::class, $exception);
        $this->assertInstanceOf(\Exception::class, $exception);
    }

    public function test_supports_tags_and_extra_data()
    {
        $tags = ['service' => 'payment-api', 'environment' => 'production'];
        $extra = ['request_id' => '123-456', 'response_time' => 2.5];

        $exception = new RemoteServiceException(
            'Payment service unavailable',
            503,
            null,
            $tags,
            $extra
        );

        $this->assertEquals($tags, $exception->getTags());
        $this->assertEquals($extra, $exception->getExtra());
    }

    public function test_typical_usage_scenarios()
    {
        // Scenario 1: Bad Gateway - remote service returned an error
        $exception = new RemoteServiceException('Remote API returned error response', 502);

        $this->assertEquals('Remote API returned error response', $exception->getMessage());
        $this->assertEquals(502, $exception->getStatusCode());

        // Scenario 2: Service Unavailable - timeout
        $exception = new RemoteServiceException('Error getting response from remote server: Connection timeout', 503);

        $this->assertEquals('Error getting response from remote server: Connection timeout', $exception->getMessage());
        $this->assertEquals(503, $exception->getStatusCode());

        // Scenario 3: Gateway Timeout
        $exception = new RemoteServiceException('Request to payment gateway timed out', 504);

        $this->assertEquals('Request to payment gateway timed out', $exception->getMessage());
        $this->assertEquals(504, $exception->getStatusCode());

        // Scenario 4: With previous exception for debugging
        $connectionException = new \Exception('Connection refused');
        $exception = new RemoteServiceException(
            'Unable to connect to inventory service',
            503,
            $connectionException,
            ['service' => 'inventory'],
            ['attempt' => 3, 'max_retries' => 3]
        );

        $this->assertSame($connectionException, $exception->getPrevious());
        $this->assertEquals(['service' => 'inventory'], $exception->getTags());
        $this->assertEquals(['attempt' => 3, 'max_retries' => 3], $exception->getExtra());
    }

    public function test_backward_compatibility_with_existing_catch_blocks()
    {
        // Ensure existing code catching generic Exception still works
        $caughtAsException = false;
        $caughtAsHttpException = false;

        try {
            throw new RemoteServiceException('Test error', 502);
        } catch (\Exception $e) {
            $caughtAsException = true;
        }

        try {
            throw new RemoteServiceException('Test error', 502);
        } catch (HttpException $e) {
            $caughtAsHttpException = true;
        }

        $this->assertTrue($caughtAsException, 'Should still be catchable as generic Exception');
        $this->assertTrue($caughtAsHttpException, 'Should be catchable as HttpException');
    }
}
