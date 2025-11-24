<?php
/**
 * API для управления кодами бонусов (REFACTORED)
 *
 * Endpoints:
 * - GET api.php?action=list - получить все коды бонусов
 * - POST api.php?action=update - обновить значения бонусов
 * - POST api.php?action=import_csv - импортировать из CSV файла
 */

// Подключаем autoloader
require_once __DIR__ . '/src/autoload.php';

use App\Controllers\BonusController;

// Устанавливаем заголовок JSON
header('Content-Type: application/json; charset=utf-8');

try {
    // Создаем экземпляр контроллера и обрабатываем запрос
    $controller = new BonusController();
    $controller->handleRequest();

} catch (\Exception $e) {
    // Обработка непредвиденных ошибок
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error: ' . $e->getMessage()
    ]);
}
