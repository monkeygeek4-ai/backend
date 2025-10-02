<?php
// backend/lib/InviteManager.php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/SMSService.php';

class InviteManager {
    private $db;
    private $sms;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->sms = new SMSService();
    }
    
    /**
     * Создать инвайт
     */
    public function createInvite($userId, $phone = null) {
        try {
            $code = $this->generateInviteCode();
            $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
            
            $this->db->insert(
                "INSERT INTO invites (code, created_by, phone, expires_at, created_at) 
                 VALUES (:code, :created_by, :phone, :expires_at, NOW())",
                [
                    'code' => $code,
                    'created_by' => $userId,
                    'phone' => $phone,
                    'expires_at' => $expiresAt
                ]
            );
            
            return [
                'success' => true,
                'code' => $code,
                'expires_at' => $expiresAt
            ];
            
        } catch (Exception $e) {
            error_log("Create invite error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Ошибка создания инвайта'
            ];
        }
    }
    
    /**
     * Отправить инвайт по SMS
     */
    public function sendInvite($userId, $phone) {
        try {
            $inviteResult = $this->createInvite($userId, $phone);
            
            if (!$inviteResult['success']) {
                return $inviteResult;
            }
            
            $code = $inviteResult['code'];
            
            // Отправляем SMS через Call Password
            $smsResult = $this->sms->sendVerificationCode($phone, $code);
            
            if ($smsResult['success']) {
                return [
                    'success' => true,
                    'code' => $code,
                    'message' => 'Инвайт отправлен'
                ];
            } else {
                return [
                    'success' => false,
                    'error' => $smsResult['error'] ?? 'Ошибка отправки SMS'
                ];
            }
            
        } catch (Exception $e) {
            error_log("Send invite error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Ошибка отправки инвайта'
            ];
        }
    }
    
    /**
     * Валидировать инвайт-код
     */
    public function validateInvite($code) {
        try {
            // Сначала удаляем все истекшие инвайты
            $this->deleteExpiredInvites();
            
            $invite = $this->db->fetch(
                "SELECT * FROM invites WHERE code = :code",
                ['code' => $code]
            );
            
            if (!$invite) {
                return [
                    'valid' => false,
                    'error' => 'Неверный инвайт-код'
                ];
            }
            
            if ($invite['is_used']) {
                return [
                    'valid' => false,
                    'error' => 'Инвайт уже использован'
                ];
            }
            
            // Проверяем срок действия
            if (isset($invite['expires_at']) && $invite['expires_at']) {
                $expiryDate = new DateTime($invite['expires_at']);
                $now = new DateTime();
                
                if ($now > $expiryDate) {
                    return [
                        'valid' => false,
                        'error' => 'Срок действия инвайта истек'
                    ];
                }
            }
            
            return [
                'valid' => true,
                'invite' => $invite
            ];
            
        } catch (Exception $e) {
            error_log("Validate invite error: " . $e->getMessage());
            return [
                'valid' => false,
                'error' => 'Ошибка проверки кода'
            ];
        }
    }
    
    /**
     * Отметить инвайт как использованный
     */
    public function markInviteAsUsed($code, $userId) {
        try {
            $this->db->execute(
                "UPDATE invites 
                 SET is_used = true, used_by = :user_id, used_at = NOW() 
                 WHERE code = :code",
                [
                    'code' => $code,
                    'user_id' => $userId
                ]
            );
            
            return [
                'success' => true
            ];
            
        } catch (Exception $e) {
            error_log("Mark invite as used error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Ошибка обновления инвайта'
            ];
        }
    }
    
    /**
     * Получить список инвайтов пользователя
     */
    public function getUserInvites($userId) {
        try {
            // Удаляем истекшие инвайты перед показом списка
            $this->deleteExpiredInvites();
            
            $invites = $this->db->fetchAll(
                "SELECT 
                    i.*,
                    u.username as used_by_username
                 FROM invites i
                 LEFT JOIN users u ON u.id = i.used_by
                 WHERE i.created_by = :user_id
                 ORDER BY i.created_at DESC",
                ['user_id' => $userId]
            );
            
            return [
                'success' => true,
                'invites' => $invites
            ];
            
        } catch (Exception $e) {
            error_log("Get user invites error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Ошибка получения инвайтов'
            ];
        }
    }
    
    /**
     * Создать SMS код верификации
     */
    public function createVerificationCode($phone, $inviteCode = null) {
        try {
            // Удаляем старые неиспользованные коды для этого номера
            $this->db->execute(
                "DELETE FROM sms_verifications 
                 WHERE phone = :phone AND is_verified = false",
                ['phone' => $phone]
            );
            
            $code = SMSService::generateCode();
            $expiresAt = date('Y-m-d H:i:s', strtotime('+5 minutes'));
            
            $this->db->insert(
                "INSERT INTO sms_verifications (phone, code, invite_code, expires_at, created_at) 
                 VALUES (:phone, :code, :invite_code, :expires_at, NOW())",
                [
                    'phone' => $phone,
                    'code' => $code,
                    'invite_code' => $inviteCode,
                    'expires_at' => $expiresAt
                ]
            );
            
            // Отправляем SMS
            $smsResult = $this->sms->sendVerificationCode($phone, $code);
            
            if ($smsResult['success']) {
                return [
                    'success' => true,
                    'message' => 'Код отправлен на номер ' . $phone
                ];
            } else {
                return [
                    'success' => false,
                    'error' => $smsResult['error'] ?? 'Ошибка отправки SMS'
                ];
            }
            
        } catch (Exception $e) {
            error_log("Create verification code error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Ошибка создания кода'
            ];
        }
    }
    
    /**
     * Проверить SMS код
     */
    public function verifyCode($phone, $code) {
        try {
            $verification = $this->db->fetch(
                "SELECT * FROM sms_verifications 
                 WHERE phone = :phone 
                 AND code = :code 
                 AND is_verified = false
                 AND expires_at > NOW()
                 ORDER BY created_at DESC 
                 LIMIT 1",
                [
                    'phone' => $phone,
                    'code' => $code
                ]
            );
            
            if (!$verification) {
                return [
                    'success' => false,
                    'error' => 'Неверный или истекший код'
                ];
            }
            
            // Отмечаем как проверенный
            $this->db->execute(
                "UPDATE sms_verifications 
                 SET is_verified = true 
                 WHERE id = :id",
                ['id' => $verification['id']]
            );
            
            return [
                'success' => true,
                'invite_code' => $verification['invite_code']
            ];
            
        } catch (Exception $e) {
            error_log("Verify code error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Ошибка проверки кода'
            ];
        }
    }
    
    /**
     * Удалить истекшие инвайты (автоматически)
     */
    private function deleteExpiredInvites() {
        try {
            $this->db->execute(
                "DELETE FROM invites 
                 WHERE expires_at < NOW() 
                 AND is_used = false"
            );
        } catch (Exception $e) {
            error_log("Delete expired invites error: " . $e->getMessage());
        }
    }
    
    /**
     * Генерация инвайт-кода
     */
    private function generateInviteCode() {
        $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        
        for ($i = 0; $i < 8; $i++) {
            $code .= $characters[random_int(0, strlen($characters) - 1)];
        }
        
        return $code;
    }
}