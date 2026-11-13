# Machine Token

## Overview

`MachineTokenService` is the shared implementation of `MachineTokenServiceInterface` for client-credentials service-to-service authentication. It retrieves a token from a configured auth endpoint, stores the token payload in Laravel cache, refreshes before expiry, and falls back to the current valid token if refresh temporarily fails.

## Installation

Register the provider in the consuming Laravel app:

```php
// config/app.php
'providers' => [
    NuiMarkets\LaravelSharedUtils\Providers\MachineTokenServiceProvider::class,
],
```

Publish the config when the app needs a local config file:

```bash
php artisan vendor:publish --tag=machine-token-config
```

The provider binds `NuiMarkets\LaravelSharedUtils\Contracts\MachineTokenServiceInterface` to `NuiMarkets\LaravelSharedUtils\Services\MachineTokenService` as a singleton.

## Configuration

Configuration is read from `config/machine_token.php` under the `machine_token.*` namespace.

| Key | Environment variable | Default | Notes |
| --- | --- | --- | --- |
| `machine_token.redis_key` | `MACHINE_TOKEN_REDIS_KEY` | `machine_token` | Logical cache key. Keep the suffix stable for existing revocation tooling. |
| `machine_token.time_before_expire` | `MACHINE_TOKEN_TIME_BEFORE_EXPIRE` | `604800` | Refresh window in seconds before token expiry. |
| `machine_token.client_id` | `MACHINE_TOKEN_CLIENT_ID` | `null` | OAuth client ID. |
| `machine_token.secret` | `MACHINE_TOKEN_SECRET` | `null` | OAuth client secret. |
| `machine_token.url` | `MACHINE_TOKEN_URL` | `null` | Token endpoint URL. |

## Behavior

```text
getToken(?float $timeout = null)
  -> return in-memory token if already loaded this request
  -> read cached payload from machine_token.redis_key (a read failure is a miss)
  -> return cached token when payload is valid and outside the refresh window
  -> refresh from auth endpoint when missing, malformed, or near expiry,
     bounded by $timeout seconds when given
  -> cache new token payload (a write failure keeps it in memory) and return token
```

If refresh fails while an old token is still valid, the service returns the old token and logs an error so operators can fix the auth endpoint before the token expires. If no valid old token exists, it throws `RuntimeException`.

A cache outage does not block a token: a failed read fetches a token from the auth endpoint, and a failed write keeps the fresh token in memory for the rest of the request (or the worker's life under a long-lived runtime). The cache read itself is bounded only by the default cache connection's own timeouts.

The fallback has a cost. Under a runtime that resets state per request (PHP-FPM, FrankenPHP without workers), the in-memory token lasts one request, so during a cache outage every request that needs a token fetches its own from the auth endpoint. That endpoint then takes the service's request rate, and an issuer that stores each token it grants stores one per request, until the cache recovers.

### Time budget

`getToken($timeout)` gives the auth request what is left of `$timeout` seconds after the cache read, as both its total and connection timeouts, and the request follows no redirects (each redirect hop would get the full timeout again). A remainder under 10 ms is treated as spent: no request is made, and the service falls back to the old token or throws. A cached or in-memory token is returned whatever the budget. Without the argument the HTTP client's defaults apply, as for every existing caller. `TokenStatusClient` passes its remaining budget (see [Token Revocation Check](token-revocation.md)).

### Invalidating a refused token

`invalidate()` drops the in-memory token, the old-token fallback and the cached copy, so the next `getToken()` fetches a new token from the auth endpoint. Call it when a request made with the token is refused (401 or 403) because the token was revoked or rotated. If the cache cannot be cleared, the next fetch skips the cache read and overwrites the refused copy. It never throws.

## Logging

| Action | Level | Condition |
| --- | --- | --- |
| `config_missing` | `warning` | `url`, `client_id`, or `secret` is missing. |
| `connection_failed` | `warning` | The HTTP request to the token endpoint throws. |
| `token_refresh_failed` | `error` | Refresh fails while an old token is still valid. |
| `cache_read_failed` | `warning` | Reading the cached token throws; the token is fetched instead. |
| `cache_write_failed` | `warning` | Writing a fresh token to the cache throws; it is kept in memory. |
| `cache_forget_failed` | `warning` | `invalidate()` cannot clear the cached copy. |
| `budget_spent` | `warning` | A fetch is needed but under 10 ms of the caller's budget is left. |

`cache_read_failed`, `cache_write_failed`, `cache_forget_failed` and `budget_spent` describe failures the service has already worked around, so a logger that throws while writing one of them is ignored rather than allowed to undo the fallback.

Warnings use `ErrorLogger::logWarning()` and should not page Slack in normal consumer routing. The refresh failure path uses `ErrorLogger::logError()` because it needs operator attention before the old token expires.

## Testing

Use `MocksMachineTokenService` when a test only needs a token to exist:

```php
use NuiMarkets\LaravelSharedUtils\Testing\MocksMachineTokenService;

class ProductRepositoryTest extends TestCase
{
    use MocksMachineTokenService;

    public function test_repository_call()
    {
        $this->mockMachineTokenService('test-token');

        // Exercise code that resolves MachineTokenServiceInterface.
    }
}
```

The trait binds a Mockery double for `MachineTokenServiceInterface` and `MachineTokenService`, so tests do not make real HTTP calls to the token endpoint. The double also accepts `invalidate()`.

## Migration Notes

For existing consumers with a local `App\Services\MachineTokenService`:

1. Require a shared-utils version that includes this component.
2. Register `MachineTokenServiceProvider` in `config/app.php`.
3. Delete the local service and provider.
4. Move `pxc.machine_token.*` values to `machine_token.*`.
5. Remove the old `pxc.machine_token` config block once all references are gone.
