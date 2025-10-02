<?php
// backend/lib/WebSocketClient.php

/**
 * WebSocket клиент для отправки сообщений из REST API в WebSocket сервер
 * Используется для уведомлений о звонках, сообщениях и других событиях
 */
class WebSocketClient {
    private $host;
    private $port;
    private $timeout;
    private $socket;
    
    public function __construct($host = 'localhost', $port = 8085, $timeout = 5) {
        $this->host = $host;
        $this->port = $port;
        $this->timeout = $timeout;
    }
    
    /**
     * Устанавливает соединение с WebSocket сервером
     */
    private function connect() {
        $this->socket = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);
        
        if (!$this->socket) {
            error_log("WebSocketClient: Failed to connect to {$this->host}:{$this->port} - $errstr ($errno)");
            return false;
        }
        
        stream_set_timeout($this->socket, $this->timeout);
        return true;
    }
    
    /**
     * Закрывает соединение
     */
    private function disconnect() {
        if ($this->socket) {
            fclose($this->socket);
            $this->socket = null;
        }
    }
    
    /**
     * Отправляет сообщение в WebSocket сервер
     * 
     * @param array $data Данные для отправки
     * @return bool Успешность отправки
     */
    public function send($data) {
        try {
            // Для простоты используем HTTP запрос к внутреннему эндпоинту
            // В реальном продакшн-приложении это должен быть полноценный WebSocket клиент
            
            // Альтернативный подход: используем базу данных для очереди событий
            // которые WebSocket сервер будет читать
            
            // Пока просто логируем
            error_log("WebSocketClient: Would send: " . json_encode($data));
            
            // TODO: Реализовать один из подходов:
            // 1. Redis pub/sub
            // 2. Очередь в PostgreSQL
            // 3. Полноценный WebSocket клиент
            
            return true;
            
        } catch (Exception $e) {
            error_log("WebSocketClient: Error sending message: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Отправляет уведомление конкретному пользователю
     * 
     * @param int $userId ID пользователя
     * @param array $data Данные уведомления
     * @return bool
     */
    public function sendToUser($userId, $data) {
        $data['to'] = $userId;
        return $this->send($data);
    }
    
    /**
     * Отправляет уведомление всем участникам чата
     * 
     * @param string $chatId UUID чата
     * @param array $data Данные уведомления
     * @return bool
     */
    public function sendToChat($chatId, $data) {
        $data['chatId'] = $chatId;
        return $this->send($data);
    }
    
    /**
     * Уведомление о новом сообщении
     */
    public function notifyNewMessage($chatId, $message) {
        return $this->send([
            'type' => 'new_message',
            'chatId' => $chatId,
            'message' => $message
        ]);
    }
    
    /**
     * Уведомление о прочтении сообщений
     */
    public function notifyMessagesRead($chatId, $userId, $messageIds = null) {
        return $this->send([
            'type' => 'messages_read',
            'chatId' => $chatId,
            'userId' => $userId,
            'messageIds' => $messageIds
        ]);
    }
    
    /**
     * Уведомление о звонке
     */
    public function notifyCall($type, $callData) {
        return $this->send(array_merge(['type' => $type], $callData));
    }
    
    /**
     * Проверка доступности WebSocket сервера
     */
    public function isServerAvailable() {
        if ($this->connect()) {
            $this->disconnect();
            return true;
        }
        return false;
    }
}

/**
 * ПРИМЕЧАНИЕ ДЛЯ РАЗРАБОТЧИКА:
 * 
 * Эта реализация - заглушка для демонстрации архитектуры.
 * В продакшене рекомендуется использовать один из подходов:
 * 
 * 1. Redis Pub/Sub:
 *    - REST API публикует события в Redis
 *    - WebSocket сервер подписан на каналы Redis
 *    - Быстро, надежно, масштабируемо
 * 
 * 2. PostgreSQL NOTIFY/LISTEN:
 *    - REST API: NOTIFY channel_name, 'payload'
 *    - WebSocket: LISTEN channel_name
 *    - Встроено в PostgreSQL
 * 
 * 3. Очередь сообщений (RabbitMQ, Amazon SQS):
 *    - REST API добавляет в очередь
 *    - WebSocket сервер читает из очереди
 *    - Гарантированная доставка
 * 
 * 4. HTTP POST на локальный эндпоинт WebSocket сервера:
 *    - Добавить HTTP listener в WebSocket сервер
 *    - REST API делает POST запрос
 *    - Просто, но требует дополнительного порта
 */