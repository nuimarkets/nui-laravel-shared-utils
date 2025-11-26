<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Utils;

use Illuminate\Http\Request;
use NuiMarkets\LaravelSharedUtils\Auth\JWTUser;

/**
 * Shared test helpers for creating HTTP Request objects.
 *
 * Provides factory methods for creating Laravel Request objects with users,
 * routes, and headers to reduce duplication across middleware and controller tests.
 */
trait HttpRequestTestHelpers
{
    /**
     * Create a basic HTTP request for testing.
     *
     * @param  string  $uri  Request URI
     * @param  string  $method  HTTP method
     * @param  array  $parameters  Request parameters
     * @param  array  $headers  Request headers
     */
    protected function createHttpRequest(
        string $uri = '/api/test',
        string $method = 'GET',
        array $parameters = [],
        array $headers = []
    ): Request {
        $request = Request::create($uri, $method, $parameters);

        foreach ($headers as $key => $value) {
            $request->headers->set($key, $value);
        }

        return $request;
    }

    /**
     * Create an authenticated request with a stdClass user.
     *
     * @param  string  $uri  Request URI
     * @param  int|string  $userId  User ID
     * @param  int|string|null  $orgId  Organization ID
     * @param  array  $extraUserProps  Additional user properties
     * @param  string  $method  HTTP method
     */
    protected function createAuthenticatedRequest(
        string $uri = '/api/test',
        int|string $userId = 123,
        int|string|null $orgId = 456,
        array $extraUserProps = [],
        string $method = 'GET'
    ): Request {
        $user = $this->createStdClassUser($userId, $orgId, $extraUserProps);
        $request = $this->createHttpRequest($uri, $method);
        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        return $request;
    }

    /**
     * Create an authenticated request with a JWTUser.
     *
     * @param  string  $uri  Request URI
     * @param  string  $userId  User ID
     * @param  string  $orgId  Organization ID
     * @param  string  $role  User role
     * @param  string|null  $email  User email
     * @param  string|null  $orgName  Organization name
     * @param  string|null  $orgType  Organization type
     * @param  string  $method  HTTP method
     */
    protected function createJwtAuthenticatedRequest(
        string $uri = '/api/test',
        string $userId = 'jwt-user-123',
        string $orgId = 'jwt-org-456',
        string $role = 'buyer',
        ?string $email = null,
        ?string $orgName = null,
        ?string $orgType = null,
        string $method = 'GET'
    ): Request {
        $jwtUser = new JWTUser(
            id: $userId,
            org_id: $orgId,
            role: $role,
            email: $email,
            org_name: $orgName,
            org_type: $orgType
        );

        $request = $this->createHttpRequest($uri, $method);
        $request->setUserResolver(function () use ($jwtUser) {
            return $jwtUser;
        });

        return $request;
    }

    /**
     * Create a request with route parameters.
     *
     * @param  string  $uri  Request URI
     * @param  array  $routeParams  Route parameters
     * @param  string|null  $routeName  Route name
     * @param  string  $method  HTTP method
     */
    protected function createRequestWithRoute(
        string $uri = '/api/test',
        array $routeParams = [],
        ?string $routeName = null,
        string $method = 'GET'
    ): Request {
        $request = $this->createHttpRequest($uri, $method);

        $route = \Mockery::mock();
        $route->shouldReceive('parameters')->andReturn($routeParams);
        $route->shouldReceive('getName')->andReturn($routeName ?? 'test.route');

        $request->setRouteResolver(function () use ($route) {
            return $route;
        });

        return $request;
    }

    /**
     * Create a request with X-Ray trace header.
     *
     * @param  string  $uri  Request URI
     * @param  string  $traceId  X-Ray trace ID
     * @param  string  $method  HTTP method
     */
    protected function createRequestWithXRayTrace(
        string $uri = '/api/test',
        string $traceId = 'Root=1-67a92466-4b6aa15a05ffcd4c510de968;Parent=53995c3f42cd8ad8;Sampled=1',
        string $method = 'GET'
    ): Request {
        return $this->createHttpRequest($uri, $method, [], [
            'X-Amzn-Trace-Id' => $traceId,
        ]);
    }

    /**
     * Create a request with a custom request ID.
     *
     * @param  string  $uri  Request URI
     * @param  string  $requestId  Request ID
     * @param  string  $headerName  Request ID header name
     * @param  string  $method  HTTP method
     */
    protected function createRequestWithRequestId(
        string $uri = '/api/test',
        string $requestId = 'test-request-id',
        string $headerName = 'X-Request-ID',
        string $method = 'GET'
    ): Request {
        return $this->createHttpRequest($uri, $method, [], [
            $headerName => $requestId,
        ]);
    }

    /**
     * Create a stdClass user object.
     *
     * @param  int|string  $id  User ID
     * @param  int|string|null  $orgId  Organization ID
     * @param  array  $extra  Additional properties
     */
    protected function createStdClassUser(
        int|string $id = 123,
        int|string|null $orgId = 456,
        array $extra = []
    ): \stdClass {
        $user = new \stdClass;
        $user->id = $id;

        if ($orgId !== null) {
            $user->org_id = $orgId;
        }

        foreach ($extra as $key => $value) {
            $user->$key = $value;
        }

        return $user;
    }

    /**
     * Create a request with Intercom tracking parameters.
     *
     * @param  string  $uri  Request URI
     * @param  string  $userId  User ID parameter
     * @param  string|null  $tenantId  Tenant UUID parameter
     * @param  string  $method  HTTP method
     */
    protected function createIntercomTrackingRequest(
        string $uri = '/test',
        string $userId = 'user-123',
        ?string $tenantId = 'tenant-789',
        string $method = 'GET'
    ): Request {
        $parameters = ['userID' => $userId];

        if ($tenantId !== null) {
            $parameters['tenant_uuid'] = $tenantId;
        }

        return $this->createHttpRequest($uri, $method, $parameters);
    }

    /**
     * Create a request with common browser headers.
     *
     * @param  string  $uri  Request URI
     * @param  string  $userAgent  User agent string
     * @param  string  $ip  Client IP address
     * @param  string  $method  HTTP method
     */
    protected function createBrowserRequest(
        string $uri = '/api/test',
        string $userAgent = 'Mozilla/5.0',
        string $ip = '192.168.1.1',
        string $method = 'GET'
    ): Request {
        $request = Request::create($uri, $method, [], [], [], [
            'HTTP_USER_AGENT' => $userAgent,
            'REMOTE_ADDR' => $ip,
        ]);

        return $request;
    }
}
