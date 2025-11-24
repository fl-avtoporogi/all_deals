<?php

namespace App\Core;

/**
 * Класс для управления конфигурацией приложения
 */
class Config
{
    private static $instance = null;
    private $config = [];

    private function __construct()
    {
        $this->loadConfig();
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Загрузка конфигурации
     */
    private function loadConfig()
    {
        // Базовая конфигурация
        $this->config = [
            'whitelist_user_ids' => [],
            'cache_file' => __DIR__ . '/../../bonus_codes_cache.json',
            'log_file' => __DIR__ . '/../../logs/bonus_changes.log',
        ];

        // Загружаем db_connect.php
        $this->loadDatabaseConfig();
    }

    /**
     * Загрузка конфигурации базы данных
     */
    private function loadDatabaseConfig()
    {
        // Пытаемся найти db_connect.php в 2 местах
        $paths = [
            __DIR__ . '/../../../db_connect.php',
            __DIR__ . '/../../../../db_connect.php',
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                require_once $path;
                if (isset($config['db'])) {
                    $this->config['db'] = $config['db'];
                    return;
                }
            }
        }

        throw new \Exception('db_connect.php not found');
    }

    /**
     * Получить значение конфигурации
     */
    public function get($key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Установить значение конфигурации
     */
    public function set($key, $value)
    {
        $this->config[$key] = $value;
    }

    /**
     * Получить всю конфигурацию
     */
    public function all()
    {
        return $this->config;
    }
}
