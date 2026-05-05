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

    'v2' => [
        'metric_keys' => ['advertisement', 'call', 'kabul', 'show', 'lead', 'deposit', 'deal'],
        'metric_mapping' => [
            'advertisement' => [
                'source_column' => 'ad_count',
                'source_type' => 'manual',
                'entities' => ['daily_reports.ad_count'],
                'valid_statuses' => [],
                'task_type_codes' => [],
            ],
            'call' => [
                'source_column' => 'calls_count',
                'source_type' => 'manual',
                'entities' => ['daily_reports.calls_count'],
                'valid_statuses' => [],
                'task_type_codes' => [],
            ],
            'kabul' => [
                'source_column' => 'new_clients_count',
                'source_type' => 'manual',
                'entities' => ['daily_reports.new_clients_count'],
                'valid_statuses' => [],
                'task_type_codes' => [],
            ],
            'show' => [
                'source_column' => 'shows_count',
                'source_type' => 'manual',
                'entities' => ['daily_reports.shows_count'],
                'valid_statuses' => [],
                'task_type_codes' => [],
            ],
            'lead' => [
                'source_column' => 'new_properties_count',
                'source_type' => 'manual',
                'entities' => ['daily_reports.new_properties_count'],
                'valid_statuses' => [],
                'task_type_codes' => [],
            ],
            'deposit' => [
                'source_column' => 'deposits_count',
                'source_type' => 'manual',
                'entities' => ['daily_reports.deposits_count'],
                'valid_statuses' => [],
                'task_type_codes' => [],
            ],
            'deal' => [
                'source_column' => 'deals_count',
                'source_type' => 'manual',
                'entities' => ['daily_reports.deals_count'],
                'valid_statuses' => [],
                'task_type_codes' => [],
            ],
        ],
        'targets' => [
            'advertisement' => 20,
            'call' => 30,
            'kabul' => 5,
            'show' => 2,
            'lead' => 1,
            'deposit' => 1,
            'deal' => 1,
        ],
        'weights' => [
            'advertisement' => 0.15,
            'call' => 0.20,
            'kabul' => 0.15,
            'show' => 0.20,
            'lead' => 0.10,
            'deposit' => 0.10,
            'deal' => 0.10,
        ],
    ],
];
