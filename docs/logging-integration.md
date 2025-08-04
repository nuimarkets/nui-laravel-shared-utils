# Logging Integration Guide

This guide explains how to integrate the shared logging utilities into your Laravel service.

## Table of Contents

- [Overview](#overview)
- [Quick Start](#quick-start)
- [Advanced Configuration](#advanced-configuration)
- [Migration from Custom Logging](#migration-from-custom-logging)
- [Benefits](#benefits)
- [Troubleshooting](#troubleshooting)
- [Support](#support)

## Overview

The shared logging utilities provide:
- **Automatic Elasticsearch routing** - Ensures logs go to the correct service-specific index
- **Consistent field naming** - Standardized log fields across all services
- **Request context tracking** - Automatic inclusion of request ID, user context, etc.
- **Controller action logging** - Simple trait to add comprehensive logging to controllers
- **Error logging utilities** - Centralized error formatting with appropriate log levels
- **Sensitive data protection** - Automatic redaction of passwords, tokens, etc.

## Quick Start

### 1. Install the Package

```bash
composer require nuimarkets/laravel-shared-utils
```

### 2. Create Your Service-Specific LogFields

Create a class that extends the base LogFields to add service-specific fields:

```php
<?php

namespace App\Logging;

use NuiMarkets\LaravelSharedUtils\Logging\LogFields as BaseLogFields;

class OrderLogFields extends BaseLogFields
{
    // Add service-specific fields
    const ORDER_ID = 'order_id';
    const ORDER_STATUS = 'order_status';
    const ORDER_TOTAL = 'order_total';
    
    public static function getServiceSpecificFields(): array
    {
        return [
            'ORDER_ID' => self::ORDER_ID,
            'ORDER_STATUS' => self::ORDER_STATUS,
            'ORDER_TOTAL' => self::ORDER_TOTAL,
        ];
    }
}
```

### 3. Configure Monolog

Create a service-specific Monolog customizer:

```php
<?php

namespace App\Logging;

use NuiMarkets\LaravelSharedUtils\Logging\CustomizeMonoLog as BaseCustomizeMonoLog;
use NuiMarkets\LaravelSharedUtils\Logging\Processors\AddTargetProcessor;

class CustomizeMonoLog extends BaseCustomizeMonoLog
{
    protected function createTargetProcessor(): AddTargetProcessor
    {
        // Replace 'connect-order' with your service name
        return new AddTargetProcessor('connect-order');
    }
}
```

Update your `config/logging.php` to use the customizer:

```php
'channels' => [
    'stack' => [
        'driver' => 'stack',
        'channels' => ['single'],
        'tap' => [App\Logging\CustomizeMonoLog::class],
    ],
    // ... other channels
],
```

### 4. Create Request Logging Middleware (Optional)

If you want automatic request context in all logs:

```php
<?php

namespace App\Http\Middleware;

use App\Logging\OrderLogFields;
use Illuminate\Http\Request;
use NuiMarkets\LaravelSharedUtils\Http\Middleware\RequestLoggingMiddleware;

class OrderLoggingMiddleware extends RequestLoggingMiddleware
{
    protected function addServiceContext(Request $request, array $context): array
    {
        // Add order ID if present in route
        if ($orderId = $request->route('id')) {
            $context[OrderLogFields::ORDER_ID] = $orderId;
        }
        
        return $context;
    }
}
```

Register the middleware in `app/Http/Kernel.php`:

```php
protected $middlewareGroups = [
    'api' => [
        // ... other middleware
        \App\Http\Middleware\OrderLoggingMiddleware::class,
    ],
];
```

### 5. Use the Logging Traits in Controllers

```php
<?php

namespace App\Http\Controllers;

use NuiMarkets\LaravelSharedUtils\Http\Controllers\Traits\LogsControllerActions;

class OrderController extends Controller
{
    use LogsControllerActions;
    
    public function store(CreateOrderRequest $request)
    {
        $this->logActionStart('store', $request);
        
        try {
            $order = $this->orderService->create($request->validated());
            
            $this->logActionSuccess('store', $order, $request);
            
            return response()->json($order);
        } catch (\Exception $e) {
            $this->logActionFailure('store', $e, $request);
            throw $e;
        }
    }
}
```

### 6. Use the Error Logger

```php
use NuiMarkets\LaravelSharedUtils\Logging\ErrorLogger;

try {
    // Your code here
} catch (\Exception $e) {
    ErrorLogger::logException($e, [
        'order_id' => $orderId,
        'action' => 'process_payment',
    ]);
}

// Log validation errors
ErrorLogger::logValidationError($validator->errors()->toArray(), [
    'feature' => 'order_creation',
]);

// Log API errors
ErrorLogger::logApiError('payment-gateway', '/charge', $response, [
    'order_id' => $orderId,
]);
```

## Advanced Configuration

### Publishing Configuration

To customize the logging configuration:

```bash
php artisan vendor:publish --tag=logging-utils-config
```

This creates `config/logging-utils.php` where you can customize:
- Target processor settings
- Middleware configuration
- Error logging behavior
- Sensitive data patterns

### Using the Service Provider

For automatic configuration, register the LoggingServiceProvider:

```php
// config/app.php
'providers' => [
    // ... other providers
    NuiMarkets\LaravelSharedUtils\Providers\LoggingServiceProvider::class,
],
```

### Environment Variables

- `LOG_TARGET` - Override the service name for Elasticsearch routing
- `APP_DEBUG` - Controls whether stack traces are included in error logs

## Migration from Custom Logging

If you're migrating from custom logging implementation:

1. **Update imports** - Change from local classes to shared utils
2. **Extend base classes** - Modify your existing classes to extend the shared base classes
3. **Test thoroughly** - Ensure all logging still works as expected

## Benefits

1. **Fixes Elasticsearch Routing** - No more logs in wrong indexes
2. **Reduces Code Duplication** - ~300 lines of logging code becomes simple trait usage
3. **Consistent Logging** - All services use same field names and patterns
4. **Easy Integration** - Services can integrate in minutes
5. **Extensible Design** - Services can add their own fields and customize behavior
6. **Well Tested** - Comprehensive test suite ensures reliability

## Troubleshooting

### Logs not appearing in correct Elasticsearch index

1. Verify the target processor is configured correctly
2. Check that your Monolog customizer is being called
3. Ensure the `target` field is present in log context

### Missing request context

1. Ensure the request logging middleware is registered
2. Verify it's in the correct middleware group
3. Check that routes are using that middleware group

### Sensitive data appearing in logs

1. The SensitiveDataProcessor should handle common patterns
2. Add custom patterns to `config/logging-utils.php`
3. Report any missed patterns for inclusion in the base processor

## Support

For issues or questions:
- Check the test files for usage examples
- Review connect-order implementation as a reference
- Create an issue in the repository