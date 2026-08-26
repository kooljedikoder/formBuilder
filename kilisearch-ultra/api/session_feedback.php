<?php

require_once __DIR__ . '/../bootstrap.php';

killi_require_app_auth_json();
header('Content-Type: application/json');

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$rating = filter_var($body['rating'] ?? null, FILTER_VALIDATE_INT);
$comment = trim((string) ($body['comment'] ?? ''));
$turns = is_array($body['turns'] ?? null) ? $body['turns'] : [];

if ($rating === false || $rating < 1 || $rating > 5) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'A "rating" between 1 and 5 is required.'],
    ]);
    exit;
}

killi_record_session_rating($rating, $comment, $turns);

echo json_encode(['success' => true, 'data' => ['recorded' => true], 'meta' => []]);
