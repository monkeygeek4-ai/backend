<?php
// backend/api/messages/read.php
// Помечает сообщения как прочитанные (альтернатива mark-read.php)

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
$messageIds = $data['messageIds'] ?? null; // Опционально: конкретные ID сообщений

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
    
    // Проверяем участие пользователя в чате
    $participant = $db->fetchOne(
        "SELECT * FROM chat_participants WHERE chat_id = :chat_id AND user_id = :user_id",
        ['chat_id' => $chat['id'], 'user_id' => $user['id']]
    );
    
    if (!$participant) {
        Response::error('Доступ запрещен', 403);
    }
    
    // Если указаны конкретные ID сообщений
    if ($messageIds && is_array($messageIds) && count($messageIds) > 0) {
        // Отмечаем конкретные сообщения как прочитанные
        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        
        $params = array_merge([$chat['id'], $user['id']], $messageIds);
        
        // В PostgreSQL нет отдельной таблицы для отметок прочтения
        // Просто обновляем last_read_at до времени последнего указанного сообщения
        $lastMessageTime = $db->fetchOne(
            "SELECT MAX(created_at) as max_time 
             FROM messages 
             WHERE id = ANY(:message_ids) 
             AND chat_id = :chat_id",
            [
                'message_ids' => '{' . implode(',', $messageIds) . '}',
                'chat_id' => $chat['id']
            ]
        );
        
        if ($lastMessageTime && $lastMessageTime['max_time']) {
            $db->execute(
                "UPDATE chat_participants 
                 SET last_read_at = :last_read_at
                 WHERE chat_id = :chat_id AND user_id = :user_id
                 AND (last_read_at IS NULL OR last_read_at < :last_read_at)",
                [
                    'chat_id' => $chat['id'],
                    'user_id' => $user['id'],
                    'last_read_at' => $lastMessageTime['max_time']
                ]
            );
        }
        
        $readCount = count($messageIds);
    } else {
        // Отмечаем ВСЕ сообщения в чате как прочитанные
        $db->execute(
            "UPDATE chat_participants 
             SET last_read_at = NOW() 
             WHERE chat_id = :chat_id AND user_id = :user_id",
            ['chat_id' => $chat['id'], 'user_id' => $user['id']]
        );
        
        // Подсчитываем количество прочитанных сообщений
        $result = $db->fetchOne(
            "SELECT COUNT(*) as count 
             FROM messages 
             WHERE chat_id = :chat_id 
             AND sender_id != :user_id 
             AND is_deleted = false",
            ['chat_id' => $chat['id'], 'user_id' => $user['id']]
        );
        
        $readCount = intval($result['count'] ?? 0);
    }
    
    error_log("User {$user['id']} marked $readCount messages as read in chat $chatId");
    
    Response::json([
        'success' => true,
        'chatId' => $chatId,
        'readCount' => $readCount,
        'timestamp' => date('c')
    ]);
    
} catch (Exception $e) {
    error_log("Mark messages read error: " . $e->getMessage());
    Response::error('Ошибка отметки сообщений как прочитанных', 500);
}