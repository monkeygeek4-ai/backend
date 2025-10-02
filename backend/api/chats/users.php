<?php

require_once dirname(__DIR__, 2) . '/lib/Auth.php';
require_once dirname(__DIR__, 2) . '/lib/Database.php';
require_once dirname(__DIR__, 2) . '/lib/Response.php';

$auth = new Auth();
$user = $auth->requireAuth();

$db = Database::getInstance();

try {
    // Получаем всех пользователей кроме текущего
    $users = $db->fetchAll("
        SELECT 
            id,
            username,
            email,
            avatar_url,
            is_online,
            last_seen
        FROM users
        WHERE id != :current_user_id
        ORDER BY username
    ", ['current_user_id' => $user['id']]);
    
    Response::json($users);
    
} catch (Exception $e) {
    error_log("Get users error: " . $e->getMessage());
    Response::error('Ошибка получения пользователей', 500);
} 