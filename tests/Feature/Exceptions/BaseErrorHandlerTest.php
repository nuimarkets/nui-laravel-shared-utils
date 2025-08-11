<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Feature\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use NuiMarkets\LaravelSharedUtils\Exceptions\BaseErrorHandler;
use NuiMarkets\LaravelSharedUtils\Exceptions\RemoteServiceException;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;

class BaseErrorHandlerTest extends TestCase
{
    protected BaseErrorHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = new BaseErrorHandler($this->app);
    }

    public function test_remote_service_exception_returns_correct_status_code()
    {
        $request = Request::create('/test', 'GET');

        // Test 502 Bad Gateway
        $exception = new RemoteServiceException('Remote API error', 502);
        $response = $this->handler->render($request, $exception);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(502, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals('Bad Gateway', $data['meta']['message']);
        $this->assertEquals(502, $data['meta']['status']);
        $this->assertEquals('502', $data['errors'][0]['status']);
        $this->assertEquals('Bad Gateway', $data['errors'][0]['title']);
        $this->assertEquals('Remote API error', $data['errors'][0]['detail']);

        // Test 503 Service Unavailable
        $exception = new RemoteServiceException('Service timeout', 503);
        $response = $this->handler->render($request, $exception);

        $this->assertEquals(503, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals('Service Unavailable', $data['meta']['message']);
        $this->assertEquals(503, $data['meta']['status']);
        $this->assertEquals('Service timeout', $data['errors'][0]['detail']);

        // Test 504 Gateway Timeout
        $exception = new RemoteServiceException('Gateway timeout', 504);
        $response = $this->handler->render($request, $exception);

        $this->assertEquals(504, $response->getStatusCode());
    }

    public function test_remote_service_exception_with_previous_exception()
    {
        $request = Request::create('/test', 'GET');
        $previousException = new \Exception('Connection refused');

        $exception = new RemoteServiceException(
            'Unable to connect to payment service',
            503,
            $previousException
        );

        $response = $this->handler->render($request, $exception);

        $this->assertEquals(503, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals('Service Unavailable', $data['meta']['message']);
        $this->assertEquals(503, $data['meta']['status']);
        $this->assertEquals('Unable to connect to payment service', $data['errors'][0]['detail']);
    }

    public function test_remote_service_exception_with_tags_and_extra()
    {
        $request = Request::create('/test', 'GET');

        $exception = new RemoteServiceException(
            'Inventory service unavailable',
            503,
            null,
            ['service' => 'inventory', 'region' => 'us-east-1'],
            ['retry_count' => 3, 'last_error_time' => '2025-08-04 10:30:00']
        );

        $response = $this->handler->render($request, $exception);

        $this->assertEquals(503, $response->getStatusCode());

        // In non-debug mode, tags and extra are not exposed in the response
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('Service Unavailable', $data['meta']['message']);
        $this->assertEquals('Inventory service unavailable', $data['errors'][0]['detail']);
        $this->assertArrayNotHasKey('tags', $data['errors'][0]);
        $this->assertArrayNotHasKey('extra', $data['errors'][0]);
    }

    public function test_remote_service_exception_default_status_code()
    {
        $request = Request::create('/test', 'GET');

        // When no status code is provided, it should default to 502
        $exception = new RemoteServiceException('External service error');
        $response = $this->handler->render($request, $exception);

        $this->assertEquals(502, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals(502, $data['meta']['status']);
    }
}
