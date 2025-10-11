<?php
// backend/lib/FirebaseAdmin.php

class FirebaseAdmin {
    private $projectId;
    private $serviceAccountPath;
    private $accessToken;
    private $tokenExpiry;
    
    public function __construct() {
        $this->serviceAccountPath = __DIR__ . '/../config/wave-messenger-56985-firebase-adminsdk-fbsvc-e4108fe6e9.json';
        
        if (!file_exists($this->serviceAccountPath)) {
            throw new Exception("Firebase service account file not found at: " . $this->serviceAccountPath);
        }
        
        $serviceAccount = json_decode(file_get_contents($this->serviceAccountPath), true);
        $this->projectId = $serviceAccount['project_id'];
    }
    
    /**
     * Получение OAuth2 access token для Firebase Admin SDK
     */
    private function getAccessToken() {
        // Проверяем кэшированный токен
        if ($this->accessToken && $this->tokenExpiry && time() < $this->tokenExpiry) {
            return $this->accessToken;
        }
        
        $serviceAccount = json_decode(file_get_contents($this->serviceAccountPath), true);
        
        // Создаем JWT
        $now = time();
        $exp = $now + 3600; // 1 час
        
        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT'
        ];
        
        $payload = [
            'iss' => $serviceAccount['client_email'],
            'sub' => $serviceAccount['client_email'],
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $exp,
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging'
        ];
        
        $base64UrlHeader = $this->base64UrlEncode(json_encode($header));
        $base64UrlPayload = $this->base64UrlEncode(json_encode($payload));
        
        $signatureInput = $base64UrlHeader . '.' . $base64UrlPayload;
        
        // Подписываем JWT
        $privateKey = openssl_pkey_get_private($serviceAccount['private_key']);
        openssl_sign($signatureInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        
        $base64UrlSignature = $this->base64UrlEncode($signature);
        $jwt = $signatureInput . '.' . $base64UrlSignature;
        
        // Обмениваем JWT на access token
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt
        ]));
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            error_log("❌ Failed to get FCM access token: HTTP $httpCode - $response");
            throw new Exception("Failed to get access token: " . $response);
        }
        
        $tokenData = json_decode($response, true);
        $this->accessToken = $tokenData['access_token'];
        $this->tokenExpiry = time() + ($tokenData['expires_in'] - 300); // 5 минут запаса
        
        error_log("✅ FCM access token obtained successfully");
        
        return $this->accessToken;
    }
    
    /**
     * ✅ ИСПРАВЛЕНО: Отправка уведомления через FCM v1 API
     * Поддержка data-only сообщений (без notification payload)
     */
    public function sendNotification($token, $notification = null, $data = []) {
        try {
            $accessToken = $this->getAccessToken();
            
            // Строим message
            $message = [
                'token' => $token,
                'android' => [
                    'priority' => 'high',
                    'notification' => [
                        'channel_id' => 'calls_channel', // ⭐ Важно для звонков
                        'sound' => 'default',
                        'priority' => 'max',
                        'visibility' => 'public'
                    ]
                ]
            ];
            
            // ⭐ Добавляем notification только если он передан
            if ($notification !== null && !empty($notification)) {
                $message['notification'] = $notification;
            }
            
            // ⭐ Добавляем data payload
            if (!empty($data)) {
                $message['data'] = $data;
            }
            
            $url = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";
            
            $payload = json_encode(['message' => $message]);
            
            error_log("📤 Sending FCM request:");
            error_log("  URL: $url");
            error_log("  Payload: " . $payload);
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            error_log("📥 FCM Response: HTTP $httpCode");
            error_log("  Response body: $response");
            
            if ($httpCode === 200) {
                error_log("✅ FCM notification sent successfully");
                return true;
            } else {
                error_log("❌ FCM Error: HTTP $httpCode - $response");
                return false;
            }
        } catch (Exception $e) {
            error_log("❌ FCM Exception: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Отправка уведомлений на несколько токенов
     */
    public function sendMulticast($tokens, $notification, $data = []) {
        if (empty($tokens)) {
            return ['success' => 0, 'failure' => 0];
        }
        
        $success = 0;
        $failure = 0;
        
        foreach ($tokens as $token) {
            if ($this->sendNotification($token, $notification, $data)) {
                $success++;
            } else {
                $failure++;
            }
        }
        
        return [
            'success' => $success,
            'failure' => $failure
        ];
    }
    
    /**
     * Base64 URL encode
     */
    private function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
