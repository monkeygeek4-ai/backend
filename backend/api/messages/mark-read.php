<?php
// backend/api/messages/mark-read.php

require_once dirname(__DIR__, 2) . '/lib/Auth.php';
require_once dirname(__DIR__, 2) . '/lib/Database.php';
require_once dirname(__DIR__, 2) . '/lib/Response.php';
require_once dirname(__DIR__, 2) . '/lib/WebSocketClient.php';

$auth = new Auth();
$user = $auth->requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Метод не разрешен', 405);
}

// Получаем данные из тела запроса
$input = json_decode(file_get_contents('php://input'), true);
$chatId = $input['chatId'] ?? null;
$messageId = $input['messageId'] ?? null; // Опционально - для отметки конкретного сообщения

// Логируем для отладки
error_log("=== MARK READ CALLED ===");
error_log("Input: " . json_encode($input));
error_log("ChatId: " . ($chatId ?? 'null'));
error_log("User ID: " . $user['id']);

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
    
    // Обновляем время последнего прочтения
    $db->execute(
        "UPDATE chat_participants 
         SET last_read_at = NOW() 
         WHERE chat_id = :chat_id AND user_id = :user_id",
        ['chat_id' => $chat['id'], 'user_id' => $user['id']]
    );
    
    // Получаем ID последнего прочитанного сообщения
    $lastMessage = $db->fetchOne(
        "SELECT id, sender_id 
         FROM messages 
         WHERE chat_id = :chat_id 
         AND is_deleted = false
         ORDER BY created_at DESC 
         LIMIT 1",
        ['chat_id' => $chat['id']]
    );
    
    // Отправляем уведомление через WebSocket отправителю сообщения
    if ($lastMessage && $lastMessage['sender_id'] != $user['id']) {
        try {
            $wsClient = new WebSocketClient();
            $wsClient->send([
                'type' => 'message_read',
                'chatId' => $chatId,
                'messageId' => $lastMessage['id'],
                'readBy' => $user['id'],
                'readByName' => $user['username'] ?? $user['email'],
                'timestamp' => date('c')
            ]);
        } catch (Exception $e) {
            error_log("WebSocket notification error: " . $e->getMessage());
            // Не прерываем выполнение, если WebSocket не работает
        }
    }
    
    // Логируем действие
    error_log("User {$user['id']} marked messages as read in chat $chatId");
    error_log("=== END MARK READ ===");
    
    Response::json([
        'success' => true,
        'chatId' => $chatId,
        'lastReadAt' => date('c')
    ]);
    
} catch (Exception $e) {
    error_log("Mark as read error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    Response::error('Ошибка обновления статуса прочтения', 500);
}