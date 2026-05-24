<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use NuiMarkets\LaravelSharedUtils\Auth\JWTUser;
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
                       $context['request.method'] === 'GET' &&
                       $context['request.path'] === 'api/orders' &&
                       isset($context['request.ip']) &&
                       isset($context['request.user_agent']);
            })
        );
    }

    public function test_logs_x_forwarded_for_header_when_present()
    {
        // The raw X-Forwarded-For chain is logged for partner-IP forensics.
        $request = Request::create('/api/orders', 'GET');
        $request->headers->set('X-Forwarded-For', '203.0.113.42, 198.51.100.7');

        $response = new Response('OK', 200);

        $this->middleware->handle($request, function ($req) use ($response) {
            return $response;
        });

        Log::shouldHaveReceived('withContext')->once()->with(
            \Mockery::on(function ($context) {
                return ($context['request.x_forwarded_for'] ?? null) === '203.0.113.42, 198.51.100.7';
            })
        );
    }

    public function test_logs_null_x_forwarded_for_when_header_absent()
    {
        // Missing header normalizes to null (Laravel skips null values in log context).
        $request = Request::create('/api/orders', 'GET');

        $response = new Response('OK', 200);

        $this->middleware->handle($request, function ($req) use ($response) {
            return $response;
        });

        Log::shouldHaveReceived('withContext')->once()->with(
            \Mockery::on(function ($context) {
                return array_key_exists('request.x_forwarded_for', $context)
                    && $context['request.x_forwarded_for'] === null;
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
        $user->role = 'buyer';

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
                return $context['request.user_id'] === 123 &&
                       $context['request.org_id'] === 456 &&
                       $context['request.user_email'] === 'test@example.com' &&
                       $context['request.user_type'] === 'buyer';
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
                return $context['request.user_id'] === 789 &&
                       $context['request.org_id'] === null &&
                       $context['request.user_email'] === 'noorg@example.com';
            })
        );
    }

    public function test_handles_user_with_non_standard_org_property()
    {
        $user = new \stdClass;
        $user->id = 999;
        $user->organization_id = 888; // Non-standard property name (should result in null)

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
                return $context['request.user_id'] === 999 &&
                       $context['request.org_id'] === null; // null because org_id property is missing
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

    public function test_extracts_context_from_jwt_user_object()
    {
        $jwtUser = new JWTUser(
            id: 'jwt-user-123',
            org_id: 'jwt-org-456',
            role: 'seller',
            email: 'jwt@example.com',
            org_name: 'JWT Company',
            org_type: 'buyer'
        );

        $request = Request::create('/api/test', 'GET');
        $request->setUserResolver(function () use ($jwtUser) {
            return $jwtUser;
        });

        $response = new Response('Test', 200);

        $this->middleware->handle($request, function ($req) use ($response) {
            return $response;
        });

        Log::shouldHaveReceived('withContext')->once()->with(
            \Mockery::on(function ($context) {
                return $context['request.user_id'] === 'jwt-user-123' &&
                       $context['request.org_id'] === 'jwt-org-456' &&
                       $context['request.user_email'] === 'jwt@example.com' &&
                       $context['request.user_type'] === 'seller' &&
                       $context['request.org_name'] === 'JWT Company' &&
                       $context['request.org_type'] === 'buyer';
            })
        );
    }

    public function test_handles_jwt_user_with_minimal_properties()
    {
        $jwtUser = new JWTUser(
            id: 'minimal-user',
            org_id: 'minimal-org',
            role: 'machine'
        );

        $request = Request::create('/api/test', 'GET');
        $request->setUserResolver(function () use ($jwtUser) {
            return $jwtUser;
        });

        $response = new Response('Test', 200);

        $this->middleware->handle($request, function ($req) use ($response) {
            return $response;
        });

        Log::shouldHaveReceived('withContext')->once()->with(
            \Mockery::on(function ($context) {
                return $context['request.user_id'] === 'minimal-user' &&
                       $context['request.org_id'] === 'minimal-org' &&
                       $context['request.user_type'] === 'machine' &&
                       ! array_key_exists('request.user_email', $context) &&
                       ! array_key_exists('request.org_name', $context) &&
                       ! array_key_exists('request.org_type', $context);
            })
        );
    }

    public function test_actually_writes_request_start_log()
    {
        $request = Request::create('/api/orders', 'POST');
        $response = new Response('OK', 200);

        $this->middleware->handle($request, function ($req) use ($response) {
            return $response;
        });

        // Verify that Log::info was actually called for request start
        Log::shouldHaveReceived('info')->with('Request start', \Mockery::type('array'));
    }

    public function test_actually_writes_request_complete_log()
    {
        $request = Request::create('/api/orders', 'GET');
        $response = new Response('OK', 200);

        $this->middleware->handle($request, function ($req) use ($response) {
            return $response;
        });

        // Verify that Log::info was actually called for request complete
        Log::shouldHaveReceived('info')->with('Request complete', \Mockery::type('array'));
    }

    public function test_excluded_paths_skip_all_logging()
    {
        $middleware = new TestRequestLoggingMiddleware;
        $middleware->configure(['excluded_paths' => ['/']]);

        $request = Request::create('/', 'GET');
        $response = new Response('Health OK', 200);

        $middleware->handle($request, function ($req) use ($response) {
            return $response;
        });

        // Verify that NO logs were written for excluded path
        Log::shouldNotHaveReceived('info');
    }

    public function test_non_excluded_paths_do_log()
    {
        $middleware = new TestRequestLoggingMiddleware;
        $middleware->configure(['excluded_paths' => ['/']]);

        $request = Request::create('/api/orders', 'POST');
        $response = new Response('Created', 201);

        $middleware->handle($request, function ($req) use ($response) {
            return $response;
        });

        // Verify that logs WERE written for non-excluded path
        Log::shouldHaveReceived('info')->with('Request start', \Mockery::type('array'));
        Log::shouldHaveReceived('info')->with('Request complete', \Mockery::type('array'));
    }

    public function test_response_status_logged_as_flat_top_level_field()
    {
        $request = Request::create('/api/orders', 'GET');
        $response = new Response('Not Found', 404);

        $this->middleware->handle($request, function ($req) use ($response) {
            return $response;
        });

        // response.status must be a top-level flat key (dot-notation) so the
        // ingestion Lambda can promote it into an indexed ES field. A nested
        // ['response' => ['status' => 404]] structure gets buried in `data.*`
        // and cannot be searched with `response.status:4xx` range queries.
        Log::shouldHaveReceived('info')->with(
            'Request complete',
            \Mockery::on(function ($context) {
                return ($context['response.status'] ?? null) === 404
                    && ! isset($context['response']['status']);
            })
        );
    }

    public function test_integration_features_extracted_via_get_features_method()
    {
        $user = new UserWithGetFeatures([
            'integration-create-as-pending',
            'integration-skip-validation',
            'standard-feature',
        ]);

        $request = Request::create('/api/test', 'GET');
        $request->setUserResolver(fn () => $user);

        $this->middleware->handle($request, fn () => new Response('OK', 200));

        Log::shouldHaveReceived('withContext')->once()->with(
            \Mockery::on(function ($context) {
                return ($context['integration_features'] ?? null) === [
                    'integration-create-as-pending',
                    'integration-skip-validation',
                ];
            })
        );
    }

    public function test_integration_features_extracted_via_features_property()
    {
        $user = new \stdClass;
        $user->id = 1;
        $user->features = [
            'integration-create-as-pending',
            'standard-feature',
            'integration-bypass-stock-check',
        ];

        $request = Request::create('/api/test', 'GET');
        $request->setUserResolver(fn () => $user);

        $this->middleware->handle($request, fn () => new Response('OK', 200));

        Log::shouldHaveReceived('withContext')->once()->with(
            \Mockery::on(function ($context) {
                return ($context['integration_features'] ?? null) === [
                    'integration-create-as-pending',
                    'integration-bypass-stock-check',
                ];
            })
        );
    }

    public function test_integration_features_absent_when_features_empty()
    {
        $user = new \stdClass;
        $user->id = 1;
        $user->features = [];

        $request = Request::create('/api/test', 'GET');
        $request->setUserResolver(fn () => $user);

        $this->middleware->handle($request, fn () => new Response('OK', 200));

        Log::shouldHaveReceived('withContext')->once()->with(
            \Mockery::on(function ($context) {
                return ! array_key_exists('integration_features', $context);
            })
        );
    }

    public function test_integration_features_absent_when_only_non_integration_features()
    {
        $user = new \stdClass;
        $user->id = 1;
        $user->features = ['standard-feature', 'another-feature'];

        $request = Request::create('/api/test', 'GET');
        $request->setUserResolver(fn () => $user);

        $this->middleware->handle($request, fn () => new Response('OK', 200));

        Log::shouldHaveReceived('withContext')->once()->with(
            \Mockery::on(function ($context) {
                return ! array_key_exists('integration_features', $context);
            })
        );
    }

    public function test_integration_features_skips_objects_with_magic_call_and_no_real_get_features()
    {
        // Mimics an Eloquent model: __call would route a fictitious getFeatures() to a relation/scope lookup
        // and throw. method_exists() returns false for magic methods, so we must fall through to property-based
        // sourcing. Public dynamic property here drives the read.
        $user = new UserWithMagicCall;
        $user->features = ['integration-create-as-pending', 'standard-feature'];

        $request = Request::create('/api/test', 'GET');
        $request->setUserResolver(fn () => $user);

        $this->middleware->handle($request, fn () => new Response('OK', 200));

        Log::shouldHaveReceived('withContext')->once()->with(
            \Mockery::on(function ($context) {
                return ($context['integration_features'] ?? null) === ['integration-create-as-pending'];
            })
        );
    }

    public function test_integration_features_skips_protected_get_features_method()
    {
        // method_exists is true, but is_callable is false from outside-class scope, so we fall through to
        // property-based sourcing and avoid an \Error from invoking a non-public method.
        $user = new UserWithProtectedGetFeatures;
        $user->features = ['integration-bypass-stock-check'];

        $request = Request::create('/api/test', 'GET');
        $request->setUserResolver(fn () => $user);

        $this->middleware->handle($request, fn () => new Response('OK', 200));

        Log::shouldHaveReceived('withContext')->once()->with(
            \Mockery::on(function ($context) {
                return ($context['integration_features'] ?? null) === ['integration-bypass-stock-check'];
            })
        );
    }

    public function test_integration_features_skips_protected_get_features_when_magic_call_exists()
    {
        // Combined edge case: a class with both __call and a non-public getFeatures(). is_callable() returns
        // true here (because __call would handle the call), but the reflection-based visibility check rejects
        // the protected method, so we fall through to the property fallback rather than invoking __call.
        $user = new UserWithProtectedGetFeaturesAndMagicCall;
        $user->features = ['integration-create-as-pending'];

        $request = Request::create('/api/test', 'GET');
        $request->setUserResolver(fn () => $user);

        $this->middleware->handle($request, fn () => new Response('OK', 200));

        Log::shouldHaveReceived('withContext')->once()->with(
            \Mockery::on(function ($context) {
                return ($context['integration_features'] ?? null) === ['integration-create-as-pending'];
            })
        );
    }

    public function test_integration_features_filters_non_string_and_malformed_entries()
    {
        $user = new \stdClass;
        $user->id = 1;
        $user->features = [
            'integration-create-as-pending',
            123,
            null,
            ['nested'],
            new \stdClass,
            'integration-bypass-stock-check',
            'unrelated',
        ];

        $request = Request::create('/api/test', 'GET');
        $request->setUserResolver(fn () => $user);

        $this->middleware->handle($request, fn () => new Response('OK', 200));

        Log::shouldHaveReceived('withContext')->once()->with(
            \Mockery::on(function ($context) {
                return ($context['integration_features'] ?? null) === [
                    'integration-create-as-pending',
                    'integration-bypass-stock-check',
                ];
            })
        );
    }
}

/**
 * Test fixture: simulates a user model exposing getFeatures() (e.g. connect-order's ConnectUser).
 */
class UserWithGetFeatures
{
    public $id = 42;

    private array $features;

    public function __construct(array $features)
    {
        $this->features = $features;
    }

    public function getFeatures(): array
    {
        return $this->features;
    }
}

/**
 * Test fixture: simulates an Eloquent-shaped user. __call would handle magic method lookups.
 * method_exists() must return false for magic, otherwise we'd invoke __call and crash the request.
 */
class UserWithMagicCall
{
    public $id = 42;

    public ?array $features = null;

    public function __call(string $name, array $arguments)
    {
        throw new \BadMethodCallException("Magic call to {$name} would query the database");
    }
}

/**
 * Test fixture: a user model with a protected getFeatures(). method_exists is true but is_callable is false
 * from outside-class scope. The middleware must skip the call and fall through to the property fallback.
 */
class UserWithProtectedGetFeatures
{
    public $id = 42;

    public ?array $features = null;

    protected function getFeatures(): array
    {
        return ['should-not-be-read'];
    }
}

/**
 * Test fixture: combines protected getFeatures() with __call. PHP's is_callable() returns true here because
 * __call would handle the invocation, so a visibility check based on is_callable alone is insufficient.
 * Reflection on the declared method is the only correct guard.
 */
class UserWithProtectedGetFeaturesAndMagicCall
{
    public $id = 42;

    public ?array $features = null;

    protected function getFeatures(): array
    {
        return ['should-not-be-read'];
    }

    public function __call(string $name, array $arguments)
    {
        throw new \BadMethodCallException("Magic call to {$name} would crash");
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

    protected function getServiceName(): string
    {
        return 'test-service';
    }
}
