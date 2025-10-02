<?php
// backend/api/messages/chat.php

require_once dirname(__DIR__, 2) . '/lib/Auth.php';
require_once dirname(__DIR__, 2) . '/lib/Database.php';
require_once dirname(__DIR__, 2) . '/lib/Response.php';

$auth = new Auth();
$user = $auth->requireAuth();

// Получаем chatId из параметров запроса
$chatId = $_GET['chatId'] ?? null;

if (!$chatId) {
    Response::error('chatId обязателен', 400);
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
    
    // Проверяем, что пользователь является участником чата
    $participant = $db->fetchOne(
        "SELECT * FROM chat_participants WHERE chat_id = :chat_id AND user_id = :user_id",
        ['chat_id' => $chat['id'], 'user_id' => $user['id']]
    );
    
    if (!$participant) {
        Response::error('Доступ запрещен', 403);
    }
    
    // Получаем сообщения чата
    $messages = $db->fetchAll("
        SELECT 
            m.id,
            c.chat_uuid as chatId,
            m.sender_id as senderId,
            u.username as senderName,
            u.avatar_url as senderAvatar,
            m.content,
            m.type,
            m.created_at as timestamp,
            COALESCE(m.status, 'sent') as status,
            CASE 
                WHEN m.sender_id = :user_id THEN true
                WHEN m.created_at <= COALESCE(cp.last_read_at, '1970-01-01') THEN true
                ELSE false
            END as isRead
        FROM messages m
        JOIN chats c ON c.id = m.chat_id
        JOIN users u ON u.id = m.sender_id
        LEFT JOIN chat_participants cp ON cp.chat_id = m.chat_id AND cp.user_id = :user_id
        WHERE m.chat_id = :chat_id
        ORDER BY m.created_at ASC
    ", [
        'chat_id' => $chat['id'],
        'user_id' => $user['id']
    ]);
    
    // Преобразуем типы данных и исправляем регистр ключей
    $result = [];
    foreach ($messages as $message) {
        // PostgreSQL возвращает все ключи в нижнем регистре
        $formatted = [
            'id' => (string)$message['id'],
            'chatId' => $message['chatid'] ?? $chatId,
            'senderId' => (string)$message['senderid'],
            'senderName' => $message['sendername'] ?? '',
            'senderAvatar' => $message['senderavatar'],
            'content' => $message['content'] ?? '',
            'type' => $message['type'] ?? 'text',
            'timestamp' => $message['timestamp'] ?? date('c'),
            'status' => $message['status'] ?? 'отправлено',
            'isRead' => filter_var($message['isread'] ?? false, FILTER_VALIDATE_BOOLEAN)
        ];
        
        $result[] = $formatted;
    }
    
    // Обновляем время последнего прочтения
    $db->execute(
        "UPDATE chat_participants 
         SET last_read_at = NOW() 
         WHERE chat_id = :chat_id AND user_id = :user_id",
        ['chat_id' => $chat['id'], 'user_id' => $user['id']]
    );
    
    // Логируем для отладки
    error_log("Messages for chat $chatId: " . count($result) . " messages found");
    if (count($result) > 0) {
        error_log("First message: " . json_encode($result[0]));
    }
    
    Response::json($result);
    
} catch (Exception $e) {
    error_log("Get messages error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    Response::error('Ошибка получения сообщений', 500);
}