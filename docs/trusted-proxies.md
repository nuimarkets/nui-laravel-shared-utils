# Client IP behind AWS API Gateway

`TrustAwsApiGatewayProxies` makes `$request->ip()` return the real caller for services
reached through AWS API Gateway and then a load balancer. Everything that reads the client IP
picks it up: `RequestLoggingMiddleware` (`request.ip`), `EnvironmentProcessor`, rate limiters
keyed by IP, and audit fields.

## The problem it fixes

The framework's `TrustProxies` with `$proxies = '*'` trusts only the immediate caller. For a
request routed `client -> API Gateway -> load balancer -> app`, the `X-Forwarded-For` header
arrives as:

```text
<anything the client sent>, <client IP, appended by API Gateway>, <API Gateway egress IP, appended by the load balancer>
```

Symfony resolves the client IP by walking that list from the right, skipping trusted proxies.
Trusting only the load balancer stops at the API Gateway egress address, so every request
through the gateway logs and throttles as one of a few AWS addresses.

This middleware also trusts the published `API_GATEWAY` ranges for one AWS region, so the
walk skips the gateway hop and returns the address API Gateway appended. Requests that reach
the load balancer directly resolve exactly as before.

## Usage

Register it as the first global middleware, in place of the framework's `TrustProxies`.

Laravel 11+ (`bootstrap/app.php`):

```php
use Illuminate\Http\Middleware\TrustProxies;
use NuiMarkets\LaravelSharedUtils\Http\Middleware\TrustAwsApiGatewayProxies;

->withMiddleware(function (Middleware $middleware) {
    $middleware->replace(TrustProxies::class, TrustAwsApiGatewayProxies::class);
})
```

HTTP kernel:

```php
protected $middleware = [
    \App\Http\Middleware\TrustProxies::class, // extends TrustAwsApiGatewayProxies
    // ...
];
```

```php
namespace App\Http\Middleware;

use NuiMarkets\LaravelSharedUtils\Http\Middleware\TrustAwsApiGatewayProxies;

class TrustProxies extends TrustAwsApiGatewayProxies
{
    protected ?string $region = 'ap-southeast-2';
}
```

| Property | Default | Meaning |
|---|---|---|
| `$region` | `AWS_REGION`, then `AWS_DEFAULT_REGION` | Region whose API Gateway ranges are trusted. Set it explicitly when the runtime does not export the variable. |
| `$additionalProxies` | `[]` | Extra IPs or CIDRs to trust, such as a CDN in front of the load balancer. |
| `$headers` | `X-Forwarded-For`, `-Host`, `-Port`, `-Proto` | As in the framework middleware. |

The immediate caller (`REMOTE_ADDR`) is always trusted, as with `'*'`. The static
`TrustProxies::at()` override is ignored, because `at('**')` would trust every hop and hand
back the leftmost, client-controlled entry.

## What it guarantees and what it does not

- A value the client puts in `X-Forwarded-For` is never returned for traffic through your
  gateway: it sits to the left of the address API Gateway appends.
- Non-IP strings in the header are dropped by Symfony, never returned.
- An unknown region, or one with no published ranges, trusts only the immediate caller,
  which is the framework's `'*'` behaviour.
- **Anyone can run their own API Gateway in the same region and choose what it forwards**,
  since its egress is trusted too. Treat the resolved IP as good for logging, attribution and
  throttling. Enforce IP allow-lists at the gateway on the source IP it records, not on
  `$request->ip()`.

## Refreshing the ranges

The ranges are committed in `resources/aws/api-gateway-ranges.php`, so nothing is fetched at
runtime. Regenerate them from AWS's published list:

```bash
php scripts/refresh-aws-api-gateway-ranges.php
```

The file records the list's `createDate`. A range AWS adds after the last refresh is not
trusted, so requests through it resolve to the gateway address until the next refresh, never
to a client-supplied one.
