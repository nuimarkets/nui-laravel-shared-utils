# Distributed Tracing with X-Ray Integration

## Overview

This library provides comprehensive distributed tracing support for Laravel applications, enabling complete request correlation across microservice boundaries. The implementation supports AWS X-Ray native tracing and custom correlation IDs for debugging complex service interactions.

## Table of Contents

- [Features](#features)
- [Quick Start](#quick-start)
- [Architecture](#architecture)
- [Configuration](#configuration)
- [Usage Examples](#usage-examples)
- [Field Reference](#field-reference)
- [Troubleshooting](#troubleshooting)
- [Migration Guide](#migration-guide)

## Features

### Core Capabilities
- **AWS X-Ray Integration** - Native support for AWS X-Ray distributed tracing with automatic trace propagation
- **Request ID Propagation** - Continuous request ID tracking across service boundaries
- **Automatic Header Extraction** - Seamless capture of trace IDs from incoming requests
- **Service-to-Service Correlation** - Complete request correlation through RemoteRepository calls
- **CloudWatch Compatibility** - Field naming aligned with connect-cloudwatch-kinesis-to-es processing

### Enhanced Debugging
- **Cross-Service Tracing** - Track requests from API Gateway through entire service chains
- **Retry Loop Detection** - Debug infinite retry scenarios with complete trace correlation
- **Performance Analysis** - Service boundary timing analysis and bottleneck identification
- **Error Correlation** - Link errors across multiple services with shared trace context

## Quick Start

### 1. RequestLoggingMiddleware Integration

Extend the abstract `RequestLoggingMiddleware` to automatically capture trace context:

```php
<?php

namespace App\Http\Middleware;

use NuiMarkets\LaravelSharedUtils\Http\Middleware\RequestLoggingMiddleware;

class ServiceRequestLoggingMiddleware extends RequestLoggingMiddleware
{
    protected function addServiceContext(Request $request, array $context): array
    {
        $context['service_name'] = 'connect-auth';
        
        // Add route-specific context
        if ($route = $request->route()) {
            $context['route_name'] = $route->getName();
        }
        
        return $context;
    }
}
```

### 2. Register Middleware

Add to your `app/Http/Kernel.php`:

```php
protected $middleware = [
    // Other middleware...
    \App\Http\Middleware\ServiceRequestLoggingMiddleware::class,
];
```

### 3. RemoteRepository Usage

The RemoteRepository automatically propagates trace context:

```php
<?php

namespace App\RemoteRepositories;

use NuiMarkets\LaravelSharedUtils\RemoteRepositories\RemoteRepository;

class ProductRepository extends RemoteRepository
{
    protected function filter(array $productIds)
    {
        // X-Ray trace context automatically propagated in headers:
        // X-Amzn-Trace-Id: Root=1-67a92466-..;Parent=53995c3f;Sampled=1
        // X-Request-ID: original-request-uuid
        // X-Correlation-ID: extracted-trace-id
        
        $response = $this->get('v1/products?ids=' . implode(',', $productIds));
        $this->cache($response);
        
        return $this->handleResponse($response);
    }
}
```

## Architecture

### Request Flow with Distributed Tracing

```
User Request → API Gateway → Service A (connect-auth)
    [X-Amzn-Trace-Id: Root=1-67a92466-..;Parent=...;Sampled=1]
                    ↓ RequestLoggingMiddleware captures trace context
             Logs: request.trace_id, request.amz_trace_id
                    ↓ Business logic processes request
    Service A → API Gateway → Service B (connect-product)
    [X-Amzn-Trace-Id: Root=1-67a92466-..;Parent=NEW;Sampled=1]
                    ↓ X-Ray automatically links as parent-child
             Complete trace chain maintained ✅
```

### Header Propagation Strategy

The implementation uses a **hybrid approach** for maximum compatibility:

1. **X-Amzn-Trace-Id**: Full AWS X-Ray header for native trace continuity
2. **X-Request-ID**: Original request ID for custom correlation
3. **X-Correlation-ID**: Extracted trace ID for fallback scenarios

## Configuration

### Environment Variables

No additional configuration required! The implementation automatically detects and uses X-Ray trace headers when available.

### Optional Customization

Override header names in your middleware implementation:

```php
class CustomRequestLoggingMiddleware extends RequestLoggingMiddleware
{
    protected string $requestIdHeader = 'X-Custom-Request-ID';
    
    // Middleware automatically handles X-Amzn-Trace-Id extraction
}
```

## Usage Examples

### Basic Logging with Trace Context

```php
// In any controller or service after middleware runs
Log::info('Processing order', [
    'order_id' => $orderId,
    'product_count' => count($products)
]);

// Automatically includes trace context:
// {
//   "message": "Processing order",
//   "context": {
//     "order_id": "12345",
//     "product_count": 3
//   },
//   "request_id": "550e8400-e29b-41d4-a716-446655440000",
//   "request.trace_id": "1-67a92466-4b6aa15a05ffcd4c510de968",
//   "request.amz_trace_id": "Root=1-67a92466-4b6aa15a05ffcd4c510de968;Parent=53995c3f42cd8ad8;Sampled=1"
// }
```

### Service-to-Service Calls

```php
// In connect-auth service
class OrderService
{
    public function processOrder($orderData)
    {
        Log::info('Starting order processing');
        
        // RemoteRepository call automatically propagates trace context
        $products = $this->productRepository->findByIds($orderData['product_ids']);
        
        Log::info('Retrieved products', ['product_count' => count($products)]);
        
        return $this->createOrder($orderData, $products);
    }
}

// In connect-product service (different service, same trace)
class ProductController
{
    public function getProducts(Request $request)
    {
        // Same trace ID automatically available
        Log::info('Product request received', [
            'requested_ids' => $request->get('ids')
        ]);
        
        // All logs from both services linked by trace ID
    }
}
```

### Error Correlation Across Services

```php
// When an error occurs in connect-product
try {
    $products = $this->productService->getProducts($ids);
} catch (Exception $e) {
    Log::error('Product retrieval failed', [
        'error' => $e->getMessage(),
        'product_ids' => $ids
    ]);
    
    // Error logs automatically include trace context
    // Can be correlated with original request in connect-auth
    throw new RemoteServiceException('Product service unavailable');
}
```

## Field Reference

### Log Fields Added by RequestLoggingMiddleware

| Field | Type | Description | Example |
|-------|------|-------------|---------|
| `request_id` | string | Unique request identifier | `550e8400-e29b-41d4-a716-446655440000` |
| `request.trace_id` | string | Extracted X-Ray trace ID | `1-67a92466-4b6aa15a05ffcd4c510de968` |
| `request.amz_trace_id` | string | Full X-Ray trace header | `Root=1-67a92466-...;Parent=...;Sampled=1` |

### Headers Added by RemoteRepository

| Header | Purpose | Example |
|--------|---------|---------|
| `X-Request-ID` | Request continuity | `550e8400-e29b-41d4-a716-446655440000` |
| `X-Amzn-Trace-Id` | AWS X-Ray tracing | `Root=1-67a92466-...;Parent=...;Sampled=1` |
| `X-Correlation-ID` | Fallback correlation | `1-67a92466-4b6aa15a05ffcd4c510de968` |

## Troubleshooting

### Common Issues

#### Issue: Traces Not Linking Across Services
**Cause**: X-Ray not enabled on API Gateway or services
**Solution**: Ensure X-Ray tracing is enabled on all AWS components

#### Issue: Missing Trace IDs in Logs
**Cause**: RequestLoggingMiddleware not registered
**Solution**: Add middleware to `app/Http/Kernel.php`

#### Issue: Service-to-Service Calls Not Correlated
**Cause**: RemoteRepository not using shared utils library
**Solution**: Ensure all services use `nuimarkets/laravel-shared-utils`

### Debug Mode

Enable request logging to debug trace propagation:

```php
// config/app.php
'remote_repository' => [
    'log_requests' => env('REMOTE_REPOSITORY_LOG_REQUESTS', true),
    // ... other config
],
```

### Verification

Check that headers are being propagated correctly:

```php
// In RemoteRepository implementation
protected function filter(array $data)
{
    Log::debug('Remote request headers', [
        'headers' => $this->headers  // Check X-Amzn-Trace-Id is present
    ]);
    
    // Continue with API call...
}
```

## Migration Guide

### From Custom Correlation Implementation

If your service already implements custom correlation:

#### Step 1: Update Dependencies
```bash
composer require nuimarkets/laravel-shared-utils:^latest
```

#### Step 2: Replace Custom Middleware
```php
// Remove custom middleware
// App\Http\Middleware\RequestCorrelationMiddleware

// Add shared middleware
App\Http\Middleware\ServiceRequestLoggingMiddleware extends RequestLoggingMiddleware
```

#### Step 3: Update RemoteRepository Usage
```php
// Old - custom correlation
class ProductRepository
{
    private function addCorrelationHeaders()
    {
        return ['X-Correlation-ID' => session('correlation_id')];
    }
}

// New - automatic trace propagation
class ProductRepository extends RemoteRepository
{
    // Headers automatically include X-Amzn-Trace-Id, X-Request-ID, X-Correlation-ID
}
```

#### Step 4: Update Log Processing
```php
// connect-cloudwatch-kinesis-to-es TOP_LEVEL_FIELDS
// Add new fields to promote to root level:
"request.trace_id",
"request.amz_trace_id"
```

### Breaking Changes

**None** - The implementation maintains full backward compatibility with existing logging patterns.

## Best Practices

### 1. Always Use Middleware
Register RequestLoggingMiddleware early in the middleware stack for complete trace coverage.

### 2. Consistent Field Names
Use the standardized field names (`request.trace_id`, `request.amz_trace_id`) for compatibility with log processing pipelines.

### 3. RemoteRepository Extension
Always extend the shared RemoteRepository for service-to-service calls to ensure automatic trace propagation.

### 4. Error Handling
Include trace context in error logs for easier debugging:

```php
Log::error('Service unavailable', [
    'service' => 'connect-product',
    'endpoint' => '/v1/products',
    'error' => $exception->getMessage()
]);
// Trace context automatically included
```

### 5. Performance Monitoring
Use trace IDs to correlate performance metrics across service boundaries.

## Related Documentation

- **[RemoteRepository Documentation](RemoteRepository.md)** - Complete API communication guide
- **[Logging Integration Guide](logging-integration.md)** - Comprehensive logging setup
- **[Intercom Integration](intercom-integration.md)** - User analytics and event tracking

---

## License

This documentation is part of the `nuimarkets/laravel-shared-utils` package and is licensed under the MIT License.