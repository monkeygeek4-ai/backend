<?php
// backend/api/invites/delete.php

require_once dirname(__DIR__, 2) . '/lib/Auth.php';
require_once dirname(__DIR__, 2) . '/lib/Database.php';
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

$auth = new Auth();
$user = $auth->requireAuth();

// ИСПРАВЛЕНО: Поддерживаем и POST и DELETE
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Метод не разрешен', 405);
}

// Получаем код инвайта из URL
$inviteCode = $_GET['code'] ?? null;

if (!$inviteCode) {
    Response::error('Код инвайта не указан', 400);
}

$db = Database::getInstance();

try {
    // Проверяем, принадлежит ли инвайт пользователю
    $invite = $db->fetch(
        "SELECT * FROM invites WHERE code = :code AND created_by = :user_id",
        [
            'code' => $inviteCode,
            'user_id' => $user['id']
        ]
    );
    
    if (!$invite) {
        Response::error('Инвайт не найден или не принадлежит вам', 404);
    }
    
    // Проверяем, не использован ли инвайт
    if ($invite['is_used']) {
        Response::error('Нельзя удалить использованный инвайт', 400);
    }
    
    // Удаляем инвайт
    $db->execute(
        "DELETE FROM invites WHERE code = :code",
        ['code' => $inviteCode]
    );
    
    Response::json([
        'success' => true,
        'message' => 'Инвайт удален'
    ]);
    
} catch (Exception $e) {
    error_log("Delete invite error: " . $e->getMessage());
    Response::error('Ошибка удаления инвайта', 500);
}