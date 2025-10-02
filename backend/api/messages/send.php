<?php
// backend/api/messages/send.php

require_once dirname(__DIR__, 2) . '/lib/Auth.php';
require_once dirname(__DIR__, 2) . '/lib/Database.php';
require_once dirname(__DIR__, 2) . '/lib/Response.php';

$auth = new Auth();
$user = $auth->requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Метод не разрешен', 405);
}

$data = json_decode(file_get_contents('php://input'), true);

$chatId = $data['chatId'] ?? null;
$content = $data['content'] ?? null;
$type = $data['type'] ?? 'text';
$replyToId = $data['replyToId'] ?? null;

if (!$chatId || !$content) {
    Response::error('chatId и content обязательны', 400);
}

$db = Database::getInstance();

try {
    // Получаем ID чата по UUID
    $chat = $db->fetchOne(
        "SELECT id FROM chats WHERE chat_uuid = :chat_uuid",
        ['chat_uuid' => $chatId]
    );
    
    if (!$chat) {
        Response::error('Чат не найден', 404);
    }
    
    // Сохраняем сообщение с статусом
    $messageId = $db->insert(
        "INSERT INTO messages (chat_id, sender_id, content, type, status, created_at) 
         VALUES (:chat_id, :sender_id, :content, :type, 'sent', NOW())
         RETURNING id",
        [
            'chat_id' => $chat['id'],
            'sender_id' => $user['id'],
            'content' => $content,
            'type' => $type
        ]
    );
    
    // Обновляем последнее сообщение в чате
    $db->execute(
        "UPDATE chats 
         SET last_message = :content, 
             last_message_at = NOW() 
         WHERE id = :chat_id",
        [
            'content' => $content,
            'chat_id' => $chat['id']
        ]
    );
    
    // Возвращаем созданное сообщение
    Response::json([
        'id' => (string)$messageId,
        'chatId' => $chatId,
        'senderId' => (string)$user['id'],
        'senderName' => $user['username'],
        'content' => $content,
        'type' => $type,
        'timestamp' => date('c'),
        'status' => 'отправлено', // Важно: устанавливаем статус
        'isRead' => false
    ]);
    
} catch (Exception $e) {
    error_log("Send message error: " . $e->getMessage());
    Response::error('Ошибка отправки сообщения', 500);
}