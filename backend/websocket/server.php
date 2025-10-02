<?php
// backend/websocket/server.php

require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/lib/Database.php';
require_once dirname(__DIR__) . '/lib/Auth.php';
require_once __DIR__ . '/call_handlers.php';

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class ChatWebSocket implements MessageComponentInterface {
    protected $clients;
    protected $userConnections;
    protected $auth;
    protected $db;
    protected $authorizedConnections;

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->userConnections = [];
        $this->authorizedConnections = [];
        $this->auth = new Auth();
        $this->db = Database::getInstance();
        
        echo "WebSocket сервер запущен\n";
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        echo "=====================================\n";
        echo "Новое подключение: {$conn->resourceId}\n";
        echo "Всего подключений: " . count($this->clients) . "\n";
        
        // ВАЖНО: Инициализируем userData как stdClass объект
        $conn->userData = new \stdClass();
        $conn->userData->isAuthorized = false;
        $conn->userData->userId = null;
        $conn->userData->username = null;
        $conn->userData->currentChatId = null;
        
        echo "userData инициализирована для соединения {$conn->resourceId}\n";
        echo "Ожидаем авторизацию от клиента...\n";
        echo "=====================================\n";
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        echo "Сообщение от {$from->resourceId}: $msg\n";
        
        try {
            $data = json_decode($msg, true);
            
            if (!$data) {
                echo "Ошибка парсинга JSON\n";
                return;
            }
            
            echo "Тип сообщения: {$data['type']}\n";
            
            // Проверяем авторизацию для всех типов кроме auth и ping
            if ($data['type'] !== 'auth' && $data['type'] !== 'ping') {
                if (!isset($from->userData) || !$from->userData->isAuthorized) {
                    echo "Соединение {$from->resourceId} не авторизовано\n";
                    
                    // Для звонков отправляем специальное сообщение
                    if (in_array($data['type'], ['call_offer', 'call_answer', 'call_ice_candidate', 'call_end', 'call_decline'])) {
                        // Для call_end разрешаем без авторизации (очистка)
                        if ($data['type'] === 'call_end') {
                            echo "Разрешаем call_end для очистки ресурсов\n";
                            // Создаем минимальный объект для обработки
                            $from->userId = null;
                            handleCallMessage($data['type'], $data, $from, $this->clients, $this->db);
                            return;
                        }
                        
                        $from->send(json_encode([
                            'type' => 'call_error',
                            'error' => 'unauthorized',
                            'message' => 'Требуется авторизация для совершения звонков'
                        ]));
                    } else {
                        $from->send(json_encode([
                            'type' => 'error',
                            'message' => 'Требуется авторизация. Отправьте сообщение типа "auth" с токеном.'
                        ]));
                    }
                    return;
                }
            }
            
            switch ($data['type']) {
                case 'auth':
                    $this->handleAuth($from, $data['token'] ?? null);
                    break;
                    
                case 'ping':
                    $from->send(json_encode(['type' => 'pong']));
                    break;
                    
                case 'typing':
                    $this->handleTyping($from, $data);
                    break;
                    
                case 'stopped_typing':
                    $this->handleStoppedTyping($from, $data);
                    break;
                    
                case 'send_message':
                    echo "Вызываем handleMessage для send_message\n";
                    $this->handleMessage($from, $data);
                    break;
                    
                case 'message':
                    echo "Вызываем handleMessage для message\n";
                    $this->handleMessage($from, $data);
                    break;
                    
                case 'join_chat':
                    $this->handleJoinChat($from, $data);
                    break;
                    
                case 'leave_chat':
                    $this->handleLeaveChat($from, $data);
                    break;
                    
                case 'mark_read':
                    $this->handleMarkRead($from, $data);
                    break;
                    
                case 'call_offer':
                case 'call_answer':
                case 'call_ice_candidate':
                case 'call_end':
                case 'call_decline':
                    // Добавляем userId из userData для совместимости с обработчиками
                    $from->userId = $from->userData->userId;
                    
                    // ИСПРАВЛЕНИЕ: передаем $data['type'] вместо $type
                    handleCallMessage($data['type'], $data, $from, $this->clients, $this->db);
                    break;
                
                default:
                    echo "Неизвестный тип сообщения: {$data['type']}\n";
            }
        } catch (Exception $e) {
            echo "Ошибка обработки сообщения: " . $e->getMessage() . "\n";
            echo "Stack trace: " . $e->getTraceAsString() . "\n";
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Ошибка обработки сообщения'
            ]));
        }
    }

    protected function handleAuth($conn, $token) {
        echo "=== АВТОРИЗАЦИЯ ===\n";
        echo "Соединение: {$conn->resourceId}\n";
        
        if (!$token) {
            echo "Токен не предоставлен\n";
            $conn->send(json_encode([
                'type' => 'auth_error',
                'error' => 'Токен не предоставлен'
            ]));
            return;
        }
        
        echo "Получен токен для авторизации\n";
        
        // Убираем Bearer если есть и кавычки
        $token = str_replace(['Bearer ', '"', "'"], '', $token);
        echo "Очищенный токен: " . substr($token, 0, 20) . "...\n";
        
        $user = $this->auth->getUserByToken($token);
        
        if (!$user) {
            echo "Пользователь не найден для токена\n";
            $conn->send(json_encode([
                'type' => 'auth_error',
                'error' => 'Неверный токен'
            ]));
            return;
        }
        
        echo "Найден пользователь: {$user['username']} (ID: {$user['id']})\n";
        
        // Проверяем, не подключен ли уже этот пользователь
        if (isset($this->userConnections[$user['id']])) {
            $oldConn = $this->userConnections[$user['id']];
            echo "Пользователь {$user['username']} уже подключен с соединения {$oldConn->resourceId}, закрываем старое\n";
            
            // Закрываем старое соединение
            $oldConn->close();
        }
        
        // Обновляем userData
        $conn->userData->isAuthorized = true;
        $conn->userData->userId = $user['id'];
        $conn->userData->username = $user['username'];
        $conn->userData->currentChatId = null;
        
        // Добавляем userId напрямую для совместимости
        $conn->userData->userId = $user['id'];
        
        // Сохраняем соединение
        $this->userConnections[$user['id']] = $conn;
        $this->authorizedConnections[$conn->resourceId] = $user['id'];
        
        echo "userData установлена для соединения {$conn->resourceId}: userId={$user['id']}, username={$user['username']}\n";
        echo "Всего активных подключений: " . count($this->userConnections) . "\n";
        
        // Обновляем статус онлайн
        $this->db->execute(
            "UPDATE users SET is_online = true, last_seen = CURRENT_TIMESTAMP WHERE id = :id",
            ['id' => $user['id']]
        );
        
        // Отправляем подтверждение
        $conn->send(json_encode([
            'type' => 'auth_success',
            'userId' => $user['id'],
            'username' => $user['username']
        ]));
        
        echo "Пользователь авторизован: {$user['username']} (ID: {$user['id']})\n";
        echo "=== КОНЕЦ АВТОРИЗАЦИИ ===\n";
        
        // Уведомляем других о статусе онлайн
        $this->broadcastUserStatus($user['id'], true);
    }
    
    protected function handleJoinChat($conn, $data) {
        if (!$conn->userData->isAuthorized) {
            return;
        }
        
        $chatId = $data['chatId'] ?? null;
        if ($chatId) {
            $conn->userData->currentChatId = $chatId;
            echo "Пользователь {$conn->userData->username} присоединился к чату {$chatId}\n";
        }
    }
    
    protected function handleLeaveChat($conn, $data) {
        if (!$conn->userData->isAuthorized) {
            return;
        }
        
        $conn->userData->currentChatId = null;
        echo "Пользователь {$conn->userData->username} покинул чат\n";
    }
    
    protected function handleTyping($conn, $data) {
        if (!$conn->userData->isAuthorized) {
            return;
        }
        
        $chatId = $data['chatId'] ?? null;
        if (!$chatId) return;
        
        // Получаем участников чата
        $participants = $this->getChatParticipants($chatId);
        
        // Отправляем уведомление другим участникам
        foreach ($participants as $participantId) {
            if ($participantId != $conn->userData->userId && isset($this->userConnections[$participantId])) {
                $this->userConnections[$participantId]->send(json_encode([
                    'type' => 'typing',
                    'chatId' => $chatId,
                    'userId' => $conn->userData->userId,
                    'userName' => $conn->userData->username,
                    'isTyping' => true
                ]));
            }
        }
    }
    
    protected function handleStoppedTyping($conn, $data) {
        if (!$conn->userData->isAuthorized) {
            return;
        }
        
        $chatId = $data['chatId'] ?? null;
        if (!$chatId) return;
        
        $participants = $this->getChatParticipants($chatId);
        
        foreach ($participants as $participantId) {
            if ($participantId != $conn->userData->userId && isset($this->userConnections[$participantId])) {
                $this->userConnections[$participantId]->send(json_encode([
                    'type' => 'stopped_typing',
                    'chatId' => $chatId,
                    'userId' => $conn->userData->userId,
                    'isTyping' => false
                ]));
            }
        }
    }
    
    protected function handleMessage($conn, $data) {
        echo "handleMessage вызван для соединения {$conn->resourceId}\n";
        
        // Проверяем авторизацию
        if (!$conn->userData->isAuthorized) {
            echo "Ошибка: соединение {$conn->resourceId} не авторизовано\n";
            $conn->send(json_encode([
                'type' => 'error',
                'message' => 'Требуется авторизация'
            ]));
            return;
        }
        
        echo "Пользователь авторизован: userId={$conn->userData->userId}, username={$conn->userData->username}\n";
        
        $chatId = $data['chatId'] ?? null;
        $content = $data['content'] ?? null;
        $tempId = $data['tempId'] ?? null;
        $messageType = $data['messageType'] ?? 'text';
        
        if (!$chatId || !$content) {
            echo "Ошибка: отсутствует chatId или content\n";
            return;
        }
        
        echo "Обработка сообщения от {$conn->userData->username} в чат {$chatId}: {$content}\n";
        
        // Сохраняем сообщение в БД
        $messageId = $this->saveMessage($chatId, $conn->userData->userId, $content, $messageType);
        
        if (!$messageId) {
            echo "Ошибка сохранения сообщения в БД\n";
            return;
        }
        
        // Получаем ID чата для проверки статусов
        $chat = $this->db->fetchOne(
            "SELECT id FROM chats WHERE chat_uuid = :chat_uuid",
            ['chat_uuid' => $chatId]
        );
        
        // Определяем начальный статус сообщения
        $initialStatus = 'отправлено';
        
        // Проверяем, есть ли другие участники онлайн
        $participants = $this->getChatParticipants($chatId);
        $hasOnlineRecipients = false;
        
        foreach ($participants as $participantId) {
            if ($participantId != $conn->userData->userId && isset($this->userConnections[$participantId])) {
                $hasOnlineRecipients = true;
                
                // Проверяем, находится ли получатель в этом чате
                if ($this->userConnections[$participantId]->userData->currentChatId == $chatId) {
                    $initialStatus = 'прочитано';
                    
                    // Обновляем last_read_at для получателя
                    $this->db->execute(
                        "UPDATE chat_participants 
                         SET last_read_at = NOW() 
                         WHERE chat_id = :chat_id AND user_id = :user_id",
                        ['chat_id' => $chat['id'], 'user_id' => $participantId]
                    );
                } else {
                    $initialStatus = 'доставлено';
                }
                break;
            }
        }
        
        // Создаем объект сообщения
        $message = [
            'id' => (string)$messageId,
            'chatId' => $chatId,
            'senderId' => (string)$conn->userData->userId,
            'senderName' => $conn->userData->username,
            'content' => $content,
            'timestamp' => date('c'),
            'type' => $messageType,
            'status' => $initialStatus
        ];
        
        echo "Участники чата: " . implode(', ', $participants) . "\n";
        echo "Начальный статус сообщения: $initialStatus\n";
        
        // ВАЖНО: Отправляем сообщение ВСЕМ участникам, включая отправителя
        $sentCount = 0;
        foreach ($participants as $participantId) {
            if (isset($this->userConnections[$participantId])) {
                $messageType = ($participantId == $conn->userData->userId) ? 'message_sent' : 'message';
                
                $payload = [
                    'type' => $messageType,
                    'message' => $message
                ];
                
                // Добавляем tempId для отправителя
                if ($participantId == $conn->userData->userId && $tempId) {
                    $payload['tempId'] = $tempId;
                }
                
                $this->userConnections[$participantId]->send(json_encode($payload));
                echo "Сообщение отправлено пользователю ID: {$participantId} (тип: $messageType)\n";
                $sentCount++;
            } else {
                echo "Пользователь ID: {$participantId} не подключен к WebSocket\n";
            }
        }
        
        echo "Сообщение разослано {$sentCount} пользователям\n";
        
        // Обновляем последнее сообщение в чате
        $this->updateChatLastMessage($chatId, $content);
    }
    
    protected function handleMarkRead($conn, $data) {
        if (!$conn->userData->isAuthorized) {
            echo "Mark read: пользователь не авторизован\n";
            return;
        }
        
        $chatId = $data['chatId'] ?? null;
        $messageId = $data['messageId'] ?? null;
        
        if (!$chatId) {
            echo "Mark read: chatId не указан\n";
            return;
        }
        
        echo "=== MARK READ ===\n";
        echo "Пользователь {$conn->userData->username} (ID: {$conn->userData->userId}) отмечает прочитанным чат {$chatId}\n";
        
        // Получаем ID чата
        $chat = $this->db->fetchOne(
            "SELECT id FROM chats WHERE chat_uuid = :chat_uuid",
            ['chat_uuid' => $chatId]
        );
        
        if (!$chat) {
            echo "Чат не найден: {$chatId}\n";
            return;
        }
        
        echo "ID чата в БД: {$chat['id']}\n";
        
        // Обновляем время последнего прочтения
        $result = $this->db->execute(
            "UPDATE chat_participants 
             SET last_read_at = NOW()
             WHERE user_id = :user_id AND chat_id = :chat_id",
            ['user_id' => $conn->userData->userId, 'chat_id' => $chat['id']]
        );
        
        echo "Обновлено строк в chat_participants: " . ($result ? "успешно" : "ошибка") . "\n";
        
        // Получаем все непрочитанные сообщения от других пользователей
        $unreadMessages = $this->db->fetchAll(
            "SELECT m.id, m.sender_id
             FROM messages m
             WHERE m.chat_id = :chat_id
             AND m.sender_id != :user_id
             AND m.is_deleted = false
             ORDER BY m.created_at DESC",
            ['chat_id' => $chat['id'], 'user_id' => $conn->userData->userId]
        );
        
        echo "Найдено сообщений для отметки как прочитанных: " . count($unreadMessages) . "\n";
        
        // Уведомляем отправителей о прочтении их сообщений
        $notifiedUsers = [];
        foreach ($unreadMessages as $msg) {
            if (!in_array($msg['sender_id'], $notifiedUsers)) {
                if (isset($this->userConnections[$msg['sender_id']])) {
                    $this->userConnections[$msg['sender_id']]->send(json_encode([
                        'type' => 'message_read',
                        'chatId' => $chatId,
                        'messageId' => $msg['id'],
                        'readBy' => $conn->userData->userId,
                        'status' => 'прочитано'
                    ]));
                    echo "Уведомлен пользователь ID {$msg['sender_id']} о прочтении сообщения {$msg['id']}\n";
                }
                $notifiedUsers[] = $msg['sender_id'];
            }
        }
        
        echo "=== END MARK READ ===\n\n";
    }
    
    protected function getChatParticipants($chatUuid) {
        $participants = $this->db->fetchAll(
            "SELECT user_id FROM chat_participants 
             WHERE chat_id = (SELECT id FROM chats WHERE chat_uuid = :chat_uuid)",
            ['chat_uuid' => $chatUuid]
        );
        
        return array_column($participants, 'user_id');
    }
    
    protected function saveMessage($chatUuid, $userId, $content, $type = 'text') {
        // Получаем ID чата
        $chat = $this->db->fetchOne(
            "SELECT id FROM chats WHERE chat_uuid = :chat_uuid",
            ['chat_uuid' => $chatUuid]
        );
        
        if (!$chat) {
            echo "Чат не найден: {$chatUuid}\n";
            return null;
        }
        
        try {
            // Сохраняем сообщение
            $this->db->execute(
                "INSERT INTO messages (chat_id, sender_id, content, type, created_at, status) 
                 VALUES (:chat_id, :sender_id, :content, :type, NOW(), 'отправлено')",
                [
                    'chat_id' => $chat['id'],
                    'sender_id' => $userId,
                    'content' => $content,
                    'type' => $type
                ]
            );
            
            // Получаем ID последнего вставленного сообщения
            $messageId = $this->db->lastInsertId();
            
            echo "Сообщение сохранено с ID: {$messageId}\n";
            return $messageId;
            
        } catch (Exception $e) {
            echo "Ошибка сохранения сообщения: " . $e->getMessage() . "\n";
            return null;
        }
    }
    
    protected function updateChatLastMessage($chatUuid, $content) {
        try {
            $this->db->execute(
                "UPDATE chats 
                 SET last_message = :content, 
                     last_message_at = NOW() 
                 WHERE chat_uuid = :chat_uuid",
                [
                    'content' => $content,
                    'chat_uuid' => $chatUuid
                ]
            );
        } catch (Exception $e) {
            echo "Ошибка обновления последнего сообщения: " . $e->getMessage() . "\n";
        }
    }
    
    protected function broadcastUserStatus($userId, $isOnline) {
        // Получаем всех пользователей, с кем есть чаты
        $relatedUsers = $this->db->fetchAll(
            "SELECT DISTINCT cp2.user_id
             FROM chat_participants cp1
             JOIN chat_participants cp2 ON cp1.chat_id = cp2.chat_id
             WHERE cp1.user_id = :user_id AND cp2.user_id != :user_id",
            ['user_id' => $userId]
        );
        
        // Отправляем уведомление
        foreach ($relatedUsers as $user) {
            if (isset($this->userConnections[$user['user_id']])) {
                $this->userConnections[$user['user_id']]->send(json_encode([
                    'type' => $isOnline ? 'user_online' : 'user_offline',
                    'userId' => $userId,
                    'isOnline' => $isOnline
                ]));
            }
        }
    }

    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
        
        if (isset($conn->userData) && $conn->userData->isAuthorized) {
            $userId = $conn->userData->userId;
            $username = $conn->userData->username;
            
            // Обновляем статус офлайн
            $this->db->execute(
                "UPDATE users SET is_online = false, last_seen = CURRENT_TIMESTAMP WHERE id = :id",
                ['id' => $userId]
            );
            
            // Уведомляем других
            $this->broadcastUserStatus($userId, false);
            
            // Удаляем из списка подключений
            unset($this->userConnections[$userId]);
            unset($this->authorizedConnections[$conn->resourceId]);
            
            echo "Пользователь отключился: {$username} (ID: {$userId})\n";
        }
        
        echo "Соединение {$conn->resourceId} закрыто\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "Ошибка: {$e->getMessage()}\n";
        $conn->close();
    }
}

// Запуск сервера
$port = 8085;

echo "Запуск WebSocket сервера на порту $port...\n";

try {
    $server = IoServer::factory(
        new HttpServer(
            new WsServer(
                new ChatWebSocket()
            )
        ),
        $port
    );
    
    echo "WebSocket сервер запущен на порту $port\n";
    echo "Нажмите Ctrl+C для остановки\n\n";
    
    $server->run();
} catch (Exception $e) {
    echo "Ошибка запуска сервера: " . $e->getMessage() . "\n";
    exit(1);
}