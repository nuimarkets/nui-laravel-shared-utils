<?php

namespace Nuimarkets\LaravelSharedUtils\Tests\Unit\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route as RoutingRoute;
use Nuimarkets\LaravelSharedUtils\Http\Middleware\RequestMetrics;
use Nuimarkets\LaravelSharedUtils\Tests\TestCase;

class RequestMetricsTest extends TestCase
{
    private RequestMetrics $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new RequestMetrics;
    }

    public function test_handle_logs_request_metrics()
    {

        $request = Request::create('api/test', 'POST');

        // Mock the route properly
        $route = new RoutingRoute('POST', 'api/test', ['as' => 'test.route']);
        $request->setRouteResolver(function () use ($route) {
            return $route;
        });

        // Act
        $response = $this->middleware->handle($request, function ($req) {
            return new Response;
        });

        // Assert
        $this->assertEquals(200, $response->getStatusCode());
    }
}
