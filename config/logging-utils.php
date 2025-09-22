<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Logging Utilities Configuration
    |--------------------------------------------------------------------------
    |
    | This configuration file controls the behavior of the shared logging
    | utilities. Services can publish and customize this configuration
    | to meet their specific needs.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Monolog Processors
    |--------------------------------------------------------------------------
    |
    | Configure the Monolog processors that should be added to the logger.
    |
    */
    'processors' => [
        'add_target' => [
            'enabled' => true,
            'target' => env('LOG_TARGET', env('APP_NAME', 'laravel')),
            'override' => false, // Whether to override existing target values
        ],

        // The class that customizes Monolog (can be overridden per service)
        'monolog_customizer' => \NuiMarkets\LaravelSharedUtils\Logging\CustomizeMonoLog::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Request Logging Middleware
    |--------------------------------------------------------------------------
    |
    | Configure the request logging middleware behavior.
    |
    */
    'middleware' => [
        'request_logging' => [
            'enabled' => false, // Services must explicitly enable this
            'class' => null, // Services must provide their implementation
            'request_id_header' => 'X-Request-ID',
            'add_request_id_to_response' => true,

            // Log request start/completion (can impact performance)
            'log_request_start' => false,
            'log_request_complete' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Error Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how errors and exceptions are logged.
    |
    */
    'error_logging' => [
        // Include stack traces in non-production environments
        'include_stack_trace' => env('APP_DEBUG', false),

        // Maximum response body length for API errors (prevents huge logs)
        'max_response_body_length' => 1000,

        // Log levels for specific exception types (can be customized)
        'exception_levels' => [
            \Illuminate\Validation\ValidationException::class => 'info',
            \Illuminate\Auth\AuthenticationException::class => 'warning',
            \Illuminate\Auth\Access\AuthorizationException::class => 'warning',
            // Add custom exception mappings here
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sensitive Data Patterns
    |--------------------------------------------------------------------------
    |
    | Additional patterns for sensitive data that should be redacted.
    | The SensitiveDataProcessor already handles common patterns.
    |
    */
    'sensitive_patterns' => [
        // Add service-specific patterns here
        // 'credit_card' => '/\b\d{4}[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{4}\b/',
    ],

    /*
    |--------------------------------------------------------------------------
    | Field Name Conventions
    |--------------------------------------------------------------------------
    |
    | Ensure consistent field naming across services.
    |
    */
    'field_conventions' => [
        'use_snake_case' => true,
        'use_dot_notation_for_nested' => true, // e.g., 'request.method'
    ],

    /*
    |--------------------------------------------------------------------------
    | Remote Repository Profiling
    |--------------------------------------------------------------------------
    |
    | Configure profiling behavior for remote repository operations.
    |
    */
    'remote_repository' => [
        'enable_profiling' => env('REMOTE_REPOSITORY_ENABLE_PROFILING', false),
        'log_requests' => env('REMOTE_REPOSITORY_LOG_REQUESTS', false),
        'max_url_length' => env('REMOTE_REPOSITORY_MAX_URL_LENGTH', 255),
    ],
];
