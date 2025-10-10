<?php
// backend/websocket/message_handlers.php

require_once __DIR__ . '/../lib/PushNotificationService.php';

/**
 * Логирование в файл И в консоль
 */
function logToFile($message) {
    $timestamp = date('Y-m-d H:i:s');
    
    // Выводим в консоль (stdout)
    echo "[$timestamp] $message" . PHP_EOL;
    
    // Пытаемся также записать в файл
    try {
        $logDir = __DIR__ . '/../logs';
        if (!file_exists($logDir)) {
            @mkdir($logDir, 0777, true);
        }
        
        if (is_writable($logDir) || is_writable(__DIR__ . '/..')) {
            $logFile = $logDir . '/message_handlers_' . date('Y-m-d') . '.log';
            $logMessage = "[$timestamp] $message" . PHP_EOL;
            @file_put_contents($logFile, $logMessage, FILE_APPEND);
        }
    } catch (Exception $e) {
        echo "⚠️ Не удалось записать в лог-файл: " . $e->getMessage() . PHP_EOL;
    }
    
    // Также дублируем в error_log
    error_log($message);
}

/**
 * Обработка отправки сообщения
 */
function handleSendMessage($data, $from, $clients, $db) {
    logToFile("========================================");
    logToFile("📨 PROCESSING MESSAGE");
    logToFile("========================================");
    
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
    
    logToFile("📋 Параметры:");
    logToFile("  - chatId: " . ($chatId ?? 'NULL'));
    logToFile("  - content length: " . (isset($content) ? strlen($content) : 0));
    logToFile("  - tempId: " . ($tempId ?? 'NULL'));
    logToFile("  - from userId: " . ($userId ?? 'NULL'));
    
    if (!$chatId || !$content || !$userId) {
        logToFile("❌ MESSAGE ERROR: недостаточно данных");
        logToFile("========================================");
        return;
    }
    
    try {
        // Получаем информацию о чате
        $chat = $db->fetchOne(
            "SELECT * FROM chats WHERE chat_uuid = :chat_uuid",
            ['chat_uuid' => $chatId]
        );
        
        if (!$chat) {
            logToFile("❌ MESSAGE ERROR: чат не найден");
            logToFile("========================================");
            return;
        }
        
        logToFile("✅ Чат найден: ID {$chat['id']}");
        
        // Получаем участников чата из chat_participants
        $participants = $db->fetchAll(
            "SELECT user_id FROM chat_participants WHERE chat_id = :chat_id",
            ['chat_id' => $chat['id']]
        );
        
        // Находим получателя (все участники кроме отправителя)
        $receiverId = null;
        foreach ($participants as $participant) {
            if ($participant['user_id'] != $userId) {
                $receiverId = $participant['user_id'];
                break;
            }
        }
        
        if (!$receiverId) {
            logToFile("❌ MESSAGE ERROR: получатель не найден");
            logToFile("========================================");
            return;
        }
        
        logToFile("📤 Получатель: userId $receiverId");
        
        // ⭐ ИСПРАВЛЕНО: Используем insert() вместо execute()
        $messageId = $db->insert(
            "INSERT INTO messages (chat_id, sender_id, content, type, created_at, status) 
             VALUES (:chat_id, :sender_id, :content, :type, NOW(), :status)
             RETURNING id",
            [
                'chat_id' => $chat['id'],
                'sender_id' => $userId,
                'content' => $content,
                'type' => 'text',
                'status' => 'отправлено'
            ]
        );
        
        if (!$messageId) {
            logToFile("❌ MESSAGE ERROR: не удалось получить ID сообщения");
            logToFile("========================================");
            return;
        }
        
        logToFile("✅ Сообщение сохранено в БД: ID $messageId");
        
        // Обновляем last_message_at в чате
        $db->execute(
            "UPDATE chats SET last_message = :content, last_message_at = NOW() WHERE id = :id",
            ['content' => $content, 'id' => $chat['id']]
        );
        
        // Получаем информацию об отправителе
        $sender = $db->fetchOne(
            "SELECT username, email, avatar_url FROM users WHERE id = :id",
            ['id' => $userId]
        );
        
        logToFile("📨 Формируем сообщение для отправки:");
        logToFile("  - Message ID: $messageId");
        logToFile("  - Sender: {$sender['username']} (ID: $userId)");
        logToFile("  - Receiver ID: $receiverId");
        
        // Формируем сообщение для отправки
        $message = [
            'type' => 'new_message',
            'chatId' => $chatId,
            'message' => [
                'id' => (string)$messageId,
                'tempId' => $tempId,
                'content' => $content,
                'senderId' => (string)$userId,
                'receiverId' => (string)$receiverId,
                'messageType' => 'text',
                'isRead' => false,
                'timestamp' => date('c'),
                'senderName' => $sender['username'],
                'senderAvatar' => $sender['avatar_url'],
            ],
        ];
        
        logToFile("🔍 Проверка подключенных клиентов:");
        logToFile("  - Всего клиентов: " . count($clients));
        
        // Отправляем через WebSocket получателю
        $webSocketSent = false;
        $clientsInfo = [];
        
        foreach ($clients as $client) {
            $clientUserId = null;
            if (isset($client->userData) && isset($client->userData->userId)) {
                $clientUserId = $client->userData->userId;
            } elseif (isset($client->userId)) {
                $clientUserId = $client->userId;
            }
            
            $clientsInfo[] = "Client ID: {$client->resourceId}, User ID: " . ($clientUserId ?? 'NULL');
            
            if ($clientUserId && $clientUserId == $receiverId) {
                $client->send(json_encode($message));
                logToFile("✅ Сообщение отправлено через WebSocket получателю $receiverId");
                logToFile("  - Resource ID: {$client->resourceId}");
                $webSocketSent = true;
                break;
            }
        }
        
        // Логируем информацию о всех клиентах
        foreach ($clientsInfo as $info) {
            logToFile("  - $info");
        }
        
        if (!$webSocketSent) {
            logToFile("⚠️ Получатель $receiverId не найден в списке подключенных клиентов");
            logToFile("⚠️ Сообщение НЕ отправлено через WebSocket!");
        }
        
        // 📱 ВАЖНО: Отправляем Push ВСЕГДА, даже если пользователь онлайн
        // Это нужно для случаев когда вкладка в фоне или браузер свернут
        logToFile("📱 Отправка Push-уведомления получателю $receiverId");
        
        try {
            $pushService = new PushNotificationService();
            $result = $pushService->sendNewMessageNotification(
                $receiverId,
                $chatId,
                $sender['username'] ?? 'Пользователь',
                $content,
                null
            );
            
            if ($result) {
                logToFile("✅ Push-уведомление успешно отправлено");
            } else {
                logToFile("⚠️ Не удалось отправить Push-уведомление (возможно, нет FCM токена)");
            }
        } catch (Exception $e) {
            logToFile("⚠️ Ошибка отправки Push-уведомления: " . $e->getMessage());
            logToFile("⚠️ Stack trace: " . $e->getTraceAsString());
        }
        
    } catch (Exception $e) {
        logToFile("❌ MESSAGE ERROR: " . $e->getMessage());
        logToFile("❌ Stack trace: " . $e->getTraceAsString());
    }
    
    logToFile("========================================");
}

/**
 * Обработка отметки сообщения как прочитанного
 */
function handleMarkAsRead($data, $from, $clients, $db) {
    logToFile("========================================");
    logToFile("📖 MARK AS READ");
    logToFile("========================================");
    
    $messageId = $data['messageId'] ?? null;
    
    // Получаем userId отправителя
    $userId = null;
    if (isset($from->userData) && isset($from->userData->userId)) {
        $userId = $from->userData->userId;
    } elseif (isset($from->userId)) {
        $userId = $from->userId;
    }
    
    logToFile("📋 Параметры:");
    logToFile("  - messageId: " . ($messageId ?? 'NULL'));
    logToFile("  - from userId: " . ($userId ?? 'NULL'));
    
    if (!$messageId || !$userId) {
        logToFile("❌ MARK_AS_READ ERROR: недостаточно данных");
        logToFile("========================================");
        return;
    }
    
    try {
        // Получаем сообщение
        $message = $db->fetchOne(
            "SELECT * FROM messages WHERE id = :message_id",
            ['message_id' => $messageId]
        );
        
        if (!$message) {
            logToFile("❌ MARK_AS_READ ERROR: сообщение не найдено");
            logToFile("========================================");
            return;
        }
        
        // Обновляем last_read_at для пользователя
        $db->execute(
            "UPDATE chat_participants 
             SET last_read_at = NOW()
             WHERE chat_id = :chat_id AND user_id = :user_id",
            ['chat_id' => $message['chat_id'], 'user_id' => $userId]
        );
        
        logToFile("✅ Сообщение отмечено как прочитанное");
        
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
                logToFile("✅ Уведомление о прочтении отправлено");
                break;
            }
        }
        
    } catch (Exception $e) {
        logToFile("❌ MARK_AS_READ ERROR: " . $e->getMessage());
        logToFile("❌ Stack trace: " . $e->getTraceAsString());
    }
    
    logToFile("========================================");
}

/**
 * Генерация UUID v4
 */
function generateUUID() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}