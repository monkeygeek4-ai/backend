<?php
// backend/api/notifications/register.php
// Регистрация FCM токена

require_once __DIR__ . '/../../lib/Database.php';
require_once __DIR__ . '/../../lib/Auth.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Обработка preflight запросов
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Только POST запросы
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

error_log("========================================");
error_log("📱 FCM TOKEN REGISTRATION REQUEST");
error_log("========================================");

try {
    // Получаем данные запроса
    $input = file_get_contents('php://input');
    error_log("Raw input: $input");
    
    $data = json_decode($input, true);
    
    if (!$data) {
        error_log("❌ Failed to decode JSON");
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
        exit;
    }
    
    error_log("Decoded data: " . json_encode($data));
    
    // Проверяем авторизацию
    $user = getUserFromToken();
    
    if (!$user) {
        error_log("❌ Unauthorized - no valid token");
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    
    $userId = $user['id'];
    error_log("✅ User authenticated: ID=$userId, Username=" . $user['username']);
    
    // Получаем параметры
    $token = $data['token'] ?? null;
    $platform = $data['platform'] ?? 'unknown';
    
    error_log("FCM Token: " . ($token ? substr($token, 0, 30) . "..." : "NULL"));
    error_log("Platform: $platform");
    
    // Валидация
    if (!$token) {
        error_log("❌ Token is required");
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Token is required']);
        exit;
    }
    
    // Подключение к БД
    $db = Database::getInstance();
    
    // Проверяем существует ли такой токен для этого пользователя
    error_log("🔍 Checking if token already exists...");
    
    $existing = $db->fetchOne(
        "SELECT id FROM fcm_tokens WHERE user_id = :user_id AND token = :token",
        [
            'user_id' => $userId,
            'token' => $token
        ]
    );
    
    if ($existing) {
        error_log("♻️ Token already exists (ID: {$existing['id']}), updating...");
        
        // Обновляем timestamp
        $db->execute(
            "UPDATE fcm_tokens 
             SET updated_at = CURRENT_TIMESTAMP, 
                 platform = :platform
             WHERE user_id = :user_id AND token = :token",
            [
                'user_id' => $userId,
                'token' => $token,
                'platform' => $platform
            ]
        );
        
        error_log("✅ Token updated successfully");
        $tokenId = $existing['id'];
    } else {
        error_log("➕ Token is new, inserting...");
        
        // Вставляем новый токен
        $db->execute(
            "INSERT INTO fcm_tokens (user_id, token, platform, created_at, updated_at) 
             VALUES (:user_id, :token, :platform, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
            [
                'user_id' => $userId,
                'token' => $token,
                'platform' => $platform
            ]
        );
        
        // Получаем ID вставленной записи
        $tokenId = $db->getLastInsertId();
        
        error_log("✅ Token inserted successfully (ID: $tokenId)");
    }
    
    // Проверяем что токен действительно сохранился
    $saved = $db->fetchOne(
        "SELECT id, user_id, platform, created_at 
         FROM fcm_tokens 
         WHERE user_id = :user_id AND token = :token",
        [
            'user_id' => $userId,
            'token' => $token
        ]
    );
    
    if ($saved) {
        error_log("========================================");
        error_log("✅✅✅ FCM TOKEN SAVED SUCCESSFULLY!");
        error_log("  Token ID: " . $saved['id']);
        error_log("  User ID: " . $saved['user_id']);
        error_log("  Platform: " . $saved['platform']);
        error_log("  Created: " . $saved['created_at']);
        error_log("========================================");
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'FCM token registered successfully',
            'tokenId' => $saved['id']
        ]);
    } else {
        error_log("========================================");
        error_log("⚠️⚠️⚠️ TOKEN NOT FOUND AFTER SAVE!");
        error_log("This should never happen!");
        error_log("========================================");
        
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Token saved but not found'
        ]);
    }
    
} catch (Exception $e) {
    error_log("========================================");
    error_log("❌❌❌ EXCEPTION IN FCM REGISTRATION!");
    error_log("Message: " . $e->getMessage());
    error_log("File: " . $e->getFile());
    error_log("Line: " . $e->getLine());
    error_log("Trace: " . $e->getTraceAsString());
    error_log("========================================");
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error: ' . $e->getMessage()
    ]);
}
