<?php
// backend/api/auth/logout.php

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

if ($authHeader) {
    // Извлекаем токен
    $token = str_replace('Bearer ', '', $authHeader);
    
    // Создаем экземпляр Auth
    $auth = new Auth();
    
    // Получаем пользователя по токену
    $user = $auth->getUserByToken($token);
    
    if ($user) {
        // Выполняем logout
        $auth->logout($user['id']);
        
        Response::json([
            'success' => true,
            'message' => 'Выход выполнен успешно'
        ]);
    } else {
        // Даже если токен недействительный, возвращаем успех
        // чтобы клиент мог очистить локальные данные
        Response::json([
            'success' => true,
            'message' => 'Выход выполнен'
        ]);
    }
} else {
    // Если нет токена, все равно возвращаем успех
    Response::json([
        'success' => true,
        'message' => 'Выход выполнен'
    ]);
}