<?php
/**
 * Главный обработчик приложения для редактирования бонусов
 * Локальное приложение с использованием CRest для OAuth авторизации
 */

// Подключаем локальную копию CRest SDK (с локальными настройками OAuth)
require_once(__DIR__ . '/crest.php');
require_once(__DIR__ . '/crestcurrent.php');

// Получаем данные текущего пользователя через CRest
// CRest автоматически обработает OAuth токены из $_REQUEST
$currentUser = CRestCurrent::call('user.current');

$memberId = 'unknown';
$userName = '';

if (!empty($currentUser['result']['ID'])) {
    $memberId = $currentUser['result']['ID'];
    $userName = $currentUser['result']['NAME'] . ' ' . $currentUser['result']['LAST_NAME'];
}

// Параметры для передачи в JavaScript
$domain = isset($_REQUEST['DOMAIN']) ? $_REQUEST['DOMAIN'] : '';
$apiBaseUrl = 'api.php?member_id=' . urlencode($memberId);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Редактор кодов бонусов</title>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">

    <!-- Custom styles -->
    <link href="public/css/styles.css" rel="stylesheet">

    <!-- Bitrix24 JS SDK (только для управления UI) -->
    <script src="//api.bitrix24.com/api/v1/"></script>

    <script>
        // Передаем параметры в JavaScript
        const APP_CONFIG = {
            memberId: '<?php echo htmlspecialchars($memberId, ENT_QUOTES, 'UTF-8'); ?>',
            userName: '<?php echo htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'); ?>'
        };
    </script>
</head>
<body>
    <div class="container-fluid py-4">
        <div class="row mb-4">
            <div class="col">
                <h2 class="mb-0">
                    <!--i class="bi bi-cash-coin"></i--> Редактор кодов бонусов
                </h2>
                <?php if (!empty($userName)): ?>
                    <small class="text-muted">Пользователь: <?php echo htmlspecialchars($userName); ?></small>
                <?php endif; ?>
            </div>
        </div>

        <!-- Секция импорта CSV -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">
                            <i class="bi bi-file-earmark-arrow-up"></i> Импорт из CSV
                        </h5>
                        <p class="card-text text-muted small">
                            Формат файла: Код бонуса;Бонус (например: A1;35)
                        </p>
                        <div class="csv-upload-wrapper">
                            <div class="csv-upload-container">
                                <label for="csvFile" class="csv-file-label">
                                    <i class="bi bi-folder2-open"></i>
                                    <span class="csv-file-text">Выберите файл</span>
                                    <input type="file" id="csvFile" accept=".csv" class="csv-file-input">
                                </label>
                                <button class="btn btn-primary csv-upload-btn" type="button" id="importBtn">
                                    <i class="bi bi-upload"></i> Импортировать
                                </button>
                            </div>
                            <div class="csv-file-name" id="csvFileName"></div>
                        </div>
                        <div id="importResult" class="mt-3"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Таблица с кодами бонусов -->
        <div class="row">
            <div class="col">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-table"></i> Коды бонусов
                            </h5>
                            <button class="btn btn-success" id="saveBtn" disabled>
                                <i class="bi bi-save"></i> Сохранить все изменения
                            </button>
                        </div>

                        <!-- Поиск/фильтр -->
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <input type="text" class="form-control" id="searchInput" placeholder="Поиск по коду...">
                            </div>
                        </div>

                        <!-- Статус загрузки -->
                        <div id="loadingSpinner" class="text-center py-5">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Загрузка...</span>
                            </div>
                            <p class="mt-2 text-muted">Загрузка данных...</p>
                        </div>

                        <!-- Таблицы (3 колонки) -->
                        <div id="tableContainer" style="display: none;">
                            <div class="row">
                                <!-- Колонка 1 -->
                                <div class="col-md-4">
                                    <div class="table-responsive">
                                        <table class="table table-striped table-hover table-sm">
                                            <thead class="table-dark">
                                                <tr>
                                                    <th>Код</th>
                                                    <th>Наименование</th>
                                                    <th>Бонус (₽)</th>
                                                    <th>Кат.</th>
                                                </tr>
                                            </thead>
                                            <tbody id="bonusTableBody1">
                                                <!-- Заполняется через JavaScript -->
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <!-- Колонка 2 -->
                                <div class="col-md-4">
                                    <div class="table-responsive">
                                        <table class="table table-striped table-hover table-sm">
                                            <thead class="table-dark">
                                                <tr>
                                                    <th>Код</th>
                                                    <th>Наименование</th>
                                                    <th>Бонус (₽)</th>
                                                    <th>Кат.</th>
                                                </tr>
                                            </thead>
                                            <tbody id="bonusTableBody2">
                                                <!-- Заполняется через JavaScript -->
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <!-- Колонка 3 -->
                                <div class="col-md-4">
                                    <div class="table-responsive">
                                        <table class="table table-striped table-hover table-sm">
                                            <thead class="table-dark">
                                                <tr>
                                                    <th>Код</th>
                                                    <th>Наименование</th>
                                                    <th>Бонус (₽)</th>
                                                    <th>Кат.</th>
                                                </tr>
                                            </thead>
                                            <tbody id="bonusTableBody3">
                                                <!-- Заполняется через JavaScript -->
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-3">
                                <small class="text-muted">
                                    <i class="bi bi-info-circle"></i>
                                    Всего кодов: <span id="totalCodes">0</span>
                                </small>
                            </div>
                        </div>

                        <!-- Сообщение об ошибке -->
                        <div id="errorMessage" class="alert alert-danger" style="display: none;">
                            <i class="bi bi-exclamation-triangle"></i>
                            <span id="errorText"></span>
                        </div>

                        <!-- Сообщение об успехе -->
                        <div id="successMessage" class="alert alert-success" style="display: none;">
                            <i class="bi bi-check-circle"></i>
                            <span id="successText"></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Custom JavaScript -->
    <script src="public/js/app.js"></script>
</body>
</html>
