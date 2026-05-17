# Application Cache Clear

## Overview

`ApplicationCacheController` exposes operator-callable endpoints for the
Laravel **application cache store** (the store backing `Cache::remember()`,
`Cache::get()`, `Cache::put()` calls).

It complements `ClearLadaAndResponseCacheController`, which only targets the
lada-cache and Spatie ResponseCache layers. Without these endpoints, a
long-lived application-cache key had no recovery path other than waiting
for natural TTL.

Two surfaces:

| Surface | When to use |
|---------|-------------|
| `?action=forget&key=X` | Surgical removal of a single known key. Use first. |
| `?action=clear-cache&include=app` | Bulk flush of the whole cache store. Use only when surgical isn't possible. |

The bulk flush is opt-in via the `include=app` flag because operators
routinely call `?action=clear-cache` during deploys, silently extending it
to nuke the application cache would surprise the next on-caller.

## Wiring

The shared package does not register routes. Wire from the consuming
service's `HomeController::index` (or equivalent dispatcher) action:

```php
use NuiMarkets\LaravelSharedUtils\Http\Controllers\ApplicationCacheController;
use NuiMarkets\LaravelSharedUtils\Http\Controllers\ClearLadaAndResponseCacheController;

public function index(Request $request)
{
    return match ($request->query('action')) {
        'forget'      => app(ApplicationCacheController::class)->forget($request),
        'clear-cache' => app(ClearLadaAndResponseCacheController::class)->clearCache($request),
        // ... other cases
    };
}
```

The `?include=app` flag is read from the query string by
`ClearLadaAndResponseCacheController` itself, no additional case is needed.

## Authorization

Both endpoints share `AuthorizesCacheOperations::isAuthorizedForDetailedInfo()`,
which is **token-only**: the request must carry `?token=` matching
`config('app.clear_cache_token')`. Comparison is constant-time via
`hash_equals()`.

Wire-up per consumer:

1. Add to your `config/app.php`:

   ```php
   'clear_cache_token' => env('CLEAR_CACHE_TOKEN'),
   ```

2. Set `CLEAR_CACHE_TOKEN` in every environment the endpoint is reachable from
   (production, staging, dev) and in your local `.env`.

The config indirection means the value survives `php artisan config:cache`.

If `config('app.clear_cache_token')` is unset or empty, every request returns
401 (fail-closed). Unauthorized requests get
`401 { "status": "restricted", "message": "Not available" }`.

## Surgical: `?action=forget&key=X`

```bash
curl 'https://service.example.com/?action=forget&key=lookup:countries:all&token=...'
```

Response (200):

```json
{
  "message": "Application cache key forgotten",
  "detail": {
    "key": "lookup:countries:all",
    "cache_store": "redis",
    "driver": "Illuminate\\Cache\\RedisStore",
    "existed_before": true,
    "forgotten": true,
    "duration_ms": 1.42
  }
}
```

- `existed_before: true` confirms the key was actually present before deletion.
- `cache_store` and `driver` let the operator confirm they didn't just
  forget a key in a misconfigured `array` driver.
- Missing `key` query param returns `422`:
  ```json
  { "status": "invalid", "message": "Missing required query parameter: key" }
  ```

## Bulk: `?action=clear-cache&include=app`

```bash
curl 'https://service.example.com/?action=clear-cache&include=app&token=...'
```

Response is the existing `clearCache` payload with an extra `app_cache` block:

```json
{
  "message": "Lada cache and response cache cleared",
  "detail": {
    "duration_ms": 12.3,
    "summary": { "...": "..." },
    "app_cache": {
      "flushed": true,
      "cache_store": "redis",
      "driver": "Illuminate\\Cache\\RedisStore",
      "duration_ms": 4.1
    }
  }
}
```

Without `include=app`, `app_cache` is omitted and the response shape matches
the pre-`0.6.0` behaviour. Existing callers that don't opt in see no change.

## Auditing

Both `forget` and `flushAppCache` emit a `Log::info` with the full detail
block. Search logs for `"Application cache key forgotten"` or
`"Application cache flushed"` to see who called what and when.

## See also

- [Idempotency Middleware](idempotency.md) for safe POST/PUT/PATCH replay.
- `ClearLadaAndResponseCacheController` for lada-cache + Spatie
  ResponseCache flushing.
