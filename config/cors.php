<?php

return [

    'paths' => ['*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => [
        'https://www.aura.tj',
        'https://dashboard.aura.tj',
        'https://new.aura.tj',
        'https://aura.tj',
        'https://manora.tj',
        'https://www.manora.tj',
        ...(env('APP_ENV') === 'local' ? ['http://localhost:3000'] : []),
        ...array_filter(explode(',', (string) env('CORS_EXTRA_ORIGINS', ''))),
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['X-Access-Scope-Version'],

    'max_age' => 0,

    'supports_credentials' => true,
];
