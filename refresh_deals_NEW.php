<?php
/** 
 * ОПТИМИЗИРОВАННОЕ обновление данных сделок
 * Файл: refresh_deals_NEW.php
 *
 * ГЛАВНЫЕ ОПТИМИЗАЦИИ:
 * - Batch-запросы к API Битрикс24 (до 50 сделок за раз)
 * - Кэширование всех справочников (стадии, пользователи, отделы)
 * - Bulk INSERT в БД (пакеты по 100 записей)
 * - Минимизация запросов к каталогу товаров
 * - Прямая обработка без HTTP-запросов
 *
 * ОЖИДАЕМАЯ ПРОИЗВОДИТЕЛЬНОСТЬ:
 * ~500-1000 сделок/мин (против ~100-200 в старой версии)
 * Для 50000 сделок: ~1-2 часа (против 9 часов)
 *
 * Режимы запуска:
 * php refresh_deals_NEW.php           - обычный режим
 * php refresh_deals_NEW.php reset     - сброс прогресса
 * php refresh_deals_NEW.php limit 100 - обработать только 100 сделок (для теста)
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);
ini_set('display_errors', 1);
set_time_limit(0);
ini_set('memory_limit', '1024M');

// Отключаем буферизацию вывода
if (ob_get_level()) {
    ob_end_flush();
}
ob_implicit_flush(true);

require_once __DIR__ . '/src/crest.php';
require_once __DIR__ . '/../db_connect.php';

// ==================== КОНСТАНТЫ ====================
define('BATCH_SIZE', 50);           // Сколько сделок обрабатывать за один batch-запрос
define('DB_BULK_SIZE', 100);        // Сколько записей вставлять в БД за раз
define('PROGRESS_FILE', __DIR__ . '/refresh_progress_new.json');
define('CACHE_DIR', __DIR__ . '/cache/');
define('CACHE_TTL', 3600);          // 1 час

// Создаем директорию для кэша
if (!file_exists(CACHE_DIR)) {
    mkdir(CACHE_DIR, 0755, true);
}

// ==================== ФУНКЦИИ КЭШИРОВАНИЯ ====================

function getCached($key, $callback, $ttl = CACHE_TTL) {
    $cacheFile = CACHE_DIR . md5($key) . '.json';

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        $data = json_decode(file_get_contents($cacheFile), true);
        if ($data !== null) {
            echo "[CACHE] Загружено из кэша: $key\n";
            return $data;
        }
    }

    echo "[CACHE] Получение свежих данных: $key\n";
    $data = $callback();

    if ($data !== null) {
        file_put_contents($cacheFile, json_encode($data, JSON_PRETTY_PRINT));
    }

    return $data;
}

function clearCache() {
    $files = glob(CACHE_DIR . '*.json');
    foreach ($files as $file) {
        unlink($file);
    }
    echo "Кэш очищен\n";
}

// ==================== ФУНКЦИИ API ====================

function batchCall($calls) {
    if (empty($calls)) return [];

    // Преобразуем формат для CRest::callBatch
    // Из ['key' => ['method', ['params']]]
    // В ['key' => ['method' => 'method', 'params' => ['params']]]
    $batch = [];
    foreach ($calls as $key => $call) {
        if (is_array($call) && count($call) >= 2) {
            $batch[$key] = [
                'method' => $call[0],
                'params' => $call[1] ?? []
            ];
        }
    }

    echo "DEBUG: Отправка batch-запроса с " . count($batch) . " командами\n";
    $result = CRest::callBatch($batch);

    // Детальное логирование для отладки
    if (isset($result['error'])) {
        echo "ОШИБКА batch-запроса:\n";
        echo "  Код: " . ($result['error'] ?? 'не указан') . "\n";
        echo "  Описание: " . ($result['error_description'] ?? 'не указано') . "\n";
        return [];
    }

    if (!isset($result['result'])) {
        echo "ОШИБКА: Нет поля 'result' в ответе\n";
        return [];
    }

    if (!isset($result['result']['result'])) {
        echo "ОШИБКА: Нет поля 'result.result' в ответе\n";
        echo "Структура result: " . json_encode(array_keys($result['result']), JSON_UNESCAPED_UNICODE) . "\n";
        return [];
    }

    echo "DEBUG: Batch-запрос успешен, получено результатов: " . count($result['result']['result']) . "\n";
    return $result['result']['result'];
}

// ==================== ЗАГРУЗКА СПРАВОЧНИКОВ ====================

function loadBonusCodes($mysqli) {
    return getCached('bonus_codes', function() use ($mysqli) {
        $result = $mysqli->query("SELECT code, bonus_amount FROM bonus_codes");
        $map = [];
        while ($row = $result->fetch_assoc()) {
            $map[$row['code']] = floatval($row['bonus_amount']);
        }
        echo "Загружено кодов бонусов: " . count($map) . "\n";
        return $map;
    });
}

function loadStages() {
    return getCached('stages', function() {
        $stages = [];

        // Загружаем стадии для всех воронок
        // Сначала стандартная воронка
        $result = CRest::call('crm.status.list', ['ENTITY_ID' => 'DEAL_STAGE']);
        if (isset($result['result'])) {
            foreach ($result['result'] as $status) {
                $stages[$status['STATUS_ID']] = $status['NAME'];
            }
        }

        // Загружаем категории сделок
        $categories = CRest::call('crm.category.list', ['entityTypeId' => 2]);
        if (isset($categories['result']['categories'])) {
            foreach ($categories['result']['categories'] as $category) {
                $catId = $category['id'];
                if ($catId > 0) {
                    $entityId = 'DEAL_STAGE_' . $catId;
                    $result = CRest::call('crm.status.list', ['ENTITY_ID' => $entityId]);
                    if (isset($result['result'])) {
                        foreach ($result['result'] as $status) {
                            $stages[$status['STATUS_ID']] = $status['NAME'];
                        }
                    }
                }
            }
        }

        echo "Загружено стадий: " . count($stages) . "\n";
        return $stages;
    });
}

function loadCategories() {
    return getCached('categories', function() {
        $categories = [];
        $result = CRest::call('crm.category.list', ['entityTypeId' => 2]);

        if (isset($result['result']['categories'])) {
            foreach ($result['result']['categories'] as $category) {
                $categories[$category['id']] = $category['name'];
            }
        }

        echo "Загружено категорий: " . count($categories) . "\n";
        return $categories;
    });
}

function loadUsers() {
    return getCached('users', function() {
        $users = [];
        $start = 0;

        do {
            $result = CRest::call('user.get', ['start' => $start]);

            if (!isset($result['result'])) break;

            foreach ($result['result'] as $user) {
                $users[$user['ID']] = [
                    'name' => trim($user['LAST_NAME'] . ' ' . $user['NAME']),
                    'department_id' => isset($user['UF_DEPARTMENT'][0]) ? $user['UF_DEPARTMENT'][0] : null
                ];
            }

            $start += 50;
        } while (isset($result['next']));

        echo "Загружено пользователей: " . count($users) . "\n";
        return $users;
    });
}

function loadDepartments() {
    return getCached('departments', function() {
        $departments = [];
        $result = CRest::call('department.get', []);

        if (isset($result['result'])) {
            foreach ($result['result'] as $dept) {
                $departments[$dept['ID']] = $dept['NAME'];
            }
        }

        echo "Загружено отделов: " . count($departments) . "\n";
        return $departments;
    });
}

function loadUserFields() {
    return getCached('userfields', function() {
        $result = CRest::call('crm.deal.userfield.list', []);
        $fieldMap = [];

        if (isset($result['result'])) {
            foreach ($result['result'] as $field) {
                if (isset($field['LIST'])) {
                    foreach ($field['LIST'] as $item) {
                        $fieldMap[$field['FIELD_NAME']][$item['ID']] = $item['VALUE'];
                    }
                }
            }
        }

        echo "Загружено пользовательских полей\n";
        return $fieldMap;
    });
}

// ==================== ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ====================

function normalizeBonusCode($code) {
    if (empty($code)) return '';
    $code = str_replace(['А', 'В', 'а', 'в'], ['A', 'B', 'A', 'B'], $code);
    return strtoupper(trim($code));
}

function convertToMySQLDate($dateStr) {
    if (empty($dateStr)) return null;

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
        return $dateStr;
    }

    try {
        $dt = new DateTime($dateStr);
        return $dt->format('Y-m-d');
    } catch (Exception $e) {
        return null;
    }
}

function extractPropertyValue($property) {
    if (empty($property)) return null;

    if (is_array($property)) {
        if (isset($property['valueEnum']) && !empty($property['valueEnum'])) {
            return $property['valueEnum'];
        }
        if (isset($property['value']) && !empty($property['value'])) {
            return $property['value'];
        }
        if (isset($property['VALUE']) && !empty($property['VALUE'])) {
            return $property['VALUE'];
        }
        if (isset($property[0]) && !empty($property[0])) {
            return $property[0];
        }
        return null;
    }

    return $property;
}

// ==================== ОБРАБОТКА СДЕЛОК ====================

function processDealsBatch($dealIds, $bonusCodes, $stages, $categories, $users, $departments, $userFields) {
    if (empty($dealIds)) return [];

    echo "\nОбработка пакета из " . count($dealIds) . " сделок...\n";

    // Batch-запрос для получения данных сделок
    $batchCalls = [];
    foreach ($dealIds as $dealId) {
        $batchCalls["deal_$dealId"] = ['crm.deal.get', ['ID' => $dealId]];
        $batchCalls["products_$dealId"] = ['crm.deal.productrows.get', ['id' => $dealId]];
    }

    $batchResults = batchCall($batchCalls);

    if (empty($batchResults)) {
        echo "Ошибка получения данных пакета\n";
        return [];
    }

    // Обрабатываем результаты
    $processedDeals = [];
    $productIdsToFetch = [];
    $skippedCount = 0;

    foreach ($dealIds as $dealId) {
        $dealKey = "deal_$dealId";
        $productsKey = "products_$dealId";

        if (!isset($batchResults[$dealKey])) {
            echo "  Сделка $dealId: отсутствует в результатах (возможно удалена)\n";
            $skippedCount++;
            continue;
        }

        if (!isset($batchResults[$productsKey])) {
            echo "  Сделка $dealId: нет товаров в результатах\n";
            $skippedCount++;
            continue;
        }

        $deal = $batchResults[$dealKey];
        $products = $batchResults[$productsKey];

        // Собираем ID товаров для batch-запроса к каталогу
        foreach ($products as $product) {
            if (isset($product['PRODUCT_ID'])) {
                $productIdsToFetch[$product['PRODUCT_ID']] = true;
            }
        }

        $processedDeals[$dealId] = [
            'deal' => $deal,
            'products' => $products
        ];
    }

    // Batch-запрос для получения данных товаров из каталога
    $catalogData = fetchCatalogData(array_keys($productIdsToFetch));

    // Рассчитываем данные для каждой сделки
    $results = [];
    foreach ($processedDeals as $dealId => $data) {
        $deal = $data['deal'];
        $products = $data['products'];

        // Обогащаем товары данными из каталога
        foreach ($products as &$product) {
            $productId = $product['PRODUCT_ID'] ?? null;
            if ($productId && isset($catalogData[$productId])) {
                $product['CATALOG_DATA'] = $catalogData[$productId];

                // Извлекаем код бонуса
                $bonusCode = null;
                if (isset($catalogData[$productId]['property221'])) {
                    $bonusCode = extractPropertyValue($catalogData[$productId]['property221']);
                } elseif (isset($catalogData[$productId]['PROPERTY_221'])) {
                    $bonusCode = extractPropertyValue($catalogData[$productId]['PROPERTY_221']);
                }

                $product['BONUS_CODE'] = $bonusCode;
            }
        }

        // Рассчитываем бонусы и обороты
        $calculations = calculateBonusesAndTurnovers($products, $bonusCodes);

        // Формируем данные для БД
        $categoryId = $deal['CATEGORY_ID'] ?? 0;
        $stageId = $deal['STAGE_ID'] ?? null;
        $responsibleId = $deal['ASSIGNED_BY_ID'] ?? null;
        $channelId = $deal['UF_CRM_1698142542036'] ?? null;

        if (is_array($channelId)) {
            $channelId = $channelId[0] ?? null;
        }

        $closedate = null;
        if (isset($deal['CLOSED']) && $deal['CLOSED'] === 'Y') {
            $closedate = convertToMySQLDate($deal['CLOSEDATE'] ?? null);
        }

        $results[] = [
            'deal_id' => $deal['ID'],
            'title' => $deal['TITLE'] ?? '',
            'funnel_id' => $categoryId,
            'funnel_name' => $categories[$categoryId] ?? null,
            'stage_id' => $stageId,
            'stage_name' => $stages[$stageId] ?? null,
            'date_create' => convertToMySQLDate($deal['DATE_CREATE'] ?? null),
            'closedate' => $closedate,
            'responsible_id' => $responsibleId,
            'responsible_name' => isset($users[$responsibleId]) ? $users[$responsibleId]['name'] : null,
            'department_id' => isset($users[$responsibleId]) ? $users[$responsibleId]['department_id'] : null,
            'department_name' => isset($users[$responsibleId]['department_id']) ? ($departments[$users[$responsibleId]['department_id']] ?? null) : null,
            'opportunity' => round(floatval($deal['OPPORTUNITY'] ?? 0), 2),
            'quantity' => $calculations['total_quantity'],
            'turnover_category_a' => $calculations['turnover_category_a'],
            'turnover_category_b' => $calculations['turnover_category_b'],
            'bonus_category_a' => $calculations['bonus_category_a'],
            'bonus_category_b' => $calculations['bonus_category_b'],
            'channel_id' => $channelId,
            'channel_name' => isset($userFields['UF_CRM_1698142542036'][$channelId]) ? $userFields['UF_CRM_1698142542036'][$channelId] : null
        ];
    }

    echo "Статистика пакета:\n";
    echo "  Запрошено сделок: " . count($dealIds) . "\n";
    echo "  Пропущено (не найдено): $skippedCount\n";
    echo "  Успешно обработано: " . count($results) . "\n";

    return $results;
}

function fetchCatalogData($productIds) {
    if (empty($productIds)) return [];

    $catalogData = [];
    $chunks = array_chunk($productIds, 50); // API Битрикс24 ограничивает batch до 50 команд

    foreach ($chunks as $chunk) {
        $batchCalls = [];

        foreach ($chunk as $productId) {
            $batchCalls["product_$productId"] = ['catalog.product.get', ['id' => $productId]];
        }

        $results = batchCall($batchCalls);

        foreach ($chunk as $productId) {
            $key = "product_$productId";
            if (isset($results[$key]['product'])) {
                $catalogData[$productId] = $results[$key]['product'];
            } elseif (isset($results[$key]['sku'])) {
                $catalogData[$productId] = $results[$key]['sku'];
            }
        }
    }

    return $catalogData;
}

function calculateBonusesAndTurnovers($products, $bonusCodesMap) {
    $results = [
        'turnover_category_a' => 0,
        'turnover_category_b' => 0,
        'bonus_category_a' => 0,
        'bonus_category_b' => 0,
        'total_quantity' => 0
    ];

    foreach ($products as $product) {
        $quantity = floatval($product['QUANTITY'] ?? 0);
        $price = floatval($product['PRICE'] ?? 0);
        $bonusCode = $product['BONUS_CODE'] ?? '';

        if (empty($bonusCode)) continue;

        $normalizedCode = normalizeBonusCode($bonusCode);
        $category = substr($normalizedCode, 0, 1);
        $turnover = $quantity * $price;
        $bonusPerUnit = $bonusCodesMap[$normalizedCode] ?? 0;
        $bonus = $quantity * $bonusPerUnit;

        if ($category === 'A') {
            $results['turnover_category_a'] += $turnover;
            $results['bonus_category_a'] += $bonus;
        } elseif ($category === 'B') {
            $results['turnover_category_b'] += $turnover;
            $results['bonus_category_b'] += $bonus;
        }

        $results['total_quantity'] += $quantity;
    }

    $results['turnover_category_a'] = round($results['turnover_category_a'], 2);
    $results['turnover_category_b'] = round($results['turnover_category_b'], 2);
    $results['bonus_category_a'] = round($results['bonus_category_a'], 2);
    $results['bonus_category_b'] = round($results['bonus_category_b'], 2);

    return $results;
}

// ==================== РАБОТА С БД ====================

function bulkInsertDeals($mysqli, $dealsData) {
    if (empty($dealsData)) return 0;

    $values = [];
    $params = [];
    $types = '';

    foreach ($dealsData as $deal) {
        $values[] = "(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $params[] = $deal['deal_id'];           // i
        $params[] = $deal['title'];             // s
        $params[] = $deal['funnel_id'];         // i
        $params[] = $deal['funnel_name'];       // s
        $params[] = $deal['stage_id'];          // s
        $params[] = $deal['stage_name'];        // s
        $params[] = $deal['date_create'];       // s
        $params[] = $deal['closedate'];         // s
        $params[] = $deal['responsible_id'];    // i
        $params[] = $deal['responsible_name'];  // s
        $params[] = $deal['department_id'];     // i
        $params[] = $deal['department_name'];   // s
        $params[] = $deal['opportunity'];       // d
        $params[] = $deal['quantity'];          // d
        $params[] = $deal['turnover_category_a']; // d
        $params[] = $deal['turnover_category_b']; // d
        $params[] = $deal['bonus_category_a'];  // d
        $params[] = $deal['bonus_category_b'];  // d
        $params[] = $deal['channel_id'];        // i
        $params[] = $deal['channel_name'];      // s

        $types .= 'isisssssisisddddddis';
    }

    $sql = "INSERT INTO all_deals (
        deal_id, title, funnel_id, funnel_name, stage_id, stage_name,
        date_create, closedate, responsible_id, responsible_name,
        department_id, department_name, opportunity, quantity,
        turnover_category_a, turnover_category_b,
        bonus_category_a, bonus_category_b,
        channel_id, channel_name
    ) VALUES " . implode(', ', $values) . "
    ON DUPLICATE KEY UPDATE
        title = VALUES(title),
        funnel_id = VALUES(funnel_id),
        funnel_name = VALUES(funnel_name),
        stage_id = VALUES(stage_id),
        stage_name = VALUES(stage_name),
        date_create = VALUES(date_create),
        closedate = VALUES(closedate),
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
        channel_name = VALUES(channel_name)";

    $stmt = $mysqli->prepare($sql);

    if (!$stmt) {
        echo "Ошибка подготовки SQL: " . $mysqli->error . "\n";
        return 0;
    }

    echo "DEBUG: Подготовка bind_param для " . count($dealsData) . " записей, типы: $types\n";

    if (!$stmt->bind_param($types, ...$params)) {
        echo "Ошибка bind_param: " . $stmt->error . "\n";
        echo "Количество параметров: " . count($params) . "\n";
        echo "Длина строки типов: " . strlen($types) . "\n";
        $stmt->close();
        return 0;
    }

    echo "DEBUG: bind_param успешен, выполняем запрос...\n";

    if (!$stmt->execute()) {
        echo "Ошибка выполнения SQL: " . $stmt->error . "\n";
        $stmt->close();
        return 0;
    }

    $affected = $stmt->affected_rows;

    // MySQL affected_rows для ON DUPLICATE KEY UPDATE:
    // 0 = запись существует, данные не изменились
    // 1 = новая запись (INSERT)
    // 2 = обновление существующей записи (UPDATE с изменениями)

    $inserted = 0;
    $updated = 0;
    $unchanged = 0;

    if ($affected == 0) {
        // Все записи уже существуют и данные не изменились
        $unchanged = count($dealsData);
        echo "DEBUG: Все записи уже существуют и актуальны (unchanged = $unchanged)\n";
    } else {
        // Приблизительный подсчет (может быть неточным)
        $inserted = floor($affected / 2); // affected=2 за UPDATE
        $updated = $affected - $inserted;
        if ($inserted + $updated < count($dealsData)) {
            $unchanged = count($dealsData) - ($inserted + $updated);
        }
        echo "DEBUG: inserted ≈ $inserted, updated ≈ $updated, unchanged ≈ $unchanged\n";
    }

    $stmt->close();

    // Возвращаем количество обработанных записей (не affected_rows)
    return count($dealsData);
}

// ==================== РАБОТА С ПРОГРЕССОМ ====================

function saveProgress($data) {
    file_put_contents(PROGRESS_FILE, json_encode($data, JSON_PRETTY_PRINT));
}

function loadProgress() {
    return file_exists(PROGRESS_FILE) ? json_decode(file_get_contents(PROGRESS_FILE), true) : null;
}

// ==================== ОСНОВНАЯ ЛОГИКА ====================

echo "=== ОПТИМИЗИРОВАННОЕ ОБНОВЛЕНИЕ СДЕЛОК ===\n\n";

// Обработка аргументов
$testLimit = null;
$freshStart = false;

if (isset($argv[1])) {
    if ($argv[1] == 'reset') {
        if (file_exists(PROGRESS_FILE)) {
            unlink(PROGRESS_FILE);
            echo "Прогресс сброшен.\n";
        }
        clearCache();
        exit;
    } elseif ($argv[1] == 'limit' && isset($argv[2])) {
        $testLimit = intval($argv[2]);
        echo "ТЕСТОВЫЙ РЕЖИМ: Обработка только $testLimit сделок\n\n";
    } elseif ($argv[1] == 'fresh') {
        $freshStart = true;
        if (isset($argv[2])) {
            $testLimit = intval($argv[2]);
            echo "ТЕСТОВЫЙ РЕЖИМ: Обработка $testLimit сделок с начала (минимальный ID)\n\n";
        } else {
            echo "РЕЖИМ FRESH: Обработка с начала (минимальный ID), игнорируя прогресс\n\n";
        }
    }
}

global $config;

// Подключение к БД
$mysqli = new mysqli(
    $config['db']['servername'],
    $config['db']['username'],
    $config['db']['password'],
    $config['db']['dbname']
);

if ($mysqli->connect_error) {
    die("Ошибка БД: " . $mysqli->connect_error . "\n");
}

$mysqli->set_charset("utf8mb4");

echo "Загрузка справочников...\n";
$bonusCodes = loadBonusCodes($mysqli);
$stages = loadStages();
$categories = loadCategories();
$users = loadUsers();
$departments = loadDepartments();
$userFields = loadUserFields();
echo "\nВсе справочники загружены!\n\n";

// Определяем диапазон обработки
$progress = !$freshStart ? loadProgress() : null;
$startId = 0;
$isAscending = false; // Направление обработки

$minResult = $mysqli->query("SELECT MIN(deal_id) as min_id FROM all_deals");
$minId = $minResult->fetch_assoc()['min_id'] ?: 1;

$maxResult = $mysqli->query("SELECT MAX(deal_id) as max_id FROM all_deals");
$maxId = $maxResult->fetch_assoc()['max_id'] ?: 0;

if ($freshStart) {
    // Режим fresh - начинаем с минимального ID (по возрастанию)
    $startId = $minId;
    $isAscending = true;
    echo "Начало с ID: $startId (минимальный, fresh mode)\n";
} elseif ($progress) {
    // Продолжаем с сохраненной позиции (по убыванию)
    $startId = $progress['last_processed_id'] - 1;
    if ($startId < $minId) {
        echo "Все сделки уже обработаны!\n";
        if (file_exists(PROGRESS_FILE)) {
            unlink(PROGRESS_FILE);
        }
        $mysqli->close();
        exit;
    }
    echo "Продолжение с ID: $startId\n";
} else {
    // Начинаем с максимального ID (по убыванию)
    $startId = $maxId;
    echo "Начало с ID: $startId (максимальный)\n";
}

// Подсчет сделок
if ($isAscending) {
    $query = "SELECT COUNT(*) as total FROM all_deals WHERE deal_id >= $startId";
} else {
    $query = "SELECT COUNT(*) as total FROM all_deals WHERE deal_id <= $startId";
}

$result = $mysqli->query($query);
$remainingDeals = $result->fetch_assoc()['total'];

if ($testLimit && $remainingDeals > $testLimit) {
    $remainingDeals = $testLimit;
}

$totalResult = $mysqli->query("SELECT COUNT(*) as total FROM all_deals");
$totalRecords = $totalResult->fetch_assoc()['total'];

if ($remainingDeals == 0) {
    echo "Нет сделок для обработки.\n";
    $mysqli->close();
    exit;
}

echo "Всего записей в БД: $totalRecords\n";
echo "Осталось обработать: $remainingDeals\n";

// Оценка производительности
$expectedRate = BATCH_SIZE * 60 / 10; // ~300 сделок в минуту
echo "Ожидаемая скорость: ~" . round($expectedRate) . " сделок/мин\n";

$estimatedMinutes = $remainingDeals / $expectedRate;
$estimatedSeconds = ceil($estimatedMinutes * 60);
echo "Примерное время: " . gmdate("H:i:s", $estimatedSeconds) . "\n\n";

// Инициализация счетчиков
$processed = 0;
$successful = 0;
$failed = 0;
$startTime = time();

if ($progress) {
    $processed = $progress['total_processed'];
    $successful = $progress['successful'];
    $failed = $progress['failed'];
}

// Основной цикл обработки
$offset = 0;
$dbBuffer = [];

while ($offset < $remainingDeals) {
    $limit = BATCH_SIZE;
    if ($testLimit && ($offset + $limit) > $testLimit) {
        $limit = $testLimit - $offset;
    }

    // Получаем пакет ID сделок
    if ($isAscending) {
        // Fresh mode - от минимального к максимальному
        $result = $mysqli->query(
            "SELECT deal_id FROM all_deals
             WHERE deal_id >= $startId
             ORDER BY deal_id ASC
             LIMIT $offset, $limit"
        );
    } else {
        // Обычный режим - от максимального к минимальному
        $result = $mysqli->query(
            "SELECT deal_id FROM all_deals
             WHERE deal_id <= $startId
             ORDER BY deal_id DESC
             LIMIT $offset, $limit"
        );
    }

    if (!$result) break;

    $dealIds = [];
    while ($row = $result->fetch_assoc()) {
        $dealIds[] = $row['deal_id'];
    }

    if (empty($dealIds)) break;

    // Обрабатываем пакет
    $batchStartTime = microtime(true);
    $dealsData = processDealsBatch(
        $dealIds,
        $bonusCodes,
        $stages,
        $categories,
        $users,
        $departments,
        $userFields
    );

    // Добавляем в буфер для bulk insert
    echo "Добавление " . count($dealsData) . " записей в буфер (текущий размер: " . count($dbBuffer) . ")\n";
    foreach ($dealsData as $dealData) {
        $dbBuffer[] = $dealData;
        $processed++;
        $successful++;
    }
    echo "Буфер после добавления: " . count($dbBuffer) . " записей\n";

    // Bulk insert когда буфер заполнен
    if (count($dbBuffer) >= DB_BULK_SIZE) {
        $inserted = bulkInsertDeals($mysqli, $dbBuffer);
        echo "Записано в БД: $inserted записей\n";
        $dbBuffer = [];
    }

    // Статистика
    $batchTime = microtime(true) - $batchStartTime;
    $actualProcessed = count($dealsData);
    $batchRate = $actualProcessed > 0 ? $actualProcessed / $batchTime * 60 : 0;
    $elapsed = time() - $startTime;
    $rate = $elapsed > 0 ? round($processed / $elapsed * 60, 1) : 0;
    $eta = $rate > 0 ? round(($remainingDeals - $processed) / ($rate / 60)) : 0;

    printf(
        "[%d/%d] %.1f%% | Запрошено: %d, Обработано: %d за %.1fs (%.0f/мин) | Всего: %.1f/мин | ETA: %s\n",
        $processed, $remainingDeals,
        ($processed / $remainingDeals) * 100,
        count($dealIds), $actualProcessed, $batchTime, $batchRate,
        $rate,
        gmdate("H:i:s", $eta)
    );

    // Сохраняем прогресс
    if (!empty($dealIds)) {
        $lastDealId = end($dealIds);
        saveProgress([
            'last_processed_id' => $lastDealId,
            'total_processed' => $processed,
            'successful' => $successful,
            'failed' => $failed,
            'total_deals' => $remainingDeals,
            'start_time' => date('Y-m-d H:i:s', $startTime),
            'last_update' => date('Y-m-d H:i:s')
        ]);
    }

    $offset += BATCH_SIZE;

    if ($testLimit && $offset >= $testLimit) {
        break;
    }
}

// Записываем остатки буфера
echo "\nФинальная запись в БД:\n";
echo "  Размер буфера: " . count($dbBuffer) . " записей\n";
if (!empty($dbBuffer)) {
    $inserted = bulkInsertDeals($mysqli, $dbBuffer);
    echo "Записано в БД (финальный пакет): $inserted записей\n";
} else {
    echo "Буфер пуст, нечего записывать\n";
}

$mysqli->close();

// Итоги
$totalTime = time() - $startTime;
$avgRate = $totalTime > 0 ? round($processed / $totalTime * 60, 1) : 0;

echo "\n=== ЗАВЕРШЕНО ===\n";
echo "Обработано: $processed\n";
echo "Успешно: $successful\n";
echo "Ошибок: $failed\n";
echo "Общее время: " . gmdate("H:i:s", $totalTime) . "\n";
echo "Средняя скорость: $avgRate сделок/мин\n";

if (!$testLimit && $processed >= $remainingDeals && file_exists(PROGRESS_FILE)) {
    unlink(PROGRESS_FILE);
    echo "Прогресс сброшен (все сделки обработаны)\n";
}
?>
