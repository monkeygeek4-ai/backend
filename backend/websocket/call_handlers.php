<?php
// backend/websocket/call_handlers.php
// Обработчики звонков для WebSocket сервера

/**
 * Главная функция для обработки всех типов сообщений звонков
 */
function handleCallMessage($type, $data, $from, $clients, $db) {
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
            error_log("Неизвестный тип звонка: $type");
    }
    
    error_log("=== END CALL MESSAGE ===");
}

/**
 * Обработка предложения звонка (call offer)
 * Когда пользователь A начинает звонок пользователю B
 */
function handleCallOffer($data, $from, $clients, $db) {
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
    
    error_log("CALL_OFFER: callId=$callId, chatId=$chatId, receiverId=$receiverId, callerId=$callerId, type=$callType");
    
    if (!$callId || !$chatId || !$receiverId || !$offer || !$callerId) {
        error_log("CALL_OFFER ERROR: недостаточно данных");
        $from->send(json_encode([
            'type' => 'error',
            'message' => 'Недостаточно данных для начала звонка'
        ]));
        return;
    }
    
    // Проверяем, что receiverId - это число
    if (!is_numeric($receiverId)) {
        error_log("CALL_OFFER ERROR: receiverId не является числом: $receiverId");
        $from->send(json_encode([
            'type' => 'error',
            'message' => 'Некорректный ID получателя'
        ]));
        return;
    }
    
    // Получаем информацию о звонящем из БД
    try {
        $caller = $db->fetchOne(
            "SELECT id, username, email, avatar_url FROM users WHERE id = :id",
            ['id' => $callerId]
        );
        
        if (!$caller) {
            error_log("CALL_OFFER ERROR: звонящий не найден в БД");
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Пользователь не найден'
            ]));
            return;
        }
    } catch (Exception $e) {
        error_log("CALL_OFFER ERROR БД: " . $e->getMessage());
        return;
    }
    
    // Формируем сообщение для получателя
    $message = [
        'type' => 'call_offer',
        'callId' => $callId,
        'chatId' => $chatId,
        'callerId' => (string)$callerId,
        'callerName' => $caller['username'] ?? $caller['email'] ?? 'Неизвестный',
        'callerAvatar' => $caller['avatar_url'],
        'callType' => $callType,
        'offer' => $offer
    ];
    
    // Ищем получателя среди подключенных клиентов
    $receiverFound = false;
    foreach ($clients as $client) {
        // Проверяем userId в userData и в прямом свойстве
        $clientUserId = null;
        if (isset($client->userData) && isset($client->userData->userId)) {
            $clientUserId = $client->userData->userId;
        } elseif (isset($client->userId)) {
            $clientUserId = $client->userId;
        }
        
        error_log("Проверяем клиента: resourceId={$client->resourceId}, userId=" . ($clientUserId ?? 'null'));
        
        if ($clientUserId && $clientUserId == $receiverId) {
            $client->send(json_encode($message));
            error_log("CALL_OFFER: отправлен пользователю $receiverId (connection {$client->resourceId})");
            $receiverFound = true;
            
            // Отправляем подтверждение инициатору
            $from->send(json_encode([
                'type' => 'call_offer_sent',
                'callId' => $callId,
                'status' => 'sent'
            ]));
            break;
        }
    }
    
    if (!$receiverFound) {
        error_log("CALL_OFFER: получатель $receiverId не в сети или не найден");
        
        // Выводим список всех подключенных пользователей для отладки
        error_log("Подключенные пользователи:");
        foreach ($clients as $client) {
            $clientUserId = null;
            if (isset($client->userData) && isset($client->userData->userId)) {
                $clientUserId = $client->userData->userId;
            } elseif (isset($client->userId)) {
                $clientUserId = $client->userId;
            }
            if ($clientUserId) {
                error_log("  - User ID: $clientUserId (connection {$client->resourceId})");
            }
        }
        
        $from->send(json_encode([
            'type' => 'call_error',
            'callId' => $callId,
            'error' => 'Пользователь не в сети'
        ]));
    }
    
    // Сохраняем информацию о звонке в БД
    try {
        // Получаем ID чата по UUID
        $chat = $db->fetchOne(
            "SELECT id FROM chats WHERE chat_uuid = :chat_uuid",
            ['chat_uuid' => $chatId]
        );
        
        if ($chat) {
            $callUuid = $callId; // Используем переданный callId как UUID
            
            // Используем execute вместо insert
            $db->execute(
                "INSERT INTO calls (call_uuid, chat_id, caller_id, receiver_id, call_type, status, started_at) 
                 VALUES (:call_uuid, :chat_id, :caller_id, :receiver_id, :call_type, :status, :started_at)",
                [
                    'call_uuid' => $callUuid,
                    'chat_id' => $chat['id'],
                    'caller_id' => $callerId,
                    'receiver_id' => $receiverId,
                    'call_type' => $callType,
                    'status' => 'pending',
                    'started_at' => date('Y-m-d H:i:s')
                ]
            );
            error_log("CALL_OFFER: звонок сохранен в БД");
        } else {
            error_log("CALL_OFFER: чат не найден в БД: $chatId");
        }
    } catch (Exception $e) {
        error_log("CALL_OFFER: ошибка сохранения в БД: " . $e->getMessage());
        // Не прерываем процесс, звонок может работать и без записи в БД
    }
}

/**
 * Обработка ответа на звонок (call answer)
 */
function handleCallAnswer($data, $from, $clients, $db) {
    $callId = $data['callId'] ?? null;
    $answer = $data['answer'] ?? null;
    
    // Получаем userId отправителя
    $userId = null;
    if (isset($from->userData) && isset($from->userData->userId)) {
        $userId = $from->userData->userId;
    } elseif (isset($from->userId)) {
        $userId = $from->userId;
    }
    
    error_log("CALL_ANSWER: callId=$callId, from userId=$userId");
    
    if (!$callId || !$answer) {
        error_log("CALL_ANSWER ERROR: недостаточно данных");
        return;
    }
    
    // Получаем информацию о звонке из БД
    try {
        $call = $db->fetchOne(
            "SELECT * FROM calls WHERE call_uuid = :call_uuid",
            ['call_uuid' => $callId]
        );
        
        if ($call) {
            // Обновляем статус звонка
            $db->execute(
                "UPDATE calls SET status = 'active', connected_at = NOW() 
                 WHERE call_uuid = :call_uuid",
                ['call_uuid' => $callId]
            );
            
            // Определяем кому отправить answer (инициатору звонка)
            $targetUserId = ($call['receiver_id'] == $userId) 
                ? $call['caller_id'] 
                : $call['receiver_id'];
            
            // Отправляем answer инициатору
            $message = [
                'type' => 'call_answer',
                'callId' => $callId,
                'answer' => $answer
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
                    error_log("CALL_ANSWER: отправлен пользователю $targetUserId");
                    break;
                }
            }
        }
    } catch (Exception $e) {
        error_log("CALL_ANSWER ERROR: " . $e->getMessage());
        
        // Если БД недоступна, просто отправляем всем кроме отправителя
        $message = [
            'type' => 'call_answer',
            'callId' => $callId,
            'answer' => $answer
        ];
        
        foreach ($clients as $client) {
            if ($client !== $from) {
                $clientUserId = null;
                if (isset($client->userData) && isset($client->userData->userId)) {
                    $clientUserId = $client->userData->userId;
                } elseif (isset($client->userId)) {
                    $clientUserId = $client->userId;
                }
                
                if ($clientUserId) {
                    $client->send(json_encode($message));
                }
            }
        }
    }
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
    
    error_log("ICE_CANDIDATE: callId=$callId, from userId=$userId");
    
    if (!$callId || !$candidate) {
        error_log("ICE_CANDIDATE ERROR: недостаточно данных");
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
                    error_log("ICE_CANDIDATE: отправлен пользователю $targetUserId");
                    break;
                }
            }
        } else {
            error_log("ICE_CANDIDATE: звонок не найден в БД: $callId");
        }
    } catch (Exception $e) {
        error_log("ICE_CANDIDATE ERROR: " . $e->getMessage());
    }
}

/**
 * Обработка завершения звонка
 */
function handleCallEnd($data, $from, $clients, $db) {
    $callId = $data['callId'] ?? null;
    $reason = $data['reason'] ?? 'user_ended';
    
    // Получаем userId отправителя (может быть null для неавторизованных)
    $userId = null;
    if (isset($from->userData) && isset($from->userData->userId)) {
        $userId = $from->userData->userId;
    } elseif (isset($from->userId)) {
        $userId = $from->userId;
    }
    
    error_log("CALL_END: callId=$callId, reason=$reason, from userId=" . ($userId ?? 'unknown'));
    
    if (!$callId) {
        error_log("CALL_END ERROR: не указан callId");
        return;
    }
    
    // Обновляем информацию в БД
    try {
        $call = $db->fetchOne(
            "SELECT * FROM calls WHERE call_uuid = :call_uuid",
            ['call_uuid' => $callId]
        );
        
        if ($call) {
            // Вычисляем длительность если звонок был активен
            $duration = null;
            if ($call['connected_at']) {
                $connected = new DateTime($call['connected_at']);
                $ended = new DateTime();
                $duration = $ended->getTimestamp() - $connected->getTimestamp();
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
            
            error_log("CALL_END: звонок обновлен в БД, длительность: $duration сек");
            
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
                foreach ($clients as $client) {
                    $clientUserId = null;
                    if (isset($client->userData) && isset($client->userData->userId)) {
                        $clientUserId = $client->userData->userId;
                    } elseif (isset($client->userId)) {
                        $clientUserId = $client->userId;
                    }
                    
                    if ($clientUserId && $clientUserId == $targetUserId) {
                        $client->send(json_encode($message));
                        error_log("CALL_END: уведомление отправлено пользователю $targetUserId");
                        break;
                    }
                }
            } else {
                // Отправляем обоим участникам звонка
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
                        error_log("CALL_END: уведомление отправлено пользователю $clientUserId");
                    }
                }
            }
        } else {
            error_log("CALL_END: звонок не найден в БД: $callId");
        }
    } catch (Exception $e) {
        error_log("CALL_END ERROR: " . $e->getMessage());
    }
}

/**
 * Обработка отклонения звонка
 */
function handleCallDecline($data, $from, $clients, $db) {
    $callId = $data['callId'] ?? null;
    
    // Получаем userId отправителя
    $userId = null;
    if (isset($from->userData) && isset($from->userData->userId)) {
        $userId = $from->userData->userId;
    } elseif (isset($from->userId)) {
        $userId = $from->userId;
    }
    
    error_log("CALL_DECLINE: callId=$callId, from userId=$userId");
    
    if (!$callId) {
        error_log("CALL_DECLINE ERROR: не указан callId");
        return;
    }
    
    // Обновляем информацию в БД
    try {
        $call = $db->fetchOne(
            "SELECT * FROM calls WHERE call_uuid = :call_uuid",
            ['call_uuid' => $callId]
        );
        
        if ($call) {
            // Обновляем статус
            $db->execute(
                "UPDATE calls 
                 SET status = 'declined', 
                     ended_at = NOW()
                 WHERE call_uuid = :call_uuid",
                ['call_uuid' => $callId]
            );
            
            error_log("CALL_DECLINE: звонок отклонен в БД");
            
            // Отправляем уведомление инициатору звонка
            $targetUserId = $call['caller_id'];
            
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
                    error_log("CALL_DECLINE: уведомление отправлено пользователю $targetUserId");
                    break;
                }
            }
        }
    } catch (Exception $e) {
        error_log("CALL_DECLINE ERROR: " . $e->getMessage());
    }
}

// Вспомогательная функция для отладки
function logCallState($db, $callId) {
    try {
        $call = $db->fetchOne(
            "SELECT * FROM calls WHERE call_uuid = :call_uuid",
            ['call_uuid' => $callId]
        );
        
        if ($call) {
            error_log("CALL STATE: " . json_encode($call));
        } else {
            error_log("CALL STATE: звонок $callId не найден в БД");
        }
    } catch (Exception $e) {
        error_log("CALL STATE ERROR: " . $e->getMessage());
    }
}
?>