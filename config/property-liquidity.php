<?php

return [
    'model_version' => 'liquidity-rules-v1',
    'lookback_months' => 12,
    'demand_freshness_days' => 90,
    'minimum_sold_for_prediction' => 15,
    'medium_confidence_sold_count' => 15,
    'high_confidence_sold_count' => 50,
    'public_badge_minimum_confidence' => 45,
    'liquid_score_threshold' => 65,
    'price_position' => [
        'below_market_max_pct' => -5,
        'at_market_max_pct' => 5,
    ],
    'weights' => [
        'district_market' => 0.25,
        'price' => 0.45,
        'demand' => 0.15,
        'apartment_fit' => 0.15,
    ],
    'promotion' => [
        'rotation_days' => 14,
        'minimum_photos' => 5,
        'weights' => [
            'liquidity' => 0.60,
            'content_readiness' => 0.15,
            'freshness' => 0.10,
            'rotation' => 0.10,
            'opportunity' => 0.05,
        ],
    ],
];
