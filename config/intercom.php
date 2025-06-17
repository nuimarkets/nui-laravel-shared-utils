<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Intercom API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for direct Intercom API integration.
    |
    */
    'token' => env('INTERCOM_TOKEN'),
    'api_version' => env('INTERCOM_API_VERSION', '2.13'),
    'base_url' => env('INTERCOM_BASE_URL', 'https://api.intercom.io'),
    'workspace_id' => env('INTERCOM_WORKSPACE_ID'),
    'service_name' => env('INTERCOM_SERVICE_NAME', env('APP_NAME', 'connect-service')),
    'enabled' => env('INTERCOM_ENABLED', false),
    'fail_silently' => env('INTERCOM_FAIL_SILENTLY', true),
    'timeout' => env('INTERCOM_TIMEOUT', 10),
    'batch_size' => env('INTERCOM_BATCH_SIZE', 50),
];