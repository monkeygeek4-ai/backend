<?php
// backend/api/auth/register-by-invite.php
// Публичный эндпоинт для регистрации по инвайт-ссылке
// НЕ требует авторизации

require_once dirname(__DIR__, 2) . '/lib/Auth.php';
require_once dirname(__DIR__, 2) . '/lib/SimpleInviteManager.php';
require_once dirname(__DIR__, 2) . '/lib/Response.php';

// Устанавливаем заголовки
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Обработка preflight запроса
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Только POST запросы
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Метод не разрешен', 405);
}

// Получаем данные из запроса
$data = json_decode(file_get_contents('php://input'), true);

$inviteCode = $data['inviteCode'] ?? null;
$username = $data['username'] ?? null;
$password = $data['password'] ?? null;
$email = $data['email'] ?? null;
$fullName = $data['fullName'] ?? $username;

// Валидация обязательных полей
if (!$inviteCode) {
    Response::error('Код приглашения обязателен', 400);
}

if (!$username) {
    Response::error('Имя пользователя обязательно', 400);
}

if (!$password) {
    Response::error('Пароль обязателен', 400);
}

if (!$email) {
    Response::error('Email обязателен', 400);
}

// Валидация формата email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    Response::error('Неверный формат email', 400);
}

// Валидация длины пароля
if (strlen($password) < 6) {
    Response::error('Пароль должен быть не менее 6 символов', 400);
}

// Валидация username
if (strlen($username) < 3) {
    Response::error('Имя пользователя должно быть не менее 3 символов', 400);
}

$inviteManager = new SimpleInviteManager();
$auth = new Auth();

try {
    // ШАГ 1: Проверяем валидность инвайта
    error_log("Checking invite: $inviteCode");
    $inviteCheck = $inviteManager->checkInvite($inviteCode);
    
    if (!$inviteCheck['valid']) {
        error_log("Invalid invite: $inviteCode - " . ($inviteCheck['error'] ?? 'unknown error'));
        Response::json([
            'success' => false,
            'error' => $inviteCheck['error'] ?? 'Неверный код приглашения'
        ]);
        exit;
    }
    
    // ШАГ 2: Проверяем, не использован ли уже этот инвайт
    if ($inviteCheck['invite']['used']) {
        error_log("Invite already used: $inviteCode");
        Response::json([
            'success' => false,
            'error' => 'Этот код приглашения уже использован'
        ]);
        exit;
    }
    
    error_log("Invite $inviteCode is valid and not used");
    
    // ШАГ 3: Регистрируем пользователя
    error_log("Registering user: $username ($email)");
    $registerResult = $auth->register($username, $email, $password, $fullName);
    
    if (!$registerResult['success']) {
        error_log("Registration failed: " . ($registerResult['error'] ?? 'unknown error'));
        Response::json($registerResult);
        exit;
    }
    
    $userId = $registerResult['user']['id'];
    error_log("User registered successfully: $userId");
    
    // ШАГ 4: Помечаем инвайт как использованный
    error_log("Marking invite $inviteCode as used by user $userId");
    $useResult = $inviteManager->useInvite($inviteCode, $userId);
    
    if (!$useResult['success']) {
        error_log("Warning: User $userId registered but invite $inviteCode not marked as used");
        // Не прерываем выполнение - пользователь уже создан
    } else {
        error_log("Invite $inviteCode successfully marked as used");
    }
    
    // ШАГ 5: Возвращаем успешный результат
    Response::json([
        'success' => true,
        'token' => $registerResult['token'],
        'user' => $registerResult['user'],
        'message' => 'Регистрация успешна'
    ]);
    
} catch (Exception $e) {
    error_log("Register by invite error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    Response::error('Ошибка регистрации. Попробуйте позже.', 500);
}
