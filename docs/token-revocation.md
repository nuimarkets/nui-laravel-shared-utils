# Token Revocation Check

## Overview

A resource server that validates JWT access tokens on its own (signature and expiry) cannot see a revocation recorded at the token issuer, so a revoked token keeps working until it expires. `TokenRevocationGuard` closes that gap: after the token passes local validation, it asks the issuer whether the token is still active, through `TokenStatusClient`, and caches the answer briefly.

While the issuer answers, a revocation takes effect within `active_ttl` (30 seconds) plus the duration of one call, at every service that runs the check. While it cannot answer, the `fail` setting decides whether tokens are admitted.

## How a check runs

```text
shouldReject(jti, exp, claims)
  mode off      -> false, no cache read, no call
  otherwise     -> TokenStatusClient::check(jti, exp)

check(jti, exp)                                   t0 = when the check starts
  read the entry for jti and the breaker key (one cache round trip)
    entry `revoked`                               -> revoked (hit)
    entry `active`, active_until > t0             -> active (hit)
    breaker key set                               -> unavailable (no call)
  get this service's machine token                inside the budget
  POST {"jti": ...} to the status URL             inside the budget, no redirects
    401           -> drop the machine token from this process and the cache,
                     fetch a new one, retry once
    200 {"active": true}   -> write `active`, active_until = t0 + active_ttl,
                              only if no entry exists           -> active
    200 {"active": false}  -> write `revoked`, overwriting      -> revoked
    anything else          -> set the breaker, write nothing    -> unavailable
```

The budget is `timeout` (2 seconds) from t0, and covers the machine token, the status call and reading its body. The status call's connection phase is capped at `connect_timeout` (1 second) inside it. The machine token's own cache read is bounded only by the default cache connection's timeouts, so keep those short as well.

The response body goes into a sink that refuses bytes past 1 KB, counted after decompression, and the refusal aborts the transfer, so an oversized or inflating answer is never buffered.

| Result | Meaning | Cached |
| --- | --- | --- |
| `active` | 200 with `"active": true` | Until `t0 + active_ttl`, and never past the token's `exp`. Written only when no entry exists, so a slow positive answer cannot replace a newer `revoked`. Not written when the token has already expired. |
| `revoked` | 200 with `"active": false`. The only result that is a revocation. | Overwrites any entry, for `max(revoked_grace, exp + revoked_grace - now)` seconds. |
| `unavailable` | Anything else: a connection failure or timeout, a status other than 200 (redirects included, which are not followed), a body that is not a JSON object with a boolean `active`, a body over 1 KB once decoded, no machine token, a spent budget, the breaker open, an empty `jti` or no URL configured. | Nothing. A failed call sets the breaker key for `breaker_seconds`, so cache misses skip the call until it lapses. Cached answers keep being served meanwhile. |

`TokenStatusClient` and `TokenRevocationGuard` never throw.

## Installation

Register the provider in the consuming app. It also registers `MachineTokenServiceProvider`, whose token the status call carries:

```php
// config/app.php
'providers' => [
    NuiMarkets\LaravelSharedUtils\Providers\TokenRevocationServiceProvider::class,
],
```

Publish the config when the app needs a local copy:

```bash
php artisan vendor:publish --tag=token-revocation-config
```

### A cache store with short timeouts

The check reads the cache on every request, so define a dedicated Redis connection with short connection and read timeouts, and a cache store on it. Copy the app's cache connection (same host, port, database and prefix) so it shares the app's cache namespace:

```php
// config/database.php, under 'redis'
'token_status' => [
    'url' => env('REDIS_URL'),
    'host' => env('REDIS_HOST', '127.0.0.1'),
    'username' => env('REDIS_USERNAME'),
    'password' => env('REDIS_PASSWORD'),
    'port' => env('REDIS_PORT', '6379'),
    'database' => env('REDIS_CACHE_DB', '1'),
    // phpredis: 100 ms for the connection and 100 ms per read.
    'timeout' => 0.1,
    'read_timeout' => 0.1,
    // predis takes the read limit as 'read_write_timeout' instead.
],
```

```php
// config/cache.php, under 'stores'
'token_status' => [
    'driver' => 'redis',
    'connection' => 'token_status',
    'lock_connection' => 'token_status',
],
```

A cache failure (refused, timed out, or the store not defined) skips the cache and the breaker and still makes the call, so the check keeps working without Redis, at the cost of one call per request. When the token status store shares a host with the default cache, as the connection above does, an outage takes both down, and `MachineTokenService` then fetches a token on each request as well (see [Machine Token](machine-token.md)), so the issuer takes two calls per request until the cache recovers. The startup log line reports whether the configured store is defined.

### Calling the guard

Call it after the token's signature and expiry are verified, never before, and refuse through the app's existing invalid-token path:

```php
use NuiMarkets\LaravelSharedUtils\Auth\TokenRevocationGuard;

$claims = (array) JWT::decode($jwt, $keys); // signature and expiry verified

if (app(TokenRevocationGuard::class)->shouldReject((string) ($claims['jti'] ?? ''), (int) $claims['exp'], $claims)) {
    throw new AuthenticationException('Token Exception.'); // the app's own 401
}
```

`TokenStatusClient::status(string $jti, int $exp): TokenStatus` is available for callers that apply their own policy. `TokenStatusClient::check()` returns the same result with the cache hit, the reason for `unavailable` and the latency.

## Configuration

Read from `config/token_revocation.php` under `token_revocation.*`.

| Key | Environment variable | Default | Notes |
| --- | --- | --- | --- |
| `url` | `TOKEN_STATUS_URL` | `null` | The issuer's status endpoint. Unset, every check is `unavailable` with no call, so the `fail` setting decides. |
| `store` | `TOKEN_STATUS_CACHE_STORE` | `token_status` | Cache store for answers, the breaker key and the log rate limits. Empty uses the default store. |
| `active_ttl` | `TOKEN_STATUS_ACTIVE_TTL` | `30` | Seconds an `active` answer is trusted, from the start of its check. The revocation bound: raising it delays revocations by as much. |
| `revoked_grace` | `TOKEN_STATUS_REVOKED_GRACE` | `60` | Seconds past `exp` a `revoked` answer is kept. |
| `connect_timeout` | `TOKEN_STATUS_CONNECT_TIMEOUT` | `1` | Seconds for the status call's connection phase. |
| `timeout` | `TOKEN_STATUS_TIMEOUT` | `2` | The whole check's budget in seconds: machine token, status call and body. |
| `breaker_seconds` | `TOKEN_STATUS_BREAKER_SECONDS` | `10` | Seconds cache misses skip the call after a check the issuer could not answer. `0` turns the breaker off. |
| `mode` | `TOKEN_REVOCATION_MODE` | `off` | `off`, `shadow` or `enforce`. |
| `fail` | `TOKEN_REVOCATION_FAIL` | `open` | `open` admits a token whose status is `unavailable`; `closed` rejects it. |
| `sample_rate` | `TOKEN_REVOCATION_SAMPLE_RATE` | `0.01` | Fraction of checks logged with result, cache hit or miss and latency. |

`mode` and `fail` are case-insensitive. An unrecognised value falls back to the default (`off`, `open`) and logs a warning.

| `mode` | Cache read and call | Returns true for |
| --- | --- | --- |
| `off` | Neither | Nothing |
| `shadow` | Both, and writes the cache as `enforce` would | Nothing; logs `would reject` instead |
| `enforce` | Both | `revoked`, and `unavailable` when `fail` is `closed` |

## The issuer's endpoint

The check expects an endpoint that:

- accepts `POST` with `Authorization: Bearer <machine token>` and the JSON body `{"jti": "<token id>"}`;
- answers `200` with `{"active": true}` or `{"active": false}`, and nothing else counts as an answer;
- does not evaluate expiry, since each validator owns expiry and its own leeway;
- answers a database or other infrastructure error with a 5xx, never `"active": false`;
- answers 401 when it refuses the caller's machine token. The client then drops that token from memory and the cache (`MachineTokenService::invalidate()`), fetches a new one and retries once within the budget. A 403 is `unavailable` like any other status, with no new token: a new token from the same client cannot change a refusal of its scope, or a refusal from a gateway or firewall in front of the route. A route that keeps answering 401 for another reason therefore costs one new machine token per `breaker_seconds` per service while the cache works. While it does not, under a runtime that resets state per request (PHP-FPM, FrankenPHP without workers) nothing remembers the breaker or the token, so each request fetches a token, is refused, drops it, fetches another and is refused again: two token grants and two status calls per request. A long-lived worker keeps the second token in memory, so after its first request it makes one grant and two status calls per request.

## Logging

Every line carries `feature: token_revocation` and an `action`.

| Message | Level | `action` | When | Fields |
| --- | --- | --- | --- | --- |
| `Token revocation: check enabled` | info | `startup` | First check in a process that misses the cache, then hourly, and again after `mode` or `fail` changes. Not logged in `off`. | `mode`, `fail`, `url_host`, `cache_store`, `cache_store_defined`, `sample_rate` |
| `Token revocation: would reject` | warning | `would_reject` | `shadow`, a check `enforce` would reject | `jti`, `sub`, `user_id`, `aud`, `grant_type`, `validator` (the app name), `result`, `reason`, `mode`, `fail` |
| `Token revocation: rejected` | warning | `rejected` | `enforce`, a rejected check | as `would reject` |
| `Token revocation: status unavailable` | warning | `unavailable` | An `unavailable` result other than the breaker, at most once a minute per process | `reason`, `admitted`, `mode`, `fail` |
| `Token revocation: breaker open, status not checked` | warning | `breaker_open` | A miss skipped by the breaker, at most once a minute per process | `reason`, `admitted`, `mode`, `fail` |
| `Token revocation: check` | info | `check` | A `sample_rate` fraction of checks | `result`, `reason`, `cache.hit` (boolean), `cache_error`, `duration_ms` (the client's check), `mode`, `fail` |
| `Token revocation: unrecognised setting, using the default` | warning | `invalid_setting` | `mode` or `fail` not recognised, at most once a minute per process | `mode_setting`, `fail_setting` |

A runtime that resets state per request (PHP-FPM, FrankenPHP without workers) keeps no memory between requests, so the once-per-process and once-a-minute limits are held in the token status store, under keys naming the host and process ID. Checking a limit is a cache write, which is why the startup line waits for a check that missed the cache: a hit costs the one read and nothing more. If that store fails, or the client has just found it down, the guard does not try it again for these limits, and each line is logged at most once per request.

The package's default `SensitiveDataProcessor` redacts `user_id` when PII redaction is on (the default), so `would reject` and `rejected` lines show `user_id` as `[REDACTED]` unless `logging-utils.processors.sensitive_data.preserve_fields` lists it. `sub` and `jti` are not redacted.

`reason` on an `unavailable` result is one of `not_configured`, `missing_jti`, `breaker_open`, `machine_token`, `deadline`, `connection`, `oversized`, `http_<status>`, `malformed` or `unexpected`.

## Testing

Use the `array` store for `token_status` and `Http::fake()` for the status endpoint. `MocksMachineTokenService` binds a token double that also accepts `invalidate()`:

```php
use Illuminate\Support\Facades\Http;
use NuiMarkets\LaravelSharedUtils\Testing\MocksMachineTokenService;

class RevokedTokenTest extends TestCase
{
    use MocksMachineTokenService;

    public function test_revoked_token_is_refused()
    {
        config([
            'cache.stores.token_status' => ['driver' => 'array'],
            'token_revocation.url' => 'https://issuer.test/status',
            'token_revocation.mode' => 'enforce',
        ]);
        $this->mockMachineTokenService();
        Http::fake(['https://issuer.test/status' => Http::response(['active' => false])]);

        $this->withToken($this->signedToken())->getJson('/api/orders')->assertUnauthorized();
    }
}
```
