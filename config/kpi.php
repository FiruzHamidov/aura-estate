<?php

return [
    'working_days_per_week' => 6,

    // Daily targets + weight for KPI formula.
    'metrics' => [
        'ad_count' => [
            'label' => 'Реклама',
            'target' => 20,
            'weight' => 0.15,
        ],
        'calls_count' => [
            'label' => 'Звонок',
            'target' => 30,
            'weight' => 0.20,
        ],
        'new_clients_count' => [
            'label' => 'Кабул',
            'target' => 5,
            'weight' => 0.15,
        ],
        'shows_count' => [
            'label' => 'Показ',
            'target' => 2,
            'weight' => 0.20,
        ],
        'meetings_count' => [
            'label' => 'Встреча',
            'target' => 1,
            'weight' => 0.10,
        ],
        'deposits_count' => [
            'label' => 'Залог',
            'target' => 1,
            'weight' => 0.10,
        ],
        'deals_count' => [
            'label' => 'Сделка',
            'target' => 1,
            'weight' => 0.10,
        ],
    ],

    'status_thresholds' => [
        'success' => 1.0,
        'control' => 0.8,
        'risk' => 0.6,
    ],
];
