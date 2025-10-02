<?php

require_once dirname(__DIR__, 2) . '/lib/Auth.php';
require_once dirname(__DIR__, 2) . '/lib/Database.php';
require_once dirname(__DIR__, 2) . '/lib/Response.php';

$auth = new Auth();
$user = $auth->requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Метод не разрешен', 405);
}

$data = json_decode(file_get_contents('php://input'), true);
$targetUserId = $data['userId'] ?? null;
$userName = $data['userName'] ?? null;

if (!$targetUserId) {
    Response::error('userId обязателен', 400);
}

$db = Database::getInstance();

try {
    // Проверяем, не существует ли уже чат
    $existingChat = $db->fetchOne("
        SELECT c.* FROM chats c
        JOIN chat_participants cp1 ON cp1.chat_id = c.id AND cp1.user_id = :user1
        JOIN chat_participants cp2 ON cp2.chat_id = c.id AND cp2.user_id = :user2
        WHERE c.type = 'personal'
        LIMIT 1
    ", ['user1' => $user['id'], 'user2' => $targetUserId]);
    
    if ($existingChat) {
        Response::json([
            'id' => $existingChat['chat_uuid'],
            'name' => $userName,
            'type' => 'personal',
            'existed' => true
        ]);
    }
    
    // Создаем новый чат
    $chatUuid = 'chat_' . time() . rand(100, 999);
    
    $chatId = $db->insert(
        "INSERT INTO chats (chat_uuid, type, created_by) 
         VALUES (:uuid, 'personal', :user_id)
         RETURNING id",
        ['uuid' => $chatUuid, 'user_id' => $user['id']]
    );
    
    // Добавляем участников
    $db->insert(
        "INSERT INTO chat_participants (chat_id, user_id) VALUES (:chat_id, :user_id)",
        ['chat_id' => $chatId, 'user_id' => $user['id']]
    );
    
    $db->insert(
        "INSERT INTO chat_participants (chat_id, user_id) VALUES (:chat_id, :user_id)",
        ['chat_id' => $chatId, 'user_id' => $targetUserId]
    );
    
    Response::json([
        'id' => $chatUuid,
        'name' => $userName,
        'type' => 'personal',
        'created' => true
    ]);
    
} catch (Exception $e) {
    error_log("Create chat error: " . $e->getMessage());
    Response::error('Ошибка создания чата', 500);
} 