<?php

return [
    'timezone' => env('ATTENDANCE_TIMEZONE', 'Asia/Dushanbe'),
    'device_request_max_bytes' => (int) env('ATTENDANCE_DEVICE_REQUEST_MAX_BYTES', 262144),
    'device_rate_limit_per_minute' => (int) env('ATTENDANCE_DEVICE_RATE_LIMIT_PER_MINUTE', 240),
    'duplicate_window_seconds' => (int) env('ATTENDANCE_DUPLICATE_WINDOW_SECONDS', 10),
    'offline_threshold_minutes' => (int) env('ATTENDANCE_OFFLINE_THRESHOLD_MINUTES', 10),
    'clock_drift_warning_seconds' => (int) env('ATTENDANCE_CLOCK_DRIFT_WARNING_SECONDS', 300),
    'clock_drift_measurement_window_seconds' => (int) env('ATTENDANCE_CLOCK_DRIFT_MEASUREMENT_WINDOW_SECONDS', 1800),
    'raw_retention_days' => (int) env('ATTENDANCE_RAW_RETENTION_DAYS', 90),
    'queue_stale_after_minutes' => (int) env('ATTENDANCE_QUEUE_STALE_AFTER_MINUTES', 15),
    'allowed_ips' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ATTENDANCE_ALLOWED_IPS', ''))
    ))),
    'administrator_roles' => ['admin', 'superadmin', 'owner'],
    'default_schedule' => [
        '1' => ['start' => '09:00', 'end' => '18:00', 'grace_minutes' => 0],
        '2' => ['start' => '09:00', 'end' => '18:00', 'grace_minutes' => 0],
        '3' => ['start' => '09:00', 'end' => '18:00', 'grace_minutes' => 0],
        '4' => ['start' => '09:00', 'end' => '18:00', 'grace_minutes' => 0],
        '5' => ['start' => '09:00', 'end' => '18:00', 'grace_minutes' => 0],
        '6' => ['start' => '09:00', 'end' => '15:00', 'grace_minutes' => 0],
        '7' => null,
    ],
    // ZKTeco status codes used by TA PUSH. Unknown values remain "punch".
    'status_map' => [
        '0' => 'check_in',
        '1' => 'check_out',
        '4' => 'check_in',
        '5' => 'check_out',
    ],
    'verification_map' => [
        '0' => 'password',
        '1' => 'fingerprint',
        '2' => 'card',
        '4' => 'card',
        '15' => 'face',
        '20' => 'face',
        '200' => 'face',
    ],
];
