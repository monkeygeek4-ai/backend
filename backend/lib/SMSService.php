<?php
// backend/lib/SMSService.php

class SMSService {
    private $apiUrl = 'https://api.iqsms.ru/messages/v2/send.json';
    private $login;
    private $password;
    
    public function __construct() {
        // Берем из .env или config
        $this->login = $_ENV['IQSMS_LOGIN'] ?? '';
        $this->password = $_ENV['IQSMS_PASSWORD'] ?? '';
        
        if (empty($this->login) || empty($this->password)) {
            error_log("IQSMS credentials not configured");
        }
    }
    
    /**
     * Отправить SMS с кодом верификации
     */
    public function sendVerificationCode($phone, $code) {
        $text = "Ваш код подтверждения: $code\nКод действителен 5 минут.";
        
        return $this->sendSMS($phone, $text);
    }
    
    /**
     * Отправить SMS с инвайт-ссылкой
     */
    public function sendInvite($phone, $inviteCode, $inviterName) {
        $appUrl = $_ENV['APP_URL'] ?? 'https://yourapp.com';
        $inviteUrl = "$appUrl/register?invite=$inviteCode";
        
        $text = "$inviterName приглашает вас в SecureWave!\nПерейдите по ссылке для регистрации: $inviteUrl";
        
        return $this->sendSMS($phone, $text);
    }
    
    /**
     * Базовый метод отправки SMS через IQSMS API
     */
    private function sendSMS($phone, $text, $sender = null) {
        // Форматируем номер телефона (должен быть в формате 71234567890)
        $phone = $this->formatPhone($phone);
        
        if (!$phone) {
            return [
                'success' => false,
                'error' => 'Неверный формат номера телефона'
            ];
        }
        
        $clientId = uniqid('sms_', true);
        
        $payload = [
            'login' => $this->login,
            'password' => $this->password,
            'messages' => [
                [
                    'phone' => $phone,
                    'clientId' => $clientId,
                    'text' => $text
                ]
            ]
        ];
        
        // Добавляем sender если указан
        if ($sender) {
            $payload['messages'][0]['sender'] = $sender;
        }
        
        try {
            $ch = curl_init($this->apiUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json'
                ],
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_TIMEOUT => 10
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($error) {
                error_log("IQSMS API Error: $error");
                return [
                    'success' => false,
                    'error' => 'Ошибка отправки SMS: ' . $error
                ];
            }
            
            $result = json_decode($response, true);
            
            error_log("IQSMS Response: " . print_r($result, true));
            
            if ($httpCode === 200 && isset($result['status']) && $result['status'] === 'ok') {
                $messageStatus = $result['messages'][0]['status'] ?? 'unknown';
                
                if ($messageStatus === 'accepted') {
                    return [
                        'success' => true,
                        'smscId' => $result['messages'][0]['smscId'] ?? null,
                        'clientId' => $clientId
                    ];
                } else {
                    return [
                        'success' => false,
                        'error' => 'SMS не принято: ' . $messageStatus
                    ];
                }
            } else {
                return [
                    'success' => false,
                    'error' => $result['error'] ?? 'Ошибка API'
                ];
            }
            
        } catch (Exception $e) {
            error_log("SMS Send Exception: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Ошибка отправки SMS'
            ];
        }
    }
    
    /**
     * Форматирование номера телефона для API
     * Принимает: +7 (123) 456-78-90, 8 123 456 78 90, 71234567890
     * Возвращает: 71234567890
     */
    private function formatPhone($phone) {
        // Убираем все кроме цифр
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // Если начинается с 8, заменяем на 7
        if (strlen($phone) === 11 && $phone[0] === '8') {
            $phone = '7' . substr($phone, 1);
        }
        
        // Если начинается с 7 и длина 11 - все ок
        if (strlen($phone) === 11 && $phone[0] === '7') {
            return $phone;
        }
        
        // Если 10 цифр, добавляем 7 в начало
        if (strlen($phone) === 10) {
            return '7' . $phone;
        }
        
        return null;
    }
    
    /**
     * Генерация случайного 6-значного кода
     */
    public static function generateCode() {
        return str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
    
    /**
     * Проверка статуса отправленного SMS
     */
    public function checkStatus($smscId) {
        $statusUrl = 'https://api.iqsms.ru/messages/v2/status.json';
        
        $payload = [
            'login' => $this->login,
            'password' => $this->password,
            'messages' => [
                ['smscId' => $smscId]
            ]
        ];
        
        try {
            $ch = curl_init($statusUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json'
                ],
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_TIMEOUT => 10
            ]);
            
            $response = curl_exec($ch);
            curl_close($ch);
            
            return json_decode($response, true);
            
        } catch (Exception $e) {
            error_log("Check SMS status error: " . $e->getMessage());
            return null;
        }
    }
}