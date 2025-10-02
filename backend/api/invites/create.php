<?php
// backend/api/invites/create.php

require_once dirname(__DIR__, 2) . '/lib/Auth.php';
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

$auth = new Auth();
$user = $auth->requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Метод не разрешен', 405);
}

$data = json_decode(file_get_contents('php://input'), true);
$phone = $data['phone'] ?? null;

$inviteManager = new InviteManager();

if ($phone) {
    // Отправляем инвайт по SMS
    $result = $inviteManager->sendInvite($user['id'], $phone);
} else {
    // Просто создаем инвайт-код
    $result = $inviteManager->createInvite($user['id']);
}

Response::json($result);