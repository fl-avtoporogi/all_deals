<?php

namespace App\Repository;

use App\Core\Database;

/**
 * Репозиторий для работы с кодами бонусов
 */
class BonusRepository
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Получить все коды бонусов
     */
    public function findAll()
    {
        // Получаем все коды без сортировки
        $query = "SELECT code, bonus_amount FROM bonus_codes";
        $result = $this->db->query($query);

        if (!$result) {
            throw new \Exception('Database query failed: ' . $this->db->getError());
        }

        $codes = [];
        while ($row = $result->fetch_assoc()) {
            $codes[] = [
                'code' => $row['code'],
                'bonus' => floatval($row['bonus_amount'])
            ];
        }

        // Применяем natural sort для правильной сортировки (A1, A2, ..., A9, A10, A11)
        usort($codes, function($a, $b) {
            return strnatcmp($a['code'], $b['code']);
        });

        return $codes;
    }

    /**
     * Получить код бонуса по коду
     */
    public function findByCode($code)
    {
        $stmt = $this->db->prepare("SELECT code, bonus_amount FROM bonus_codes WHERE code = ?");
        $stmt->bind_param("s", $code);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            return [
                'code' => $row['code'],
                'bonus' => floatval($row['bonus_amount'])
            ];
        }

        return null;
    }

    /**
     * Проверить существование кода
     */
    public function exists($code)
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) as cnt FROM bonus_codes WHERE code = ?");
        $stmt->bind_param("s", $code);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        return $row['cnt'] > 0;
    }

    /**
     * Обновить значение бонуса
     */
    public function update($code, $bonusAmount)
    {
        $stmt = $this->db->prepare("UPDATE bonus_codes SET bonus_amount = ? WHERE code = ?");
        $stmt->bind_param("ds", $bonusAmount, $code);

        if (!$stmt->execute()) {
            throw new \Exception('Failed to update bonus: ' . $stmt->error);
        }

        return $stmt->affected_rows > 0;
    }

    /**
     * Массовое обновление бонусов
     */
    public function updateBatch($codes)
    {
        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare("UPDATE bonus_codes SET bonus_amount = ? WHERE code = ?");
            $updated = 0;

            foreach ($codes as $item) {
                $code = $item['code'];
                $bonus = $item['bonus'];

                $stmt->bind_param("ds", $bonus, $code);

                if ($stmt->execute() && $stmt->affected_rows > 0) {
                    $updated++;
                }
            }

            $stmt->close();
            $this->db->commit();

            return $updated;

        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Создать новый код бонуса (если понадобится в будущем)
     */
    public function create($code, $bonusAmount)
    {
        $stmt = $this->db->prepare("INSERT INTO bonus_codes (code, bonus_amount) VALUES (?, ?)");
        $stmt->bind_param("sd", $code, $bonusAmount);

        if (!$stmt->execute()) {
            throw new \Exception('Failed to create bonus: ' . $stmt->error);
        }

        return true;
    }

    /**
     * Удалить код бонуса (если понадобится в будущем)
     */
    public function delete($code)
    {
        $stmt = $this->db->prepare("DELETE FROM bonus_codes WHERE code = ?");
        $stmt->bind_param("s", $code);

        if (!$stmt->execute()) {
            throw new \Exception('Failed to delete bonus: ' . $stmt->error);
        }

        return $stmt->affected_rows > 0;
    }
}
