<?php
// backend/lib/FCMService.php

class FCMService {
    private static $serverKey = 'BFa2MCbGoEgkfwY72WfpeycJjH4rTzboMqka_e0niTIHhLhBp_b5unNIus46patWHo9-KpqND1WiEiMkKIrjSR0'; // Замените на ваш ключ
    private static $fcmUrl = 'https://fcm.googleapis.com/fcm/send';
    
    /**
     * Отправка уведомления о входящем звонке
     */
    public static function sendCallNotification($fcmTokens, $callData) {
        $notification = [
            'title' => $callData['callType'] === 'video' ? 'Видеозвонок' : 'Аудиозвонок',
            'body' => "Входящий звонок от {$callData['callerName']}",
            'icon' => '/icons/Icon-192.png',
            'badge' => '/icons/Icon-192.png',
            'tag' => $callData['callId'],
            'requireInteraction' => true,
        ];
        
        $data = [
            'type' => 'incoming_call',
            'callId' => $callData['callId'],
            'callerName' => $callData['callerName'],
            'callType' => $callData['callType'],
            'callerAvatar' => $callData['callerAvatar'] ?? null,
        ];
        
        return self::sendToTokens($fcmTokens, $notification, $data);
    }
    
    /**
     * Отправка уведомления о новом сообщении
     */
    public static function sendMessageNotification($fcmTokens, $messageData) {
        $notification = [
            'title' => $messageData['senderName'],
            'body' => $messageData['messageText'],
            'icon' => '/icons/Icon-192.png',
            'badge' => '/icons/Icon-192.png',
            'tag' => $messageData['chatId'],
        ];
        
        $data = [
            'type' => 'new_message',
            'chatId' => $messageData['chatId'],
            'senderName' => $messageData['senderName'],
            'messageText' => $messageData['messageText'],
            'senderAvatar' => $messageData['senderAvatar'] ?? null,
        ];
        
        return self::sendToTokens($fcmTokens, $notification, $data);
    }
    
    /**
     * Отправка уведомления о завершении звонка
     */
    public static function sendCallEndedNotification($fcmTokens, $callId) {
        $data = [
            'type' => 'call_ended',
            'callId' => $callId,
        ];
        
        return self::sendToTokens($fcmTokens, null, $data);
    }
    
    /**
     * Отправка уведомлений на несколько токенов
     */
    private static function sendToTokens($tokens, $notification = null, $data = null) {
        if (empty($tokens)) {
            return ['success' => false, 'error' => 'No tokens provided'];
        }
        
        // Если токен один - оборачиваем в массив
        if (!is_array($tokens)) {
            $tokens = [$tokens];
        }
        
        $results = [];
        foreach ($tokens as $token) {
            $result = self::sendToToken($token, $notification, $data);
            $results[] = $result;
        }
        
        return $results;
    }
    
    /**
     * Отправка уведомления на один токен
     */
    private static function sendToToken($token, $notification = null, $data = null) {
        $payload = [
            'to' => $token,
            'priority' => 'high',
        ];
        
        if ($notification) {
            $payload['notification'] = $notification;
        }
        
        if ($data) {
            $payload['data'] = $data;
        }
        
        // Для web добавляем специфичные настройки
        $payload['webpush'] = [
            'headers' => [
                'Urgency' => 'high'
            ],
            'notification' => $notification ?? [],
            'fcm_options' => [
                'link' => 'https://securewave.sbk-19.ru'
            ]
        ];
        
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
            error_log('[FCM] Curl error: ' . curl_error($ch));
            curl_close($ch);
            return ['success' => false, 'error' => curl_error($ch)];
        }
        
        curl_close($ch);
        
        $response = json_decode($result, true);
        
        if ($httpCode === 200 && isset($response['success']) && $response['success'] === 1) {
            return ['success' => true, 'response' => $response];
        } else {
            error_log('[FCM] Send failed: ' . $result);
            return ['success' => false, 'error' => $result];
        }
    }
}