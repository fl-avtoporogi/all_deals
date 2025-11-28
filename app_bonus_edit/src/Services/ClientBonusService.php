<?php

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;

/**
 * Сервис для работы с процентами премии за клиента
 */
class ClientBonusService
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Получить все записи процентов премии за клиента
     * @return array
     */
    public function getAllClientBonusRates()
    {
        $query = "SELECT id, created_date, bonus_rate, created_at 
                  FROM bonus_clients 
                  ORDER BY created_date DESC";
        
        $result = $this->db->query($query);
        
        if (!$result) {
            // Logger::error("Failed to get client bonus rates: " . $this->db->error); // Закомментировано
            return [];
        }

        $rates = [];
        while ($row = $result->fetch_assoc()) {
            $rates[] = [
                'id' => $row['id'],
                'created_date' => $row['created_date'],
                'bonus_rate' => floatval($row['bonus_rate']),
                'created_at' => $row['created_at']
            ];
        }

        return $rates;
    }

    /**
     * Получить текущий (актуальный) процент премии за клиента
     * @return float|null
     */
    public function getCurrentClientBonusRate()
    {
        $query = "SELECT bonus_rate 
                  FROM bonus_clients 
                  ORDER BY created_date DESC 
                  LIMIT 1";
        
        $result = $this->db->query($query);
        
        if (!$result) {
            // Logger::error("Failed to get current client bonus rate: " . $this->db->error); // Закомментировано
            return null;
        }

        $row = $result->fetch_assoc();
        return $row ? floatval($row['bonus_rate']) : null;
    }

    /**
     * Добавить новый процент премии за клиента
     * @param float $bonusRate Процент премии (0-100)
     * @param string $createdDate Дата установки (в формате YYYY-MM-DD)
     * @return array
     */
    public function addClientBonusRate($bonusRate, $createdDate = null)
    {
        $errors = [];

        // Валидация
        if (!is_numeric($bonusRate) || $bonusRate < 0 || $bonusRate > 100) {
            $errors[] = "Процент должен быть числом от 0 до 100";
        }

        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        // Используем текущую дату если не указана
        if ($createdDate === null) {
            $createdDate = date('Y-m-d');
        }

        // Валидация формата даты
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $createdDate)) {
            $errors[] = "Некорректный формат даты";
        }

        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        // Подготовленный запрос для вставки
        $stmt = $this->db->prepare(
            "INSERT INTO bonus_clients (created_date, bonus_rate) VALUES (?, ?)"
        );

        if (!$stmt) {
            // Logger::error("Prepare failed: " . $this->db->error); // Закомментировано
            return ['success' => false, 'errors' => ['Ошибка подготовки запроса']];
        }

        $bonusRateFormatted = number_format($bonusRate, 2, '.', '');
        
        $bind = $stmt->bind_param("sd", $createdDate, $bonusRateFormatted);
        
        if (!$bind) {
            // Logger::error("Bind failed: " . $stmt->error); // Закомментировано
            return ['success' => false, 'errors' => ['Ошибка привязки параметров']];
        }

        $execute = $stmt->execute();
        
        if (!$execute) {
            // Logger::error("Execute failed: " . $stmt->error); // Закомментировано
            return ['success' => false, 'errors' => ['Ошибка выполнения запроса']];
        }

        $insertedId = $this->db->insert_id;
        $stmt->close();

        // Logger::info("Client bonus rate added: {$bonusRate}% for date {$createdDate}"); // Закомментировано чтобы не портить JSON

        return [
            'success' => true,
            'inserted_id' => $insertedId,
            'created_date' => $createdDate,
            'bonus_rate' => floatval($bonusRateFormatted)
        ];
    }

    /**
     * Получить статистику по процентам премии
     * @return array
     */
    public function getClientBonusStats()
    {
        $query = "SELECT 
                    COUNT(*) as total_records,
                    MIN(bonus_rate) as min_rate,
                    MAX(bonus_rate) as max_rate,
                    AVG(bonus_rate) as avg_rate
                  FROM bonus_clients";
        
        $result = $this->db->query($query);
        
        if (!$result) {
            // Logger::error("Failed to get client bonus stats: " . $this->db->error); // Закомментировано
            return [];
        }

        $row = $result->fetch_assoc();
        
        return [
            'total_records' => intval($row['total_records']),
            'min_rate' => $row['min_rate'] ? floatval($row['min_rate']) : 0,
            'max_rate' => $row['max_rate'] ? floatval($row['max_rate']) : 0,
            'avg_rate' => $row['avg_rate'] ? floatval($row['avg_rate']) : 0
        ];
    }
}
