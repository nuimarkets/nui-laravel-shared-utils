# Laravel Shared Utilities

[![Latest Version](https://img.shields.io/packagist/v/nuimarkets/laravel-shared-utils.svg?style=flat-square)](https://packagist.org/packages/nuimarkets/laravel-shared-utils)
[![PHP Version](https://img.shields.io/packagist/php-v/nuimarkets/laravel-shared-utils.svg?style=flat-square)](https://packagist.org/packages/nuimarkets/laravel-shared-utils)
[![Laravel Version](https://img.shields.io/badge/laravel-8.x%20|%209.x%20|%2010.x-brightgreen.svg?style=flat-square)](https://laravel.com)
[![Tests](https://img.shields.io/github/actions/workflow/status/nuimarkets/nui-laravel-shared-utils/tests.yml?branch=master&label=tests&style=flat-square)](https://github.com/nuimarkets/nui-laravel-shared-utils/actions)
[![License](https://img.shields.io/packagist/l/nuimarkets/laravel-shared-utils.svg?style=flat-square)](https://packagist.org/packages/nuimarkets/laravel-shared-utils)

A comprehensive Laravel package providing standardized components for building robust microservice architectures. Battle-tested in production environments handling millions of requests.

## Quick Start

```bash
composer require nuimarkets/laravel-shared-utils
```

```php
// Enable complete request lifecycle logging in 2 minutes
class ServiceRequestLogger extends RequestLoggingMiddleware {
    protected function getServiceName(): string {
        return 'my-service'; // Your service name
    }

    protected function addServiceContext($request, $context) {
        $context['resource_id'] = $request->route('id');
        return $context;
    }
}
// Register as global middleware → Automatic request start/complete + X-Ray correlation + performance metrics

// Add comprehensive health checks instantly
Route::get('/healthcheck', [HealthCheckController::class, 'detailed']);
```

## Key Features

### **Request Lifecycle Logging**

Complete request tracking with automatic performance metrics, X-Ray trace correlation, and customizable service context. One middleware class gives you request start/complete logs, duration tracking, memory usage, and business logic correlation.

### **Distributed Tracing**

Native AWS X-Ray integration with automatic trace propagation across microservices. Track requests through your entire service mesh with zero configuration. Automatic response headers (`X-Request-ID` and `X-Trace-ID`) for frontend correlation.

### **Advanced Logging**

Production-ready logging with automatic Elasticsearch routing, configurable sensitive data redaction, and structured JSON formatting. Fixes common logging issues that cause logs to end up in wrong indexes. Supports privacy compliance with flexible field-level redaction controls.

### **Health Monitoring**

Comprehensive health checks for MySQL, PostgreSQL, Redis, RabbitMQ, storage, cache, and PHP environment. Get detailed diagnostics with a single endpoint.

### **Service Communication**

Enhanced RemoteRepository with lazy token loading for optimal performance, retry logic, intelligent caching, automatic JWT authentication, and **HTTP status code preservation**. Original status codes (404, 500, 503, etc.) are preserved instead of being wrapped as 502, enabling smarter retry logic and failure classification.

### **Failure Caching**

Intelligent caching of remote service failures to prevent cascading timeouts during outages. HTTP status-aware TTLs cache 404s longer than transient errors like timeouts. Includes failure classification, configurable per-category TTLs, and convenience methods for handling cached failures gracefully.

### **Analytics Integration**

Complete Intercom integration for user analytics and event tracking with queue-based processing and multi-tenant support.

### **JSON API Validation**

Standardized JSON:API error handling with consistent validation responses. Provides unified error formatting across all services without configuration.

### **Testing Utilities**

Automated test database management, specialized test jobs for queue testing, JSON API validation assertions, and base test cases for rapid test development.

## Requirements

- PHP 8.0 or higher
- Laravel 8.x, 9.x, or 10.x
- Composer 2.x

## Installation

```bash
composer require nuimarkets/laravel-shared-utils
```

### Optional Configuration Publishing

```bash
# Publish all configs
php artisan vendor:publish --provider="NuiMarkets\LaravelSharedUtils\Providers\LoggingServiceProvider"

# Publish specific configs
php artisan vendor:publish --tag=logging-utils-config
php artisan vendor:publish --tag=intercom-config
```

## Documentation

### Core Components

| Component | Description | Documentation |
|-----------|-------------|---------------|
| **Distributed Tracing** | AWS X-Ray integration with request correlation | [Guide](docs/distributed-tracing.md) |
| **Logging System** | Enhanced logging with Elasticsearch routing | [Guide](docs/logging-integration.md) |
| **RemoteRepository** | Service-to-service communication framework | [Guide](docs/RemoteRepository.md) |
| **Failure Caching** | Cache remote failures to prevent cascading timeouts | [Guide](docs/failure-caching.md) |
| **JSON API Validation** | Standardized error handling with unified formatting | [Guide](docs/json-api-validation.md) |
| **Intercom Integration** | User analytics and event tracking | [Guide](docs/intercom-integration.md) |
| **IncludesParser** | API response optimization utility | [Guide](docs/includes-parser.md) |

### Quick Examples

#### Enable Request Lifecycle Logging

```php
// 1. Extend RequestLoggingMiddleware
class ServiceRequestLogger extends RequestLoggingMiddleware {
    protected function getServiceName(): string {
        return 'auth-service';
    }

    protected function addServiceContext($request, $context) {
        // Add route-specific context (JWT user context is automatically added)
        if ($userId = $request->route('userId')) {
            $context['target_user_id'] = $userId; // Route parameter, not JWT user
        }
        return $context;
    }

    // 3. Payload logging is disabled by default for security
    // Enable only when needed for debugging/development

    // 4. Optional: Configure path exclusions and payload logging
    public function __construct() {
        $this->configure([
            'excluded_paths' => ['/health*', '/metrics'], // Supports exact matches, globs, and prefixes
            'request_id_header' => 'X-Request-ID',
            // SECURITY: Payload logging disabled by default - only enable when needed
            'log_request_payload' => true, // Enable for debugging/development only
            // Both enabled by default - only specify if you want to disable:
            // 'add_request_id_to_response' => false,
            // 'add_trace_id_to_response' => false,
        ]);
        // Note: middleware-level exclusions complement any global defaults
    }
}

// 2. Register in Kernel.php
protected $middleware = [
    \App\Http\Middleware\ServiceRequestLogger::class,
];

// 4. Service calls automatically propagate traces
$this->productRepository->findByIds($productIds);
// Headers automatically include X-Amzn-Trace-Id
```

#### Configure Logging

```php
// 1. Create service-specific LogFields
class OrderLogFields extends LogFields {
    const ORDER_ID = 'order_id';
    const ORDER_STATUS = 'order_status';
}

// 2. Configure Monolog
use NuiMarkets\LaravelSharedUtils\Logging\CustomizeMonoLog as BaseCustomizeMonoLog;

class ServiceCustomizeMonoLog extends BaseCustomizeMonoLog {
    protected function createTargetProcessor() {
        return new AddTargetProcessor('order-service');
    }
}

// 3. Configure sensitive data redaction
// Option 1: Default behavior (auth + PII fields redacted)
$processor = new SensitiveDataProcessor();

// Option 2: Preserve debugging fields while still redacting PII
$processor = new SensitiveDataProcessor(['user_email', 'ip_address']);

// Option 3: Fluent configuration
$processor = (new SensitiveDataProcessor())
    ->preserveFields(['user_email', 'ip_address']); // Keep email and IP for debugging

// Option 4: Disable PII redaction (only auth fields)
$processor = new SensitiveDataProcessor([], false);

// 4. Logs automatically route to correct Elasticsearch index
Log::info('Order processed', ['order_id' => $order->id]);
```

#### Standardize JSON API Validation

```php
// 1. Use trait in FormRequest classes
use NuiMarkets\LaravelSharedUtils\Http\Requests\JsonApiValidation;

class CreateOrderRequest extends FormRequest {
    use JsonApiValidation;

    public function rules() {
        return ['email' => 'required|email'];
    }
}

// 2. Consistent error format across all services (uses Laravel dot-notation in pointers)
{
    "meta": {"message": "Validation Failed", "status": 422},
    "errors": [{
        "status": "422",
        "title": "Validation Error",
        "detail": "email: The email field is required.",
        "source": {"pointer": "/data/attributes/email"}
    }]
}
```

#### Add Health Checks

```php
// Routes automatically available at /healthcheck
Route::get('/healthcheck', [HealthCheckController::class, 'detailed']);

// Response includes all infrastructure status
{
    "status": "healthy",
    "checks": {
        "database": {"status": "up", "response_time": "5ms"},
        "cache": {"status": "up", "driver": "redis"},
        "queue": {"status": "up", "jobs_pending": 0}
    }
}
```

## Advanced Configuration

### Configurable Path Exclusions

Customize which paths to exclude from request logging:

```php
class ServiceRequestLogger extends RequestLoggingMiddleware {
    public function __construct() {
        $this->configure([
            'excluded_paths' => ['/healthcheck', '/health', '/metrics', '/status'],
            'request_id_header' => 'X-Request-ID',
            // Response headers enabled by default - only specify to disable:
            // 'add_request_id_to_response' => false,
            // 'add_trace_id_to_response' => false,
        ]);
    }
}
```

### Flexible Sensitive Data Redaction

Balance privacy compliance with debugging needs:

```php
// Default: PII redaction enabled by default
$processor = new SensitiveDataProcessor();

// Debugging-friendly: Preserve email and IP for troubleshooting
$processor = new SensitiveDataProcessor(['user_email', 'ip_address']);

// Fluent configuration
$processor = (new SensitiveDataProcessor())
    ->preserveFields(['user_email', 'ip_address']);

// Note: Some organizations treat user-agent as PII
// To preserve user-agent for debugging while redacting other PII:
$processor = new SensitiveDataProcessor(['user_agent']);

// Disable PII redaction (only auth fields)
$processor = new SensitiveDataProcessor([], false);
```

**Field Categories:**

- **Auth fields** (always redacted): password, token, secret, api_key, jwt, bearer
- **PII fields** (redacted by default): email, phone, address, ssn, credit_card, bank_account
- **Preserve fields**: Override redaction for specific debugging-friendly fields

## Architecture

This package follows a **trait-based architecture** allowing you to:

- Use only the components you need
- Extend base classes for customization
- Integrate gradually into existing codebases

### No Service Provider Required

The package intentionally doesn't auto-register a service provider, giving you full control over which components to use.

### Cross-Version Compatibility

| Laravel | PHP | Monolog | Orchestra Testbench |
|---------|-----|---------|---------------------|
| 8.x | 8.0+ | 2.x | 7.x |
| 9.x | 8.0+ | 2.x | 7.x |
| 10.x | 8.1+ | 3.x | 8.x |

## Configuration

### Environment Variables

```env
# Distributed Tracing (automatic with X-Ray)
# No configuration needed!

# Logging
LOG_TARGET=my-service
APP_DEBUG=true

# RemoteRepository
API_GATEWAY_ENDPOINT=https://api.example.com
REMOTE_REPOSITORY_MAX_URL_LENGTH=2048
REMOTE_REPOSITORY_LOG_REQUESTS=true

# Intercom
INTERCOM_ENABLED=true
INTERCOM_TOKEN=your_token
INTERCOM_SERVICE_NAME=my-service
```

### RemoteRepository Configuration

Add to your `config/app.php`:

```php
'remote_repository' => [
    'base_uri' => env('API_GATEWAY_ENDPOINT'),
    'max_url_length' => env('REMOTE_REPOSITORY_MAX_URL_LENGTH', 2048),
    'log_requests' => env('REMOTE_REPOSITORY_LOG_REQUESTS', true),

    // Failure caching (optional - defaults shown)
    'failure_cache_ttl' => env('REMOTE_FAILURE_CACHE_TTL', 120),
    'failure_cache_ttl_by_category' => [
        'not_found' => 600,      // 10 min for 404s
        'timeout' => 30,         // 30 sec for timeouts
        'server_error' => 120,   // 2 min for 5xx errors
    ],
],
```

[See full migration guide →](docs/RemoteRepository.md#migration-guide)

## Testing

```bash
# Run all tests
composer test-all

# Run specific tests
composer test RequestMetrics

# Generate coverage report
composer test-coverage

# Code quality
composer lint    # Check code style
composer format  # Fix code style
```

## Contributing

We welcome contributions! Please follow these guidelines:

1. Fork the repository and create your branch from `master`
2. Add tests for any new functionality
3. Ensure the test suite passes (`composer test-all`)
4. Follow the existing code style (`composer lint` and `composer format`)
5. Update documentation as needed
6. Submit a pull request with a clear description of changes

## Performance Impact

- **Distributed Tracing**: < 1ms overhead per request
- **Logging**: Asynchronous processing, no request blocking
- **Health Checks**: Cached results available, configurable timeouts
- **RemoteRepository**: Built-in caching reduces API calls by up to 80%

## Security

- Automatic redaction of sensitive data in logs
- JWT token validation for service-to-service communication
- Environment-aware security settings
- No credentials stored in code

## License

The MIT License (MIT). See [License File](LICENSE.md) for more information.

## Support

- [Documentation](docs/)
- [Issue Tracker](https://github.com/nuimarkets/nui-laravel-shared-utils/issues)
- [Discussions](https://github.com/nuimarkets/nui-laravel-shared-utils/discussions)

---

Built with ❤️ by [Nui Markets](https://nuimarkets.com)
