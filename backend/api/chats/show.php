<?php
// backend/api/chats/show.php

require_once dirname(__DIR__, 2) . '/lib/Auth.php';
require_once dirname(__DIR__, 2) . '/lib/Database.php';
require_once dirname(__DIR__, 2) . '/lib/Response.php';

$auth = new Auth();
$user = $auth->requireAuth();

// Получаем chatId из GET параметров
$chatId = $_GET['chatId'] ?? null;

if (!$chatId) {
    Response::error('chatId обязателен', 400);
}

$db = Database::getInstance();

try {
    // Получаем информацию о чате
    $chat = $db->fetchOne("
        SELECT 
            c.chat_uuid as id,
            c.type,
            c.last_message,
            c.last_message_at as lastMessageTime,
            COALESCE(u2.username, u2.email, 'Chat') as name,
            u2.avatar_url as avatar,
            u2.is_online as isOnline,
            cp2.user_id::text as receiverId,
            (
                SELECT COUNT(*)::int
                FROM messages m
                WHERE m.chat_id = c.id
                AND m.sender_id != :user_id
                AND m.is_deleted = false
                AND m.created_at > COALESCE(cp.last_read_at, '1970-01-01'::timestamp)
            ) as unreadCount
        FROM chats c
        JOIN chat_participants cp ON cp.chat_id = c.id AND cp.user_id = :user_id
        JOIN chat_participants cp2 ON cp2.chat_id = c.id AND cp2.user_id != :user_id
        LEFT JOIN users u2 ON u2.id = cp2.user_id
        WHERE c.chat_uuid = :chat_uuid
        AND c.type = 'personal'
    ", [
        'user_id' => $user['id'],
        'chat_uuid' => $chatId
    ]);
    
    if (!$chat) {
        Response::error('Чат не найден или доступ запрещен', 404);
    }
    
    // Форматируем ответ
    $response = [
        'id' => $chat['id'],
        'name' => $chat['name'],
        'type' => $chat['type'],
        'avatar' => $chat['avatar'],
        'lastMessage' => $chat['last_message'],
        'lastMessageTime' => $chat['lastmessagetime'],
        'isOnline' => filter_var($chat['isonline'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'receiverId' => $chat['receiverid'],
        'unreadCount' => intval($chat['unreadcount'] ?? 0)
    ];
    
    Response::json($response);
    
} catch (Exception $e) {
    error_log("Get chat error: " . $e->getMessage());
    Response::error('Ошибка получения чата', 500);
}