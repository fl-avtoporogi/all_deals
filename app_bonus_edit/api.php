<?php
/**
 * API для управления кодами бонусов
 *
 * Endpoints:
 * - GET api.php?action=list - получить все коды бонусов
 * - POST api.php?action=update - обновить значения бонусов
 * - POST api.php?action=import_csv - импортировать из CSV файла
 */

header('Content-Type: application/json; charset=utf-8');

// Подключаем конфигурацию
require_once __DIR__ . '/config.php';

// Получаем user_id из параметров (Битрикс24 передает member_id)
$user_id = isset($_GET['member_id']) ? $_GET['member_id'] : 'unknown';

// Проверка прав доступа
if (!checkAccess($user_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

// Определяем действие
$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($action) {
    case 'list':
        handleList();
        break;

    case 'update':
        handleUpdate($user_id);
        break;

    case 'import_csv':
        handleImportCSV($user_id);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        break;
}

/**
 * Получить список всех кодов бонусов
 */
function handleList() {
    global $conn;

    $query = "SELECT code, bonus_amount FROM bonus_codes ORDER BY code";
    $result = $conn->query($query);

    if (!$result) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $conn->error]);
        return;
    }

    $codes = [];
    while ($row = $result->fetch_assoc()) {
        $codes[] = [
            'code' => $row['code'],
            'bonus' => floatval($row['bonus_amount'])
        ];
    }

    echo json_encode(['success' => true, 'data' => $codes]);
}

/**
 * Обновить значения бонусов
 */
function handleUpdate($user_id) {
    global $conn;

    // Получаем данные из POST
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!$data || !isset($data['codes']) || !is_array($data['codes'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid data format']);
        return;
    }

    $codes = $data['codes'];
    $updated = 0;
    $errors = [];
    $changes = [];

    // Начинаем транзакцию
    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare("UPDATE bonus_codes SET bonus_amount = ? WHERE code = ?");

        foreach ($codes as $item) {
            if (!isset($item['code']) || !isset($item['bonus'])) {
                continue;
            }

            $code = trim($item['code']);
            $bonus = floatval($item['bonus']);

            // Валидация
            if (empty($code)) {
                $errors[] = "Empty code";
                continue;
            }

            if ($bonus < 0) {
                $errors[] = "Invalid bonus value for code {$code}";
                continue;
            }

            // Выполняем обновление
            $stmt->bind_param("ds", $bonus, $code);

            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    $updated++;
                    $changes[] = ['code' => $code, 'bonus' => $bonus];
                }
            } else {
                $errors[] = "Failed to update code {$code}: " . $stmt->error;
            }
        }

        $stmt->close();

        // Подтверждаем транзакцию
        $conn->commit();

        // Логируем изменения
        if (!empty($changes)) {
            logChanges($user_id, 'update', $changes);
            clearBonusCache();
        }

        echo json_encode([
            'success' => true,
            'updated' => $updated,
            'errors' => $errors
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Transaction failed: ' . $e->getMessage()]);
    }
}

/**
 * Импортировать данные из CSV файла
 */
function handleImportCSV($user_id) {
    global $conn;

    // Проверяем наличие файла
    if (!isset($_FILES['csv_file'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'No file uploaded']);
        return;
    }

    $file = $_FILES['csv_file'];

    // Проверка ошибок загрузки
    if ($file['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'File upload error']);
        return;
    }

    // Читаем файл
    $content = file_get_contents($file['tmp_name']);

    // Определяем кодировку и конвертируем в UTF-8 если нужно
    $encoding = mb_detect_encoding($content, ['UTF-8', 'Windows-1251', 'ISO-8859-1'], true);
    if ($encoding !== 'UTF-8') {
        $content = mb_convert_encoding($content, 'UTF-8', $encoding);
    }

    $lines = explode("\n", $content);

    $updated = 0;
    $errors = [];
    $changes = [];
    $line_number = 0;

    // Начинаем транзакцию
    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare("UPDATE bonus_codes SET bonus_amount = ? WHERE code = ?");
        $check_stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM bonus_codes WHERE code = ?");

        foreach ($lines as $line) {
            $line_number++;

            // Пропускаем пустые строки
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            // Пропускаем заголовок (первая строка)
            if ($line_number === 1 && (stripos($line, 'Код бонуса') !== false || stripos($line, 'Бонус') !== false)) {
                continue;
            }

            // Парсим строку CSV (разделитель - точка с запятой)
            $parts = explode(';', $line);

            if (count($parts) < 2) {
                $errors[] = "Line {$line_number}: Invalid format";
                continue;
            }

            $code = trim($parts[0]);
            $bonus = trim($parts[1]);

            // Пропускаем пустые коды
            if (empty($code)) {
                continue;
            }

            // Валидация бонуса
            if (!is_numeric($bonus)) {
                $errors[] = "Line {$line_number}: Invalid bonus value for code {$code}";
                continue;
            }

            $bonus = floatval($bonus);

            if ($bonus < 0) {
                $errors[] = "Line {$line_number}: Negative bonus value for code {$code}";
                continue;
            }

            // Проверяем существование кода в БД
            $check_stmt->bind_param("s", $code);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            $check_row = $check_result->fetch_assoc();

            if ($check_row['cnt'] == 0) {
                $errors[] = "Line {$line_number}: Code '{$code}' does not exist in database";
                continue;
            }

            // Обновляем код
            $stmt->bind_param("ds", $bonus, $code);

            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    $updated++;
                    $changes[] = ['code' => $code, 'bonus' => $bonus];
                }
            } else {
                $errors[] = "Line {$line_number}: Failed to update code {$code}";
            }
        }

        $stmt->close();
        $check_stmt->close();

        // Подтверждаем транзакцию
        $conn->commit();

        // Логируем изменения
        if (!empty($changes)) {
            logChanges($user_id, 'import_csv', $changes);
            clearBonusCache();
        }

        echo json_encode([
            'success' => true,
            'updated' => $updated,
            'errors' => $errors,
            'total_lines' => $line_number
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Import failed: ' . $e->getMessage()]);
    }
}
