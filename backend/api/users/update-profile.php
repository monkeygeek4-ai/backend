<?php
// backend/api/users/update-profile.php

require_once dirname(__DIR__, 2) . '/lib/Auth.php';
require_once dirname(__DIR__, 2) . '/lib/Database.php';
require_once dirname(__DIR__, 2) . '/lib/Response.php';

// Устанавливаем заголовки
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Обработка preflight запроса
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$auth = new Auth();
$user = $auth->requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Метод не разрешен', 405);
}

$data = json_decode(file_get_contents('php://input'), true);

$username = $data['username'] ?? null;
$fullName = $data['fullName'] ?? null;
$phone = $data['phone'] ?? null;
$bio = $data['bio'] ?? null;
$nickname = $data['nickname'] ?? null;

$db = Database::getInstance();

try {
    // Проверяем, не занят ли username другим пользователем
    if ($username && $username !== $user['username']) {
        $existing = $db->fetchOne(
            "SELECT id FROM users WHERE username = :username AND id != :user_id",
            ['username' => $username, 'user_id' => $user['id']]
        );
        
        if ($existing) {
            Response::json([
                'success' => false,
                'error' => 'Имя пользователя уже занято'
            ]);
            exit;
        }
    }
    
    // Проверяем, не занят ли nickname другим пользователем
    if ($nickname) {
        $existing = $db->fetchOne(
            "SELECT id FROM users WHERE nickname = :nickname AND id != :user_id",
            ['nickname' => $nickname, 'user_id' => $user['id']]
        );
        
        if ($existing) {
            Response::json([
                'success' => false,
                'error' => 'Никнейм уже занят'
            ]);
            exit;
        }
    }
    
    // Проверяем, не занят ли телефон другим пользователем
    if ($phone) {
        $existing = $db->fetchOne(
            "SELECT id FROM users WHERE phone = :phone AND id != :user_id",
            ['phone' => $phone, 'user_id' => $user['id']]
        );
        
        if ($existing) {
            Response::json([
                'success' => false,
                'error' => 'Номер телефона уже занят'
            ]);
            exit;
        }
    }
    
    // Формируем SQL для обновления
    $updateFields = [];
    $params = ['id' => $user['id']];
    
    if ($username !== null) {
        $updateFields[] = "username = :username";
        $params['username'] = $username;
    }
    
    if ($fullName !== null) {
        $updateFields[] = "full_name = :full_name";
        $params['full_name'] = $fullName;
    }
    
    if ($phone !== null) {
        $updateFields[] = "phone = :phone";
        $params['phone'] = $phone;
    }
    
    if ($bio !== null) {
        $updateFields[] = "bio = :bio";
        $params['bio'] = $bio;
    }
    
    if ($nickname !== null) {
        $updateFields[] = "nickname = :nickname";
        $params['nickname'] = $nickname;
    }
    
    // Обновляем профиль, если есть что обновлять
    if (!empty($updateFields)) {
        $sql = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = :id";
        $db->execute($sql, $params);
    }
    
    // Получаем обновленные данные пользователя
    $updatedUser = $db->fetchOne(
        "SELECT id, username, email, full_name, phone, phone_verified, bio, avatar_url, nickname, is_online, last_seen, created_at
         FROM users
         WHERE id = :id",
        ['id' => $user['id']]
    );
    
    Response::json([
        'success' => true,
        'message' => 'Профиль обновлен',
        'user' => $updatedUser
    ]);
    
} catch (Exception $e) {
    error_log("Update profile error: " . $e->getMessage());
    Response::error('Ошибка обновления профиля: ' . $e->getMessage(), 500);
}