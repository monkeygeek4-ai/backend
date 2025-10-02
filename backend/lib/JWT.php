<?php
// backend/lib/JWT.php

class JWT {
    private $secret;
    private $algorithm;
    private $expireDays;
    
    public function __construct() {
        // Загружаем конфигурацию из .env
        $this->loadConfig();
    }
    
    private function loadConfig() {
        // Пробуем загрузить из config.php
        $configFile = dirname(__DIR__) . '/config/config.php';
        if (file_exists($configFile)) {
            $config = require $configFile;
            $this->secret = $config['jwt']['secret'];
            $this->algorithm = $config['jwt']['algorithm'] ?? 'HS256';
            $this->expireDays = $config['jwt']['expire_days'] ?? 7;
            return;
        }
        
        // Загружаем из .env файла
        $envFile = dirname(__DIR__) . '/.env';
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            
            foreach ($lines as $line) {
                // Пропускаем комментарии
                if (strpos(trim($line), '#') === 0) {
                    continue;
                }
                
                // Разбираем строку
                if (strpos($line, '=') !== false) {
                    list($key, $value) = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value);
                    
                    // Убираем кавычки если есть
                    if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') ||
                        (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
                        $value = substr($value, 1, -1);
                    }
                    
                    $_ENV[$key] = $value;
                }
            }
            
            $this->secret = $_ENV['JWT_SECRET'] ?? 'SW2024$ecureW@ve!K3y#9x7mNp4qR8tUv2wXyZ';
            $this->algorithm = $_ENV['JWT_ALGORITHM'] ?? 'HS256';
            $this->expireDays = $_ENV['JWT_EXPIRE_DAYS'] ?? 7;
        } else {
            // Fallback значения
            $this->secret = 'SW2024$ecureW@ve!K3y#9x7mNp4qR8tUv2wXyZ';
            $this->algorithm = 'HS256';
            $this->expireDays = 7;
        }
    }
    
    /**
     * Генерация JWT токена (совместимый с API формат)
     */
    public function generateToken($userId, $username) {
        $header = [
            'alg' => $this->algorithm,
            'typ' => 'JWT'
        ];
        
        $now = time();
        $jti = $userId . '_' . $now . sprintf('%03d', rand(0, 999));
        
        $payload = [
            'aud' => ['securewave.sbk-19.ru'],
            'exp' => $now + ($this->expireDays * 24 * 60 * 60),
            'iat' => $now,
            'iss' => 'SecureWave',
            'jti' => $jti,
            'sub' => (string)$userId,
            'type' => 'access',
            'username' => $username,
            'userId' => (int)$userId
        ];
        
        $headerEncoded = $this->base64UrlEncode(json_encode($header));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload));
        
        $signature = hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, $this->secret, true);
        $signatureEncoded = $this->base64UrlEncode($signature);
        
        return $headerEncoded . '.' . $payloadEncoded . '.' . $signatureEncoded;
    }
    
    /**
     * Проверка и декодирование JWT токена
     */
    public function verifyToken($token) {
        try {
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                error_log("JWT: Invalid token structure");
                return false;
            }
            
            list($headerEncoded, $payloadEncoded, $signatureEncoded) = $parts;
            
            // Декодируем части
            $header = json_decode($this->base64UrlDecode($headerEncoded), true);
            $payload = json_decode($this->base64UrlDecode($payloadEncoded), true);
            
            if (!$header || !$payload) {
                error_log("JWT: Failed to decode token parts");
                return false;
            }
            
            // Проверяем алгоритм
            if (!isset($header['alg']) || $header['alg'] !== $this->algorithm) {
                error_log("JWT: Invalid algorithm - expected {$this->algorithm}, got " . ($header['alg'] ?? 'none'));
                return false;
            }
            
            // Проверяем подпись
            $signature = $this->base64UrlEncode(
                hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, $this->secret, true)
            );
            
            if (!$this->hashEquals($signature, $signatureEncoded)) {
                error_log("JWT: Signature verification failed");
                error_log("JWT: Expected: " . $signature);
                error_log("JWT: Got: " . $signatureEncoded);
                error_log("JWT: Secret used: " . substr($this->secret, 0, 10) . "...");
                return false;
            }
            
            // Проверяем срок действия
            if (isset($payload['exp']) && $payload['exp'] < time()) {
                error_log("JWT: Token expired at " . date('Y-m-d H:i:s', $payload['exp']));
                return false;
            }
            
            // Нормализуем данные для обратной совместимости
            if (!isset($payload['userId']) && isset($payload['sub'])) {
                $payload['userId'] = $payload['sub'];
            }
            
            return $payload;
            
        } catch (Exception $e) {
            error_log("JWT: Exception during verification: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Base64 URL-safe кодирование
     */
    private function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    
    /**
     * Base64 URL-safe декодирование
     */
    private function base64UrlDecode($data) {
        $padding = strlen($data) % 4;
        if ($padding) {
            $data .= str_repeat('=', 4 - $padding);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }
    
    /**
     * Безопасное сравнение хешей (защита от timing attack)
     */
    private function hashEquals($expected, $actual) {
        if (function_exists('hash_equals')) {
            return hash_equals($expected, $actual);
        }
        
        // Fallback для старых версий PHP
        if (strlen($expected) !== strlen($actual)) {
            return false;
        }
        
        $result = 0;
        for ($i = 0; $i < strlen($expected); $i++) {
            $result |= ord($expected[$i]) ^ ord($actual[$i]);
        }
        
        return $result === 0;
    }
    
    /**
     * Извлечение userId из токена без полной верификации
     */
    public function getUserIdFromToken($token) {
        try {
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                return null;
            }
            
            $payload = json_decode($this->base64UrlDecode($parts[1]), true);
            
            if (isset($payload['userId'])) {
                return $payload['userId'];
            }
            
            if (isset($payload['sub'])) {
                return $payload['sub'];
            }
            
            return null;
            
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * Обновление токена (refresh)
     */
    public function refreshToken($oldToken) {
        $payload = $this->verifyToken($oldToken);
        
        if (!$payload) {
            return false;
        }
        
        // Генерируем новый токен с теми же данными
        return $this->generateToken($payload['userId'], $payload['username']);
    }
}