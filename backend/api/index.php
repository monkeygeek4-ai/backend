<?php
// backend/api/index.php

// Включаем отображение ошибок для отладки
error_reporting(E_ALL);
ini_set('display_errors', 1);

// CORS заголовки
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

// Обработка preflight запросов
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Автозагрузка классов
require_once dirname(__DIR__) . '/lib/Database.php';
require_once dirname(__DIR__) . '/lib/Auth.php';
require_once dirname(__DIR__) . '/lib/Response.php';
require_once dirname(__DIR__) . '/lib/JWT.php';

// Парсинг URL
$requestUri = $_SERVER['REQUEST_URI'];
// Убираем /backend/api/ из пути
$requestUri = preg_replace('#^/backend/api/#', '', $requestUri);
// Убираем параметры запроса
$requestUri = explode('?', $requestUri)[0];
// Убираем начальный и конечный слэш
$requestUri = trim($requestUri, '/');
// Разделяем на части
$parts = $requestUri ? explode('/', $requestUri) : [];

// Определяем ресурс и действие
$resource = $parts[0] ?? '';
$action = $parts[1] ?? '';
$id = $parts[2] ?? null;

// Логирование для отладки
error_log("[API] Request: " . $_SERVER['REQUEST_METHOD'] . " /$resource/$action" . ($id ? "/$id" : ""));

// Маршрутизация
try {
    switch ($resource) {
        case '':
        case 'health':
            Response::json([
                'status' => 'работает',
                'время' => date('c'),
                'версия' => '2.0.0',
                'база_данных' => 'PostgreSQL'
            ]);
            break;
            
        case 'auth':
            switch ($action) {
                case 'login':
                    require __DIR__ . '/auth/login.php';
                    break;
                case 'register':
                    require __DIR__ . '/auth/register.php';
                    break;
                case 'logout':
                    require __DIR__ . '/auth/logout.php';
                    break;
                case 'me':
                case 'user':
                case '':  // Добавлено для /auth/
                    require __DIR__ . '/auth/me.php';
                    break;
                default:
                    Response::error('Метод не найден: ' . $action, 404);
            }
            break;
            
        case 'chats':
			// Логирование для отладки
			error_log("[CHATS] Method: " . $_SERVER['REQUEST_METHOD'] . ", Action: '$action', ID: '$id'");
			
			if ($_SERVER['REQUEST_METHOD'] === 'GET') {
				if ($action === '' || $action === null) {
					// GET /chats/ - список чатов
					error_log("[CHATS] Loading chats list");
					$file = __DIR__ . '/chats/index.php';
					if (file_exists($file)) {
						require $file;
					} else {
						error_log("[CHATS] File not found: $file");
						Response::error('Файл не найден: chats/index.php', 500);
					}
				} elseif ($action === 'users') {
					// GET /chats/users - список пользователей  
					require __DIR__ . '/chats/users.php';
				} elseif ($action && !$id) {
					// GET /chats/{chatId} - конкретный чат
					$_GET['chatId'] = $action;
					$file = __DIR__ . '/chats/show.php';
					if (file_exists($file)) {
						require $file;
					} else {
						// Если show.php не существует, возвращаем пустой результат
						Response::json([
							'id' => $action,
							'messages' => []
						]);
					}
				} else {
					Response::error('Метод не найден', 404);
				}
			} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
				if ($action === 'create') {
					require __DIR__ . '/chats/create.php';
				} else {
					Response::error('Метод не найден', 404);
				}
			} elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
				if ($action === 'delete') {
					$file = __DIR__ . '/chats/delete.php';
					if (file_exists($file)) {
						require $file;
					} else {
						Response::json(['deleted' => true]);
					}
				} else {
					Response::error('Метод не найден', 404);
				}
			} else {
				Response::error('Метод не разрешен', 405);
			}
			break;
            
        case 'messages':
			// Логирование для отладки
			error_log("[MESSAGES] Method: " . $_SERVER['REQUEST_METHOD'] . ", Action: '$action', ID: '$id'");
			
			switch ($action) {
				case 'chat':
					// Поддержка обоих форматов:
					// 1. /messages/chat/{chatId}
					// 2. /messages/chat?chatId={chatId}
					
					if ($id) {
						// Формат: /messages/chat/{chatId}
						$_GET['chatId'] = $id;
					}
					// Иначе chatId уже должен быть в $_GET из query string
					
					require __DIR__ . '/messages/index.php';
					break;
					
				case 'send':
					require __DIR__ . '/messages/send.php';
					break;
					
				case 'read':
					require __DIR__ . '/messages/read.php';
					break;
				
				// ДОБАВЛЕНО: новый роут для mark-read
				case 'mark-read':
					$file = __DIR__ . '/messages/mark-read.php';
					if (file_exists($file)) {
						require $file;
					} else {
						// Если файл еще не создан, возвращаем успех
						Response::json(['success' => true]);
					}
					break;
					
				case '':
					// Если action пустой, проверяем есть ли chatId в параметрах
					if (isset($_GET['chatId'])) {
						require __DIR__ . '/messages/index.php';
					} else {
						Response::error('chatId обязателен', 400);
					}
					break;
					
				default:
					Response::error('Метод не найден: ' . $action, 404);
			}
			break;
            
        case 'users':
        case 'user':
            switch ($action) {
                case '':
                case 'me':
                    require __DIR__ . '/auth/me.php';
                    break;
                case 'search':
                    require __DIR__ . '/users/search.php';
                    break;
                default:
                    Response::error('Метод не найден: ' . $action, 404);
            }
            break;
            
        case 'profile':
            require __DIR__ . '/auth/me.php';
            break;
            
        default:
            Response::error('Ресурс не найден: ' . $resource, 404);
    }
} catch (Exception $e) {
    error_log("[API] Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    Response::error('Внутренняя ошибка сервера: ' . $e->getMessage(), 500);
}