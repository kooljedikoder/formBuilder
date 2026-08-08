<?php

require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json');

$engine = kili_data_source_engine();
$action = $_GET['action'] ?? 'list';

if ($action === 'activate') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = $body['id'] ?? '';

    if ($engine->find($id) === null) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'error' => ['code' => 'VALIDATION_ERROR', 'message' => "Unknown data source id \"$id\""],
        ]);
        exit;
    }

    kili_set_active_data_source($id);
    echo json_encode(['success' => true, 'data' => ['active' => $id], 'meta' => []]);
    exit;
}

echo json_encode([
    'success' => true,
    'data' => ['sources' => $engine->all(), 'active' => $engine->activeId()],
    'meta' => [],
]);
