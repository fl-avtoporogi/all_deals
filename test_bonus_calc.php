<?php
// Тестовый скрипт для проверки работы параметра bonus_calc

echo "=== ТЕСТИРОВАНИЕ УСЛОВНОГО РАСЧЕТА БОНУСОВ ===\n\n";

// Тест 1: С параметром bonus_calc=y
echo "ТЕСТ 1: Вебхук С параметром bonus_calc=y\n";
echo "URL: index.php?deal_id=101827&bonus_calc=y\n";
echo "Ожидаемый результат:\n";
echo "- Расчет бонусов: ВКЛЮЧЕН (bonus_calc=y)\n";
echo "- Используем ПОЛНЫЙ запрос (с бонусами и премиями)\n";
echo "- Бонусы и премия должны быть рассчитаны и сохранены\n\n";

// Тест 2: Без параметра bonus_calc
echo "ТЕСТ 2: Вебхук БЕЗ параметра bonus_calc\n";
echo "URL: index.php?deal_id=101827\n";
echo "Ожидаемый результат:\n";
echo "- Расчет бонусов: ОТКЛЮЧЕН\n";
echo "- Используем ЧАСТИЧНЫЙ запрос (без обновления бонусов и премий)\n";
echo "- Бонусы и премия НЕ должны обновляться\n\n";

// Тест 3: С неверным параметром
echo "ТЕСТ 3: Вебхук с неверным параметром bonus_calc\n";
echo "URL: index.php?deal_id=101827&bonus_calc=n\n";
echo "Ожидаемый результат:\n";
echo "- Расчет бонусов: ОТКЛЮЧЕН\n";
echo "- Используем ЧАСТИЧНЫЙ запрос (без обновления бонусов и премий)\n";
echo "- Бонусы и премия НЕ должны обновляться\n\n";

echo "=== ИНСТРУКЦИЯ ПО ТЕСТИРОВАНИЮ ===\n";
echo "1. Выполните в браузере или через curl:\n";
echo "   curl 'http://9dk.ru/webhooks/avtoporogi/all_deals/index.php?deal_id=101827&bonus_calc=y'\n\n";
echo "2. Проверьте в логе наличие строк:\n";
echo "   'Расчет бонусов: ВКЛЮЧЕН (bonus_calc=y)'\n";
echo "   'Используем ПОЛНЫЙ запрос (с бонусами и премиями)'\n\n";
echo "3. Выполните без параметра:\n";
echo "   curl 'http://9dk.ru/webhooks/avtoporogi/all_deals/index.php?deal_id=101827'\n\n";
echo "4. Проверьте в логе наличие строк:\n";
echo "   'Расчет бонусов: ОТКЛЮЧЕН'\n";
echo "   'Используем ЧАСТИЧНЫЙ запрос (без обновления бонусов и премий)'\n\n";

echo "=== ПОЛЯ, КОТОРЫЕ ЗАВИСЯТ ОТ bonus_calc ===\n";
echo "Обновляются при bonus_calc=y:\n";
echo "- turnover_category_a, turnover_category_b\n";
echo "- bonus_category_a, bonus_category_b\n";
echo "- quantity\n";
echo "- client_bonus, client_bonus_rate\n\n";

echo "Всегда обновляются (независимо от bonus_calc):\n";
echo "- title, funnel_id, funnel_name\n";
echo "- stage_id, stage_name, date_create, closedate\n";
echo "- responsible_id, responsible_name\n";
echo "- department_id, department_name\n";
echo "- opportunity, channel_id, channel_name\n";
echo "- contact_id, contact_responsible_id, contact_responsible_name\n\n";
