<?php

return [
    'active_per_user_limit' => (int) env('STORIES_ACTIVE_PER_USER_LIMIT', 30),
    'active_reel_per_user_limit' => (int) env('STORIES_ACTIVE_REEL_PER_USER_LIMIT', 10),
    'ttl_hours' => (int) env('STORIES_TTL_HOURS', 24),
];

