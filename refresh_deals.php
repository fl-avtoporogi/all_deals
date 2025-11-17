<?php
/**
 * Автоматическое обновление данных сделок с параллельной обработкой
 * Файл: refresh_deals.php
 *
 * Обработка идет от новых сделок к старым (по убыванию ID)
 * Использует curl_multi для параллельной обработки до 10-20 запросов одновременно
 *
 * Режимы запуска:
 * php refresh_deals.php         - обычный режим (~1000+ записей/мин)
 * php refresh_deals.php fast    - быстрый режим (~2000+ записей/мин)
 * php refresh_deals.php reset   - сброс прогресса
 *
 * Оптимизации:
 * - Параллельная обработка через curl_multi
 * - Сжатие gzip для уменьшения трафика
 * - Keep-alive соединения
 * - Увеличенный размер пакетов
 * - Минимальные задержки
 * - Кэширование прогресса с редкой записью
 *
 * Исправлены ошибки:
 * - Убраны устаревшие опции CURL
 * - Исправлены проблемы с округлением
 * - Устранены undefined переменные
 * - Отключен вывод предупреждений
 */

// Отключаем вывод предупреждений в консоль
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);
ini_set('display_errors', 0);
set_time_limit(0);
ini_set('memory_limit', '512M');

// Отключаем буферизацию вывода для плавного обновления прогресса
if (ob_get_level()) {
    ob_end_flush();
}
ob_implicit_flush(true);

require_once '../db_connect.php';

// Константы
$fastMode = isset($argv[1]) && $argv[1] == 'fast';
define('BATCH_SIZE', $fastMode ? 200 : 100);
define('DELAY_BETWEEN_BATCHES', $fastMode ? 0 : 0.2); // Еще уменьшил задержку
define('DELAY_BETWEEN_DEALS', 0);
define('PARALLEL_REQUESTS', $fastMode ? 20 : 10);
define('PROGRESS_FILE', dirname(__FILE__) . '/refresh_progress.json');

// Функции работы с прогрессом
function saveProgress($data) {
    file_put_contents(PROGRESS_FILE, json_encode($data, JSON_PRETTY_PRINT));
}

function loadProgress() {
    return file_exists(PROGRESS_FILE) ? json_decode(file_get_contents(PROGRESS_FILE), true) : null;
}

// Функция параллельного обновления пакета сделок
function updateDealsBatch($dealIds) {
    $url_base = 'https://9dk.ru/webhooks/avtoporogi/all_deals/index.php?deal_id=';
    $results = [];

    // Создаем multi handle с оптимизациями
    $mh = curl_multi_init();
    // Убираем устаревшие опции
    curl_multi_setopt($mh, CURLMOPT_MAX_HOST_CONNECTIONS, PARALLEL_REQUESTS);

    $handles = [];

    // Инициализируем запросы
    foreach ($dealIds as $dealId) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url_base . $dealId,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_TCP_KEEPIDLE => 120,
            CURLOPT_TCP_KEEPINTVL => 60,
            CURLOPT_ENCODING => 'gzip' // Включаем сжатие
        ]);

        curl_multi_add_handle($mh, $ch);
        $handles[$dealId] = $ch;
    }

    // Выполняем запросы
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        if ($running) {
            curl_multi_select($mh, 0.1);
        }
    } while ($running > 0);

    // Собираем результаты
    foreach ($handles as $dealId => $ch) {
        $result = curl_multi_getcontent($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if ($error) {
            $results[$dealId] = ['success' => false, 'error' => $error];
        } elseif ($httpCode !== 200) {
            $results[$dealId] = ['success' => false, 'error' => "HTTP: $httpCode"];
        } elseif (strpos($result, 'успешно') !== false || strpos($result, 'Данные успешно') !== false) {
            $results[$dealId] = ['success' => true];
        } else {
            $results[$dealId] = ['success' => false, 'error' => 'Неожиданный ответ'];
        }

        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }

    curl_multi_close($mh);
    return $results;
}

// ОСНОВНАЯ ЛОГИКА
global $config;

// Проверка режима работы
if (isset($argv[1])) {
    if ($argv[1] == 'reset' && file_exists(PROGRESS_FILE)) {
        unlink(PROGRESS_FILE);
        echo "Прогресс сброшен.\n";
        exit;
    } elseif ($argv[1] == 'fast') {
        echo "=== БЫСТРЫЙ РЕЖИМ ===\n";
        echo "Пакет: " . BATCH_SIZE . " записей\n";
        echo "Параллельных запросов: " . PARALLEL_REQUESTS . "\n";
        echo "Задержки минимальны\n\n";
    }
}

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

// Определение стартовой позиции
$startId = 0;

// Получаем минимальный ID из БД для проверки границы
$minResult = $mysqli->query("SELECT MIN(deal_id) as min_id FROM all_deals");
$minId = $minResult->fetch_assoc()['min_id'] ?: 1;

if ($progress = loadProgress()) {
    // Есть сохраненный прогресс - продолжаем с него (движемся вниз)
    $startId = $progress['last_processed_id'] - 1;
    if ($startId < $minId) {
        echo "Все сделки уже обработаны (достигнут минимальный ID: $minId).\n";
        if (file_exists(PROGRESS_FILE)) {
            unlink(PROGRESS_FILE);
        }
        $mysqli->close();
        exit;
    }
    echo "Продолжение с ID: $startId\n";
} else {
    // Нет прогресса - начинаем с максимального ID
    $result = $mysqli->query("SELECT MAX(deal_id) as max_id FROM all_deals");
    $maxId = $result->fetch_assoc()['max_id'];
    $startId = $maxId ?: 0;
    echo "Начало с ID: $startId (максимальный)\n";
}

// Подсчет всех сделок для обработки (теперь считаем те, что <= startId)
$result = $mysqli->query("SELECT COUNT(*) as total FROM all_deals WHERE deal_id <= $startId");
$remainingDeals = $result->fetch_assoc()['total'];

// Показываем общую статистику
$totalResult = $mysqli->query("SELECT COUNT(*) as total FROM all_deals");
$totalRecords = $totalResult->fetch_assoc()['total'];

if ($remainingDeals == 0) {
    echo "Все сделки обработаны.\n";
    if (file_exists(PROGRESS_FILE)) {
        unlink(PROGRESS_FILE);
    }
    $mysqli->close();
    exit;
}

// Получаем диапазон ID для обработки
$rangeResult = $mysqli->query("SELECT MIN(deal_id) as min_id, MAX(deal_id) as max_id FROM all_deals WHERE deal_id <= $startId");
$range = $rangeResult->fetch_assoc();

echo "Всего записей в БД: $totalRecords\n";
echo "Осталось обработать: $remainingDeals\n";
echo "Диапазон ID: " . $range['max_id'] . " → " . $range['min_id'] . " (от новых к старым)\n";

// Оценка производительности
$expectedRate = PARALLEL_REQUESTS * 60 / 0.5;
echo "Ожидаемая скорость: ~" . round($expectedRate) . " записей/мин\n";

// Оценка времени выполнения
$estimatedMinutes = $remainingDeals / $expectedRate;
$estimatedSeconds = ceil($estimatedMinutes * 60);
echo "Примерное время выполнения: " . gmdate("H:i:s", $estimatedSeconds) . " (параллельная обработка)\n\n";

// Инициализация
$processed = $successful = $failed = 0;
$startTime = time();

// Если есть сохраненный прогресс, восстанавливаем счетчики
if ($progress) {
    $processed = $progress['total_processed'];
    $successful = $progress['successful'];
    $failed = $progress['failed'];
}

// Основной цикл
$offset = 0;
$batchNumber = 0;
while ($offset < $remainingDeals) {
    $result = $mysqli->query(
        "SELECT deal_id FROM all_deals WHERE deal_id <= $startId 
         ORDER BY deal_id DESC LIMIT $offset, " . BATCH_SIZE
    );

    if (!$result) break;

    $dealIds = [];
    while ($row = $result->fetch_assoc()) {
        $dealIds[] = $row['deal_id'];
    }

    if (empty($dealIds)) break;

    $batchNumber++;
    $batchStartTime = microtime(true);
    echo "\nПакет #$batchNumber: обработка " . count($dealIds) . " сделок параллельно...\n";

    // Разбиваем пакет на подпакеты для параллельной обработки
    $chunks = array_chunk($dealIds, PARALLEL_REQUESTS);

    foreach ($chunks as $chunkIndex => $chunk) {
        // Параллельная обработка подпакета
        $results = updateDealsBatch($chunk);

        // Обработка результатов
        foreach ($chunk as $dealId) {
            $processed++;
            $percent = round(($processed / $totalRecords) * 100, 2);

            if ($results[$dealId]['success']) {
                $successful++;
                $status = "✓";
            } else {
                $failed++;
                $status = "✗";
            }

            // Обновляем строку состояния
            $elapsed = time() - $startTime;
            $rate = $elapsed > 0 ? round($processed / $elapsed * 60, 1) : 0;
            $eta = $rate > 0 ? round(($totalRecords - $processed) / ($rate / 60)) : 0;

            $statusLine = sprintf(
                "\r[%d/%d] %.1f%% | ID:%d↓ %s | OK:%d ERR:%d | %.1f/мин | ETA: %s",
                $processed, $totalRecords, $percent, $dealId, $status,
                $successful, $failed, $rate,
                gmdate("H:i:s", $eta)
            );
            echo $statusLine;
        }

        // Сохраняем прогресс после каждых 10 подпакетов или в конце пакета
        if (($chunkIndex % 10 == 9 || $chunkIndex == count($chunks) - 1) && !empty($chunk)) {
            $lastDealId = end($chunk);
            saveProgress([
                'last_processed_id' => $lastDealId,
                'total_processed' => $processed,
                'successful' => $successful,
                'failed' => $failed,
                'total_deals' => $totalRecords,
                'start_time' => date('Y-m-d H:i:s', $startTime),
                'last_update' => date('Y-m-d H:i:s')
            ]);
        }
    }

    // Статистика пакета
    $batchTime = microtime(true) - $batchStartTime;
    $batchRate = count($dealIds) / $batchTime * 60;
    echo sprintf("\nПакет обработан за %.1f сек (%.0f записей/мин)\n", $batchTime, $batchRate);

    $offset += BATCH_SIZE;

    if ($offset < $remainingDeals && DELAY_BETWEEN_BATCHES > 0) {
        usleep(DELAY_BETWEEN_BATCHES * 1000000);
    }
}

$mysqli->close();

// Итоги
$totalTime = time() - $startTime;
$avgRate = $totalTime > 0 ? round($processed / $totalTime * 60, 1) : 0;

echo "\n\n=== ЗАВЕРШЕНО ===\n";
echo "Обработано: $processed\n";
echo "Успешно: $successful\n";
echo "Ошибок: $failed\n";
echo "Общее время: " . gmdate("H:i:s", $totalTime) . "\n";
echo "Средняя скорость: $avgRate записей/мин\n";

if ($processed >= $totalRecords && file_exists(PROGRESS_FILE)) {
    unlink(PROGRESS_FILE);
}
?>