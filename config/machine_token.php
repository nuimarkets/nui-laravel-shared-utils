<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Machine Token Cache Key
    |--------------------------------------------------------------------------
    |
    | The logical cache key used to store the current machine token. Consumer
    | applications may add a cache prefix, but this suffix should remain stable.
    |
    */
    'redis_key' => env('MACHINE_TOKEN_REDIS_KEY', 'machine_token'),

    /*
    |--------------------------------------------------------------------------
    | Refresh Window
    |--------------------------------------------------------------------------
    |
    | Number of seconds before token expiry when the service should refresh the
    | token. The default is seven days.
    |
    */
    'time_before_expire' => (int) env('MACHINE_TOKEN_TIME_BEFORE_EXPIRE', 7 * 24 * 3600),

    'client_id' => env('MACHINE_TOKEN_CLIENT_ID'),
    'secret' => env('MACHINE_TOKEN_SECRET'),
    'url' => env('MACHINE_TOKEN_URL'),
];
