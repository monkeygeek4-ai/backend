<?php
// backend/api/notifications/test.php

require_once dirname(__DIR__, 2) . '/lib/Auth.php';
require_once dirname(__DIR__, 2) . '/lib/Database.php';
require_once dirname(__DIR__, 2) . '/lib/Response.php';
require_once dirname(__DIR__, 2) . '/lib/PushNotificationService.php';

try {
    $auth = new Auth();
    $user = $auth->requireAuth();
    
    error_log('[Test Notification] Starting test for user: ' . $user['id']);
    
    $pushService = new PushNotificationService();
    
    // Отправляем тестовое уведомление о сообщении
    $result = $pushService->sendNewMessageNotification(
        $user['id'],
        'test_chat_' . time(),
        'SecureWave Test 🔔',
        'Это тестовое push-уведомление! Если вы видите это - всё работает! ✅',
        null
    );
    
    if ($result) {
        error_log('[Test Notification] SUCCESS - notification sent');
        Response::json([
            'success' => true,
            'message' => 'Тестовое уведомление отправлено! Проверьте браузер.'
        ]);
    } else {
        error_log('[Test Notification] FAILED - no tokens or send failed');
        Response::error('Не удалось отправить уведомление. Проверьте что токен зарегистрирован.', 500);
    }
    
} catch (Exception $e) {
    error_log('[Test Notification] ERROR: ' . $e->getMessage());
    error_log('[Test Notification] Stack: ' . $e->getTraceAsString());
    Response::error('Ошибка: ' . $e->getMessage(), 500);
}