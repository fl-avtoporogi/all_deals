<?php

namespace App\Controllers;

use App\Services\ClientBonusService;
use App\Utils\AccessControl;
use App\Utils\Response;

/**
 * Контроллер для обработки HTTP запросов к API процентов премии за клиента
 */
class ClientBonusController
{
    private $clientBonusService;
    private $accessControl;

    public function __construct()
    {
        $this->clientBonusService = new ClientBonusService();
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
     * GET /api.php?action=client_bonus_list
     * Получить список всех процентов премии за клиента
     */
    public function list()
    {
        $userId = $this->checkAccess();

        try {
            $rates = $this->clientBonusService->getAllClientBonusRates();
            $stats = $this->clientBonusService->getClientBonusStats();

            Response::success([
                'data' => $rates,
                'stats' => $stats
            ]);

        } catch (\Exception $e) {
            Response::serverError('Database error: ' . $e->getMessage());
        }
    }

    /**
     * POST /api.php?action=client_bonus_add
     * Добавить новый процент премии за клиента
     */
    public function add()
    {
        $userId = $this->checkAccess();

        // Получаем данные из POST
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if (!$data || !isset($data['bonus_rate'])) {
            Response::error('Invalid data format. Expected: {"bonus_rate": 5.0}', 400);
        }

        $bonusRate = $data['bonus_rate'];
        $createdDate = $data['created_date'] ?? null;

        try {
            $result = $this->clientBonusService->addClientBonusRate($bonusRate, $createdDate);

            if ($result['success']) {
                Response::success([
                    'id' => $result['inserted_id'],
                    'created_date' => $result['created_date'],
                    'bonus_rate' => $result['bonus_rate']
                ]);
            } else {
                Response::error('Failed to add client bonus rate', 400, [
                    'errors' => $result['errors']
                ]);
            }

        } catch (\Exception $e) {
            Response::serverError('Transaction failed: ' . $e->getMessage());
        }
    }

    /**
     * GET /api.php?action=client_bonus_current
     * Получить текущий (актуальный) процент премии за клиента
     */
    public function current()
    {
        $userId = $this->checkAccess();

        try {
            $currentRate = $this->clientBonusService->getCurrentClientBonusRate();

            if ($currentRate !== null) {
                Response::success([
                    'bonus_rate' => $currentRate
                ]);
            } else {
                Response::error('No client bonus rates found', 404);
            }

        } catch (\Exception $e) {
            Response::serverError('Database error: ' . $e->getMessage());
        }
    }

    /**
     * Обработать запрос на основе action параметра для client bonus
     */
    public function handleRequest()
    {
        $action = $_GET['action'] ?? '';

        switch ($action) {
            case 'client_bonus_list':
                $this->list();
                break;

            case 'client_bonus_add':
                $this->add();
                break;

            case 'client_bonus_current':
                $this->current();
                break;

            default:
                Response::error('Invalid action for client bonus', 400);
                break;
        }
    }
}
