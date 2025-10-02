<?php
// backend/lib/SimpleInviteManager.php
// Упрощенный менеджер инвайтов - просто ссылки, без SMS

require_once __DIR__ . '/Database.php';

class SimpleInviteManager {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Генерировать уникальный код инвайта (12 символов)
     */
    private function generateCode() {
        // Используем только легко читаемые символы (без 0, O, I, 1, l и т.д.)
        $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        
        // Генерируем код из 12 символов
        for ($i = 0; $i < 12; $i++) {
            $code .= $characters[random_int(0, strlen($characters) - 1)];
        }
        
        // Проверяем уникальность в БД
        $exists = $this->db->fetchOne(
            "SELECT id FROM invites WHERE code = :code",
            ['code' => $code]
        );
        
        // Если код уже существует (крайне маловероятно), генерируем новый рекурсивно
        if ($exists) {
            return $this->generateCode();
        }
        
        return $code;
    }
    
    /**
     * Создать новый инвайт
     * 
     * @param int $userId - ID пользователя, создающего инвайт
     * @return array - результат с кодом и URL
     */
    public function createInvite($userId) {
        try {
            // Генерируем уникальный код
            $code = $this->generateCode();
            
            // Формируем URL (берем из .env или используем дефолтный)
            $baseUrl = $_ENV['APP_URL'] ?? 'https://securewave.sbk-19.ru';
            $inviteUrl = $baseUrl . '/invite/' . $code;
            
            // Сохраняем в БД
            $this->db->insert(
                "INSERT INTO invites (code, created_by, created_at) 
                 VALUES (:code, :created_by, NOW())",
                [
                    'code' => $code,
                    'created_by' => $userId
                ]
            );
            
            error_log("Created invite: $code for user: $userId");
            
            return [
                'success' => true,
                'code' => $code,
                'url' => $inviteUrl
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
     * Проверить валидность инвайта
     * 
     * @param string $code - код инвайта
     * @return array - valid: true/false + error если невалидный
     */
    public function checkInvite($code) {
        try {
            // Ищем инвайт в БД
            $invite = $this->db->fetchOne(
                "SELECT * FROM invites WHERE code = :code",
                ['code' => $code]
            );
            
            // Инвайт не найден
            if (!$invite) {
                return [
                    'valid' => false,
                    'error' => 'Код приглашения не найден'
                ];
            }
            
            // Инвайт уже использован
            if ($invite['is_used']) {
                return [
                    'valid' => false,
                    'error' => 'Код приглашения уже использован'
                ];
            }
            
            // Все ок, инвайт валидный
            return [
                'valid' => true,
                'code' => $code
            ];
            
        } catch (Exception $e) {
            error_log("Check invite error: " . $e->getMessage());
            return [
                'valid' => false,
                'error' => 'Ошибка проверки кода приглашения'
            ];
        }
    }
    
    /**
     * Использовать инвайт (при успешной регистрации)
     * 
     * @param string $code - код инвайта
     * @param int $userId - ID зарегистрировавшегося пользователя
     * @return array - success: true/false
     */
    public function useInvite($code, $userId) {
        try {
            // Сначала проверяем валидность
            $check = $this->checkInvite($code);
            if (!$check['valid']) {
                return $check;
            }
            
            // Помечаем инвайт как использованный
            $this->db->execute(
                "UPDATE invites 
                 SET is_used = true, 
                     used_by = :user_id, 
                     used_at = NOW() 
                 WHERE code = :code",
                [
                    'code' => $code,
                    'user_id' => $userId
                ]
            );
            
            error_log("Invite $code used by user $userId");
            
            return [
                'success' => true
            ];
            
        } catch (Exception $e) {
            error_log("Use invite error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Ошибка использования инвайта'
            ];
        }
    }
    
    /**
     * Получить список инвайтов пользователя
     * 
     * @param int $userId - ID пользователя
     * @return array - список инвайтов с полными URL
     */
    public function getUserInvites($userId) {
        try {
            $baseUrl = $_ENV['APP_URL'] ?? 'https://securewave.sbk-19.ru';
            
            // Получаем все инвайты пользователя
            $invites = $this->db->fetchAll(
                "SELECT 
                    i.id,
                    i.code,
                    i.is_used,
                    i.created_at,
                    i.used_at,
                    u.username as used_by_username
                 FROM invites i
                 LEFT JOIN users u ON u.id = i.used_by
                 WHERE i.created_by = :user_id
                 ORDER BY i.created_at DESC",
                ['user_id' => $userId]
            );
            
            // Добавляем полный URL к каждому инвайту
            foreach ($invites as &$invite) {
                $invite['url'] = $baseUrl . '/invite/' . $invite['code'];
                
                // Преобразуем is_used в boolean для удобства фронтенда
                $invite['is_used'] = (bool)$invite['is_used'];
            }
            
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
     * Удалить инвайт (только если не использован)
     * 
     * @param string $code - код инвайта
     * @param int $userId - ID пользователя, пытающегося удалить
     * @return array - success: true/false
     */
    public function deleteInvite($code, $userId) {
        try {
            // Проверяем, что инвайт существует и принадлежит пользователю
            $invite = $this->db->fetchOne(
                "SELECT * FROM invites 
                 WHERE code = :code AND created_by = :user_id",
                [
                    'code' => $code,
                    'user_id' => $userId
                ]
            );
            
            // Инвайт не найден или не принадлежит пользователю
            if (!$invite) {
                return [
                    'success' => false,
                    'error' => 'Код приглашения не найден'
                ];
            }
            
            // Нельзя удалить использованный инвайт
            if ($invite['is_used']) {
                return [
                    'success' => false,
                    'error' => 'Нельзя удалить использованный код приглашения'
                ];
            }
            
            // Удаляем инвайт
            $this->db->execute(
                "DELETE FROM invites WHERE code = :code",
                ['code' => $code]
            );
            
            error_log("Deleted invite: $code by user: $userId");
            
            return [
                'success' => true,
                'message' => 'Код приглашения удален'
            ];
            
        } catch (Exception $e) {
            error_log("Delete invite error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Ошибка удаления кода приглашения'
            ];
        }
    }
}