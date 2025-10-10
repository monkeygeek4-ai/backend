<?php
// backend/lib/WebSocketLogger.php

class WebSocketLogger {
    private static $instance = null;
    private $logFile;
    private $logDir;
    
    private function __construct() {
        $this->logDir = __DIR__ . '/../logs';
        
        // Создаем директорию logs если её нет
        if (!file_exists($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
        
        // Имя файла с датой
        $date = date('Y-m-d');
        $this->logFile = $this->logDir . "/websocket-{$date}.log";
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function log($message, $level = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
        
        // Пишем в файл
        file_put_contents($this->logFile, $logMessage, FILE_APPEND);
        
        // Также выводим в консоль
        echo $logMessage;
    }
    
    public function info($message) {
        $this->log($message, 'INFO');
    }
    
    public function error($message) {
        $this->log($message, 'ERROR');
    }
    
    public function debug($message) {
        $this->log($message, 'DEBUG');
    }
    
    public function separator() {
        $this->log("========================================");
    }
}