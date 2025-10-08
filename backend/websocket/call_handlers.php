<?php
// backend/websocket/call_handlers.php
// Обработчики звонков для WebSocket сервера

/**
 * Главная функция для обработки всех типов сообщений звонков
 */
function handleCallMessage($type, $data, $from, $clients, $db) {
    error_log("========================================");
    error_log("=== CALL MESSAGE ===");
    error_log("Тип: $type");
    
    // Получаем userId из userData или из прямого свойства
    $userId = null;
    if (isset($from->userData) && isset($from->userData->userId)) {
        $userId = $from->userData->userId;
    } elseif (isset($from->userId)) {
        $userId = $from->userId;
    }
    
    error_log("От пользователя ID: " . ($userId ?? 'unknown'));
    error_log("Данные: " . json_encode($data));
    
    switch($type) {
        case 'call_offer':
            handleCallOffer($data, $from, $clients, $db);
            break;
            
        case 'call_answer':
            handleCallAnswer($data, $from, $clients, $db);
            break;
            
        case 'call_ice_candidate':
            handleIceCandidate($data, $from, $clients, $db);
            break;
            
        case 'call_end':
            handleCallEnd($data, $from, $clients, $db);
            break;
            
        case 'call_decline':
            handleCallDecline($data, $from, $clients, $db);
            break;
            
        default:
            error_log("⚠️ Неизвестный тип звонка: $type");
    }
    
    error_log("=== END CALL MESSAGE ===");
    error_log("========================================");
}

/**
 * Обработка предложения звонка (call offer)
 * Когда пользователь A начинает звонок пользователю B
 */
function handleCallOffer($data, $from, $clients, $db) {
    error_log("========================================");
    error_log("📞 PROCESSING CALL_OFFER");
    error_log("========================================");
    
    $callId = $data['callId'] ?? null;
    $chatId = $data['chatId'] ?? null;
    $receiverId = $data['receiverId'] ?? null;
    $callType = $data['callType'] ?? 'audio';
    $offer = $data['offer'] ?? null;
    
    // Получаем userId отправителя
    $callerId = null;
    if (isset($from->userData) && isset($from->userData->userId)) {
        $callerId = $from->userData->userId;
    } elseif (isset($from->userId)) {
        $callerId = $from->userId;
    }
    
    error_log("📋 Параметры звонка:");
    error_log("  - callId: " . ($callId ?? 'NULL'));
    error_log("  - chatId: " . ($chatId ?? 'NULL'));
    error_log("  - callerId: " . ($callerId ?? 'NULL'));
    error_log("  - receiverId: " . ($receiverId ?? 'NULL'));
    error_log("  - callType: $callType");
    error_log("  - Has offer: " . ($offer ? 'YES' : 'NO'));
    
    if ($offer) {
        error_log("  - Offer has SDP: " . (isset($offer['sdp']) ? 'YES' : 'NO'));
        error_log("  - Offer has type: " . (isset($offer['type']) ? 'YES' : 'NO'));
        if (isset($offer['sdp'])) {
            error_log("  - SDP size: " . strlen($offer['sdp']) . " bytes");
        }
    }
    
    // Валидация данных
    if (!$callId || !$chatId || !$receiverId || !$offer || !$callerId) {
        error_log("========================================");
        error_log("❌ CALL_OFFER ERROR: недостаточно данных");
        error_log("  Missing:");
        if (!$callId) error_log("  - callId");
        if (!$chatId) error_log("  - chatId");
        if (!$receiverId) error_log("  - receiverId");
        if (!$offer) error_log("  - offer");
        if (!$callerId) error_log("  - callerId");
        error_log("========================================");
        
        $from->send(json_encode([
            'type' => 'error',
            'message' => 'Недостаточно данных для начала звонка'
        ]));
        return;
    }
    
    // Проверяем, что receiverId - это число
    if (!is_numeric($receiverId)) {
        error_log("========================================");
        error_log("❌ CALL_OFFER ERROR: receiverId не является числом");
        error_log("  receiverId value: $receiverId");
        error_log("  receiverId type: " . gettype($receiverId));
        error_log("========================================");
        
        $from->send(json_encode([
            'type' => 'error',
            'message' => 'Некорректный ID получателя'
        ]));
        return;
    }
    
    // Получаем информацию о звонящем из БД
    $caller = null;
    try {
        error_log("🔍 Поиск информации о звонящем в БД (ID: $callerId)...");
        
        $caller = $db->fetchOne(
            "SELECT id, username, email, avatar_url FROM users WHERE id = :id",
            ['id' => $callerId]
        );
        
        if (!$caller) {
            error_log("========================================");
            error_log("❌ CALL_OFFER ERROR: звонящий не найден в БД");
            error_log("  Искали пользователя с ID: $callerId");
            error_log("========================================");
            
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Пользователь не найден'
            ]));
            return;
        }
        
        error_log("✅ Звонящий найден: " . ($caller['username'] ?? $caller['email']));
        
    } catch (Exception $e) {
        error_log("========================================");
        error_log("❌ CALL_OFFER ERROR БД: " . $e->getMessage());
        error_log("  Stack trace: " . $e->getTraceAsString());
        error_log("========================================");
        return;
    }
    
    // КРИТИЧНО: Формируем сообщение для получателя с ПОЛНЫМ OFFER
    $message = [
        'type' => 'call_offer',
        'callId' => $callId,
        'chatId' => $chatId,
        'callerId' => (string)$callerId,
        'callerName' => $caller['username'] ?? $caller['email'] ?? 'Неизвестный',
        'callerAvatar' => $caller['avatar_url'],
        'callType' => $callType,
        'offer' => $offer  // ⭐⭐⭐ КРИТИЧНО: ПЕРЕДАЕМ ПОЛНЫЙ OFFER С SDP!
    ];
    
    error_log("========================================");
    error_log("📦 Сформировано сообщение для получателя:");
    error_log("  - type: " . $message['type']);
    error_log("  - callId: " . $message['callId']);
    error_log("  - callerName: " . $message['callerName']);
    error_log("  - callType: " . $message['callType']);
    error_log("  - offer included: " . (isset($message['offer']) ? 'YES ✅' : 'NO ❌'));
    if (isset($message['offer']) && isset($message['offer']['sdp'])) {
        error_log("  - offer.sdp size: " . strlen($message['offer']['sdp']) . " bytes");
    }
    error_log("========================================");
    
    // Ищем получателя среди подключенных клиентов
    error_log("🔍 Поиск получателя среди подключенных клиентов...");
    error_log("  Ищем пользователя с ID: $receiverId");
    
    $receiverFound = false;
    $connectedUsers = [];
    
    foreach ($clients as $client) {
        // Проверяем userId в userData и в прямом свойстве
        $clientUserId = null;
        if (isset($client->userData) && isset($client->userData->userId)) {
            $clientUserId = $client->userData->userId;
        } elseif (isset($client->userId)) {
            $clientUserId = $client->userId;
        }
        
        if ($clientUserId) {
            $connectedUsers[] = (string)$clientUserId;
        }
        
        error_log("  Проверка: resourceId={$client->resourceId}, userId=" . ($clientUserId ?? 'NULL'));
        
        // КРИТИЧНО: Если нашли получателя - ОТПРАВЛЯЕМ ЕМУ ПОЛНОЕ СООБЩЕНИЕ С OFFER!
        if ($clientUserId && $clientUserId == $receiverId) {
            error_log("========================================");
            error_log("✅✅✅ ПОЛУЧАТЕЛЬ НАЙДЕН!");
            error_log("  User ID: $clientUserId");
            error_log("  Connection ID: {$client->resourceId}");
            error_log("📤 ОТПРАВЛЯЕМ call_offer с ПОЛНЫМ OFFER...");
            error_log("========================================");
            
            // Отправляем ПОЛНОЕ сообщение с offer
            $client->send(json_encode($message));
            
            error_log("========================================");
            error_log("✅ CALL_OFFER УСПЕШНО ОТПРАВЛЕН!");
            error_log("  Получатель: User ID $receiverId");
            error_log("  Connection: {$client->resourceId}");
            error_log("  Размер сообщения: " . strlen(json_encode($message)) . " bytes");
            error_log("========================================");
            
            $receiverFound = true;
            
            // Отправляем подтверждение инициатору
            $from->send(json_encode([
                'type' => 'call_offer_sent',
                'callId' => $callId,
                'status' => 'sent'
            ]));
            
            error_log("✅ Подтверждение call_offer_sent отправлено инициатору");
            
            break;
        }
    }
    
    if (!$receiverFound) {
        error_log("========================================");
        error_log("❌❌❌ ПОЛУЧАТЕЛЬ НЕ НАЙДЕН В СЕТИ!");
        error_log("========================================");
        error_log("Искали пользователя: ID $receiverId");
        error_log("Всего подключенных клиентов: " . count($clients));
        error_log("Подключенные пользователи: " . json_encode($connectedUsers));
        error_log("========================================");
        
        // Детальный вывод всех подключенных
        error_log("Детали всех подключенных клиентов:");
        $index = 1;
        foreach ($clients as $client) {
            $clientUserId = null;
            if (isset($client->userData) && isset($client->userData->userId)) {
                $clientUserId = $client->userData->userId;
            } elseif (isset($client->userId)) {
                $clientUserId = $client->userId;
            }
            
            error_log("  [$index] Connection ID: {$client->resourceId}");
            error_log("      User ID: " . ($clientUserId ?? 'NOT AUTHORIZED'));
            $index++;
        }
        error_log("========================================");
        
        $from->send(json_encode([
            'type' => 'call_error',
            'callId' => $callId,
            'error' => 'Пользователь не в сети'
        ]));
        
        error_log("❌ Отправлена ошибка инициатору: пользователь не в сети");
    }
    
    // Сохраняем информацию о звонке в БД
    try {
        error_log("💾 Сохранение информации о звонке в БД...");
        
        // Получаем ID чата по UUID
        $chat = $db->fetchOne(
            "SELECT id FROM chats WHERE chat_uuid = :chat_uuid",
            ['chat_uuid' => $chatId]
        );
        
        if ($chat) {
            // Используем execute для insert
            $db->execute(
                "INSERT INTO calls (call_uuid, chat_id, caller_id, receiver_id, call_type, status, started_at) 
                 VALUES (:call_uuid, :chat_id, :caller_id, :receiver_id, :call_type, :status, :started_at)",
                [
                    'call_uuid' => $callId,
                    'chat_id' => $chat['id'],
                    'caller_id' => $callerId,
                    'receiver_id' => $receiverId,
                    'call_type' => $callType,
                    'status' => 'pending',
                    'started_at' => date('Y-m-d H:i:s')
                ]
            );
            error_log("✅ Звонок сохранен в БД (call_uuid: $callId)");
        } else {
            error_log("⚠️ Чат не найден в БД: $chatId");
        }
    } catch (Exception $e) {
        error_log("⚠️ Ошибка сохранения в БД: " . $e->getMessage());
        // Не прерываем процесс, звонок может работать и без записи в БД
    }
    
    error_log("========================================");
    error_log("✅ handleCallOffer завершен");
    error_log("========================================");
}

/**
 * Обработка ответа на звонок (call answer)
 */
function handleCallAnswer($data, $from, $clients, $db) {
    error_log("========================================");
    error_log("📞 PROCESSING CALL_ANSWER");
    error_log("========================================");
    
    $callId = $data['callId'] ?? null;
    $answer = $data['answer'] ?? null;
    
    // Получаем userId отправителя
    $userId = null;
    if (isset($from->userData) && isset($from->userData->userId)) {
        $userId = $from->userData->userId;
    } elseif (isset($from->userId)) {
        $userId = $from->userId;
    }
    
    error_log("📋 Параметры:");
    error_log("  - callId: " . ($callId ?? 'NULL'));
    error_log("  - from userId: " . ($userId ?? 'NULL'));
    error_log("  - Has answer: " . ($answer ? 'YES' : 'NO'));
    if ($answer && isset($answer['sdp'])) {
        error_log("  - Answer SDP size: " . strlen($answer['sdp']) . " bytes");
    }
    
    if (!$callId || !$answer) {
        error_log("❌ CALL_ANSWER ERROR: недостаточно данных");
        error_log("========================================");
        return;
    }
    
    // Получаем информацию о звонке из БД
    try {
        error_log("🔍 Поиск звонка в БД...");
        
        $call = $db->fetchOne(
            "SELECT * FROM calls WHERE call_uuid = :call_uuid",
            ['call_uuid' => $callId]
        );
        
        if ($call) {
            error_log("✅ Звонок найден в БД");
            error_log("  - caller_id: " . $call['caller_id']);
            error_log("  - receiver_id: " . $call['receiver_id']);
            error_log("  - status: " . $call['status']);
            
            // Обновляем статус звонка
            $db->execute(
                "UPDATE calls SET status = 'active', connected_at = NOW() 
                 WHERE call_uuid = :call_uuid",
                ['call_uuid' => $callId]
            );
            
            error_log("✅ Статус звонка обновлен на 'active'");
            
            // Определяем кому отправить answer (инициатору звонка)
            $targetUserId = ($call['receiver_id'] == $userId)
                ? $call['caller_id']
                : $call['receiver_id'];
            
            error_log("📤 Отправка answer пользователю ID: $targetUserId");
            
            // Отправляем answer инициатору
            $message = [
                'type' => 'call_answer',
                'callId' => $callId,
                'answer' => $answer  // ПОЛНЫЙ answer с SDP
            ];
            
            $answerSent = false;
            foreach ($clients as $client) {
                $clientUserId = null;
                if (isset($client->userData) && isset($client->userData->userId)) {
                    $clientUserId = $client->userData->userId;
                } elseif (isset($client->userId)) {
                    $clientUserId = $client->userId;
                }
                
                if ($clientUserId && $clientUserId == $targetUserId) {
                    $client->send(json_encode($message));
                    error_log("✅ CALL_ANSWER отправлен пользователю $targetUserId");
                    $answerSent = true;
                    break;
                }
            }
            
            if (!$answerSent) {
                error_log("⚠️ Целевой пользователь $targetUserId не найден среди подключенных");
            }
        } else {
            error_log("⚠️ Звонок не найден в БД: $callId");
        }
    } catch (Exception $e) {
        error_log("❌ CALL_ANSWER ERROR: " . $e->getMessage());
        error_log("  Stack trace: " . $e->getTraceAsString());
    }
    
    error_log("========================================");
}

/**
 * Обработка ICE кандидатов
 */
function handleIceCandidate($data, $from, $clients, $db) {
    $callId = $data['callId'] ?? null;
    $candidate = $data['candidate'] ?? null;
    
    // Получаем userId отправителя
    $userId = null;
    if (isset($from->userData) && isset($from->userData->userId)) {
        $userId = $from->userData->userId;
    } elseif (isset($from->userId)) {
        $userId = $from->userId;
    }
    
    error_log("🧊 ICE_CANDIDATE: callId=$callId, from userId=$userId");
    
    if (!$callId || !$candidate) {
        error_log("❌ ICE_CANDIDATE ERROR: недостаточно данных");
        return;
    }
    
    // Получаем информацию о звонке
    try {
        $call = $db->fetchOne(
            "SELECT * FROM calls WHERE call_uuid = :call_uuid",
            ['call_uuid' => $callId]
        );
        
        if ($call) {
            // Определяем кому отправить ICE кандидата
            $targetUserId = ($call['caller_id'] == $userId)
                ? $call['receiver_id']
                : $call['caller_id'];
            
            $message = [
                'type' => 'call_ice_candidate',
                'callId' => $callId,
                'candidate' => $candidate
            ];
            
            // Отправляем ICE кандидата другому участнику
            foreach ($clients as $client) {
                $clientUserId = null;
                if (isset($client->userData) && isset($client->userData->userId)) {
                    $clientUserId = $client->userData->userId;
                } elseif (isset($client->userId)) {
                    $clientUserId = $client->userId;
                }
                
                if ($clientUserId && $clientUserId == $targetUserId) {
                    $client->send(json_encode($message));
                    error_log("✅ ICE кандидат отправлен пользователю $targetUserId");
                    break;
                }
            }
        } else {
            error_log("⚠️ ICE_CANDIDATE: звонок не найден в БД: $callId");
        }
    } catch (Exception $e) {
        error_log("❌ ICE_CANDIDATE ERROR: " . $e->getMessage());
    }
}

/**
 * Обработка завершения звонка
 */
function handleCallEnd($data, $from, $clients, $db) {
    error_log("========================================");
    error_log("📞 PROCESSING CALL_END");
    error_log("========================================");
    
    $callId = $data['callId'] ?? null;
    $reason = $data['reason'] ?? 'user_ended';
    
    // Получаем userId отправителя
    $userId = null;
    if (isset($from->userData) && isset($from->userData->userId)) {
        $userId = $from->userData->userId;
    } elseif (isset($from->userId)) {
        $userId = $from->userId;
    }
    
    error_log("📋 Параметры:");
    error_log("  - callId: " . ($callId ?? 'NULL'));
    error_log("  - reason: $reason");
    error_log("  - from userId: " . ($userId ?? 'unknown'));
    
    if (!$callId) {
        error_log("❌ CALL_END ERROR: не указан callId");
        error_log("========================================");
        return;
    }
    
    // Обновляем информацию в БД
    try {
        $call = $db->fetchOne(
            "SELECT * FROM calls WHERE call_uuid = :call_uuid",
            ['call_uuid' => $callId]
        );
        
        if ($call) {
            error_log("✅ Звонок найден в БД");
            
            // Вычисляем длительность если звонок был активен
            $duration = null;
            if ($call['connected_at']) {
                $connected = new DateTime($call['connected_at']);
                $ended = new DateTime();
                $duration = $ended->getTimestamp() - $connected->getTimestamp();
                error_log("  - Длительность: $duration секунд");
            } else {
                error_log("  - Звонок не был принят (нет connected_at)");
            }
            
            // Обновляем статус
            $db->execute(
                "UPDATE calls 
                 SET status = 'ended', 
                     ended_at = NOW(),
                     duration = :duration,
                     end_reason = :reason
                 WHERE call_uuid = :call_uuid",
                [
                    'call_uuid' => $callId,
                    'duration' => $duration,
                    'reason' => $reason
                ]
            );
            
            error_log("✅ Звонок завершен в БД");
            
            // Определяем кому отправить уведомление
            $targetUserId = null;
            if ($userId) {
                $targetUserId = ($call['caller_id'] == $userId)
                    ? $call['receiver_id']
                    : $call['caller_id'];
            }
            
            $message = [
                'type' => 'call_ended',
                'callId' => $callId,
                'reason' => $reason,
                'duration' => $duration
            ];
            
            // Отправляем уведомление
            if ($targetUserId) {
                error_log("📤 Отправка уведомления пользователю ID: $targetUserId");
                
                foreach ($clients as $client) {
                    $clientUserId = null;
                    if (isset($client->userData) && isset($client->userData->userId)) {
                        $clientUserId = $client->userData->userId;
                    } elseif (isset($client->userId)) {
                        $clientUserId = $client->userId;
                    }
                    
                    if ($clientUserId && $clientUserId == $targetUserId) {
                        $client->send(json_encode($message));
                        error_log("✅ CALL_ENDED отправлен пользователю $targetUserId");
                        break;
                    }
                }
            } else {
                // Отправляем обоим участникам звонка
                error_log("📤 Отправка уведомления обоим участникам");
                
                foreach ($clients as $client) {
                    $clientUserId = null;
                    if (isset($client->userData) && isset($client->userData->userId)) {
                        $clientUserId = $client->userData->userId;
                    } elseif (isset($client->userId)) {
                        $clientUserId = $client->userId;
                    }
                    
                    if ($clientUserId &&
                        ($clientUserId == $call['caller_id'] || $clientUserId == $call['receiver_id'])) {
                        $client->send(json_encode($message));
                        error_log("✅ CALL_ENDED отправлен пользователю $clientUserId");
                    }
                }
            }
        } else {
            error_log("⚠️ Звонок не найден в БД: $callId");
        }
    } catch (Exception $e) {
        error_log("❌ CALL_END ERROR: " . $e->getMessage());
    }
    
    error_log("========================================");
}

/**
 * Обработка отклонения звонка
 */
function handleCallDecline($data, $from, $clients, $db) {
    error_log("========================================");
    error_log("📞 PROCESSING CALL_DECLINE");
    error_log("========================================");
    
    $callId = $data['callId'] ?? null;
    
    // Получаем userId отправителя
    $userId = null;
    if (isset($from->userData) && isset($from->userData->userId)) {
        $userId = $from->userData->userId;
    } elseif (isset($from->userId)) {
        $userId = $from->userId;
    }
    
    error_log("📋 Параметры:");
    error_log("  - callId: " . ($callId ?? 'NULL'));
    error_log("  - from userId: " . ($userId ?? 'NULL'));
    
    if (!$callId) {
        error_log("❌ CALL_DECLINE ERROR: не указан callId");
        error_log("========================================");
        return;
    }
    
    // Обновляем информацию в БД
    try {
        $call = $db->fetchOne(
            "SELECT * FROM calls WHERE call_uuid = :call_uuid",
            ['call_uuid' => $callId]
        );
        
        if ($call) {
            error_log("✅ Звонок найден в БД");
            
            // Обновляем статус
            $db->execute(
                "UPDATE calls 
                 SET status = 'declined', 
                     ended_at = NOW()
                 WHERE call_uuid = :call_uuid",
                ['call_uuid' => $callId]
            );
            
            error_log("✅ Звонок отклонен в БД");
            
            // Отправляем уведомление инициатору звонка
            $targetUserId = $call['caller_id'];
            
            error_log("📤 Отправка уведомления инициатору ID: $targetUserId");
            
            $message = [
                'type' => 'call_declined',
                'callId' => $callId
            ];
            
            foreach ($clients as $client) {
                $clientUserId = null;
                if (isset($client->userData) && isset($client->userData->userId)) {
                    $clientUserId = $client->userData->userId;
                } elseif (isset($client->userId)) {
                    $clientUserId = $client->userId;
                }
                
                if ($clientUserId && $clientUserId == $targetUserId) {
                    $client->send(json_encode($message));
                    error_log("✅ CALL_DECLINED отправлен пользователю $targetUserId");
                    break;
                }
            }
        } else {
            error_log("⚠️ Звонок не найден в БД: $callId");
        }
    } catch (Exception $e) {
        error_log("❌ CALL_DECLINE ERROR: " . $e->getMessage());
    }
    
    error_log("========================================");
}

/**
 * Вспомогательная функция для отладки состояния звонка
 */
function logCallState($db, $callId) {
    try {
        $call = $db->fetchOne(
            "SELECT * FROM calls WHERE call_uuid = :call_uuid",
            ['call_uuid' => $callId]
        );
        
        if ($call) {
            error_log("========================================");
            error_log("📊 CALL STATE:");
            error_log(json_encode($call, JSON_PRETTY_PRINT));
            error_log("========================================");
        } else {
            error_log("⚠️ CALL STATE: звонок $callId не найден в БД");
        }
    } catch (Exception $e) {
        error_log("❌ CALL STATE ERROR: " . $e->getMessage());
    }
}
?>
