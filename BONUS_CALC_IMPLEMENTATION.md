# Реализация условного расчета бонусов по параметру bonus_calc

## Обзор

Система была модифицирована для поддержки условного расчета бонусов на основе параметра `bonus_calc=y`. Это позволяет бизнес-процессу Bitrix24 контролировать, когда нужно пересчитывать бонусы и премии в зависимости от стадии сделки.

## Функционал

### Параметр вебхука
- **С параметром**: `https://9dk.ru/webhooks/avtoporogi/all_deals/index.php?deal_id={{ID}}&bonus_calc=y`
  - Рассчитываются и обновляются все бонусы и премии
- **Без параметра**: `https://9dk.ru/webhooks/avtoporogi/all_deals/index.php?deal_id={{ID}}`
  - Бонусы и премии НЕ обновляются, сохраняются текущие значения

### Поля, зависящие от параметра bonus_calc

**Обновляются ТОЛЬКО при bonus_calc=y:**
- `turnover_category_a` - оборот по категории товаров A
- `turnover_category_b` - оборот по категории товаров B
- `bonus_category_a` - бонус по категории товаров A
- `bonus_category_b` - бонус по категории товаров B
- `quantity` - общее количество товаров
- `client_bonus` - премия за клиента
- `client_bonus_rate` - коэффициент премии

**Всегда обновляются (независимо от bonus_calc):**
- `title`, `funnel_id`, `funnel_name`
- `stage_id`, `stage_name`, `date_create`, `closedate`
- `responsible_id`, `responsible_name`
- `department_id`, `department_name`
- `opportunity`, `channel_id`, `channel_name`
- `contact_id`, `contact_responsible_id`, `contact_responsible_name`

## Техническая реализация

### 1. Проверка параметра
```php
$calculateBonuses = isset($_GET['bonus_calc']) && $_GET['bonus_calc'] === 'y';
```

### 2. Условный расчет бонусов
```php
if ($calculateBonuses) {
    // Полный расчет бонусов и оборотов
    $products = getDealProductsWithCatalogData($dealId);
    $bonusCodesMap = getBonusCodesMap($mysqli);
    $calculations = calculateBonusesAndTurnovers($products, $bonusCodesMap);
    $clientBonusRate = getCurrentClientBonusRate($mysqli);
    $clientBonus = $opportunityAmount * $clientBonusRate;
} else {
    // Используем нулевые значения
    $calculations = [...нули...];
    $clientBonusRate = 0;
    $clientBonus = 0.00;
}
```

### 3. Два SQL-запроса

**ПОЛНЫЙ запрос (с бонусами)** - 25 параметров:
```sql
INSERT INTO all_deals (
    deal_id, title, funnel_id, funnel_name, stage_id, stage_name,
    date_create, closedate, responsible_id, responsible_name,
    department_id, department_name, opportunity,
    quantity, turnover_category_a, turnover_category_b,
    bonus_category_a, bonus_category_b, channel_id, channel_name,
    contact_id, contact_responsible_id, contact_responsible_name,
    client_bonus, client_bonus_rate
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
ON DUPLICATE KEY UPDATE [...все поля...]
```

**ЧАСТИЧНЫЙ запрос (без бонусов)** - 18 параметров:
```sql
INSERT INTO all_deals (
    deal_id, title, funnel_id, funnel_name, stage_id, stage_name,
    date_create, closedate, responsible_id, responsible_name,
    department_id, department_name, opportunity,
    channel_id, channel_name, contact_id, contact_responsible_id, contact_responsible_name
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
ON DUPLICATE KEY UPDATE [...без бонусных полей...]
```

## Интеграция с бизнес-процессом Bitrix24

### Настройка в бизнес-процессе:

1. **Добавить условие по стадии сделки**
   ```
   Если стадия сделки = [подходящие стадии]
   ```

2. **Добавить действие вызова вебхука**
   ```
   URL: https://9dk.ru/webhooks/avtoporogi/all_deals/index.php?deal_id={{ID}}&bonus_calc=y
   ```

3. **Добавить действие для других стадий**
   ```
   URL: https://9dk.ru/webhooks/avtoporogi/all_deals/index.php?deal_id={{ID}}
   ```

## Преимущества

### 1. Гибкость
- Можно легко изменить стадии для расчета бонусов в Bitrix24 без изменения кода
- Поддержка любых комбинаций стадий

### 2. Производительность
- На "неподходящих" стадиях не выполняется тяжелый расчет бонусов
- Не запрашиваются товары сделки и коды бонусов
- Оптимизированные SQL-запросы

### 3. Обратная совместимость
- Старые вебхуки продолжают работать
- По умолчанию бонусы не пересчитываются (безопасно)

### 4. Точность данных
- Бонусы не перезаписываются случайно
- Сохраняется история расчетов

## Тестирование

Файл `test_bonus_calc.php` содержит инструкции по тестированию:

### Тест 1: С параметром bonus_calc=y
```bash
curl 'http://9dk.ru/webhooks/avtoporogi/all_deals/index.php?deal_id=101827&bonus_calc=y'
```
Ожидаемые логи:
- `Расчет бонусов: ВКЛЮЧЕН (bonus_calc=y)`
- `Используем ПОЛНЫЙ запрос (с бонусами и премиями)`

### Тест 2: Без параметра bonus_calc
```bash
curl 'http://9dk.ru/webhooks/avtoporogi/all_deals/index.php?deal_id=101827'
```
Ожидаемые логи:
- `Расчет бонусов: ОТКЛЮЧЕН`
- `Используем ЧАСТИЧНЫЙ запрос (без обновления бонусов и премий)`

## Версия и изменения

- **Версия**: 2025-11-28 BONUS_CALC-v1
- **Изменения**: 245 добавлений, 94 удаления
- **Файлы изменены**: `index.php`, `test_bonus_calc.php`
- **Совместимость**: Полная обратная совместимость

## Рекомендации по использованию

1. **Настройте стадии** в бизнес-процессе Bitrix24, которые должны触发 расчет бонусов
2. **Протестируйте** на нескольких сделках с разными стадиями
3. **Мониторьте** логи для проверки правильной работы
4. **Используйте** `bonus_calc=y` только для критически важных стадий
5. **Учитывайте**, что премия за клиента также зависит от этого параметра

## Поддержка

При возникновении вопросов:
1. Проверьте логи выполнения вебхука
2. Убедитесь, что параметр `bonus_calc=y` передается правильно
3. Проверьте настройки бизнес-процесса в Bitrix24
4. Используйте `test_bonus_calc.php` для диагностики
