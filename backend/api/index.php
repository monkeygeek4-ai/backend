<?php
// backend/api/index.php
// Главный роутер для всех API запросов

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

// Логируем для отладки
error_log("API Request: $path | Method: {$_SERVER['REQUEST_METHOD']}");

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
    
    case $path === 'auth/send-code':
        require __DIR__ . '/auth/send-code.php';
        break;
        
    case $path === 'auth/verify-code':
        require __DIR__ . '/auth/verify-code.php';
        break;
    
    // POST /auth/register-by-invite - регистрация по инвайту (НОВЫЙ, публичный)
    case $path === 'auth/register-by-invite':
        require __DIR__ . '/auth/register-by-invite.php';
        break;
    
    // ============ INVITE ROUTES ============
    // POST /invites/create - создать новый инвайт
    case $path === 'invites/create':
        require __DIR__ . '/invites/create.php';
        break;
        
    // GET /invites - получить список инвайтов пользователя
    case $path === 'invites':
    case $path === 'invites/':
        require __DIR__ . '/invites/index.php';
        break;
    
    // GET /invites/check?code=XXX - проверить инвайт (НОВЫЙ, публичный)
    case $path === 'invites/check':
        require __DIR__ . '/invites/check.php';
        break;
    
    // DELETE /invites/{code} - удалить инвайт
    case preg_match('#^invites/([A-Z0-9]+)$#', $path, $matches):
        $_GET['code'] = $matches[1];
        require __DIR__ . '/invites/delete.php';
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
        
    case preg_match('#^chats/([^/]+)/delete$#', $path, $matches):
        $_GET['chatId'] = $matches[1];
        require __DIR__ . '/chats/delete.php';
        break;
    
    // ============ MESSAGE ROUTES ============
    // GET /messages?chatId=xxx - получить все сообщения чата
    case $path === 'messages':
    case $path === 'messages/':
        require __DIR__ . '/messages/index.php';
        break;
    
    // GET /messages/chat/{chatId} - альтернативный способ получения сообщений
    case preg_match('#^messages/chat/([^/]+)$#', $path, $matches):
        $_GET['chatId'] = $matches[1];
        require __DIR__ . '/messages/chat.php';
        break;
        
    // POST /messages/send - отправить сообщение
    case $path === 'messages/send':
        require __DIR__ . '/messages/send.php';
        break;
    
    // POST /messages/mark-read - отметить сообщения как прочитанные
    case $path === 'messages/mark-read':
        require __DIR__ . '/messages/mark-read.php';
        break;
        
    // POST /messages/read - альтернативный эндпоинт для отметки прочтения
    case $path === 'messages/read':
        require __DIR__ . '/messages/read.php';
        break;
    
    // ============ 404 ============
    default:
        http_response_code(404);
        error_log("404 Not Found: $path");
        echo json_encode([
            'success' => false,
            'error' => 'Endpoint not found',
            'path' => $path,
            'method' => $_SERVER['REQUEST_METHOD']
        ]);
        break;
}