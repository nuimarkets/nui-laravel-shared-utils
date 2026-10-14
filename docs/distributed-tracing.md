# Distributed Tracing with X-Ray Integration

## Overview

This library provides distributed tracing support for Laravel applications:
one AWS X-Ray trace id joins a request, the queued jobs and console commands it
leads to, and every downstream service call any of them makes. The trace is
decided once, where each unit of work starts, and every RemoteRepository call
sends it as an X-Ray `X-Amzn-Trace-Id` header.

## Table of Contents

- [Features](#features)
- [Quick Start](#quick-start)
- [Queued Jobs and Console Commands](#queued-jobs-and-console-commands)
- [Architecture](#architecture)
- [Configuration](#configuration)
- [Usage Examples](#usage-examples)
- [Field Reference](#field-reference)
- [Troubleshooting](#troubleshooting)
- [Migration Guide](#migration-guide) (including [Upgrading to 0.8.0](#upgrading-to-080))

## Features

### Core Capabilities
- **AWS X-Ray Integration** - Native support for AWS X-Ray distributed tracing with automatic trace propagation
- **Request ID Propagation** - Continuous request ID tracking across service boundaries
- **Automatic Header Extraction** - Seamless capture of trace IDs from incoming requests
- **Service-to-Service Correlation** - Complete request correlation through RemoteRepository calls
- **CloudWatch Compatibility** - Field naming aligned with downstream log processing

### Enhanced Debugging
- **Cross-Service Tracing** - Track requests from API Gateway through entire service chains
- **Retry Loop Detection** - Debug infinite retry scenarios with complete trace correlation
- **Performance Analysis** - Service boundary timing analysis and bottleneck identification
- **Error Correlation** - Link errors across multiple services with shared trace context

## Quick Start

### 1. TracingServiceProvider

Nothing to do: the package auto-discovers `TracingServiceProvider`, which starts
the trace of every request, queued job and console command run (see
[Queued Jobs and Console Commands](#queued-jobs-and-console-commands)). A service
that turns package discovery off lists it where it registers its own providers
instead:

```php
// bootstrap/providers.php's returned list, or the 'providers' array in config/app.php
NuiMarkets\LaravelSharedUtils\Providers\TracingServiceProvider::class,
```

Without it, RemoteRepository sends no trace header at all.

### 2. RequestLoggingMiddleware Integration

Extend the abstract `RequestLoggingMiddleware` to automatically capture trace context:

```php
<?php

namespace App\Http\Middleware;

use NuiMarkets\LaravelSharedUtils\Http\Middleware\RequestLoggingMiddleware;

class ServiceRequestLoggingMiddleware extends RequestLoggingMiddleware
{
    protected function addServiceContext(Request $request, array $context): array
    {
        $context['service_name'] = 'auth-service';
        
        // Add route-specific context
        if ($route = $request->route()) {
            $context['route_name'] = $route->getName();
        }
        
        return $context;
    }
}
```

### 3. Register Middleware

Add to your `app/Http/Kernel.php`:

```php
protected $middleware = [
    // Other middleware...
    \App\Http\Middleware\ServiceRequestLoggingMiddleware::class,
];
```

### 4. RemoteRepository Usage

The RemoteRepository automatically propagates trace context:

```php
<?php

namespace App\RemoteRepositories;

use NuiMarkets\LaravelSharedUtils\RemoteRepositories\RemoteRepository;

class ProductRepository extends RemoteRepository
{
    protected function filter(array $productIds)
    {
        // Trace context automatically propagated in headers:
        // X-Amzn-Trace-Id: Root=1-67a92466-..;Parent=53995c3f42cd8ad8;Sampled=1
        // X-Request-ID: original-request-uuid (during a request that has one)
        
        $response = $this->get('v1/products?ids=' . implode(',', $productIds));
        $this->cache($response);
        
        return $this->handleResponse($response);
    }
}
```

## Queued Jobs and Console Commands

`TracingServiceProvider` decides each unit of work's trace once, where the work
starts, and holds it in `TraceContext`. Everything after that reads it from
there: RemoteRepository's outbound header, the trace stamped onto each job
dispatched, and a job's or command's log fields.

| Unit of work | Trace once it starts | Outbound `X-Amzn-Trace-Id` |
| --- | --- | --- |
| Request with an inbound X-Ray header | Root, Parent and Sampled from that header | `Root=r;Parent=p;Sampled=s` |
| Request with no valid Root (local runs, tests) | A minted root | `Root=r` |
| Sync job (runs inside its dispatcher) | Its dispatcher's, unchanged | Its dispatcher's |
| Queued job dispatched by a request or another job | Root and sampling decision from its payload | `Root=r;Sampled=s` |
| Queued job dispatched by a top-level command run | Root from its payload | `Root=r` |
| Job arriving with no usable root (pushed by another producer, or queued before the provider was registered) | A minted root | `Root=r` |
| Console command run | A minted root, one for the whole run. A command started with `Artisan::call()` keeps its caller's | `Root=r` |

How it works:

- **Entries.** `StartTraceContext`, which the provider prepends to the global
  middleware stack, starts a request's trace before a service's own middleware
  runs. A `JobProcessing` listener starts a queued job's from its payload, for
  every queued job, so a job never sees the previous job's trace, including
  under a worker that does not reset scoped instances between jobs, such as
  this package's `WorkCommand`. A `CommandStarting` listener starts a command
  run's when nothing has started one yet.
- **Header shape.** Outbound calls always send X-Ray's `Root=` form, and only
  its `Root`, `Parent` and `Sampled` fields; a load balancer adds its own
  `Self`. A bare id is never sent: API Gateway does not read `1-...` alone as an
  X-Ray header and starts a new trace, so the call would lose its caller's id.
  An inbound header whose Root is not a valid X-Ray trace id
  (`1-<8 hex>-<24 hex>`) starts a new trace; an invalid Parent, or a Sampled
  other than `0` or `1`, is dropped.
- **Parent on the request path only.** A request forwards the Parent it
  received, which keeps each downstream hop nested under its caller in X-Ray. A
  queued job is not a child of its dispatcher's call: it runs on its own,
  usually after that call has returned, so it sends no Parent.
- **Sampling travels with jobs.** A job carries its dispatcher's root under
  `nui_trace_id` and its sampling decision under `nui_trace_sampled` (the
  string `"1"` or `"0"`, absent when there was none; any other value is
  ignored). API Gateway records a hop that arrives
  with `Sampled=1` in the caller's trace, so a sampled request's jobs show up in
  its X-Ray trace.
- **Log fields.** A job's or command's root joins the log context as
  `request.amz_trace_id` and the legacy `request.trace_id`, the same fields
  request logs carry. Both hold the bare root. A request's own fields stay
  `RequestLoggingMiddleware`'s, which logs the raw inbound header in
  `request.amz_trace_id`, so search by the root to find its jobs.
- **Nothing started, nothing sent.** Code running outside the three entries,
  such as a unit test, or a feature test that calls `withoutMiddleware()` (which
  skips the request entry too), has no trace: RemoteRepository sends no trace
  header, a queued job it dispatches mints its own root when it starts, and a
  sync job it dispatches runs with none.
- **Sync jobs** run inside their dispatcher and keep its trace, Parent
  included, leaving a request's raw `X-Amzn-Trace-Id` in the log context
  untouched.
- **No correlation header.** RemoteRepository sends no `X-Correlation-ID`: a
  non-proxy API Gateway HTTP integration forwards only the headers it maps, so
  the root in `X-Amzn-Trace-Id` is what reliably reaches the next service.

Minted roots use the X-Ray trace id format, `1-<8 hex epoch seconds>-<24 hex>`,
so they travel the same header and land in the same downstream log field as a
root the gateway issued. A producer outside Laravel can set `nui_trace_id` (and
`nui_trace_sampled`) in a job's payload to continue an existing trace, as
strings, with the root in lowercase hex. A queued job or a top-level command run
sends no `X-Request-ID`: each service mints its own.

## Architecture

### Request Flow with Distributed Tracing

```text
User Request → API Gateway → Service A (auth-service)
    [X-Amzn-Trace-Id: Self=...;Root=1-67a92466-..;Parent=53995c3f42cd8ad8;Sampled=1]
                    ↓ StartTraceContext keeps Root, Parent and Sampled
                    ↓ RequestLoggingMiddleware logs request.trace_id, request.amz_trace_id
                    ↓ Business logic processes request
    Service A → API Gateway → Service B (product-service)
    [X-Amzn-Trace-Id: Root=1-67a92466-..;Parent=53995c3f42cd8ad8;Sampled=1]
                    ↓ X-Ray nests the hop under Service A's caller
    Service A queues a job
    [payload: nui_trace_id=1-67a92466-.., nui_trace_sampled=1]
    Worker → API Gateway → Service B
    [X-Amzn-Trace-Id: Root=1-67a92466-..;Sampled=1]
                    ↓ X-Ray records the hop in the same trace, with no parent
```

### Header Propagation Strategy

Every RemoteRepository call builds its headers from the current unit of work:

1. **X-Amzn-Trace-Id**: the trace `TraceContext` holds, always in X-Ray's
   `Root=` form (see the table under
   [Queued Jobs and Console Commands](#queued-jobs-and-console-commands))
2. **X-Request-ID**: the inbound request's, during a request that has one,
   including a sync job or `Artisan::call()` it runs; never sent from a queued
   job or a top-level command run

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
// In auth-service
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

// In product-service (different service, same trace)
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
// When an error occurs in product-service
try {
    $products = $this->productService->getProducts($ids);
} catch (Exception $e) {
    Log::error('Product retrieval failed', [
        'error' => $e->getMessage(),
        'product_ids' => $ids
    ]);
    
    // Error logs automatically include trace context
    // Can be correlated with original request in auth-service
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
| --- | --- | --- |
| `X-Request-ID` | Request continuity, during a request that has one | `550e8400-e29b-41d4-a716-446655440000` |
| `X-Amzn-Trace-Id` | AWS X-Ray tracing: `Root=r;Parent=p;Sampled=s` from a request, `Root=r[;Sampled=s]` from a job or command | `Root=1-67a92466-...;Parent=...;Sampled=1` |

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

#### Issue: No X-Amzn-Trace-Id on Outbound Calls

**Cause**: `TracingServiceProvider` is not registered (the service turned package
discovery off), or the call runs outside a request, a queued job and a command
run, such as in a unit test
**Solution**: Register the provider (see [Quick Start](#1-tracingserviceprovider)).
Outside the three entries no trace has started, and none is sent by design

#### Issue: A Job's Downstream Calls Start a New Trace

**Cause**: The calling service runs a version before 0.8.0, which sent a job's
or command's id bare (`1-...`), a value API Gateway does not continue
**Solution**: Upgrade to 0.8.0 or later, which always sends `Root=...`

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

Outbound headers are assembled per call and are not readable from a subclass, so
verify them from what the client is handed rather than from repository state.

Enable request logging and read the emitted entry:

```php
// config/app.php
'remote_repository' => [
    'log_requests' => true,
],
```

`POST` logs the outbound header *names* under `Request Debug`, and `GET` logs the
URL under `API GET`. Values are deliberately omitted: the set carries the machine
token and the caller's actor context. To see values, capture them at the client,
which is also how to assert on them in a test. A test that calls a repository
directly runs outside the three entries, so it starts the trace itself first:

```php
app(TraceContext::class)->startFromHeader('Root=1-67a92466-4b6aa15a05ffcd4c510de968;Sampled=1');

$client->method('get')->willReturnCallback(function ($url, $headers) {
    $this->assertSame('Root=1-67a92466-4b6aa15a05ffcd4c510de968;Sampled=1', $headers['X-Amzn-Trace-Id'] ?? null);

    return new Document;
});
```

## Migration Guide

### Upgrading to 0.8.0

- **The provider is auto-discovered.** An explicit `TracingServiceProvider`
  entry in `config/app.php` can stay: Laravel registers a provider class once.
  Without the provider, RemoteRepository sends no trace header at all, since it
  no longer reads the inbound request itself. Two ways to end up without it:
  - Laravel reads discovered providers from `bootstrap/cache/packages.php` and
    rebuilds that file only when it is missing. The skeleton's
    `post-autoload-dump` scripts (`ComposerScripts::postAutoloadDump`, which
    deletes it, and `php artisan package:discover`) refresh it on every
    `composer install`. A project without them, or an install run with
    `--no-scripts`, keeps a copy built before the upgrade, so run
    `php artisan package:discover` once (or delete the file). A fresh image
    with no cached copy builds it on boot.
  - A project that turns discovery off for this package (`dont-discover`) must
    list the provider itself.
- **The outbound `X-Amzn-Trace-Id` is rebuilt, not forwarded.** A request sends
  `Root`, `Parent` and `Sampled` from its inbound header and drops `Self`,
  `Lineage` and any other field. An inbound Root that is not a valid X-Ray trace
  id is not continued: the request mints a root instead. A job or command sends
  `Root=<id>` instead of the bare id, so its calls stay on the trace that
  started it. `passthrough_headers` and `contextual_headers` entries naming
  `X-Amzn-Trace-Id` are ignored.
- **`X-Correlation-ID` is no longer sent.** Read the Root of `X-Amzn-Trace-Id`
  instead.
- **Removed**: `RemoteRepository::getCurrentTraceId()` and
  `getCurrentTraceHeader()` (protected), and `TraceContext::ensure()` and
  `set()`. `TraceContext::current()` no longer falls back to the request's
  header: the request entry has already started the trace. Use `start()`,
  `startFromHeader()`, `current()` and `header()`.
- **A request with no valid inbound Root** mints a root for its jobs and calls,
  and its own log fields keep whatever header arrived (none, or the invalid
  value), so they do not join its jobs. Under 0.7.x a request with no header
  wrote the root it minted into its log context at its first dispatch, and a
  bare or malformed inbound value travelled to its jobs and calls as it was.
- **Tests that drove the trace header through the request.** A test that put an
  inbound `X-Amzn-Trace-Id` on the bound request to get an outbound one now gets
  none. Start the trace with `TraceContext::startFromHeader()` or `start()`, or
  send the request through the kernel; `withoutMiddleware()` skips the request
  entry too.
- **Payloads are compatible both ways.** `nui_trace_id` keeps its shape and
  `nui_trace_sampled` is optional: a job queued by an earlier version restores
  its root with no sampling decision, and an earlier worker ignores the new
  key.

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
    // Headers automatically include X-Amzn-Trace-Id,
    // and X-Request-ID during a request that has one
}
```

#### Step 4: Update Log Processing
```php
// Log processor TOP_LEVEL_FIELDS config
// Add new fields to promote to root level:
"request.trace_id",
"request.amz_trace_id"
```

### Breaking Changes

Adopting the package in place of a custom correlation implementation changes no
existing logging patterns. Upgrading from 0.7.x has the breaking changes listed
under [Upgrading to 0.8.0](#upgrading-to-080).

## Performance Considerations

The distributed tracing implementation has minimal performance impact:

- **Header Extraction**: < 0.1ms per request (simple string parsing)
- **Context Addition**: < 0.5ms for log context enrichment
- **Header Propagation**: < 0.1ms added to outgoing requests
- **Total Overhead**: Typically under 1ms per request

The benefits of distributed tracing far outweigh the minimal performance cost, especially when debugging complex service interactions.

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
    'service' => 'product-service',
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