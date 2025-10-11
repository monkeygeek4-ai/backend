<?php
// backend/test_fcm_direct.php
// Прямой тест отправки FCM уведомления

require_once __DIR__ . '/lib/Database.php';
require_once __DIR__ . '/lib/FirebaseAdmin.php';

echo "========================================\n";
echo "🧪 ПРЯМОЙ ТЕСТ FCM\n";
echo "========================================\n\n";

try {
    // 1. Получаем FCM токены из БД
    echo "1️⃣ Получение FCM токенов из БД...\n";
    $db = Database::getInstance();
    
    $tokens = $db->fetchAll(
        "SELECT user_id, token, platform FROM fcm_tokens ORDER BY created_at DESC LIMIT 5"
    );
     
    if (empty($tokens)) {
        echo "❌ FCM токены не найдены в БД!\n";
        exit(1);
    }
    
    echo "✅ Найдено токенов: " . count($tokens) . "\n";
    foreach ($tokens as $idx => $tokenData) {
        echo "  [" . ($idx + 1) . "] User ID: {$tokenData['user_id']}, Platform: {$tokenData['platform']}, Token: " . substr($tokenData['token'], 0, 30) . "...\n";
    }
    
    // 2. Выбираем токен для теста
    echo "\nВведите номер токена для отправки тестового уведомления (1-" . count($tokens) . ") [Enter = 1]: ";
    $handle = fopen("php://stdin", "r");
    $line = trim(fgets($handle));
    $selectedIndex = empty($line) ? 0 : intval($line) - 1;
    fclose($handle);
    
    if ($selectedIndex < 0 || $selectedIndex >= count($tokens)) {
        echo "❌ Неверный номер токена!\n";
        exit(1);
    }
    
    $selectedToken = $tokens[$selectedIndex]['token'];
    $userId = $tokens[$selectedIndex]['user_id'];
    
    echo "\n========================================\n";
    echo "📤 ОТПРАВКА ТЕСТОВОГО УВЕДОМЛЕНИЯ\n";
    echo "========================================\n";
    echo "User ID: $userId\n";
    echo "Token: " . substr($selectedToken, 0, 30) . "...\n";
    echo "========================================\n\n";
    
    // 3. Создаём тестовые данные звонка
    $testCallId = 'test-call-' . time();
    $testData = [
        'type' => 'incoming_call',
        'callId' => $testCallId,
        'callerName' => 'Test Caller 🧪',
        'callType' => 'audio',
        'callerAvatar' => '',
        'timestamp' => strval(time()),
        'title' => 'Тестовый звонок от Test Caller 🧪',
        'body' => 'Входящий тестовый звонок'
    ];
    
    echo "📦 Данные уведомления:\n";
    echo json_encode($testData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
    
    // 4. Отправляем через FirebaseAdmin
    echo "🚀 Отправка через FirebaseAdmin...\n\n";
    
    $firebaseAdmin = new FirebaseAdmin();
    $result = $firebaseAdmin->sendNotification(
        $selectedToken,
        null, // БЕЗ notification payload для data-only
        $testData
    );
    
    echo "\n========================================\n";
    if ($result) {
        echo "✅✅✅ ТЕСТ УСПЕШЕН!\n";
        echo "========================================\n";
        echo "FCM уведомление отправлено пользователю $userId\n";
        echo "Проверьте Android устройство - должно появиться:\n";
        echo "  1. Foreground: Callback в FCMService\n";
        echo "  2. Логи: [FCM] 📩 📩 📩 FOREGROUND MESSAGE ПОЛУЧЕНО!\n";
        echo "  3. UI: Overlay с входящим звонком\n";
    } else {
        echo "❌❌❌ ТЕСТ ПРОВАЛЕН!\n";
        echo "========================================\n";
        echo "FCM уведомление НЕ отправлено\n";
        echo "Проверьте логи выше для деталей ошибки\n";
    }
    echo "========================================\n";
    
} catch (Exception $e) {
    echo "\n========================================\n";
    echo "❌❌❌ EXCEPTION!\n";
    echo "========================================\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
    echo "========================================\n";
}
