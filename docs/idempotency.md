# Idempotency Middleware

## Overview

`IdempotencyMiddleware` lets write endpoints safely replay completed
responses when a caller retries the same request. It is designed for POST,
PUT, PATCH, and DELETE routes where a network timeout or gateway retry could
otherwise create duplicate records or repeat side effects.

The middleware supports two key strategies:

- Caller-provided `Idempotency-Key` headers for clients that can generate
  stable retry keys.
- A short-lived body-hash fallback for clients that retry byte-identical
  requests without sending a key.

The middleware is opt-in. Applications register the service provider, publish
config if they need overrides, and attach the middleware only to routes that
should use this contract.

## Installation

Register the provider in the consuming application:

```php
// config/app.php
'providers' => [
    NuiMarkets\LaravelSharedUtils\Providers\IdempotencyServiceProvider::class,
],
```

Publish the config when local overrides are needed:

```bash
php artisan vendor:publish --tag=idempotency-config
```

Attach the middleware to selected routes or route groups:

```php
use NuiMarkets\LaravelSharedUtils\Http\Middleware\IdempotencyMiddleware;

Route::post('/orders', [OrderController::class, 'store'])
    ->middleware(IdempotencyMiddleware::class);
```

Enable it through configuration:

```bash
IDEMPOTENCY_ENABLED=true
IDEMPOTENCY_REDIS_CONNECTION=default
```

## Request Contract

### Header Path

Clients should send an `Idempotency-Key` header for retryable write requests:

```http
POST /orders HTTP/1.1
Content-Type: application/json
Idempotency-Key: 4c03a6e8-13be-4d47-861f-d79b312bc880
```

Header keys must be printable ASCII, non-empty after trimming, contain no
whitespace or control characters, and fit within `header_max_length`.

Invalid keys return `400 idempotency_key_invalid`. The middleware does not
fall back to body hashing when a malformed header is present, because the
caller attempted to use the explicit idempotency contract.

### Body-Hash Fallback

When the header is absent, the middleware can synthesize a key from:

- authenticated actor scope
- HTTP method
- route identity
- raw request body hash

The body hash uses raw request bytes. JSON is not normalized. Retried requests
must send byte-identical bodies to replay.

Body-hash entries use a shorter TTL by default because they are intended to
catch rapid retries from systems that cannot send an idempotency header.

### Content-Type Skips

If no `Idempotency-Key` header is present, configured upload-style request
content types are skipped by default:

- `multipart/form-data`
- `application/octet-stream`

If a valid `Idempotency-Key` header is present, the header path still runs
even for these request content types.

## Cache Identity

The Redis key does not store raw user IDs, organization IDs, route data, or
caller-provided idempotency keys. Components are joined with an ASCII unit
separator and hashed.

The cache identity includes:

- actor scope, including organization ID when available
- HTTP method
- route name plus sorted route parameters, when a named route exists
- normalized query string
- caller-provided idempotency key, or synthesized body-hash key

The stored fingerprint includes the actor scope, method, route identity, and
raw request body hash. Reusing the same `Idempotency-Key` for the same actor,
method, route identity, and query string with a different body returns
`422 idempotency_key_conflict`.

Changing the route, query string, actor, or organization changes the Redis key
scope. Those requests do not replay each other.

## Cached Responses

Only configured replayable status codes are cached. Defaults:

```php
[200, 201, 202, 204, 422]
```

The cached payload stores:

```json
{
  "status": 201,
  "headers": {
    "content-type": "application/json",
    "location": "/orders/123"
  },
  "body_b64": "eyJpZCI6IjEyMyJ9",
  "fingerprint": "sha256-hex",
  "state": "complete",
  "completed_at": 1778544000,
  "locked_at": 1778543999
}
```

Response bodies are base64 encoded before storage so arbitrary bytes can
round-trip through JSON. The `max_response_bytes` limit applies to the raw
response body before base64 expansion.

### Header Replay

Only allowlisted response headers are stored and replayed. Defaults:

```php
[
    'content-type',
    'cache-control',
    'etag',
    'location',
]
```

`Location` is included so `201 Created` responses can replay the created
resource URL.

The middleware adds these headers to replayed responses:

```http
X-Idempotency-Replay: 1
X-Idempotency-Original-Status: 201
```

Middleware-generated error responses, including 400, 409, and 422 responses,
and replay responses receive `Cache-Control: no-store` unless that directive is
already present.

### No-Body Responses

Configured no-body statuses can be cached without a `Content-Type` header when
the response body is empty. Default:

```php
'no_body_status_codes' => [204],
```

Responses with a body, or with statuses outside this list, still need a
replayable content type.

## Inflight Requests

The first matching request writes an inflight lock to Redis using `SET NX`
with `lock_ttl`.

If a duplicate request arrives while the first request is still processing,
the middleware returns:

```http
409 Conflict
Retry-After: 5
Cache-Control: no-store
```

`Retry-After` is computed from the remaining lock TTL when the inflight payload
is readable. It falls back to `retry_after_seconds` otherwise.

Completed payloads are written in a Laravel terminating callback. If the lock
expires before the callback runs, the middleware logs
`idempotency.lock_expired_before_complete` and skips the stale write.

Set `lock_ttl` higher than the worst-case controller execution time for
protected routes. If a controller can legitimately run longer than 60 seconds,
raise `IDEMPOTENCY_LOCK_TTL`.

## Error Responses

Plain JSON is used by default:

```json
{
  "error": "idempotency_key_conflict",
  "message": "The idempotency key was already used for a different request."
}
```

When the request `Accept` header includes `application/vnd.api+json`, JSON:API
error shape is used:

```json
{
  "errors": [
    {
      "status": "422",
      "code": "idempotency_key_conflict",
      "title": "Idempotency Key Conflict",
      "detail": "The idempotency key was already used for a different request."
    }
  ]
}
```

## Configuration Reference

```php
return [
    'enabled' => env('IDEMPOTENCY_ENABLED', false),
    'redis_connection' => env('IDEMPOTENCY_REDIS_CONNECTION', 'default'),
    'key_prefix' => env('IDEMPOTENCY_KEY_PREFIX', 'idem:v1'),
    'ttl_header' => env('IDEMPOTENCY_TTL_HEADER', 600),
    'ttl_body_hash' => env('IDEMPOTENCY_TTL_BODY_HASH', 30),
    'lock_ttl' => env('IDEMPOTENCY_LOCK_TTL', 60),
    'header_name' => env('IDEMPOTENCY_HEADER_NAME', 'Idempotency-Key'),
    'header_max_length' => env('IDEMPOTENCY_HEADER_MAX_LENGTH', 255),
    'retry_after_seconds' => env('IDEMPOTENCY_RETRY_AFTER_SECONDS', 5),
    'max_response_bytes' => env('IDEMPOTENCY_MAX_RESPONSE_BYTES', 262144),
    'replayable_status_codes' => [200, 201, 202, 204, 422],
    'no_body_status_codes' => [204],
    'replayable_content_types' => [
        'application/json',
        'application/vnd.api+json',
        'text/plain',
    ],
    'replay_headers_allowlist' => [
        'content-type',
        'cache-control',
        'etag',
        'location',
    ],
    'body_hash_skip_content_types' => [
        'multipart/form-data',
        'application/octet-stream',
    ],
];
```

## Logging

The middleware logs these events:

- `idempotency.lock_acquired`
- `idempotency.replay`
- `idempotency.conflict`
- `idempotency.inflight_reject`
- `idempotency.fail_open`
- `idempotency.skip_cache`
- `idempotency.lock_expired_before_complete`

Skip cache logs include `skip_reason`:

- `streamed`
- `oversize`
- `content_type`
- `status_code`

Redis errors fail open. The request continues through the application and the
middleware logs `idempotency.fail_open`.

## Testing

The package includes unit and feature coverage for the middleware:

```bash
composer test IdempotencyMiddleware
```

The test suite includes coverage for header replay, body-hash fallback, route
and query scoping, invalid keys, conflicts, inflight rejection, 201 `Location`
replay, 204 no-content replay, binary body round-trips, and Redis fail-open
behavior.
