<?php
/**
 * Тестовый файл для проверки API и подключения к БД
 */

header('Content-Type: application/json; charset=utf-8');

// Шаг 1: Проверяем подключение config.php
try {
    require_once __DIR__ . '/config.php';
    echo json_encode([
        'step' => 1,
        'status' => 'OK',
        'message' => 'config.php подключен',
        'db_connected' => isset($conn) ? true : false,
        'conn_object' => isset($conn) ? get_class($conn) : null
    ]);
} catch (Exception $e) {
    echo json_encode([
        'step' => 1,
        'status' => 'ERROR',
        'message' => 'Ошибка при подключении config.php',
        'error' => $e->getMessage()
    ]);
    exit;
}

// Шаг 2: Проверяем наличие таблицы bonus_codes
if (isset($conn)) {
    try {
        $query = "SELECT COUNT(*) as cnt FROM bonus_codes";
        $result = $conn->query($query);

        if ($result) {
            $row = $result->fetch_assoc();
            echo json_encode([
                'step' => 2,
                'status' => 'OK',
                'message' => 'Таблица bonus_codes доступна',
                'count' => $row['cnt']
            ]);
        } else {
            echo json_encode([
                'step' => 2,
                'status' => 'ERROR',
                'message' => 'Ошибка запроса к таблице',
                'error' => $conn->error
            ]);
        }
    } catch (Exception $e) {
        echo json_encode([
            'step' => 2,
            'status' => 'ERROR',
            'message' => 'Exception при запросе',
            'error' => $e->getMessage()
        ]);
    }
}
