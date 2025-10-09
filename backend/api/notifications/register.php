<?php
// backend/api/notifications/register.php

$logFile = __DIR__ . '/../../logs/fcm_register.log';

require_once dirname(__DIR__, 2) . '/lib/Auth.php';
require_once dirname(__DIR__, 2) . '/lib/Database.php';
require_once dirname(__DIR__, 2) . '/lib/Response.php';

try {
    $auth = new Auth();
    $user = $auth->requireAuth();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        Response::error('Метод не разрешен', 405);
    }

    $data = json_decode(file_get_contents('php://input'), true);

    $token = $data['token'] ?? null;
    $platform = $data['platform'] ?? 'unknown';

    if (!$token) {
        Response::error('Токен обязателен', 400);
    }

    $db = Database::getInstance();

    // Проверяем, существует ли уже такой токен
    $existing = $db->fetchOne(
        "SELECT * FROM fcm_tokens 
         WHERE user_id = :user_id AND token = :token",
        [
            'user_id' => $user['id'],
            'token' => $token
        ]
    );
    
    if ($existing) {
        // Обновляем timestamp
        $db->execute(
            "UPDATE fcm_tokens 
             SET platform = :platform, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id",
            [
                'id' => $existing['id'],
                'platform' => $platform
            ]
        );
    } else {
        // Добавляем новый токен - ИСПРАВЛЕНО!
        $db->execute(
            "INSERT INTO fcm_tokens (user_id, token, platform) 
             VALUES (:user_id, :token, :platform)",
            [
                'user_id' => $user['id'],
                'token' => $token,
                'platform' => $platform
            ]
        );
    }
    
    Response::json([
        'success' => true,
        'message' => 'Токен зарегистрирован'
    ]);
    
} catch (Exception $e) {
    file_put_contents($logFile, date('Y-m-d H:i:s') . " ERROR: {$e->getMessage()}\n", FILE_APPEND);
    Response::error('Ошибка регистрации токена', 500);
}