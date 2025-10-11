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
     * ✅✅✅ ИСПРАВЛЕНО: Правильная отправка через FCM v1 API
     * БЕЗ недопустимых полей в android.notification
     */
    public function sendNotification($token, $notification = null, $data = []) {
        try {
            error_log("========================================");
            error_log("📤 FCM sendNotification called");
            error_log("  Token: " . substr($token, 0, 30) . "...");
            error_log("  Has notification: " . ($notification ? "YES" : "NO"));
            error_log("  Has data: " . (empty($data) ? "NO" : "YES"));
            if (!empty($data)) {
                error_log("  Data type: " . ($data['type'] ?? 'unknown'));
            }
            
            $accessToken = $this->getAccessToken();
            
            // ⭐⭐⭐ КРИТИЧНО: Правильная структура сообщения
            $message = [
                'token' => $token,
            ];
            
            // ВАРИАНТ 1: Data-only сообщение (для foreground звонков)
            if ($notification === null && !empty($data)) {
                error_log("📦 Building DATA-ONLY message (for foreground)");
                
                $message['android'] = [
                    'priority' => 'high',
                    // ⭐ БЕЗ android.notification для data-only!
                ];
                
                $message['data'] = $data;
                
                error_log("✅ Data-only message structure ready");
            }
            // ВАРИАНТ 2: Notification + data (для background/terminated)
            else if ($notification !== null) {
                error_log("📢 Building NOTIFICATION message (for background)");
                
                $message['notification'] = $notification;
                
                // ⭐⭐⭐ ИСПРАВЛЕНО: Убраны недопустимые поля
                $message['android'] = [
                    'priority' => 'high',
                    'notification' => [
                        'channel_id' => 'calls_channel',
                        'sound' => 'default',
                        // ❌ УДАЛЕНО: 'priority' - нет такого поля!
                        // ❌ УДАЛЕНО: 'visibility' - нет такого поля!
                    ]
                ];
                
                if (!empty($data)) {
                    $message['data'] = $data;
                }
                
                error_log("✅ Notification message structure ready");
            }
            // ВАРИАНТ 3: Только data (fallback)
            else {
                error_log("⚠️ Building empty message (fallback)");
                $message['data'] = $data;
            }
            
            $url = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";
            
            $payload = json_encode(['message' => $message], JSON_PRETTY_PRINT);
            
            error_log("========================================");
            error_log("📤 SENDING FCM REQUEST:");
            error_log("  URL: $url");
            error_log("  Project ID: {$this->projectId}");
            error_log("========================================");
            error_log("📦 PAYLOAD:");
            error_log($payload);
            error_log("========================================");
            
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
            $curlError = curl_error($ch);
            curl_close($ch);
            
            error_log("========================================");
            error_log("📥 FCM RESPONSE:");
            error_log("  HTTP Code: $httpCode");
            if ($curlError) {
                error_log("  CURL Error: $curlError");
            }
            error_log("  Response body: $response");
            error_log("========================================");
            
            if ($httpCode === 200) {
                error_log("✅✅✅ FCM NOTIFICATION SENT SUCCESSFULLY!");
                error_log("========================================");
                return true;
            } else {
                error_log("❌❌❌ FCM ERROR!");
                error_log("  HTTP Code: $httpCode");
                error_log("  Response: $response");
                error_log("========================================");
                
                // Парсим ошибку для детального логирования
                $errorData = json_decode($response, true);
                if ($errorData && isset($errorData['error'])) {
                    error_log("  Error code: " . ($errorData['error']['code'] ?? 'unknown'));
                    error_log("  Error message: " . ($errorData['error']['message'] ?? 'unknown'));
                    if (isset($errorData['error']['details'])) {
                        error_log("  Error details: " . json_encode($errorData['error']['details']));
                    }
                }
                
                return false;
            }
        } catch (Exception $e) {
            error_log("========================================");
            error_log("❌❌❌ FCM EXCEPTION!");
            error_log("  Message: " . $e->getMessage());
            error_log("  File: " . $e->getFile());
            error_log("  Line: " . $e->getLine());
            error_log("  Trace: " . $e->getTraceAsString());
            error_log("========================================");
            return false;
        }
    }
    
    /**
     * Отправка уведомлений на несколько токенов
     */
    public function sendMulticast($tokens, $notification, $data = []) {
        if (empty($tokens)) {
            error_log("⚠️ sendMulticast: no tokens provided");
            return ['success' => 0, 'failure' => 0];
        }
        
        error_log("========================================");
        error_log("📤 FCM sendMulticast");
        error_log("  Tokens count: " . count($tokens));
        error_log("========================================");
        
        $success = 0;
        $failure = 0;
        
        foreach ($tokens as $token) {
            if ($this->sendNotification($token, $notification, $data)) {
                $success++;
            } else {
                $failure++;
            }
        }
        
        error_log("========================================");
        error_log("📊 FCM Multicast Results:");
        error_log("  Success: $success");
        error_log("  Failure: $failure");
        error_log("========================================");
        
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