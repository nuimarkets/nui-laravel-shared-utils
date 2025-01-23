# nui-laravel-shared-utils

Shared Classes for Laravel

Note these are specific to our use case however you may find some value in the code.


## Installation

```
composer require nuimarkets/laravel-shared-utils
```


## Classes

### Exceptions

| Namespace | Class | Description |
|-----------|--------|-------------|
| `Nuimarkets\LaravelSharedUtils\Exceptions` | `BaseErrorHandler` | Base Error Handler |
| `Nuimarkets\LaravelSharedUtils\Exceptions` | `BaseHttpRequestException` | Main Exception handler for something gone wrong in the request |

### Logging

| Namespace | Class | Description |
|-----------|--------|-------------|
| `Nuimarkets\LaravelSharedUtils\Logging` | `SensitiveDataProcessor` | Log Processor for sanitizing sensitive data in log records |
| `Nuimarkets\LaravelSharedUtils\Logging` | `EnvironmentProcessor` | Log Processor for environment info etc |
| `Nuimarkets\LaravelSharedUtils\Logging` | `ColoredJsonLineFormatter` | Formats log records as colored JSON lines with improved readability. |
| `Nuimarkets\LaravelSharedUtils\Logging` | `SentryHandler` | Sentry Error Handler with support for tags and exceptions |
| `Nuimarkets\LaravelSharedUtils\Logging` | `SourceLocationProcessor` | Log Processor for PHP Source Location |

### Http

| Namespace | Class | Description |
|-----------|--------|-------------|
| `Nuimarkets\LaravelSharedUtils\Http\Requests` | `BaseFormRequest` | Base Form Request - logging & error handling bits |
| `Nuimarkets\LaravelSharedUtils\Http\Controllers` | `ErrorTestController` | Test exception handling by using /test-error?exception= |
| `Nuimarkets\LaravelSharedUtils\Http\Controllers` | `HealthCheckController` | Detailed Health Checks |
| `Nuimarkets\LaravelSharedUtils\Http\Middleware` | `RequestMetrics` | Request Metrics |

