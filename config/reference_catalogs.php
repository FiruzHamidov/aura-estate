<?php

return [
    'property-types' => [
        'table' => 'property_types',
        'label_columns' => ['name'],
        'references' => [
            ['key' => 'properties', 'label' => 'Объекты недвижимости', 'table' => 'properties', 'column' => 'type_id'],
            ['key' => 'external_property_requests', 'label' => 'Заявки внешних агентов', 'table' => 'external_property_requests', 'column' => 'type_id'],
            ['key' => 'client_needs', 'label' => 'Потребности клиентов', 'table' => 'client_needs', 'column' => 'property_type_id'],
            ['key' => 'client_needs', 'label' => 'Потребности клиентов', 'table' => 'client_need_property_type', 'column' => 'property_type_id', 'entity_column' => 'client_need_id', 'pivot' => true],
        ],
    ],
    'property-statuses' => [
        'table' => 'property_statuses',
        'label_columns' => ['name'],
        'references' => [
            ['key' => 'properties', 'label' => 'Объекты недвижимости', 'table' => 'properties', 'column' => 'status_id'],
        ],
    ],
    'building-types' => [
        'table' => 'building_types',
        'label_columns' => ['name'],
        'references' => [],
    ],
    'parking-types' => [
        'table' => 'parking_types',
        'label_columns' => ['name'],
        'references' => [
            ['key' => 'properties', 'label' => 'Объекты недвижимости', 'table' => 'properties', 'column' => 'parking_type_id'],
        ],
    ],
    'heating-types' => [
        'table' => 'heating_types',
        'label_columns' => ['name'],
        'references' => [
            ['key' => 'properties', 'label' => 'Объекты недвижимости', 'table' => 'properties', 'column' => 'heating_type_id'],
        ],
    ],
    'repair-types' => [
        'table' => 'repair_types',
        'label_columns' => ['name'],
        'references' => [
            ['key' => 'properties', 'label' => 'Объекты недвижимости', 'table' => 'properties', 'column' => 'repair_type_id'],
            ['key' => 'external_property_requests', 'label' => 'Заявки внешних агентов', 'table' => 'external_property_requests', 'column' => 'repair_type_id'],
            ['key' => 'client_needs', 'label' => 'Потребности клиентов', 'table' => 'client_needs', 'column' => 'repair_type_id'],
            ['key' => 'client_needs', 'label' => 'Потребности клиентов', 'table' => 'client_need_repair_type', 'column' => 'repair_type_id', 'entity_column' => 'client_need_id', 'pivot' => true],
        ],
    ],
    'contract-types' => [
        'table' => 'contract_types',
        'label_columns' => ['name'],
        'references' => [
            ['key' => 'properties', 'label' => 'Объекты недвижимости', 'table' => 'properties', 'column' => 'contract_type_id'],
        ],
    ],
    'document-types' => [
        'table' => 'document_types',
        'label_columns' => ['name'],
        'references' => [
            ['key' => 'properties', 'label' => 'Объекты недвижимости', 'table' => 'properties', 'column' => 'document_type_id'],
        ],
    ],
    'locations' => [
        'table' => 'locations',
        'label_columns' => ['city', 'district'],
        'references' => [
            ['key' => 'properties', 'label' => 'Объекты недвижимости', 'table' => 'properties', 'column' => 'location_id'],
            ['key' => 'client_needs', 'label' => 'Потребности клиентов', 'table' => 'client_needs', 'column' => 'location_id'],
            ['key' => 'new_buildings', 'label' => 'Жилые комплексы', 'table' => 'new_buildings', 'column' => 'location_id'],
            ['key' => 'external_property_requests', 'label' => 'Заявки внешних агентов', 'table' => 'external_property_requests', 'column' => 'location_id'],
        ],
    ],
    'branches' => [
        'table' => 'branches',
        'label_columns' => ['name'],
        'references' => [
            ['key' => 'users', 'label' => 'Пользователи', 'table' => 'users', 'column' => 'branch_id'],
            ['key' => 'clients', 'label' => 'Клиенты', 'table' => 'clients', 'column' => 'branch_id'],
            ['key' => 'properties', 'label' => 'Объекты недвижимости', 'table' => 'properties', 'column' => 'branch_id'],
            ['key' => 'branch_groups', 'label' => 'Группы филиала', 'table' => 'branch_groups', 'column' => 'branch_id'],
            ['key' => 'leads', 'label' => 'Лиды', 'table' => 'leads', 'column' => 'branch_id'],
            ['key' => 'crm_deals', 'label' => 'Сделки', 'table' => 'crm_deals', 'column' => 'branch_id'],
            ['key' => 'crm_deal_pipelines', 'label' => 'Воронки сделок', 'table' => 'crm_deal_pipelines', 'column' => 'branch_id'],
            ['key' => 'external_property_requests', 'label' => 'Заявки внешних агентов', 'table' => 'external_property_requests', 'column' => 'branch_id'],
            ['key' => 'kpi_plans', 'label' => 'Планы KPI', 'table' => 'kpi_plans', 'column' => 'branch_id'],
            ['key' => 'kpi_rop_plans', 'label' => 'Планы РОП', 'table' => 'kpi_rop_plans', 'column' => 'branch_id'],
            ['key' => 'kpi_period_locks', 'label' => 'Блокировки KPI', 'table' => 'kpi_period_locks', 'column' => 'branch_id'],
            ['key' => 'user_location_points', 'label' => 'История местоположений', 'table' => 'user_location_points', 'column' => 'branch_id'],
        ],
    ],
    'branch-groups' => [
        'table' => 'branch_groups',
        'label_columns' => ['name'],
        'references' => [
            ['key' => 'users', 'label' => 'Пользователи', 'table' => 'users', 'column' => 'branch_group_id'],
            ['key' => 'clients', 'label' => 'Клиенты', 'table' => 'clients', 'column' => 'branch_group_id'],
            ['key' => 'properties', 'label' => 'Объекты недвижимости', 'table' => 'properties', 'column' => 'branch_group_id'],
            ['key' => 'external_property_requests', 'label' => 'Заявки внешних агентов', 'table' => 'external_property_requests', 'column' => 'branch_group_id'],
            ['key' => 'kpi_plans', 'label' => 'Планы KPI', 'table' => 'kpi_plans', 'column' => 'branch_group_id'],
            ['key' => 'kpi_rop_plans', 'label' => 'Планы РОП', 'table' => 'kpi_rop_plans', 'column' => 'branch_group_id'],
            ['key' => 'kpi_period_locks', 'label' => 'Блокировки KPI', 'table' => 'kpi_period_locks', 'column' => 'branch_group_id'],
            ['key' => 'user_location_points', 'label' => 'История местоположений', 'table' => 'user_location_points', 'column' => 'branch_group_id'],
        ],
    ],
    'roles' => [
        'table' => 'roles',
        'label_columns' => ['name'],
        'protect_system_roles' => true,
        'references' => [
            ['key' => 'users', 'label' => 'Пользователи', 'table' => 'users', 'column' => 'role_id'],
        ],
    ],
    'developers' => [
        'table' => 'developers',
        'label_columns' => ['name'],
        'cleanup_file' => ['disk' => 'public', 'column' => 'logo_path'],
        'references' => [
            ['key' => 'properties', 'label' => 'Объекты недвижимости', 'table' => 'properties', 'column' => 'developer_id'],
            ['key' => 'new_buildings', 'label' => 'Жилые комплексы', 'table' => 'new_buildings', 'column' => 'developer_id'],
        ],
    ],
    'features' => [
        'table' => 'features',
        'label_columns' => ['name'],
        'references' => [
            ['key' => 'properties', 'label' => 'Объекты недвижимости', 'table' => 'feature_property', 'column' => 'feature_id', 'entity_column' => 'property_id', 'pivot' => true],
            ['key' => 'new_buildings', 'label' => 'Жилые комплексы', 'table' => 'feature_new_building', 'column' => 'feature_id', 'entity_column' => 'new_building_id', 'pivot' => true],
        ],
    ],
    'tags' => [
        'table' => 'tags',
        'label_columns' => ['name'],
        'references' => [
            ['key' => 'properties', 'label' => 'Объекты недвижимости', 'table' => 'property_tag', 'column' => 'tag_id', 'entity_column' => 'property_id', 'pivot' => true],
        ],
    ],
    'materials' => [
        'table' => 'materials',
        'label_columns' => ['name'],
        'references' => [
            ['key' => 'new_buildings', 'label' => 'Жилые комплексы', 'table' => 'new_buildings', 'column' => 'material_id'],
        ],
    ],
    'construction-stages' => [
        'table' => 'construction_stages',
        'label_columns' => ['name'],
        'references' => [
            ['key' => 'new_buildings', 'label' => 'Жилые комплексы', 'table' => 'new_buildings', 'column' => 'construction_stage_id'],
            ['key' => 'new_building_blocks', 'label' => 'Корпуса', 'table' => 'new_building_blocks', 'column' => 'construction_stage_id'],
        ],
    ],
    'client-types' => [
        'table' => 'client_types',
        'label_columns' => ['name'],
        'references' => [
            ['key' => 'clients', 'label' => 'Клиенты', 'table' => 'clients', 'column' => 'client_type_id'],
        ],
    ],
    'client-sources' => [
        'table' => 'client_sources',
        'label_columns' => ['name'],
        'references' => [
            ['key' => 'clients', 'label' => 'Клиенты', 'table' => 'clients', 'column' => 'source_id'],
        ],
    ],
    'client-need-types' => [
        'table' => 'client_need_types',
        'label_columns' => ['name'],
        'references' => [
            ['key' => 'client_needs', 'label' => 'Потребности клиентов', 'table' => 'client_needs', 'column' => 'type_id'],
        ],
    ],
    'client-need-statuses' => [
        'table' => 'client_need_statuses',
        'label_columns' => ['name'],
        'references' => [
            ['key' => 'client_needs', 'label' => 'Потребности клиентов', 'table' => 'client_needs', 'column' => 'status_id'],
            ['key' => 'clients', 'label' => 'Клиенты', 'table' => 'clients', 'column' => 'status_id'],
        ],
    ],
];
