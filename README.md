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
    'base_uri' => env('API_GATEWAY_ENDPOINT'),  // Use existing API_GATEWAY_ENDPOINT
    'max_url_length' => env('REMOTE_REPOSITORY_MAX_URL_LENGTH', 2048),
    'log_requests' => env('REMOTE_REPOSITORY_LOG_REQUESTS', true),  // Enable logging by default
    'recoverable_error_patterns' => [
        'Duplicate active delivery address codes found',
        // Add your custom patterns here
    ],
],
```

**💡 Pro Tip**: Use `API_GATEWAY_ENDPOINT` for the base URI to maintain consistency with existing Connect platform services. The defaults (2048 for max_url_length, true for log_requests) are sensible for most use cases.

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

1. **Add the new configuration structure to your `config/app.php`** (see example above)
2. **Use existing environment variable**: If your project already has `API_GATEWAY_ENDPOINT`, you're done! No new .env variables needed.
3. **Optional environment variables** (only set if you want to override defaults):
   ```bash
   # Only add these if you want to override the defaults
   REMOTE_REPOSITORY_MAX_URL_LENGTH=4096    # Default: 2048
   REMOTE_REPOSITORY_LOG_REQUESTS=false     # Default: true
   ```
4. **Update RemoteRepository implementations**:
   - Fix method calls: `cacheSingle()` → `cacheOne()`
   - Remove redundant method overrides that duplicate base class functionality
   - Clean up unused imports
5. **Test your application** to ensure everything works correctly
6. **Remove legacy config** - Comment out or remove old configuration keys from `pxc.php`, `jsonapi.php`, etc.

#### Quick Migration Checklist

- ✅ Add `remote_repository` config to `config/app.php`
- ✅ Verify `API_GATEWAY_ENDPOINT` is set in `.env`
- ✅ Update RemoteRepository method calls (`cacheSingle` → `cacheOne`)
- ✅ Run tests to verify functionality
- ✅ Remove/comment legacy config keys
- ✅ Clean up unused imports


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

### Distributed Tracing
📖 **[Complete Distributed Tracing Documentation](docs/distributed-tracing.md)**

Enterprise-grade distributed tracing with AWS X-Ray integration for complete request correlation across microservice boundaries.

**Key Features:**
- AWS X-Ray native support with automatic trace propagation
- Request ID continuity across service boundaries via RemoteRepository
- Cross-service error correlation and retry loop debugging
- CloudWatch/Elasticsearch compatibility with standardized field naming
- Zero-configuration setup with backward compatibility

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

📖 **[Complete Logging Integration Guide](docs/logging-integration.md)**

**Comprehensive Logging Solution** - Battle-tested implementation from connect-order that fixed 42.2M logs routing to wrong Elasticsearch indexes.

**Key Components:**
* `LogFields` - **Enhanced with distributed tracing fields** - Extensible base class for consistent field naming across services
* `AddTargetProcessor` - Configurable processor for Elasticsearch routing (fixes index routing issues)
* `RequestLoggingMiddleware` - **X-Ray trace capture enabled** - Base middleware for automatic request context and distributed tracing in all logs
* `LogsControllerActions` - Trait for comprehensive controller action logging with minimal code
* `ErrorLogger` - Centralized error logging with appropriate log levels and API error formatting
* `CustomizeMonoLog` - Base Monolog customizer that services can extend

**Existing Components:**
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

