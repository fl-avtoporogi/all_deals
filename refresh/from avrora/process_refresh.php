<?php
// process_refresh.php

set_time_limit(0); // Убираем ограничение по времени выполнения

// Путь к файлам состояния и логов
$stateFile = __DIR__ . '/refresh_state.json';
$logFile = __DIR__ . '/refresh_log.txt';
$lockFile = __DIR__ . '/refresh.lock';

// Подключаем CRest
require_once (__DIR__ . '/../src/crest.php'); // Убедитесь, что путь к crest.php корректен

// Функция для записи логов
function writeLog($message) {
    global $logFile;
    $date = date('Y-m-d H:i:s');

    // Читаем текущие логи
    $currentLogs = file_exists($logFile) ? file_get_contents($logFile) : '';

    // Формируем новую запись лога
    $newLog = "[$date] $message\n";

    // Записываем новую запись перед существующими
    file_put_contents($logFile, $newLog . $currentLogs);
}

// Функция для чтения состояния
function readState() {
    global $stateFile;
    if (!file_exists($stateFile)) {
        $defaultState = [
            "total_deals" => 0,
            "processed_deals" => 0,
            "last_deal_id" => null,
            "last_processed_time" => null,
            "start" => 0,
            "status" => "idle",
            "min_id" => null,
            "max_id" => null,
            "start_time" => null,
            "forecast_end_time" => null
        ];
        file_put_contents($stateFile, json_encode($defaultState, JSON_PRETTY_PRINT));
    }
    $stateContent = file_get_contents($stateFile);
    return json_decode($stateContent, true);
}

// Функция для записи состояния
function writeState($state) {
    global $stateFile;
    file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));
}

// Функция для получения минимального и максимального ID сделок
function getMinMaxDealIds() {
    // Получение сделки с минимальным ID
    $responseMin = CRest::call('crm.deal.list', [
        'filter' => [],
        'select' => ['ID'],
        'order' => ['ID' => 'ASC'],
        'limit' => 1,
        'start' => 0
    ]);

    if (isset($responseMin['error'])) {
        writeLog("Ошибка API при получении сделки с минимальным ID: " . htmlspecialchars($responseMin['error_description']));
        $minId = null;
    } else {
        if (isset($responseMin['result'][0]['ID'])) {
            $minId = $responseMin['result'][0]['ID'];
        } else {
            $minId = null;
        }
    }

    // Получение сделки с максимальным ID
    $responseMax = CRest::call('crm.deal.list', [
        'filter' => [],
        'select' => ['ID'],
        'order' => ['ID' => 'DESC'],
        'limit' => 1,
        'start' => 0
    ]);

    if (isset($responseMax['error'])) {
        writeLog("Ошибка API при получении сделки с максимальным ID: " . htmlspecialchars($responseMax['error_description']));
        $maxId = null;
    } else {
        if (isset($responseMax['result'][0]['ID'])) {
            $maxId = $responseMax['result'][0]['ID'];
        } else {
            $maxId = null;
        }
    }

    return [
        'min_id' => $minId,
        'max_id' => $maxId
    ];
}

// Чтение текущего состояния
$state = readState();

// Проверка, запущен ли уже процесс
if ($state['status'] === 'running') {
    writeLog("Процесс уже запущен. Завершение нового запуска.");
    exit;
}

// Инициализация min_id и max_id, если они не заданы
if (is_null($state['min_id']) || is_null($state['max_id'])) {
    $minMaxIds = getMinMaxDealIds();
    if (!is_null($minMaxIds['min_id']) && !is_null($minMaxIds['max_id'])) {
        $state['min_id'] = $minMaxIds['min_id'];
        $state['max_id'] = $minMaxIds['max_id'];
        writeState($state);
        writeLog("Минимальный ID сделки: " . $state['min_id']);
        writeLog("Максимальный ID сделки: " . $state['max_id']);
    } else {
        writeLog("Не удалось получить минимальный или максимальный ID сделки. Завершение процесса.");
        $state['status'] = 'error';
        writeState($state);
        exit;
    }
}

// Получение общего количества сделок из API
$responseTotal = CRest::call('crm.deal.list', [
    'filter' => [],
    'select' => ['ID'],
    'limit' => 1, // Минимальный запрос для получения 'total'
    'start' => 0
]);

if (isset($responseTotal['error'])) {
    writeLog("Ошибка API при получении общего количества сделок: " . htmlspecialchars($responseTotal['error_description']));
    $state['status'] = 'error';
    writeState($state);
    exit;
} else {
    if (isset($responseTotal['total'])) {
        $state['total_deals'] = $responseTotal['total'];
        writeState($state);
        writeLog("Общее количество сделок для обработки: " . $state['total_deals']);
    } else {
        writeLog("API ответ не содержит 'total'. Завершение процесса.");
        $state['status'] = 'error';
        writeState($state);
        exit;
    }
}

// Установка времени начала процесса, если не установлено
if (is_null($state['start_time'])) {
    $state['start_time'] = date('Y-m-d H:i:s');
    writeState($state);
    writeLog("Время начала процесса: " . $state['start_time']);
}

// Обновляем статус на 'running'
$state['status'] = 'running';
writeState($state);

// Начинаем обработку
writeLog("Начало обработки сделок.");

$startTimestamp = strtotime($state['start_time']);

// Основной цикл обработки сделок с пагинацией
do {
    // Вызов REST API для получения страницы сделок, сортировка по ID ASC
    $response = CRest::call('crm.deal.list', [
        'filter' => [],
        'select' => ['ID'],
        'order' => ['ID' => 'ASC'],
        'start' => $state['start']
    ]);

    if (isset($response['error'])) {
        writeLog("Ошибка API при получении списка сделок: " . htmlspecialchars($response['error_description']));
        $state['status'] = 'error';
        writeState($state);
        break;
    }

    if (isset($response['result']) && is_array($response['result'])) {
        $deals = $response['result'];
        $dealsCount = count($deals);

        if ($dealsCount === 0) {
            writeLog("Нет новых сделок для обработки.");
            break;
        }

        foreach ($deals as $deal) {
            if (isset($deal['ID'])) {
                $dealId = $deal['ID'];

                // Проверка, была ли сделка уже обработана
                if (!is_null($state['last_deal_id']) && $dealId <= $state['last_deal_id']) {
                    continue;
                }

                // Вызов index.php с текущим deal_id
                $url = "https://42b.ru/webhooks/avrora/all_deals/index.php?deal_id=$dealId";
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                $responseCurl = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                if (curl_errno($ch)) {
                    writeLog("Ошибка CURL при обработке deal_id $dealId: " . curl_error($ch));
                } elseif ($httpCode !== 200) {
                    writeLog("Получен HTTP код $httpCode при обработке deal_id $dealId.");
                } else {
                    writeLog("Успешно обработана сделка deal_id $dealId.");
                }

                curl_close($ch);

                // Обновление состояния
                $state['processed_deals'] += 1;
                $state['last_deal_id'] = $dealId;
                $state['last_processed_time'] = date('Y-m-d H:i:s');
                writeState($state);

                // Добавление прогноза окончания
                $currentTimestamp = time();
                $elapsedSeconds = $currentTimestamp - $startTimestamp;
                if ($state['processed_deals'] > 0) {
                    $averageTimePerDeal = $elapsedSeconds / $state['processed_deals'];
                    $remainingDeals = $state['total_deals'] - $state['processed_deals'];
                    $remainingSeconds = $averageTimePerDeal * $remainingDeals;
                    $forecastEndTimestamp = $currentTimestamp + $remainingSeconds;
                    $state['forecast_end_time'] = date('Y-m-d H:i:s', $forecastEndTimestamp);
                    writeState($state);
                }

                writeLog("Обновлено состояние: last_deal_id = {$state['last_deal_id']}, last_processed_time = {$state['last_processed_time']}, processed_deals = {$state['processed_deals']}, forecast_end_time = {$state['forecast_end_time']}");

                // Проверка прерывания выполнения
                if (connection_aborted()) {
                    writeLog("Выполнение скрипта прервано.");
                    $state['status'] = 'error';
                    writeState($state);
                    exit;
                }

                // Задержка для избежания перегрузки сервера
                usleep(100000); // 0.1 секунды
            }
        }

        // Обновление оффсета для следующей страницы
        if (isset($response['next'])) {
            $state['start'] = $response['next'];
            writeState($state);
        } else {
            // Если 'next' не установлен, значит, достигли конца
            break;
        }

    } else {
        writeLog("Неожиданный API ответ: " . json_encode($response));
        $state['status'] = 'error';
        writeState($state);
        break;
    }

} while ($state['start'] < $state['max_id']); // Используем 'max_id' для ограничения

// Завершение процесса
if ($state['status'] !== 'error') {
    $state['status'] = 'completed';
    writeState($state);
    writeLog("Обработка сделок завершена.");
}
?>
