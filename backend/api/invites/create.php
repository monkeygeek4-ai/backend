<?php
// backend/api/invites/create.php
// Создание нового инвайта-ссылки

require_once dirname(__DIR__, 2) . '/lib/Auth.php';
require_once dirname(__DIR__, 2) . '/lib/SimpleInviteManager.php';
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

// Только POST запросы
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Метод не разрешен', 405);
}

// Требуем авторизацию
$auth = new Auth();
$user = $auth->requireAuth();

// Создаем инвайт
$inviteManager = new SimpleInviteManager();
$result = $inviteManager->createInvite($user['id']);

// Логируем
if ($result['success']) {
    error_log("User {$user['id']} created invite: {$result['code']}");
}

Response::json($result);