<?php
// backend/lib/SMSService.php

class SMSService {
    private $apiUrl = 'https://lcab.smsprofi.ru/json/v1.0/callpassword/send';
    private $token;
    
    public function __construct() {
        $this->token = $_ENV['SMSPROFI_TOKEN'] ?? 'th8knyfhbp9l8zb0knxv9swfvbnbbvjj828ig5yt1stwz6g3j6mh2ef9meezydx1';
    }
    
    /**
     * Отправить код верификации через звонок
     */
    public function sendVerificationCode($phone, $code) {
        // Call Password автоматически генерирует код из последних 4 цифр номера
        // Поэтому мы просто инициируем звонок
        return $this->sendCallPassword($phone);
    }
    
    /**
     * Отправить инвайт через звонок
     * Для инвайтов лучше использовать SMS, поэтому используем резервный IQSMS
     */
    public function sendInvite($phone, $inviteCode, $inviterName) {
        // Используем резервный IQSMS для текстовых инвайтов
        return $this->sendSMSFallback($phone, "$inviterName приглашает вас в SecureWave! Код: $inviteCode");
    }
    
    /**
     * Базовый метод отправки Call Password
     */
    private function sendCallPassword($phone) {
        // Форматируем номер
        $phone = $this->formatPhone($phone);
        
        if (!$phone) {
            error_log("CallPassword: Invalid phone format");
            return [
                'success' => false,
                'error' => 'Неверный формат номера телефона'
            ];
        }
        
        // Формируем тело запроса
        $payload = [
            'recipient' => $phone,
            'id' => uniqid('call_', true),
            'tags' => ['securewave', 'verification'],
            'validate' => false // ВАЖНО: false для реальной отправки
        ];
        
        error_log("CallPassword Request: " . json_encode($payload, JSON_UNESCAPED_UNICODE));
        error_log("CallPassword Token: " . substr($this->token, 0, 20) . "...");
        error_log("CallPassword URL: " . $this->apiUrl);
        
        try {
            $ch = curl_init($this->apiUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-Token: ' . $this->token
                ],
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_VERBOSE => true
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            error_log("CallPassword HTTP Code: $httpCode");
            error_log("CallPassword Response: " . $response);
            
            if ($curlError) {
                error_log("CallPassword cURL Error: $curlError");
                return [
                    'success' => false,
                    'error' => 'Ошибка соединения: ' . $curlError
                ];
            }
            
            // Парсим JSON ответ
            $result = json_decode($response, true);
            
            if ($httpCode === 200 && isset($result['success']) && $result['success'] === true) {
                error_log("CallPassword Success - Full result: " . json_encode($result, JSON_UNESCAPED_UNICODE));
                
                $code = $result['result']['code'] ?? null;
                
                return [
                    'success' => true,
                    'code' => $code,
                    'message' => 'Звонок инициирован. Код: ' . $code,
                    'result' => $result['result'] ?? null
                ];
            } else {
                // Обработка ошибок
                $errorMsg = $result['error']['message'] ?? 'Неизвестная ошибка';
                $errorCode = $result['error']['code'] ?? 'unknown';
                
                error_log("CallPassword Error: [$errorCode] $errorMsg");
                error_log("CallPassword Full error response: " . json_encode($result, JSON_UNESCAPED_UNICODE));
                
                return [
                    'success' => false,
                    'error' => $errorMsg,
                    'errorCode' => $errorCode
                ];
            }
            
        } catch (Exception $e) {
            error_log("CallPassword Exception: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Ошибка: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Резервный метод отправки SMS через IQSMS (для инвайтов)
     */
    private function sendSMSFallback($phone, $text) {
        $login = $_ENV['IQSMS_LOGIN'] ?? 'f1759379586300';
        $password = $_ENV['IQSMS_PASSWORD'] ?? '766655';
        $apiUrl = 'https://api.iqsms.ru/messages/v2';
        
        $phone = $this->formatPhone($phone);
        if (!$phone) {
            return ['success' => false, 'error' => 'Неверный формат номера'];
        }
        
        $phone = '+' . $phone;
        
        try {
            $url = $apiUrl . '/send/?' . http_build_query([
                'phone' => $phone,
                'text' => $text,
            ]);
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
                CURLOPT_USERPWD => $login . ':' . $password,
                CURLOPT_TIMEOUT => 30
            ]);
            
            $response = curl_exec($ch);
            curl_close($ch);
            
            $response = trim($response);
            
            if (stripos($response, 'accepted') === 0) {
                $parts = explode(';', $response);
                return [
                    'success' => true,
                    'smscId' => $parts[1] ?? null,
                    'message' => 'SMS отправлено'
                ];
            }
            
            return [
                'success' => false,
                'error' => 'Ошибка SMS: ' . $response
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Ошибка: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Форматирование номера телефона
     * Возвращает: 79991234567
     */
    private function formatPhone($phone) {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        if (strlen($phone) === 11 && $phone[0] === '8') {
            $phone = '7' . substr($phone, 1);
        }
        
        if (strlen($phone) === 11 && $phone[0] === '7') {
            return $phone;
        }
        
        if (strlen($phone) === 10) {
            return '7' . $phone;
        }
        
        return null;
    }
    
    /**
     * Генерация кода из последних 4 цифр номера
     * Call Password использует именно этот принцип
     */
    public static function generateCode() {
        // Для Call Password код - это последние 4 цифры номера телефона
        // Здесь генерируем 6-значный для совместимости с SMS
        return str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
    
    /**
     * Проверка статуса (если нужно)
     */
    public function checkStatus($id) {
        // Для Call Password статус можно получить через отдельный endpoint
        // Пока возвращаем заглушку
        return ['status' => 'unknown'];
    }
}