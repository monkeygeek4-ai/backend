<?php
// backend/lib/FCMService.php

class FCMService {
    // ⭐ ВАЖНО: Используйте Server Key из Firebase Console
    // Cloud Messaging -> Server key
    private static $serverKey = 'BFa2MCbGoEgkfwY72WfpeycJjH4rTzboMqka_e0niTIHhLhBp_b5unNIus46patWHo9-KpqND1WiEiMkKIrjSR0';
    private static $fcmUrl = 'https://fcm.googleapis.com/fcm/send';
    
    /**
     * Отправка уведомления о входящем звонке
     * ⭐ КРИТИЧНО: Используем ТОЛЬКО data payload для Flutter
     */
    public static function sendCallNotification($fcmTokens, $callData) {
        error_log("[FCM] ========================================");
        error_log("[FCM] 📱 Отправка уведомления о звонке");
        error_log("[FCM] Caller: " . $callData['callerName']);
        error_log("[FCM] CallId: " . $callData['callId']);
        error_log("[FCM] CallType: " . $callData['callType']);
        error_log("[FCM] ========================================");
        
        // ⭐ КРИТИЧНО: Для Android/Flutter используем ТОЛЬКО data payload
        $data = [
            'type' => 'call',  // ⭐ ВАЖНО: 'call' а не 'incoming_call'
            'call_id' => $callData['callId'],
            'caller_name' => $callData['callerName'],
            'call_type' => $callData['callType'],
            'caller_avatar' => $callData['callerAvatar'] ?? '',
            'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
        ];
        
        error_log("[FCM] Data payload: " . json_encode($data));
        
        return self::sendToTokens($fcmTokens, $data);
    }
    
    /**
     * Отправка уведомления о новом сообщении
     */
    public static function sendMessageNotification($fcmTokens, $messageData) {
        $data = [
            'type' => 'new_message',
            'chatId' => $messageData['chatId'],
            'sender_name' => $messageData['senderName'],
            'message' => $messageData['messageText'],
            'sender_avatar' => $messageData['senderAvatar'] ?? '',
        ];
        
        return self::sendToTokens($fcmTokens, $data);
    }
    
    /**
     * Отправка уведомления о завершении звонка
     */
    public static function sendCallEndedNotification($fcmTokens, $callId) {
        $data = [
            'type' => 'call_ended',
            'call_id' => $callId,
        ];
        
        return self::sendToTokens($fcmTokens, $data);
    }
    
    /**
     * Отправка уведомлений на несколько токенов
     */
    private static function sendToTokens($tokens, $data) {
        if (empty($tokens)) {
            error_log("[FCM] ⚠️ No tokens provided");
            return ['success' => false, 'error' => 'No tokens provided'];
        }
        
        // Если токен один - оборачиваем в массив
        if (!is_array($tokens)) {
            $tokens = [$tokens];
        }
        
        error_log("[FCM] 📤 Отправка на " . count($tokens) . " токенов");
        
        $results = [];
        foreach ($tokens as $token) {
            $result = self::sendToToken($token, $data);
            $results[] = $result;
        }
        
        return $results;
    }
    
    /**
     * Отправка уведомления на один токен
     * ⭐ КРИТИЧНО: Используем ТОЛЬКО data payload (без notification)
     */
    private static function sendToToken($token, $data) {
        error_log("[FCM] ========================================");
        error_log("[FCM] 📤 Отправка на токен: " . substr($token, 0, 30) . "...");
        
        // ⭐ КРИТИЧНО: Для Flutter используем ТОЛЬКО data payload
        // БЕЗ поля notification!
        $payload = [
            'to' => $token,
            'priority' => 'high',
            'data' => $data,  // ⭐ ТОЛЬКО data
        ];
        
        error_log("[FCM] Payload: " . json_encode($payload));
        
        $headers = [
            'Authorization: key=' . self::$serverKey,
            'Content-Type: application/json',
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, self::$fcmUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            error_log('[FCM] ❌ Curl error: ' . curl_error($ch));
            curl_close($ch);
            return ['success' => false, 'error' => curl_error($ch)];
        }
        
        curl_close($ch);
        
        $response = json_decode($result, true);
        
        error_log("[FCM] HTTP Code: $httpCode");
        error_log("[FCM] Response: " . json_encode($response));
        error_log("[FCM] ========================================");
        
        if ($httpCode === 200 && isset($response['success']) && $response['success'] === 1) {
            error_log("[FCM] ✅ Уведомление отправлено успешно!");
            return ['success' => true, 'response' => $response];
        } else {
            error_log('[FCM] ❌ Send failed: ' . $result);
            return ['success' => false, 'error' => $result];
        }
    }
}