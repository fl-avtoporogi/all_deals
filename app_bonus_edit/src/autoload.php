<?php
/**
 * PSR-4 Autoloader для приложения Bonus Edit
 *
 * Автоматически загружает классы по namespace
 */

spl_autoload_register(function ($class) {
    // Базовый namespace проекта
    $prefix = 'App\\';

    // Базовая директория для namespace
    $base_dir = __DIR__ . '/';

    // Проверяем, использует ли класс namespace prefix
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        // Нет, переходим к следующему autoloader
        return;
    }

    // Получаем относительное имя класса
    $relative_class = substr($class, $len);

    // Заменяем namespace separator на directory separator
    // и добавляем .php
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    // Если файл существует, загружаем его
    if (file_exists($file)) {
        require $file;
    }
});
