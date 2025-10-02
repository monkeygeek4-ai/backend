<?php
// backend/api/index.php

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Загружаем .env
require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Получаем путь запроса
$requestUri = $_SERVER['REQUEST_URI'];
$scriptName = dirname($_SERVER['SCRIPT_NAME']);
$path = str_replace($scriptName, '', $requestUri);
$path = parse_url($path, PHP_URL_PATH);
$path = trim($path, '/');

// Убираем префикс 'backend/api' если есть
$path = preg_replace('#^backend/api/#', '', $path);

// Роутинг
switch (true) {
    // ============ AUTH ROUTES ============
    case $path === 'auth/login':
        require __DIR__ . '/auth/login.php';
        break;
        
    case $path === 'auth/register':
        require __DIR__ . '/auth/register.php';
        break;
        
    case $path === 'auth/me':
        require __DIR__ . '/auth/me.php';
        break;
    
    // НОВЫЕ ENDPOINTS ДЛЯ SMS РЕГИСТРАЦИИ
    case $path === 'auth/send-code':
        require __DIR__ . '/auth/send-code.php';
        break;
        
    case $path === 'auth/verify-code':
        require __DIR__ . '/auth/verify-code.php';
        break;
    
    // ============ INVITE ROUTES ============
    case $path === 'invites/create':
        require __DIR__ . '/invites/create.php';
        break;
        
    case $path === 'invites':
    case $path === 'invites/':
        require __DIR__ . '/invites/index.php';
        break;
    
    // ============ USER ROUTES ============
    case $path === 'users/profile':
        require __DIR__ . '/users/profile.php';
        break;
    
    case $path === 'users/update-profile':
        require __DIR__ . '/users/update-profile.php';
        break;
    
    case $path === 'users/upload-avatar':
        require __DIR__ . '/users/upload-avatar.php';
        break;
        
    case preg_match('#^users/(\d+)$#', $path, $matches):
        $_GET['id'] = $matches[1];
        require __DIR__ . '/users/show.php';
        break;
    
    // ============ CHAT ROUTES ============
    case $path === 'chats':
    case $path === 'chats/':
        require __DIR__ . '/chats/index.php';
        break;
        
    case $path === 'chats/create':
        require __DIR__ . '/chats/create.php';
        break;
        
    case $path === 'chats/users':
        require __DIR__ . '/chats/users.php';
        break;
        
    case preg_match('#^chats/([^/]+)$#', $path, $matches):
        $_GET['chatId'] = $matches[1];
        require __DIR__ . '/chats/show.php';
        break;
    
    // ============ MESSAGE ROUTES ============
    case $path === 'messages':
    case preg_match('#^messages\?chatId=(.+)#', $path):
        require __DIR__ . '/messages/index.php';
        break;
        
    case $path === 'messages/send':
        require __DIR__ . '/messages/send.php';
        break;
        
    case $path === 'messages/mark-read':
        require __DIR__ . '/messages/mark-read.php';
        break;
        
    case preg_match('#^messages/chat/([^/]+)$#', $path, $matches):
        $_GET['chatId'] = $matches[1];
        require __DIR__ . '/messages/chat.php';
        break;
    
    // ============ CALL ROUTES ============
    case $path === 'calls/initiate':
        require __DIR__ . '/calls/initiate.php';
        break;
        
    case $path === 'calls/answer':
        require __DIR__ . '/calls/answer.php';
        break;
        
    case $path === 'calls/end':
        require __DIR__ . '/calls/end.php';
        break;
    
    // ============ DEFAULT ============
    default:
        http_response_code(404);
        echo json_encode([
            'error' => 'Endpoint not found',
            'path' => $path,
            'available_endpoints' => [
                'auth' => [
                    'POST /auth/login',
                    'POST /auth/register',
                    'GET /auth/me',
                    'POST /auth/send-code',
                    'POST /auth/verify-code',
                ],
                'invites' => [
                    'POST /invites/create',
                    'GET /invites',
                ],
                'chats' => [
                    'GET /chats',
                    'POST /chats/create',
                    'GET /chats/users',
                    'GET /chats/{chatId}',
                ],
                'messages' => [
                    'GET /messages?chatId={id}',
                    'POST /messages/send',
                    'POST /messages/mark-read',
                ],
                'calls' => [
                    'POST /calls/initiate',
                    'POST /calls/answer',
                    'POST /calls/end',
                ],
            ]
        ]);
        break;
}