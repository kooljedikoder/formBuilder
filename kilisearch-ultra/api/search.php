<?php

require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json');

$query = trim($_GET['q'] ?? '');
$limit = max(1, min(50, (int) ($_GET['limit'] ?? 10)));
$offset = max(0, (int) ($_GET['offset'] ?? 0));

$filters = array_filter([
    'category' => $_GET['category'] ?? null,
    'location' => $_GET['location'] ?? null,
], fn($v) => $v !== null && $v !== '');

if ($query === '') {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'Query parameter "q" is required'],
    ]);
    exit;
}

$result = kili_search_engine()->search($query, $filters, $limit, $offset);

echo json_encode([
    'success' => true,
    'data' => $result['results'],
    'meta' => [
        'total' => $result['total'],
        'limit' => $limit,
        'offset' => $offset,
        'query' => $result['query'],
        'tokens' => $result['tokens'],
    ],
]);
