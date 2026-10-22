# Reviewed employee dismissal

The existing dismissal preview now includes recipient branch/group names, approved-property workload, an eligible property co-owner (`preferred_user_id`), and property-linked task references (`follows_property_id`). These are advisory inputs to a reviewed plan; the server revalidates every recipient and record when applying it.

Automatic property allocation first assigns selected eligible co-owners, then chooses the lowest approved-property workload, incrementing it for each assignment. Equal loads rotate in a deterministic order seeded by the dismissed employee ID. Other sections balance the current batch. The user may override any assignment before submitting. Filters affect the candidate pool, not existing assignments; allocation replaces only the current section.

An administrator/superadministrator may move active properties to a recipient in another branch/group. With `users.dismissal.employee_records`, branch directors may include all active records of an employee they manage in their branch, including records without a group or from another branch. Recipients remain in the director's branch; ROP recipients remain in their assigned groups. The employee, recipient scope and permission are rechecked when applying the plan. This exception does not grant ordinary access or transfer rights to foreign records. HR retains ownership-only transfer within each record's group. Cross-group property assignments require an explicit `destination_branch_group_id` on that plan record, matching the locked recipient's current group. Old clients cannot implicitly authorize relocation.

The director permission migration updates configured `branch_director` roles only when dismissal is already allowed, records an audit and increments the permissions version. Null permissions use catalog defaults; revoked dismissal rights and other roles are preserved. It does not grant either cross-branch-recipient or general cross-branch-transfer permissions.

Relocation uses GroupRecordTransfer, including moderation version checks, audit, property-control cards and active linked task propagation. Tasks in the dismissal inventory are revalidated against their resulting group. Clients, leads, deals and bookings keep their existing eligibility scope. Closed history remains unchanged. Dismissal and all transfers run in one transaction; an incomplete, stale, unauthorized or inconsistent plan must roll back and preserve employee access.

Validation: DismissalTransferTest covers relocation, task propagation, historical preservation, explicit destination, workload/co-owner preview and restricted roles, along with the existing atomicity and stale-plan tests. Frontend unit and component tests cover load-aware allocation, co-owner priority, manual overrides, filters, destination submission, failed confirmation and narrow viewports.


## История увольнения и восстановление

Каждое новое успешное увольнение сохраняет в `user_dismissed` список переданных активных объявлений: ID, название и получателя. Для проверки возврата сохраняется хеш состояния объявления и связанных задач, сделок, показов, фотографий и журналов изменений после окончания всей передачи. Полные снимки содержимого в журнал не копируются. Счётчики просмотров/кликов и результаты фонового расчёта ликвидности не участвуют в сравнении; реальная история редактирования участвует. Закрытые объявления не входят в список возврата.

`GET /api/user/{user}/restoration-preview` проверяет право восстановления и область сотрудника. Ответ содержит `dismissal_id`, `has_snapshot`, `revision` и `properties` с `can_return` / `reason`. Объявления вне филиала не раскрывают название и получателей. В общем журнале сотрудника также отображаются сохранившиеся старые `property_owner_transferred`; они показывают факт передачи, но не доказывают отсутствие последующих изменений.

`POST /api/user/{user}/restore` без тела по-прежнему восстанавливает только доступ. Опциональный `return_plan` содержит `revision`, уникальные `property_ids` (до 1000 за запрос) и `reason` (5–2000 символов). Выбранные объявления должны относиться к последнему увольнению, сохранять получателя и состояние после передачи. Для возврата требуются действующая группа и роль получателя у сотрудника, прежние филиал и группа, а у руководителя — существующие права переноса объявления. HR без права переноса может восстановить только доступ.

Возврат использует обычный сервис переноса: активные связанные задачи следуют за объявлением; служебные контрольные карточки сохраняют свои правила. Сотрудник, объявления и журналы изменяются в одной транзакции. Устаревшая проверка возвращает 409 `RESTORATION_VERSION_CONFLICT`; недоступный ID — 422 `PROPERTY_RETURN_UNAVAILABLE`. Ошибка откатывает также активацию сотрудника. Повторное применение плана не возвращает записи дважды. Уже активный сотрудник может вернуть оставшиеся допустимые объявления позднее. Новое увольнение начинает отдельный цикл истории.

`user_restored` сохраняет кто/когда восстановил доступ и число возвращённых объявлений; `employee_properties_returned` — список ID, причину и исходное увольнение. Старый пароль и отозванные сеансы не восстанавливаются. При необходимости руководитель задаёт новый пароль существующим способом в карточке сотрудника.

Новых миграций нет. Backend следует выпускать до frontend; прежний вызов восстановления остаётся совместимым. Для старого увольнения без контрольного снимка интерфейс явно предлагает только восстановление доступа и ручную проверку объявлений.
