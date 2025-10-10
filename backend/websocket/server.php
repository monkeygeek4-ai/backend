<?php
// backend/websocket/server.php

require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/lib/Database.php';
require_once dirname(__DIR__) . '/lib/Auth.php';
require_once __DIR__ . '/call_handlers.php';
require_once __DIR__ . '/message_handlers.php';  // ⭐ ДОБАВЛЕНО!

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
        echo "========================================\n";
        echo "📨 ПОЛУЧЕНО СООБЩЕНИЕ!\n";
        echo "От: {$from->resourceId}\n";
        echo "Сообщение: $msg\n";
        echo "========================================\n";
        
        try {
            $data = json_decode($msg, true);
            
            if (!$data) {
                echo "❌ Ошибка парсинга JSON\n";
                echo "========================================\n";
                return;
            }
            
            echo "✅ JSON распарсен успешно\n";
            echo "Тип сообщения: {$data['type']}\n";
            echo "========================================\n";
            
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
                case 'message':
                    // ⭐ ИСПРАВЛЕНО: Вызываем handleSendMessage из message_handlers.php
                    echo "========================================\n";
                    echo "🔥🔥🔥 ВЫЗЫВАЕМ handleSendMessage!\n";
                    echo "От пользователя: {$from->userData->username} (ID: {$from->userData->userId})\n";
                    echo "Данные: " . json_encode($data) . "\n";
                    echo "========================================\n";
                    handleSendMessage($data, $from, $this->clients, $this->db);
                    echo "========================================\n";
                    echo "✅ handleSendMessage завершен\n";
                    echo "========================================\n";
                    break;
                    
                case 'join_chat':
                    $this->handleJoinChat($from, $data);
                    break;
                    
                case 'leave_chat':
                    $this->handleLeaveChat($from, $data);
                    break;
                    
                case 'mark_read':
                    // ⭐ ИСПРАВЛЕНО: Вызываем handleMarkAsRead из message_handlers.php
                    handleMarkAsRead($data, $from, $this->clients, $this->db);
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
    
    protected function getChatParticipants($chatUuid) {
        $participants = $this->db->fetchAll(
            "SELECT user_id FROM chat_participants 
             WHERE chat_id = (SELECT id FROM chats WHERE chat_uuid = :chat_uuid)",
            ['chat_uuid' => $chatUuid]
        );
        
        return array_column($participants, 'user_id');
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