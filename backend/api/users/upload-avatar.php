<?php
// backend/api/users/upload-avatar.php

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
$avatarData = $data['avatar'] ?? null;
$filename = $data['filename'] ?? 'avatar.jpg';

if (!$avatarData) {
    Response::error('Файл не передан', 400);
}

$db = Database::getInstance();

try {
    // Создаем директорию для аватаров, если её нет
    $uploadDir = dirname(__DIR__, 2) . '/uploads/avatars/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    // Извлекаем данные изображения из data URL
    if (preg_match('/^data:image\/(\w+);base64,/', $avatarData, $type)) {
        $avatarData = substr($avatarData, strpos($avatarData, ',') + 1);
        $type = strtolower($type[1]); // jpg, png, gif
        
        if (!in_array($type, ['jpg', 'jpeg', 'png', 'gif'])) {
            Response::error('Неподдерживаемый формат изображения', 400);
        }
        
        $avatarData = base64_decode($avatarData);
        
        if ($avatarData === false) {
            Response::error('Ошибка декодирования изображения', 400);
        }
    } else {
        Response::error('Неверный формат данных', 400);
    }
    
    // Генерируем уникальное имя файла
    $newFilename = 'avatar_' . $user['id'] . '_' . time() . '.' . $type;
    $filepath = $uploadDir . $newFilename;
    
    // Сохраняем файл
    if (file_put_contents($filepath, $avatarData) === false) {
        Response::error('Ошибка сохранения файла', 500);
    }
    
    // Формируем URL для аватара
    $avatarUrl = '/backend/uploads/avatars/' . $newFilename;
    
    // Удаляем старый аватар, если он был
    if (!empty($user['avatar_url'])) {
        $oldFile = dirname(__DIR__, 2) . $user['avatar_url'];
        if (file_exists($oldFile) && strpos($user['avatar_url'], '/uploads/avatars/') !== false) {
            @unlink($oldFile);
        }
    }
    
    // Обновляем URL аватара в базе данных
    $db->execute(
        "UPDATE users SET avatar_url = :avatar_url WHERE id = :id",
        [
            'avatar_url' => $avatarUrl,
            'id' => $user['id']
        ]
    );
    
    Response::json([
        'success' => true,
        'avatarUrl' => $avatarUrl,
        'message' => 'Аватар успешно загружен'
    ]);
    
} catch (Exception $e) {
    error_log("Upload avatar error: " . $e->getMessage());
    Response::error('Ошибка загрузки аватара: ' . $e->getMessage(), 500);
}