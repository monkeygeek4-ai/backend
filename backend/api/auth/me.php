<?php
// backend/api/auth/me.php

require_once dirname(__DIR__, 2) . '/lib/Auth.php';
require_once dirname(__DIR__, 2) . '/lib/Response.php';

// Создаем экземпляр Auth
$auth = new Auth();

// Проверяем авторизацию
$user = $auth->requireAuth();

// Возвращаем данные пользователя
Response::json([
    'id' => $user['id'],
    'username' => $user['username'],
    'email' => $user['email'],
    'phone' => $user['phone'],
    'fullName' => $user['bio'] ?? $user['username'],
    'avatar' => $user['avatar_url'],
    'bio' => $user['bio'],
    'isVerified' => (bool)$user['is_verified'],
    'isOnline' => (bool)$user['is_online'],
    'lastSeen' => $user['last_seen'],
    'createdAt' => $user['created_at']
]);