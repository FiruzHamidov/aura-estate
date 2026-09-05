<?php

return [
    'control_kind' => 'security_property_closure',
    'trigger_statuses' => ['deposit', 'sold', 'sold_by_owner', 'rented', 'deleted'],
    'successful_closing_statuses' => ['sold', 'sold_by_owner', 'rented'],
    'sla_hours' => [
        'claim' => 24,
        'branch_response' => 24,
        'recheck' => 24,
    ],
    'stages' => [
        ['name' => 'Новая', 'slug' => 'new', 'color' => '#64748b', 'sort_order' => 10, 'is_default' => true, 'is_closed' => false, 'is_lost' => false, 'is_active' => true],
        ['name' => 'На проверке СБ', 'slug' => 'security_review', 'color' => '#2563eb', 'sort_order' => 20, 'is_default' => false, 'is_closed' => false, 'is_lost' => false, 'is_active' => true],
        ['name' => 'Запрос в филиал', 'slug' => 'branch_clarification', 'color' => '#f59e0b', 'sort_order' => 30, 'is_default' => false, 'is_closed' => false, 'is_lost' => false, 'is_active' => true],
        ['name' => 'Исправление филиалом', 'slug' => 'branch_correction', 'color' => '#8b5cf6', 'sort_order' => 40, 'is_default' => false, 'is_closed' => false, 'is_lost' => false, 'is_active' => true],
        ['name' => 'Повторная проверка', 'slug' => 'security_recheck', 'color' => '#0891b2', 'sort_order' => 50, 'is_default' => false, 'is_closed' => false, 'is_lost' => false, 'is_active' => true],
        ['name' => 'Подтверждено СБ', 'slug' => 'security_verified', 'color' => '#16a34a', 'sort_order' => 60, 'is_default' => false, 'is_closed' => true, 'is_lost' => false, 'is_active' => true],
        ['name' => 'Подозрительно', 'slug' => 'security_flagged', 'color' => '#dc2626', 'sort_order' => 70, 'is_default' => false, 'is_closed' => true, 'is_lost' => true, 'is_active' => true],
        ['name' => 'Отменено/не требует проверки', 'slug' => 'cancelled', 'color' => '#6b7280', 'sort_order' => 80, 'is_default' => false, 'is_closed' => true, 'is_lost' => false, 'is_active' => true],
    ],
];
