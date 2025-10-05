<?php
// backend/api/calls/history.php

require_once dirname(__DIR__, 2) . '/lib/Auth.php';
require_once dirname(__DIR__, 2) . '/lib/Database.php';
require_once dirname(__DIR__, 2) . '/lib/Response.php';

$auth = new Auth();
$user = $auth->requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('Метод не разрешен', 405);
}

$db = Database::getInstance();

try {
    // Получаем историю звонков с дополнительной информацией
    $calls = $db->fetchAll(
        "SELECT 
            c.call_uuid as id,
            c.call_type,
            c.status,
            c.started_at,
            c.connected_at,
            c.ended_at,
            c.duration,
            c.end_reason,
            c.caller_id,
            c.receiver_id,
            ch.chat_uuid as chat_id,
            CASE 
                WHEN c.caller_id = :user_id THEN 'outgoing'
                ELSE 'incoming'
            END as direction,
            CASE 
                WHEN c.caller_id = :user_id THEN receiver_user.username
                ELSE caller_user.username
            END as contact_name,
            CASE 
                WHEN c.caller_id = :user_id THEN receiver_user.email
                ELSE caller_user.email
            END as contact_email,
            CASE 
                WHEN c.caller_id = :user_id THEN receiver_user.avatar_url
                ELSE caller_user.avatar_url
            END as contact_avatar
         FROM calls c
         LEFT JOIN chats ch ON ch.id = c.chat_id
         LEFT JOIN users caller_user ON caller_user.id = c.caller_id
         LEFT JOIN users receiver_user ON receiver_user.id = c.receiver_id
         WHERE c.caller_id = :user_id OR c.receiver_id = :user_id
         ORDER BY c.started_at DESC
         LIMIT 100",
        ['user_id' => $user['id']]
    );
    
    // Форматируем ответ
    $formattedCalls = array_map(function($call) use ($user) {
        return [
            'id' => $call['id'],
            'callType' => $call['call_type'],
            'status' => $call['status'],
            'direction' => $call['direction'], // 'incoming' или 'outgoing'
            'startedAt' => $call['started_at'],
            'connectedAt' => $call['connected_at'],
            'endedAt' => $call['ended_at'],
            'duration' => $call['duration'] ? (int)$call['duration'] : null,
            'endReason' => $call['end_reason'],
            'callerId' => (string)$call['caller_id'],
            'receiverId' => (string)$call['receiver_id'],
            'chatId' => $call['chat_id'],
            'contactName' => $call['contact_name'] ?? $call['contact_email'] ?? 'Неизвестный',
            'contactAvatar' => $call['contact_avatar'],
        ];
    }, $calls);
    
    Response::json([
        'success' => true,
        'calls' => $formattedCalls
    ]);
    
} catch (Exception $e) {
    error_log("Error fetching call history: " . $e->getMessage());
    Response::error('Ошибка получения истории звонков', 500);
}