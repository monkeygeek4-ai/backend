<?php
// backend/api/calls/index.php

require_once dirname(__DIR__, 2) . '/lib/Auth.php';
require_once dirname(__DIR__, 2) . '/lib/Database.php';
require_once dirname(__DIR__, 2) . '/lib/Response.php';
require_once dirname(__DIR__, 2) . '/lib/WebSocketClient.php';

$auth = new Auth();
$user = $auth->requireAuth();
$db = Database::getInstance();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    switch ($method) {
        case 'POST':
            handlePostRequest($action, $user, $db);
            break;
            
        case 'GET':
            handleGetRequest($action, $user, $db);
            break;
            
        default:
            Response::error('Метод не поддерживается', 405);
    }
} catch (Exception $e) {
    error_log("Calls API error: " . $e->getMessage());
    Response::error('Внутренняя ошибка сервера', 500);
}

function handlePostRequest($action, $user, $db) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    switch ($action) {
        case 'initiate':
            initiateCall($user, $db, $data);
            break;
            
        case 'answer':
            answerCall($user, $db, $data);
            break;
            
        case 'decline':
            declineCall($user, $db, $data);
            break;
            
        case 'end':
            endCall($user, $db, $data);
            break;
            
        case 'ice-candidate':
            handleIceCandidate($user, $db, $data);
            break;
            
        default:
            Response::error('Неизвестное действие', 400);
    }
}

function handleGetRequest($action, $user, $db) {
    switch ($action) {
        case 'history':
            getCallHistory($user, $db);
            break;
            
        case 'active':
            getActiveCall($user, $db);
            break;
            
        default:
            Response::error('Неизвестное действие', 400);
    }
}

function initiateCall($user, $db, $data) {
    $chatId = $data['chatId'] ?? null;
    $callType = $data['callType'] ?? 'audio';
    $offer = $data['offer'] ?? null;
    
    if (!$chatId || !$offer) {
        Response::error('Недостаточно данных', 400);
    }
    
    // Получаем чат
    $chat = $db->fetchOne(
        "SELECT c.*, 
                cp2.user_id as receiver_id,
                u.username as receiver_name,
                u.avatar_url as receiver_avatar
         FROM chats c
         JOIN chat_participants cp1 ON cp1.chat_id = c.id AND cp1.user_id = :user_id
         JOIN chat_participants cp2 ON cp2.chat_id = c.id AND cp2.user_id != :user_id
         JOIN users u ON u.id = cp2.user_id
         WHERE c.chat_uuid = :chat_uuid",
        ['user_id' => $user['id'], 'chat_uuid' => $chatId]
    );
    
    if (!$chat) {
        Response::error('Чат не найден', 404);
    }
    
    // Создаем запись о звонке
    $callUuid = bin2hex(random_bytes(16));
    $callId = $db->insert('calls', [
        'call_uuid' => $callUuid,
        'chat_id' => $chat['id'],
        'caller_id' => $user['id'],
        'receiver_id' => $chat['receiver_id'],
        'call_type' => $callType,
        'status' => 'pending',
        'started_at' => date('Y-m-d H:i:s'),
    ]);
    
    // Сохраняем offer
    $db->insert('call_signals', [
        'call_id' => $callId,
        'signal_type' => 'offer',
        'signal_data' => json_encode($offer),
        'from_user_id' => $user['id'],
        'to_user_id' => $chat['receiver_id'],
    ]);
    
    // Отправляем уведомление через WebSocket
    $wsClient = new WebSocketClient();
    $wsClient->send([
        'type' => 'call_offer',
        'callId' => $callUuid,
        'chatId' => $chatId,
        'callerId' => $user['id'],
        'callerName' => $user['username'] ?? $user['email'],
        'callerAvatar' => $user['avatar_url'],
        'callType' => $callType,
        'offer' => $offer,
        'to' => $chat['receiver_id'],
    ]);
    
    Response::json([
        'callId' => $callUuid,
        'status' => 'pending',
    ]);
}

function answerCall($user, $db, $data) {
    $callId = $data['callId'] ?? null;
    $answer = $data['answer'] ?? null;
    
    if (!$callId || !$answer) {
        Response::error('Недостаточно данных', 400);
    }
    
    // Получаем звонок
    $call = $db->fetchOne(
        "SELECT * FROM calls WHERE call_uuid = :call_uuid",
        ['call_uuid' => $callId]
    );
    
    if (!$call) {
        Response::error('Звонок не найден', 404);
    }
    
    if ($call['receiver_id'] != $user['id']) {
        Response::error('Доступ запрещен', 403);
    }
    
    // Обновляем статус звонка
    $db->execute(
        "UPDATE calls SET status = 'active', connected_at = NOW() 
         WHERE id = :id",
        ['id' => $call['id']]
    );
    
    // Сохраняем answer
    $db->insert('call_signals', [
        'call_id' => $call['id'],
        'signal_type' => 'answer',
        'signal_data' => json_encode($answer),
        'from_user_id' => $user['id'],
        'to_user_id' => $call['caller_id'],
    ]);
    
    // Отправляем уведомление через WebSocket
    $wsClient = new WebSocketClient();
    $wsClient->send([
        'type' => 'call_answer',
        'callId' => $callId,
        'answer' => $answer,
        'to' => $call['caller_id'],
    ]);
    
    Response::json([
        'status' => 'active',
    ]);
}

function declineCall($user, $db, $data) {
    $callId = $data['callId'] ?? null;
    
    if (!$callId) {
        Response::error('Недостаточно данных', 400);
    }
    
    // Получаем звонок
    $call = $db->fetchOne(
        "SELECT * FROM calls WHERE call_uuid = :call_uuid",
        ['call_uuid' => $callId]
    );
    
    if (!$call) {
        Response::error('Звонок не найден', 404);
    }
    
    if ($call['receiver_id'] != $user['id']) {
        Response::error('Доступ запрещен', 403);
    }
    
    // Обновляем статус звонка
    $db->execute(
        "UPDATE calls SET status = 'declined', ended_at = NOW() 
         WHERE id = :id",
        ['id' => $call['id']]
    );
    
    // Отправляем уведомление через WebSocket
    $wsClient = new WebSocketClient();
    $wsClient->send([
        'type' => 'call_declined',
        'callId' => $callId,
        'to' => $call['caller_id'],
    ]);
    
    Response::json([
        'status' => 'declined',
    ]);
}

function endCall($user, $db, $data) {
    $callId = $data['callId'] ?? null;
    $reason = $data['reason'] ?? 'user_ended';
    
    if (!$callId) {
        Response::error('Недостаточно данных', 400);
    }
    
    // Получаем звонок
    $call = $db->fetchOne(
        "SELECT * FROM calls WHERE call_uuid = :call_uuid",
        ['call_uuid' => $callId]
    );
    
    if (!$call) {
        Response::error('Звонок не найден', 404);
    }
    
    // Проверяем права
    if ($call['caller_id'] != $user['id'] && $call['receiver_id'] != $user['id']) {
        Response::error('Доступ запрещен', 403);
    }
    
    // Вычисляем длительность
    $duration = null;
    if ($call['connected_at']) {
        $connected = new DateTime($call['connected_at']);
        $ended = new DateTime();
        $duration = $ended->getTimestamp() - $connected->getTimestamp();
    }
    
    // Обновляем статус звонка
    $db->execute(
        "UPDATE calls 
         SET status = 'ended', 
             ended_at = NOW(),
             duration = :duration,
             end_reason = :reason
         WHERE id = :id",
        [
            'id' => $call['id'],
            'duration' => $duration,
            'reason' => $reason
        ]
    );
    
    // Определяем получателя уведомления
    $toUserId = $call['caller_id'] == $user['id'] 
        ? $call['receiver_id'] 
        : $call['caller_id'];
    
    // Отправляем уведомление через WebSocket
    $wsClient = new WebSocketClient();
    $wsClient->send([
        'type' => 'call_ended',
        'callId' => $callId,
        'reason' => $reason,
        'duration' => $duration,
        'to' => $toUserId,
    ]);
    
    Response::json([
        'status' => 'ended',
        'duration' => $duration,
    ]);
}

function handleIceCandidate($user, $db, $data) {
    $callId = $data['callId'] ?? null;
    $candidate = $data['candidate'] ?? null;
    
    if (!$callId || !$candidate) {
        Response::error('Недостаточно данных', 400);
    }
    
    // Получаем звонок
    $call = $db->fetchOne(
        "SELECT * FROM calls WHERE call_uuid = :call_uuid",
        ['call_uuid' => $callId]
    );
    
    if (!$call) {
        Response::error('Звонок не найден', 404);
    }
    
    // Определяем получателя
    $toUserId = $call['caller_id'] == $user['id'] 
        ? $call['receiver_id'] 
        : $call['caller_id'];
    
    // Сохраняем ICE кандидата
    $db->insert('call_signals', [
        'call_id' => $call['id'],
        'signal_type' => 'ice_candidate',
        'signal_data' => json_encode($candidate),
        'from_user_id' => $user['id'],
        'to_user_id' => $toUserId,
    ]);
    
    // Отправляем через WebSocket
    $wsClient = new WebSocketClient();
    $wsClient->send([
        'type' => 'call_ice_candidate',
        'callId' => $callId,
        'candidate' => $candidate,
        'to' => $toUserId,
    ]);
    
    Response::json(['success' => true]);
}

function getCallHistory($user, $db) {
    $calls = $db->fetchAll(
        "SELECT 
            c.call_uuid as id,
            c.call_type as type,
            c.status,
            c.started_at,
            c.connected_at,
            c.ended_at,
            c.duration,
            c.end_reason,
            ch.chat_uuid as chatId,
            CASE 
                WHEN c.caller_id = :user_id THEN 'outgoing'
                ELSE 'incoming'
            END as direction,
            CASE 
                WHEN c.caller_id = :user_id THEN u2.username
                ELSE u1.username
            END as contactName,
            CASE 
                WHEN c.caller_id = :user_id THEN u2.avatar_url
                ELSE u1.avatar_url
            END as contactAvatar
         FROM calls c
         JOIN chats ch ON ch.id = c.chat_id
         LEFT JOIN users u1 ON u1.id = c.caller_id
         LEFT JOIN users u2 ON u2.id = c.receiver_id
         WHERE c.caller_id = :user_id OR c.receiver_id = :user_id
         ORDER BY c.started_at DESC
         LIMIT 50",
        ['user_id' => $user['id']]
    );
    
    Response::json($calls);
}

function getActiveCall($user, $db) {
    $call = $db->fetchOne(
        "SELECT 
            c.call_uuid as id,
            c.call_type as type,
            c.status,
            c.started_at,
            c.connected_at,
            ch.chat_uuid as chatId,
            CASE 
                WHEN c.caller_id = :user_id THEN 'outgoing'
                ELSE 'incoming'
            END as direction,
            CASE 
                WHEN c.caller_id = :user_id THEN u2.username
                ELSE u1.username
            END as contactName,
            CASE 
                WHEN c.caller_id = :user_id THEN u2.avatar_url
                ELSE u1.avatar_url
            END as contactAvatar
         FROM calls c
         JOIN chats ch ON ch.id = c.chat_id
         LEFT JOIN users u1 ON u1.id = c.caller_id
         LEFT JOIN users u2 ON u2.id = c.receiver_id
         WHERE (c.caller_id = :user_id OR c.receiver_id = :user_id)
           AND c.status IN ('pending', 'connecting', 'active')
         ORDER BY c.started_at DESC
         LIMIT 1",
        ['user_id' => $user['id']]
    );
    
    Response::json($call ?: null);
}