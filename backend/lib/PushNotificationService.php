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
    
    public function sendIncomingCallNotification($userId, $callId, $callerName, $callType, $callerAvatar = null) {
        $tokens = $this->getUserTokens($userId);
        
        if (empty($tokens)) {
            error_log("No FCM tokens found for user: $userId");
            return false;
        }
        
        $isVideo = $callType === 'video';
        
        $notification = array(
            'title' => ($isVideo ? 'Видеозвонок' : 'Аудиозвонок') . ' от ' . $callerName,
            'body' => 'Входящий ' . ($isVideo ? 'видеозвонок' : 'звонок')
        );
        
        $data = array(
            'type' => 'incoming_call',
            'callId' => $callId,
            'callerName' => $callerName,
            'callType' => $callType,
            'callerAvatar' => $callerAvatar ? $callerAvatar : '',
            'timestamp' => strval(time())
        );
        
        return $this->sendToTokens($tokens, $notification, $data, true);
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
    
    private function sendToTokens($tokens, $notification = null, $data = null, $highPriority = false) {
        if (empty($tokens)) {
            return false;
        }
        
        $success = 0;
        $failure = 0;
        
        foreach ($tokens as $token) {
            try {
                $notificationPayload = $notification ? $notification : array();
                $dataPayload = $data ? $data : array();
                
                $dataPayloadStr = array();
                foreach ($dataPayload as $key => $value) {
                    $dataPayloadStr[$key] = strval($value);
                }
                
                $result = $this->firebaseAdmin->sendNotification(
                    $token,
                    $notificationPayload,
                    $dataPayloadStr
                );
                
                if ($result) {
                    $success++;
                } else {
                    $failure++;
                    $this->removeInvalidToken($token);
                }
            } catch (Exception $e) {
                error_log("FCM Error for token " . $token . ": " . $e->getMessage());
                $failure++;
                $this->removeInvalidToken($token);
            }
        }
        
        error_log("FCM Results: " . $success . " sent, " . $failure . " failed");
        
        return $success > 0;
    }
    
    private function removeInvalidToken($token) {
        try {
            $this->db->execute(
                "DELETE FROM fcm_tokens WHERE token = :token",
                array('token' => $token)
            );
            error_log("Removed invalid FCM token: " . $token);
        } catch (Exception $e) {
            error_log("Error removing invalid token: " . $e->getMessage());
        }
    }
    
    private function truncateText($text, $length = 100) {
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        
        return mb_substr($text, 0, $length - 3) . '...';
    }
}