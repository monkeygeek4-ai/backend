<?php
// backend/api/invites/delete.php
// Удаление инвайта (только если он не использован)

require_once dirname(__DIR__, 2) . '/lib/Auth.php';
require_once dirname(__DIR__, 2) . '/lib/SimpleInviteManager.php';
require_once dirname(__DIR__, 2) . '/lib/Response.php';

// Устанавливаем заголовки
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Обработка preflight запроса
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Требуем авторизацию
$auth = new Auth();
$user = $auth->requireAuth();

// Поддерживаем и POST и DELETE методы
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Метод не разрешен', 405);
}

// Получаем код инвайта из URL параметров
$inviteCode = $_GET['code'] ?? null;

if (!$inviteCode) {
    Response::error('Код инвайта не указан', 400);
}

// Удаляем инвайт
$inviteManager = new SimpleInviteManager();
$result = $inviteManager->deleteInvite($inviteCode, $user['id']);

// Логируем
if ($result['success']) {
    error_log("User {$user['id']} deleted invite: $inviteCode");
} else {
    error_log("User {$user['id']} failed to delete invite $inviteCode: " . ($result['error'] ?? 'unknown error'));
}

Response::json($result);