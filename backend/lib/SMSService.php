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
    public function sendVerificationCode($phone, $code = null) {
        // Call Password автоматически генерирует код из последних 4 цифр номера
        // Параметр $code игнорируется, но оставлен для совместимости
        return $this->sendCallPassword($phone);
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
     * Генерация кода для отображения пользователю
     * Call Password использует последние 4 цифры номера телефона
     */
    public static function generateCode() {
        // Для совместимости с существующим кодом генерируем 6-значный код
        // В реальности Call Password сам определит код из номера
        return str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
    
    /**
     * Проверка статуса звонка (опционально)
     */
    public function checkStatus($id) {
        // Можно реализовать проверку статуса через API SMSProfi
        // Пока возвращаем заглушку
        return ['status' => 'unknown'];
    }
}