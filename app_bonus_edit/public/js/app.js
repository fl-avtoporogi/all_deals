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

    // Обработчики событий для вкладки кодов бонусов
    document.getElementById('saveBtn').addEventListener('click', saveChanges);
    document.getElementById('importBtn').addEventListener('click', importCSV);
    document.getElementById('searchInput').addEventListener('input', filterTable);
    document.getElementById('csvFile').addEventListener('change', handleFileSelect);

    // Обработчики событий для вкладки бонусов за клиента
    document.getElementById('clientBonusForm').addEventListener('submit', addClientBonus);

    // Обработчик переключения вкладок
    document.getElementById('client-bonus-tab').addEventListener('shown.bs.tab', function() {
        loadClientBonusData();
    });

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
 * Отрисовка таблицы (3 колонки)
 */
function renderTable() {
    // Делим массив на 3 части
    const third = Math.ceil(bonusCodes.length / 3);
    const column1 = bonusCodes.slice(0, third);
    const column2 = bonusCodes.slice(third, third * 2);
    const column3 = bonusCodes.slice(third * 2);

    // Рендерим каждую колонку
    renderTableColumn('bonusTableBody1', column1, 0);
    renderTableColumn('bonusTableBody2', column2, third);
    renderTableColumn('bonusTableBody3', column3, third * 2);

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
 * Отрисовка одной колонки таблицы
 */
function renderTableColumn(tbodyId, codes, startIndex) {
    const tbody = document.getElementById(tbodyId);
    tbody.innerHTML = '';

    codes.forEach((item, localIndex) => {
        const globalIndex = startIndex + localIndex;
        const row = createTableRow(item, globalIndex);
        tbody.appendChild(row);
    });
}

/**
 * Создание строки таблицы
 */
function createTableRow(item, index) {
    const tr = document.createElement('tr');
    tr.dataset.index = index;

    // Определяем категорию (только буква)
    const category = item.code.startsWith('A') ? 'A' :
                     item.code.startsWith('B') ? 'B' : '?';
    const categoryBadge = item.code.startsWith('A') ? 'bg-primary' : 'bg-success';

    tr.innerHTML = `
        <td>
            <strong>${escapeHtml(item.code)}</strong>
        </td>
        <td class="bonus-name-cell">
            <small class="text-muted">${escapeHtml(item.name)}</small>
        </td>
        <td>
            <input type="number"
                   class="form-control bonus-input form-control-sm"
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
 * Обработка выбора файла
 */
function handleFileSelect(event) {
    const fileInput = event.target;
    const fileNameDisplay = document.getElementById('csvFileName');
    const fileLabel = document.querySelector('.csv-file-text');

    if (fileInput.files.length > 0) {
        const fileName = fileInput.files[0].name;
        fileNameDisplay.textContent = fileName;
        fileLabel.innerHTML = '<i class="bi bi-file-earmark-check" style="color: #55D0A4; font-size: 14px;"></i> ' + fileName;
    } else {
        fileNameDisplay.textContent = '';
        fileLabel.textContent = 'Выберите файл';
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
        document.getElementById('csvFileName').textContent = '';
        document.querySelector('.csv-file-text').textContent = 'Выберите файл';

        importBtn.innerHTML = originalText;
        importBtn.disabled = false;

    } catch (error) {
        showImportResult('danger', 'Ошибка подключения: ' + error.message);
        importBtn.innerHTML = originalText;
        importBtn.disabled = false;
    }
}

/**
 * Фильтрация таблицы по коду и наименованию (все 3 колонки)
 */
function filterTable() {
    const searchValue = document.getElementById('searchInput').value.toLowerCase();

    // Фильтруем все 3 таблицы
    ['bonusTableBody1', 'bonusTableBody2', 'bonusTableBody3'].forEach(tbodyId => {
        const rows = document.querySelectorAll(`#${tbodyId} tr`);

        rows.forEach(row => {
            const code = row.querySelector('td:first-child strong').textContent.toLowerCase();
            const name = row.querySelector('.bonus-name-cell')?.textContent.toLowerCase() || '';
            if (code.includes(searchValue) || name.includes(searchValue)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
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
 * Загрузка данных о бонусах за клиента
 */
async function loadClientBonusData() {
    try {
        // Показываем индикатор загрузки
        document.getElementById('clientBonusLoadingSpinner').style.display = 'block';
        document.getElementById('clientBonusTableContainer').style.display = 'none';

        // Загружаем историю процентов
        const listResponse = await fetch(`api.php?action=client_bonus_list&member_id=${memberId}`);
        const listResult = await listResponse.json();

        if (!listResult.success) {
            showClientBonusError('Ошибка загрузки данных: ' + listResult.error);
            return;
        }

        // Загружаем текущий процент
        const currentResponse = await fetch(`api.php?action=client_bonus_current&member_id=${memberId}`);
        const currentResult = await currentResponse.json();

        if (currentResult.success) {
            renderCurrentBonusRate(currentResult.bonus_rate);
        } else {
            renderCurrentBonusRate(null);
        }

        renderClientBonusTable(listResult.data, listResult.stats);
        hideClientBonusLoading();

    } catch (error) {
        showClientBonusError('Ошибка подключения к серверу: ' + error.message);
    }
}

/**
 * Отображение текущего процента премии
 */
function renderCurrentBonusRate(rate) {
    const container = document.getElementById('currentBonusRate');
    
    if (rate !== null && rate !== undefined) {
        container.innerHTML = `
            <div class="display-4 text-primary">
                <strong>${rate}%</strong>
            </div>
            <small class="text-muted">Текущий процент премии за клиента</small>
        `;
    } else {
        container.innerHTML = `
            <div class="text-muted">
                <i class="bi bi-dash-circle"></i> Не установлен
            </div>
            <small class="text-muted">Нет данных о проценте премии</small>
        `;
    }
}

/**
 * Отрисовка таблицы истории бонусов за клиента
 */
function renderClientBonusTable(data, stats) {
    const tbody = document.getElementById('clientBonusTableBody');
    tbody.innerHTML = '';

    if (data.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="4" class="text-center text-muted">
                    <i class="bi bi-inbox"></i> Нет записей
                </td>
            </tr>
        `;
    } else {
        data.forEach(item => {
            const row = createClientBonusTableRow(item);
            tbody.appendChild(row);
        });
    }

    // Обновляем статистику
    document.getElementById('totalClientBonusRecords').textContent = stats.total_records;

    // Показываем таблицу
    document.getElementById('clientBonusTableContainer').style.display = 'block';
}

/**
 * Создание строки таблицы истории бонусов за клиента
 */
function createClientBonusTableRow(item) {
    const tr = document.createElement('tr');
    
    const createdDate = new Date(item.created_date).toLocaleDateString('ru-RU');
    const createdAt = new Date(item.created_at).toLocaleString('ru-RU');
    
    tr.innerHTML = `
        <td><strong>${item.id}</strong></td>
        <td>${createdDate}</td>
        <td><span class="badge bg-info">${item.bonus_rate}%</span></td>
        <td><small class="text-muted">${createdAt}</small></td>
    `;
    
    return tr;
}

/**
 * Добавление нового процента премии за клиента
 */
async function addClientBonus(event) {
    event.preventDefault();
    
    const bonusRateInput = document.getElementById('bonusRate');
    const bonusRate = parseFloat(bonusRateInput.value);
    
    // Валидация
    if (isNaN(bonusRate) || bonusRate < 0 || bonusRate > 100) {
        showClientBonusError('Процент должен быть числом от 0 до 100');
        return;
    }
    
    const btn = document.getElementById('addClientBonusBtn');
    const originalText = btn.innerHTML;
    
    // Показываем индикатор загрузки
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Добавление...';
    
    try {
        const response = await fetch(`api.php?action=client_bonus_add&member_id=${memberId}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ 
                bonus_rate: bonusRate 
            })
        });
        
        let result;
        try {
            const responseText = await response.text();
            console.log('Raw response:', responseText); // Отладочный вывод
            
            if (!responseText.trim()) {
                showClientBonusError('Пустой ответ от сервера');
                btn.innerHTML = originalText;
                btn.disabled = false;
                return;
            }
            
            result = JSON.parse(responseText);
        } catch (parseError) {
            console.error('JSON parse error:', parseError);
            showClientBonusError('Ошибка парсинга JSON: ' + parseError.message);
            btn.innerHTML = originalText;
            btn.disabled = false;
            return;
        }
        
        if (!result.success) {
            showClientBonusError('Ошибка добавления: ' + (result.error || 'Неизвестная ошибка'));
            btn.innerHTML = originalText;
            btn.disabled = false;
            return;
        }
        
        // Успешно добавлено
        showClientBonusSuccess(`Новый процент ${result.bonus_rate}% успешно добавлен с датой ${result.created_date}`);
        
        // Очищаем форму
        bonusRateInput.value = '';
        
        // Перезагружаем данные
        loadClientBonusData();
        
        btn.innerHTML = originalText;
        btn.disabled = false;
        
    } catch (error) {
        showClientBonusError('Ошибка подключения: ' + error.message);
        btn.innerHTML = originalText;
        btn.disabled = false;
    }
}

/**
 * Скрыть индикатор загрузки для бонусов за клиента
 */
function hideClientBonusLoading() {
    document.getElementById('clientBonusLoadingSpinner').style.display = 'none';
}

/**
 * Показать ошибку для бонусов за клиента
 */
function showClientBonusError(message) {
    const errorDiv = document.getElementById('clientBonusErrorMessage');
    const errorText = document.getElementById('clientBonusErrorText');
    errorText.textContent = message;
    errorDiv.style.display = 'block';

    setTimeout(() => {
        errorDiv.style.display = 'none';
    }, 5000);
}

/**
 * Показать успех для бонусов за клиента
 */
function showClientBonusSuccess(message) {
    const successDiv = document.getElementById('clientBonusSuccessMessage');
    const successText = document.getElementById('clientBonusSuccessText');
    successText.innerHTML = message;
    successDiv.style.display = 'block';

    setTimeout(() => {
        successDiv.style.display = 'none';
    }, 5000);
}

/**
 * Экранирование HTML
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
