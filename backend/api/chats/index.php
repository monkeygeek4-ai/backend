<?php
// backend/api/chats/index.php

require_once dirname(__DIR__, 2) . '/lib/Auth.php';
require_once dirname(__DIR__, 2) . '/lib/Database.php';
require_once dirname(__DIR__, 2) . '/lib/Response.php';

$auth = new Auth();
$user = $auth->requireAuth();
$db = Database::getInstance();

try {
    // Добавляем отладку
    error_log("=== GET CHATS for user {$user['id']} ===");
    
    $chats = $db->fetchAll("
        SELECT 
            c.chat_uuid as id,
            COALESCE(u2.username, u2.email, 'Chat') as name,
            c.type,
            u2.avatar_url as avatar,
            c.last_message,
            c.last_message_at as lastMessageTime,
            u2.is_online as isOnline,
            -- Добавляем ID получателя для звонков
            cp2.user_id::text as receiverId,
            -- Более точный подсчет непрочитанных сообщений
            (
                SELECT COUNT(*)::int
                FROM messages m
                WHERE m.chat_id = c.id
                AND m.sender_id != :user_id
                AND m.is_deleted = false
                AND m.created_at > COALESCE(cp.last_read_at, '1970-01-01'::timestamp)
            ) as unreadCount,
            cp.last_read_at,
            -- Добавляем массив участников (опционально)
            ARRAY[cp.user_id::text, cp2.user_id::text] as participants
        FROM chats c
        JOIN chat_participants cp ON cp.chat_id = c.id AND cp.user_id = :user_id
        JOIN chat_participants cp2 ON cp2.chat_id = c.id AND cp2.user_id != :user_id
        LEFT JOIN users u2 ON u2.id = cp2.user_id
        WHERE cp.user_id = :user_id
        AND c.type = 'personal'
        ORDER BY c.last_message_at DESC NULLS LAST
    ", ['user_id' => $user['id']]);
    
    // Преобразуем результаты в правильный формат
    foreach ($chats as &$chat) {
        // Для отладки
        error_log("Chat {$chat['id']}: last_read_at = {$chat['last_read_at']}, unread raw = {$chat['unreadcount']}");
        
        // Преобразуем unreadCount в число и исправляем регистр
        $chat['unreadCount'] = intval($chat['unreadcount'] ?? 0);
        unset($chat['unreadcount']);
        
        // Убираем last_read_at из ответа (это внутреннее поле)
        unset($chat['last_read_at']);
        
        // Форматируем другие поля
        $chat['lastMessage'] = $chat['last_message'] ?? null;
        unset($chat['last_message']);
        
        $chat['lastMessageTime'] = $chat['lastmessagetime'] ?? null;
        unset($chat['lastmessagetime']);
        
        $chat['isOnline'] = filter_var($chat['isonline'] ?? false, FILTER_VALIDATE_BOOLEAN);
        unset($chat['isonline']);
        
        // Добавляем receiverId
        $chat['receiverId'] = $chat['receiverid'] ?? null;
        unset($chat['receiverid']);
        
        // Форматируем participants если есть
        if (isset($chat['participants'])) {
            // PostgreSQL возвращает массив в формате {value1,value2}
            $participantsStr = trim($chat['participants'], '{}');
            $chat['participants'] = $participantsStr ? explode(',', $participantsStr) : [];
        }
        
        // Дополнительная проверка - получаем актуальное количество непрочитанных
        if ($chat['unreadCount'] > 0) {
            $actualUnread = $db->fetchOne("
                SELECT COUNT(*)::int as count
                FROM messages m
                JOIN chats c ON c.id = m.chat_id
                WHERE c.chat_uuid = :chat_uuid
                AND m.sender_id != :user_id
                AND m.is_deleted = false
                AND m.created_at > (
                    SELECT COALESCE(last_read_at, '1970-01-01'::timestamp)
                    FROM chat_participants
                    WHERE chat_id = c.id AND user_id = :user_id
                )
            ", ['chat_uuid' => $chat['id'], 'user_id' => $user['id']]);
            
            $chat['unreadCount'] = intval($actualUnread['count'] ?? 0);
            error_log("Chat {$chat['id']}: recalculated unread = {$chat['unreadCount']}");
        }
    }
    
    error_log("=== Returning " . count($chats) . " chats ===");
    
    Response::json($chats);
    
} catch (Exception $e) {
    error_log("Get chats error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    Response::error('Ошибка получения чатов', 500);
}