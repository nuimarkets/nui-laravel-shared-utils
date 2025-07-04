<?php

namespace NuiMarkets\LaravelSharedUtils\Logging;

use Illuminate\Support\Facades\Log;

/**
 * Centralized error logging with consistent formatting across services.
 * 
 * This utility provides standardized error logging for:
 * - Exceptions with appropriate log levels
 * - Validation errors with structured format
 * - External API errors with request/response details
 * - Custom error types with consistent context
 */
class ErrorLogger
{
    /**
     * Log an exception with standard context.
     * 
     * @param \Throwable $e The exception to log
     * @param array $context Additional context to include
     * @return void
     */
    public static function logException(\Throwable $e, array $context = []): void
    {
        $errorContext = [
            LogFields::EXCEPTION => get_class($e),
            LogFields::ERROR_MESSAGE => $e->getMessage(),
            LogFields::ERROR_CODE => $e->getCode(),
            LogFields::ERROR_FILE => $e->getFile(),
            LogFields::ERROR_LINE => $e->getLine(),
        ];
        
        // Add stack trace for non-production environments
        if (static::shouldIncludeStackTrace()) {
            $errorContext[LogFields::ERROR_TRACE] = $e->getTraceAsString();
        }
        
        // Add previous exception info if exists
        if ($previous = $e->getPrevious()) {
            $errorContext['previous_exception'] = [
                'class' => get_class($previous),
                'message' => $previous->getMessage(),
                'code' => $previous->getCode(),
            ];
        }
        
        // Merge with provided context
        $fullContext = array_merge($errorContext, $context);
        
        // Use appropriate log level based on exception type
        $logLevel = static::getLogLevel($e);
        
        // Log with the determined level
        static::logWithLevel($logLevel, $e->getMessage(), $fullContext);
    }
    
    /**
     * Log validation errors with standard format.
     * 
     * @param array $errors The validation errors
     * @param array $context Additional context to include
     * @return void
     */
    public static function logValidationError(array $errors, array $context = []): void
    {
        $errorContext = [
            LogFields::VALIDATION_ERRORS => $errors,
            LogFields::ERROR_TYPE => 'validation',
            'error_count' => count($errors),
            'first_error' => !empty($errors) ? reset($errors) : null,
        ];
        
        // Add feature context if not provided
        if (!isset($context[LogFields::FEATURE])) {
            $context[LogFields::FEATURE] = 'validation';
        }
        
        Log::info('Validation failed', array_merge($errorContext, $context));
    }
    
    /**
     * Log API errors with standard format.
     * 
     * @param string $service The external service name
     * @param string $endpoint The API endpoint
     * @param mixed $response The response received (Response object, array, or string)
     * @param array $context Additional context to include
     * @return void
     */
    public static function logApiError(string $service, string $endpoint, $response, array $context = []): void
    {
        $apiContext = [
            LogFields::API_SERVICE => $service,
            LogFields::API_ENDPOINT => $endpoint,
            LogFields::API_SUCCESS => false,
            LogFields::ERROR_TYPE => 'api_error',
        ];
        
        // Handle different response types
        if ($response instanceof \Illuminate\Http\Client\Response) {
            $apiContext[LogFields::API_STATUS] = $response->status();
            $apiContext['response_body'] = static::truncateResponseBody($response->body());
            $apiContext['response_headers'] = $response->headers();
        } elseif ($response instanceof \Psr\Http\Message\ResponseInterface) {
            $apiContext[LogFields::API_STATUS] = $response->getStatusCode();
            $apiContext['response_body'] = static::truncateResponseBody((string) $response->getBody());
        } elseif (is_array($response)) {
            $apiContext['response_data'] = $response;
        } else {
            $apiContext['response'] = (string) $response;
        }
        
        // Add request details if available
        if (isset($context['request'])) {
            $apiContext['request_data'] = $context['request'];
            unset($context['request']);
        }
        
        Log::error("External API call failed: {$service}", array_merge($apiContext, $context));
    }
    
    /**
     * Log a custom error with consistent formatting.
     * 
     * @param string $errorType The type of error (e.g., 'database', 'file_system', 'permission')
     * @param string $message The error message
     * @param array $context Additional context to include
     * @param string $level The log level to use (default: 'error')
     * @return void
     */
    public static function logError(string $errorType, string $message, array $context = [], string $level = 'error'): void
    {
        $errorContext = [
            LogFields::ERROR_TYPE => $errorType,
            LogFields::ERROR_MESSAGE => $message,
        ];
        
        $fullContext = array_merge($errorContext, $context);
        
        static::logWithLevel($level, $message, $fullContext);
    }
    
    /**
     * Determine appropriate log level based on exception type.
     * 
     * @param \Throwable $e
     * @return string
     */
    protected static function getLogLevel(\Throwable $e): string
    {
        // Authentication/Authorization exceptions
        if ($e instanceof \Illuminate\Auth\AuthenticationException ||
            $e instanceof \Illuminate\Auth\Access\AuthorizationException) {
            return 'warning';
        }
        
        // Client errors (4xx) should be warnings
        if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException) {
            $statusCode = $e->getStatusCode();
            if ($statusCode >= 400 && $statusCode < 500) {
                return $statusCode === 404 ? 'info' : 'warning';
            }
        }
        
        // Validation exceptions are info level
        if ($e instanceof \Illuminate\Validation\ValidationException) {
            return 'info';
        }
        
        // Database connection errors are critical
        if ($e instanceof \Illuminate\Database\QueryException) {
            if (str_contains($e->getMessage(), 'Connection refused') ||
                str_contains($e->getMessage(), 'SQLSTATE[HY000]')) {
                return 'critical';
            }
        }
        
        // Default to error
        return 'error';
    }
    
    /**
     * Log with a specific level, validating the level first.
     * 
     * @param string $level
     * @param string $message
     * @param array $context
     * @return void
     */
    protected static function logWithLevel(string $level, string $message, array $context): void
    {
        // Validate log level against allowed methods to prevent arbitrary method execution
        switch ($level) {
            case 'emergency':
                Log::emergency($message, $context);
                break;
            case 'alert':
                Log::alert($message, $context);
                break;
            case 'critical':
                Log::critical($message, $context);
                break;
            case 'error':
                Log::error($message, $context);
                break;
            case 'warning':
                Log::warning($message, $context);
                break;
            case 'notice':
                Log::notice($message, $context);
                break;
            case 'info':
                Log::info($message, $context);
                break;
            case 'debug':
                Log::debug($message, $context);
                break;
            default:
                // Fall back to error level if invalid level is provided
                Log::error($message, $context);
                break;
        }
    }
    
    /**
     * Determine if stack traces should be included.
     * 
     * @return bool
     */
    protected static function shouldIncludeStackTrace(): bool
    {
        $env = config('app.env', 'production');
        $debugMode = config('app.debug', false);
        
        // Include stack traces in non-production or when debug is enabled
        return !in_array($env, ['production', 'prod']) || $debugMode;
    }
    
    /**
     * Truncate response body to prevent excessive log sizes.
     * 
     * @param string $body
     * @param int $maxLength
     * @return string
     */
    protected static function truncateResponseBody(string $body, int $maxLength = 1000): string
    {
        if (strlen($body) <= $maxLength) {
            return $body;
        }
        
        return substr($body, 0, $maxLength) . '... [truncated]';
    }
}