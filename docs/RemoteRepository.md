# RemoteRepository Documentation

## Overview

The `RemoteRepository` is an abstract base class that provides standardized functionality for communicating with external APIs using the JSON API client. It includes enhanced error handling, performance monitoring, caching capabilities, and retry logic to ensure robust service-to-service communication in microservice architectures.

## Table of Contents

- [Features](#features)
- [Installation](#installation)
- [Basic Usage](#basic-usage)
- [Configuration](#configuration)
- [Header Forwarding](#header-forwarding)
- [Error Handling](#error-handling)
- [HTTP Status Code Preservation](#http-status-code-preservation)
- [Failure Caching](#failure-caching)
- [Performance Monitoring](#performance-monitoring)
- [Caching](#caching)
- [Testing](#testing)
- [Migration Guide](#migration-guide)
- [API Reference](#api-reference)

## Features

### Core Functionality

- **JSON API Client Integration** - Built on `swisnl/json-api-client` for standardized API communication
- **Lazy Token Loading** - Authentication tokens loaded on-demand to improve instantiation performance
- **JWT Authentication** - Automatic machine token injection for service-to-service communication
- **Header Forwarding** - Configurable passthrough and contextual headers for inter-service communication
- **Retry Logic** - Configurable retry attempts with exponential backoff
- **URL Length Validation** - Prevents HTTP 414 errors by validating request URL lengths

### Enhanced Error Handling

- **ValidationException Support** - Graceful handling of malformed API responses
- **HTTP Status Preservation** - Original status codes (404, 500, etc.) preserved instead of 502
- **Specialized Error Patterns** - Custom handling for known error scenarios
- **Comprehensive Logging** - Detailed error reporting with Sentry integration
- **Fallback Mechanisms** - Graceful degradation when external services are unavailable
- **Failure Caching** - Cache failed lookups to prevent cascading timeouts

### Performance Features

- **ProfilingTrait Integration** - Built-in performance monitoring and timing breakdowns
- **Request/Response Logging** - Configurable debug logging for API calls
- **Caching Support** - Intelligent caching for single items and collections
- **Memory Efficient** - Optimized for high-throughput scenarios

### Configuration Flexibility

- **Multi-Config Fallback** - Supports multiple configuration sources (`jsonapi`, `pxc`, `remote`)
- **Environment Awareness** - Adapts behavior based on application environment
- **Tenant Isolation** - Full support for multi-tenant architectures

## Installation

The RemoteRepository is included in the `nuimarkets/laravel-shared-utils` package:

```bash
composer require nuimarkets/laravel-shared-utils
```

### Dependencies

Required dependencies are automatically installed:

- `swisnl/json-api-client: ^2.2`
- `laravel/framework: ^8.0|^9.0|^10.0`

## Basic Usage

### Creating a Repository

Extend the abstract `RemoteRepository` class and implement the required `filter()` method:

```php
<?php

namespace App\RemoteRepositories;

use NuiMarkets\LaravelSharedUtils\RemoteRepositories\RemoteRepository;

class ProductRepository extends RemoteRepository
{
    /**
     * Filter and retrieve products by IDs
     */
    protected function filter(array $productIds)
    {
        if (count($productIds) === 1) {
            // Single product request
            $id = array_shift($productIds);
            $response = $this->get("v1/products/{$id}?include=categories");
            $this->cacheOne($response);
        } else {
            // Bulk product request
            $url = 'v1/products?ids=' . implode(',', $productIds);
            
            if ($this->allowedGetRequest($url)) {
                $response = $this->get($url);
            } else {
                // Use POST for large requests
                $body = $this->makeRequestBody(['ids' => $productIds]);
                $response = $this->post('v1/products/filter', $body);
            }
            
            $this->cache($response);
        }
        
        return $this->handleResponse($response);
    }
}
```

### Dependency Injection

Register the repository in your service provider:

```php
// app/Providers/AppServiceProvider.php
public function register()
{
    $this->app->singleton(ProductRepository::class, function ($app) {
        return new ProductRepository(
            $app->make(DocumentClientInterface::class),
            $app->make(MachineTokenService::class)
        );
    });
}
```

### Using the Repository

```php
// In your controller or service
public function getProducts(array $productIds)
{
    $repository = app(ProductRepository::class);
    
    // Retrieve products (automatically cached)
    $products = $repository->findByIds($productIds);
    
    // Get single product
    $product = $repository->findById('product-123');
    
    // Check if product exists in cache
    if ($repository->hasId('product-123')) {
        $cached = $repository->findByIdWithoutRetrieve('product-123');
    }
    
    return $products;
}
```

## Configuration

### Base URI Configuration

The RemoteRepository requires a base URI configuration:

```php
// config/app.php (Recommended)
'remote_repository' => [
    'base_uri' => env('REMOTE_BASE_URI', 'https://api.example.com'),
],
```

**Legacy fallback keys** (deprecated, will log warnings):

```php
// These still work but will trigger deprecation warnings:
// - config/jsonapi.php: 'base_uri'
// - config/pxc.php: 'base_api_uri'
// - config/remote.php: 'base_uri'
```

### Request Configuration

```php
// config/pxc.php
'max_url_length' => env('PXC_MAX_URL_LENGTH', 2048),
'api_log_requests' => env('PXC_API_LOG_REQUESTS', false),
'user_retrieve_limit' => env('PXC_USER_RETRIEVE_LIMIT', 100),
```

### Environment Variables

```bash
# .env
JSONAPI_BASE_URI=https://api.example.com
PXC_MAX_URL_LENGTH=2048
PXC_API_LOG_REQUESTS=false
PXC_USER_RETRIEVE_LIMIT=100
```

## Header Forwarding

The RemoteRepository supports configurable header forwarding for inter-service communication. This allows headers to propagate through service chains, maintaining context across microservice boundaries.

### Header Types

**Passthrough Headers**: Simply forwarded from the incoming request if present. Use for headers that should propagate through the service chain without modification.

**Contextual Headers**: Forwarded from the incoming request if present, OR resolved via a resolver class if not. Use when headers need to be derived from application context (e.g., current user, tenant, session data).

### Configuration

```php
// config/app.php
'remote_repository' => [
    'base_uri' => env('REMOTE_BASE_URI', 'https://api.example.com'),

    // Headers to forward from incoming request (simple passthrough)
    'passthrough_headers' => [
        'X-Feature-Flag',
        'X-Debug-Mode',
    ],

    // Headers to forward from request OR resolve via class if not present
    'contextual_headers' => [
        'X-User-Context' => \App\Support\HeaderResolvers\UserContextResolver::class,
        'X-Tenant-ID' => \App\Support\HeaderResolvers\TenantResolver::class,
    ],
],
```

### Creating a Header Resolver

Implement the `HeaderResolverInterface` to create a contextual header resolver:

```php
<?php

namespace App\Support\HeaderResolvers;

use NuiMarkets\LaravelSharedUtils\Contracts\HeaderResolverInterface;

class UserContextResolver implements HeaderResolverInterface
{
    public function resolve(): ?string
    {
        // Return the header value, or null if it cannot be resolved
        $user = auth()->user();

        return $user?->id;
    }
}
```

### Resolver Behavior

The resolver is only called when:

1. The header is configured in `contextual_headers`
2. The header is **not** present in the incoming request
3. The resolver class exists
4. The resolver implements `HeaderResolverInterface`

If the resolver returns `null`, the header is not added to outgoing requests.

### Request Priority

For contextual headers, **incoming request headers take priority** over resolver values. This allows upstream services to override values when needed:

```
Client Request                    Service A                         Service B
     │                               │                                  │
     │  X-User-Context: user-123     │                                  │
     ├──────────────────────────────>│                                  │
     │                               │  X-User-Context: user-123        │
     │                               │  (from request, not resolver)    │
     │                               ├─────────────────────────────────>│
     │                               │                                  │
```

If you need the resolver to always be used (ignoring incoming headers), configure the header as passthrough-only and handle resolution separately.

### Use Cases

**Passthrough headers** are ideal for:
- Feature flags that should apply across all services
- Debug/trace modes
- Client version information
- Any header that should flow unchanged through the service chain

**Contextual headers** are ideal for:
- User identity propagation (derive from JWT if not explicitly set)
- Tenant/organization context
- Request-scoped configuration that can be derived from application state

### Example: Multi-Tenant Setup

```php
// config/app.php
'remote_repository' => [
    'contextual_headers' => [
        'X-Tenant-ID' => \App\Support\TenantResolver::class,
    ],
],
```

```php
// app/Support/TenantResolver.php
<?php

namespace App\Support;

use NuiMarkets\LaravelSharedUtils\Contracts\HeaderResolverInterface;

class TenantResolver implements HeaderResolverInterface
{
    public function resolve(): ?string
    {
        // Derive tenant from authenticated user or session
        $user = auth()->user();

        return $user?->tenant_id;
    }
}
```

Now all inter-service calls via RemoteRepository will include `X-Tenant-ID`, either from the incoming request or derived from the authenticated user.

## Error Handling

### ValidationException Handling

The RemoteRepository automatically handles malformed API responses:

```php
// Automatic handling of non-JSON API responses
try {
    $response = $repository->get('/api/endpoint');
} catch (RemoteServiceException $e) {
    // Original ValidationException is preserved as previous exception
    $originalError = $e->getPrevious();
    Log::error('API communication failed', [
        'message' => $e->getMessage(),
        'original' => $originalError ? $originalError->getMessage() : null
    ]);
}
```

### Specialized Error Patterns

Built-in handling for known error scenarios:

```php
// Automatically detects and handles specific error patterns
$response = $repository->get('/api/delivery-addresses');

// If response contains "Duplicate active delivery address codes found"
// Returns: (object) ['error' => 'Duplicate active delivery...']
// Instead of throwing an exception
```

## HTTP Status Code Preservation

The RemoteRepository now preserves original HTTP status codes from remote services instead of wrapping all errors as 502 (Bad Gateway).

### Before vs After

| Remote Response | Before | After |
|-----------------|--------|-------|
| 404 Not Found | 502 | 404 |
| 500 Server Error | 502 | 500 |
| 503 Service Unavailable | 502 | 503 |
| 429 Rate Limited | 502 | 429 |
| 401 Unauthorized | 502 | 401 |
| 403 Forbidden | 502 | 403 |

### Benefits

- **Smarter retry logic** - Don't retry 404s, do retry 503s
- **Better failure classification** - Distinguish between "doesn't exist" and "server down"
- **Accurate monitoring** - Dashboards show real error distribution
- **Intelligent caching** - Cache 404s longer than transient errors

### Handling Status Codes

```php
use NuiMarkets\LaravelSharedUtils\Exceptions\RemoteServiceException;

try {
    $product = $repository->findById('product-123');
} catch (RemoteServiceException $e) {
    match ($e->getStatusCode()) {
        404 => return null,           // Product doesn't exist
        429 => sleep(60),             // Rate limited, wait
        503 => $this->retry(),        // Server down, retry
        401, 403 => throw $e,         // Auth error, don't retry
        default => throw $e,
    };
}
```

### Breaking Change Note

> **Important:** Code checking `$e->getCode() === 502` should be updated to use `$e->getStatusCode()` and handle specific status codes appropriately.

## Failure Caching

The `CachesFailedLookups` trait prevents cascading timeouts by caching failed lookups for a configurable TTL. When a lookup fails, subsequent requests receive a cached failure response instead of retrying the expensive call.

### When to Use

- **High-traffic endpoints** that call external services
- **Relationship lookups** between entities
- **Any lookup** where repeated failures would cause cascading timeouts

### Basic Usage

```php
use NuiMarkets\LaravelSharedUtils\RemoteRepositories\Concerns\CachesFailedLookups;
use NuiMarkets\LaravelSharedUtils\Exceptions\CachedLookupFailureException;

class ProductRepository extends RemoteRepository
{
    use CachesFailedLookups;

    public function getById(string $id)
    {
        // Check cache first - throws CachedLookupFailureException if recently failed
        $this->throwIfCachedLookupFailed('product', $id);

        try {
            $res = $this->get("api/products/{$id}");
            return $this->handleResponse($res);
        } catch (\Exception $e) {
            // Cache failure for subsequent requests
            $this->cacheLookupFailure('product', $e, $id);
            throw $e;
        }
    }
}
```

### Handling Cached Failures

```php
try {
    $product = $repo->getById($productId);
} catch (CachedLookupFailureException $e) {
    // Check failure type with convenience methods
    if ($e->isNotFound()) {
        return null;  // Resource genuinely doesn't exist
    }
    if ($e->isTransient()) {
        // Timeout, server error, rate limited - might recover
        Log::info('Transient failure', [
            'category' => $e->getFailureCategory(),
            'http_status' => $e->getHttpStatus(),
        ]);
    }
    return $defaultValue;
} catch (RemoteServiceException $e) {
    // Real failure (first occurrence)
    throw $e;
}
```

### Failure Categories

| Category | HTTP Status | Default TTL | Description |
|----------|-------------|-------------|-------------|
| `not_found` | 404 | 10 min | Resource doesn't exist |
| `auth_error` | 401, 403 | 5 min | Auth/permissions issue |
| `rate_limited` | 429 | 1 min | Honor rate limiting |
| `server_error` | 5xx | 2 min | Server problems |
| `timeout` | - | 30 sec | Request timed out |
| `connection_error` | - | 30 sec | Network failure |
| `client_error` | 4xx | 5 min | Bad request data |
| `unknown` | - | 2 min | Unclassified failure |

### Configuration

```php
// config/app.php
'remote_repository' => [
    // Default TTL for all failure types (seconds)
    'failure_cache_ttl' => env('REMOTE_FAILURE_CACHE_TTL', 120),

    // Per-category TTL overrides (optional)
    'failure_cache_ttl_by_category' => [
        'not_found' => 600,       // 10 min
        'auth_error' => 300,      // 5 min
        'rate_limited' => 60,     // 1 min
        'server_error' => 120,    // 2 min
        'timeout' => 30,          // 30 sec
        'connection_error' => 30, // 30 sec
    ],
],
```

### Cache Invalidation

Clear cached failures after creating resources:

```php
public function create(array $data)
{
    $res = $this->post("api/products", $this->makeRequestBody($data));
    $product = $this->handleResponse($res);

    // Clear any cached 404 failure for this ID
    $this->clearCachedLookupFailure('product', $product->id);

    return $product;
}
```

### Debugging

```php
// Get cached failure data for debugging
$cachedData = $this->getCachedFailureData('product', $id);
if ($cachedData) {
    Log::debug('Found cached failure', [
        'cached_at' => $cachedData['cached_at'],
        'http_status' => $cachedData['http_status'],
        'failure_category' => $cachedData['failure_category'],
        'exception_class' => $cachedData['exception_class'],
    ]);
}
```

[See full failure caching guide →](failure-caching.md)

## Performance Monitoring

### ProfilingTrait Integration

The RemoteRepository includes built-in performance monitoring:

```php
// Automatic timing for all requests
$repository = new ProductRepository($client, $tokenService);

// Initialize profiling (usually done in middleware)
$repository::initProfiling();

// Make requests - automatically timed
$products = $repository->findByIds([1, 2, 3]);
$user = $repository->getUserUrl('/users/me');

// Log performance breakdown
$repository::logTimings();
```

### Performance Logs

Example log output:

```json
{
    "level": "debug",
    "message": "Remote repository timing",
    "context": {
        "class": "App\\RemoteRepositories\\ProductRepository",
        "total_seconds": 0.245,
        "request_percentage": "12%",
        "calls": 3,
        "calls_breakdown": [
            {"method": "get", "seconds": 0.120},
            {"method": "post", "seconds": 0.089},
            {"method": "getUserUrl", "seconds": 0.036}
        ]
    }
}
```

## Caching

### Automatic Caching

The RemoteRepository provides intelligent caching:

```php
// Single item caching
$response = $this->get('/api/products/123');
$this->cacheOne($response); // Caches single product

// Collection caching
$response = $this->get('/api/products?ids=1,2,3');
$this->cache($response); // Caches all products in response
```

### Cache Operations

```php
// Check if item exists in cache
if ($repository->hasId('product-123')) {
    // Retrieve from cache without API call
    $product = $repository->findByIdWithoutRetrieve('product-123');
}

// Retrieve item - uses cache if available, otherwise fetches from API
$product = $repository->findById('product-123');

// Access cache directly
$allCached = $repository->query(); // Returns Collection
```

**Note:** The in-memory cache is per-request. Items fetched during a request are cached for subsequent lookups within the same request. This is not persistent caching - each new request starts with an empty cache.

## Testing

### Basic Test Setup

```php
use NuiMarkets\LaravelSharedUtils\RemoteRepositories\RemoteRepository;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;

class ProductRepositoryTest extends TestCase
{
    public function test_repository_extends_base_class()
    {
        $repository = $this->createRepository();
        
        $this->assertInstanceOf(RemoteRepository::class, $repository);
    }
    
    private function createRepository()
    {
        $mockClient = $this->createMock(DocumentClientInterface::class);
        $mockTokenService = $this->createMockTokenService();
        
        return new ProductRepository($mockClient, $mockTokenService);
    }
}
```

## Migration Guide

### From Individual Service Implementations

If migrating from individual RemoteRepository implementations in your services:

#### Step 1: Update Dependencies

```bash
composer require nuimarkets/laravel-shared-utils
```

#### Step 2: Update Namespace

```php
// Old
use App\RemoteRepositories\RemoteRepository;

// New
use NuiMarkets\LaravelSharedUtils\RemoteRepositories\RemoteRepository;
```

#### Step 3: Update Inheritance

```php
// Old
class ProductRepository extends App\RemoteRepositories\RemoteRepository

// New  
class ProductRepository extends NuiMarkets\LaravelSharedUtils\RemoteRepositories\RemoteRepository
```

#### Step 4: Remove Local Implementation

Delete the local `app/RemoteRepositories/RemoteRepository.php` file.

#### Step 5: Update Imports

```php
// Update SimpleDocument import
use NuiMarkets\LaravelSharedUtils\Support\SimpleDocument;

// Update exception import
use NuiMarkets\LaravelSharedUtils\Exceptions\RemoteServiceException;
```

## API Reference

### Core Methods

#### `get(string $url): DocumentInterface`

Performs GET request with automatic retry and error handling.

#### `getUserUrl(string $url): DocumentInterface`

Specialized GET method for user-related endpoints.

#### `post(string $url, ItemDocumentInterface $data): DocumentInterface`

Performs POST request with request body.

### Caching Methods

#### `cache(DocumentInterface $response): void`

Caches all items from a collection response.

#### `cacheOne(DocumentInterface $response): void`

Caches a single item from response.

#### `hasId(string $id): bool`

Checks if item exists in cache.

#### `query(): Collection`

Returns the internal cache collection.

### Utility Methods

#### `makeRequestBody(array $data): SimpleDocument`

Creates JSON API request body from array data.

#### `allowedGetRequest(string $url): bool`

Validates if GET request URL length is within limits.

#### `handleResponse(DocumentInterface $response): mixed`

Processes API response and extracts data.

### Abstract Methods

#### `abstract protected filter(array $data): mixed`

Must be implemented by child classes to handle data filtering and retrieval logic.

---

## Best Practices

### 1. Lazy Token Loading

The RemoteRepository uses lazy token loading for optimal performance:

```php
// ✓ Token is NOT retrieved during instantiation
$repository = new ProductRepository($client, $tokenService);

// ✓ Token is loaded on first actual API request
$products = $repository->findByIds([1, 2, 3]);

// ✓ Subsequent requests reuse the same token
$product = $repository->findById('product-456');
```

**Benefits:**

- **Faster instantiation** - No token service calls during object creation
- **Better resilience** - Repository can be created even if token service is temporarily unavailable
- **Reduced overhead** - Token only loaded when actually needed
- **Automatic caching** - Token retrieved once and reused for all requests

**Note:** Non-request operations (like `query()`, `hasId()`) do not trigger token loading.

### 2. Error Handling

Always catch `RemoteServiceException` and handle based on HTTP status:

```php
try {
    $data = $repository->findById($id);
} catch (RemoteServiceException $e) {
    if ($e->getStatusCode() === 404) {
        return null; // Not found is often acceptable
    }
    throw $e; // Re-throw other errors
}
```

### 3. URL Length Management

Use `allowedGetRequest()` to check URL length and fall back to POST for large payloads:

```php
$url = 'v1/products?ids=' . implode(',', $productIds);

if ($this->allowedGetRequest($url)) {
    $response = $this->get($url);
} else {
    // Too many IDs - use POST instead
    $body = $this->makeRequestBody(['ids' => $productIds]);
    $response = $this->post('v1/products/filter', $body);
}
```

### 4. Leverage In-Memory Caching

The repository caches fetched items for the duration of the request. Fetch items once and reuse:

```php
// First call fetches from API and caches
$products = $repository->findByIds($allProductIds);

// Later lookups use cache - no API call
foreach ($orderItems as $item) {
    $product = $repository->findByIdWithoutRetrieve($item->product_id);
}
```

### 5. Enable Request Logging for Debugging

Set `REMOTE_REPOSITORY_LOG_REQUESTS=true` in development to see all API calls:

```bash
# .env
REMOTE_REPOSITORY_LOG_REQUESTS=true
```

## Troubleshooting

### Common Issues

#### "Client not initialized" error in tests

```php
// Solution: Mock the repository or bypass client initialization
$repository = Mockery::mock(ProductRepository::class)->makePartial();
```

#### ValidationException on malformed responses

```php
// Automatic handling - check logs for details
Log::error('Remote API returned non-JSON API compliant response');
```

#### URL too long for GET requests

```php
// Use allowedGetRequest() and fallback to POST
if (!$this->allowedGetRequest($url)) {
    $response = $this->post($endpoint, $this->makeRequestBody($data));
}
```

### Debug Mode

Enable request logging for debugging:

```bash
PXC_API_LOG_REQUESTS=true
```

---

## License

This documentation is part of the `nuimarkets/laravel-shared-utils` package and is licensed under the MIT License.
