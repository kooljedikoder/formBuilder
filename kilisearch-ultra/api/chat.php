<?php

require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$message = trim($input['message'] ?? ($_GET['message'] ?? ''));

if ($message === '') {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => ['code' => 'VALIDATION_ERROR', 'message' => '"message" is required'],
    ]);
    exit;
}

$conversation = kili_conversation_engine();
$intent = $conversation->detectIntent($message);

// Small talk (greeting/thanks/help) doesn't need a search — just a templated reply.
if (in_array($intent, ['greeting', 'thanks', 'help', 'unknown'], true)) {
    echo json_encode([
        'success' => true,
        'data' => [
            'intent' => $intent,
            'reply' => $conversation->respond($intent),
            'detected' => [],
            'results' => [],
            'total' => 0,
        ],
        'meta' => [],
    ]);
    exit;
}

$context = kili_extract_context($message);
$result = kili_search_engine()->search($message, [], $context, 10, 0);
$results = kili_resolve_sources($result['results']);

$reply = $conversation->respond('find_service', [
    'query' => $message,
    'count' => $result['total'],
    'location' => $context['location'],
]);

echo json_encode([
    'success' => true,
    'data' => [
        'intent' => $intent,
        'reply' => $reply,
        'detected' => array_filter($context, fn($v) => $v !== null),
        'results' => $results,
        'total' => $result['total'],
    ],
    'meta' => [],
]);
