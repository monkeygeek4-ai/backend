<?php
// backend/lib/Auth.php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/JWT.php';
require_once __DIR__ . '/Response.php';

class Auth {
    private $db;
    private $jwt;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->jwt = new JWT();
    }
    
    /**
     * Требовать авторизацию - для использования в API endpoints
     */
    public function requireAuth() {
        // Получаем токен из заголовка
        $headers = apache_request_headers();
        $authHeader = null;
        
        // Ищем Authorization заголовок (case-insensitive)
        foreach ($headers as $key => $value) {
            if (strtolower($key) === 'authorization') {
                $authHeader = $value;
                break;
            }
        }
        
        if (!$authHeader) {
            Response::error('Требуется авторизация', 401);
        }
        
        // Извлекаем токен
        $token = str_replace('Bearer ', '', $authHeader);
        
        // Получаем пользователя
        $user = $this->getUserByToken($token);
        
        if (!$user) {
            Response::error('Недействительный токен', 401);
        }
        
        return $user;
    }
    
    /**
     * Получить пользователя по токену
     */
    public function getUserByToken($token) {
        // Убираем Bearer и кавычки если есть
        $token = str_replace(['Bearer ', '"', "'"], '', trim($token));
        
        if (empty($token)) {
            return null;
        }
        
        // Проверяем токен
        $payload = $this->jwt->verifyToken($token);
        
        if (!$payload) {
            error_log("Invalid token: " . substr($token, 0, 20) . "...");
            return null;
        }
        
        // Получаем пользователя из БД
        try {
            $user = $this->db->fetchOne(
                "SELECT id, username, email, full_name, avatar_url, is_online, last_seen, phone, bio, created_at, is_verified 
                 FROM users 
                 WHERE id = :id",
                ['id' => $payload['userId']]
            );
            
            if ($user) {
                error_log("User found: " . $user['username'] . " (ID: " . $user['id'] . ")");
            } else {
                error_log("User not found for ID: " . $payload['userId']);
            }
            
            return $user;
            
        } catch (Exception $e) {
            error_log("Error getting user by token: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Авторизация пользователя (login)
     */
    public function login($username, $password) {
        try {
            // Получаем пользователя по username
            $user = $this->db->fetchOne(
                "SELECT id, username, email, password_hash, full_name, avatar_url 
                 FROM users 
                 WHERE username = :username OR email = :username",
                ['username' => $username]
            );
            
            if (!$user) {
                return ['success' => false, 'error' => 'Пользователь не найден'];
            }
            
            // Проверяем пароль
            if (!password_verify($password, $user['password_hash'])) {
                return ['success' => false, 'error' => 'Неверный пароль'];
            }
            
            // Генерируем токен
            $token = $this->jwt->generateToken($user['id'], $user['username']);
            
            // Обновляем статус онлайн
            $this->db->execute(
                "UPDATE users SET is_online = true, last_seen = NOW() WHERE id = :id",
                ['id' => $user['id']]
            );
            
            // Убираем пароль из ответа
            unset($user['password_hash']);
            
            return [
                'success' => true,
                'token' => $token,
                'user' => $user
            ];
            
        } catch (Exception $e) {
            error_log("Login error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Ошибка авторизации'];
        }
    }
    
    /**
     * Аутентификация пользователя (старый метод для совместимости)
     */
    public function authenticate($username, $password) {
        return $this->login($username, $password);
    }
    
    /**
     * Регистрация нового пользователя
     */
    public function register($username, $email, $password, $fullName) {
        try {
            // Проверяем, не занят ли username или email
            $existing = $this->db->fetchOne(
                "SELECT id FROM users WHERE username = :username OR email = :email",
                ['username' => $username, 'email' => $email]
            );
            
            if ($existing) {
                return ['success' => false, 'error' => 'Пользователь с таким именем или email уже существует'];
            }
            
            // Хешируем пароль
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            
            // Создаем пользователя
            $userId = $this->db->insert(
                "INSERT INTO users (username, email, password_hash, full_name, created_at) 
                 VALUES (:username, :email, :password_hash, :full_name, NOW())
                 RETURNING id",
                [
                    'username' => $username,
                    'email' => $email,
                    'password_hash' => $passwordHash,
                    'full_name' => $fullName
                ]
            );
            
            if (!$userId) {
                return ['success' => false, 'error' => 'Не удалось создать пользователя'];
            }
            
            // Генерируем токен
            $token = $this->jwt->generateToken($userId, $username);
            
            return [
                'success' => true,
                'token' => $token,
                'user' => [
                    'id' => $userId,
                    'username' => $username,
                    'email' => $email,
                    'full_name' => $fullName
                ]
            ];
            
        } catch (Exception $e) {
            error_log("Registration error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Ошибка регистрации: ' . $e->getMessage()];
        }
    }
    
    /**
     * Проверить токен и получить данные пользователя
     */
    public function validateToken($token) {
        $user = $this->getUserByToken($token);
        
        if ($user) {
            // Обновляем время последней активности
            $this->db->execute(
                "UPDATE users SET last_seen = NOW() WHERE id = :id",
                ['id' => $user['id']]
            );
            
            return [
                'valid' => true,
                'user' => $user
            ];
        }
        
        return [
            'valid' => false,
            'error' => 'Invalid token'
        ];
    }
    
    /**
     * Выход пользователя
     */
    public function logout($userId) {
        try {
            // Обновляем статус офлайн
            $this->db->execute(
                "UPDATE users SET is_online = false, last_seen = NOW() WHERE id = :id",
                ['id' => $userId]
            );
            
            return true;
        } catch (Exception $e) {
            error_log("Logout error: " . $e->getMessage());
            return false;
        }
    }
}