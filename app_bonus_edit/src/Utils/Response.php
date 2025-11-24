<?php

namespace App\Utils;

/**
 * Класс для формирования HTTP ответов
 */
class Response
{
    /**
     * Отправить JSON ответ
     */
    public static function json($data, $statusCode = 200)
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Отправить успешный ответ
     */
    public static function success($data = [], $message = null)
    {
        $response = ['success' => true];

        if ($message) {
            $response['message'] = $message;
        }

        if (!empty($data)) {
            $response = array_merge($response, $data);
        }

        self::json($response);
    }

    /**
     * Отправить ошибку
     */
    public static function error($message, $statusCode = 400, $details = [])
    {
        $response = [
            'success' => false,
            'error' => $message
        ];

        if (!empty($details)) {
            $response['details'] = $details;
        }

        self::json($response, $statusCode);
    }

    /**
     * Отправить ошибку валидации
     */
    public static function validationError($errors)
    {
        self::error('Validation failed', 422, ['validation_errors' => $errors]);
    }

    /**
     * Отправить 403 (Доступ запрещен)
     */
    public static function forbidden($message = 'Access denied')
    {
        self::error($message, 403);
    }

    /**
     * Отправить 404 (Не найдено)
     */
    public static function notFound($message = 'Not found')
    {
        self::error($message, 404);
    }

    /**
     * Отправить 500 (Внутренняя ошибка сервера)
     */
    public static function serverError($message = 'Internal server error')
    {
        self::error($message, 500);
    }
}
