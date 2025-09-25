<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use NuiMarkets\LaravelSharedUtils\Http\Middleware\RequestLoggingMiddleware;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;

class RequestLoggingMiddlewareTest extends TestCase
{
    protected TestRequestLoggingMiddleware $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new TestRequestLoggingMiddleware;
        Log::spy();
    }

    public function test_adds_request_context_to_logs()
    {
        $request = Request::create('/api/orders', 'GET');
        $request->headers->set('X-Request-ID', 'test-request-id');

        $response = new Response('OK', 200);

        $this->middleware->handle($request, function ($req) use ($response) {
            // Simulate some logging inside the request
            Log::info('Test log message');

            return $response;
        });

        Log::shouldHaveReceived('withContext')->once()->with(
            \Mockery::on(function ($context) {
                return $context['request_id'] === 'test-request-id' &&
                       $context['request']['method'] === 'GET' &&
                       $context['request']['path'] === 'api/orders' &&
                       isset($context['request']['ip']) &&
                       isset($context['request']['user_agent']);
            })
        );
    }

    public function test_generates_request_id_if_not_provided()
    {
        // Mock Str::uuid to return a predictable value
        Str::createUuidsUsing(function () {
            return Str::of('generated-uuid');
        });

        $request = Request::create('/api/users', 'POST');
        $response = new Response('Created', 201);

        $result = $this->middleware->handle($request, function ($req) use ($response) {
            return $response;
        });

        Log::shouldHaveReceived('withContext')->once()->with(
            \Mockery::on(function ($context) {
                return $context['request_id'] === 'generated-uuid';
            })
        );

        $this->assertEquals('generated-uuid', $result->headers->get('X-Request-ID'));

        // Reset UUID generation
        Str::createUuidsNormally();
    }

    public function test_adds_authenticated_user_context()
    {
        $user = new \stdClass;
        $user->id = 123;
        $user->org_id = 456;
        $user->email = 'test@example.com';
        $user->type = 'buyer';

        $request = Request::create('/api/profile', 'GET');
        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        $response = new Response('Profile data', 200);

        $this->middleware->handle($request, function ($req) use ($response) {
            return $response;
        });

        Log::shouldHaveReceived('withContext')->once()->with(
            \Mockery::on(function ($context) {
                return $context['user_id'] === 123 &&
                       $context['org_id'] === 456 &&
                       $context['user_email'] === 'test@example.com' &&
                       $context['user_type'] === 'buyer';
            })
        );
    }

    public function test_adds_service_specific_context()
    {
        $request = Request::create('/api/orders/12345', 'GET');
        $request->setRouteResolver(function () {
            $route = \Mockery::mock();
            $route->shouldReceive('parameters')->andReturn(['id' => '12345']);
            $route->shouldReceive('getName')->andReturn('test.route');

            return $route;
        });

        $response = new Response('Order data', 200);

        $this->middleware->handle($request, function ($req) use ($response) {
            return $response;
        });

        Log::shouldHaveReceived('withContext')->once()->with(
            \Mockery::on(function ($context) {
                // Check that service-specific context was added
                return $context['service_name'] === 'test-service' &&
                       $context['order_id'] === '12345';
            })
        );
    }

    public function test_does_not_add_request_id_to_response_when_disabled()
    {
        $middleware = new TestRequestLoggingMiddleware;
        $middleware->configure(['add_request_id_to_response' => false]);

        $request = Request::create('/api/test', 'GET');
        $response = new Response('Test', 200);

        $result = $middleware->handle($request, function ($req) use ($response) {
            return $response;
        });

        $this->assertFalse($result->headers->has('X-Request-ID'));
    }

    public function test_uses_custom_request_id_header()
    {
        $middleware = new TestRequestLoggingMiddleware;
        $middleware->configure(['request_id_header' => 'X-Correlation-ID']);

        $request = Request::create('/api/test', 'GET');
        $request->headers->set('X-Correlation-ID', 'custom-correlation-id');
        $response = new Response('Test', 200);

        $result = $middleware->handle($request, function ($req) use ($response) {
            return $response;
        });

        Log::shouldHaveReceived('withContext')->once()->with(
            \Mockery::on(function ($context) {
                return $context['request_id'] === 'custom-correlation-id';
            })
        );

        $this->assertEquals('custom-correlation-id', $result->headers->get('X-Correlation-ID'));
    }

    public function test_handles_user_without_org_id()
    {
        $user = new \stdClass;
        $user->id = 789;
        $user->email = 'noorg@example.com';
        // No org_id property

        $request = Request::create('/api/test', 'GET');
        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        $response = new Response('Test', 200);

        $this->middleware->handle($request, function ($req) use ($response) {
            return $response;
        });

        Log::shouldHaveReceived('withContext')->once()->with(
            \Mockery::on(function ($context) {
                return $context['user_id'] === 789 &&
                       $context['org_id'] === null &&
                       $context['user_email'] === 'noorg@example.com';
            })
        );
    }

    public function test_handles_user_with_organization_id_instead_of_org_id()
    {
        $user = new \stdClass;
        $user->id = 999;
        $user->organization_id = 888; // Different property name

        $request = Request::create('/api/test', 'GET');
        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        $response = new Response('Test', 200);

        $this->middleware->handle($request, function ($req) use ($response) {
            return $response;
        });

        Log::shouldHaveReceived('withContext')->once()->with(
            \Mockery::on(function ($context) {
                return $context['user_id'] === 999 &&
                       $context['org_id'] === 888;
            })
        );
    }

    public function test_captures_x_ray_trace_id_from_header()
    {
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('X-Amzn-Trace-Id', 'Root=1-67a92466-4b6aa15a05ffcd4c510de968;Parent=53995c3f42cd8ad8;Sampled=1');

        $response = new Response('Test', 200);

        $this->middleware->handle($request, function ($req) use ($response) {
            return $response;
        });

        Log::shouldHaveReceived('withContext')->once()->with(
            \Mockery::on(function ($context) {
                return $context['request.trace_id'] === '1-67a92466-4b6aa15a05ffcd4c510de968' &&
                       $context['request.amz_trace_id'] === 'Root=1-67a92466-4b6aa15a05ffcd4c510de968;Parent=53995c3f42cd8ad8;Sampled=1';
            })
        );
    }

    public function test_handles_trace_id_without_root_prefix()
    {
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('X-Amzn-Trace-Id', '1-67a92466-4b6aa15a05ffcd4c510de968');

        $response = new Response('Test', 200);

        $this->middleware->handle($request, function ($req) use ($response) {
            return $response;
        });

        Log::shouldHaveReceived('withContext')->once()->with(
            \Mockery::on(function ($context) {
                return $context['request.trace_id'] === '1-67a92466-4b6aa15a05ffcd4c510de968' &&
                       $context['request.amz_trace_id'] === '1-67a92466-4b6aa15a05ffcd4c510de968';
            })
        );
    }

    public function test_handles_missing_trace_id_header()
    {
        $request = Request::create('/api/test', 'GET');
        // No X-Amzn-Trace-Id header set

        $response = new Response('Test', 200);

        $this->middleware->handle($request, function ($req) use ($response) {
            return $response;
        });

        Log::shouldHaveReceived('withContext')->once()->with(
            \Mockery::on(function ($context) {
                return $context['request.trace_id'] === null &&
                       $context['request.amz_trace_id'] === null;
            })
        );
    }

    public function test_handles_malformed_trace_id_header()
    {
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('X-Amzn-Trace-Id', 'InvalidTraceIdFormat');

        $response = new Response('Test', 200);

        $this->middleware->handle($request, function ($req) use ($response) {
            return $response;
        });

        Log::shouldHaveReceived('withContext')->once()->with(
            \Mockery::on(function ($context) {
                return $context['request.trace_id'] === 'InvalidTraceIdFormat' &&
                       $context['request.amz_trace_id'] === 'InvalidTraceIdFormat';
            })
        );
    }
}

/**
 * Concrete implementation for testing
 */
class TestRequestLoggingMiddleware extends RequestLoggingMiddleware
{
    protected function addServiceContext(Request $request, array $context): array
    {
        $context['service_name'] = 'test-service';

        // Add order_id if present in route
        if ($route = $request->route()) {
            $params = $route->parameters();
            if (isset($params['id'])) {
                $context['order_id'] = $params['id'];
            }
        }

        return $context;
    }
}
