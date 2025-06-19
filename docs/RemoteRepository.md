# RemoteRepository Documentation

## Overview

The `RemoteRepository` is an abstract base class that provides standardized functionality for communicating with external APIs using the JSON API client. It includes enhanced error handling, performance monitoring, caching capabilities, and retry logic to ensure robust service-to-service communication in microservice architectures.

## Table of Contents

- [Features](#features)
- [Installation](#installation)
- [Basic Usage](#basic-usage)
- [Configuration](#configuration)
- [Error Handling](#error-handling)
- [Performance Monitoring](#performance-monitoring)
- [Caching](#caching)
- [Testing](#testing)
- [Migration Guide](#migration-guide)
- [API Reference](#api-reference)

## Features

### Core Functionality
- **JSON API Client Integration** - Built on `swisnl/json-api-client` for standardized API communication
- **JWT Authentication** - Automatic machine token injection for service-to-service communication
- **Retry Logic** - Configurable retry attempts with exponential backoff
- **URL Length Validation** - Prevents HTTP 414 errors by validating request URL lengths

### Enhanced Error Handling
- **ValidationException Support** - Graceful handling of malformed API responses
- **Specialized Error Patterns** - Custom handling for known error scenarios
- **Comprehensive Logging** - Detailed error reporting with Sentry integration
- **Fallback Mechanisms** - Graceful degradation when external services are unavailable

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

use Nuimarkets\LaravelSharedUtils\RemoteRepositories\RemoteRepository;

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

The RemoteRepository supports multiple configuration sources with automatic fallback:

```php
// config/jsonapi.php (Primary)
'base_uri' => env('JSONAPI_BASE_URI', 'https://api.example.com'),

// config/pxc.php (Fallback)
'base_api_uri' => env('PXC_BASE_API_URI', 'https://pxc-api.example.com'),

// config/remote.php (Final Fallback)
'base_uri' => env('REMOTE_BASE_URI', 'https://remote-api.example.com'),
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

// Force API call even if cached
$product = $repository->findById('product-123');

// Access cache directly
$allCached = $repository->query(); // Returns Collection
```

## Testing

### Basic Test Setup

```php
use Nuimarkets\LaravelSharedUtils\RemoteRepositories\RemoteRepository;
use Nuimarkets\LaravelSharedUtils\Tests\TestCase;

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
use Nuimarkets\LaravelSharedUtils\RemoteRepositories\RemoteRepository;
```

#### Step 3: Update Inheritance

```php
// Old
class ProductRepository extends App\RemoteRepositories\RemoteRepository

// New  
class ProductRepository extends Nuimarkets\LaravelSharedUtils\RemoteRepositories\RemoteRepository
```

#### Step 4: Remove Local Implementation

Delete the local `app/RemoteRepositories/RemoteRepository.php` file.

#### Step 5: Update Imports

```php
// Update SimpleDocument import
use Nuimarkets\LaravelSharedUtils\Support\SimpleDocument;

// Update exception import
use Nuimarkets\LaravelSharedUtils\Exceptions\RemoteServiceException;
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

### 1. Error Handling
Always catch and handle `RemoteServiceException`.

### 2. URL Length Management
Use POST for large requests when URL length exceeds limits.

### 3. Efficient Caching
Leverage cache to minimize API calls.

### 4. Performance Monitoring
Enable profiling in production for monitoring.

### 5. Configuration
Use environment-specific configuration.

## Troubleshooting

### Common Issues

**Issue: "Client not initialized" error in tests**
```php
// Solution: Mock the repository or bypass client initialization
$repository = Mockery::mock(ProductRepository::class)->makePartial();
```

**Issue: ValidationException on malformed responses**
```php
// Automatic handling - check logs for details
Log::error('Remote API returned non-JSON API compliant response');
```

**Issue: URL too long for GET requests**
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