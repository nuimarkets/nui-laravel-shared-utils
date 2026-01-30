<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\Logging;

use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use NuiMarkets\LaravelSharedUtils\Logging\ErrorLogger;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ErrorLoggerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Log::spy();
    }

    public function test_log_exception_with_standard_context()
    {
        $exception = new \Exception('Test exception', 500);

        ErrorLogger::logException($exception, ['user_id' => 123]);

        Log::shouldHaveReceived('error')->once()->with(
            'Test exception',
            \Mockery::on(function ($context) {
                return $context['exception'] === 'Exception' &&
                       $context['error_message'] === 'Test exception' &&
                       $context['error_code'] === 500 &&
                       $context['user_id'] === 123 &&
                       isset($context['error_file']) &&
                       isset($context['error_line']);
            })
        );
    }

    public function test_log_exception_includes_stack_trace_in_development()
    {
        $this->app['config']->set('app.debug', true);

        $exception = new \RuntimeException('Runtime error');

        ErrorLogger::logException($exception);

        Log::shouldHaveReceived('error')->once()->with(
            'Runtime error',
            \Mockery::on(function ($context) {
                return isset($context['error_trace']);
            })
        );
    }

    public function test_log_exception_excludes_stack_trace_in_production()
    {
        $this->app['config']->set('app.env', 'production');
        $this->app['config']->set('app.debug', false);

        $exception = new \RuntimeException('Runtime error');

        ErrorLogger::logException($exception);

        Log::shouldHaveReceived('error')->once()->with(
            'Runtime error',
            \Mockery::on(function ($context) {
                return ! isset($context['error_trace']);
            })
        );
    }

    public function test_log_exception_includes_previous_exception()
    {
        $previous = new \Exception('Previous exception', 400);
        $exception = new \Exception('Current exception', 500, $previous);

        ErrorLogger::logException($exception);

        Log::shouldHaveReceived('error')->once()->with(
            'Current exception',
            \Mockery::on(function ($context) {
                return isset($context['previous_exception']) &&
                       $context['previous_exception']['class'] === 'Exception' &&
                       $context['previous_exception']['message'] === 'Previous exception' &&
                       $context['previous_exception']['code'] === 400;
            })
        );
    }

    public function test_validation_exception_logs_as_info()
    {
        // Ensure Log facade is properly spied on
        Log::shouldReceive('info')->once()->with(
            \Mockery::type('string'),
            \Mockery::type('array')
        );

        $validator = \Mockery::mock(\Illuminate\Contracts\Validation\Validator::class);
        $validator->shouldReceive('errors')->andReturn(new \Illuminate\Support\MessageBag(['field' => ['error']]));

        $exception = new ValidationException($validator);

        ErrorLogger::logException($exception);
    }

    public function test_http_exception_4xx_logs_as_warning()
    {
        $exception = new HttpException(400, 'Bad request');

        ErrorLogger::logException($exception);

        Log::shouldHaveReceived('warning')->once();
        Log::shouldNotHaveReceived('error');
    }

    public function test_http_exception_404_logs_as_info()
    {
        $exception = new HttpException(404, 'Not found');

        ErrorLogger::logException($exception);

        Log::shouldHaveReceived('info')->once();
    }

    public function test_http_exception_5xx_logs_as_error()
    {
        $exception = new HttpException(500, 'Server error');

        ErrorLogger::logException($exception);

        Log::shouldHaveReceived('error')->once();
    }

    public function test_database_connection_error_logs_as_critical()
    {
        $pdoException = new \PDOException('SQLSTATE[HY000] Connection refused');

        // Laravel 9 vs 10 compatibility - different constructor signatures
        $reflection = new \ReflectionClass(QueryException::class);
        $constructor = $reflection->getConstructor();
        $params = $constructor->getParameters();

        if (count($params) === 4) {
            // Laravel 10: __construct($connectionName, $sql, array $bindings, Throwable $previous)
            $exception = new QueryException('mysql', 'select * from users', [], $pdoException);
        } else {
            // Laravel 9: __construct($sql, array $bindings, Throwable $previous)
            $exception = new QueryException('select * from users', [], $pdoException);
        }

        ErrorLogger::logException($exception);

        Log::shouldHaveReceived('critical')->once();
    }

    public function test_log_validation_error()
    {
        $errors = [
            'email' => ['The email field is required.'],
            'name' => ['The name field must be a string.'],
        ];

        ErrorLogger::logValidationError($errors, ['action' => 'user_registration']);

        Log::shouldHaveReceived('info')->once()->with(
            'Validation failed',
            \Mockery::on(function ($context) {
                return $context['validation_errors']['email'][0] === 'The email field is required.' &&
                       $context['error_count'] === 2 &&
                       $context['error_type'] === 'validation' &&
                       $context['action'] === 'user_registration' &&
                       $context['first_error'] === ['The email field is required.'];
            })
        );
    }

    public function test_log_validation_error_with_empty_errors_array()
    {
        $errors = [];

        ErrorLogger::logValidationError($errors, ['action' => 'data_import']);

        Log::shouldHaveReceived('info')->once()->with(
            'Validation failed',
            \Mockery::on(function ($context) {
                return $context['validation_errors'] === [] &&
                       $context['error_count'] === 0 &&
                       $context['error_type'] === 'validation' &&
                       $context['action'] === 'data_import' &&
                       $context['first_error'] === null; // Should be null, not false
            })
        );
    }

    public function test_log_api_error_with_http_client_response()
    {
        $response = \Mockery::mock(Response::class);
        $response->shouldReceive('status')->andReturn(500);
        $response->shouldReceive('body')->andReturn('{"error": "Internal server error"}');
        $response->shouldReceive('headers')->andReturn(['Content-Type' => 'application/json']);

        ErrorLogger::logApiError('payment-gateway', '/process', $response, ['transaction_id' => 'abc123']);

        Log::shouldHaveReceived('error')->once()->with(
            'External API call failed: payment-gateway',
            \Mockery::on(function ($context) {
                return $context['api.service'] === 'payment-gateway' &&
                       $context['api.endpoint'] === '/process' &&
                       $context['api.status'] === 500 &&
                       $context['api.success'] === false &&
                       $context['transaction_id'] === 'abc123' &&
                       str_contains($context['response_body'], 'Internal server error');
            })
        );
    }

    public function test_log_api_error_truncates_large_response()
    {
        $largeBody = str_repeat('x', 2000);
        $response = \Mockery::mock(Response::class);
        $response->shouldReceive('status')->andReturn(500);
        $response->shouldReceive('body')->andReturn($largeBody);
        $response->shouldReceive('headers')->andReturn([]);

        ErrorLogger::logApiError('external-api', '/data', $response);

        Log::shouldHaveReceived('error')->once()->with(
            'External API call failed: external-api',
            \Mockery::on(function ($context) {
                return strlen($context['response_body']) === 1015 && // 1000 + '... [truncated]' (15 chars)
                       str_ends_with($context['response_body'], '... [truncated]');
            })
        );
    }

    public function test_log_api_error_with_array_response()
    {
        $response = ['error' => true, 'message' => 'API key invalid'];

        ErrorLogger::logApiError('weather-api', '/forecast', $response);

        Log::shouldHaveReceived('error')->once()->with(
            'External API call failed: weather-api',
            \Mockery::on(function ($context) {
                return $context['response_data']['error'] === true &&
                       $context['response_data']['message'] === 'API key invalid';
            })
        );
    }

    public function test_log_custom_error()
    {
        ErrorLogger::logError('file_system', 'Unable to write to cache directory', [
            'directory' => '/var/cache',
            'permissions' => '0755',
        ]);

        Log::shouldHaveReceived('error')->once()->with(
            'Unable to write to cache directory',
            \Mockery::on(function ($context) {
                return $context['error_type'] === 'file_system' &&
                       $context['error_message'] === 'Unable to write to cache directory' &&
                       $context['directory'] === '/var/cache' &&
                       $context['permissions'] === '0755';
            })
        );
    }

    public function test_log_custom_error_with_custom_level()
    {
        ErrorLogger::logError('performance', 'Query took too long', [
            'query_time' => 5000,
            'query' => 'SELECT * FROM large_table',
        ], 'warning');

        Log::shouldHaveReceived('warning')->once()->with(
            'Query took too long',
            \Mockery::on(function ($context) {
                return $context['error_type'] === 'performance' &&
                       $context['query_time'] === 5000;
            })
        );
    }
}
