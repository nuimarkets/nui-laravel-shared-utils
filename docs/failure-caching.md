# Failure Caching Guide

## Overview

The failure caching system prevents cascading timeouts during service outages by caching failed remote service lookups. When a lookup fails (timeout, 500 error, etc.), subsequent requests for the same lookup receive a cached failure response instead of retrying the expensive call.

## Problem Statement

Without failure caching, a single failing external service can cause:

1. **Cascading timeouts** - Every request waits for the timeout, slowing everything down
2. **Resource exhaustion** - Connection pools fill up with waiting requests
3. **Poor user experience** - Users wait for timeouts instead of getting fast error responses
4. **Wasted resources** - Repeatedly calling a service that's known to be down

## Architecture

### Components

```text
┌─────────────────────────────────────────────────────────────────┐
│                      Your Repository                             │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │              CachesFailedLookups Trait                   │   │
│  │  ┌─────────────┐  ┌──────────────┐  ┌───────────────┐  │   │
│  │  │ throwIf...  │  │ cacheLookup  │  │ clearCached   │  │   │
│  │  │ CachedFailed│  │ Failure      │  │ LookupFailure │  │   │
│  │  └─────────────┘  └──────────────┘  └───────────────┘  │   │
│  └─────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                    FailureCategory                               │
│  Constants: NOT_FOUND, AUTH_ERROR, RATE_LIMITED, SERVER_ERROR,  │
│             TIMEOUT, CONNECTION_ERROR, CLIENT_ERROR, UNKNOWN     │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│              CachedLookupFailureException                        │
│  - HTTP status from original failure                             │
│  - Failure category                                              │
│  - Convenience methods: isNotFound(), isTransient(), etc.        │
└─────────────────────────────────────────────────────────────────┘
```

### Files

| File | Purpose |
|------|---------|
| `src/RemoteRepositories/Concerns/CachesFailedLookups.php` | Trait with caching logic |
| `src/RemoteRepositories/FailureCategory.php` | Category constants |
| `src/Exceptions/CachedLookupFailureException.php` | Exception for cached failures |

## Quick Start

### 1. Add the Trait

```php
use NuiMarkets\LaravelSharedUtils\RemoteRepositories\Concerns\CachesFailedLookups;

class OrganisationRepository extends RemoteRepository
{
    use CachesFailedLookups;
}
```

### 2. Wrap Your Lookups

```php
public function getById(string $id)
{
    // Step 1: Check for cached failure
    $this->throwIfCachedLookupFailed('product', $id);

    try {
        // Step 2: Make the actual request
        $res = $this->get("api/products/{$id}");
        return $this->handleResponse($res);
    } catch (\Exception $e) {
        // Step 3: Cache the failure
        $this->cacheLookupFailure('product', $e, $id);
        throw $e;
    }
}
```

### 3. Handle Cached Failures

```php
use NuiMarkets\LaravelSharedUtils\Exceptions\CachedLookupFailureException;

try {
    $product = $repo->getById($productId);
} catch (CachedLookupFailureException $e) {
    // Service was recently unavailable
    return $defaultValue;
}
```

## Configuration

### Default Configuration

Without any configuration, failure caching uses these defaults:

- **Default TTL**: 120 seconds (2 minutes) for all failure types
- **Cache driver**: Your application's default cache driver

### Custom Configuration

Add to `config/app.php`:

```php
'remote_repository' => [
    // Default TTL for all failure types
    'failure_cache_ttl' => env('REMOTE_FAILURE_CACHE_TTL', 120),

    // Per-category TTL overrides
    'failure_cache_ttl_by_category' => [
        'not_found' => 600,       // 10 min - resource doesn't exist
        'auth_error' => 300,      // 5 min - won't self-resolve
        'rate_limited' => 60,     // 1 min - honor rate limiting
        'server_error' => 120,    // 2 min - server problems
        'timeout' => 30,          // 30 sec - often transient
        'connection_error' => 30, // 30 sec - network issues
        'client_error' => 300,    // 5 min - bad request data
        // 'unknown' uses default TTL
    ],
],
```

### Environment Variables

```bash
# Default TTL for all failures (seconds)
REMOTE_FAILURE_CACHE_TTL=120
```

## Failure Categories

### Category Reference

| Category | HTTP Status | Typical TTL | Use Case |
|----------|-------------|-------------|----------|
| `not_found` | 404 | Long (10 min) | Resource genuinely doesn't exist |
| `auth_error` | 401, 403 | Long (5 min) | Credentials/permissions won't self-resolve |
| `rate_limited` | 429 | Short (1 min) | Honor rate limiting, retry soon |
| `server_error` | 5xx | Medium (2 min) | Server problems, may recover |
| `timeout` | - | Short (30 sec) | Network timeout, often transient |
| `connection_error` | - | Short (30 sec) | DNS/connection failure |
| `client_error` | 4xx | Long (5 min) | Bad request data won't change |
| `unknown` | - | Default | Unclassified failures |

### Using FailureCategory Constants

```php
use NuiMarkets\LaravelSharedUtils\RemoteRepositories\FailureCategory;

// Check category directly
if ($e->getFailureCategory() === FailureCategory::NOT_FOUND) {
    return null;
}

// Check if transient using static helper method
if (FailureCategory::isTransient($e->getFailureCategory())) {
    // Might recover, schedule retry
}

// Or use the convenience method on the exception (preferred)
if ($e->isTransient()) {
    // Might recover, schedule retry
}
```

## Exception Handling

### CachedLookupFailureException

This exception is thrown when `throwIfCachedLookupFailed()` finds a cached failure.

#### Properties

| Method | Returns | Description |
|--------|---------|-------------|
| `getHttpStatus()` | `?int` | Original HTTP status (null for network errors) |
| `getFailureCategory()` | `string` | Category constant |
| `getCachedAt()` | `string` | ISO 8601 timestamp |
| `getOriginalExceptionClass()` | `string` | Original exception class name |
| `getOriginalExceptionMessage()` | `string` | Original error message |
| `getRepository()` | `string` | Repository class name |
| `getLookupType()` | `string` | The lookup type that failed |
| `getIdentifiers()` | `array` | Identifiers that were being looked up |

#### Convenience Methods

| Method | Returns True When |
|--------|-------------------|
| `isNotFound()` | Category is `not_found` |
| `isServerError()` | Category is `server_error` |
| `isAuthError()` | Category is `auth_error` |
| `isRateLimited()` | Category is `rate_limited` |
| `isTransient()` | Category is timeout, connection, server error, or rate limited |

### Handling Pattern

```php
try {
    $data = $repository->getById($id);
} catch (CachedLookupFailureException $e) {
    // This is a cached failure - don't retry

    if ($e->isNotFound()) {
        // Resource genuinely doesn't exist
        return null;
    }

    if ($e->isAuthError()) {
        // Auth issue - might need attention
        Log::warning('Auth failure for resource lookup', [
            'id' => $id,
            'cached_at' => $e->getCachedAt(),
        ]);
        throw $e;
    }

    if ($e->isTransient()) {
        // Might recover - use fallback for now
        return $this->getFallbackData($id);
    }

    throw $e;
} catch (RemoteServiceException $e) {
    // Real failure (first occurrence) - already cached by cacheLookupFailure()
    throw $e;
}
```

## Advanced Patterns

### Custom Repository Name

Override `getRepositoryShortName()` to customize the cache key prefix:

```php
class ProductRepository extends RemoteRepository
{
    use CachesFailedLookups;

    protected function getRepositoryShortName(): string
    {
        return 'product'; // Results in cache keys like: remote_failure:product:lookup:abc123
    }
}
```

### Custom Default TTL

Override `getFailureCacheTtl()` for repository-specific defaults:

```php
class CriticalServiceRepository extends RemoteRepository
{
    use CachesFailedLookups;

    protected function getFailureCacheTtl(): int
    {
        return 60; // More aggressive caching for critical service
    }
}
```

### Multiple Lookup Types

Use different lookup types for different operations:

```php
class ProductRepository extends RemoteRepository
{
    use CachesFailedLookups;

    public function getById(string $id)
    {
        $this->throwIfCachedLookupFailed('product', $id);
        // ...
    }

    public function getVariants(string $productId)
    {
        $this->throwIfCachedLookupFailed('variants', $productId);
        // ...
    }

    public function getInventory(string $productId)
    {
        $this->throwIfCachedLookupFailed('inventory', $productId);
        // ...
    }
}
```

### Cache Invalidation After Mutations

```php
public function create(array $data)
{
    $res = $this->post("api/products", $this->makeRequestBody($data));
    $product = $this->handleResponse($res);

    // Clear any cached 404 failure for this ID
    $this->clearCachedLookupFailure('product', $product->id);

    return $product;
}

public function delete(string $id)
{
    $res = $this->delete("api/products/{$id}");

    // Don't clear cache - next lookup should see 404
    // The 404 will be cached with appropriate TTL

    return $this->handleResponse($res);
}
```

## Debugging

### Check Cached Failure Data

```php
$cachedData = $this->getCachedFailureData('product', $id);

if ($cachedData) {
    dump([
        'cached_at' => $cachedData['cached_at'],
        'http_status' => $cachedData['http_status'],
        'failure_category' => $cachedData['failure_category'],
        'exception_class' => $cachedData['exception_class'],
        'exception_message' => $cachedData['exception_message'],
    ]);
}
```

### Log Context

All cache operations are logged with structured context:

```json
// Cache hit
{
    "message": "Remote lookup cache hit - returning cached failure",
    "feature": "remote_repository",
    "action": "lookup_failure.cache_hit",
    "cache_hit": true,
    "cache_key": "remote_failure:productrepository:product:abc123",
    "api.service": "productrepository",
    "entity_type": "product",
    "entity_id": "prod-12345",
    "http_status": 404,
    "failure_category": "not_found"
}

// Cache store
{
    "message": "Remote lookup failed - caching failure",
    "feature": "remote_repository",
    "action": "lookup_failure.cached",
    "cache_key": "remote_failure:productrepository:product:abc123",
    "cache_ttl": 600,
    "http_status": 404,
    "failure_category": "not_found"
}
```

### Cache Key Format

Cache keys follow this format:

```text
remote_failure:{repository_short_name}:{lookup_type}:{md5_hash_of_identifiers}
```

Example: `remote_failure:productrepository:product:a1b2c3d4e5f6...`

## Testing

### Test Cache Hit

```php
public function test_returns_cached_failure_on_second_request(): void
{
    $repo = new ProductRepository($client, $tokenService);

    // First request - real failure
    $client->shouldReceive('get')->once()->andThrow(new RequestException(...));

    try {
        $repo->getById('prod-123');
    } catch (\Exception $e) {
        // Expected
    }

    // Second request - cached failure
    $this->expectException(CachedLookupFailureException::class);
    $repo->getById('prod-123');
}
```

### Test Cache Invalidation

```php
public function test_clears_cache_after_create(): void
{
    $repo = new ProductRepository($client, $tokenService);

    // Cache a failure manually for testing
    Cache::put('remote_failure:productrepository:product:' . md5('prod-123'), [
        'cached_at' => now()->toIso8601String(),
        'exception_class' => 'Exception',
        'exception_message' => 'Test failure',
        'http_status' => 404,
        'failure_category' => 'not_found',
        'repository' => ProductRepository::class,
        'lookup_type' => 'product',
        'identifiers' => ['prod-123'],
    ], 600);

    // Create product
    $client->shouldReceive('post')->once()->andReturn($successResponse);
    $repo->create($data);

    // Cache should be cleared
    $this->assertNull($repo->getCachedFailureData('product', 'prod-123'));
}
```

### Test Category-Specific TTL

```php
public function test_uses_category_specific_ttl(): void
{
    config(['app.remote_repository.failure_cache_ttl_by_category' => [
        'not_found' => 600,
    ]]);

    $repo = new TestRepository($client, $tokenService);

    // 404 should use 600s TTL
    Cache::shouldReceive('put')
        ->withArgs(function ($key, $data, $ttl) {
            return $ttl === 600;
        })
        ->once();

    // Trigger 404 failure
    $repo->triggerFailure(404);
}
```

## Troubleshooting

### Cache Not Working

**Symptoms:** Every request hits the remote service, no caching effect.

**Check:**

1. Verify `throwIfCachedLookupFailed()` is called before the request
2. Verify `cacheLookupFailure()` is called in the catch block
3. Check cache driver is working: `Cache::put('test', 'value', 60); Cache::get('test');`
4. Check for exceptions during cache operations in logs

### Wrong TTL Being Used

**Symptoms:** Failures cached for unexpected duration.

**Check:**

1. Verify config path: `config('app.remote_repository.failure_cache_ttl_by_category')`
2. Check category classification in logs: look for `failure_category` field
3. Verify HTTP status is being extracted correctly

### CachedLookupFailureException Not Caught

**Symptoms:** Exception bubbles up instead of being handled.

**Check:**

1. Ensure you're catching `CachedLookupFailureException` specifically
2. Order of catch blocks matters - catch specific exceptions first

```php
// WRONG - CachedLookupFailureException extends RuntimeException
try {
    $data = $repo->getData();
} catch (\RuntimeException $e) {
    // This catches CachedLookupFailureException too!
}

// RIGHT
try {
    $data = $repo->getData();
} catch (CachedLookupFailureException $e) {
    // Handle cached failure
} catch (\RuntimeException $e) {
    // Handle other runtime exceptions
}
```

## Performance Considerations

- **Cache driver**: Use Redis or Memcached for production
- **Key length**: MD5 hash keeps keys short regardless of identifier count
- **Memory**: Cached data includes exception details (~500 bytes per entry)
- **Logging**: Cache operations log at INFO/WARNING level - adjust if too noisy

## Related Documentation

- [RemoteRepository Guide](RemoteRepository.md)
