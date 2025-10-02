<?php
// backend/api/auth/register.php

require_once dirname(__DIR__, 2) . '/lib/Auth.php';
require_once dirname(__DIR__, 2) . '/lib/Response.php';

// Проверяем метод
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Метод не разрешен', 405);
}

// Получаем данные
$data = json_decode(file_get_contents('php://input'), true);

$username = $data['username'] ?? null;
$email = $data['email'] ?? null;
$password = $data['password'] ?? null;
$fullName = $data['fullName'] ?? $data['full_name'] ?? null;

// Валидация
if (!$username || !$email || !$password) {
    Response::error('Требуется имя пользователя, email и пароль', 400);
}

// Регистрация
$auth = new Auth();

try {
    $result = $auth->register($username, $email, $password, $fullName);
    Response::json($result);
} catch (Exception $e) {
    error_log("Register error: " . $e->getMessage());
    Response::error($e->getMessage(), 400);
}