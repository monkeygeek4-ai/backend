<?php
// backend/api/notifications/test.php

$logFile = __DIR__ . '/../../logs/test_notification.log';

try {
    file_put_contents($logFile, date('Y-m-d H:i:s') . " [Test] START\n", FILE_APPEND);
    
    require_once dirname(__DIR__, 2) . '/lib/Auth.php';
    file_put_contents($logFile, date('Y-m-d H:i:s') . " [Test] Auth loaded\n", FILE_APPEND);
    
    require_once dirname(__DIR__, 2) . '/lib/Database.php';
    file_put_contents($logFile, date('Y-m-d H:i:s') . " [Test] Database loaded\n", FILE_APPEND);
    
    require_once dirname(__DIR__, 2) . '/lib/Response.php';
    file_put_contents($logFile, date('Y-m-d H:i:s') . " [Test] Response loaded\n", FILE_APPEND);
    
    require_once dirname(__DIR__, 2) . '/lib/PushNotificationService.php';
    file_put_contents($logFile, date('Y-m-d H:i:s') . " [Test] PushNotificationService loaded\n", FILE_APPEND);
    
    $auth = new Auth();
    $user = $auth->requireAuth();
    file_put_contents($logFile, date('Y-m-d H:i:s') . " [Test] User authenticated: {$user['id']}\n", FILE_APPEND);
    
    $pushService = new PushNotificationService();
    file_put_contents($logFile, date('Y-m-d H:i:s') . " [Test] PushNotificationService created\n", FILE_APPEND);
    
    // Отправляем тестовое уведомление
    $result = $pushService->sendNewMessageNotification(
        $user['id'],
        'test_chat_' . time(),
        'SecureWave Test',
        'Тестовое уведомление!',
        null
    );
    
    file_put_contents($logFile, date('Y-m-d H:i:s') . " [Test] Result: " . ($result ? 'SUCCESS' : 'FAILED') . "\n", FILE_APPEND);
    
    if ($result) {
        Response::json([
            'success' => true,
            'message' => 'Уведомление отправлено!'
        ]);
    } else {
        Response::error('Не удалось отправить уведомление', 500);
    }
    
} catch (Exception $e) {
    file_put_contents($logFile, date('Y-m-d H:i:s') . " [Test] ERROR: {$e->getMessage()}\n", FILE_APPEND);
    file_put_contents($logFile, date('Y-m-d H:i:s') . " [Test] File: {$e->getFile()}:{$e->getLine()}\n", FILE_APPEND);
    file_put_contents($logFile, date('Y-m-d H:i:s') . " [Test] Stack: {$e->getTraceAsString()}\n", FILE_APPEND);
    
    Response::error('Ошибка: ' . $e->getMessage(), 500);
}