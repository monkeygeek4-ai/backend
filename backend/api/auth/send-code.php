<?php
// backend/api/auth/send-code.php

require_once dirname(__DIR__, 2) . '/lib/InviteManager.php';
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
$inviteCode = $data['inviteCode'] ?? null;

if (!$phone) {
    Response::error('Номер телефона обязателен', 400);
}

$inviteManager = new InviteManager();

// Если есть инвайт-код, проверяем его
if ($inviteCode) {
    $validation = $inviteManager->validateInvite($inviteCode);
    if (!$validation['valid']) {
        Response::json([
            'success' => false,
            'error' => $validation['error']
        ]);
        exit;
    }
}

// Создаем и отправляем код
$result = $inviteManager->createVerificationCode($phone, $inviteCode);
Response::json($result);