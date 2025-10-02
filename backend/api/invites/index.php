<?php
// backend/api/invites/index.php
// Получение списка инвайтов текущего пользователя

require_once dirname(__DIR__, 2) . '/lib/Auth.php';
require_once dirname(__DIR__, 2) . '/lib/SimpleInviteManager.php';
require_once dirname(__DIR__, 2) . '/lib/Response.php';

// Устанавливаем заголовки
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Обработка preflight запроса
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Только GET запросы
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('Метод не разрешен', 405);
}

// Требуем авторизацию
$auth = new Auth();
$user = $auth->requireAuth();

// Получаем список инвайтов пользователя
$inviteManager = new SimpleInviteManager();
$result = $inviteManager->getUserInvites($user['id']);

// Логируем
if ($result['success']) {
    $count = count($result['invites']);
    error_log("User {$user['id']} has $count invites");
}

Response::json($result);