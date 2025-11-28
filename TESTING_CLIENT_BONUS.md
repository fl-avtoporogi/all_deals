# Инструкция по внедрению и тестированию "Премия за клиента"

## Текущий статус

✅ **Код обновлен** - все изменения в `index.php` внесены
⏳ **БД не обновлена** - необходимо выполнить миграцию
⏳ **Не протестировано** - требуется тестирование

## Шаг 1: Миграция БД (обязательно!)

**ВАЖНО:** Выполните SQL-миграцию через phpMyAdmin перед тестированием!

1. Откройте файл `migration_client_bonus.sql`
2. Скопируйте содержимое
3. Откройте phpMyAdmin → выберите БД `j0941615_avtop`
4. Перейдите в раздел SQL
5. Вставьте скрипт и выполните

**Что делает миграция:**
- Создает резервную копию таблицы `all_deals_backup_20251128`
- Добавляет 5 новых колонок в таблицу `all_deals`
- Создает индексы для оптимизации

## Шаг 2: Проверка структуры БД

После выполнения миграции проверьте:

```sql
DESCRIBE all_deals;
```

Должны быть следующие новые колонки:
- `contact_id` INT(11)
- `contact_responsible_id` INT(11)
- `contact_responsible_name` VARCHAR(255)
- `client_bonus` DECIMAL(15,2) DEFAULT 0.00
- `client_bonus_rate` DECIMAL(5,4) DEFAULT 0.0500

## Шаг 3: Тестирование

### Тест 1: Сделка с контактом

1. Найдите сделку в Битрикс24, у которой есть контакт
2. Запустите webhook:

```
https://9dk.ru/webhooks/avtoporogi/all_deals/index.php?deal_id=XXXX
```

(замените XXXX на реальный ID сделки)

3. Проверьте результат:

```sql
SELECT
    deal_id,
    title,
    opportunity,
    responsible_name,
    contact_id,
    contact_responsible_name,
    client_bonus,
    client_bonus_rate
FROM all_deals
WHERE deal_id = XXXX;
```

**Ожидаемый результат:**
- `contact_id` - заполнен (не NULL)
- `contact_responsible_name` - имя ответственного за контакт
- `client_bonus` = `opportunity` × 0.05
- `client_bonus_rate` = 0.0500

### Тест 2: Сделка без контакта

1. Найдите сделку БЕЗ контакта
2. Запустите webhook для этой сделки
3. Проверьте результат:

**Ожидаемый результат:**
- `contact_id` = NULL
- `contact_responsible_id` = NULL
- `contact_responsible_name` = NULL
- `client_bonus` = 0.00 (или сумма × 5%, даже если нет ответственного)
- `client_bonus_rate` = 0.0500

### Тест 3: Проверка расчета

Пример: Если сделка на 10,000 рублей:
- `opportunity` = 10000.00
- `client_bonus` должен быть = 500.00 (10000 × 0.05)

## Шаг 4: Настройка отчета в Yandex DataLens

После успешного тестирования настройте отчет.

### Простой запрос для проверки данных:

```sql
SELECT
    responsible_name as manager,
    COUNT(deal_id) as deals_count,
    SUM(opportunity) as total_amount,
    SUM(bonus_category_a) as bonus_a,
    SUM(bonus_category_b) as bonus_b,
    0 as client_bonus
FROM all_deals
WHERE responsible_id IS NOT NULL
GROUP BY responsible_id, responsible_name

UNION ALL

SELECT
    contact_responsible_name as manager,
    0 as deals_count,
    0 as total_amount,
    0 as bonus_a,
    0 as bonus_b,
    SUM(client_bonus) as client_bonus
FROM all_deals
WHERE contact_responsible_id IS NOT NULL
GROUP BY contact_responsible_id, contact_responsible_name

ORDER BY manager;
```

### Создание VIEW (опционально):

```sql
CREATE VIEW manager_bonuses AS
SELECT
    responsible_id as manager_id,
    responsible_name as manager_name,
    SUM(opportunity) as total_deals_amount,
    COUNT(deal_id) as deals_count,
    SUM(bonus_category_a) as total_bonus_a,
    SUM(bonus_category_b) as total_bonus_b,
    0 as total_client_bonus
FROM all_deals
WHERE responsible_id IS NOT NULL
GROUP BY responsible_id, responsible_name

UNION ALL

SELECT
    contact_responsible_id as manager_id,
    contact_responsible_name as manager_name,
    0 as total_deals_amount,
    0 as deals_count,
    0 as total_bonus_a,
    0 as total_bonus_b,
    SUM(client_bonus) as total_client_bonus
FROM all_deals
WHERE contact_responsible_id IS NOT NULL
GROUP BY contact_responsible_id, contact_responsible_name;
```

## Возможные проблемы и решения

### Проблема 1: Ошибка SQL при запуске webhook

**Причина:** Миграция БД не выполнена
**Решение:** Выполните `migration_client_bonus.sql` в phpMyAdmin

### Проблема 2: contact_id всегда NULL

**Причина:** В сделках Битрикс24 не указаны контакты
**Решение:** Это нормальное поведение. Проверьте в Битрикс24, действительно ли у сделки есть контакт

### Проблема 3: Неверный расчет client_bonus

**Причина:** Ошибка в логике
**Решение:** Проверьте код в [index.php:569](index.php#L569)

## Откат изменений

### Откат кода (через git):

```bash
git reset --hard before-client-bonus-feature
```

### Откат БД:

```sql
-- Удалить новые колонки
ALTER TABLE all_deals
DROP COLUMN contact_id,
DROP COLUMN contact_responsible_id,
DROP COLUMN contact_responsible_name,
DROP COLUMN client_bonus,
DROP COLUMN client_bonus_rate;

-- Или восстановить из backup
DROP TABLE all_deals;
RENAME TABLE all_deals_backup_20251128 TO all_deals;
```

## Полезные SQL-запросы

### Посмотреть все премии за клиента:

```sql
SELECT
    deal_id,
    title,
    opportunity,
    contact_responsible_name,
    client_bonus,
    CONCAT(ROUND(client_bonus_rate * 100, 2), '%') as rate
FROM all_deals
WHERE contact_responsible_id IS NOT NULL
ORDER BY client_bonus DESC
LIMIT 20;
```

### Посмотреть менеджеров с премиями:

```sql
SELECT
    contact_responsible_name,
    COUNT(*) as deals_with_contacts,
    SUM(client_bonus) as total_client_bonus
FROM all_deals
WHERE contact_responsible_id IS NOT NULL
GROUP BY contact_responsible_id, contact_responsible_name
ORDER BY total_client_bonus DESC;
```

## Следующие шаги

1. ✅ Выполнить миграцию БД
2. ✅ Протестировать на тестовой сделке
3. ✅ Проверить корректность расчетов
4. ✅ Настроить отчет в DataLens
5. ⏳ Обновить исторические данные (опционально)
6. ⏳ Удалить backup таблицу после проверки

---

**Дата создания:** 2025-11-28
**Версия:** 1.0
**Git тег:** `before-client-bonus-feature` (для отката)
