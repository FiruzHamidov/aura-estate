<?php

return [
    'api_requests' => [
        'enabled' => (bool) env('AUDIT_API_REQUESTS_ENABLED', true),
        'retention_days' => (int) env('AUDIT_API_REQUESTS_RETENTION_DAYS', 90),
        'log_methods' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('AUDIT_API_REQUESTS_METHODS', 'GET,POST,PUT,PATCH,DELETE'))
        ))),
        'excluded_paths' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('AUDIT_API_REQUESTS_EXCLUDED_PATHS', 'api/up,up'))
        ))),
        'sensitive_fields' => [
            'password',
            'password_confirmation',
            'current_password',
            'new_password',
            'token',
            'access_token',
            'refresh_token',
            'authorization',
            'remember_token',
            'phone',
            'owner_phone',
            'buyer_phone',
            'client_phone',
            'email',
        ],
        'max_string_length' => (int) env('AUDIT_API_REQUESTS_MAX_STRING_LENGTH', 1000),
        'max_array_items' => (int) env('AUDIT_API_REQUESTS_MAX_ARRAY_ITEMS', 50),
    ],
];
