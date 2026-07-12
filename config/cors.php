<?php

return [

    /*
     * : the SPA is normally served same-origin by Laravel, so CORS isn't
     * exercised; this config keeps credentialed (cookie) auth correct for any
     * split-origin dev setup. Sanctum SPA auth requires `supports_credentials`.
     */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'broadcasting/auth', 'login', 'logout'],

    'allowed_methods' => ['*'],

    // Whitelist explicit origins (comma-separated) - never '*' with credentials.
    'allowed_origins' => array_filter(explode(',', (string) env('CORS_ALLOWED_ORIGINS', env('APP_URL', 'http://localhost')))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
