# nui-laravel-shared-utils

Shared Classes for Laravel

Note these are specific to our use case however you may find some value in the code.

https://packagist.org/packages/nuimarkets/laravel-shared-utils

## Installation

```
composer require nuimarkets/laravel-shared-utils
```


## Documentation

### RemoteRepository
📖 **[Complete RemoteRepository Documentation](docs/RemoteRepository.md)**

The `RemoteRepository` is a comprehensive solution for service-to-service API communication with enhanced error handling, performance monitoring, and caching capabilities.

**Key Features:**
- JSON API client integration with automatic retry logic
- ValidationException handling for malformed responses  
- Performance monitoring with ProfilingTrait
- Intelligent caching for single items and collections
- Multi-config fallback system (jsonapi → pxc → remote)
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

