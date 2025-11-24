<?php

namespace App\Utils;

use App\Core\Config;

/**
 * Класс для управления правами доступа
 */
class AccessControl
{
    private $whitelist;

    public function __construct()
    {
        $config = Config::getInstance();
        $this->whitelist = $config->get('whitelist_user_ids', []);
    }

    /**
     * Проверить доступ пользователя
     */
    public function checkAccess($userId)
    {
        // Если белый список пуст - доступ всем
        if (empty($this->whitelist)) {
            return true;
        }

        // Проверка наличия user_id в белом списке
        return in_array($userId, $this->whitelist);
    }

    /**
     * Добавить пользователя в белый список
     */
    public function addUser($userId)
    {
        if (!in_array($userId, $this->whitelist)) {
            $this->whitelist[] = $userId;
        }
    }

    /**
     * Удалить пользователя из белого списка
     */
    public function removeUser($userId)
    {
        $key = array_search($userId, $this->whitelist);
        if ($key !== false) {
            unset($this->whitelist[$key]);
            $this->whitelist = array_values($this->whitelist);
        }
    }
}
