# nui-laravel-shared-utils

Shared Classes for Laravel

Note these are specific to our use case however you may find some value in the code.

https://packagist.org/packages/nuimarkets/laravel-shared-utils

## Installation

```
composer require nuimarkets/laravel-shared-utils
```


## Classes

### Testing

* `DBSetupExtension` - DB Setup Extension for phpUnit to drop/create testing database with migrations run

### Exceptions

* `BaseErrorHandler` - Base Error Handler
* `BaseHttpRequestException` - Main Exception handler for something gone wrong in the request

### Logging

* `SensitiveDataProcessor` - Log Processor for sanitizing sensitive data in log records
* `EnvironmentProcessor` - Log Processor for environment info etc
* `ColoredJsonLineFormatter` - Formats log records as colored JSON lines with improved readability.
* `SentryHandler` - Sentry Error Handler with support for tags and exceptions
* `SourceLocationProcessor` - Log Processor for PHP Source Location

### Http

* `BaseFormRequest` - Base Form Request - logging & error handling bits
* `ErrorTestController` - Test exception handling by using /test-error?exception=
* `HealthCheckController` - Detailed Health Checks
* `RequestMetrics` - Request Metrics

