<?php
// refresh.php
//42b.ru/webhooks/avrora/all_deals/refresh/refresh.php - запуск
// Путь к файлам состояния и логов
$stateFile = __DIR__ . '/refresh_state.json';
$logFile = __DIR__ . '/refresh_log.txt';
$lockFile = __DIR__ . '/refresh.lock';

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

// Обработка нажатия кнопок
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['start'])) {
        // Запуск обновления
        // Проверка наличия запущенного процесса
        if (file_exists($lockFile)) {
            $message = "Процесс уже запущен.";
        } else {
            // Создание lock файла
            file_put_contents($lockFile, "locked");

            // Запуск process_refresh.php в фоне
            // Путь к PHP CLI, возможно потребуется изменить
            $phpPath = '/usr/bin/php'; // Убедитесь, что путь правильный
            $scriptPath = __DIR__ . '/process_refresh.php';

            // Запуск процесса в фоне
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                // Для Windows
                pclose(popen("start /B \"$phpPath\" \"$scriptPath\"", "r"));
            } else {
                // Для Unix/Linux
                exec("$phpPath $scriptPath > /dev/null 2>&1 &");
            }

            $message = "Процесс обновления запущен.";
        }
    }

    if (isset($_POST['restart'])) {
        // Перезапуск обновления
        // Удаление текущего состояния и лога
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }
        writeState([
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
        ]);
        file_put_contents($logFile, ""); // Очистка лога

        // Создание lock файла
        file_put_contents($lockFile, "locked");

        // Запуск процесса обновления
        $phpPath = '/usr/bin/php'; // Убедитесь, что путь правильный
        $scriptPath = __DIR__ . '/process_refresh.php';

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Для Windows
            pclose(popen("start /B \"$phpPath\" \"$scriptPath\"", "r"));
        } else {
            // Для Unix/Linux
            exec("$phpPath $scriptPath > /dev/null 2>&1 &");
        }

        $message = "Процесс обновления перезапущен.";
    }
}

// Чтение текущего состояния
$state = readState();

// Если процесс завершен или произошла ошибка, удалить lock файл
if (in_array($state['status'], ['completed', 'error']) && file_exists($lockFile)) {
    unlink($lockFile);
}

// Расчет прогресса
$progress = 0;
if ($state['total_deals'] > 0) {
    $progress = ($state['processed_deals'] / $state['total_deals']) * 100;
    if ($progress > 100) $progress = 100;
}

// Получение последних обработанных сделки и времени
$lastDealId = htmlspecialchars($state['last_deal_id'] ?? 'N/A');
$lastProcessedTime = htmlspecialchars($state['last_processed_time'] ?? 'N/A');

// Получение времени начала и прогноза окончания процесса
$startTime = htmlspecialchars($state['start_time'] ?? 'N/A');
$forecastEndTime = htmlspecialchars($state['forecast_end_time'] ?? 'N/A');

// Получение сообщения об ошибках, если процесс в статусе 'error'
if ($state['status'] === 'error') {
    $message = "Произошла ошибка во время обновления. Проверьте логи для деталей.";
}

// Получение логов без переворота порядка
$logContent = '';
if (file_exists($logFile)) {
    $logs = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    // Удаляем array_reverse(), чтобы сохранять порядок записей как в файле (новые сверху)
    $logContent = implode("\n", $logs);
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Обновление Сделок</title>
    <!-- Подключение Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            padding: 20px;
        }
        .progress-container {
            margin-top: 20px;
        }
        .info-box {
            margin-top: 20px;
        }
        .log-box {
            margin-top: 20px;
            max-height: 450px; /* Увеличенная высота (1.5x от 300px) */
            overflow-y: scroll;
            background-color: #f8f9fa;
            padding: 10px;
            border: 1px solid #dee2e6;
            /*white-space: pre-wrap; /* Позволяет переносить строки */
        }
    </style>
</head>
<body>
<h1>Обновление Сделок Битрикс24</h1>

<?php if (isset($message)): ?>
    <div class="alert alert-info" role="alert">
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<form method="post">
    <button type="submit" name="start" class="btn btn-primary">Запустить обновление</button>
    <button type="submit" name="restart" class="btn btn-danger">Запустить заново</button>
</form>

<div class="progress-container">
    <label for="progressBar" class="form-label">Прогресс:</label>
    <div class="progress">
        <div id="progressBar" class="progress-bar" role="progressbar" style="width: <?php echo round($progress, 2); ?>%;" aria-valuenow="<?php echo round($progress, 2); ?>" aria-valuemin="0" aria-valuemax="100"><?php echo round($progress, 2); ?>%</div>
    </div>
</div>

<div class="info-box">
    <p><strong>Последняя обработанная сделка ID:</strong> <span id="lastDealId"><?php echo $lastDealId; ?></span></p>
    <p><strong>Время последней обработки:</strong> <span id="lastProcessedTime"><?php echo $lastProcessedTime; ?></span></p>
    <p><strong>Время начала процесса:</strong> <span id="startTime"><?php echo $startTime; ?></span></p>
    <p><strong>Прогнозируемое время окончания:</strong> <span id="forecastEndTime"><?php echo $forecastEndTime; ?></span></p>
</div>

<div class="log-box">
    <h3>Логи:</h3>
    <pre><?php echo htmlspecialchars($logContent); ?></pre>
</div>

<!-- Подключение Bootstrap JS и зависимостей -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- Подключение jQuery для AJAX -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    // Функция для обновления прогресса и информации
    function updateProgress() {
        $.ajax({
            url: 'get_progress.php',
            method: 'GET',
            dataType: 'json',
            success: function(data) {
                console.log(data); // Для отладки
                $('#progressBar').css('width', data.progress + '%').attr('aria-valuenow', data.progress).text(data.progress.toFixed(2) + '%');
                $('#lastDealId').text(data.last_deal_id);
                $('#lastProcessedTime').text(data.last_processed_time);
                $('#startTime').text(data.start_time);
                $('#forecastEndTime').text(data.forecast_end_time);
                $('.log-box pre').text(data.log);
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error("Ошибка AJAX запроса: " + textStatus + ", " + errorThrown);
            }
        });
    }

    // Обновление каждые 5 секунд
    setInterval(updateProgress, 5000);

    // Первоначальное обновление при загрузке страницы
    $(document).ready(function() {
        updateProgress();
    });
</script>
</body>
</html>
