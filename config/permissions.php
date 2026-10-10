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
        'description' => 'Редактирование объектов, где сотрудник является автором, агентом или совладельцем. Права редактирования своей группы, своего филиала и всех филиалов расширяют эту область. Закреплённые группы РОП остаются обязательной границей. Не разрешает утверждать изменения.',
        'roles_config' => 'property-moderation.creator_roles',
    ],
    'properties.deal.manage' => [
        'group' => 'Недвижимость',
        'name' => 'Залог и результат сделки',
        'description' => 'Фиксация залога и результата сделки по объявлениям, доступным для редактирования. Право «Сделка по объекту коллеги» отдельно разрешает работать с чужими объектами; назначение сотрудников также регулируется отдельным правом.',
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
        'group' => 'KPI', 'name' => 'Настройки операций KPI', 'description' => 'Изменение расписания Telegram-отчётов и доступ к подбору текущих сотрудников для корректировок. Внесение корректировок, история и блокировки периодов разрешаются отдельными правами. Глобальные настройки остаются недоступными РОП.', 'roles' => ['admin', 'superadmin', 'rop', 'branch_director'],
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

$personalStaffRoles = [...array_values(array_diff(array_column((require __DIR__.'/roles.php')['system'], 'slug'), ['client', 'external_agent'])), 'owner'];
$permissions['messaging.personal.staff'] = ['group' => 'Личные чаты', 'name' => 'Переписка с сотрудниками', 'description' => 'Создание личного диалога и отправка сообщений другим пользователям с этим правом. По умолчанию — в одном филиале; межфилиальный доступ выдаётся отдельно. История доступна только участникам.', 'roles' => $personalStaffRoles];
$permissions['messaging.personal.global'] = ['group' => 'Личные чаты', 'name' => 'Переписка между филиалами', 'description' => 'Разрешает общение с сотрудниками других филиалов в обоих направлениях, если обе стороны имеют право переписки с сотрудниками. Также снимает ограничение филиала при приглашении в групповой чат. Не открывает чужую переписку.', 'roles' => ['admin', 'superadmin', 'owner', 'marketing', 'hr', 'accountant', 'reels_manager']];
$permissions['messaging.personal.to_agents'] = ['group' => 'Личные чаты', 'name' => 'Клиентская сторона диалога', 'description' => 'Позволяет выбирать пользователей с правом «Обслуживание клиентов в личных чатах» и переписываться с ними без ограничения филиала. По умолчанию назначено клиентам; роль аккаунта не меняется.', 'roles' => ['client']];
$permissions['messaging.personal.to_clients'] = ['group' => 'Личные чаты', 'name' => 'Обслуживание клиентов в личных чатах', 'description' => 'Переписка с пользователями, имеющими право «Клиентская сторона диалога», без ограничения филиала. Такой сотрудник появляется в их списке собеседников. По умолчанию назначено агентам.', 'roles' => ['agent']];
$permissions['messaging.personal.site'] = ['group' => 'Личные чаты', 'name' => 'Выбор собеседников на сайте', 'description' => 'Открывает выбор собеседников на сайте и позволяет другим пользователям сайта выбирать этот аккаунт. Сам диалог дополнительно проверяется по правам переписки и области филиалов.', 'roles' => [...$groupChatRoles, 'client']];

$permissions['messaging.support.receive'] = ['group' => 'Поддержка', 'name' => 'Получение новых обращений', 'description' => 'Автоматически подключает сотрудника к новым обращениям пользователей и гостей как участника поддержки. Даёт доступ к этим диалогам и уведомлениям. Отзыв прекращает новые подключения; участие в уже назначенных диалогах сохраняется, как при прежней смене роли.', 'roles' => ['admin', 'superadmin', 'manager', 'operator'], 'sensitive' => true];

$permissions['bookings.site.edit'] = ['group' => 'Бронирования', 'name' => 'Редактирование бронирований на сайте', 'description' => 'Включает форму редактирования бронирования в отчёте на сайте. Дополнительно требуется право изменения своих или любых бронирований. По умолчанию выключено у всех ролей: до RBAC эта форма была недоступна. Не меняет права мобильного приложения и API.', 'roles' => []];

$permissions['users.site.mutate'] = ['group' => 'Сотрудники', 'name' => 'Изменение пользователей на сайте', 'description' => 'Показывает действия редактирования и увольнения в интерфейсе сайта. Для операции нужны соответствующие серверные права и доступ к пользователю. Сохраняет прежнее отсутствие этих кнопок у агентов и МОП.', 'roles' => ['admin', 'superadmin', 'marketing', 'hr', 'rop', 'branch_director']];
$permissions['users.site.admin_targets'] = ['group' => 'Сотрудники', 'name' => 'Изменение администраторов на сайте', 'description' => 'Разрешает действия сайта с аккаунтами администраторов и суперадминистраторов при наличии остальных прав управления пользователями. По умолчанию маркетологу не выдано, как в прежнем интерфейсе.', 'roles' => ['admin', 'superadmin'], 'sensitive' => true];
$permissions['users.site.dismiss_clients'] = ['group' => 'Сотрудники', 'name' => 'Отключение клиентских аккаунтов на сайте', 'description' => 'Открывает действие увольнения/отключения для клиентского аккаунта при наличии серверного права и доступа к этому клиенту. Редактирование контактных данных клиента этим правом не регулируется.', 'roles' => ['admin', 'superadmin', 'marketing'], 'sensitive' => true];

$permissions['daily_reports.finalized.edit'] = ['group' => 'Ежедневные отчёты', 'name' => 'Изменение финализированного отчёта', 'description' => 'Разрешает изменение отчёта с отметкой финализации при наличии права редактирования самого отчёта. Не отменяет запрет повторной отправки, дедлайн и блокировку периода.', 'roles' => ['admin', 'superadmin', 'owner', 'rop', 'branch_director'], 'sensitive' => true];
$permissions['daily_reports.locked_period.override'] = ['group' => 'Ежедневные отчёты', 'name' => 'Исключение для заблокированного периода', 'description' => 'Разрешает менять доступные ежедневные отчёты в заблокированном периоде через личную форму и общий API отчётов. В форме правки отчёта сотрудника проверки блокировки и дедлайна остаются обязательными. Остальные права редактирования необходимы.', 'roles' => [...array_values(array_diff(array_column((require __DIR__.'/roles.php')['system'], 'slug'), ['agent', 'mop', 'intern'])), 'owner'], 'sensitive' => true];

foreach (['all' => ['Карточки отчётов всех филиалов', 'Просмотр карточек ежедневных отчётов агентов и МОП любых филиалов.', ['admin', 'superadmin', 'owner']], 'branch' => ['Карточки отчётов своего филиала', 'Просмотр карточек ежедневных отчётов агентов и МОП своего филиала. Требуется назначенный филиал.', ['rop', 'branch_director']], 'own' => ['Только своя карточка отчёта', 'Просмотр только собственной карточки ежедневного отчёта сотрудника.', ['agent', 'mop', 'intern']]] as $scope => [$name, $description, $roles]) {
    $permissions['daily_reports.employee.scope.'.$scope] = ['group' => 'Ежедневные отчёты', 'name' => $name, 'description' => $description.' Для РОП сохраняются закреплённые группы и историческая принадлежность отчёта. Изменение требует отдельного права; доступ к спискам и личной форме настраивается отдельно.', 'roles' => $roles, 'exclusive_group' => 'daily_reports.employee.scope'];
}
$permissions['daily_reports.employee.edit'] = ['group' => 'Ежедневные отчёты', 'name' => 'Изменение карточки отчёта сотрудника', 'description' => 'Изменение ручных показателей в доступной карточке отчёта сотрудника. Нужна область доступа к карточкам. Блокировки, дедлайн, финализация и ограничения повторной отправки проверяются отдельно.', 'roles' => ['admin', 'superadmin', 'owner', 'rop', 'branch_director', 'agent', 'mop', 'intern']];

$dailyOwnListRoles = array_values(array_diff(array_column((require __DIR__.'/roles.php')['system'], 'slug'), ['admin', 'superadmin', 'rop', 'branch_director', 'mop']));
foreach (['all' => ['Список отчётов всех филиалов', 'Просмотр всех ежедневных отчётов.', ['admin', 'superadmin', 'owner']], 'branch' => ['Список отчётов своего филиала', 'Просмотр отчётов сотрудников своего филиала. Требуется назначенный филиал.', ['rop', 'branch_director']], 'own' => ['Список своих отчётов', 'Просмотр списка только собственных ежедневных отчётов.', $dailyOwnListRoles], 'own_group' => ['Свои отчёты с фильтрами своей группы', 'Просмотр только своих отчётов; фильтры сотрудников, группы и филиала ограничены своей группой. Сохраняет прежние ограничения МОП.', ['mop']]] as $scope => [$name, $description, $roles]) {
    $permissions['daily_reports.list.scope.'.$scope] = ['group' => 'Ежедневные отчёты', 'name' => $name, 'description' => $description.' Для РОП остаётся проверка закреплённых групп и исторической принадлежности. Доступ к карточке сотрудника задаётся отдельно.', 'roles' => $roles, 'exclusive_group' => 'daily_reports.list.scope'];
}
$permissions['daily_reports.list.edit'] = ['group' => 'Ежедневные отчёты', 'name' => 'Правка отчёта через общий API', 'description' => 'Изменение доступного отчёта через общий API ежедневных отчётов. Требуется область списка отчётов. Ограничения финализации, отправки и периода сохраняются; карточка отчёта сотрудника имеет отдельное право редактирования.', 'roles' => ['admin', 'superadmin', 'owner', 'rop', 'branch_director']];

$permissions['daily_reports.site.submission'] = ['group' => 'Ежедневные отчёты', 'name' => 'Страница сдачи ежедневного отчёта', 'description' => 'Открывает отдельную страницу сдачи отчёта. Для выбранной даты требуется просмотр своего отчёта, для текущего статуса — чтение собственной истории. Сохранение и отправка требуют изменения своего отчёта; дедлайн, закрытые периоды и финализация сохраняются.', 'roles' => ['agent', 'mop']];
$permissions['daily_reports.site.view'] = ['group' => 'Ежедневные отчёты', 'name' => 'Страница отчётов на сайте', 'description' => 'Открывает страницу ежедневных отчётов и пункт меню сайта. Личный и командный блоки включаются отдельно; серверные права просмотра и изменения обязательны.', 'roles' => ['admin', 'superadmin', 'rop', 'branch_director', 'mop', 'agent', 'owner']];
$permissions['daily_reports.site.own'] = ['group' => 'Ежедневные отчёты', 'name' => 'Личный блок на сайте', 'description' => 'Показывает личную форму и историю отчётов на странице сайта. Требуется право просмотра своего отчёта; сохранение требует права изменения.', 'roles' => ['agent', 'mop', 'intern']];
$permissions['daily_reports.site.team'] = ['group' => 'Ежедневные отчёты', 'name' => 'Командный блок на сайте', 'description' => 'Показывает список отчётов с фильтрами на странице сайта. Нужна область доступа к списку; редактирование использует отдельные права карточки сотрудника.', 'roles' => ['admin', 'superadmin', 'rop', 'branch_director', 'mop', 'owner']];

$dailyLegacyRoles = [...array_column((require __DIR__.'/roles.php')['system'], 'slug'), 'owner'];
$permissions['daily_reports.self_history.view'] = ['group' => 'Ежедневные отчёты', 'name' => 'Статус и история своих отчётов', 'description' => 'Просмотр статуса отправки, списка своих отчётов и отчёта за дату через общий API. Личная KPI-форма имеет отдельное право просмотра. По умолчанию доступ сохранён для всех прежних ролей.', 'roles' => $dailyLegacyRoles];
$permissions['daily_reports.self_submit.create'] = ['group' => 'Ежедневные отчёты', 'name' => 'Отправка своего отчёта через общий API', 'description' => 'Сохранение собственного отчёта через общий API ежедневных отчётов. Новая личная KPI-форма регулируется отдельным правом изменения. Периоды, финализация и повторная отправка проверяются отдельно.', 'roles' => $dailyLegacyRoles];
$permissions['daily_reports.submitted.override'] = ['group' => 'Ежедневные отчёты', 'name' => 'Повторная правка отправленного отчёта', 'description' => 'Разрешает правку уже отправленного отчёта через общий API и карточку сотрудника без персонального разрешения повторной правки. Требуются права самой операции. Дедлайн, финализация и блокировка периода сохраняются; новая личная KPI-форма остаётся закрыта после отправки.', 'roles' => ['admin', 'superadmin', 'owner', 'rop', 'branch_director'], 'sensitive' => true];

$kpiPlanManagers = ['admin', 'superadmin', 'owner', 'rop', 'branch_director', 'mop'];
$kpiPlanAllRoles = [...array_column((require __DIR__.'/roles.php')['system'], 'slug'), 'owner'];
foreach (['all' => ['Планы KPI всех филиалов', 'Область доступа к планам всех филиалов.', ['admin', 'superadmin', 'owner']], 'branch' => ['Планы KPI своего филиала', 'Область доступа к планам своего филиала и его групп.', ['rop', 'branch_director']], 'group' => ['Планы KPI своей группы', 'Планы своей группы; общие и массовые планы для роли РОП недоступны.', ['mop']], 'own' => ['Свой персональный план KPI', 'Область доступа только к собственному персональному плану.', array_values(array_diff($kpiPlanAllRoles, $kpiPlanManagers))]] as $scope => [$name, $description, $roles]) {
    $permissions['kpi.plans.scope.'.$scope] = ['group' => 'KPI', 'name' => $name, 'description' => $description.' Для РОП сохраняются закреплённые группы. Просмотр и изменение разрешаются отдельно.', 'roles' => $roles, 'exclusive_group' => 'kpi.plans.scope'];
}
foreach (['common.view' => ['Просмотр общих планов', 'Просмотр общих планов в выбранной области.', $kpiPlanManagers], 'common.manage' => ['Изменение общих планов', 'Изменение общих планов в выбранной области. Требуется также право управления планами.', array_values(array_diff($kpiPlanManagers, ['mop']))], 'bulk.manage' => ['Массовое назначение планов', 'Назначение планов нескольким сотрудникам выбранной области с проверкой каждого получателя. Требуется право управления планами.', $kpiPlanManagers], 'user.view' => ['Просмотр персональных планов', 'Просмотр персонального и действующего плана сотрудника в выбранной области.', $kpiPlanAllRoles], 'user.manage' => ['Изменение персональных планов', 'Изменение персональных планов в выбранной области. Требуется также право управления планами.', $kpiPlanManagers]] as $action => [$name, $description, $roles]) {
    $permissions['kpi.plans.'.$action] = ['group' => 'KPI', 'name' => $name, 'description' => $description.' Проверки периодов, конфликтов и состава показателей сохраняются.', 'roles' => $roles];
}

$permissions['kpi.plans.common.global.view'] = ['group' => 'KPI', 'name' => 'Просмотр общего шаблона компании', 'description' => 'Позволяет явно открыть общий план без филиала и группы при ограниченной области KPI. Требуется просмотр общих планов. Наследование общего шаблона внутри доступного филиала сохраняется.', 'roles' => ['rop', 'branch_director', 'mop']];
$permissions['kpi.plans.common.global.manage'] = ['group' => 'KPI', 'name' => 'Изменение общего шаблона компании', 'description' => 'Позволяет изменять общий план без филиала и группы при ограниченной области KPI. Требуются управление планами и изменение общих планов. Сохраняет прежнюю возможность директора филиала; закреплённые группы РОП остаются обязательными.', 'roles' => ['rop', 'branch_director'], 'sensitive' => true];

$permissions['kpi.plans.list.view'] = ['group' => 'KPI', 'name' => 'История планов KPI', 'description' => 'Просмотр списка сохранённых общих и персональных планов с датами и количеством показателей. Область истории задаётся отдельно; открытие карточек требует соответствующих прав просмотра.', 'roles' => $kpiPlanAllRoles];
foreach (['all' => ['История планов всех филиалов', 'Список истории всех филиалов.', array_values(array_diff($kpiPlanAllRoles, ['rop', 'branch_director', 'mop']))], 'branch' => ['История планов своего филиала', 'Персональные планы сотрудников своего филиала, общие планы филиала и общие шаблоны без филиала.', ['rop', 'branch_director']], 'group' => ['История планов своей группы', 'Персональные планы сотрудников своей группы, общие планы группы и шаблоны без группы.', ['mop']]] as $scope => [$name, $description, $roles]) {
    $permissions['kpi.plans.list.scope.'.$scope] = ['group' => 'KPI', 'name' => $name, 'description' => $description.' Требуется просмотр истории. Для РОП сохраняется историческая принадлежность плана к закреплённым группам.', 'roles' => $roles, 'exclusive_group' => 'kpi.plans.list.scope'];
}

$permissions['kpi.plans.site.view'] = ['group' => 'KPI', 'name' => 'Страница планов на сайте', 'description' => 'Открывает страницу персональных, общих и массовых планов KPI. Просмотр данных, область доступа и действия на странице разрешаются отдельно.', 'roles' => ['admin', 'superadmin', 'rop', 'branch_director', 'mop']];
$permissions['kpi.plans.site.menu'] = ['group' => 'KPI', 'name' => 'Планы KPI в основном меню', 'description' => 'Показывает пункт «Планы KPI» в основном меню сайта. Требуется доступ к странице планов. Сохраняет прежнюю видимость меню отдельно от доступа по ссылке.', 'roles' => ['admin', 'superadmin', 'rop', 'branch_director']];

$permissions['kpi.daily.input.manage'] = ['group' => 'KPI', 'name' => 'Сохранение ежедневного ввода KPI', 'description' => 'Сохраняет показатели сотрудников через таблицу ежедневного ввода KPI v2. Требуется область ввода. Права на планы KPI, личную форму отчёта и карточку отчёта сотрудника задаются отдельно. Ограничения исторических групп и блокировок РОП сохраняются.', 'roles' => $kpiPlanManagers];
foreach (['all' => ['Ежедневный ввод всех филиалов', 'Изменение показателей сотрудников всех филиалов.', ['admin', 'superadmin', 'owner']], 'branch' => ['Ежедневный ввод своего филиала', 'Изменение показателей сотрудников своего филиала.', ['rop', 'branch_director']], 'group' => ['Ежедневный ввод своей группы', 'Изменение показателей сотрудников своей группы.', ['mop']], 'own' => ['Ежедневный ввод только за себя', 'Изменение только собственных показателей.', []]] as $scope => [$name, $description, $roles]) {
    $permissions['kpi.daily.input.scope.'.$scope] = ['group' => 'KPI', 'name' => $name, 'description' => $description.' Требуется сохранение ежедневного ввода. РОП ограничен закреплёнными группами; для старых отчётов используется историческая группа.', 'roles' => $roles, 'exclusive_group' => 'kpi.daily.input.scope'];
}

$permissions['kpi.daily.site.edit'] = ['group' => 'KPI', 'name' => 'Заполнение личной формы ежедневного KPI на сайте', 'description' => 'Включает поля, сохранение черновика и отправку на странице «Ежедневный KPI». Нужны доступ к странице показателей, просмотр и изменение своего ежедневного отчёта. Дедлайн, закрытые периоды и запрет повторной отправки сохраняются.', 'roles' => ['agent', 'mop']];
$permissions['kpi.progress.site.card'] = ['group' => 'KPI', 'name' => 'Личная карточка прогресса на сайте', 'description' => 'Разрешает показ карточки «Мой KPI за сегодня» там, где она размещена на сайте. Требуется просмотр показателей KPI; отметка сдачи ежедневки дополнительно требует чтения собственной истории отчётов.', 'roles' => ['agent', 'mop']];
$permissions['kpi.reports.view'] = ['group' => 'KPI', 'name' => 'Просмотр показателей KPI', 'description' => 'Чтение дневных и сводных показателей KPI, связанных задач и личного прогресса. Для чужого прогресса и сводных отчётов требуется область просмотра; собственный прогресс доступен и без назначенного филиала. Состав показателей и целевые должности сотрудников сохраняются. Доступ к странице сайта задаётся отдельно.', 'roles' => $kpiPlanAllRoles];
foreach (['all' => ['Показатели всех филиалов', 'Просмотр показателей всех филиалов.', ['admin', 'superadmin', 'owner']], 'branch' => ['Показатели своего филиала', 'Просмотр показателей сотрудников своего филиала.', ['rop', 'branch_director']], 'group' => ['Показатели своей группы', 'Просмотр показателей сотрудников своей группы.', ['mop']], 'own' => ['Только свои показатели', 'Просмотр только собственных показателей и задач.', array_values(array_diff($kpiPlanAllRoles, $kpiPlanManagers))]] as $scope => [$name, $description, $roles]) {
    $permissions['kpi.reports.scope.'.$scope] = ['group' => 'KPI', 'name' => $name, 'description' => $description.' Требуется просмотр показателей. РОП ограничен закреплёнными группами; для истории сохраняется принадлежность отчётов на дату записи.', 'roles' => $roles, 'exclusive_group' => 'kpi.reports.scope'];
}

$permissions['kpi.reports.site.view'] = ['group' => 'KPI', 'name' => 'Страницы показателей KPI на сайте', 'description' => 'Открывает дашборд, дневные, недельные и месячные страницы KPI и их пункты меню. Серверный просмотр показателей, область доступа и ввод собственного отчёта разрешаются отдельно.', 'roles' => ['admin', 'superadmin', 'rop', 'branch_director', 'mop']];

foreach (['kpi.periods.lock' => ['Блокировка периода KPI', 'Блокирует день, неделю или месяц в выбранной области. Формат периода и исторические группы РОП проверяются отдельно.'], 'kpi.adjustments.manage' => ['Внесение корректировки KPI', 'Изменяет показатели сотрудника в уже заблокированном периоде с обязательной причиной и записью истории. Для РОП сохраняется доступ только к отчётам закреплённых групп.'], 'kpi.adjustments.view' => ['История корректировок KPI', 'Просмотр журнала корректировок с фильтрами периода и сотрудника. Для РОП журнал ограничен закреплёнными группами.'], 'kpi.adjustments.entities.view' => ['Сотрудники исторической корректировки РОП', 'Выбор сотрудников по историческим отчётам в закреплённой группе РОП и выбранном периоде. Не разрешает саму корректировку.']] as $key => [$name, $description]) {
    $permissions[$key] = ['group' => 'KPI', 'name' => $name, 'description' => $description, 'roles' => ['admin', 'superadmin', 'rop', 'branch_director'], 'sensitive' => in_array($key, ['kpi.periods.lock', 'kpi.adjustments.manage'], true)];
}
$permissions['kpi.periods.scope.all'] = ['group' => 'KPI', 'name' => 'Блокировки всех филиалов', 'description' => 'Область блокировки периодов всех филиалов, включая общий период без филиала. Требуется право блокировки; закреплённые группы РОП сохраняются.', 'roles' => ['admin', 'superadmin'], 'exclusive_group' => 'kpi.periods.scope'];
$permissions['kpi.periods.scope.branch'] = ['group' => 'KPI', 'name' => 'Блокировки своего филиала', 'description' => 'Блокировка периодов своего филиала и его групп. Общий период без филиала разрешается отдельно; РОП всегда выбирает закреплённую группу.', 'roles' => ['rop', 'branch_director'], 'exclusive_group' => 'kpi.periods.scope'];
$permissions['kpi.periods.global.lock'] = ['group' => 'KPI', 'name' => 'Общий период при доступе к филиалу', 'description' => 'Позволяет блокировать общий период без филиала и группы. Требуется блокировка периодов. Сохраняет прежнюю возможность директора филиала; РОП по-прежнему работает только с закреплёнными группами.', 'roles' => ['branch_director'], 'sensitive' => true];

$permissions['kpi.adjustments.site.view'] = ['group' => 'KPI', 'name' => 'Страница корректировок KPI', 'description' => 'Открывает страницу корректировок и её пункт меню. История отображается только при праве просмотра корректировок; форма добавления — только при праве внесения корректировки. Закрытые периоды, причина и группы РОП проверяются на сервере.', 'roles' => ['admin', 'superadmin', 'rop', 'branch_director']];

foreach (['view' => ['Просмотр планов РОП', 'Открывает список и карточку месячных планов РОП. Требуются просмотр общих планов и их область доступа.', $kpiPlanManagers], 'create' => ['Создание плана РОП', 'Создаёт месячный план РОП. Требуются изменение общих планов и их область доступа.', array_values(array_diff($kpiPlanManagers, ['mop']))], 'update' => ['Изменение плана РОП', 'Изменяет существующий месячный план РОП. Проверяется доступ к исходной и новой области; требуется изменение общих планов.', array_values(array_diff($kpiPlanManagers, ['mop']))], 'copy' => ['Копирование плана РОП', 'Копирует доступный месячный план в новый период или область. Требуются просмотр плана РОП, просмотр исходного общего плана и изменение общего плана в целевой области.', array_values(array_diff($kpiPlanManagers, ['mop']))]] as $action => [$name, $description, $roles]) {
    $permissions['kpi.rop_plans.'.$action] = ['group' => 'KPI', 'name' => $name, 'description' => $description.' Закреплённые группы РОП, конфликты периодов, состав показателей и журнал изменений сохраняются.', 'roles' => $roles];
}

$permissions['kpi.rop_plans.site.view'] = ['group' => 'KPI', 'name' => 'Страница «План от РОП»', 'description' => 'Открывает страницу месячных планов РОП и её вкладку в навигации KPI. Просмотр, создание, изменение, копирование и назначение персональных планов включаются отдельными правами.', 'roles' => ['admin', 'superadmin', 'rop', 'branch_director', 'mop']];

$permissions['kpi.rop_plans.site.apply'] = ['group' => 'KPI', 'name' => 'Назначение группе на странице плана РОП', 'description' => 'Включает кнопку назначения персональных планов группе на странице «План от РОП». Требуются просмотр персональных планов, управление планами, изменение персональных планов и массовое назначение. Сохраняет прежнее отсутствие этой кнопки у МОП.', 'roles' => ['admin', 'superadmin', 'rop', 'branch_director']];

foreach (['integrations.view' => ['Состояние интеграций KPI', 'Просмотр состояния источников данных KPI. Глобальный экран интеграций остаётся недоступным РОП.'], 'telegram.view' => ['Просмотр расписания отчётов KPI', 'Просмотр настроек расписания Telegram-отчётов. Изменение требует права операций KPI; глобальное расписание остаётся недоступным РОП.'], 'quality.view' => ['Диагностика качества данных KPI', 'Просмотр проблем качества показателей и их деталей. Для РОП сохраняются фильтрация закреплённых групп и скрытие чужих данных.'], 'alerts.view' => ['Просмотр предупреждений KPI', 'Просмотр ранних предупреждений о рисках KPI. Для РОП доступны только предупреждения закреплённых групп.'], 'alerts.update' => ['Изменение статуса предупреждения KPI', 'Подтверждение, закрытие или эскалация предупреждения. Право просмотра задаётся отдельно; для РОП сохраняется проверка закреплённой группы.'], 'acceptance.view' => ['Результаты контрольных проверок KPI', 'Просмотр результатов контрольных запусков и деталей проверки KPI. РОП видит только данные закреплённых групп.']] as $action => [$name, $description]) {
    $permissions['kpi.'.$action] = ['group' => 'KPI', 'name' => $name, 'description' => $description, 'roles' => $kpiPlanAllRoles];
}

$permissions['kpi.operations.site.view'] = ['group' => 'KPI', 'name' => 'Операционный контроль KPI на сайте', 'description' => 'Открывает страницу операционного контроля и пункт меню. Каждый блок диагностики, показатели, планы, расписание и действия требуют отдельных прав. Глобальные блоки расписания и интеграций остаются недоступными РОП.', 'roles' => ['admin', 'superadmin', 'rop', 'branch_director']];

$permissions['kpi.plans.legacy.view'] = ['group' => 'KPI', 'name' => 'Просмотр сводного плана должности', 'description' => 'Чтение прежнего сводного плана по должности без выбора сотрудника; используется в операционном экспорте KPI. Не требует доступа к персональным планам. У РОП сохраняется выбор закреплённой группы; у остальных ролей выборка, как прежде, общая по должности.', 'roles' => $kpiPlanAllRoles];
$permissions['kpi.plans.legacy.manage'] = ['group' => 'KPI', 'name' => 'Изменение сводного плана должности', 'description' => 'Изменение плана через прежний маршрут планирования по должности. Требуется также управление планами KPI. У РОП изменяется план закреплённой группы; у остальных ролей сохраняется общая выборка по должности. Персональные и месячные планы имеют отдельные права.', 'roles' => ['admin', 'superadmin', 'rop', 'branch_director', 'mop'], 'sensitive' => true];

$permissions['motivation.site.view'] = ['group' => 'Мотивация', 'name' => 'Страница мотивации на сайте', 'description' => 'Открывает обзор мотивации. Для личных данных требуется просмотр личной мотивации; управление и планы разрешаются отдельно.', 'roles' => ['admin', 'superadmin', 'rop', 'branch_director', 'agent', 'mop']];
$permissions['motivation.site.manage'] = ['group' => 'Мотивация', 'name' => 'Страница управления мотивацией', 'description' => 'Открывает управление правилами на сайте. Чтение правил и изменение мотивации требуют отдельных прав; можно предоставить страницу только для чтения.', 'roles' => ['admin', 'superadmin', 'rop', 'branch_director']];
$profileWidgetRoles = array_values(array_diff(array_column((require __DIR__.'/roles.php')['system'], 'slug'), ['client', 'external_agent']));
$permissions['profile.motivation.view'] = ['group' => 'Профиль', 'name' => 'Мотивация в профиле', 'description' => 'Показывает вкладку личной мотивации в профиле. Данные и карточка цели доступны только с правом просмотра личной мотивации.', 'roles' => $profileWidgetRoles];
$permissions['profile.kpi.view'] = ['group' => 'Профиль', 'name' => 'KPI в профиле', 'description' => 'Показывает вкладку дневных, недельных и месячных показателей в профиле. Требуется просмотр показателей KPI; серверная область доступа сохраняется.', 'roles' => $profileWidgetRoles];
$permissions['profile.kpi.links'] = ['group' => 'Профиль', 'name' => 'Ссылки на подробный KPI из профиля', 'description' => 'Показывает переходы к полному дашборду и ежедневному KPI. Требуются вкладка KPI и доступ к соответствующим страницам.', 'roles' => array_values(array_diff($profileWidgetRoles, ['agent', 'mop']))];
$permissions['profile.start_day'] = ['group' => 'Профиль', 'name' => 'Напоминание о начале дня', 'description' => 'Включает окно начала дня и напоминание о сдаче ежедневного отчёта. Требуется чтение собственной истории отчётов. Карточка цели показывается только при наличии права просмотра личной мотивации.', 'roles' => ['agent', 'mop', 'intern']];
$motivationAllRoles = [...array_column((require __DIR__.'/roles.php')['system'], 'slug'), 'owner'];
$permissions['motivation.rules.view'] = ['group' => 'Мотивация', 'name' => 'Просмотр условий мотивации', 'description' => 'Чтение списка правил и наград с фильтрами периода, активности и личной или корпоративной программы. Изменение условий требует управления мотивацией.', 'roles' => $motivationAllRoles];
$permissions['motivation.achievements.view'] = ['group' => 'Мотивация', 'name' => 'Просмотр списка достижений', 'description' => 'Чтение достижений и состояния выдачи наград. Состав сотрудников определяется областью доступа; достижения компании разрешаются отдельно. Личный обзор мотивации имеет отдельное право.', 'roles' => $motivationAllRoles];
$permissions['motivation.achievements.company'] = ['group' => 'Мотивация', 'name' => 'Достижения компании', 'description' => 'Добавляет корпоративные достижения в доступный список. Требуется просмотр списка достижений; не разрешает изменение наград.', 'roles' => $motivationAllRoles];
foreach (['all' => ['Достижения всех сотрудников', 'Просмотр достижений сотрудников всех филиалов.', ['admin', 'superadmin', 'owner']], 'branch' => ['Достижения своего филиала', 'Просмотр достижений сотрудников своего филиала. Без назначенного филиала сохраняется доступ только к своим достижениям.', ['rop', 'branch_director']], 'group' => ['Достижения своей группы', 'Просмотр достижений сотрудников своей группы. Без назначенной группы сохраняется доступ только к своим достижениям.', ['mop']], 'own' => ['Только свои достижения', 'Просмотр только собственных достижений.', array_values(array_diff($motivationAllRoles, ['admin', 'superadmin', 'owner', 'rop', 'branch_director', 'mop']))]] as $scope => [$name, $description, $roles]) {
    $permissions['motivation.achievements.scope.'.$scope] = ['group' => 'Мотивация', 'name' => $name, 'description' => $description.' Требуется просмотр списка достижений. Ограничение доступа РОП к модулю сохраняется.', 'roles' => $roles, 'exclusive_group' => 'motivation.achievements.scope'];
}

$reminderRoles = [...array_column((require __DIR__.'/roles.php')['system'], 'slug'), 'owner'];
foreach (['view' => ['Просмотр своих напоминаний', 'Чтение времени, каналов и состояния личного напоминания о ежедневном отчёте.'], 'update' => ['Изменение своих напоминаний', 'Изменение времени, часового пояса, каналов и включения личного напоминания. Разрешение повторной правки отчёта задаётся отдельно.'], 'resubmission' => ['Личная настройка повторной правки отчёта', 'Разрешает менять личную настройку повторной правки отправленного отчёта. Требуется изменение своих напоминаний. Права редактирования отчёта, дедлайн и блокировки сохраняются.']] as $action => [$name, $description]) {
    $permissions['daily_reports.reminders.'.$action] = ['group' => 'Ежедневные отчёты', 'name' => $name, 'description' => $description, 'roles' => $reminderRoles, 'sensitive' => $action === 'resubmission'];
}
$permissions['daily_reports.reminders.site'] = ['group' => 'Ежедневные отчёты', 'name' => 'Настройки напоминаний в профиле', 'description' => 'Показывает карточку личного напоминания в профиле. Требуется просмотр своих напоминаний; изменение включается отдельным правом.', 'roles' => ['agent', 'mop']];

foreach (['view' => ['Просмотр настроек отслеживания', 'Чтение режима, расписания, интервалов и срока хранения координат сотрудника.'], 'update' => ['Изменение настроек отслеживания', 'Изменение режима, расписания и параметров отслеживания сотрудника с обязательной причиной и записью в аудит. Сам сотрудник должен иметь право передачи местоположения.']] as $action => [$name, $description]) {
    $permissions['locations.settings.'.$action] = ['group' => 'Местоположение', 'name' => $name, 'description' => $description.' Требуется область настройки отслеживания.', 'roles' => ['admin', 'superadmin', 'branch_director'], 'sensitive' => $action === 'update'];
}
$permissions['locations.settings.scope.all'] = ['group' => 'Местоположение', 'name' => 'Настройка отслеживания всех филиалов', 'description' => 'Доступ к настройкам отслеживания сотрудников всех филиалов. Просмотр и изменение разрешаются отдельно.', 'roles' => ['admin', 'superadmin'], 'exclusive_group' => 'locations.settings.scope'];
$permissions['locations.settings.scope.branch'] = ['group' => 'Местоположение', 'name' => 'Настройка отслеживания своего филиала', 'description' => 'Доступ к настройкам сотрудников своего назначенного филиала, которым разрешена передача местоположения. Просмотр и изменение разрешаются отдельно.', 'roles' => ['branch_director'], 'exclusive_group' => 'locations.settings.scope'];

foreach (['all' => ['Карта всех филиалов', 'Агенты, МОП и сотрудники с сохранённой текущей геопозицией во всех филиалах.', ['admin', 'superadmin']], 'branch' => ['Карта своего филиала', 'Агенты и МОП своего филиала. Без назначенного филиала доступна только собственная позиция.', ['rop', 'branch_director']], 'group' => ['Карта своей группы', 'Своя позиция и позиции агентов своей группы; история чужих позиций ограничивается этой группой.', ['mop']], 'own' => ['Только своё местоположение', 'Только собственная позиция и история координат.', ['agent']]] as $scope => [$name, $description, $roles]) {
    $permissions['locations.scope.'.$scope] = ['group' => 'Местоположение', 'name' => $name, 'description' => $description.' Требуется просмотр местоположения. РОП дополнительно ограничен закреплёнными группами; для истории используется группа записи.', 'roles' => $roles, 'exclusive_group' => 'locations.scope'];
}

$permissions['properties.deal.assign_users'] = ['group' => 'Недвижимость', 'name' => 'Назначение сотрудников сделки по объекту', 'description' => 'Разрешает указать получателя депозита, продавца и соисполнителей в доступной сделке по объекту. Право управления сделкой обязательно. Сохранение прежнего получателя депозита или продавца не требует нового назначения. Для передачи списка соисполнителей право обязательно; ограничения закреплённых групп РОП сохраняются.', 'roles' => ['admin', 'superadmin', 'agent', 'intern', 'mop', 'rop', 'branch_director', 'manager', 'operator']];
$permissions['properties.deal.colleague'] = ['group' => 'Недвижимость', 'name' => 'Сделка по объекту коллеги', 'description' => 'Разрешает оформить депозит или закрытие сделки по чужому объекту без права редактирования объявления. Для продажи продавцом может быть только сам сотрудник. Требуется управление сделкой; назначение участников регулируется отдельно. Границы групп РОП сохраняются.', 'roles' => ['agent']];

$permissions['properties.edit.group'] = ['group' => 'Недвижимость', 'name' => 'Редактирование объектов своей группы', 'description' => 'Расширяет редактирование своих объектов до объектов своей группы. Требуется право редактирования объявлений. Используется группа объекта, а при её отсутствии — группа агента или автора. Без назначенной группы расширение не действует. Границы закреплённых групп РОП сохраняются.', 'roles' => ['mop']];
$permissions['properties.edit.branch'] = ['group' => 'Недвижимость', 'name' => 'Редактирование объектов своего филиала', 'description' => 'Расширяет редактирование своих объектов до объектов своего филиала. Требуется право редактирования объявлений. Используется филиал объекта, а при его отсутствии — филиал агента или автора. Без назначенного филиала расширение не действует. Границы закреплённых групп РОП сохраняются.', 'roles' => ['rop', 'branch_director']];

$permissions['properties.co_owner.manage'] = ['group' => 'Недвижимость', 'name' => 'Назначение и снятие совладельца объекта', 'description' => 'Разрешает изменить или убрать совладельца объекта, доступного для редактирования. Право редактирования обязательно. Новый совладелец должен быть активным сотрудником своего филиала и не совпадать с автором или ответственным агентом. Назначение в другой филиал регулируется отдельно.', 'roles_config' => 'property-moderation.creator_roles'];
$permissions['properties.transfer'] = ['group' => 'Недвижимость', 'name' => 'Передача объекта другому ответственному', 'description' => 'Разрешает сменить ответственного агента, в том числе вместе с совладельцем, у объекта в доступной области модерации. Право модерации обязательно. Требуются причина и актуальная версия объекта; история сохраняется. Сотрудники другого филиала доступны по отдельному праву.', 'roles_config' => 'property-moderation.moderator_roles'];
$permissions['properties.ownership.assign_across_branches'] = ['group' => 'Недвижимость', 'name' => 'Назначение владельцев объекта из другого филиала', 'description' => 'Разрешает выбрать активного ответственного агента или совладельца из другого филиала. Не открывает чужие объекты и не разрешает передачу само по себе: нужны отдельные права на действие и доступ к объекту. Ограничения закреплённых групп РОП сохраняются.', 'roles_config' => 'property-moderation.global_moderator_roles', 'sensitive' => true];

$permissions['properties.promotions.request'] = ['group' => 'Недвижимость', 'name' => 'Заявки на продвижение объявлений', 'description' => 'Подача и отзыв ещё не одобренных заявок на VIP или срочное продвижение объектов, доступных для редактирования. Требуется право редактирования. Если также доступно управление продвижением, заявка опубликованного объекта включается сразу; иначе требуется одобрение.', 'roles_config' => 'property-moderation.creator_roles'];
$permissions['properties.promotions.manage'] = ['group' => 'Недвижимость', 'name' => 'Управление продвижением объявлений', 'description' => 'Одобрение и отклонение заявок, отмена активного продвижения в доступной области модерации. Требуется право модерации. Для прямого выбора типа и срока продвижения дополнительно нужны право заявок и редактирование объекта. Продвижение включается только у опубликованного объекта; сроки и журнал сохраняются.', 'roles_config' => 'property-moderation.moderator_roles'];

$permissions['properties.history.view'] = ['group' => 'Недвижимость', 'name' => 'История изменений объекта', 'description' => 'Просмотр журнала изменений объектов, доступных для редактирования. Требуется право редактирования в соответствующей области. При наличии права просмотра закрытых объектов также доступна история закрытых стадий из отчёта контроля объектов. Не разрешает менять записи журнала.', 'roles' => ['agent', 'mop', 'rop', 'branch_director', 'admin', 'superadmin', 'security']];
$permissions['properties.listing_date.refresh'] = ['group' => 'Недвижимость', 'name' => 'Обновление даты объявления', 'description' => 'Обновление даты размещения доступного для редактирования опубликованного объявления. Требуется право редактирования; исходная дата создания сохраняется. Проверки статуса и срок ожидания между обновлениями остаются обязательными, действие записывается в журнал.', 'roles_config' => 'property-moderation.creator_roles'];

$permissions['properties.closed.view'] = ['group' => 'Недвижимость', 'name' => 'Просмотр закрытых объектов', 'description' => 'Открытие карточек объектов на стадиях залога, продажи, продажи владельцем, аренды и удаления, используемых отчётом контроля объектов. Не открывает черновики или ожидающие модерации объявления и не разрешает редактирование. Доступ к отчёту и истории выдаётся отдельно. Закреплённые группы РОП остаются обязательной границей.', 'roles' => ['security']];

foreach ([
    'all' => ['Все объекты в списках', 'Списки, поиск и карта объектов без ограничения владельцем или группой, включая непубличные стадии. Открытие закрытой карточки, история и изменение объекта требуют отдельных прав. Границы закреплённых групп РОП сохраняются.', array_values(array_diff([...array_column((require __DIR__.'/roles.php')['system'], 'slug'), 'owner'], ['client', 'agent', 'intern', 'mop']))],
    'public' => ['Только публичные объекты в списках', 'В списках, поиске и на карте доступны только публичные активные объявления, как для посетителя сайта. Другие права не расширяют эту область списка.', ['client']],
    'own' => ['Свои объекты в списках', 'Списки, поиск и карта ограничены объектами, где сотрудник является автором или совладельцем. Назначение только ответственным агентом не включает объект в эту область. Удалённые записи появляются только при явном фильтре статуса.', ['agent', 'intern']],
    'group' => ['Объекты своей группы в списках', 'Списки, поиск и карта ограничены группой сотрудника, с сохранением прежних правил определения группы объекта. Без назначенной группы список пуст. Удалённые записи появляются только при явном фильтре статуса.', ['mop']],
] as $scope => [$name, $description, $roles]) {
    $permissions['properties.list.scope.'.$scope] = ['group' => 'Недвижимость', 'name' => $name, 'description' => $description, 'roles' => $roles, 'exclusive_group' => 'properties.list.scope'];
}
$permissions['properties.list.closed_only'] = ['group' => 'Недвижимость', 'name' => 'Режим закрытых объектов в разделе «Мои объекты»', 'description' => 'Ограничивает раздел «Мои объекты» закрытыми стадиями отчёта контроля и включает сводки по статусам, валютам и сотрудникам. Дополнительно применяется выбранная область списка и границы групп РОП. На общий список и карту этот режим не влияет. Открытие карточек требует отдельного права.', 'roles' => ['security']];

foreach (['own' => 'Мои объекты', 'all' => 'Все объекты'] as $kind => $title) {
    $permissions['properties.site.'.$kind] = ['group' => 'Недвижимость', 'name' => 'Страница «'.$title.'»', 'description' => 'Разрешает открыть страницу «'.$title.'» на сайте. Для загрузки данных нужны право получения внутренних списков и выбранная область объектов. Наличие страницы не расширяет область данных и не разрешает изменения.', 'roles' => ['admin', 'superadmin', 'marketing', 'hr', 'agent', 'mop', 'rop', 'branch_director', 'manager', 'operator', 'reels_manager', 'intern']];
    $permissions['properties.menu.'.$kind] = ['group' => 'Недвижимость', 'name' => 'Ссылка «'.$title.'» в меню', 'description' => 'Показывает ссылку на страницу «'.$title.'» в меню профиля. Требуются доступ к странице, получение внутренних списков и область объектов.', 'roles' => $kind === 'all' ? ['admin', 'superadmin', 'marketing', 'mop', 'rop', 'branch_director'] : ['admin', 'superadmin', 'marketing', 'agent', 'mop', 'rop', 'branch_director', 'manager', 'operator', 'reels_manager', 'intern']];
}

$permissions['properties.filters.own_author'] = ['group' => 'Недвижимость', 'name' => 'Личный фильтр автора на странице «Все объекты»', 'description' => 'На странице «Все объекты» автоматически ограничивает выборку объектами, где текущий сотрудник указан автором, независимо от выбранного фильтра автора. Сохраняет прежний режим стажёра. Дополнительно действует серверная область списков; фильтр не выдаёт доступ к странице или чужим объектам.', 'roles' => ['intern']];

$permissions['properties.filters.own_branch'] = ['group' => 'Недвижимость', 'name' => 'Фильтр своего филиала на странице «Все объекты»', 'description' => 'На странице «Все объекты» автоматически ограничивает запросы, счётчики и показанные карточки филиалом сотрудника. Если филиал не назначен, дополнительный фильтр не применяется. Серверная область списка и обязательные группы РОП действуют независимо и не расширяются этим фильтром.', 'roles' => ['rop', 'branch_director']];
$permissions['properties.filters.own_group'] = ['group' => 'Недвижимость', 'name' => 'Фильтр своей группы на странице «Все объекты»', 'description' => 'На странице «Все объекты» автоматически ограничивает запросы, счётчики и показанные карточки группой сотрудника. Если группа не назначена, дополнительный фильтр не применяется. Серверная область списка и обязательные группы РОП действуют независимо и не расширяются этим фильтром.', 'roles' => ['mop']];

$permissions['properties.list.internal'] = ['group' => 'Недвижимость', 'name' => 'Получение внутренних списков объектов и отчёта', 'description' => 'Разрешает получать данные для внутренних списков «Мои объекты», «Все объекты» и отчёта по объектам. Нужна одна из областей списка; открытие страниц и финансовые колонки настраиваются отдельно. Не управляет публичным поиском и картой. По умолчанию сохраняет прежний запрет для клиента и внешнего агента.', 'roles' => [...array_values(array_diff(array_column((require __DIR__.'/roles.php')['system'], 'slug'), ['client', 'external_agent'])), 'owner']];

$permissions['properties.write.own_group'] = ['group' => 'Недвижимость', 'name' => 'Сохранение объектов в своей группе', 'description' => 'При создании и обычном редактировании объекта закрепляет группу сотрудника. Без назначенной группы сохранение запрещено; при создании нельзя передать чужую группу. Сохраняет прежнее правило МОП. Не выдаёт права создания или редактирования и не заменяет проверки клиентов, сотрудников, обязательных групп РОП и отдельного переноса объекта.', 'roles' => ['mop']];

$permissions['bookings.property.site.create'] = ['group' => 'Показы и бронирования', 'name' => 'Форма создания показа в карточке объекта', 'description' => 'Показывает кнопку и форму создания показа в карточке объекта на сайте. Дополнительно требуется право создания показов; проверки клиента, агента, времени и групп сохраняются.', 'roles' => ['admin', 'superadmin', 'marketing', 'agent', 'mop']];
$permissions['properties.internal_fields.site'] = ['group' => 'Недвижимость', 'name' => 'Ключи и договор в карточке объекта', 'description' => 'Показывает на сайте поля «У кого ключи» и «Тип договора с продавцом» в доступной карточке объекта. Управляет отображением этих полей, а не их наличием в других ответах или формах редактирования.', 'roles' => ['admin', 'superadmin', 'marketing', 'security']];
$permissions['properties.media.download.site'] = ['group' => 'Недвижимость', 'name' => 'Кнопки скачивания фото и рилсов объекта', 'description' => 'Показывает кнопки скачивания фотографий и рилсов в галерее доступной карточки на сайте. Публичные файлы остаются доступными по ссылкам независимо от этих кнопок.', 'roles' => ['admin', 'superadmin', 'marketing', 'hr', 'accountant', 'security', 'rop', 'mop', 'branch_director', 'manager', 'operator', 'reels_manager', 'intern', 'agent', 'external_agent']];
$permissions['properties.matching.view'] = ['group' => 'Недвижимость', 'name' => 'Подбор клиентов к объекту', 'description' => 'Получение подходящих клиентов и открытых потребностей для объекта. Использует доступную область клиентов и обязательные группы РОП; не расширяет доступ к клиентам. Дополнительный фильтр только своих клиентов и показ блока на сайте настраиваются отдельно.', 'roles' => [...array_values(array_diff(array_column((require __DIR__.'/roles.php')['system'], 'slug'), ['client', 'external_agent', 'accountant'])), 'owner'], 'sensitive' => true];
$permissions['properties.matching.own_clients'] = ['group' => 'Недвижимость', 'name' => 'Только свои клиенты в подборе к объекту', 'description' => 'Дополнительно ограничивает подбор клиентами, где сотрудник является автором или ответственным агентом, даже если общая область клиентов шире. Сохраняет прежнее ограничение агента. Требуется право подбора клиентов.', 'roles' => ['agent']];
$permissions['properties.matching.site.all'] = ['group' => 'Недвижимость', 'name' => 'Блок подбора клиентов во всех доступных карточках', 'description' => 'Показывает блок «Подходящие клиенты» в карточке объекта на сайте. Требуется право подбора; общая область клиентов и фильтр своих клиентов сохраняются. Не требует показа контактов владельца.', 'roles' => ['manager', 'admin', 'superadmin', 'marketing', 'rop', 'branch_director']];
$permissions['properties.matching.site.own'] = ['group' => 'Недвижимость', 'name' => 'Блок подбора клиентов в своих карточках', 'description' => 'Показывает блок «Подходящие клиенты», когда сотрудник — автор или назначенный агент объекта. Соавторство само по себе не даёт этот показ. Требуется право подбора; не расширяет область клиентов и не требует показа контактов владельца.', 'roles' => ['agent', 'mop']];

$permissions['properties.contacts.view'] = ['group' => 'Недвижимость', 'name' => 'Получение контактов владельца и покупателя в карточке', 'description' => 'В ответе карточки доступного объекта возвращает имя, телефон и привязку CRM-клиента владельца и покупателя. По прежнему поведению выдано всем существующим ролям, включая клиента. Показ блока на сайте требует отдельного права. Не открывает сам объект, историю изменений или поиск по владельцу; не управляет отдельными клиентскими API и данными редактирования.', 'roles' => [...array_column((require __DIR__.'/roles.php')['system'], 'slug'), 'owner'], 'sensitive' => true];
$permissions['properties.contacts.site.all'] = ['group' => 'Недвижимость', 'name' => 'Блок контактов во всех доступных карточках', 'description' => 'Показывает блок контактов и CRM-данных владельца и покупателя в доступной карточке объекта на сайте. Требуется право получения контактов. Не расширяет доступ к объектам, клиентам или истории изменений.', 'roles' => ['manager', 'admin', 'superadmin', 'marketing', 'rop', 'branch_director', 'security'], 'sensitive' => true];
$permissions['properties.contacts.site.own'] = ['group' => 'Недвижимость', 'name' => 'Блок контактов в своих карточках', 'description' => 'Показывает блок контактов владельца и покупателя на сайте, когда сотрудник — автор или назначенный агент объекта. Соавторство само по себе не даёт этот показ. Требуется право получения контактов; доступ к самому объекту проверяется отдельно.', 'roles' => ['agent', 'mop'], 'sensitive' => true];

$permissions['properties.search.owner'] = ['group' => 'Недвижимость', 'name' => 'Поиск объектов по имени и телефону владельца', 'description' => 'Позволяет находить объекты через текстовый поиск по имени и телефону владельца. Без этого права поиск использует только публичные поля объявления. Требуется область списка объектов; право не расширяет её, не открывает закрытые публикации и не добавляет контакты в ответ поиска. Доступ к контактам в карточке регулируется отдельно.', 'roles' => [...array_values(array_diff(array_column((require __DIR__.'/roles.php')['system'], 'slug'), ['client'])), 'owner'], 'sensitive' => true];

$permissions['properties.reports.site'] = ['group' => 'Недвижимость', 'name' => 'Страница отчёта по объектам', 'description' => 'Открывает отчёт по объектам и экспорт его доступных строк на сайте. Требуются доступ к внутренним спискам и область объектов; фильтры и ограничения групп сохраняются. Дополнительные финансовые колонки выдаются отдельно.', 'roles' => ['admin', 'superadmin', 'branch_director', 'rop', 'mop', 'security', 'accountant']];
$permissions['properties.reports.own_branch'] = ['group' => 'Недвижимость', 'name' => 'Фильтр своего филиала в отчёте по объектам', 'description' => 'В отчёте по объектам фиксирует филиал сотрудника для таблицы, фильтров, адресной строки и CSV. Если филиал не назначен, дополнительный фильтр не применяется. Не выдаёт доступ к странице и не расширяет серверную область объектов или обязательные группы РОП. Не управляет страницей «Все объекты».', 'roles' => ['rop', 'branch_director']];
$permissions['properties.reports.own_group'] = ['group' => 'Недвижимость', 'name' => 'Фильтр своей группы в отчёте по объектам', 'description' => 'В отчёте по объектам фиксирует группу сотрудника для таблицы, фильтров, адресной строки и CSV. Если группа не назначена, дополнительный фильтр не применяется. Не выдаёт доступ к странице и не расширяет серверную область объектов или обязательные группы РОП. Не управляет страницей «Все объекты».', 'roles' => ['mop']];
$permissions['properties.reports.economics'] = ['group' => 'Недвижимость', 'name' => 'Финансовые колонки и сотрудники отчёта по объектам', 'description' => 'Показывает дополнительные финансовые колонки в таблице и CSV отчёта. Добавляет в ответ списка сведения о продавце и текущих РОП/МОП с выплатами участников сделки. Не расширяет область объектов и не выдаёт доступ к странице. Не управляет обычными полями сделки в других карточках и API.', 'roles' => ['superadmin', 'accountant'], 'sensitive' => true];

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
