<?php
header('Content-Type: application/json');
echo json_encode([
    'status' => 'ok',
    'message' => 'Test endpoint works',
    'request_uri' => $_SERVER['REQUEST_URI'],
    'script_name' => $_SERVER['SCRIPT_NAME']
]);