<?php
// БД ДЛЯ ВСЕХ СДЕЛОК С РАСЧЕТОМ БОНУСОВ!!!
//https://avtoporogi.bitrix24.ru/company/personal/user/9/tasks/task/view/24611/ - задача
//https://avtoporogi.bitrix24.ru/crm/configs/bp/CRM_DEAL/edit/37/#A66894_66463_78347_39643 - запуск из БП
//https://42b.ru/webhooks/avtoporogi/z51/index.php?deal_id={{ID}} - здесь обновляются данные по товарам
//9dk.ru/webhooks/avtoporogi/all_deals/index.php?deal_id=101827 - тест
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Подключаем CRest
require_once (__DIR__.'/src/crest.php');

// Подключаем конфигурацию базы данных
require_once '../db_connect.php';

// Создаем подключение к базе данных
$mysqli = new mysqli(
    $config['db']['servername'],
    $config['db']['username'],
    $config['db']['password'],
    $config['db']['dbname']
);

// Проверяем подключение
if ($mysqli->connect_error) {
    die("Ошибка подключения к базе данных: " . $mysqli->connect_error . "<br>");
}
echo "Успешно подключились к базе данных.<br>";

// Устанавливаем кодировку соединения
if (!$mysqli->set_charset("utf8mb4")) {
    die("Ошибка установки кодировки: " . htmlspecialchars($mysqli->error) . "<br>");
}

// Функция для выполнения REST-запросов через CRest
function rest($method, $params = []) {
    $response = CRest::call($method, $params);
    if (isset($response['error'])) {
        // Обработка ошибок
        echo "Ошибка: " . htmlspecialchars($response['error_description']) . "<br>";
        exit;
    }
    return $response;
}

// Функция нормализации кода бонуса (защита от кириллицы)
function normalizeBonusCode($code) {
    if (empty($code)) {
        return '';
    }

    // Заменяем кириллические А и В на латинские
    $code = str_replace(['А', 'В', 'а', 'в'], ['A', 'B', 'A', 'B'], $code);

    // Приводим к верхнему регистру и убираем пробелы
    return strtoupper(trim($code));
}

// Функция для загрузки кодов бонусов с кэшированием
function getBonusCodesMap($mysqli) {
    $cacheFile = __DIR__ . '/bonus_codes_cache.json';
    $cacheTime = 3600; // 1 час

    // Проверяем наличие и актуальность кэша
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTime) {
        $cacheContent = file_get_contents($cacheFile);
        $bonusCodesMap = json_decode($cacheContent, true);
        if ($bonusCodesMap) {
            echo "Используем кэшированные коды бонусов.<br>";
            return $bonusCodesMap;
        }
    }

    // Если кэша нет или он устарел, получаем новые данные из БД
    $query = "SELECT code, bonus_amount FROM bonus_codes";
    $result = $mysqli->query($query);

    if (!$result) {
        echo "Ошибка получения кодов бонусов: " . htmlspecialchars($mysqli->error) . "<br>";
        return [];
    }

    $bonusCodesMap = [];
    while ($row = $result->fetch_assoc()) {
        $bonusCodesMap[$row['code']] = floatval($row['bonus_amount']);
    }

    // Сохраняем в кэш
    if (file_put_contents($cacheFile, json_encode($bonusCodesMap))) {
        echo "Коды бонусов закэшированы. Загружено " . count($bonusCodesMap) . " кодов.<br>";
    } else {
        echo "Не удалось сохранить кэш кодов бонусов.<br>";
    }

    return $bonusCodesMap;
}

// Функция для безопасного извлечения значения из свойства
function extractPropertyValue($property, $propertyName = 'свойство') {
    if (empty($property)) {
        return null;
    }

    if (is_array($property)) {
        echo "Анализ структуры {$propertyName}: ";

        // ДЛЯ СПИСОЧНЫХ ПОЛЕЙ: приоритет у valueEnum (текстовое значение)
        if (isset($property['valueEnum']) && !empty($property['valueEnum'])) {
            echo "используем valueEnum = '{$property['valueEnum']}'<br>";
            return $property['valueEnum'];
        }

        // Попытки извлечения значения в порядке приоритета
        if (isset($property['value']) && !empty($property['value'])) {
            echo "используем value = '{$property['value']}'<br>";
            return $property['value'];
        }

        if (isset($property['VALUE']) && !empty($property['VALUE'])) {
            echo "используем VALUE = '{$property['VALUE']}'<br>";
            return $property['VALUE'];
        }

        if (isset($property[0]) && !empty($property[0])) {
            echo "используем [0] = '{$property[0]}'<br>";
            return $property[0];
        }

        echo "не удалось извлечь значение<br>";
        return null;
    }

    return $property;
}

// Функция для получения данных товара из каталога
function getProductFromCatalog($productId) {
    echo "Запрашиваем данные товара {$productId} из каталога...<br>";

    // Сначала пробуем получить товар как продукт
    $result = rest('catalog.product.get', ['id' => $productId]);
    if ($result && isset($result['result']['product'])) {
        echo "Товар найден как продукт<br>";
        return $result['result']['product'];
    }

    // Если не найден как продукт, пробуем как вариацию
    $result = rest('catalog.product.sku.get', ['id' => $productId]);
    if ($result && isset($result['result']['sku'])) {
        echo "Товар найден как вариация (SKU)<br>";
        return $result['result']['sku'];
    }

    echo "Товар не найден в каталоге<br>";
    return null;
}

// Функция для получения товаров сделки с данными из каталога
function getDealProductsWithCatalogData($dealId) {
    $result = rest('crm.deal.productrows.get', ['id' => $dealId]);

    if (!$result['result']) {
        echo "Ошибка получения товаров сделки.<br>";
        return [];
    }

    $products = $result['result'];
    echo "Получено товаров в сделке: " . count($products) . "<br>";

    // Обогащаем каждый товар данными из каталога
    $enrichedProducts = [];
    foreach ($products as $product) {
        $productId = isset($product['PRODUCT_ID']) ? $product['PRODUCT_ID'] : null;

        if ($productId) {
            echo "Обработка товара ID {$productId}: {$product['PRODUCT_NAME']}<br>";

            // Получаем данные из каталога
            $catalogData = getProductFromCatalog($productId);

            if ($catalogData) {
                // Объединяем данные товара из сделки и каталога
                $product['CATALOG_DATA'] = $catalogData;

                // Извлекаем код бонуса из каталога
                $bonusCode = null;
                if (isset($catalogData['property221'])) {
                    $bonusCode = extractPropertyValue($catalogData['property221'], 'property221');
                } elseif (isset($catalogData['PROPERTY_221'])) {
                    $bonusCode = extractPropertyValue($catalogData['PROPERTY_221'], 'PROPERTY_221');
                }

                $product['BONUS_CODE'] = $bonusCode;
                echo "Код бонуса для товара: " . ($bonusCode ? "'{$bonusCode}'" : 'НЕ НАЙДЕН') . "<br>";
            } else {
                echo "Не удалось получить данные товара из каталога<br>";
                $product['BONUS_CODE'] = null;
            }
        } else {
            echo "У товара отсутствует PRODUCT_ID<br>";
            $product['BONUS_CODE'] = null;
        }

        $enrichedProducts[] = $product;
    }

    return $enrichedProducts;
}

// Функция для расчета бонусов и оборотов
function calculateBonusesAndTurnovers($products, $bonusCodesMap) {
    $results = [
        'turnover_category_a' => 0,
        'turnover_category_b' => 0,
        'bonus_category_a' => 0,
        'bonus_category_b' => 0,
        'total_quantity' => 0
    ];

    foreach ($products as $product) {
        $quantity = floatval($product['QUANTITY']);
        $price = floatval($product['PRICE']);
        $bonusCode = isset($product['BONUS_CODE']) ? $product['BONUS_CODE'] : '';

        echo "Обработка товара: {$product['PRODUCT_NAME']}<br>";
        echo "- Количество: {$quantity}<br>";
        echo "- Цена: {$price}<br>";
        echo "- Код бонуса: " . ($bonusCode ? "'{$bonusCode}'" : 'ПУСТОЙ') . "<br>";

        // Если код бонуса пустой, пропускаем товар
        if (empty($bonusCode)) {
            echo "- Товар пропущен: пустой код бонуса<br>";
            continue;
        }

        // Нормализуем код бонуса
        $normalizedCode = normalizeBonusCode($bonusCode);
        if ($normalizedCode !== $bonusCode) {
            echo "- Код нормализован: '{$bonusCode}' -> '{$normalizedCode}'<br>";
        }

        // Определяем категорию по первой букве кода
        $category = substr($normalizedCode, 0, 1);

        // Рассчитываем оборот для данного товара
        $turnover = $quantity * $price;

        // Рассчитываем бонус для данного товара
        $bonusPerUnit = isset($bonusCodesMap[$normalizedCode]) ? $bonusCodesMap[$normalizedCode] : 0;
        $bonus = $quantity * $bonusPerUnit;

        echo "- Бонус за единицу: {$bonusPerUnit}<br>";
        echo "- Оборот товара: {$quantity} × {$price} = {$turnover}<br>";
        echo "- Бонус товара: {$quantity} × {$bonusPerUnit} = {$bonus}<br>";

        // Добавляем к соответствующей категории
        if ($category === 'A') {
            $results['turnover_category_a'] += $turnover;
            $results['bonus_category_a'] += $bonus;
            echo "- Добавлено к категории A<br>";
        } elseif ($category === 'B') {
            $results['turnover_category_b'] += $turnover;
            $results['bonus_category_b'] += $bonus;
            echo "- Добавлено к категории B<br>";
        } else {
            echo "- ВНИМАНИЕ: Неизвестная категория '{$category}' для кода '{$normalizedCode}'<br>";
        }

        $results['total_quantity'] += $quantity;
        echo "- Общее количество увеличено на {$quantity}<br>";
        echo "<br>";
    }

    // Округляем результаты до 2 знаков после запятой
    $results['turnover_category_a'] = round($results['turnover_category_a'], 2);
    $results['turnover_category_b'] = round($results['turnover_category_b'], 2);
    $results['bonus_category_a'] = round($results['bonus_category_a'], 2);
    $results['bonus_category_b'] = round($results['bonus_category_b'], 2);

    echo "=== ИТОГОВЫЕ РАСЧЕТЫ ===<br>";
    echo "Оборот категории A: {$results['turnover_category_a']}<br>";
    echo "Оборот категории B: {$results['turnover_category_b']}<br>";
    echo "Бонус категории A: {$results['bonus_category_a']}<br>";
    echo "Бонус категории B: {$results['bonus_category_b']}<br>";
    echo "Общее количество: {$results['total_quantity']}<br>";
    echo "=======================<br>";

    return $results;
}

// ИСПРАВЛЕННАЯ функция для получения названия стадии по коду
function getStageName($stageId, $categoryId = null) {
    // Определяем ENTITY_ID на основе категории сделки
    if ($categoryId === null || $categoryId == 0) {
        $entityId = 'DEAL_STAGE';
    } else {
        $entityId = 'DEAL_STAGE_' . $categoryId;
    }

    echo "Ищем стадию '{$stageId}' в воронке с ENTITY_ID: '{$entityId}'<br>";

    $result = rest('crm.status.list', ['ENTITY_ID' => $entityId]);

    if ($result['result']) {
        foreach ($result['result'] as $status) {
            // Дополнительная проверка на соответствие ENTITY_ID для исключения попадания других типов
            if ($status['STATUS_ID'] === $stageId && $status['ENTITY_ID'] === $entityId) {
                echo "Найдена стадия: '{$status['NAME']}'<br>";
                return $status['NAME'];
            }
        }
        echo "Стадия не найдена в указанной воронке<br>";
    } else {
        echo "Ошибка получения списка стадий<br>";
    }

    return null;
}

// Функция для получения названия категории сделки (воронки)
function getDealCategoryName($categoryId) {
    $result = rest('crm.category.get', [
        'entityTypeId' => 2, // Идентификатор типа сущности CRM для сделок
        'id' => $categoryId
    ]);

    if ($result['result'] && isset($result['result']['category']['name'])) {
        return $result['result']['category']['name'];
    }
    return null;
}

// Функция для получения имени пользователя по ID
function getUserName($userId) {
    $result = rest('user.get', ['ID' => $userId]);

    if ($result['result'] && isset($result['result'][0])) {
        $user = $result['result'][0];
        return $user['LAST_NAME'] . ' ' . $user['NAME'];
    }
    return null;
}

// Функция для получения информации об отделе пользователя
function getUserDepartment($userId) {
    $result = rest('user.get', ['ID' => $userId]);
    $departmentData = ['id' => null, 'name' => null];

    if ($result['result'] && isset($result['result'][0]['UF_DEPARTMENT']) && is_array($result['result'][0]['UF_DEPARTMENT']) && count($result['result'][0]['UF_DEPARTMENT']) > 0) {
        $departmentId = $result['result'][0]['UF_DEPARTMENT'][0]; // Берем первый отдел пользователя
        $departmentData['id'] = $departmentId;

        // Получаем название отдела
        $deptResult = rest('department.get', ['ID' => $departmentId]);
        if ($deptResult['result'] && isset($deptResult['result'][0]['NAME'])) {
            $departmentData['name'] = $deptResult['result'][0]['NAME'];
        }
    }

    return $departmentData;
}

// Функция для получения метаданных пользовательских полей и создания карты значений с кэшированием
function getUserFieldsMap() {
    $cacheFile = __DIR__ . '/userfields_cache.json';
    $cacheTime = 3600; // 1 час

    // Проверяем наличие и актуальность кэша
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTime) {
        $cacheContent = file_get_contents($cacheFile);
        $fieldIdToValue = json_decode($cacheContent, true);
        if ($fieldIdToValue) {
            echo "Используем кэшированные пользовательские поля.<br>";
            return $fieldIdToValue;
        }
    }

    // Если кэша нет или он устарел, получаем новые данные
    $userFieldsMetadata = rest('crm.deal.userfield.list', []);
    $allUserFields = $userFieldsMetadata['result'];

    $fieldIdToValue = [];

    foreach ($allUserFields as $field) {
        if (isset($field['LIST'])) {
            foreach ($field['LIST'] as $item) {
                $fieldIdToValue[$field['FIELD_NAME']][$item['ID']] = $item['VALUE'];
            }
        }
    }

    // Сохраняем в кэш
    if (file_put_contents($cacheFile, json_encode($fieldIdToValue))) {
        echo "Пользовательские поля закэшированы.<br>";
    } else {
        echo "Не удалось сохранить кэш пользовательских полей.<br>";
    }

    return $fieldIdToValue;
}

// Функция для получения значения поля списка по ID
function getListFieldValue($fieldName, $valueId, $fieldIdToValueMap) {
    if (empty($valueId) || empty($fieldIdToValueMap[$fieldName])) {
        return null;
    }

    return isset($fieldIdToValueMap[$fieldName][$valueId])
        ? $fieldIdToValueMap[$fieldName][$valueId]
        : null;
}

// Функция для преобразования даты из ISO8601 в формат MySQL DATE
function convertToMySQLDate($dateStr) {
    if (empty($dateStr)) return null;
    try {
        // Выводим исходное значение даты для отладки
        echo "Исходная дата: " . htmlspecialchars($dateStr) . "<br>";

        // Проверяем формат даты
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
            // Если формат уже YYYY-MM-DD, возвращаем его без изменений
            echo "Дата уже в формате Y-m-d: " . htmlspecialchars($dateStr) . "<br>";
            return $dateStr;
        }

        // Для Битрикс24 даты часто в формате YYYY-MM-DDThh:mm:ss±hh:mm
        $dt = new DateTime($dateStr);
        $formattedDate = $dt->format('Y-m-d');
        echo "Преобразованная дата: " . htmlspecialchars($formattedDate) . "<br>";
        return $formattedDate;
    } catch (Exception $e) {
        echo "Ошибка преобразования даты: " . htmlspecialchars($e->getMessage()) . "<br>";
        return null;
    }
}

// ==================== ОСНОВНАЯ ЛОГИКА ====================

// Получение deal_id из входящего запроса
if (isset($_REQUEST['deal_id']) && is_numeric($_REQUEST['deal_id'])) {
    $dealId = intval($_REQUEST['deal_id']);
    echo "Получен deal_id: " . htmlspecialchars($dealId) . "<br>";
} else {
    echo "Некорректный или отсутствующий параметр deal_id.<br>";
    exit;
}

// Получение данных сделки
$dealResult = rest('crm.deal.get', ['ID' => $dealId]);

// Проверяем успешность запроса
if (!$dealResult['result']) {
    echo "Ошибка получения данных сделки.<br>";
    exit;
}

echo "Данные сделки успешно получены.<br>";

// Получаем данные сделки
$deal = $dealResult['result'];

// Получаем товары сделки с данными из каталога
$products = getDealProductsWithCatalogData($dealId);

// Получаем коды бонусов
$bonusCodesMap = getBonusCodesMap($mysqli);

// Рассчитываем бонусы и обороты
$calculations = calculateBonusesAndTurnovers($products, $bonusCodesMap);

// Получаем карту пользовательских полей
$fieldIdToValueMap = getUserFieldsMap();

// Извлекаем необходимые поля
$categoryId = isset($deal['CATEGORY_ID']) ? $deal['CATEGORY_ID'] : null;
$stageId = isset($deal['STAGE_ID']) ? $deal['STAGE_ID'] : null;
$responsibleId = isset($deal['ASSIGNED_BY_ID']) ? $deal['ASSIGNED_BY_ID'] : null;

// НОВОЕ: Получаем значение канала
$channelId = isset($deal['UF_CRM_1698142542036']) ? $deal['UF_CRM_1698142542036'] : null;
$channelName = null;

if ($channelId) {
    // Если это массив (может быть в некоторых случаях), берем первый элемент
    if (is_array($channelId)) {
        $channelId = $channelId[0];
    }

    $channelName = getListFieldValue('UF_CRM_1698142542036', $channelId, $fieldIdToValueMap);
    echo "Канал ID: " . htmlspecialchars($channelId) . ", Название: " . htmlspecialchars($channelName ?: 'не найдено') . "<br>";
}

// Выводим для отладки информацию о дате создания
echo "DATE_CREATE в данных сделки: " . (isset($deal['DATE_CREATE']) ? htmlspecialchars($deal['DATE_CREATE']) : 'не определено') . "<br>";

// Получаем название направления и стадии
$categoryName = getDealCategoryName($categoryId);
// ИЗМЕНЕНО: Передаем categoryId в функцию getStageName
$stageName = getStageName($stageId, $categoryId);

// Получаем имя ответственного
$responsibleName = $responsibleId ? getUserName($responsibleId) : null;

// Получаем информацию об отделе ответственного
$departmentData = $responsibleId ? getUserDepartment($responsibleId) : ['id' => null, 'name' => null];

// Форматируем общую сумму сделки с двумя знаками после запятой
$opportunityAmount = isset($deal['OPPORTUNITY']) ? number_format((float)$deal['OPPORTUNITY'], 2, '.', '') : null;

// Создаем массив с необходимыми данными
$dealData = [
    'deal_id' => $deal['ID'], // Ключевое поле
    'title' => $deal['TITLE'], // Название сделки
    'funnel_id' => $categoryId, // ID воронки (направления)
    'funnel_name' => $categoryName, // Название воронки
    'stage_id' => $stageId, // ID стадии
    'stage_name' => $stageName, // Название стадии
    'date_create' => convertToMySQLDate($deal['DATE_CREATE']), // Дата создания сделки
    'responsible_id' => $responsibleId, // ID ответственного
    'responsible_name' => $responsibleName, // Имя ответственного
    'department_id' => $departmentData['id'], // ID отдела
    'department_name' => $departmentData['name'], // Название отдела
    'opportunity' => $opportunityAmount, // Общая сумма сделки
    'quantity' => $calculations['total_quantity'], // Общее количество товаров (рассчитанное)
    'turnover_category_a' => $calculations['turnover_category_a'], // Оборот по категории товаров A (рассчитанный)
    'turnover_category_b' => $calculations['turnover_category_b'], // Оборот по категории товаров B (рассчитанный)
    'bonus_category_a' => $calculations['bonus_category_a'], // Бонус по категории товаров A (рассчитанный)
    'bonus_category_b' => $calculations['bonus_category_b'], // Бонус по категории товаров B (рассчитанный)
    'channel_id' => $channelId, // ID канала
    'channel_name' => $channelName, // Название канала
];

// Выводим результат (для отладки, можно убрать в продакшене)
echo '<pre>';
print_r($dealData);
echo '</pre>';

// Подготовка SQL-запроса для вставки данных с использованием подготовленных выражений
echo "Подготовлено выражение для вставки.<br>";

$stmt = $mysqli->prepare("INSERT INTO all_deals (
    deal_id,
    title, 
    funnel_id, 
    funnel_name, 
    stage_id, 
    stage_name, 
    date_create, 
    responsible_id, 
    responsible_name, 
    department_id, 
    department_name, 
    opportunity, 
    quantity,
    turnover_category_a,
    turnover_category_b,
    bonus_category_a,
    bonus_category_b,
    channel_id,
    channel_name
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
ON DUPLICATE KEY UPDATE
    title = VALUES(title),
    funnel_id = VALUES(funnel_id),
    funnel_name = VALUES(funnel_name),
    stage_id = VALUES(stage_id),
    stage_name = VALUES(stage_name),
    date_create = VALUES(date_create),
    responsible_id = VALUES(responsible_id),
    responsible_name = VALUES(responsible_name),
    department_id = VALUES(department_id),
    department_name = VALUES(department_name),
    opportunity = VALUES(opportunity),
    quantity = VALUES(quantity),
    turnover_category_a = VALUES(turnover_category_a),
    turnover_category_b = VALUES(turnover_category_b),
    bonus_category_a = VALUES(bonus_category_a),
    bonus_category_b = VALUES(bonus_category_b),
    channel_id = VALUES(channel_id),
    channel_name = VALUES(channel_name)
");

if (!$stmt) {
    die("Ошибка подготовки запроса: " . htmlspecialchars($mysqli->error) . "<br>");
}

echo "Выражение успешно подготовлено.<br>";

// Привязываем параметры
$bind = $stmt->bind_param(
    "isisssissisddddddis", // i - integer, s - string, d - double (19 спецификаторов)
    $dealData['deal_id'],                // 1. i
    $dealData['title'],                  // 2. s
    $dealData['funnel_id'],              // 3. i
    $dealData['funnel_name'],            // 4. s
    $dealData['stage_id'],               // 5. s
    $dealData['stage_name'],             // 6. s
    $dealData['date_create'],            // 7. s
    $dealData['responsible_id'],         // 8. i
    $dealData['responsible_name'],       // 9. s
    $dealData['department_id'],          // 10. i
    $dealData['department_name'],        // 11. s
    $dealData['opportunity'],            // 12. d
    $dealData['quantity'],               // 13. d
    $dealData['turnover_category_a'],    // 14. d
    $dealData['turnover_category_b'],    // 15. d
    $dealData['bonus_category_a'],       // 16. d
    $dealData['bonus_category_b'],       // 17. d
    $dealData['channel_id'],             // 18. i
    $dealData['channel_name']            // 19. s
);

if (!$bind) {
    die("Ошибка привязки параметров: " . htmlspecialchars($stmt->error) . "<br>");
}

echo "Параметры успешно привязаны.<br>";

// Выполнение запроса
if ($stmt->execute()) {
    echo "Данные успешно вставлены или обновлены.<br>";

    // Проверяем фактические данные, записанные в БД
    $checkQuery = "SELECT date_create FROM all_deals WHERE deal_id = {$dealData['deal_id']}";
    $checkResult = $mysqli->query($checkQuery);
    if ($checkResult) {
        $row = $checkResult->fetch_assoc();
        echo "Дата в базе данных: " . htmlspecialchars($row['date_create']) . "<br>";
    }

    // Если дата в БД неверна, попробуем прямое обновление
    if (isset($row['date_create']) && $row['date_create'] == '0000-00-00') {
        echo "Исправляем дату прямым SQL-запросом...<br>";
        $directUpdateQuery = "UPDATE all_deals SET date_create = '{$dealData['date_create']}' WHERE deal_id = {$dealData['deal_id']}";
        if ($mysqli->query($directUpdateQuery)) {
            echo "Дата успешно обновлена прямым SQL-запросом.<br>";
        } else {
            echo "Ошибка при прямом обновлении даты: " . htmlspecialchars($mysqli->error) . "<br>";
        }
    }
} else {
    echo "Ошибка выполнения запроса: " . htmlspecialchars($stmt->error) . "<br>";
}

// Закрываем подготовленное выражение и соединение
$stmt->close();
$mysqli->close();
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Обработка сделки Битрикс24</title>
    <style>
        .logo {
            position: absolute;
            top: 10px;
            right: 10px;
            font-family: Arial, sans-serif;
            font-size: 12px;
            color: #707070;
        }
        .logo a {
            color: #707070;
            text-decoration: none;
        }
        .logo a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
<div class="logo"><a href="https://4ias.ru" target="_blank">4ias.ru</a></div>
</body>
</html>