<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Token Status URL
    |--------------------------------------------------------------------------
    |
    | The token issuer's status endpoint. TokenStatusClient POSTs {"jti": ...}
    | to it with this service's machine token and expects 200
    | {"active": true|false}. Unset, every check is `unavailable` and no call
    | is made.
    |
    */
    'url' => env('TOKEN_STATUS_URL'),

    /*
    |--------------------------------------------------------------------------
    | Cache Store
    |--------------------------------------------------------------------------
    |
    | The cache store holding answers and the breaker key. Define it on its
    | own Redis connection with short connection and read timeouts (see
    | docs/token-revocation.md), so a slow cache cannot stall the request.
    |
    */
    'store' => env('TOKEN_STATUS_CACHE_STORE', 'token_status'),

    /*
    |--------------------------------------------------------------------------
    | Answer Lifetimes (seconds)
    |--------------------------------------------------------------------------
    |
    | active_ttl bounds how long a revocation can go unnoticed while this
    | service's checks are answered: an `active` answer is trusted for this
    | long from the moment its check started. Issuer failures add the time
    | they recur plus breaker_seconds. revoked_grace is how long past the
    | token's own expiry a `revoked` answer is kept.
    |
    */
    'active_ttl' => (int) env('TOKEN_STATUS_ACTIVE_TTL', 30),
    'revoked_grace' => (int) env('TOKEN_STATUS_REVOKED_GRACE', 60),

    /*
    |--------------------------------------------------------------------------
    | Call Budget (seconds)
    |--------------------------------------------------------------------------
    |
    | timeout is the whole check's budget: fetching this service's machine
    | token, the status call and reading its body. connect_timeout caps the
    | status call's connection phase inside that budget.
    |
    */
    'connect_timeout' => (float) env('TOKEN_STATUS_CONNECT_TIMEOUT', 1),
    'timeout' => (float) env('TOKEN_STATUS_TIMEOUT', 2),

    /*
    |--------------------------------------------------------------------------
    | Breaker (seconds)
    |--------------------------------------------------------------------------
    |
    | After a call that says the issuer, or the path to it, failed (a
    | connection failure or timeout, a 5xx, a 429, a redirect, a 200 that is
    | no answer, or a 401 after the retry), cache misses skip the call and are
    | `unavailable` for this long. A failure before the call (no machine
    | token, the budget spent) and any other 4xx open nothing. Cached answers
    | keep being served.
    |
    */
    'breaker_seconds' => (int) env('TOKEN_STATUS_BREAKER_SECONDS', 10),

    /*
    |--------------------------------------------------------------------------
    | Guard Mode and Failure Setting
    |--------------------------------------------------------------------------
    |
    | mode: off (no cache read, no call), shadow (check and log what would be
    | rejected, reject nothing) or enforce. fail: whether an `unavailable`
    | result admits the token (open) or rejects it (closed). An unrecognised
    | value falls back to the default and is logged.
    |
    */
    'mode' => env('TOKEN_REVOCATION_MODE', 'off'),
    'fail' => env('TOKEN_REVOCATION_FAIL', 'open'),

    /*
    |--------------------------------------------------------------------------
    | Sample Rate
    |--------------------------------------------------------------------------
    |
    | Fraction of checks logged with their result, cache hit or miss and
    | latency. 0.01 logs one check in a hundred.
    |
    */
    'sample_rate' => (float) env('TOKEN_REVOCATION_SAMPLE_RATE', 0.01),
];
