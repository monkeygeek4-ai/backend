<?php
// backend/api/chats/delete.php

require_once dirname(__DIR__, 2) . '/lib/Auth.php';
require_once dirname(__DIR__, 2) . '/lib/Database.php';
require_once dirname(__DIR__, 2) . '/lib/Response.php';

$auth = new Auth();
$user = $auth->requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Метод не разрешен', 405);
}

// Получаем chatId из тела запроса или параметров
$data = json_decode(file_get_contents('php://input'), true);
$chatId = $data['chatId'] ?? $_GET['chatId'] ?? null;

if (!$chatId) {
    Response::error('chatId обязателен', 400);
}

$db = Database::getInstance();

try {
    // Получаем ID чата и проверяем доступ
    $chat = $db->fetchOne(
        "SELECT c.id 
         FROM chats c
         JOIN chat_participants cp ON cp.chat_id = c.id
         WHERE c.chat_uuid = :chat_uuid 
         AND cp.user_id = :user_id",
        [
            'chat_uuid' => $chatId,
            'user_id' => $user['id']
        ]
    );
    
    if (!$chat) {
        Response::error('Чат не найден или доступ запрещен', 404);
    }
    
    $db->beginTransaction();
    
    try {
        // Мягкое удаление - помечаем сообщения как удаленные для пользователя
        // Вариант 1: Полное удаление чата (если оба участника удалили)
        
        // Удаляем участие пользователя в чате
        $db->execute(
            "DELETE FROM chat_participants 
             WHERE chat_id = :chat_id AND user_id = :user_id",
            [
                'chat_id' => $chat['id'],
                'user_id' => $user['id']
            ]
        );
        
        // Проверяем, остались ли еще участники
        $remainingParticipants = $db->fetchOne(
            "SELECT COUNT(*) as count FROM chat_participants WHERE chat_id = :chat_id",
            ['chat_id' => $chat['id']]
        );
        
        // Если участников больше нет, удаляем чат полностью
        if (intval($remainingParticipants['count']) === 0) {
            // Удаляем сообщения чата
            $db->execute(
                "DELETE FROM messages WHERE chat_id = :chat_id",
                ['chat_id' => $chat['id']]
            );
            
            // Удаляем звонки чата
            $db->execute(
                "DELETE FROM call_signals WHERE call_id IN (SELECT id FROM calls WHERE chat_id = :chat_id)",
                ['chat_id' => $chat['id']]
            );
            
            $db->execute(
                "DELETE FROM calls WHERE chat_id = :chat_id",
                ['chat_id' => $chat['id']]
            );
            
            // Удаляем сам чат
            $db->execute(
                "DELETE FROM chats WHERE id = :chat_id",
                ['chat_id' => $chat['id']]
            );
            
            error_log("Chat $chatId полностью удален");
        } else {
            error_log("User {$user['id']} покинул чат $chatId");
        }
        
        $db->commit();
        
        Response::json([
            'success' => true,
            'deleted' => true,
            'chatId' => $chatId,
            'message' => 'Чат успешно удален'
        ]);
        
    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("Delete chat error: " . $e->getMessage());
    Response::error('Ошибка удаления чата', 500);
}