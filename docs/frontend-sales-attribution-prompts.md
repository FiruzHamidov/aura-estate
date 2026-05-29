# Prompts For Frontend Team (Sales Attribution Update)

Дата: 2026-05-29

## Prompt 1: Core Refactor

```
Обнови фронтенд KPI/Reports под новый backend contract:

1) Поля sold, rented, closed, sales_count, deals_count теперь могут быть decimal (float).
2) Убери int-cast в sales-контекстах (parseInt, Math.floor, toFixed(0), побитовые преобразования).
3) Добавь единый formatter:
   - вход: number | null | undefined
   - выход: строка до 2 знаков после запятой
   - trim trailing zeros (1.00 -> 1, 1.50 -> 1.5).
4) Примени formatter в:
   - manager efficiency,
   - agents leaderboard,
   - agent earnings,
   - KPI day/week/month sales blocks.
5) Обнови сортировки таблиц/чартов на numeric float compare.
6) Добавь tooltip:
   - "Совместные сделки делятся между участниками поровну (1/N)."
   - "Фильтр по агенту в продажах = продавец (атрибуция на backend)."
7) Не менять endpoint paths и response keys.

Сделай diff минимальным и безопасным, без изменения non-sales метрик.
```

## Prompt 2: Test Coverage

```
Добавь/обнови тесты фронтенда для sales attribution:

1) shared sale, 2 участника -> отображается 0.5
2) shared rent, 3 участника -> отображается 0.33 (или 0.3333 по локальной политике)
3) non-shared sale -> отображается 1
4) сортировка: 1.5 > 1.25 > 1.0 > 0.5
5) нет NaN/глитчей для 0, 0.5, 0.3333
6) KPI и manager efficiency дают совместимые значения на одинаковых фильтрах
7) export/CSV/Excel сохраняет дробную часть (не округлять до int)

Покажи список измененных тестов и короткое объяснение почему каждый нужен.
```

## Prompt 3: QA Regression Pass

```
Сделай QA pass по отчетам/KPI после поддержки дробных sales-метрик:

- Проверить UI:
  - manager efficiency
  - agents leaderboard
  - agent earnings
  - KPI daily/weekly/monthly
- Проверить фильтры:
  - agent_id
  - branch_id
  - branch_group_id
- Проверить экспорты:
  - CSV/Excel значения sales не теряют дробную часть
- Проверить тексты:
  - есть подсказка про 1/N
  - есть подсказка про смысл фильтра agent_id в продажах

Вывести результат в формате:
- OK блоки
- Найденные проблемы
- Риски перед релизом
```
