<?php

// Shared registry for permission definitions and legacy catalog mutation routes.
return [
    'branches' => ['controller' => 'BranchController', 'name' => 'Филиалы'],
    'property-types' => ['controller' => 'PropertyTypeController', 'name' => 'Типы недвижимости'],
    'property-statuses' => ['controller' => 'PropertyStatusController', 'name' => 'Статусы недвижимости'],
    'building-types' => ['controller' => 'BuildingTypeController', 'name' => 'Типы зданий'],
    'parking-types' => ['controller' => 'ParkingTypeController', 'name' => 'Типы парковки'],
    'heating-types' => ['controller' => 'HeatingTypeController', 'name' => 'Типы отопления'],
    'contract-types' => ['controller' => 'ContractTypeController', 'name' => 'Типы договоров'],
    'document-types' => ['controller' => 'DocumentTypeController', 'name' => 'Типы документов'],
    'repair-types' => ['controller' => 'RepairTypeController', 'name' => 'Типы ремонта'],
    'locations' => ['controller' => 'LocationController', 'name' => 'Города и районы'],
    'developers' => ['controller' => 'DeveloperController', 'name' => 'Застройщики'],
    'features' => ['controller' => 'FeatureController', 'name' => 'Особенности объектов'],
    'tags' => ['controller' => 'TagController', 'name' => 'Теги объектов'],
    'materials' => ['controller' => 'MaterialController', 'name' => 'Материалы'],
    'construction-stages' => ['controller' => 'ConstructionStageController', 'name' => 'Этапы строительства'],
];
