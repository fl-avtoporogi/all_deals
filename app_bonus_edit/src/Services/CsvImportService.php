<?php

namespace App\Services;

use App\Repository\BonusRepository;
use App\Core\Logger;
use App\Core\Cache;

/**
 * Сервис для импорта бонусов из CSV
 */
class CsvImportService
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
     * Импортировать данные из CSV файла
     */
    public function importFromFile($file, $userId)
    {
        // Проверка загрузки файла
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return [
                'success' => false,
                'error' => 'File upload error'
            ];
        }

        // Чтение и парсинг файла
        $content = file_get_contents($file['tmp_name']);

        // Определяем кодировку и конвертируем в UTF-8
        $encoding = mb_detect_encoding($content, ['UTF-8', 'Windows-1251', 'ISO-8859-1'], true);
        if ($encoding !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        }

        $lines = explode("\n", $content);

        // Парсим CSV
        $parseResult = $this->parseCsvLines($lines);

        if (empty($parseResult['valid'])) {
            return [
                'success' => false,
                'error' => 'No valid data to import',
                'errors' => $parseResult['errors'],
                'total_lines' => $parseResult['total_lines']
            ];
        }

        // Импортируем данные
        try {
            $updated = $this->repository->updateBatch($parseResult['valid']);

            // Логируем изменения
            $this->logger->log($userId, 'import_csv', $parseResult['valid']);

            // Инвалидируем кэш
            $this->cache->clear();

            return [
                'success' => true,
                'updated' => $updated,
                'errors' => $parseResult['errors'],
                'total_lines' => $parseResult['total_lines']
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Import failed: ' . $e->getMessage(),
                'errors' => $parseResult['errors'],
                'total_lines' => $parseResult['total_lines']
            ];
        }
    }

    /**
     * Парсинг строк CSV
     */
    private function parseCsvLines($lines)
    {
        $valid = [];
        $errors = [];
        $lineNumber = 0;

        foreach ($lines as $line) {
            $lineNumber++;

            // Пропускаем пустые строки
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            // Пропускаем заголовок (первая строка)
            if ($lineNumber === 1 && (stripos($line, 'Код бонуса') !== false || stripos($line, 'Бонус') !== false)) {
                continue;
            }

            // Парсим строку CSV (разделитель - точка с запятой)
            $parts = explode(';', $line);

            if (count($parts) < 2) {
                $errors[] = "Line {$lineNumber}: Invalid format";
                continue;
            }

            $code = trim($parts[0]);
            $bonus = trim($parts[1]);

            // Пропускаем пустые коды
            if (empty($code)) {
                continue;
            }

            // Валидация бонуса
            if (!is_numeric($bonus)) {
                $errors[] = "Line {$lineNumber}: Invalid bonus value for code {$code}";
                continue;
            }

            $bonus = floatval($bonus);

            if ($bonus < 0) {
                $errors[] = "Line {$lineNumber}: Negative bonus value for code {$code}";
                continue;
            }

            // Проверяем существование кода в БД
            if (!$this->repository->exists($code)) {
                $errors[] = "Line {$lineNumber}: Code '{$code}' does not exist in database";
                continue;
            }

            $valid[] = [
                'code' => $code,
                'bonus' => $bonus
            ];
        }

        return [
            'valid' => $valid,
            'errors' => $errors,
            'total_lines' => $lineNumber
        ];
    }
}
