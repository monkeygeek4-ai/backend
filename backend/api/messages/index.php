<?php
// backend/api/messages/index.php

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
    
    // Получаем сообщения чата с правильными статусами
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
            -- Статус сообщения для отправителя
            CASE 
                WHEN m.sender_id = :user_id THEN
                    CASE
                        -- Если есть участники, которые прочитали
                        WHEN EXISTS (
                            SELECT 1 FROM chat_participants cp2
                            WHERE cp2.chat_id = m.chat_id 
                            AND cp2.user_id != m.sender_id
                            AND cp2.last_read_at >= m.created_at
                        ) THEN 'прочитано'
                        -- Если сообщение доставлено (создано в БД)
                        WHEN m.created_at IS NOT NULL THEN 'доставлено'
                        ELSE 'отправлено'
                    END
                ELSE NULL
            END as status,
            -- Определяем, прочитано ли сообщение текущим пользователем
            CASE 
                WHEN m.sender_id = :user_id THEN true
                WHEN cp.last_read_at IS NOT NULL AND m.created_at <= cp.last_read_at THEN true
                ELSE false
            END as isRead
        FROM messages m
        JOIN chats c ON c.id = m.chat_id
        JOIN users u ON u.id = m.sender_id
        LEFT JOIN chat_participants cp ON cp.chat_id = m.chat_id AND cp.user_id = :user_id
        WHERE m.chat_id = :chat_id
        AND m.is_deleted = false
        ORDER BY m.created_at ASC
    ", [
        'chat_id' => $chat['id'],
        'user_id' => $user['id']
    ]);
    
    // Преобразуем типы данных и исправляем регистр ключей
    $result = [];
    foreach ($messages as $message) {
        $formatted = [
            'id' => (string)$message['id'],
            'chatId' => $message['chatid'] ?? $chatId,
            'senderId' => (string)$message['senderid'],
            'senderName' => $message['sendername'] ?? '',
            'senderAvatar' => $message['senderavatar'],
            'content' => $message['content'] ?? '',
            'type' => $message['type'] ?? 'text',
            'timestamp' => $message['timestamp'] ?? date('c'),
            'status' => $message['status'],  // Может быть null для входящих сообщений
            'isRead' => filter_var($message['isread'] ?? false, FILTER_VALIDATE_BOOLEAN)
        ];
        
        $result[] = $formatted;
    }
    
    // Обновляем время последнего прочтения для всех сообщений в чате
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