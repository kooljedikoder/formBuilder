<?php

require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json');

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$password = $body['password'] ?? '';

if (!killi_app_password_configured()) {
    killi_set_app_authenticated(true);
    echo json_encode(['success' => true, 'data' => ['authenticated' => true], 'meta' => []]);
    exit;
}

if (killi_verify_app_password($password)) {
    killi_set_app_authenticated(true);
    echo json_encode(['success' => true, 'data' => ['authenticated' => true], 'meta' => []]);
    exit;
}

http_response_code(401);
echo json_encode([
    'success' => false,
    'error' => ['code' => 'AUTH_REQUIRED', 'message' => 'Incorrect password.'],
]);
