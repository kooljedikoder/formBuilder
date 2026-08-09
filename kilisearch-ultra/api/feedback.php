<?php

require_once __DIR__ . '/../bootstrap.php';

killi_require_app_auth_json();
header('Content-Type: application/json');

const ALLOWED_EMOJI = ['😍', '👍', '😐', '👎'];

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$emoji = $body['emoji'] ?? '';
$reply = trim($body['reply'] ?? '');

if (!in_array($emoji, ALLOWED_EMOJI, true) || $reply === '') {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'A valid "emoji" and non-empty "reply" are required.'],
    ]);
    exit;
}

killi_record_feedback($emoji, $reply, is_array($body['context'] ?? null) ? $body['context'] : []);

echo json_encode(['success' => true, 'data' => ['recorded' => true], 'meta' => []]);
