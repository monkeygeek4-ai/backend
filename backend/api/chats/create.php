<?php
// backend/api/chats/create.php

require_once dirname(__DIR__, 2) . '/lib/Auth.php';
require_once dirname(__DIR__, 2) . '/lib/Database.php';
require_once dirname(__DIR__, 2) . '/lib/Response.php';

// Логирование для отладки
error_log("=== CREATE CHAT REQUEST START ===");
error_log("Method: " . $_SERVER['REQUEST_METHOD']);

$auth = new Auth();
$user = $auth->requireAuth();

error_log("Authenticated user ID: " . $user['id']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_log("ERROR: Invalid method");
    Response::error('Метод не разрешен', 405);
}

// Читаем и логируем сырые данные
$rawInput = file_get_contents('php://input');
error_log("Raw input: " . $rawInput);

$data = json_decode($rawInput, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("ERROR: JSON parse error - " . json_last_error_msg());
    Response::error('Неверный формат JSON', 400);
}

error_log("Parsed data: " . print_r($data, true));

// ИСПРАВЛЕНО: принимаем recipientId или userId
$targetUserId = $data['recipientId'] ?? $data['userId'] ?? null;
$userName = $data['userName'] ?? null;

error_log("Target user ID: " . ($targetUserId ?? 'NULL'));
error_log("User name: " . ($userName ?? 'NULL'));

if (!$targetUserId) {
    error_log("ERROR: recipientId is missing");
    Response::json([
        'success' => false,
        'error' => 'recipientId обязателен',
        'received_data' => $data
    ], 400);
    exit;
}

$db = Database::getInstance();

try {
    error_log("Checking for existing chat between users {$user['id']} and $targetUserId");
    
    // Проверяем, не существует ли уже чат
    $existingChat = $db->fetchOne("
        SELECT c.* FROM chats c
        JOIN chat_participants cp1 ON cp1.chat_id = c.id AND cp1.user_id = :user1
        JOIN chat_participants cp2 ON cp2.chat_id = c.id AND cp2.user_id = :user2
        WHERE c.type = 'personal'
        LIMIT 1
    ", ['user1' => $user['id'], 'user2' => $targetUserId]);
    
    if ($existingChat) {
        error_log("Existing chat found: " . $existingChat['chat_uuid']);
        Response::json([
            'success' => true,
            'id' => $existingChat['chat_uuid'],
            'name' => $userName,
            'type' => 'personal',
            'existed' => true
        ]);
        exit;
    }
    
    error_log("No existing chat, creating new one");
    
    // Создаем новый чат
    $chatUuid = 'chat_' . time() . rand(100, 999);
    error_log("New chat UUID: $chatUuid");
    
    $chatId = $db->insert(
        "INSERT INTO chats (chat_uuid, type, created_by) 
         VALUES (:uuid, 'personal', :user_id)
         RETURNING id",
        ['uuid' => $chatUuid, 'user_id' => $user['id']]
    );
    
    error_log("Chat created with ID: $chatId");
    
    // Добавляем участников
    $db->insert(
        "INSERT INTO chat_participants (chat_id, user_id) VALUES (:chat_id, :user_id)",
        ['chat_id' => $chatId, 'user_id' => $user['id']]
    );
    
    error_log("Added participant: " . $user['id']);
    
    $db->insert(
        "INSERT INTO chat_participants (chat_id, user_id) VALUES (:chat_id, :user_id)",
        ['chat_id' => $chatId, 'user_id' => $targetUserId]
    );
    
    error_log("Added participant: $targetUserId");
    
    error_log("=== CREATE CHAT SUCCESS ===");
    
    Response::json([
        'success' => true,
        'id' => $chatUuid,
        'name' => $userName,
        'type' => 'personal',
        'created' => true
    ]);
    
} catch (Exception $e) {
    error_log("=== CREATE CHAT ERROR ===");
    error_log("Exception: " . $e->getMessage());
    error_log("Trace: " . $e->getTraceAsString());
    Response::error('Ошибка создания чата: ' . $e->getMessage(), 500);
}
