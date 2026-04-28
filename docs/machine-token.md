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
getToken()
  -> return in-memory token if already loaded this request
  -> read cached payload from machine_token.redis_key
  -> return cached token when payload is valid and outside the refresh window
  -> refresh from auth endpoint when missing, malformed, or near expiry
  -> cache new token payload and return token
```

If refresh fails while an old token is still valid, the service returns the old token and logs an error so operators can fix the auth endpoint before the token expires. If no valid old token exists, it throws `RuntimeException`.

## Logging

| Action | Level | Condition |
| --- | --- | --- |
| `config_missing` | `warning` | `url`, `client_id`, or `secret` is missing. |
| `connection_failed` | `warning` | The HTTP request to the token endpoint throws. |
| `token_refresh_failed` | `error` | Refresh fails while an old token is still valid. |

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

The trait binds a Mockery double for `MachineTokenServiceInterface`, so tests do not make real HTTP calls to the token endpoint.

## Migration Notes

For existing consumers with a local `App\Services\MachineTokenService`:

1. Require a shared-utils version that includes this component.
2. Register `MachineTokenServiceProvider` in `config/app.php`.
3. Delete the local service and provider.
4. Move `pxc.machine_token.*` values to `machine_token.*`.
5. Remove the old `pxc.machine_token` config block once all references are gone.
