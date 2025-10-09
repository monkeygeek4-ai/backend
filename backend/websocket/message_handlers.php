<?php
// backend/websocket/message_handlers.php

require_once __DIR__ . '/../lib/PushNotificationService.php';

/**
 * Обработка отправки сообщения
 */
function handleSendMessage($data, $from, $clients, $db) {
    error_log("========================================");
    error_log("📨 PROCESSING MESSAGE");
    error_log("========================================");
    
    $chatId = $data['chatId'] ?? null;
    $content = $data['content'] ?? null;
    $tempId = $data['tempId'] ?? null;
    
    // Получаем userId отправителя
    $userId = null;
    if (isset($from->userData) && isset($from->userData->userId)) {
        $userId = $from->userData->userId;
    } elseif (isset($from->userId)) {
        $userId = $from->userId;
    }
    
    error_log("📋 Параметры:");
    error_log("  - chatId: " . ($chatId ?? 'NULL'));
    error_log("  - content length: " . (isset($content) ? strlen($content) : 0));
    error_log("  - tempId: " . ($tempId ?? 'NULL'));
    error_log("  - from userId: " . ($userId ?? 'NULL'));
    
    if (!$chatId || !$content || !$userId) {
        error_log("❌ MESSAGE ERROR: недостаточно данных");
        error_log("========================================");
        return;
    }
    
    try {
        // Получаем информацию о чате
        $chat = $db->fetchOne(
            "SELECT * FROM chats WHERE chat_uuid = :chat_uuid",
            ['chat_uuid' => $chatId]
        );
        
        if (!$chat) {
            error_log("❌ MESSAGE ERROR: чат не найден");
            error_log("========================================");
            return;
        }
        
        error_log("✅ Чат найден: ID {$chat['id']}");
        
        // Определяем получателя
        $receiverId = ($chat['sender_id'] == $userId) 
            ? $chat['receiver_id'] 
            : $chat['sender_id'];
        
        error_log("📤 Получатель: userId $receiverId");
        
        // Генерируем UUID для сообщения
        $messageUuid = generateUUID();
        
        // Сохраняем сообщение в БД
        $messageId = $db->insert('messages', [
            'message_uuid' => $messageUuid,
            'chat_id' => $chat['id'],
            'sender_id' => $userId,
            'receiver_id' => $receiverId,
            'content' => $content,
            'message_type' => 'text',
            'is_read' => 0,
        ]);
        
        error_log("✅ Сообщение сохранено в БД: ID $messageId");
        
        // Обновляем last_message_at в чате
        $db->execute(
            "UPDATE chats SET last_message_at = NOW() WHERE id = :id",
            ['id' => $chat['id']]
        );
        
        // Получаем информацию об отправителе
        $sender = $db->fetchOne(
            "SELECT username, email, avatar_url FROM users WHERE id = :id",
            ['id' => $userId]
        );
        
        // Формируем сообщение для отправки
        $message = [
            'type' => 'new_message',
            'chatId' => $chatId,
            'message' => [
                'id' => $messageUuid,
                'tempId' => $tempId,
                'content' => $content,
                'senderId' => (string)$userId,
                'receiverId' => (string)$receiverId,
                'messageType' => 'text',
                'isRead' => false,
                'createdAt' => date('Y-m-d H:i:s'),
                'sender' => [
                    'username' => $sender['username'],
                    'email' => $sender['email'],
                    'avatarUrl' => $sender['avatar_url'],
                ],
            ],
        ];
        
        // Отправляем через WebSocket получателю
        $sent = false;
        foreach ($clients as $client) {
            $clientUserId = null;
            if (isset($client->userData) && isset($client->userData->userId)) {
                $clientUserId = $client->userData->userId;
            } elseif (isset($client->userId)) {
                $clientUserId = $client->userId;
            }
            
            if ($clientUserId && $clientUserId == $receiverId) {
                $client->send(json_encode($message));
                error_log("✅ Сообщение отправлено через WebSocket получателю $receiverId");
                $sent = true;
                break;
            }
        }
        
        // Если получатель не онлайн, отправляем Push-уведомление
        if (!$sent) {
            error_log("📱 Получатель не онлайн, отправка Push-уведомления");
            
            $pushService = new PushNotificationService();
            $pushService->sendNewMessageNotification(
                $receiverId,
                $chatId,
                $sender['username'] ?? $sender['email'],
                $content,
                $sender['avatar_url']
            );
            
            error_log("✅ Push-уведомление отправлено");
        }
        
        // Подтверждение отправителю
        $confirmation = [
            'type' => 'message_sent',
            'tempId' => $tempId,
            'messageId' => $messageUuid,
            'chatId' => $chatId,
        ];
        
        $from->send(json_encode($confirmation));
        error_log("✅ Подтверждение отправлено отправителю");
        
    } catch (Exception $e) {
        error_log("❌ MESSAGE ERROR: " . $e->getMessage());
        error_log($e->getTraceAsString());
    }
    
    error_log("========================================");
}

/**
 * Обработка прочтения сообщения
 */
function handleMarkAsRead($data, $from, $clients, $db) {
    error_log("========================================");
    error_log("👁️ PROCESSING MARK_AS_READ");
    error_log("========================================");
    
    $messageId = $data['messageId'] ?? null;
    
    // Получаем userId отправителя
    $userId = null;
    if (isset($from->userData) && isset($from->userData->userId)) {
        $userId = $from->userData->userId;
    } elseif (isset($from->userId)) {
        $userId = $from->userId;
    }
    
    error_log("📋 Параметры:");
    error_log("  - messageId: " . ($messageId ?? 'NULL'));
    error_log("  - from userId: " . ($userId ?? 'NULL'));
    
    if (!$messageId || !$userId) {
        error_log("❌ MARK_AS_READ ERROR: недостаточно данных");
        error_log("========================================");
        return;
    }
    
    try {
        // Получаем сообщение
        $message = $db->fetchOne(
            "SELECT * FROM messages WHERE message_uuid = :message_uuid",
            ['message_uuid' => $messageId]
        );
        
        if (!$message) {
            error_log("❌ MARK_AS_READ ERROR: сообщение не найдено");
            error_log("========================================");
            return;
        }
        
        // Проверяем, что пользователь - получатель
        if ($message['receiver_id'] != $userId) {
            error_log("❌ MARK_AS_READ ERROR: пользователь не является получателем");
            error_log("========================================");
            return;
        }
        
        // Обновляем статус
        $db->execute(
            "UPDATE messages SET is_read = 1, read_at = NOW() 
             WHERE message_uuid = :message_uuid",
            ['message_uuid' => $messageId]
        );
        
        error_log("✅ Сообщение отмечено как прочитанное");
        
        // Уведомляем отправителя
        $notification = [
            'type' => 'message_read',
            'messageId' => $messageId,
        ];
        
        foreach ($clients as $client) {
            $clientUserId = null;
            if (isset($client->userData) && isset($client->userData->userId)) {
                $clientUserId = $client->userData->userId;
            } elseif (isset($client->userId)) {
                $clientUserId = $client->userId;
            }
            
            if ($clientUserId && $clientUserId == $message['sender_id']) {
                $client->send(json_encode($notification));
                error_log("✅ Уведомление о прочтении отправлено");
                break;
            }
        }
        
    } catch (Exception $e) {
        error_log("❌ MARK_AS_READ ERROR: " . $e->getMessage());
    }
    
    error_log("========================================");
}