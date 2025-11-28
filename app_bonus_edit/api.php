<?php
/**
 * API для управления кодами бонусов и процентами премии за клиента (REFACTORED)
 *
 * Endpoints:
 * - GET api.php?action=list - получить все коды бонусов
 * - POST api.php?action=update - обновить значения бонусов
 * - POST api.php?action=import_csv - импортировать из CSV файла
 * - GET api.php?action=client_bonus_list - получить все проценты премии за клиента
 * - POST api.php?action=client_bonus_add - добавить новый процент премии за клиента
 * - GET api.php?action=client_bonus_current - получить текущий процент премии за клиента
 */

// Подключаем autoloader
require_once __DIR__ . '/src/autoload.php';

use App\Controllers\BonusController;
use App\Controllers\ClientBonusController;

// Устанавливаем заголовок JSON
header('Content-Type: application/json; charset=utf-8');

try {
    $action = $_GET['action'] ?? '';
    
    // Определяем какой контроллер использовать на основе action
    if (strpos($action, 'client_bonus') === 0) {
        // Используем ClientBonusController для действий с бонусами за клиента
        $controller = new ClientBonusController();
        $controller->handleRequest();
    } else {
        // Используем BonusController для действий с кодами бонусов
        $controller = new BonusController();
        $controller->handleRequest();
    }

} catch (\Exception $e) {
    // Обработка непредвиденных ошибок
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error: ' . $e->getMessage()
    ]);
}
