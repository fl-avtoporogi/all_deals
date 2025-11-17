<?php
// get_progress.php

// Подключаем конфигурацию, если используется
// require_once __DIR__ . '/config.php';

// Путь к файлам состояния и логов
$stateFile = __DIR__ . '/refresh_state.json';
$logFile = __DIR__ . '/refresh_log.txt';

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

$state = readState();
$progress = 0;
if ($state['total_deals'] > 0) {
    $progress = ($state['processed_deals'] / $state['total_deals']) * 100;
    if ($progress > 100) $progress = 100;
}

$logContent = '';
if (file_exists($logFile)) {
    $logs = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    // Удаляем array_reverse(), чтобы сохранять порядок записей как в файле (новые сверху)
    $logContent = implode("\n", $logs);
}

$response = [
    "progress" => round($progress, 2),
    "last_deal_id" => $state['last_deal_id'] ?? 'N/A',
    "last_processed_time" => $state['last_processed_time'] ?? 'N/A',
    "start_time" => $state['start_time'] ?? 'N/A',
    "forecast_end_time" => $state['forecast_end_time'] ?? 'N/A',
    "log" => $logContent
];

header('Content-Type: application/json');
echo json_encode($response);
?>
