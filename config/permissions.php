<?php

// Defaults are used for migration and for compatibility with an older database.
// Runtime changes live on roles; editing this file must never reset saved grants.
return [
    'roles.manage' => [
        'group' => 'Администрирование', 'name' => 'Управление ролями и правами',
        'description' => 'Создание ролей, изменение их разрешений и назначение прав управления доступом. Даёт возможность расширять права других ролей.',
        'roles' => ['admin', 'superadmin'], 'sensitive' => true,
    ],
    'catalogs.manage' => [
        'group' => 'Справочники', 'name' => 'Управление справочниками',
        'description' => 'Просмотр связей, объединение и удаление записей общих справочников. Системные записи и используемые записи защищены от прямого удаления.',
        'roles' => ['admin', 'superadmin'], 'sensitive' => true,
    ],
    'catalogs.new_buildings.manage' => [
        'group' => 'Справочники', 'name' => 'Справочники новостроек',
        'description' => 'Управление застройщиками, стадиями строительства, материалами, особенностями и локациями через раздел справочников.',
        'roles' => ['admin', 'superadmin', 'owner', 'agent', 'mop'],
    ],
    'properties.analytics.view' => [
        'group' => 'Недвижимость', 'name' => 'Аналитика просмотров и обращений',
        'description' => 'Просмотр статистики просмотров объявлений, нажатий на телефон, чат и WhatsApp с фильтрами по сотруднику, филиалу и группе.',
        'roles' => ['admin', 'superadmin', 'marketing'],
    ],
    'crm.task_types.view' => [
        'group' => 'CRM', 'name' => 'Просмотр типов задач',
        'description' => 'Получение списка типов CRM-задач, включая группы и признаки участия в KPI. Не разрешает изменять сами задачи.',
        'roles' => ['admin', 'superadmin', 'rop', 'branch_director', 'mop', 'agent'],
    ],
    'properties.create' => [
        'group' => 'Недвижимость',
        'name' => 'Создание объявлений',
        'description' => 'Создание нового объекта и отправка на модерацию. Проверки обязательных полей, ответственного и группы сохраняются.',
        'roles_config' => 'property-moderation.creator_roles',
    ],
    'properties.edit' => [
        'group' => 'Недвижимость',
        'name' => 'Редактирование объявлений',
        'description' => 'Редактирование доступных объектов: своих, своей группы или филиала в пределах области роли. Не разрешает утверждать изменения.',
        'roles_config' => 'property-moderation.creator_roles',
    ],
    'properties.deal.manage' => [
        'group' => 'Недвижимость',
        'name' => 'Залог и результат сделки',
        'description' => 'Фиксация залога и результата сделки по доступным объявлениям. Для агента сохраняется работа с доступными чужими объявлениями.',
        'roles_config' => 'property-moderation.creator_roles',
    ],
    'properties.moderate' => [
        'group' => 'Недвижимость',
        'name' => 'Модерация объявлений',
        'description' => 'Проверка заявок, принятие решений, работа с дублями, апелляциями и продвижением в доступном филиале или закреплённых группах.',
        'roles_config' => 'property-moderation.moderator_roles',
    ],
    'properties.manage_all' => [
        'group' => 'Недвижимость',
        'name' => 'Объявления всех филиалов',
        'description' => 'Расширяет область редактирования и модерации до всех филиалов. Для действий нужны отдельные разрешения. Ограничения закреплённых групп РОП сохраняются.',
        'roles_config' => 'property-moderation.global_moderator_roles',
        'sensitive' => true,
    ],
    'properties.break_glass' => [
        'group' => 'Недвижимость',
        'name' => 'Аварийное вмешательство',
        'description' => 'Исключительное вмешательство в процесс модерации с обязательной причиной и записью в журнале.',
        'roles' => ['superadmin'],
        'sensitive' => true,
    ],
    'attendance.table.view' => [
        'group' => 'Посещаемость',
        'name' => 'Таблица посещаемости',
        'description' => 'Просмотр таблицы, детализации и доступных сотрудников.',
        'roles_config' => 'attendance.table_roles',
    ],
    'attendance.devices.manage' => [
        'group' => 'Посещаемость',
        'name' => 'Управление терминалами',
        'description' => 'Добавление, настройка и обслуживание устройств учёта посещаемости.',
        'roles_config' => 'attendance.administrator_roles',
    ],
    'attendance.devices.view' => [
        'group' => 'Посещаемость',
        'name' => 'Просмотр терминалов',
        'description' => 'Просмотр устройств и их состояния без права настройки.',
        'roles_config' => 'attendance.device_viewer_roles',
    ],
    'attendance.mappings.manage' => [
        'group' => 'Посещаемость',
        'name' => 'Сопоставления сотрудников',
        'description' => 'Связывание учётных записей терминалов с сотрудниками в доступной области.',
        'roles_config' => 'attendance.mapping_roles',
    ],
    'attendance.hr_report.view' => [
        'group' => 'Посещаемость',
        'name' => 'HR-отчёт посещаемости',
        'description' => 'Просмотр HR-отчёта по доступным сотрудникам.',
        'roles_config' => 'attendance.hr_report_roles',
    ],
    'attendance.comments.manage' => [
        'group' => 'Посещаемость',
        'name' => 'Комментарии HR',
        'description' => 'Добавление и изменение HR-комментариев к дням посещаемости.',
        'roles_config' => 'attendance.comment_roles',
    ],
    'attendance.schedules.manage' => [
        'group' => 'Посещаемость',
        'name' => 'Рабочие графики',
        'description' => 'Настройка рабочих графиков сотрудников.',
        'roles_config' => 'attendance.schedule_roles',
    ],
    'attendance.leaves.manage' => [
        'group' => 'Посещаемость',
        'name' => 'Отпуска и отсутствия',
        'description' => 'Назначение и изменение отпусков и периодов отсутствия.',
        'roles_config' => 'attendance.leave_roles',
    ],
    'attendance.duties.manage' => [
        'group' => 'Посещаемость',
        'name' => 'Дежурства',
        'description' => 'Назначение и изменение дежурств сотрудников.',
        'roles_config' => 'attendance.duty_roles',
    ],
    'attendance.holidays.manage' => [
        'group' => 'Посещаемость',
        'name' => 'Праздничные дни',
        'description' => 'Настройка календаря праздничных и нерабочих дней.',
        'roles_config' => 'attendance.holiday_roles',
    ],
    'attendance.all_branches.view' => [
        'group' => 'Посещаемость',
        'name' => 'Посещаемость всех филиалов',
        'description' => 'Расширяет выборку посещаемости на сотрудников всех филиалов. Для отчётов и изменений нужны отдельные права.',
        'roles_config' => 'attendance.all_branch_viewer_roles',
        'sensitive' => true,
    ],
    'attendance.presence.view' => [
        'group' => 'Посещаемость',
        'name' => 'Присутствие сотрудников',
        'description' => 'Просмотр информации о присутствии сотрудников в пределах доступной области.',
        'roles' => ['rop', 'branch_director', 'admin', 'superadmin', 'owner'],
    ],
    'locations.transmit' => [
        'group' => 'Местоположение',
        'name' => 'Передача местоположения',
        'description' => 'Разрешает устройству отправлять координаты сотрудника с учётом его графика и настроек отслеживания.',
        'roles_config' => 'location_tracking.tracked_roles',
    ],
    'locations.view' => [
        'group' => 'Местоположение',
        'name' => 'Просмотр местоположения',
        'description' => 'Открывает модуль местоположения. Список сотрудников и исторические координаты ограничены текущей областью видимости.',
        'roles_config' => 'location_tracking.viewer_roles',
    ],
    'new_buildings.manage' => [
        'group' => 'Новостройки',
        'name' => 'Работа с жилыми комплексами',
        'description' => 'Создание и редактирование своих или назначенных ЖК; для директора — в своём филиале. Учётная запись должна быть активна.',
        'roles' => ['superadmin', 'admin', 'owner', 'branch_director', 'agent', 'mop'],
    ],
    'new_buildings.manage_all' => [
        'group' => 'Новостройки',
        'name' => 'ЖК всех филиалов',
        'description' => 'Расширяет область управления жилыми комплексами до всех филиалов. Требуется право работы с ЖК.',
        'roles' => ['superadmin', 'admin', 'owner'],
        'sensitive' => true,
    ],
    'new_buildings.publish' => [
        'group' => 'Новостройки',
        'name' => 'Публикация жилых комплексов',
        'description' => 'Публикация, архивирование и импорт данных доступного жилого комплекса.',
        'roles' => ['superadmin', 'admin', 'owner', 'branch_director'],
    ],
    'clients.create' => [
        'group' => 'Контакты', 'name' => 'Создание контактов', 'description' => 'Создание контакта в доступной области. Для типа покупатель, продавец или оба типа требуется соответствующее разрешение.', 'roles' => ['admin', 'superadmin', 'rop', 'branch_director', 'marketing', 'mop', 'agent', 'manager', 'operator', 'intern'],
    ],
    'clients.update' => [
        'group' => 'Контакты', 'name' => 'Изменение контактов', 'description' => 'Изменение доступных контактов с сохранением ограничений филиала, группы, владельца и участников.', 'roles' => ['admin', 'superadmin', 'rop', 'branch_director', 'marketing', 'mop', 'agent', 'manager', 'operator', 'intern'],
    ],
    'clients.delete' => [
        'group' => 'Контакты', 'name' => 'Удаление контактов', 'description' => 'Удаление доступных контактов; ограничения связанных данных сохраняются.', 'roles' => ['admin', 'superadmin', 'rop', 'branch_director', 'marketing', 'mop', 'agent', 'manager', 'operator', 'intern'],
    ],
    'clients.buyer.create' => [
        'group' => 'Контакты', 'name' => 'Создание покупателей', 'description' => 'Создание контактов покупателей при наличии общего права создания контактов.', 'roles' => ['admin', 'superadmin', 'rop', 'branch_director', 'marketing', 'mop', 'agent', 'manager', 'operator', 'intern'],
    ],
    'clients.seller.create' => [
        'group' => 'Контакты', 'name' => 'Создание продавцов', 'description' => 'Создание контактов продавцов при наличии общего права создания контактов.', 'roles' => ['admin', 'superadmin', 'rop', 'branch_director', 'marketing', 'mop', 'agent', 'manager', 'operator', 'intern'],
    ],
    'clients.both.create' => [
        'group' => 'Контакты', 'name' => 'Совмещение покупателя и продавца', 'description' => 'Создание контакта, который является одновременно покупателем и продавцом.', 'roles' => ['admin', 'superadmin', 'rop', 'branch_director', 'marketing', 'mop', 'agent', 'manager', 'operator', 'intern'],
    ],
    'clients.collaborators.create' => [
        'group' => 'Контакты', 'name' => 'Добавление участников контакта', 'description' => 'Предоставление сотрудникам совместного доступа к контакту. Проверки владельца и доступной области сохраняются.', 'roles' => ['admin', 'superadmin', 'rop', 'branch_director', 'marketing', 'mop', 'agent', 'manager', 'operator', 'intern'],
    ],
    'clients.collaborators.delete' => [
        'group' => 'Контакты', 'name' => 'Удаление участников контакта', 'description' => 'Удаление совместного доступа участника контакта в пределах прав управления этим контактом.', 'roles' => ['admin', 'superadmin', 'rop', 'branch_director', 'marketing', 'mop', 'agent', 'manager', 'operator', 'intern'],
    ],
    'client_needs.create' => [
        'group' => 'Контакты', 'name' => 'Создание потребностей', 'description' => 'Добавление потребностей доступного клиента.', 'roles' => ['admin', 'superadmin', 'rop', 'branch_director', 'marketing', 'mop', 'agent', 'manager', 'operator', 'intern'],
    ],
    'client_needs.update' => [
        'group' => 'Контакты', 'name' => 'Изменение потребностей', 'description' => 'Изменение потребностей доступного клиента.', 'roles' => ['admin', 'superadmin', 'rop', 'branch_director', 'marketing', 'mop', 'agent', 'manager', 'operator', 'intern'],
    ],
    'client_needs.delete' => [
        'group' => 'Контакты', 'name' => 'Удаление потребностей', 'description' => 'Удаление потребностей доступного клиента.', 'roles' => ['admin', 'superadmin', 'rop', 'branch_director', 'marketing', 'mop', 'agent', 'manager', 'operator', 'intern'],
    ],
    'clients.catalog.view' => [
        'group' => 'Контакты', 'name' => 'Каталог свободных клиентов', 'description' => 'Просмотр доступного каталога клиентов, счётчиков и списка закреплений.', 'roles' => ['admin', 'superadmin', 'intern', 'agent', 'rop', 'mop'],
    ],
    'clients.catalog.claim' => [
        'group' => 'Контакты', 'name' => 'Взять клиента из каталога', 'description' => 'Закрепление свободного клиента за собой. Сохраняются дневной лимит, проверка филиала и защита от одновременного получения.', 'roles' => ['agent', 'intern'],
    ],
    'clients.dictionaries.manage' => [
        'group' => 'Контакты', 'name' => 'Справочники контактов и потребностей', 'description' => 'Изменение типов клиентов, типов и статусов потребностей.', 'roles' => ['admin', 'superadmin', 'marketing'],
    ],
    'audit.api.view' => [
        'group' => 'Администрирование', 'name' => 'Журнал API-запросов', 'description' => 'Просмотр запросов, ошибок и деталей обращений к API. Может содержать служебную информацию.', 'roles' => ['admin', 'superadmin'], 'sensitive' => true,
    ],
    'kpi.plans.manage' => [
        'group' => 'KPI', 'name' => 'Управление планами', 'description' => 'Создание и изменение KPI-планов в разрешённой области. Проверки периода и подчинённости сохраняются.', 'roles' => ['admin', 'superadmin', 'rop', 'branch_director', 'mop'],
    ],
    'kpi.operations.manage' => [
        'group' => 'KPI', 'name' => 'Операции и корректировки', 'description' => 'Управление операциями KPI в доступной области. Закрытые периоды и исторические ограничения проверяются отдельно.', 'roles' => ['admin', 'superadmin', 'rop', 'branch_director'],
    ],
    'kpi.inactive.view' => [
        'group' => 'KPI', 'name' => 'Неактивные сотрудники в KPI', 'description' => 'Разрешает включать неактивных сотрудников в выборки KPI внутри текущей области.', 'roles' => ['admin', 'superadmin', 'rop', 'branch_director', 'owner'],
    ],
    'daily_reports.own.view' => [
        'group' => 'Ежедневные отчёты', 'name' => 'Просмотр своего отчёта', 'description' => 'Чтение личного ежедневного отчёта и доступных периодов.', 'roles' => ['admin', 'superadmin', 'rop', 'branch_director', 'agent', 'mop', 'intern', 'owner'],
    ],
    'daily_reports.own.edit' => [
        'group' => 'Ежедневные отчёты', 'name' => 'Изменение своего отчёта', 'description' => 'Заполнение личного ежедневного отчёта. Дедлайн, блокировки периодов и ограничения полей сохраняются.', 'roles' => ['admin', 'superadmin', 'rop', 'branch_director', 'agent', 'mop', 'intern', 'owner'],
    ],
    'motivation.manage' => [
        'group' => 'Мотивация', 'name' => 'Управление мотивацией', 'description' => 'Настройка наград, достижений и выдачи вознаграждений в доступной области.', 'roles' => ['admin', 'superadmin', 'rop', 'branch_director', 'owner'],
    ],
    'motivation.own.view' => [
        'group' => 'Мотивация', 'name' => 'Личные достижения', 'description' => 'Просмотр личной мотивации и достижений сотрудника.', 'roles' => ['agent', 'mop'],
    ],
    'properties.control_report.view' => [
        'group' => 'Недвижимость', 'name' => 'Отчёт контроля объектов', 'description' => 'Просмотр отчёта службы безопасности по контролю закрытых объектов.', 'roles' => ['admin', 'superadmin', 'security'],
    ],
    'liquidity.view' => [
        'group' => 'Ликвидность', 'name' => 'Оценка ликвидности', 'description' => 'Просмотр оценки и факторов ликвидности доступных объявлений.', 'roles' => ['admin', 'superadmin', 'agent', 'intern', 'mop', 'rop', 'branch_director', 'marketing', 'reels_manager'],
    ],
    'liquidity.promotion.manage' => [
        'group' => 'Ликвидность', 'name' => 'Маркетинговое продвижение', 'description' => 'Изменение маркетинговых параметров продвижения доступных объектов.', 'roles' => ['admin', 'superadmin', 'marketing', 'reels_manager'],
    ],
    'liquidity.priority.manage' => [
        'group' => 'Ликвидность', 'name' => 'Приоритет объектов', 'description' => 'Настройка бизнес-приоритета доступных объектов.', 'roles' => ['admin', 'superadmin', 'rop', 'branch_director', 'mop'],
    ],
    'liquidity.queue.view' => [
        'group' => 'Ликвидность', 'name' => 'Очередь ликвидности', 'description' => 'Просмотр рабочей очереди объектов в доступной области.', 'roles' => ['admin', 'superadmin', 'rop', 'branch_director', 'mop', 'marketing', 'reels_manager'],
    ],
    'liquidity.settings.manage' => [
        'group' => 'Ликвидность', 'name' => 'Настройки расчёта', 'description' => 'Изменение настроек расчёта ликвидности и параметров рынка.', 'roles' => ['admin', 'superadmin'], 'sensitive' => true,
    ],
];
