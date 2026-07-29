<?php

return [
    'tracked_roles' => ['agent', 'mop'],
    'viewer_roles' => ['agent', 'mop', 'rop', 'branch_director', 'admin', 'superadmin'],
    'default_enabled' => true,
    'default_mode' => 'work_schedule',
    'default_timezone' => env('LOCATION_TRACKING_TIMEZONE', 'Asia/Dushanbe'),
    'default_schedule' => [
        '1' => [['09:00', '18:00']],
        '2' => [['09:00', '18:00']],
        '3' => [['09:00', '18:00']],
        '4' => [['09:00', '18:00']],
        '5' => [['09:00', '18:00']],
        '6' => [['09:00', '15:00']],
        '7' => [],
    ],
    'foreground_interval_sec' => 30,
    'background_interval_sec' => 120,
    'min_distance_m' => 75,
    'history_retention_days' => 90,
    'offline_window_hours' => 72,
    'live_threshold_seconds' => 120,
    'stale_threshold_seconds' => 900,
    'suspect_speed_kmh' => 250,
    'max_batch_size' => 50,
    'realtime_broadcast_enabled' => env('LOCATION_REALTIME_BROADCAST_ENABLED', false),
    'fallback_polling_interval_seconds' => 30,
];
