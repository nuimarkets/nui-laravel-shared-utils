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

Both endpoints share `AuthorizesCacheOperations`, which authorizes a request
by either of two paths. Neither depends on the other: the user path works with
no token configured, and the token works for a request with no signed-in user.

| Path | Passes when | For |
|------|-------------|-----|
| User | `request()->user()` resolves and `Gate::forUser($user)->allows(CacheAbility::MANAGE)` | An admin frontend calling as its signed-in user |
| Token | `?token=` matches `config('app.clear_cache_token')`, compared with `hash_equals()` | Operators and scripts |

The token is checked first, so a request with a valid token never touches the
auth guard, and a request that carries both is recorded as `token`. Anything
else gets `401 { "status": "restricted", "message": "Not available" }`,
whichever path it tried.

### User path

The caller sends its user's bearer token instead of a shared secret:

```bash
curl -H 'Authorization: Bearer <user token>' \
  'https://service.example.com/?action=forget&key=lookup:countries:all'
```

Wire-up per consumer:

1. Define the gate where the service registers gates (its
   `AuthServiceProvider`, else `AppServiceProvider::boot()`), returning true
   only for the users who may clear caches:

   ```php
   use Illuminate\Support\Facades\Gate;
   use NuiMarkets\LaravelSharedUtils\Auth\CacheAbility;

   Gate::define(CacheAbility::MANAGE, fn ($user) => $user->isSuperAdmin());
   ```

   Laravel passes the resolved user straight to the callback, so a parameter
   typed as a class the guard does not return throws a `TypeError` (a 500).
   Type it as the class your guard actually returns, or leave it untyped.

2. Authenticate bearer requests on the route, so `request()->user()` resolves
   by the time the controller runs. The package does not authenticate: on an
   unauthenticated route (a typical `/` home route) `request()->user()` is
   null and only the token can pass. Run the service's existing API
   authentication for the cache actions when the request carries a bearer
   token, and pass every other request through untouched so health checks and
   token-only calls behave as before. A bearer that fails authentication
   should get the auth layer's own 401 as JSON, not a redirect.

3. If a gateway or proxy sits in front of the service, make sure it forwards
   the `Authorization` header on that route.

Without a `CacheAbility::MANAGE` definition the ability is undefined, Laravel
denies it, and only the token can pass. The exception is a `Gate::before()` or
`Gate::after()` callback that returns `true` for a user: Laravel consults it
for every ability, defined or not, so that user passes the user path with no
definition at all.

### Token path

1. Add to your `config/app.php`:

   ```php
   'clear_cache_token' => env('CLEAR_CACHE_TOKEN'),
   ```

2. Set `CLEAR_CACHE_TOKEN` in each environment where operators or scripts
   call the endpoint with a token, including your local `.env` if you do.

The config indirection means the value survives `php artisan config:cache`.
If `config('app.clear_cache_token')` is unset, empty or not a string, the token
path fails closed and refuses every token; the user path still works.

The token travels in the query string, where access logs and browser history
can record it. Keep it for server-side callers; a frontend uses the user path.

### Customising the rule

The controllers call `cacheOperationAuthorization()`, which returns the audit
fields for the path that passed, or `null`. A subclass that changes the rule
overrides that method. `isAuthorizedForDetailedInfo()` remains as a boolean
wrapper around it, and overriding the wrapper alone does not change what the
controllers allow.

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

Every success writes a `Log::info`: `clearCache` under its result message
(for example `"Lada cache and response cache cleared"`), `forget` under
`"Application cache key forgotten"` or `"Application cache key was already
absent"`, and the `include=app` flush under `"Application cache flushed"`.
Each carries the detail block (`forget` logs the key's SHA-256 as `key_hash`
in place of the raw key) plus:

| Field | Value |
|-------|-------|
| `authorized_by` | `user` or `token` |
| `user_id` | User path only: the auth identifier when the user implements `Authenticatable`, else its public `id` property. Omitted when it has neither. |

The token is never logged. The audit fields go to the log only; the response
bodies are unchanged. `user_id` is on `SensitiveDataProcessor`'s default PII
list, so a consumer that logs with PII redaction on records it as
`[REDACTED]`; `authorized_by` matches neither default list.

## Upgrading to 0.9.0

Require `^0.9.0`. The user path is additive, so most consumers need no change:

- **No gate, no change.** A consumer that defines no `CacheAbility::MANAGE`
  gate keeps the token-only behaviour of earlier releases, unless it has a
  `Gate::before()` or `Gate::after()` callback that returns `true` for some
  users (see [User path](#user-path)). Check those callbacks before upgrading:
  on a route where `request()->user()` resolves, those users can now clear
  caches.
- **Overriding the rule.** A subclass that overrides
  `isAuthorizedForDetailedInfo()` moves the override to
  `cacheOperationAuthorization()`; the controllers no longer call the former.
- **Logs.** The success logs gain `authorized_by` and, on the user path,
  `user_id` (see [Auditing](#auditing)).
- **`flushAppCache()`** takes an optional `$authorization` array, merged into
  its log only. Existing calls are unchanged.

## See also

- [Idempotency Middleware](idempotency.md) for safe POST/PUT/PATCH replay.
- `ClearLadaAndResponseCacheController` for lada-cache + Spatie
  ResponseCache flushing.
