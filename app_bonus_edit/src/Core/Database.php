<?php

namespace App\Core;

/**
 * Класс для управления подключением к базе данных
 */
class Database
{
    private static $instance = null;
    private $connection;

    private function __construct()
    {
        $config = Config::getInstance();
        $dbConfig = $config->get('db');

        if (!$dbConfig) {
            throw new \Exception('Database configuration not found');
        }

        $this->connection = new \mysqli(
            $dbConfig['servername'],
            $dbConfig['username'],
            $dbConfig['password'],
            $dbConfig['dbname']
        );

        if ($this->connection->connect_error) {
            throw new \Exception('Database connection failed: ' . $this->connection->connect_error);
        }

        $this->connection->set_charset("utf8mb4");
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Получить mysqli соединение
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * Выполнить запрос
     */
    public function query($sql)
    {
        return $this->connection->query($sql);
    }

    /**
     * Подготовить prepared statement
     */
    public function prepare($sql)
    {
        return $this->connection->prepare($sql);
    }

    /**
     * Начать транзакцию
     */
    public function beginTransaction()
    {
        return $this->connection->begin_transaction();
    }

    /**
     * Подтвердить транзакцию
     */
    public function commit()
    {
        return $this->connection->commit();
    }

    /**
     * Откатить транзакцию
     */
    public function rollback()
    {
        return $this->connection->rollback();
    }

    /**
     * Получить последнюю ошибку
     */
    public function getError()
    {
        return $this->connection->error;
    }
}
