# nui-laravel-shared-utils

Shared Classes for Laravel

Note these are specific to our use case however you may find some value in the code.

https://packagist.org/packages/nuimarkets/laravel-shared-utils

## Installation

```
composer require nuimarkets/laravel-shared-utils
```

## Configuration Migration Notice

### RemoteRepository Configuration Standardization

As of version X.X.X, the RemoteRepository configuration has been standardized to use Laravel's `config/app.php` file. This change simplifies configuration management and follows Laravel best practices.

#### New Configuration Structure

Add the following to your `config/app.php`:

```php
'remote_repository' => [
    'base_uri' => env('REMOTE_REPOSITORY_BASE_URI'),
    'max_url_length' => env('REMOTE_REPOSITORY_MAX_URL_LENGTH', 2048),
    'log_requests' => env('REMOTE_REPOSITORY_LOG_REQUESTS', false),
    'recoverable_error_patterns' => [
        'Duplicate active delivery address codes found',
        // Add your custom patterns here
    ],
],
```

#### Legacy Configuration Fallback

For backward compatibility, the following legacy configuration keys are still supported but **deprecated**:

- `jsonapi.base_uri` → migrate to `app.remote_repository.base_uri`
- `pxc.base_api_uri` → migrate to `app.remote_repository.base_uri`
- `remote.base_uri` → migrate to `app.remote_repository.base_uri`
- `pxc.max_url_length` → migrate to `app.remote_repository.max_url_length`
- `pxc.api_log_requests` → migrate to `app.remote_repository.log_requests`
- `remote.recoverable_error_patterns` → migrate to `app.remote_repository.recoverable_error_patterns`

**Note**: Using legacy configuration keys will log deprecation warnings. Please migrate to the new structure as soon as possible.

#### Migration Steps

1. Add the new configuration structure to your `config/app.php`
2. Copy your existing values from the legacy configuration files
3. Update your `.env` file with the new environment variables:
   ```
   REMOTE_REPOSITORY_BASE_URI=https://your-api-endpoint.com
   REMOTE_REPOSITORY_MAX_URL_LENGTH=2048
   REMOTE_REPOSITORY_LOG_REQUESTS=false
   ```
4. Test your application to ensure everything works correctly
5. Remove the old configuration files once migration is complete


## Documentation

### RemoteRepository
📖 **[Complete RemoteRepository Documentation](docs/RemoteRepository.md)**

The `RemoteRepository` is a comprehensive solution for service-to-service API communication with enhanced error handling, performance monitoring, and caching capabilities.

**Key Features:**
- JSON API client integration with automatic retry logic
- ValidationException handling for malformed responses  
- Performance monitoring with ProfilingTrait
- Intelligent caching for single items and collections
- Standardized configuration in `config/app.php` with legacy fallback support
- Microservice architecture compatibility

## Classes

### RemoteRepositories

* `RemoteRepository` - **[📖 Full Documentation](docs/RemoteRepository.md)** - Abstract base class for external API communication with enhanced error handling, performance monitoring, and caching

### Console

* `ScheduleRunCommand` - Run scheduled tasks ensuring Log is used for all stdout
* `TestFailedJob` - Test failed job command for queue testing
* `TestJob` - Test job command for queue testing
* `WorkCommand` - Run queue work jobs ensuring Log is used for all stdout

### Jobs

* `TestFailedJob` - Test job that intentionally fails for testing purposes
* `TestJob` - Basic test job for queue testing

### Services

* `SentryEventHandler` - Sentry Event Handler
* `IntercomService` - Intercom API integration for user analytics and event tracking

### Testing

* `DBSetupExtension` - DB Setup Extension for phpUnit to drop/create testing database with migrations run

### Exceptions

* `BaseErrorHandler` - Base Error Handler
* `BaseHttpRequestException` - Main Exception handler for something gone wrong in the request
* `RemoteServiceException` - Exception for remote service communication failures

### Logging

* `SensitiveDataProcessor` - Log Processor for sanitizing sensitive data in log records
* `EnvironmentProcessor` - Log Processor for environment info etc
* `SlackHandler` - Slack Handler
* `ColoredJsonLineFormatter` - Formats log records as colored JSON lines with improved readability
* `SentryHandler` - Sentry Error Handler with support for tags and exceptions
* `SourceLocationProcessor` - Log Processor for PHP Source Location

### Http

* `BaseFormRequest` - Base Form Request - logging & error handling bits
* `ClearLadaAndResponseCacheController` - Clear Lada and Response Cache
* `ErrorTestController` - Test exception handling by using /test-error?exception=
* `HomeController` - Home Route (hello/health check)
* `HealthCheckController` - Detailed Health Checks
* `RequestMetrics` - Request Metrics
* `TracksIntercomEvents` - Controller trait for Intercom event tracking

### Support

* `IncludesParser` - Include/exclude parameter parser for API response transformation
* `SimpleDocument` - JSON API document implementation for non-JSON API request bodies
* `ProfilingTrait` - Performance monitoring trait with timing and logging capabilities

