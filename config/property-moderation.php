<?php

return [
    'creator_roles' => ['agent', 'mop', 'rop', 'branch_director', 'admin', 'superadmin'],
    'moderator_roles' => ['rop', 'branch_director', 'admin', 'superadmin'],
    'global_moderator_roles' => ['admin', 'superadmin'],
    'price_increase_review_percent' => (float) env('PROPERTY_PRICE_INCREASE_REVIEW_PERCENT', 0),
    'promotion_default_days' => (int) env('PROPERTY_PROMOTION_DEFAULT_DAYS', 7),
    'promotion_max_days' => (int) env('PROPERTY_PROMOTION_MAX_DAYS', 30),
    'trust_window_days' => (int) env('PROPERTY_TRUST_WINDOW_DAYS', 90),
    'trust_repeat_multiplier' => (float) env('PROPERTY_TRUST_REPEAT_MULTIPLIER', 1.5),
    'trust_points' => [
        'confirmed_price_manipulation' => -10,
        'confirmed_duplicate' => -15,
        'moderation_bypass_attempt' => -10,
    ],
];
