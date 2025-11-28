# Исправление ошибки bind_param в index.php

## Проблема
Фатальная ошибка `ArgumentCountError: The number of elements in the type definition string must match the number of bind variables` возникает из-за неправильных строк типов в вызовах `bind_param`.

## Текущее состояние
- **Полный запрос (с бонусами)**: строка 711 имеет неправильную строку типов
- **Частичный запрос (без бонусов)**: строка 753 имеет неправильную строку типов

## Исправления

### 1. Полный запрос (строка 711)
**Найти:**
```php
$paramTypes = "isisssssissddddddisiiisdd"; // 25 параметров
```

**Заменить на:**
```php
$paramTypes = "isisssssisisddddddisiisdd"; // 25 параметров
```

### 2. Частичный запрос (строка 753)
**Найти:**
```php
$paramTypes = "isisssssisisdisii s"; // 18 параметров
```

**Заменить на:**
```php
$paramTypes = "isisssssisisdisii s"; // 18 параметров
```

**Внимание:** Убедитесь, что в строке типов нет лишних пробелов. Должно быть ровно 18 символов для частичного запроса.

## Проверка типов

### Полный запрос (25 параметров):
```
i s i s s s s s i s i s d d d d d d i s i i s d d
1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20 21 22 23 24 25
```

### Частичный запрос (18 параметров):
```
i s i s s s s s i s i s d i s i i s
1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18
```

## Действия
1. Откройте файл `index.php` на сервере
2. Примените указанные исправления
3. Сохраните файл
4. Протестируйте на URL: `https://9dk.ru/webhooks/avtoporogi/all_deals/index.php?deal_id=55`

## Переменные для проверки

### Частичный запрос:
1. deal_id (i)
2. title (s)
3. funnel_id (i)
4. funnel_name (s)
5. stage_id (s)
6. stage_name (s)
7. date_create (s)
8. closedate (s)
9. responsible_id (i)
10. responsible_name (s)
11. department_id (i)
12. department_name (s)
13. opportunity (d)
14. channel_id (i)
15. channel_name (s)
16. contact_id (i)
17. contact_responsible_id (i)
18. contact_responsible_name (s)