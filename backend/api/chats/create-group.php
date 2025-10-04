<?php
// backend/api/chats/create-group.php

require_once dirname(__DIR__, 2) . '/lib/Auth.php';
require_once dirname(__DIR__, 2) . '/lib/Database.php';
require_once dirname(__DIR__, 2) . '/lib/Response.php';

error_log("=== CREATE GROUP CHAT REQUEST START ===");

$auth = new Auth();
$user = $auth->requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Метод не разрешен', 405);
}

$data = json_decode(file_get_contents('php://input'), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    Response::error('Неверный формат JSON', 400);
}

$groupName = $data['name'] ?? null;
$participants = $data['participants'] ?? [];

error_log("Group name: " . ($groupName ?? 'NULL'));
error_log("Participants: " . print_r($participants, true));

if (!$groupName || empty($groupName)) {
    Response::error('Название группы обязательно', 400);
}

if (!is_array($participants) || count($participants) < 2) {
    Response::error('Требуется минимум 2 участника', 400);
}

$db = Database::getInstance();

try {
    $db->beginTransaction();
    
    // Генерируем UUID для чата
    $chatUuid = 'chat_' . time() . '_' . bin2hex(random_bytes(8));
    
    error_log("Creating group chat with UUID: $chatUuid");
    
    // Создаем групповой чат
    $chatId = $db->insert('chats', [
        'chat_uuid' => $chatUuid,
        'type' => 'group',
        'name' => $groupName,
        'created_by' => $user['id'],
        'created_at' => date('Y-m-d H:i:s')
    ]);
    
    if (!$chatId) {
        throw new Exception('Не удалось создать чат');
    }
    
    error_log("Chat created with ID: $chatId");
    
    // Добавляем создателя в участники
    $allParticipants = array_merge([$user['id']], $participants);
    $allParticipants = array_unique($allParticipants);
    
    error_log("Adding participants: " . print_r($allParticipants, true));
    
    foreach ($allParticipants as $participantId) {
        $result = $db->insert('chat_participants', [
            'chat_id' => $chatId,
            'user_id' => $participantId,
            'joined_at' => date('Y-m-d H:i:s')
        ]);
        
        if (!$result) {
            throw new Exception("Не удалось добавить участника $participantId");
        }
    }
    
    $db->commit();
    
    error_log("Group chat created successfully");
    
    // Возвращаем информацию о созданном чате
    Response::json([
        'success' => true,
        'id' => $chatUuid,
        'name' => $groupName,
        'type' => 'group',
        'participants' => $allParticipants,
        'createdAt' => date('c'),
        'avatarUrl' => null,
        'lastMessage' => null,
        'lastMessageTime' => null,
        'unreadCount' => 0,
        'isOnline' => false,
        'isPinned' => false
    ]);
    
} catch (Exception $e) {
    $db->rollback();
    error_log("Create group error: " . $e->getMessage());
    Response::error('Ошибка создания группы: ' . $e->getMessage(), 500);
}