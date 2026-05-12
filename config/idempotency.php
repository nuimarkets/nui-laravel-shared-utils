<?php

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
