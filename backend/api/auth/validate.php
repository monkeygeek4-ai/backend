<?php
// backend/api/auth/validate.php

require_once dirname(__DIR__, 2) . '/lib/Auth.php';
require_once dirname(__DIR__, 2) . '/lib/Response.php';

// Проверяем метод
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Метод не разрешен', 405);
}

// Получаем заголовки
$headers = apache_request_headers();
$authHeader = null;

// Ищем Authorization заголовок (case-insensitive)
foreach ($headers as $key => $value) {
    if (strtolower($key) === 'authorization') {
        $authHeader = $value;
        break;
    }
}

if (!$authHeader) {
    Response::json([
        'valid' => false,
        'error' => 'Токен не предоставлен'
    ]);
}

// Извлекаем токен
$token = str_replace('Bearer ', '', $authHeader);

// Создаем экземпляр Auth
$auth = new Auth();

// Валидируем токен
$result = $auth->validateToken($token);

// Возвращаем результат
Response::json($result);
?> 