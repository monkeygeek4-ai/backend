<?php
// backend/api/auth/login.php

require_once dirname(__DIR__, 2) . '/lib/Auth.php';
require_once dirname(__DIR__, 2) . '/lib/Response.php';

// Проверяем метод
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Метод не разрешен', 405);
}

// Получаем данные
$data = json_decode(file_get_contents('php://input'), true);

$username = $data['username'] ?? null;
$password = $data['password'] ?? null;

// Валидация
if (!$username || !$password) {
    Response::error('Требуется имя пользователя и пароль', 400);
}

// Авторизация
$auth = new Auth();

try {
    $result = $auth->login($username, $password);
    
    if (!$result) {
        Response::error('Неверное имя пользователя или пароль', 401);
    }
    
    // ВАЖНО: Возвращаем токен из базы данных, а не JWT
    Response::json($result);
} catch (Exception $e) {
    error_log("Login error: " . $e->getMessage());
    Response::error('Ошибка авторизации', 500);
}