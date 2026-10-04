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

This middleware also trusts that one gateway hop, so the walk skips it and returns the
address API Gateway appended. It trusts exactly one entry: walking from the right past the
load balancer and any `$additionalProxies`, the first entry is trusted when it falls in the
region's published `API_GATEWAY` ranges, and nothing to its left is. Symfony trusts by
address rather than position, so the hop is trusted only when the entry to its left (the
caller API Gateway appended) is a valid address other than the hop and the load balancer.
Otherwise the gateway address is returned. Requests that reach the
load balancer directly resolve exactly as before.

Trusting the ranges as a whole would not be enough. A caller whose own address is in those
ranges (another API Gateway, for instance) would make every entry trusted, and Symfony then
falls back to the leftmost entry, which the client wrote.

## Requirements

The resolution relies on two facts about the deployment, and is only as trustworthy as they
are:

- **The load balancer appends to `X-Forwarded-For`.** On an AWS Application Load Balancer
  that is the default `append` processing mode. In `preserve` mode the load balancer passes
  the header through untouched, so the rightmost entry is whatever the client wrote: a
  client can put a gateway-range address there and have the entry to its left returned.
  `remove` mode leaves nothing to resolve. Keep the load balancer in `append` mode.
- **Only the load balancer can reach the application.** The immediate caller
  (`REMOTE_ADDR`) is always trusted, as with the framework's `'*'`, so anything that reaches
  the application directly is treated as a proxy and can set the resolved IP through
  `X-Forwarded-For`. Restrict ingress to the load balancer, for example with security groups
  that admit only its traffic.

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
| --- | --- | --- |
| `$region` | `AWS_REGION`, then `AWS_DEFAULT_REGION` | Region whose API Gateway ranges are trusted. Set it explicitly when the runtime does not export the variable. |
| `$additionalProxies` | `[]` | Extra IPs or CIDRs to trust, such as a CDN in front of the load balancer. |
| `$headers` | The framework default | Which forwarded headers are honoured, as in the framework middleware. |

The immediate caller (`REMOTE_ADDR`) is always trusted, as with `'*'`. The static
`TrustProxies::at()` override is ignored, because `at('**')` would trust every hop and hand
back the leftmost, client-controlled entry. A `$proxies` property or `trustedproxy.proxies`
config carried over from the framework middleware is ignored too: move those entries to
`$additionalProxies`.

## What it guarantees and what it does not

- A value the client puts in `X-Forwarded-For` is never returned for traffic through your
  gateway: it sits to the left of the address API Gateway appends, and only the hop to the
  right of that address is trusted.
- Non-IP strings in the header are dropped by Symfony, never returned.
- An unknown region, or one with no published ranges, trusts only the immediate caller,
  which is the framework's `'*'` behaviour.
- **Anyone can run their own API Gateway in the same region and choose what it forwards**,
  since its egress is a gateway address too. Treat the resolved IP as good for logging, attribution and
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
