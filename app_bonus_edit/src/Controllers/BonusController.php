<?php

namespace App\Controllers;

use App\Services\BonusService;
use App\Services\CsvImportService;
use App\Utils\AccessControl;
use App\Utils\Response;

/**
 * Контроллер для обработки HTTP запросов к API бонусов
 */
class BonusController
{
    private $bonusService;
    private $csvImportService;
    private $accessControl;

    public function __construct()
    {
        $this->bonusService = new BonusService();
        $this->csvImportService = new CsvImportService();
        $this->accessControl = new AccessControl();
    }

    /**
     * Получить ID пользователя из запроса
     */
    private function getUserId()
    {
        return $_GET['member_id'] ?? 'unknown';
    }

    /**
     * Проверить права доступа пользователя
     */
    private function checkAccess()
    {
        $userId = $this->getUserId();

        if (!$this->accessControl->checkAccess($userId)) {
            Response::forbidden();
        }

        return $userId;
    }

    /**
     * GET /api.php?action=list
     * Получить список всех кодов бонусов
     */
    public function list()
    {
        $userId = $this->checkAccess();

        try {
            $bonuses = $this->bonusService->getAllBonuses();

            Response::success(['data' => $bonuses]);

        } catch (\Exception $e) {
            Response::serverError('Database error: ' . $e->getMessage());
        }
    }

    /**
     * POST /api.php?action=update
     * Обновить значения бонусов
     */
    public function update()
    {
        $userId = $this->checkAccess();

        // Получаем данные из POST
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if (!$data || !isset($data['codes']) || !is_array($data['codes'])) {
            Response::error('Invalid data format', 400);
        }

        $codes = $data['codes'];

        if (empty($codes)) {
            Response::error('No codes provided', 400);
        }

        try {
            $result = $this->bonusService->updateBonuses($codes, $userId);

            if ($result['success']) {
                Response::success([
                    'updated' => $result['updated'],
                    'errors' => $result['errors']
                ]);
            } else {
                Response::error('Update failed', 500, [
                    'errors' => $result['errors']
                ]);
            }

        } catch (\Exception $e) {
            Response::serverError('Transaction failed: ' . $e->getMessage());
        }
    }

    /**
     * POST /api.php?action=import_csv
     * Импортировать данные из CSV файла
     */
    public function importCsv()
    {
        $userId = $this->checkAccess();

        // Проверяем наличие файла
        if (!isset($_FILES['csv_file'])) {
            Response::error('No file uploaded', 400);
        }

        $file = $_FILES['csv_file'];

        try {
            $result = $this->csvImportService->importFromFile($file, $userId);

            if ($result['success']) {
                Response::success([
                    'updated' => $result['updated'],
                    'errors' => $result['errors'],
                    'total_lines' => $result['total_lines']
                ]);
            } else {
                Response::error($result['error'], 400, [
                    'errors' => $result['errors'] ?? [],
                    'total_lines' => $result['total_lines'] ?? 0
                ]);
            }

        } catch (\Exception $e) {
            Response::serverError('Import failed: ' . $e->getMessage());
        }
    }

    /**
     * Обработать запрос на основе action параметра
     */
    public function handleRequest()
    {
        $action = $_GET['action'] ?? '';

        switch ($action) {
            case 'list':
                $this->list();
                break;

            case 'update':
                $this->update();
                break;

            case 'import_csv':
                $this->importCsv();
                break;

            default:
                Response::error('Invalid action', 400);
                break;
        }
    }
}
