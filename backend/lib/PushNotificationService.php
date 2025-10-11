<?php
// backend/lib/PushNotificationService.php

require_once __DIR__ . '/FirebaseAdmin.php';

class PushNotificationService {
    private $db;
    private $firebaseAdmin;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->firebaseAdmin = new FirebaseAdmin();
    }
    
    public function sendNewMessageNotification($userId, $chatId, $senderName, $messageText, $senderAvatar = null) {
        $tokens = $this->getUserTokens($userId);
        
        if (empty($tokens)) {
            error_log("No FCM tokens found for user: $userId");
            return false;
        }
        
        $notification = array(
            'title' => $senderName,
            'body' => $this->truncateText($messageText, 100)
        );
        
        $data = array(
            'type' => 'new_message',
            'chatId' => $chatId,
            'senderName' => $senderName,
            'messageText' => $messageText,
            'senderAvatar' => $senderAvatar ? $senderAvatar : '',
            'timestamp' => strval(time())
        );
        
        return $this->sendToTokens($tokens, $notification, $data);
    }
    
    /**
     * ✅ ИСПРАВЛЕНО: Специальный формат для Android звонков
     */
    public function sendIncomingCallNotification($userId, $callId, $callerName, $callType, $callerAvatar = null) {
        $tokens = $this->getUserTokens($userId);
        
        if (empty($tokens)) {
            error_log("No FCM tokens found for user: $userId");
            return false;
        }
        
        $isVideo = $callType === 'video';
        
        // ⭐ DATA-ONLY сообщение для Android (без notification payload)
        $data = array(
            'type' => 'incoming_call',
            'callId' => $callId,
            'callerName' => $callerName,
            'callType' => $callType,
            'callerAvatar' => $callerAvatar ? $callerAvatar : '',
            'timestamp' => strval(time()),
            // Дополнительные данные для отображения
            'title' => ($isVideo ? 'Видеозвонок' : 'Аудиозвонок') . ' от ' . $callerName,
            'body' => 'Входящий ' . ($isVideo ? 'видеозвонок' : 'звонок')
        );
        
        // ⭐ Отправляем БЕЗ notification payload для Android
        return $this->sendToTokens($tokens, null, $data, true);
    }
    
    public function sendCallEndedNotification($userId, $callId) {
        $tokens = $this->getUserTokens($userId);
        
        if (empty($tokens)) {
            return false;
        }
        
        $data = array(
            'type' => 'call_ended',
            'callId' => $callId,
            'action' => 'cancel_notification',
            'timestamp' => strval(time())
        );
        
        // БЕЗ notification payload
        return $this->sendToTokens($tokens, null, $data, true);
    }
    
    private function getUserTokens($userId) {
        $results = $this->db->fetchAll(
            "SELECT token, platform FROM fcm_tokens 
             WHERE user_id = :user_id 
             AND updated_at > CURRENT_TIMESTAMP - INTERVAL '30 days'",
            array('user_id' => $userId)
        );
        
        $tokens = array();
        foreach ($results as $row) {
            $tokens[] = $row['token'];
        }
        return $tokens;
    }
    
    /**
     * ✅ ИСПРАВЛЕНО: Правильная отправка через FCM v1 API
     */
    private function sendToTokens($tokens, $notification = null, $data = null, $highPriority = false) {
        if (empty($tokens)) {
            return false;
        }
        
        $success = 0;
        $failure = 0;
        
        foreach ($tokens as $token) {
            try {
                // Конвертируем все значения data в строки
                $dataPayloadStr = array();
                if ($data) {
                    foreach ($data as $key => $value) {
                        $dataPayloadStr[$key] = strval($value);
                    }
                }
                
                // ⭐ Если notification = null, отправляем только data
                $result = $this->firebaseAdmin->sendNotification(
                    $token,
                    $notification, // null для data-only сообщений
                    $dataPayloadStr
                );
                
                if ($result) {
                    $success++;
                    error_log("✅ FCM sent to token: " . substr($token, 0, 20) . "...");
                } else {
                    $failure++;
                    error_log("❌ FCM failed for token: " . substr($token, 0, 20) . "...");
                    $this->removeInvalidToken($token);
                }
            } catch (Exception $e) {
                error_log("❌ FCM Exception for token " . substr($token, 0, 20) . "...: " . $e->getMessage());
                $failure++;
                $this->removeInvalidToken($token);
            }
        }
        
        error_log("📊 FCM Results: {$success} sent, {$failure} failed");
        
        return $success > 0;
    }
    
    private function removeInvalidToken($token) {
        try {
            $this->db->execute(
                "DELETE FROM fcm_tokens WHERE token = :token",
                array('token' => $token)
            );
            error_log("🗑️ Removed invalid FCM token: " . substr($token, 0, 20) . "...");
        } catch (Exception $e) {
            error_log("⚠️ Error removing invalid token: " . $e->getMessage());
        }
    }
    
    private function truncateText($text, $length = 100) {
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        
        return mb_substr($text, 0, $length - 3) . '...';
    }
}
