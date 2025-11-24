<?php

namespace App\Core;

/**
 * Класс для управления кэшем приложения
 */
class Cache
{
    private $cacheFile;

    public function __construct()
    {
        $config = Config::getInstance();
        $this->cacheFile = $config->get('cache_file');
    }

    /**
     * Получить данные из кэша
     */
    public function get($key = null)
    {
        if (!file_exists($this->cacheFile)) {
            return null;
        }

        $data = json_decode(file_get_contents($this->cacheFile), true);

        if ($key === null) {
            return $data;
        }

        return $data[$key] ?? null;
    }

    /**
     * Сохранить данные в кэш
     */
    public function set($key, $value)
    {
        $data = $this->get() ?? [];
        $data[$key] = $value;

        file_put_contents($this->cacheFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /**
     * Удалить кэш
     */
    public function clear($key = null)
    {
        if ($key === null) {
            // Удаляем весь кэш
            if (file_exists($this->cacheFile)) {
                unlink($this->cacheFile);
            }
        } else {
            // Удаляем конкретный ключ
            $data = $this->get() ?? [];
            if (isset($data[$key])) {
                unset($data[$key]);
                file_put_contents($this->cacheFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
        }
    }

    /**
     * Проверить существование кэша
     */
    public function exists($key = null)
    {
        if (!file_exists($this->cacheFile)) {
            return false;
        }

        if ($key === null) {
            return true;
        }

        $data = $this->get();
        return isset($data[$key]);
    }
}
