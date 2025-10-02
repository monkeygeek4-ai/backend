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
     * Создать инвайт-код
     */
    public function createInvite($userId, $phone = null, $expiresInDays = 7) {
        try {
            // Генерируем уникальный код
            do {
                $code = $this->generateInviteCode();
                $existing = $this->db->fetchOne(
                    "SELECT id FROM invites WHERE code = :code",
                    ['code' => $code]
                );
            } while ($existing);
            
            $expiresAt = date('Y-m-d H:i:s', strtotime("+$expiresInDays days"));
            
            $inviteId = $this->db->insert(
                "INSERT INTO invites (code, created_by, phone, expires_at, created_at) 
                 VALUES (:code, :user_id, :phone, :expires_at, NOW())
                 RETURNING id",
                [
                    'code' => $code,
                    'user_id' => $userId,
                    'phone' => $phone,
                    'expires_at' => $expiresAt
                ]
            );
            
            return [
                'success' => true,
                'inviteId' => $inviteId,
                'code' => $code,
                'expiresAt' => $expiresAt
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
            // Получаем данные пользователя
            $user = $this->db->fetchOne(
                "SELECT username, full_name FROM users WHERE id = :id",
                ['id' => $userId]
            );
            
            if (!$user) {
                return [
                    'success' => false,
                    'error' => 'Пользователь не найден'
                ];
            }
            
            // Создаем инвайт
            $invite = $this->createInvite($userId, $phone);
            
            if (!$invite['success']) {
                return $invite;
            }
            
            // Отправляем SMS
            $inviterName = $user['full_name'] ?? $user['username'];
            $smsResult = $this->sms->sendInvite($phone, $invite['code'], $inviterName);
            
            if ($smsResult['success']) {
                return [
                    'success' => true,
                    'inviteCode' => $invite['code'],
                    'message' => 'Инвайт отправлен на номер ' . $phone
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
     * Проверить валидность инвайт-кода
     */
    public function validateInvite($code) {
        try {
            $invite = $this->db->fetchOne(
                "SELECT * FROM invites 
                 WHERE code = :code 
                 AND is_used = false 
                 AND (expires_at IS NULL OR expires_at > NOW())",
                ['code' => $code]
            );
            
            if (!$invite) {
                return [
                    'valid' => false,
                    'error' => 'Инвайт недействителен или истек'
                ];
            }
            
            return [
                'valid' => true,
                'invite' => $invite
            ];
            
        } catch (Exception $e) {
            error_log("Validate invite error: " . $e->getMessage());
            return [
                'valid' => false,
                'error' => 'Ошибка проверки инвайта'
            ];
        }
    }
    
    /**
     * Отметить инвайт как использованный
     */
    public function markInviteAsUsed($code, $usedByUserId) {
        try {
            $this->db->execute(
                "UPDATE invites 
                 SET is_used = true, used_by = :used_by, used_at = NOW() 
                 WHERE code = :code",
                [
                    'code' => $code,
                    'used_by' => $usedByUserId
                ]
            );
            
            return ['success' => true];
            
        } catch (Exception $e) {
            error_log("Mark invite as used error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Ошибка обновления инвайта'
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
     * Проверить SMS код верификации
     */
    public function verifyCode($phone, $code) {
        try {
            $verification = $this->db->fetchOne(
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
                // Увеличиваем счетчик попыток
                $this->db->execute(
                    "UPDATE sms_verifications 
                     SET attempts = attempts + 1 
                     WHERE phone = :phone AND is_verified = false",
                    ['phone' => $phone]
                );
                
                return [
                    'success' => false,
                    'error' => 'Неверный или истекший код'
                ];
            }
            
            // Отмечаем как подтвержденный
            $this->db->execute(
                "UPDATE sms_verifications 
                 SET is_verified = true, verified_at = NOW() 
                 WHERE id = :id",
                ['id' => $verification['id']]
            );
            
            return [
                'success' => true,
                'inviteCode' => $verification['invite_code']
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
     * Получить список инвайтов пользователя
     */
    public function getUserInvites($userId) {
        try {
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