<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Feature\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use NuiMarkets\LaravelSharedUtils\Exceptions\BaseErrorHandler;
use NuiMarkets\LaravelSharedUtils\Exceptions\RemoteServiceException;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

    public function test_validation_exception_always_uses_jsonapi_format()
    {
        $request = Request::create('/test', 'POST');
        $validator = Validator::make(['email' => 'invalid'], ['email' => 'email|required']);
        $exception = new ValidationException($validator);

        $response = $this->handler->render($request, $exception);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);

        // Verify JSON:API structure
        $this->assertEquals('Validation Failed', $data['meta']['message']);
        $this->assertEquals(422, $data['meta']['status']);
        $this->assertArrayHasKey('errors', $data);

        $error = $data['errors'][0];
        $this->assertEquals('422', $error['status']);
        $this->assertEquals('Validation Error', $error['title']);
        $this->assertStringContainsString('email:', $error['detail']);
        $this->assertEquals('/data/attributes/email', $error['source']['pointer']);
    }

    public function test_validation_exception_ignores_config_settings()
    {
        $request = Request::create('/test', 'POST');

        // Set config to legacy (should be ignored)
        config(['api.error_format' => 'legacy']);

        $validator = Validator::make(['name' => ''], ['name' => 'required']);
        $exception = new ValidationException($validator);

        $response = $this->handler->render($request, $exception);

        $data = json_decode($response->getContent(), true);

        // Should still use JSON:API format despite config
        $this->assertArrayHasKey('errors', $data);
        $this->assertEquals('/data/attributes/name', $data['errors'][0]['source']['pointer']);
    }

    public function test_multiple_validation_errors_create_separate_error_objects()
    {
        $request = Request::create('/test', 'POST');
        $validator = Validator::make(
            ['email' => 'invalid', 'name' => ''],
            ['email' => 'email', 'name' => 'required|min:2']
        );
        $exception = new ValidationException($validator);

        $response = $this->handler->render($request, $exception);

        $data = json_decode($response->getContent(), true);

        // Should have separate error objects for each field/rule
        $this->assertGreaterThanOrEqual(2, count($data['errors']));

        $emailErrors = array_filter($data['errors'], fn ($e) => $e['source']['pointer'] === '/data/attributes/email');
        $nameErrors = array_filter($data['errors'], fn ($e) => $e['source']['pointer'] === '/data/attributes/name');

        $this->assertNotEmpty($emailErrors);
        $this->assertNotEmpty($nameErrors);
    }

    public function test_validation_exception_with_multiple_messages_per_field()
    {
        $request = Request::create('/test', 'POST');
        $validator = Validator::make(
            ['password' => 'abc'],
            ['password' => 'required|min:8|confirmed']
        );
        $exception = new ValidationException($validator);

        $response = $this->handler->render($request, $exception);

        $data = json_decode($response->getContent(), true);

        // Should create separate error objects for each validation rule that failed
        $passwordErrors = array_filter($data['errors'], fn ($e) => $e['source']['pointer'] === '/data/attributes/password');
        $this->assertGreaterThanOrEqual(2, count($passwordErrors)); // min and confirmed rules failed

        // Each error should have consistent structure
        foreach ($passwordErrors as $error) {
            $this->assertEquals('422', $error['status']);
            $this->assertEquals('Validation Error', $error['title']);
            $this->assertStringContainsString('password:', $error['detail']);
            $this->assertEquals('/data/attributes/password', $error['source']['pointer']);
        }
    }

    public function test_validation_exception_with_nested_field_names()
    {
        $request = Request::create('/test', 'POST');

        // Create a validator that will fail and manually create the ValidationException
        $validator = Validator::make(
            ['user.profile.age' => 'not_a_number'],
            ['user.profile.age' => 'required|integer|min:18']
        );

        // Create ValidationException with failed validation
        try {
            $validator->validate();
        } catch (ValidationException $exception) {
            // Use the actual validation exception with errors
            $response = $this->handler->render($request, $exception);

            $data = json_decode($response->getContent(), true);

            $this->assertGreaterThanOrEqual(1, count($data['errors']));
            $error = $data['errors'][0];

            // Should handle nested field names correctly
            $this->assertEquals('422', $error['status']);
            $this->assertEquals('Validation Error', $error['title']);
            $this->assertStringContainsString('user.profile.age:', $error['detail']);
            $this->assertEquals('/data/attributes/user.profile.age', $error['source']['pointer']);

            return;
        }

        $this->fail('ValidationException was not thrown as expected');
    }

    public function test_validation_exception_empty_errors_array()
    {
        $request = Request::create('/test', 'POST');

        // Create a ValidationException with empty errors (edge case)
        $validator = Validator::make([], []);
        $exception = new ValidationException($validator);

        $response = $this->handler->render($request, $exception);

        $data = json_decode($response->getContent(), true);

        // Should still have proper structure even with no validation errors
        // When validation errors are empty, it falls through to generic error handling
        $this->assertEquals('Validation Failed', $data['meta']['message']);
        $this->assertEquals(422, $data['meta']['status']);
        $this->assertIsArray($data['errors']);
        $this->assertNotEmpty($data['errors']); // Will have generic error object
        $this->assertEquals('422', $data['errors'][0]['status']);
        $this->assertEquals('Validation Failed', $data['errors'][0]['title']);
    }

    public function test_validation_exception_is_not_reported()
    {
        $validator = Validator::make(['email' => 'invalid'], ['email' => 'email|required']);
        $exception = new ValidationException($validator);

        // Verify shouldReport returns false for ValidationException
        $this->assertFalse($this->handler->shouldReport($exception));
    }

    public function test_content_type_header_with_json_api_accept()
    {
        $request = Request::create('/test', 'POST');
        $request->headers->set('Accept', 'application/vnd.api+json');

        $validator = Validator::make(['email' => 'invalid'], ['email' => 'email|required']);
        $exception = new ValidationException($validator);

        $response = $this->handler->render($request, $exception);

        $this->assertEquals('application/vnd.api+json', $response->headers->get('Content-Type'));
    }

    public function test_content_type_header_with_default_accept()
    {
        $request = Request::create('/test', 'POST');
        $request->headers->set('Accept', 'application/json');

        $validator = Validator::make(['email' => 'invalid'], ['email' => 'email|required']);
        $exception = new ValidationException($validator);

        $response = $this->handler->render($request, $exception);

        // Should use default JsonResponse content type (application/json)
        $this->assertEquals('application/json', $response->headers->get('Content-Type'));
    }

    public function test_sqlstate_22p02_maps_to_400_bad_request()
    {
        $request = Request::create('/test', 'POST');

        // Create a mock QueryException with SQLSTATE 22P02 (invalid UUID)
        // Use a more robust approach to handle different Laravel versions
        $previousException = new \Exception('Invalid UUID');

        // Use reflection to determine the correct constructor signature
        $reflection = new \ReflectionClass(\Illuminate\Database\QueryException::class);
        $constructor = $reflection->getConstructor();
        $parameters = $constructor->getParameters();

        // Check parameter types to determine the correct signature
        $paramCount = count($parameters);

        if ($paramCount >= 4) {
            // Laravel 10.x+ signature: connectionName, sql, bindings, previous
            $exception = new \Illuminate\Database\QueryException(
                'pgsql',
                'SELECT * FROM orders WHERE id = ?',
                ['invalid-uuid'],
                $previousException
            );
        } elseif ($paramCount === 3) {
            // Check if second parameter expects array (Laravel 9.x prefer-lowest)
            $secondParam = $parameters[1] ?? null;
            $secondParamType = $secondParam ? $secondParam->getType() : null;

            if ($secondParamType && $secondParamType->getName() === 'array') {
                // Laravel 9.x prefer-lowest signature: connectionName, bindings, previous
                $exception = new \Illuminate\Database\QueryException(
                    'pgsql',
                    ['invalid-uuid'],
                    $previousException
                );
            } else {
                // Laravel 9.x signature: connectionName, sql, previous
                $exception = new \Illuminate\Database\QueryException(
                    'pgsql',
                    'SELECT * FROM orders WHERE id = ?',
                    $previousException
                );
            }
        } else {
            // Laravel 8.x or other fallback: connectionName, previous
            $exception = new \Illuminate\Database\QueryException(
                'pgsql',
                $previousException
            );
        }

        // Set the errorInfo property to simulate SQLSTATE 22P02
        $reflection = new \ReflectionClass($exception);
        $property = $reflection->getProperty('errorInfo');
        $property->setAccessible(true);
        $property->setValue($exception, ['22P02', 7, 'invalid input syntax for type uuid']);

        $response = $this->handler->render($request, $exception);

        $this->assertEquals(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals('Bad Request', $data['meta']['message']);
        $this->assertEquals(400, $data['meta']['status']);
        $this->assertEquals('Invalid UUID format.', $data['errors'][0]['detail']);
    }

    /**
     * @dataProvider invalidStatusCodeProvider
     */
    public function test_invalid_status_codes_normalize_to_500(int $invalidCode): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->with('Invalid HTTP status code normalized to 500', \Mockery::on(function ($context) use ($invalidCode) {
                return $context['original_status'] === $invalidCode
                    && $context['exception_class'] === HttpException::class;
            }));

        // Suppress other log calls from render() method
        Log::shouldReceive('info')->zeroOrMoreTimes();

        $request = Request::create('/test', 'GET');

        // HttpException with invalid status code - this is the actual bug scenario
        $exception = new HttpException($invalidCode, 'Test error');

        $response = $this->handler->render($request, $exception);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(500, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals(500, $data['meta']['status']);
        $this->assertEquals('Internal Server Error', $data['meta']['message']);
    }

    public static function invalidStatusCodeProvider(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'below_100' => [99],
            'above_599' => [600],
            'large_number' => [999],
        ];
    }

    public function test_http_exception_with_status_zero_normalizes_to_500(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->with('Invalid HTTP status code normalized to 500', \Mockery::on(function ($context) {
                return $context['original_status'] === 0
                    && $context['exception_class'] === HttpException::class
                    && $context['exception_message'] === 'Bad status';
            }));
        Log::shouldReceive('info')->zeroOrMoreTimes();

        $request = Request::create('/test', 'GET');

        // HttpException can technically be created with any int status
        // This simulates the bug scenario where status becomes 0
        $exception = new HttpException(0, 'Bad status');

        $response = $this->handler->render($request, $exception);

        $this->assertEquals(500, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals(500, $data['meta']['status']);
        $this->assertEquals('Internal Server Error', $data['meta']['message']);
    }

    /**
     * @dataProvider validStatusCodeProvider
     */
    public function test_valid_status_codes_pass_through_unchanged(int $validCode, string $expectedTitle): void
    {
        // Valid codes should NOT trigger warning log
        Log::shouldReceive('warning')->never();
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        $request = Request::create('/test', 'GET');

        $exception = new HttpException($validCode, 'Test');

        $response = $this->handler->render($request, $exception);

        $this->assertEquals($validCode, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals($validCode, $data['meta']['status']);
        $this->assertEquals($expectedTitle, $data['meta']['message']);
    }

    public static function validStatusCodeProvider(): array
    {
        return [
            'ok' => [200, 'OK'],
            'bad_request' => [400, 'Bad Request'],
            'not_found' => [404, 'Not Found'],
            'internal_error' => [500, 'Internal Server Error'],
            'bad_gateway' => [502, 'Bad Gateway'],
        ];
    }
}
