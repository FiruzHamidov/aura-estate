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
    'leads.scope.all' => [
        'exclusive_group' => 'leads.scope',
        'group' => 'CRM · Лиды', 'name' => 'Лиды всех филиалов', 'description' => 'Просмотр лидов всех филиалов. Для РОП сохраняется обязательная граница закреплённых групп. Действия с лидами выдаются отдельно.', 'roles' => ['superadmin', 'admin', 'owner', 'marketing'], 'sensitive' => true,
    ],
    'leads.scope.branch' => [
        'exclusive_group' => 'leads.scope',
        'group' => 'CRM · Лиды', 'name' => 'Лиды своего филиала', 'description' => 'Просмотр всех лидов своего филиала. РОП видит только лиды закреплённых за ним групп.', 'roles' => ['branch_director', 'rop', 'operator', 'manager'],
    ],
    'leads.scope.own' => [
        'exclusive_group' => 'leads.scope',
        'group' => 'CRM · Лиды', 'name' => 'Свои лиды', 'description' => 'Просмотр созданных вами или назначенных вам лидов внутри вашего филиала. Доступ ко всему филиалу имеет приоритет над этим разрешением.', 'roles' => ['agent', 'mop'],
    ],
    'leads.create' => [
        'group' => 'CRM · Лиды', 'name' => 'Создание лидов', 'description' => 'Создание лида внутри разрешённой области данных.', 'roles' => ['superadmin', 'admin', 'owner', 'marketing', 'branch_director', 'rop', 'operator', 'manager', 'agent', 'mop'],
    ],
    'leads.update' => [
        'group' => 'CRM · Лиды', 'name' => 'Изменение лидов', 'description' => 'Редактирование контактов, статуса и ответственного доступного лида.', 'roles' => ['superadmin', 'admin', 'owner', 'marketing', 'branch_director', 'rop', 'operator', 'manager', 'agent', 'mop'],
    ],
    'leads.delete' => [
        'group' => 'CRM · Лиды', 'name' => 'Удаление лидов', 'description' => 'Удаление доступных лидов с записью действия в журнале.', 'roles' => ['superadmin', 'admin', 'owner', 'marketing', 'branch_director', 'rop', 'operator', 'manager', 'agent', 'mop'], 'sensitive' => true,
    ],
    'leads.convert' => [
        'group' => 'CRM · Лиды', 'name' => 'Преобразование лида', 'description' => 'Преобразование доступного лида в контакт и сделку. Это разрешение включает создание связанных записей в рамках преобразования.', 'roles' => ['superadmin', 'admin', 'owner', 'marketing', 'branch_director', 'rop', 'operator', 'manager', 'agent', 'mop'],
    ],
    'deals.scope.all' => [
        'group' => 'CRM · Сделки', 'name' => 'Все сделки', 'description' => 'Сделки всех филиалов. Действия с данными разрешаются отдельно; обязательные ограничения закреплённых групп сохраняются.', 'roles' => ['admin', 'superadmin', 'marketing'], 'exclusive_group' => 'deals.scope', 'sensitive' => true,
    ],
    'deals.scope.branch' => [
        'group' => 'CRM · Сделки', 'name' => 'Сделки своего филиала', 'description' => 'Все сделки своего филиала; РОП ограничен закреплёнными группами. Действия с данными разрешаются отдельно; обязательные ограничения закреплённых групп сохраняются.', 'roles' => ['branch_director', 'rop', 'manager'], 'exclusive_group' => 'deals.scope',
    ],
    'deals.scope.own' => [
        'group' => 'CRM · Сделки', 'name' => 'Свои сделки', 'description' => 'Созданные вами и назначенные вам сделки своего филиала. Действия с данными разрешаются отдельно; обязательные ограничения закреплённых групп сохраняются.', 'roles' => ['agent', 'operator'], 'exclusive_group' => 'deals.scope',
    ],
    'deals.scope.hr' => [
        'group' => 'CRM · Сделки', 'name' => 'Только найм', 'description' => 'Сделки только в HR-воронке найма. Действия с данными разрешаются отдельно; обязательные ограничения закреплённых групп сохраняются.', 'roles' => ['hr'], 'exclusive_group' => 'deals.scope',
    ],
    'deals.scope.control' => [
        'group' => 'CRM · Сделки', 'name' => 'Только контроль объектов', 'description' => 'Карточки только в активной воронке контроля объектов. Действия с данными разрешаются отдельно; обязательные ограничения закреплённых групп сохраняются.', 'roles' => ['security'], 'exclusive_group' => 'deals.scope',
    ],
    'pipelines.scope.all' => [
        'group' => 'CRM · Воронки', 'name' => 'Все воронки', 'description' => 'Доступ к воронкам всех филиалов. Действия с данными разрешаются отдельно; обязательные ограничения закреплённых групп сохраняются.', 'roles' => ['admin', 'superadmin', 'marketing'], 'exclusive_group' => 'pipelines.scope', 'sensitive' => true,
    ],
    'pipelines.scope.branch' => [
        'group' => 'CRM · Воронки', 'name' => 'Воронки своего филиала', 'description' => 'Воронки своего филиала и общие воронки без филиала. Действия с данными разрешаются отдельно; обязательные ограничения закреплённых групп сохраняются.', 'roles' => ['branch_director', 'rop', 'manager', 'agent', 'operator'], 'exclusive_group' => 'pipelines.scope',
    ],
    'pipelines.scope.hr' => [
        'group' => 'CRM · Воронки', 'name' => 'Воронка найма', 'description' => 'Доступ только к HR-воронке найма. Действия с данными разрешаются отдельно; обязательные ограничения закреплённых групп сохраняются.', 'roles' => ['hr'], 'exclusive_group' => 'pipelines.scope',
    ],
    'pipelines.scope.control' => [
        'group' => 'CRM · Воронки', 'name' => 'Воронка контроля', 'description' => 'Доступ только к активной воронке контроля объектов. Действия с данными разрешаются отдельно; обязательные ограничения закреплённых групп сохраняются.', 'roles' => ['security'], 'exclusive_group' => 'pipelines.scope',
    ],
    'deals.create' => [
        'group' => 'CRM · Сделки', 'name' => 'Создание сделок', 'description' => 'Создание сделки в доступной воронке и области данных.', 'roles' => ['admin', 'superadmin', 'marketing', 'branch_director', 'rop', 'manager', 'agent', 'operator', 'hr'],
    ],
    'deals.update' => [
        'group' => 'CRM · Сделки', 'name' => 'Редактирование сделок', 'description' => 'Изменение доступных сделок. Системные карточки контроля требуют отдельного права редактирования.', 'roles' => ['admin', 'superadmin', 'marketing', 'branch_director', 'rop', 'manager', 'agent', 'operator', 'hr'],
    ],
    'deals.delete' => [
        'group' => 'CRM · Сделки', 'name' => 'Удаление сделок', 'description' => 'Удаление доступных сделок. Системные карточки контроля объектов удалять нельзя.', 'roles' => ['admin', 'superadmin', 'marketing', 'branch_director', 'rop', 'manager', 'agent', 'operator', 'hr'], 'sensitive' => true,
    ],
    'deals.move' => [
        'group' => 'CRM · Сделки', 'name' => 'Перемещение сделок', 'description' => 'Перемещение доступных сделок между этапами. Переходы карточек контроля дополнительно ограничены правилами процесса.', 'roles' => ['admin', 'superadmin', 'marketing', 'branch_director', 'rop', 'manager', 'agent', 'operator', 'hr', 'security'],
    ],
    'deals.activities.create' => [
        'group' => 'CRM · Сделки', 'name' => 'Действия и комментарии по сделке', 'description' => 'Запись звонков, комментариев, меток и дат контакта по доступной сделке. Смена этапа требует права перемещения, а назначение ответственного — редактирования сделки.', 'roles' => ['admin', 'superadmin', 'marketing', 'branch_director', 'rop', 'manager', 'agent', 'operator', 'hr', 'security'],
    ],
    'deals.related_control.view' => [
        'group' => 'CRM · Сделки', 'name' => 'Контроль своих объектов', 'description' => 'Включает в выборку своих сделок карточки контроля по вашим объявлениям.', 'roles' => ['agent', 'intern'],
    ],
    'deals.control.edit' => [
        'group' => 'CRM · Сделки', 'name' => 'Редактирование данных карточки контроля', 'description' => 'Изменение основных данных системной карточки контроля. Требуется общее право редактирования сделок.', 'roles' => ['admin', 'superadmin'], 'sensitive' => true,
    ],
    'deals.control.claim' => [
        'group' => 'CRM · Сделки', 'name' => 'Взять карточку контроля', 'description' => 'Назначить себя ответственным за свободную новую карточку контроля.', 'roles' => ['security'],
    ],
    'deals.control.deadline' => [
        'group' => 'CRM · Сделки', 'name' => 'Срок проверки карточки', 'description' => 'Изменение срока следующего действия по карточке контроля.', 'roles' => ['admin', 'superadmin', 'security'],
    ],
    'deals.control.review' => [
        'group' => 'CRM · Сделки', 'name' => 'Проверка службой безопасности', 'description' => 'Переходы проверки СБ по доступным карточкам контроля с соблюдением обязательных комментариев.', 'roles' => ['admin', 'superadmin', 'security'],
    ],
    'deals.control.correct' => [
        'group' => 'CRM · Сделки', 'name' => 'Исправления филиала', 'description' => 'Переходы уточнения и исправления по карточкам своего филиала.', 'roles' => ['admin', 'superadmin', 'branch_director', 'rop', 'manager', 'agent', 'operator'],
    ],
    'deals.control.manage_all' => [
        'group' => 'CRM · Сделки', 'name' => 'Все переходы контроля', 'description' => 'Позволяет выполнять переходы СБ и филиала для любой доступной карточки контроля.', 'roles' => ['admin', 'superadmin'], 'sensitive' => true,
    ],
    'pipelines.manage' => [
        'group' => 'CRM · Воронки', 'name' => 'Настройка воронок', 'description' => 'Создание и изменение воронок и этапов внутри выбранной области. Для области найма доступно изменение существующей HR-воронки.', 'roles' => ['admin', 'superadmin', 'marketing', 'branch_director', 'hr'],
    ],
    'deals.properties.all' => [
        'group' => 'CRM · Сделки', 'name' => 'Привязка: Объекты всех филиалов', 'description' => 'Определяет, какие объявления можно прикреплять к доступным сделкам. Само изменение сделки требует отдельного разрешения.', 'exclusive_group' => 'deals.properties', 'roles' => ['admin', 'superadmin', 'marketing'],
    ],
    'deals.properties.branch' => [
        'group' => 'CRM · Сделки', 'name' => 'Привязка: Объекты своего филиала', 'description' => 'Определяет, какие объявления можно прикреплять к доступным сделкам. Само изменение сделки требует отдельного разрешения.', 'exclusive_group' => 'deals.properties', 'roles' => ['branch_director', 'rop', 'manager', 'operator'],
    ],
    'deals.properties.group' => [
        'group' => 'CRM · Сделки', 'name' => 'Привязка: Объекты своей группы', 'description' => 'Определяет, какие объявления можно прикреплять к доступным сделкам. Само изменение сделки требует отдельного разрешения.', 'exclusive_group' => 'deals.properties', 'roles' => ['mop'],
    ],
    'deals.properties.own' => [
        'group' => 'CRM · Сделки', 'name' => 'Привязка: Свои объекты', 'description' => 'Определяет, какие объявления можно прикреплять к доступным сделкам. Само изменение сделки требует отдельного разрешения.', 'exclusive_group' => 'deals.properties', 'roles' => ['agent', 'intern'],
    ],
    'clients.scope.all' => [
        'group' => 'CRM · Клиенты', 'name' => 'Клиенты всех филиалов', 'description' => 'Просмотр всех клиентов. Изменение клиентов требует отдельных разрешений. Закреплённые группы РОП остаются обязательным ограничением.', 'roles' => ['admin', 'superadmin', 'marketing'], 'exclusive_group' => 'clients.scope',
    ],
    'clients.scope.branch' => [
        'group' => 'CRM · Клиенты', 'name' => 'Клиенты своего филиала', 'description' => 'Просмотр клиентов своего филиала без ограничений по типу контакта. Для РОП — только закреплённые группы.', 'roles' => ['branch_director', 'rop'], 'exclusive_group' => 'clients.scope',
    ],
    'clients.scope.own' => [
        'group' => 'CRM · Клиенты', 'name' => 'Свои клиенты', 'description' => 'Просмотр клиентов своего филиала, которых сотрудник создал или за которых отвечает. При создании сотрудник назначается автором и ответственным.', 'roles' => ['intern'], 'exclusive_group' => 'clients.scope',
    ],
    'clients.scope.agent_policy' => [
        'group' => 'CRM · Клиенты', 'name' => 'Клиенты по настройкам агентов', 'description' => 'Применяет настройки видимости клиентов и продавцов, а также режим видимости контактов группы. Сохраняет действующие правила агента, менеджера и оператора.', 'roles' => ['agent', 'manager', 'operator'], 'exclusive_group' => 'clients.scope',
    ],
    'clients.scope.group_policy' => [
        'group' => 'CRM · Клиенты', 'name' => 'Клиенты по настройкам группы', 'description' => 'Применяет режим видимости контактов группы. Без собственной группы доступ закрыт; ограничения видимости продавцов для агентов не применяются.', 'roles' => ['mop'], 'exclusive_group' => 'clients.scope',
    ],
    'clients.shared.view' => [
        'group' => 'CRM · Клиенты', 'name' => 'Клиенты совместной работы', 'description' => 'Дополнительно показывает клиентов, к которым сотрудник добавлен как участник совместной работы. Ограничения закреплённых групп РОП сохраняются.', 'roles' => ['agent', 'manager', 'operator', 'mop', 'hr', 'security', 'reels_manager', 'owner'],
    ],
    'clients.collaborators.manage_all' => [
        'group' => 'CRM · Клиенты', 'name' => 'Участники доступных клиентов', 'description' => 'Разрешает управлять участниками любого доступного клиента. Без этого права — только у клиентов, за которых сотрудник отвечает. Добавление и удаление требуют отдельных разрешений.', 'roles' => ['admin', 'superadmin', 'marketing', 'branch_director', 'rop'],
    ],
    'clients.settings.manage' => [
        'group' => 'CRM · Клиенты', 'name' => 'Настройки видимости клиентов', 'description' => 'Изменение общих настроек видимости клиентов и продавцов для ролей с областью «Клиенты по настройкам агентов».', 'roles' => ['admin', 'superadmin', 'marketing'],
    ],
    'clients.properties.all' => [
        'group' => 'CRM · Клиенты', 'name' => 'Связь клиентов с любыми объектами', 'description' => 'Разрешает прикреплять существующего клиента к объектам всех филиалов.', 'roles' => ['admin', 'superadmin', 'marketing'], 'exclusive_group' => 'clients.properties',
    ],
    'clients.properties.branch' => [
        'group' => 'CRM · Клиенты', 'name' => 'Связь клиентов с объектами филиала', 'description' => 'Разрешает прикреплять клиента к объектам своего филиала. Для РОП сохраняется ограничение закреплённых групп.', 'roles' => ['branch_director', 'rop', 'manager', 'operator'], 'exclusive_group' => 'clients.properties',
    ],
    'clients.properties.own' => [
        'group' => 'CRM · Клиенты', 'name' => 'Связь клиентов со своими объектами', 'description' => 'Разрешает прикреплять клиента к своим объектам в своём филиале.', 'roles' => ['agent', 'mop', 'intern'], 'exclusive_group' => 'clients.properties',
    ],
    'clients.attach' => [
        'group' => 'CRM · Клиенты', 'name' => 'Использование существующего клиента',
        'description' => 'Прикрепление существующего контакта к доступному контексту CRM и включение в совместную работу. Сохраняются проверки филиала, группы и доступа к связанному объекту.',
        'roles' => ['admin', 'superadmin', 'marketing', 'hr', 'security', 'branch_director', 'rop', 'mop', 'agent', 'intern', 'manager', 'operator', 'reels_manager', 'owner'],
    ],
    'clients.duplicates.search' => [
        'group' => 'CRM · Клиенты', 'name' => 'Поиск дубликатов клиентов',
        'description' => 'Проверка совпадений по телефону, имени и почте. Недоступные контакты показываются с маскировкой личных данных.',
        'roles' => ['admin', 'superadmin', 'marketing', 'hr', 'security', 'branch_director', 'rop', 'mop', 'agent', 'intern', 'manager', 'operator', 'reels_manager', 'owner'],
    ],
];
