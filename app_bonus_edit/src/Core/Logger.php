<?php

namespace App\Core;

/**
 * Класс для логирования событий приложения
 */
class Logger
{
    private $logFile;

    public function __construct()
    {
        $config = Config::getInstance();
        $this->logFile = $config->get('log_file');
    }

    /**
     * Записать лог
     */
    public function log($userId, $action, $changes)
    {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = sprintf(
            "[%s] User: %s | Action: %s | Changes: %s\n",
            $timestamp,
            $userId,
            $action,
            json_encode($changes, JSON_UNESCAPED_UNICODE)
        );

        // Создаем папку logs если её нет
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        file_put_contents($this->logFile, $logEntry, FILE_APPEND);
    }

    /**
     * Получить последние записи лога
     */
    public function getLastEntries($count = 100)
    {
        if (!file_exists($this->logFile)) {
            return [];
        }

        $lines = file($this->logFile, FILE_IGNORE_NEW_LINES);
        return array_slice($lines, -$count);
    }
}
