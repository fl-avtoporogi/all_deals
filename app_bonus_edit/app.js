/**
 * Приложение для редактирования кодов бонусов
 * Локальное приложение - OAuth обрабатывается на сервере через CRest
 */

// Глобальные переменные
let bonusCodes = [];
let originalCodes = [];
let memberId = 'unknown';
let hasChanges = false;

// Инициализация приложения
document.addEventListener('DOMContentLoaded', function() {
    // Получаем member_id из конфига (передан из PHP через CRest)
    if (typeof APP_CONFIG !== 'undefined' && APP_CONFIG.memberId) {
        memberId = APP_CONFIG.memberId;
    }

    // Загружаем данные
    loadBonusCodes();

    // Обработчики событий
    document.getElementById('saveBtn').addEventListener('click', saveChanges);
    document.getElementById('importBtn').addEventListener('click', importCSV);
    document.getElementById('searchInput').addEventListener('input', filterTable);

    // Подстраиваем высоту iframe в Битрикс24
    // Для локальных приложений BX24 уже готов, init не нужен
    if (typeof BX24 !== 'undefined') {
        setTimeout(function() {
            BX24.fitWindow();
        }, 100);
    }
});

/**
 * Загрузка кодов бонусов из API
 */
async function loadBonusCodes() {
    try {
        const response = await fetch(`api.php?action=list&member_id=${memberId}`);
        const result = await response.json();

        if (!result.success) {
            showError('Ошибка загрузки данных: ' + result.error);
            return;
        }

        bonusCodes = result.data;
        originalCodes = JSON.parse(JSON.stringify(result.data)); // Deep copy

        renderTable();
        hideLoading();
    } catch (error) {
        showError('Ошибка подключения к серверу: ' + error.message);
    }
}

/**
 * Отрисовка таблицы
 */
function renderTable() {
    const tbody = document.getElementById('bonusTableBody');
    tbody.innerHTML = '';

    bonusCodes.forEach((item, index) => {
        const row = createTableRow(item, index);
        tbody.appendChild(row);
    });

    document.getElementById('totalCodes').textContent = bonusCodes.length;
    document.getElementById('tableContainer').style.display = 'block';

    // Подстраиваем высоту iframe в Битрикс24
    if (typeof BX24 !== 'undefined' && BX24.fitWindow) {
        setTimeout(function() {
            BX24.fitWindow();
        }, 100);
    }
}

/**
 * Создание строки таблицы
 */
function createTableRow(item, index) {
    const tr = document.createElement('tr');
    tr.dataset.index = index;

    // Определяем категорию
    const category = item.code.startsWith('A') ? 'Категория A' :
                     item.code.startsWith('B') ? 'Категория B' : 'Неизвестно';
    const categoryBadge = item.code.startsWith('A') ? 'bg-primary' : 'bg-success';

    tr.innerHTML = `
        <td>
            <strong>${escapeHtml(item.code)}</strong>
        </td>
        <td>
            <input type="number"
                   class="form-control bonus-input"
                   value="${item.bonus}"
                   min="0"
                   step="0.01"
                   data-index="${index}">
        </td>
        <td>
            <span class="badge ${categoryBadge}">${category}</span>
        </td>
    `;

    // Добавляем обработчик изменений
    const input = tr.querySelector('.bonus-input');
    input.addEventListener('input', handleInputChange);

    return tr;
}

/**
 * Обработчик изменения значения в input
 */
function handleInputChange(event) {
    const index = parseInt(event.target.dataset.index);
    const newValue = parseFloat(event.target.value);

    // Обновляем значение в массиве
    bonusCodes[index].bonus = newValue;

    // Проверяем наличие изменений
    checkForChanges();
}

/**
 * Проверка наличия изменений
 */
function checkForChanges() {
    hasChanges = false;

    for (let i = 0; i < bonusCodes.length; i++) {
        if (bonusCodes[i].bonus !== originalCodes[i].bonus) {
            hasChanges = true;
            break;
        }
    }

    // Активируем/деактивируем кнопку сохранения
    document.getElementById('saveBtn').disabled = !hasChanges;
}

/**
 * Сохранение изменений
 */
async function saveChanges() {
    // Собираем только измененные коды
    const changedCodes = [];

    for (let i = 0; i < bonusCodes.length; i++) {
        if (bonusCodes[i].bonus !== originalCodes[i].bonus) {
            changedCodes.push({
                code: bonusCodes[i].code,
                bonus: bonusCodes[i].bonus
            });
        }
    }

    if (changedCodes.length === 0) {
        showInfo('Нет изменений для сохранения');
        return;
    }

    // Показываем индикатор загрузки
    const saveBtn = document.getElementById('saveBtn');
    const originalText = saveBtn.innerHTML;
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Сохранение...';

    try {
        const response = await fetch(`api.php?action=update&member_id=${memberId}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ codes: changedCodes })
        });

        const result = await response.json();

        if (!result.success) {
            showError('Ошибка сохранения: ' + result.error);
            saveBtn.innerHTML = originalText;
            saveBtn.disabled = false;
            return;
        }

        // Обновляем originalCodes
        originalCodes = JSON.parse(JSON.stringify(bonusCodes));
        hasChanges = false;

        // Показываем результат
        let message = `Успешно обновлено: ${result.updated} кодов`;
        if (result.errors && result.errors.length > 0) {
            message += `<br><small>Ошибки: ${result.errors.join(', ')}</small>`;
        }
        showSuccess(message);

        saveBtn.innerHTML = originalText;
        saveBtn.disabled = true;

    } catch (error) {
        showError('Ошибка подключения: ' + error.message);
        saveBtn.innerHTML = originalText;
        saveBtn.disabled = false;
    }
}

/**
 * Импорт из CSV файла
 */
async function importCSV() {
    const fileInput = document.getElementById('csvFile');
    const file = fileInput.files[0];

    if (!file) {
        showError('Выберите CSV файл');
        return;
    }

    // Проверка расширения файла
    if (!file.name.toLowerCase().endsWith('.csv')) {
        showError('Выберите файл формата CSV');
        return;
    }

    // Показываем индикатор загрузки
    const importBtn = document.getElementById('importBtn');
    const originalText = importBtn.innerHTML;
    importBtn.disabled = true;
    importBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Импорт...';

    const formData = new FormData();
    formData.append('csv_file', file);

    try {
        const response = await fetch(`api.php?action=import_csv&member_id=${memberId}`, {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (!result.success) {
            showImportResult('danger', 'Ошибка импорта: ' + result.error);
            importBtn.innerHTML = originalText;
            importBtn.disabled = false;
            return;
        }

        // Формируем сообщение о результатах
        let message = `
            <strong>Импорт завершен:</strong><br>
            Обновлено кодов: <strong>${result.updated}</strong><br>
            Обработано строк: ${result.total_lines}
        `;

        if (result.errors && result.errors.length > 0) {
            message += '<br><br><strong>Ошибки:</strong><ul class="mb-0">';
            result.errors.forEach(error => {
                message += `<li>${escapeHtml(error)}</li>`;
            });
            message += '</ul>';
        }

        const alertType = result.errors && result.errors.length > 0 ? 'warning' : 'success';
        showImportResult(alertType, message);

        // Перезагружаем данные
        loadBonusCodes();

        // Очищаем input файла
        fileInput.value = '';

        importBtn.innerHTML = originalText;
        importBtn.disabled = false;

    } catch (error) {
        showImportResult('danger', 'Ошибка подключения: ' + error.message);
        importBtn.innerHTML = originalText;
        importBtn.disabled = false;
    }
}

/**
 * Фильтрация таблицы по коду
 */
function filterTable() {
    const searchValue = document.getElementById('searchInput').value.toLowerCase();
    const rows = document.querySelectorAll('#bonusTableBody tr');

    rows.forEach(row => {
        const code = row.querySelector('td:first-child strong').textContent.toLowerCase();
        if (code.includes(searchValue)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

/**
 * Показать результат импорта
 */
function showImportResult(type, message) {
    const resultDiv = document.getElementById('importResult');
    resultDiv.innerHTML = `
        <div class="alert alert-${type} alert-dismissible fade show" role="alert">
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    `;
}

/**
 * Показать ошибку
 */
function showError(message) {
    const errorDiv = document.getElementById('errorMessage');
    const errorText = document.getElementById('errorText');
    errorText.textContent = message;
    errorDiv.style.display = 'block';

    setTimeout(() => {
        errorDiv.style.display = 'none';
    }, 5000);
}

/**
 * Показать успех
 */
function showSuccess(message) {
    const successDiv = document.getElementById('successMessage');
    const successText = document.getElementById('successText');
    successText.innerHTML = message;
    successDiv.style.display = 'block';

    setTimeout(() => {
        successDiv.style.display = 'none';
    }, 5000);
}

/**
 * Показать информацию
 */
function showInfo(message) {
    const successDiv = document.getElementById('successMessage');
    const successText = document.getElementById('successText');
    successText.textContent = message;
    successDiv.style.display = 'block';

    setTimeout(() => {
        successDiv.style.display = 'none';
    }, 3000);
}

/**
 * Скрыть индикатор загрузки
 */
function hideLoading() {
    document.getElementById('loadingSpinner').style.display = 'none';
}

/**
 * Экранирование HTML
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
