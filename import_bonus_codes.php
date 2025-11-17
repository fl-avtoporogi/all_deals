<?php
//import_bonus_codes.php
//9dk.ru/webhooks/avtoporogi/all_deals/import_bonus_codes.php
// Скрипт для импорта кодов бонусов из CSV в базу данных
// Запускать одноразово при первоначальной настройке или обновлении данных

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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
    die("Ошибка подключения к базе данных: " . $mysqli->connect_error . "\n");
}

// Устанавливаем кодировку соединения
if (!$mysqli->set_charset("utf8mb4")) {
    die("Ошибка установки кодировки: " . $mysqli->error . "\n");
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

// Создаем таблицу, если она не существует
$createTableQuery = "
CREATE TABLE IF NOT EXISTS bonus_codes (
    code VARCHAR(10) PRIMARY KEY,
    bonus_amount DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)
";

if ($mysqli->query($createTableQuery)) {
    echo "Таблица bonus_codes создана или уже существует.\n";
} else {
    die("Ошибка создания таблицы: " . $mysqli->error . "\n");
}

// Путь к CSV файлу
$csvFile = __DIR__ . '/bonus_code.csv';

// Проверяем существование файла
if (!file_exists($csvFile)) {
    die("Файл bonus_code.csv не найден в директории: " . __DIR__ . "\n");
}

// Открываем и читаем CSV файл
$handle = fopen($csvFile, 'r');
if (!$handle) {
    die("Не удалось открыть файл: " . $csvFile . "\n");
}

// Счетчики для статистики
$totalRows = 0;
$successfulInserts = 0;
$errors = 0;

// Подготавливаем SQL-запрос для вставки/обновления
$stmt = $mysqli->prepare("
    INSERT INTO bonus_codes (code, bonus_amount) 
    VALUES (?, ?) 
    ON DUPLICATE KEY UPDATE 
        bonus_amount = VALUES(bonus_amount),
        updated_at = CURRENT_TIMESTAMP
");

if (!$stmt) {
    die("Ошибка подготовки запроса: " . $mysqli->error . "\n");
}

// Читаем заголовки (первая строка)
$headers = fgetcsv($handle, 1000, ';');
if (!$headers || count($headers) < 2) {
    die("Неверный формат CSV файла. Ожидается заголовок с двумя колонками.\n");
}

echo "Заголовки CSV: " . implode(', ', $headers) . "\n";
echo "Начинаем импорт данных...\n";

// Читаем данные построчно
while (($data = fgetcsv($handle, 1000, ';')) !== false) {
    $totalRows++;

    // Проверяем, что у нас есть минимум 2 колонки
    if (count($data) < 2) {
        echo "Строка {$totalRows}: недостаточно данных, пропускаем\n";
        $errors++;
        continue;
    }

    // Извлекаем и очищаем данные
    $rawCode = trim($data[0]);
    $rawBonus = trim($data[1]);

    // Пропускаем пустые строки
    if (empty($rawCode) || empty($rawBonus)) {
        echo "Строка {$totalRows}: пустые данные, пропускаем\n";
        $errors++;
        continue;
    }

    // Нормализуем код бонуса
    $bonusCode = normalizeBonusCode($rawCode);

    // Проверяем, что код не пустой после нормализации
    if (empty($bonusCode)) {
        echo "Строка {$totalRows}: код бонуса пустой после нормализации '{$rawCode}', пропускаем\n";
        $errors++;
        continue;
    }

    // Преобразуем бонус в число
    $bonusAmount = floatval(str_replace(',', '.', $rawBonus));

    // Проверяем, что бонус больше нуля
    if ($bonusAmount <= 0) {
        echo "Строка {$totalRows}: некорректный бонус '{$rawBonus}', пропускаем\n";
        $errors++;
        continue;
    }

    // Выводим информацию о нормализации, если код изменился
    if ($rawCode !== $bonusCode) {
        echo "Строка {$totalRows}: код нормализован '{$rawCode}' -> '{$bonusCode}'\n";
    }

    // Вставляем данные в БД
    $stmt->bind_param("sd", $bonusCode, $bonusAmount);

    if ($stmt->execute()) {
        $successfulInserts++;
        echo "Строка {$totalRows}: успешно добавлен код '{$bonusCode}' с бонусом {$bonusAmount}\n";
    } else {
        echo "Строка {$totalRows}: ошибка вставки - " . $stmt->error . "\n";
        $errors++;
    }
}

// Закрываем файл и соединение
fclose($handle);
$stmt->close();

// Выводим статистику
echo "\n=== СТАТИСТИКА ИМПОРТА ===\n";
echo "Всего строк обработано: {$totalRows}\n";
echo "Успешно добавлено: {$successfulInserts}\n";
echo "Ошибок: {$errors}\n";

// Проверяем итоговое количество записей в таблице
$countResult = $mysqli->query("SELECT COUNT(*) as total FROM bonus_codes");
if ($countResult) {
    $row = $countResult->fetch_assoc();
    echo "Всего записей в таблице: " . $row['total'] . "\n";
}

// Показываем несколько примеров записей
echo "\n=== ПРИМЕРЫ ЗАПИСЕЙ ===\n";
$sampleResult = $mysqli->query("SELECT * FROM bonus_codes ORDER BY code LIMIT 10");
if ($sampleResult) {
    while ($row = $sampleResult->fetch_assoc()) {
        echo "Код: {$row['code']}, Бонус: {$row['bonus_amount']}\n";
    }
}

// Удаляем кэш кодов бонусов, если он существует
$cacheFile = __DIR__ . '/bonus_codes_cache.json';
if (file_exists($cacheFile)) {
    unlink($cacheFile);
    echo "\nКэш кодов бонусов очищен.\n";
}

$mysqli->close();

echo "\nИмпорт завершен.\n";
?>