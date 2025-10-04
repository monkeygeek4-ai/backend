<?php
// backend/lib/InviteManager.php
// Менеджер для работы с Call Password (авторизация по звонку) и инвайтами

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/SimpleInviteManager.php';

class InviteManager {
    private $db;
    private $simpleInviteManager;
    
    // SMSProfi API
    private $apiUrl = 'https://lcab.smsprofi.ru/json/v1.0';
    private $apiToken;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->simpleInviteManager = new SimpleInviteManager();
        
        // Загружаем из .env
        $this->apiToken = $_ENV['SMSPROFI_API_TOKEN'] ?? null;
        
        if (!$this->apiToken) {
            error_log("Warning: SMSProfi API token not configured");
        }
    }
    
    /**
     * Валидация инвайт-кода
     */
    public function validateInvite($code) {
        return $this->simpleInviteManager->checkInvite($code);
    }
    
    /**
     * Создание и отправка кода через Call Password
     */
    public function createVerificationCode($phone, $inviteCode = null) {
        try {
            // Если есть инвайт-код, проверяем его
            if ($inviteCode) {
                $inviteCheck = $this->simpleInviteManager->checkInvite($inviteCode);
                if (!$inviteCheck['valid']) {
                    return [
                        'success' => false,
                        'error' => $inviteCheck['error'] ?? 'Неверный код приглашения'
                    ];
                }
            }
            
            // Очищаем номер телефона (оставляем только цифры)
            $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
            
            if (strlen($cleanPhone) !== 11 || !str_starts_with($cleanPhone, '7')) {
                return [
                    'success' => false,
                    'error' => 'Неверный формат номера телефона'
                ];
            }
            
            // Отправляем через Call Password API
            $sendResult = $this->sendCallPassword($cleanPhone);
            
            if (!$sendResult['success']) {
                return $sendResult;
            }
            
            $requestId = $sendResult['id'];
            $code = $sendResult['code'];
            
            // Сохраняем в БД (PostgreSQL)
            $this->db->execute(
                "INSERT INTO verification_codes (phone, request_id, code, invite_code, expires_at, created_at) 
                 VALUES (:phone, :request_id, :code, :invite_code, NOW() + INTERVAL '10 minutes', NOW())
                 ON CONFLICT (phone) DO UPDATE SET
                 request_id = EXCLUDED.request_id,
                 code = EXCLUDED.code,
                 invite_code = EXCLUDED.invite_code, 
                 expires_at = NOW() + INTERVAL '10 minutes',
                 attempts = 0,
                 verified = FALSE,
                 created_at = NOW()",
                [
                    'phone' => $cleanPhone,
                    'request_id' => $requestId,
                    'code' => $code,
                    'invite_code' => $inviteCode
                ]
            );
            
            error_log("Call Password sent to $cleanPhone, id: $requestId, code: $code");
            
            return [
                'success' => true,
                'message' => 'Вам поступит входящий звонок. Код подтверждения - последние 4 цифры номера звонящего.'
            ];
            
        } catch (Exception $e) {
            error_log("Error creating verification code: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Ошибка отправки кода'
            ];
        }
    }
    
    /**
     * Проверка введенного кода
     */
    public function verifyCode($phone, $code) {
        try {
            $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
            
            // Получаем запись из БД
            $record = $this->db->fetchOne(
                "SELECT * FROM verification_codes 
                 WHERE phone = :phone 
                 AND expires_at > NOW()
                 ORDER BY created_at DESC 
                 LIMIT 1",
                ['phone' => $cleanPhone]
            );
            
            if (!$record) {
                return [
                    'success' => false,
                    'error' => 'Код не найден или истек. Запросите новый код'
                ];
            }
            
            // Проверяем количество попыток
            if ($record['attempts'] >= 3) {
                return [
                    'success' => false,
                    'error' => 'Превышено количество попыток. Запросите новый код'
                ];
            }
            
            // Увеличиваем счетчик попыток
            $this->db->execute(
                "UPDATE verification_codes 
                 SET attempts = attempts + 1 
                 WHERE id = :id",
                ['id' => $record['id']]
            );
            
            // Проверяем код (последние 4 цифры)
            if ($code === $record['code']) {
                // Помечаем как проверенный
                $this->db->execute(
                    "UPDATE verification_codes 
                     SET verified = TRUE 
                     WHERE id = :id",
                    ['id' => $record['id']]
                );
                
                return [
                    'success' => true,
                    'verified' => true,
                    'inviteCode' => $record['invite_code']
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Неверный код'
                ];
            }
            
        } catch (Exception $e) {
            error_log("Error verifying code: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Ошибка проверки кода'
            ];
        }
    }
    
    /**
     * Отметить инвайт как использованный
     */
    public function markInviteAsUsed($code, $userId) {
        return $this->simpleInviteManager->useInvite($code, $userId);
    }
    
    /**
     * Отправка Call Password через SMSProfi API
     */
    private function sendCallPassword($phone) {
        if (!$this->apiToken) {
            // Если API не настроен, возвращаем тестовые данные
            error_log("Warning: SMSProfi API not configured, using test mode");
            $testCode = str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
            return [
                'success' => true,
                'id' => 'test-' . uniqid(),
                'code' => $testCode
            ];
        }
        
        $data = [
            'recipient' => $phone
        ];
        
        $ch = curl_init($this->apiUrl . '/callpassword/send');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-Token: ' . $this->apiToken
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        error_log("SMSProfi API response: HTTP $httpCode, Body: $response");
        
        if ($httpCode === 200) {
            $result = json_decode($response, true);
            
            if ($result['success'] && isset($result['result']['id'], $result['result']['code'])) {
                return [
                    'success' => true,
                    'id' => $result['result']['id'],
                    'code' => $result['result']['code']
                ];
            }
            
            // Обработка ошибки из API
            $errorMsg = $result['error']['descr'] ?? 'Неизвестная ошибка';
            error_log("SMSProfi API error: " . $errorMsg);
            
            return [
                'success' => false,
                'error' => 'Ошибка отправки: ' . $errorMsg
            ];
        }
        
        error_log("SMSProfi API error: HTTP $httpCode");
        
        return [
            'success' => false,
            'error' => 'Ошибка связи с сервисом отправки'
        ];
    }
    
    /**
     * Форматирование номера телефона для отображения
     */
    private function formatPhone($phone) {
        if (strlen($phone) === 11) {
            return '+' . substr($phone, 0, 1) . ' (' .
                   substr($phone, 1, 3) . ') ' .
                   substr($phone, 4, 3) . '-' .
                   substr($phone, 7, 2) . '-' .
                   substr($phone, 9, 2);
        }
        return $phone;
    }
}
