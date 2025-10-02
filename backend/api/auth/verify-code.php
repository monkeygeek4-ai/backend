<?php
// backend/api/auth/verify-code.php

require_once dirname(__DIR__, 2) . '/lib/InviteManager.php';
require_once dirname(__DIR__, 2) . '/lib/Auth.php';
require_once dirname(__DIR__, 2) . '/lib/Database.php';
require_once dirname(__DIR__, 2) . '/lib/Response.php';

// Устанавливаем заголовки
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Обработка preflight запроса
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Метод не разрешен', 405);
}

$data = json_decode(file_get_contents('php://input'), true);
$phone = $data['phone'] ?? null;
$code = $data['code'] ?? null;
$username = $data['username'] ?? null;
$password = $data['password'] ?? null;
$fullName = $data['fullName'] ?? null;

if (!$phone || !$code) {
    Response::error('Номер телефона и код обязательны', 400);
}

$inviteManager = new InviteManager();
$db = Database::getInstance();

// Проверяем код
$verification = $inviteManager->verifyCode($phone, $code);

if (!$verification['success']) {
    Response::json($verification);
    exit;
}

// Если это регистрация (переданы username и password)
if ($username && $password) {
    // Проверяем наличие инвайт-кода
    $inviteCode = $verification['inviteCode'];
    
    if (!$inviteCode) {
        Response::json([
            'success' => false,
            'error' => 'Регистрация возможна только по инвайту'
        ]);
        exit;
    }
    
    // Валидируем инвайт
    $inviteValidation = $inviteManager->validateInvite($inviteCode);
    if (!$inviteValidation['valid']) {
        Response::json([
            'success' => false,
            'error' => $inviteValidation['error']
        ]);
        exit;
    }
    
    // Создаем пользователя
    $auth = new Auth();
    
    // Используем телефон как email если email не указан
    $email = $phone . '@phone.local';
    
    $registerResult = $auth->register($username, $email, $password, $fullName);
    
    if ($registerResult['success']) {
        $userId = $registerResult['user']['id'];
        
        // Обновляем номер телефона и статус верификации
        $db->execute(
            "UPDATE users 
             SET phone = :phone, 
                 phone_verified = true,
                 invited_by = :invited_by
             WHERE id = :id",
            [
                'phone' => $phone,
                'invited_by' => $inviteValidation['invite']['created_by'],
                'id' => $userId
            ]
        );
        
        // Отмечаем инвайт как использованный
        $inviteManager->markInviteAsUsed($inviteCode, $userId);
        
        // Обновляем данные пользователя в ответе
        $registerResult['user']['phone'] = $phone;
        $registerResult['user']['phone_verified'] = true;
        
        Response::json($registerResult);
    } else {
        Response::json($registerResult);
    }
} else {
    // Просто подтверждение кода без регистрации
    Response::json([
        'success' => true,
        'verified' => true,
        'inviteCode' => $verification['inviteCode']
    ]);
}