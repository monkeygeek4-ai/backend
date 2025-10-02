<?php
// backend/api/invites/check.php
// Публичный эндпоинт для проверки валидности инвайта
// НЕ требует авторизации

require_once dirname(__DIR__, 2) . '/lib/SimpleInviteManager.php';
require_once dirname(__DIR__, 2) . '/lib/Response.php';

// Устанавливаем заголовки
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Обработка preflight запроса
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Только GET запросы
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('Метод не разрешен', 405);
}

// Получаем код из URL параметров
$code = $_GET['code'] ?? null;

if (!$code) {
    Response::error('Код инвайта не указан', 400);
}

// Проверяем инвайт
$inviteManager = new SimpleInviteManager();
$result = $inviteManager->checkInvite($code);

// Логируем проверку
error_log("Invite check: $code - " . ($result['valid'] ? 'valid' : 'invalid'));

Response::json($result);