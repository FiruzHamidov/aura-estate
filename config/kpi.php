<?php

return [
    'working_days_per_week' => 6,
    'daily_report' => [
        'enforce_submission' => (bool) env('DAILY_REPORT_ENFORCE_SUBMISSION', true),
        'enforced_roles' => array_values(array_filter(array_map(
            static fn (string $role): string => trim($role),
            explode(',', (string) env('DAILY_REPORT_ENFORCED_ROLES', 'agent,mop,intern'))
        ))),
        // Time in app timezone after which yesterday's missing daily report becomes required.
        'missing_report_check_time' => (string) env('DAILY_REPORT_MISSING_CHECK_TIME', '11:00'),
    ],

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
        'metric_keys' => ['objects', 'shows', 'ads', 'calls', 'sales'],
        'metric_mapping' => [
            'objects' => [
                'source_column' => 'new_properties_count',
                'source_type' => 'system',
                'entities' => ['properties.created_at'],
                'valid_statuses' => [],
                'task_type_codes' => [],
            ],
            'shows' => [
                'source_column' => 'shows_count',
                'source_type' => 'system',
                'entities' => ['bookings.start_time'],
                'valid_statuses' => [],
                'task_type_codes' => [],
            ],
            'ads' => [
                'source_column' => 'ad_count',
                'source_type' => 'manual',
                'entities' => ['daily_reports.ad_count'],
                'valid_statuses' => [],
                'task_type_codes' => [],
            ],
            'calls' => [
                'source_column' => 'calls_count',
                'source_type' => 'manual',
                'entities' => ['daily_reports.calls_count'],
                'valid_statuses' => [],
                'task_type_codes' => [],
            ],
            'sales' => [
                'source_column' => 'sales_count',
                'source_type' => 'manual',
                'entities' => ['properties.sold_at', 'property_agent_sales.agent_id'],
                'valid_statuses' => [],
                'task_type_codes' => [],
            ],
        ],
        'targets' => [
            'objects' => 1,
            'shows' => 2,
            'ads' => 20,
            'calls' => 30,
            'sales' => 1,
        ],
        'weights' => [
            'objects' => 0.20,
            'shows' => 0.20,
            'ads' => 0.20,
            'calls' => 0.20,
            'sales' => 0.20,
        ],
    ],
];
