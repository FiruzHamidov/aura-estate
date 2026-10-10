<?php

// Defaults are used for migration and for compatibility with an older database.
// Runtime changes live on roles; editing this file must never reset saved grants.
$permissions = [
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
    'catalogs.clients.view' => [
        'group' => 'Справочники', 'name' => 'Просмотр клиентских справочников на сайте',
        'description' => 'Открывает типы клиентов, типы и статусы потребностей и источники клиентов. Изменение требует прав управления справочниками и справочниками контактов.',
        'roles' => ['admin', 'superadmin', 'marketing'],
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
        'description' => 'Создание и редактирование ЖК в выбранной области доступа. Дополнительно выберите свои ЖК, свой филиал или все филиалы. Учётная запись должна быть активна.',
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
    'bookings.scope.all' => [
        'group' => 'Показы и бронирования', 'name' => 'Все показы', 'description' => 'Просмотр показов всех филиалов. Для РОП сохраняется ограничение закреплённых групп.', 'roles' => ['admin', 'superadmin', 'marketing', 'hr', 'accountant', 'security', 'manager', 'operator', 'reels_manager', 'intern', 'external_agent', 'owner'], 'exclusive_group' => 'bookings.scope',
    ],
    'bookings.scope.agent' => [
        'group' => 'Показы и бронирования', 'name' => 'Назначенные показы', 'description' => 'Просмотр показов, в которых сотрудник указан агентом.', 'roles' => ['agent'], 'exclusive_group' => 'bookings.scope',
    ],
    'bookings.scope.client' => [
        'group' => 'Показы и бронирования', 'name' => 'Показы клиента', 'description' => 'Просмотр показов, привязанных к аккаунту клиента.', 'roles' => ['client'], 'exclusive_group' => 'bookings.scope',
    ],
    'bookings.scope.branch' => [
        'group' => 'Показы и бронирования', 'name' => 'Показы своего филиала', 'description' => 'Просмотр показов агентов своего филиала. Для РОП выборка строится по закреплённым группам записей.', 'roles' => ['rop', 'branch_director'], 'exclusive_group' => 'bookings.scope',
    ],
    'bookings.scope.group' => [
        'group' => 'Показы и бронирования', 'name' => 'Показы своей группы', 'description' => 'Просмотр показов агентов своей группы. Без назначенной группы доступ закрыт.', 'roles' => ['mop'], 'exclusive_group' => 'bookings.scope',
    ],
    'bookings.create' => [
        'group' => 'Показы и бронирования', 'name' => 'Создание показов', 'description' => 'Создание показа с действующим агентом, объектом и клиентом. Сохраняются проверки времени, связанных записей и групп.', 'roles' => ['admin', 'superadmin', 'marketing', 'hr', 'security', 'manager', 'operator', 'reels_manager', 'intern', 'rop', 'branch_director', 'mop', 'agent', 'owner'],
    ],
    'bookings.update.own' => [
        'group' => 'Показы и бронирования', 'name' => 'Изменение назначенных показов', 'description' => 'Изменение времени, заметки и клиента показа, в котором сотрудник указан агентом.', 'roles' => ['admin', 'superadmin', 'marketing', 'hr', 'security', 'manager', 'operator', 'reels_manager', 'intern', 'rop', 'branch_director', 'mop', 'agent', 'owner'],
    ],
    'bookings.update.all' => [
        'group' => 'Показы и бронирования', 'name' => 'Изменение любых показов', 'description' => 'Изменение времени, заметки и клиента любого показа. Для РОП сохраняются закреплённые группы.', 'roles' => ['admin', 'superadmin', 'marketing'],
    ],
    'bookings.agent.assign' => [
        'group' => 'Показы и бронирования', 'name' => 'Смена агента показа', 'description' => 'Назначение другого действующего агента при редактировании показа. Требуется право изменения самого показа.', 'roles' => ['admin', 'superadmin', 'marketing'],
    ],
    'bookings.reports.view' => [
        'group' => 'Показы и бронирования', 'name' => 'Отчёт по показам', 'description' => 'Просмотр отчёта по агентам в пределах области видимости показов.', 'roles' => ['admin', 'superadmin', 'hr', 'security', 'manager', 'operator', 'reels_manager', 'intern', 'rop', 'branch_director', 'mop', 'agent', 'owner'],
    ],
    'users.scope.all' => [
        'group' => 'Сотрудники', 'name' => 'Пользователи всех филиалов', 'description' => 'Просмотр пользователей всех филиалов. Изменение учётных записей требует отдельных прав.', 'roles' => ['admin', 'superadmin', 'marketing', 'hr'], 'exclusive_group' => 'users.scope', 'sensitive' => true,
    ],
    'users.scope.branch' => [
        'group' => 'Сотрудники', 'name' => 'Пользователи своего филиала', 'description' => 'Просмотр пользователей своего филиала. Для РОП дополнительно действуют закреплённые группы и допустимые роли сотрудников.', 'roles' => ['branch_director', 'rop', 'mop', 'agent'], 'exclusive_group' => 'users.scope',
    ],
    'users.clients.view' => [
        'group' => 'Сотрудники', 'name' => 'Просмотр клиентских аккаунтов', 'description' => 'Дополнительно показывает клиентские аккаунты без привязки к филиалу. Для РОП сохраняются групповые ограничения.', 'roles' => ['branch_director'],
    ],
    'users.unassigned.view' => [
        'group' => 'Сотрудники', 'name' => 'Сотрудники без филиала', 'description' => 'Разрешает включать сотрудников без назначенного филиала в списки своего филиала.', 'roles' => ['branch_director'],
    ],
    'users.create' => [
        'group' => 'Сотрудники', 'name' => 'Создание сотрудников', 'description' => 'Создание учётных записей в пределах правил назначения ролей и филиала.', 'roles' => ['admin', 'superadmin', 'marketing', 'hr', 'branch_director', 'rop'], 'sensitive' => true,
    ],
    'users.update' => [
        'group' => 'Сотрудники', 'name' => 'Редактирование сотрудников', 'description' => 'Действие с доступной учётной записью в пределах политики управления ролями. Редактирование включает контактные данные и сброс пароля; увольнение сохраняет обязательное перераспределение записей.', 'roles' => ['admin', 'superadmin', 'marketing', 'hr', 'branch_director', 'rop', 'mop', 'agent'], 'sensitive' => true,
    ],
    'users.dismiss' => [
        'group' => 'Сотрудники', 'name' => 'Увольнение сотрудников', 'description' => 'Действие с доступной учётной записью в пределах политики управления ролями. Редактирование включает контактные данные и сброс пароля; увольнение сохраняет обязательное перераспределение записей.', 'roles' => ['admin', 'superadmin', 'marketing', 'hr', 'branch_director', 'rop', 'mop', 'agent'], 'sensitive' => true,
    ],
    'users.restore' => [
        'group' => 'Сотрудники', 'name' => 'Восстановление сотрудников', 'description' => 'Восстановление уволенных сотрудников в пределах области и политики управления.', 'roles' => ['admin', 'superadmin', 'hr', 'branch_director'], 'sensitive' => true,
    ],
    'users.manage_all_roles' => [
        'group' => 'Сотрудники', 'name' => 'Управление аккаунтами любых ролей', 'description' => 'Разрешает действия с аккаунтами любых ролей, включая администраторов, в доступной области. Требуются отдельные права изменения или увольнения. Это чувствительное право позволяет влиять на доступ других пользователей.', 'roles' => ['admin', 'superadmin', 'marketing', 'mop', 'agent'], 'exclusive_group' => 'users.management_policy', 'sensitive' => true,
    ],
    'users.manage_hr_roles' => [
        'group' => 'Сотрудники', 'name' => 'Кадровая политика управления', 'description' => 'Сохраняет кадровые ограничения: редактирование сотрудников до директора филиала, увольнение и восстановление только сотруднических ролей, ограничения создания и превращения сотрудника в клиента.', 'roles' => ['hr'], 'exclusive_group' => 'users.management_policy',
    ],
    'users.manage_branch_roles' => [
        'group' => 'Сотрудники', 'name' => 'Политика руководителя филиала', 'description' => 'Управление допустимыми ролями до уровня РОП включительно. Для РОП дополнительно действуют закреплённые группы, запрет управления собой и отдельные процедуры переноса и увольнения.', 'roles' => ['branch_director', 'rop'], 'exclusive_group' => 'users.management_policy',
    ],
    'users.security_attendance.assign' => [
        'group' => 'Сотрудники', 'name' => 'Назначение филиалов посещаемости СБ', 'description' => 'Назначение филиалов, посещаемость которых видит сотрудник службы безопасности. Не изменяет его основную роль и филиал.', 'roles' => ['admin', 'superadmin'], 'sensitive' => true,
    ],
    'users.report_agents.view_all' => [
        'group' => 'Сотрудники', 'name' => 'Список агентов для отчёта по объектам', 'description' => 'Просмотр краткого списка агентов для фильтров отчёта по объектам без общего доступа к карточкам сотрудников.', 'roles' => ['accountant'],
    ],
];


$permissions['users.directory.view'] = [
    'group' => 'Сотрудники', 'name' => 'Раздел управления сотрудниками',
    'description' => 'Открывает раздел сотрудников на сайте. Состав списка определяется областью видимости; изменение аккаунтов требует отдельных прав. Краткие списки сотрудников в других формах сохраняют свои правила.',
    'roles' => ['admin', 'superadmin', 'marketing', 'hr', 'branch_director', 'rop'],
];

$permissions['users.record_counts.view'] = ['group' => 'Перенос записей', 'name' => 'Счётчики записей сотрудников', 'description' => 'Показывает количество объектов, клиентов, сделок и показов на сотруднике.', 'roles' => ['admin', 'superadmin', 'hr', 'marketing', 'branch_director', 'rop']];
$permissions['users.record_counts.all_branches'] = ['group' => 'Перенос записей', 'name' => 'Счётчики записей всех филиалов', 'description' => 'Считает записи сотрудников по всем филиалам. Без права — только свой филиал; закреплённые группы РОП остаются обязательными.', 'roles' => ['admin', 'superadmin', 'hr', 'marketing']];
$permissions['users.dismissal.all_branches'] = ['group' => 'Перенос записей', 'name' => 'Перераспределение при увольнении между филиалами', 'description' => 'Разрешает обрабатывать записи и получателей разных филиалов при увольнении. Для смены группы требуются дополнительные права переноса записей.', 'roles' => ['admin', 'superadmin', 'hr', 'marketing', 'mop', 'agent']];
$permissions['records.transfer'] = ['group' => 'Перенос записей', 'name' => 'Перенос записей между группами', 'description' => 'Перенос доступной записи к активному ответственному в другую группу с проверкой версии и обязательной причиной. Действуют дополнительные права соответствующего раздела и ограничения связанных записей.', 'roles' => ['admin', 'superadmin', 'branch_director', 'rop']];
$permissions['records.transfer.all_branches'] = ['group' => 'Перенос записей', 'name' => 'Перенос записей между филиалами', 'description' => 'Расширяет разрешённые исходные и целевые группы переноса на все филиалы. Для РОП закреплённые группы остаются обязательными.', 'roles' => ['admin', 'superadmin']];
$permissions['users.dismissal.change_group'] = ['group' => 'Перенос записей', 'name' => 'Смена группы записей при увольнении', 'description' => 'Разрешает включать смену группы в план увольнения. Для РОП перенос объектов сохраняет отдельные ограничения; связанные задачи следуют за родительской записью.', 'roles' => ['admin', 'superadmin', 'branch_director', 'rop']];

$permissions['users.group_transfer'] = ['group' => 'Перенос записей', 'name' => 'Перевод сотрудника в другую группу', 'description' => 'Перевод агента, стажёра или МОП с явным планом обработки каждой активной записи. Перенос записей требует соответствующих разрешений. Групповые ограничения РОП сохраняются.', 'roles' => ['admin', 'superadmin', 'branch_director', 'rop']];
$permissions['users.group_transfer.all_branches'] = ['group' => 'Перенос записей', 'name' => 'Перевод сотрудника между филиалами', 'description' => 'Разрешает выбирать исходного сотрудника и целевую группу в других филиалах. Для переноса записей между филиалами требуется отдельное право.', 'roles' => ['admin', 'superadmin'], 'sensitive' => true];

$taskRoles = [...array_column((require __DIR__.'/roles.php')['system'], 'slug'), 'owner'];
$taskOwnRoles = array_values(array_diff($taskRoles, ['admin', 'superadmin', 'rop', 'branch_director', 'mop']));
$taskGlobalAssignees = array_values(array_diff($taskRoles, ['rop', 'branch_director']));
$permissions['tasks.scope.all'] = ['group' => 'CRM · Задачи', 'name' => 'Все задачи', 'description' => 'Просмотр задач всех сотрудников. Для РОП сохраняются закреплённые группы.', 'roles' => ['admin', 'superadmin'], 'exclusive_group' => 'tasks.scope'];
$permissions['tasks.scope.branch'] = ['group' => 'CRM · Задачи', 'name' => 'Задачи своего филиала', 'description' => 'Просмотр задач исполнителей своего филиала. Для РОП — задачи закреплённых групп.', 'roles' => ['branch_director', 'rop'], 'exclusive_group' => 'tasks.scope'];
$permissions['tasks.scope.group'] = ['group' => 'CRM · Задачи', 'name' => 'Задачи своей группы', 'description' => 'Просмотр задач исполнителей своей группы.', 'roles' => ['mop'], 'exclusive_group' => 'tasks.scope'];
$permissions['tasks.scope.own'] = ['group' => 'CRM · Задачи', 'name' => 'Назначенные задачи', 'description' => 'Просмотр задач, в которых сотрудник указан исполнителем.', 'roles' => $taskOwnRoles, 'exclusive_group' => 'tasks.scope'];
$permissions['tasks.create'] = ['group' => 'CRM · Задачи', 'name' => 'Создание задач', 'description' => 'Создание задач с проверкой типа, результата звонка, исполнителя и связанных записей.', 'roles' => $taskRoles];
$permissions['tasks.update'] = ['group' => 'CRM · Задачи', 'name' => 'Изменение и выполнение задач', 'description' => 'Изменение доступных задач, завершение и возврат в работу. Связанные задачи сохраняют группу родительской записи.', 'roles' => $taskRoles];
$permissions['tasks.assign.all'] = ['group' => 'CRM · Задачи', 'name' => 'Исполнители задач всех филиалов', 'description' => 'Разрешает назначать создаваемую задачу исполнителю из любого филиала. Групповые проверки РОП и связи с родительской записью сохраняются.', 'roles' => $taskGlobalAssignees, 'exclusive_group' => 'tasks.assign'];
$permissions['tasks.assign.branch'] = ['group' => 'CRM · Задачи', 'name' => 'Исполнители задач своего филиала', 'description' => 'Разрешает назначать создаваемые задачи исполнителям своего филиала. Для РОП сохраняются закреплённые группы.', 'roles' => ['branch_director', 'rop'], 'exclusive_group' => 'tasks.assign'];
$permissions['tasks.module.view'] = ['group' => 'CRM · Задачи', 'name' => 'Раздел задач на сайте', 'description' => 'Открывает раздел задач CRM на сайте. Нужна также область видимости задач; действия разрешаются отдельно.', 'roles' => ['admin', 'superadmin', 'marketing', 'agent', 'mop', 'manager', 'operator', 'rop', 'branch_director']];

$permissions['crm_reports.scope.all'] = ['group' => 'CRM · Отчёты', 'name' => 'Отчёты всех филиалов', 'description' => 'Показатели операторов и менеджеров всех филиалов с выбором сотрудника и периода. Недоступно РОП: отчёт не ограничивается закреплёнными группами.', 'roles' => ['admin', 'superadmin'], 'exclusive_group' => 'crm_reports.scope'];
$permissions['crm_reports.scope.branch'] = ['group' => 'CRM · Отчёты', 'name' => 'Отчёты своего филиала', 'description' => 'Показатели операторов и менеджеров своего филиала. Требуется назначенный филиал; ограничение РОП сохраняется.', 'roles' => ['branch_director'], 'exclusive_group' => 'crm_reports.scope'];
$permissions['crm_reports.scope.own_operator'] = ['group' => 'CRM · Отчёты', 'name' => 'Личные показатели оператора', 'description' => 'Только собственные показатели обработки заявок. Строка отчёта формируется для сотрудника с ролью оператора.', 'roles' => ['operator'], 'exclusive_group' => 'crm_reports.scope'];
$permissions['crm_reports.scope.own_manager'] = ['group' => 'CRM · Отчёты', 'name' => 'Личные показатели менеджера', 'description' => 'Только собственные показатели контроля объектов. Строка отчёта формируется для сотрудника с ролью менеджера.', 'roles' => ['manager'], 'exclusive_group' => 'crm_reports.scope'];

$catalogWriters = array_values(array_diff([...array_column((require __DIR__.'/roles.php')['system'], 'slug'), 'owner'], ['client', 'external_agent', 'accountant']));
foreach (require __DIR__.'/catalog_permissions.php' as $catalog => $definition) {
    $permissions['catalogs.entries.'.$catalog.'.manage'] = [
        'group' => 'Изменение справочников', 'name' => $definition['name'],
        'description' => 'Создание, изменение и удаление записей справочника «'.$definition['name'].'». Доступ к странице управления и объединение используемых записей требуют отдельных прав управления справочниками.',
        'roles' => $catalogWriters,
    ];
}

$permissions['branch_groups.scope.all'] = ['group' => 'Группы сотрудников', 'name' => 'Группы всех филиалов', 'description' => 'Просмотр групп, настроек видимости контактов и количества связанных записей всех филиалов. Для РОП сохраняются закреплённые группы.', 'roles' => ['admin', 'superadmin', 'marketing'], 'exclusive_group' => 'branch_groups.scope'];
$permissions['branch_groups.scope.branch'] = ['group' => 'Группы сотрудников', 'name' => 'Группы своего филиала', 'description' => 'Просмотр групп и связанных счётчиков своего филиала. РОП видит только закреплённые группы.', 'roles' => ['branch_director', 'rop', 'agent', 'manager', 'operator'], 'exclusive_group' => 'branch_groups.scope'];
$permissions['branch_groups.labels.list'] = ['group' => 'Группы сотрудников', 'name' => 'Названия групп для выбора', 'description' => 'Список названий и филиалов групп для табеля и назначения сотрудников, без CRM-счётчиков и настроек. Полная область просмотра имеет приоритет.', 'roles' => ['hr', 'accountant']];
$permissions['branch_groups.labels.show'] = ['group' => 'Группы сотрудников', 'name' => 'Название отдельной группы', 'description' => 'Получение названия и филиала выбранной группы без настроек и связанных данных.', 'roles' => ['hr']];
$permissions['branch_groups.manage.all'] = ['group' => 'Группы сотрудников', 'name' => 'Изменение групп всех филиалов', 'description' => 'Создание групп, изменение настроек и удаление пустых групп во всех филиалах. Для существующих групп требуется область просмотра. РОП не меняет структуру групп.', 'roles' => ['admin', 'superadmin', 'marketing'], 'exclusive_group' => 'branch_groups.manage'];
$permissions['branch_groups.manage.branch'] = ['group' => 'Группы сотрудников', 'name' => 'Изменение групп своего филиала', 'description' => 'Создание, изменение и удаление пустых групп своего филиала. Для существующих групп требуется область просмотра. Защита связанных записей сохраняется; РОП не меняет структуру групп.', 'roles' => ['branch_director'], 'exclusive_group' => 'branch_groups.manage'];
$permissions['branch_groups.module.view'] = ['group' => 'Группы сотрудников', 'name' => 'Раздел управления группами на сайте', 'description' => 'Открывает страницу групп. Область просмотра и изменение структуры назначаются отдельно.', 'roles' => ['admin', 'superadmin', 'marketing', 'rop', 'branch_director']];

$permissions['catalogs.entries.branches.manage']['roles'] = array_values(array_diff($catalogWriters, ['rop']));
$permissions['catalogs.entries.branches.manage']['description'] = 'Создание филиалов, изменение названия, координат и фотографии, удаление пустых филиалов. Связанные сотрудники и групповые данные защищены от удаления. РОП не меняет структуру филиалов.';
$permissions['branches.module.view'] = ['group' => 'Структура компании', 'name' => 'Раздел филиалов на сайте', 'description' => 'Открывает страницу управления филиалами. Для изменения данных нужно отдельное право изменения справочника филиалов. Публичный список филиалов доступен без этого права.', 'roles' => ['admin', 'superadmin', 'marketing']];

$permissions['rop_groups.scope.all'] = ['group' => 'Доступ РОП к группам', 'name' => 'Назначения РОП всех филиалов', 'description' => 'Просмотр закреплённых групп РОП всех филиалов. Для изменения нужно отдельное право.', 'roles' => ['admin', 'superadmin'], 'exclusive_group' => 'rop_groups.scope'];
$permissions['rop_groups.scope.branch'] = ['group' => 'Доступ РОП к группам', 'name' => 'Назначения РОП своего филиала', 'description' => 'Просмотр закреплённых групп РОП своего филиала. Для изменения нужно отдельное право.', 'roles' => ['branch_director'], 'exclusive_group' => 'rop_groups.scope'];
$permissions['rop_groups.scope.self'] = ['group' => 'Доступ РОП к группам', 'name' => 'Свои назначения РОП', 'description' => 'Просмотр собственных закреплённых групп для сотрудника с ролью РОП. Не позволяет самостоятельно расширять доступ.', 'roles' => ['rop'], 'exclusive_group' => 'rop_groups.scope'];
$permissions['rop_groups.manage'] = ['group' => 'Доступ РОП к группам', 'name' => 'Изменение назначений РОП', 'description' => 'Выдача и отзыв доступа РОП к группам в разрешённой области. Группы должны относиться к филиалу РОП. Изменения версионируются и записываются в журнал. РОП не может изменять назначения.', 'roles' => ['admin', 'superadmin', 'branch_director'], 'sensitive' => true];
$permissions['group_access_review.scope.all'] = ['group' => 'Группы сотрудников', 'name' => 'Разбор групповой принадлежности всех филиалов', 'description' => 'Просмотр очереди записей с неопределённой или противоречивой группой во всех филиалах. Не даёт доступа к содержимому исходных карточек. Недоступно РОП: группа записей ещё не установлена.', 'roles' => ['admin', 'superadmin'], 'exclusive_group' => 'group_access_review.scope'];
$permissions['group_access_review.scope.branch'] = ['group' => 'Группы сотрудников', 'name' => 'Разбор групповой принадлежности своего филиала', 'description' => 'Просмотр очереди проблем групповой принадлежности только своего филиала. Требуется назначенный филиал; исходные карточки проверяются отдельно. Недоступно РОП.', 'roles' => ['branch_director'], 'exclusive_group' => 'group_access_review.scope'];

$permissions['payment_programs.global.manage'] = ['group' => 'Условия покупки', 'name' => 'Общие ипотечные программы', 'description' => 'Просмотр и изменение общих ипотечных программ, не привязанных к конкретному ЖК. Требуется активная учётная запись; публикация разрешается отдельно.', 'roles' => ['admin', 'superadmin', 'owner']];
$permissions['payment_programs.building.manage'] = ['group' => 'Условия покупки', 'name' => 'Программы покупки доступных ЖК', 'description' => 'Просмотр и изменение условий покупки в доступном жилом комплексе. Дополнительно требуется право управления этим ЖК; доступ к чужим ЖК не расширяется.', 'roles' => ['admin', 'superadmin', 'owner', 'branch_director', 'agent', 'mop']];
$permissions['payment_programs.publish'] = ['group' => 'Условия покупки', 'name' => 'Подтверждение и публикация программ', 'description' => 'Публикация, отклонение и архивирование доступных программ покупки. Подтверждающие данные, срок действия и проверка версии обязательны. Без этого права изменения опубликованных условий отправляются на модерацию.', 'roles' => ['admin', 'superadmin', 'owner', 'branch_director'], 'sensitive' => true];

$permissions['new_buildings.manage_all']['exclusive_group'] = 'new_buildings.scope';
$permissions['new_buildings.scope.branch'] = ['group' => 'Новостройки', 'name' => 'ЖК своего филиала', 'description' => 'Область управления ЖК своего филиала. Требуется право работы с ЖК и назначенный филиал.', 'roles' => ['branch_director'], 'exclusive_group' => 'new_buildings.scope'];
$permissions['new_buildings.scope.own'] = ['group' => 'Новостройки', 'name' => 'Свои и назначенные ЖК', 'description' => 'Область управления ЖК, где сотрудник является автором или ответственным. При создании и смене филиала сохраняется ограничение своим филиалом.', 'roles' => ['agent', 'mop'], 'exclusive_group' => 'new_buildings.scope'];
$permissions['new_buildings.assign'] = ['group' => 'Новостройки', 'name' => 'Назначение консультанта ЖК', 'description' => 'Выбор другого активного сотрудника с правом работы с ЖК в филиале комплекса. Для изменения самого ЖК нужны права управления; публикация разрешается отдельно.', 'roles' => ['admin', 'superadmin', 'owner', 'branch_director']];

$reelRoles = ['admin', 'superadmin', 'marketing', 'reels_manager', 'rop', 'branch_director'];
$permissions['reels.scope.all'] = ['group' => 'Ролики', 'name' => 'Ролики всех филиалов', 'description' => 'Область управления роликами всех филиалов. Для РОП сохраняются закреплённые группы.', 'roles' => ['admin', 'superadmin', 'marketing', 'reels_manager'], 'exclusive_group' => 'reels.scope'];
$permissions['reels.scope.branch'] = ['group' => 'Ролики', 'name' => 'Ролики своего филиала', 'description' => 'Ролики объектов сотрудников своего филиала и отдельные ролики авторов своего филиала. Для РОП — только закреплённые группы.', 'roles' => ['rop', 'branch_director'], 'exclusive_group' => 'reels.scope'];
foreach (['view' => ['Просмотр и раздел управления', 'Просмотр неопубликованных роликов и доступ к управлению в выбранной области. Публичная лента доступна отдельно.'], 'create' => ['Загрузка роликов', 'Создание роликов и завершение прямой загрузки. Нужны просмотр и область управления.'], 'update' => ['Редактирование роликов', 'Изменение видео, обложки, текста и привязки к объекту в доступной области. Замена видео запускает обработку заново.'], 'publish' => ['Публикация и архивирование', 'Публикация готового ролика, возврат в черновик и архивирование в доступной области.'], 'delete' => ['Удаление роликов', 'Удаление доступного ролика и его медиафайлов.']] as $action => [$name, $description]) {
    $permissions['reels.'.$action] = ['group' => 'Ролики', 'name' => $name, 'description' => $description, 'roles' => $reelRoles];
}

$storyRoles = [...array_column((require __DIR__.'/roles.php')['system'], 'slug'), 'owner'];
$storyWriters = array_values(array_diff($storyRoles, ['client']));
$permissions['stories.own.view'] = ['group' => 'Истории', 'name' => 'Просмотр своих историй', 'description' => 'Список своих историй, включая черновики, архив и удалённые записи. Публичная лента доступна отдельно. Для РОП внутренний раздел историй закрыт до появления надёжной групповой принадлежности.', 'roles' => $storyRoles];
foreach (['create' => ['Создание историй', 'Создание своих историй из медиа, объекта или опубликованного ролика. Для немедленной публикации нужно право изменения статуса своих историй.'], 'update' => ['Редактирование своих историй', 'Изменение текста, времени начала и медиа своих историй.'], 'delete' => ['Удаление своих историй', 'Перевод своих историй в удалённые с сохранением записи в истории.'], 'status' => ['Публикация и скрытие своих историй', 'Публикация, повторная публикация, архивирование и скрытие своих историй. Лимиты активных историй сохраняются.']] as $action => [$name, $description]) {
    $permissions['stories.'.$action] = ['group' => 'Истории', 'name' => $name, 'description' => $description.' Для РОП внутренние операции с историями закрыты.', 'roles' => $storyWriters];
}
$permissions['stories.manage_all'] = ['group' => 'Истории', 'name' => 'Редактирование и удаление чужих историй', 'description' => 'Расширяет права редактирования и удаления на истории любых авторов. Для каждой операции требуется соответствующее право. Не открывает список модерации; для РОП внутренние операции закрыты.', 'roles' => ['admin', 'superadmin'], 'sensitive' => true];
foreach (['all' => ['Все истории для модерации', 'Истории авторов всех филиалов.', ['admin', 'superadmin']], 'branch' => ['Модерация своего филиала', 'Истории авторов своего филиала. Требуется назначенный филиал.', ['rop', 'branch_director']], 'group' => ['Модерация своей группы', 'Истории авторов своей группы. Требуется назначенная группа.', ['mop']]] as $scope => [$name, $description, $roles]) {
    $permissions['stories.moderation.scope.'.$scope] = ['group' => 'Истории', 'name' => $name, 'description' => $description.' Просмотр и изменение статуса разрешаются отдельно. Для РОП внутренний раздел историй закрыт до появления надёжной групповой принадлежности.', 'roles' => $roles, 'exclusive_group' => 'stories.moderation.scope'];
}
$permissions['stories.moderation.view'] = ['group' => 'Истории', 'name' => 'Просмотр очереди модерации', 'description' => 'Список историй в выбранной области модерации, включая черновики и скрытые записи. Для РОП раздел закрыт.', 'roles' => ['admin', 'superadmin', 'mop', 'rop', 'branch_director']];
$permissions['stories.moderation.status'] = ['group' => 'Истории', 'name' => 'Изменение статуса чужих историй', 'description' => 'Публикация, повторная публикация, скрытие и архивирование историй в области модерации. Лимиты активных историй сохраняются. Для РОП операция закрыта.', 'roles' => ['admin', 'superadmin', 'mop', 'rop', 'branch_director']];

$groupChatRoles = ['admin', 'superadmin', 'marketing', 'rop', 'branch_director', 'manager', 'operator', 'agent', 'reels_manager'];
$permissions['messaging.groups.join'] = ['group' => 'Групповые чаты', 'name' => 'Приглашение в групповые чаты', 'description' => 'Пользователя с этим правом можно включать в новые групповые чаты и приглашать в существующие. Существующее участие и история переписки сохраняются при отзыве. Для создания группы нужно отдельное право.', 'roles' => $groupChatRoles];
$permissions['messaging.groups.create'] = ['group' => 'Групповые чаты', 'name' => 'Создание групповых чатов', 'description' => 'Создание группы с выбранными участниками. Создателю и приглашённым нужно право приглашения в групповые чаты. Не открывает чужую переписку.', 'roles' => $groupChatRoles];
$permissions['messaging.groups.manage_own'] = ['group' => 'Групповые чаты', 'name' => 'Управление участниками своей группы', 'description' => 'Добавление и удаление участников, если пользователь является владельцем или администратором конкретного чата. Ограничения филиала при добавлении сохраняются; владельца чата удалить нельзя.', 'roles' => [...array_column((require __DIR__.'/roles.php')['system'], 'slug'), 'owner']];
$permissions['messaging.groups.manage_all'] = ['group' => 'Групповые чаты', 'name' => 'Управление участниками любых групп', 'description' => 'Добавление и удаление участников групповых чатов без роли администратора конкретного чата. Ограничения филиала при добавлении сохраняются; владельца удалить нельзя. Само по себе не даёт чтения переписки.', 'roles' => ['admin', 'superadmin', 'manager', 'operator'], 'sensitive' => true];

$assignments = [
    'marketing' => ['marketing', 'branch_director', 'rop', 'mop', 'agent', 'external_agent', 'manager', 'operator', 'intern', 'client'],
    'hr' => ['intern', 'agent', 'mop', 'manager', 'operator', 'reels_manager', 'rop', 'client'],
    'rop' => ['mop', 'agent', 'external_agent', 'manager', 'operator', 'intern', 'client'],
    'branch_director' => ['mop', 'agent', 'external_agent', 'manager', 'operator', 'intern', 'client'],
];
$permissions['users.assign_any_role'] = [
    'group' => 'Назначение ролей', 'name' => 'Назначение любой роли',
    'description' => 'Разрешает назначать любые существующие и будущие роли, включая административные. Создание или редактирование учётной записи требует отдельных прав.',
    'roles' => ['admin', 'superadmin'], 'sensitive' => true,
];
foreach ((require __DIR__.'/roles.php')['system'] as $role) {
    $actors = [];
    foreach ($assignments as $actor => $targets) {
        if (in_array($role['slug'], $targets, true)) $actors[] = $actor;
    }
    $permissions['users.assign_role.'.$role['slug']] = [
        'group' => 'Назначение ролей', 'name' => 'Назначение роли «'.$role['name'].'»',
        'description' => 'Разрешает назначать эту роль при создании и редактировании доступных пользователей. Кадровые ограничения, группы и защита дополнительных привилегий сохраняются.',
        'roles' => $actors, 'sensitive' => true,
    ];
}
return $permissions;
