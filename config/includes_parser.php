<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Includes
    |--------------------------------------------------------------------------
    |
    | These includes will be automatically added to all API responses unless
    | explicitly excluded. Common Connect Platform defaults might include
    | 'tenant', 'shortdata', or other frequently needed data.
    |
    */
    'default_includes' => [
        // Example: 'tenant', 'shortdata'
    ],

    /*
    |--------------------------------------------------------------------------
    | Disabled Includes
    |--------------------------------------------------------------------------
    |
    | These includes will be blocked from being included in API responses
    | for security or performance reasons. They cannot be overridden by
    | query parameters or default settings.
    |
    */
    'disabled_includes' => [
        // Example: 'sensitive_data', 'internal_only'
    ],
];
