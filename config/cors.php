<?php

return [

    'paths' => ['*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => [
        'https://*.aura.tj',
        'https://aura.tj',
        'https://manora.tj',
        'https://www.manora.tj',
        'http://localhost:3000',
        ...array_filter(explode(',', (string) env('CORS_EXTRA_ORIGINS', ''))),
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['X-Access-Scope-Version'],

    'max_age' => 0,

    'supports_credentials' => true,
];
