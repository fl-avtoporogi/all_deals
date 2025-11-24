<?php

namespace App\Services;

use App\Repository\BonusRepository;
use App\Core\Logger;
use App\Core\Cache;

/**
 * Сервис для работы с бизнес-логикой бонусов
 */
class BonusService
{
    private $repository;
    private $logger;
    private $cache;

    public function __construct()
    {
        $this->repository = new BonusRepository();
        $this->logger = new Logger();
        $this->cache = new Cache();
    }

    /**
     * Получить все коды бонусов
     */
    public function getAllBonuses()
    {
        return $this->repository->findAll();
    }

    /**
     * Обновить значения бонусов
     */
    public function updateBonuses($codes, $userId)
    {
        // Валидация данных
        $validatedCodes = $this->validateBonusCodes($codes);

        if (empty($validatedCodes['valid'])) {
            return [
                'success' => false,
                'updated' => 0,
                'errors' => $validatedCodes['errors']
            ];
        }

        // Обновляем только валидные коды
        try {
            $updated = $this->repository->updateBatch($validatedCodes['valid']);

            // Логируем изменения
            $this->logger->log($userId, 'update', $validatedCodes['valid']);

            // Инвалидируем кэш
            $this->cache->clear();

            return [
                'success' => true,
                'updated' => $updated,
                'errors' => $validatedCodes['errors']
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'updated' => 0,
                'errors' => ['Database error: ' . $e->getMessage()]
            ];
        }
    }

    /**
     * Валидация кодов бонусов
     */
    private function validateBonusCodes($codes)
    {
        $valid = [];
        $errors = [];

        foreach ($codes as $item) {
            // Проверка структуры данных
            if (!isset($item['code']) || !isset($item['bonus'])) {
                $errors[] = "Missing code or bonus field";
                continue;
            }

            $code = trim($item['code']);
            $bonus = floatval($item['bonus']);

            // Проверка пустого кода
            if (empty($code)) {
                $errors[] = "Empty code";
                continue;
            }

            // Проверка значения бонуса
            if ($bonus < 0) {
                $errors[] = "Invalid bonus value for code {$code}";
                continue;
            }

            // Проверка существования кода в БД
            if (!$this->repository->exists($code)) {
                $errors[] = "Code '{$code}' does not exist in database";
                continue;
            }

            $valid[] = [
                'code' => $code,
                'bonus' => $bonus
            ];
        }

        return [
            'valid' => $valid,
            'errors' => $errors
        ];
    }

    /**
     * Получить бонус по коду
     */
    public function getBonusByCode($code)
    {
        return $this->repository->findByCode($code);
    }
}
