<?php
/**
 * Конфигурация приложения для редактирования бонусов
 */

// Белый список user_id (пустой массив = доступ всем)
$whitelist_user_ids = [];

// Путь к кэш-файлу бонусов
$cache_file = __DIR__ . '/../bonus_codes_cache.json';

// Путь к файлу логов
$log_file = __DIR__ . '/logs/bonus_changes.log';

// Подключение к базе данных
// Попробуем 2 уровня вверх (структура на сервере может отличаться)
// Путь на сервере: /webhooks/avtoporogi/db_connect.php
if (file_exists(__DIR__ . '/../../db_connect.php')) {
    require_once __DIR__ . '/../../db_connect.php';
} elseif (file_exists(__DIR__ . '/../../../db_connect.php')) {
    require_once __DIR__ . '/../../../db_connect.php';
} else {
    die(json_encode(['success' => false, 'error' => 'db_connect.php not found']));
}

// Проверка подключения к БД
if (!isset($config['db'])) {
    die(json_encode(['success' => false, 'error' => 'Database configuration not found']));
}

// Создание подключения к MySQL
$conn = new mysqli(
    $config['db']['servername'],
    $config['db']['username'],
    $config['db']['password'],
    $config['db']['dbname']
);

// Проверка соединения
if ($conn->connect_error) {
    die(json_encode(['success' => false, 'error' => 'Database connection failed: ' . $conn->connect_error]));
}

// Установка кодировки UTF-8
$conn->set_charset("utf8mb4");

/**
 * Функция для проверки прав доступа пользователя
 * @param string $user_id ID пользователя из Битрикс24
 * @return bool true если доступ разрешен
 */
function checkAccess($user_id) {
    global $whitelist_user_ids;

    // Если белый список пуст - доступ всем
    if (empty($whitelist_user_ids)) {
        return true;
    }

    // Проверка наличия user_id в белом списке
    return in_array($user_id, $whitelist_user_ids);
}

/**
 * Функция для логирования изменений
 * @param string $user_id ID пользователя
 * @param string $action Действие (update, import_csv)
 * @param array $changes Массив изменений
 */
function logChanges($user_id, $action, $changes) {
    global $log_file;

    $timestamp = date('Y-m-d H:i:s');
    $log_entry = sprintf(
        "[%s] User: %s | Action: %s | Changes: %s\n",
        $timestamp,
        $user_id,
        $action,
        json_encode($changes, JSON_UNESCAPED_UNICODE)
    );

    // Создаем папку logs если её нет
    $log_dir = dirname($log_file);
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }

    file_put_contents($log_file, $log_entry, FILE_APPEND);
}

/**
 * Функция для удаления кэша бонусов
 */
function clearBonusCache() {
    global $cache_file;

    if (file_exists($cache_file)) {
        unlink($cache_file);
    }
}
