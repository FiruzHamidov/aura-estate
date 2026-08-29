<?php

return [
    'cookie' => env('GUEST_SUPPORT_COOKIE', 'aura_guest_support'),
    'lifetime_minutes' => (int) env('GUEST_SUPPORT_LIFETIME_MINUTES', 43200),
    'cookie_secure' => env('GUEST_SUPPORT_COOKIE_SECURE', true),
    'cookie_same_site' => env('GUEST_SUPPORT_COOKIE_SAME_SITE', 'lax'),
    'create_rate_per_minute' => (int) env('GUEST_SUPPORT_CREATE_RATE_PER_MINUTE', 5),
    'read_rate_per_minute' => (int) env('GUEST_SUPPORT_READ_RATE_PER_MINUTE', 60),
    'message_rate_per_minute' => (int) env('GUEST_SUPPORT_MESSAGE_RATE_PER_MINUTE', 15),
];
