<?php

require_once __DIR__ . '/../bootstrap.php';

kili_require_admin_auth_json();
kili_require_feature_json('crud');
header('Content-Type: application/json');

$action = $_GET['action'] ?? 'list';
$sourceId = $_GET['source'] ?? $_POST['source'] ?? null;

try {
    $crud = kili_crud_engine($sourceId);
    $resolvedSourceId = $sourceId ?? kili_data_source_engine()->activeId();
} catch (\InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => ['code' => 'VALIDATION_ERROR', 'message' => $e->getMessage()]]);
    exit;
}

if ($action === 'list') {
    $query = trim($_GET['q'] ?? '');
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $result = $crud->list($query, $page, 20);
    echo json_encode(['success' => true, 'data' => $result, 'meta' => ['source' => $resolvedSourceId]]);
    exit;
}

if ($action === 'get') {
    $record = $crud->get($_GET['id'] ?? '');
    if ($record === null) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => 'No such record.']]);
        exit;
    }
    echo json_encode(['success' => true, 'data' => $record, 'meta' => []]);
    exit;
}

if (!in_array($action, ['create', 'update', 'delete'], true)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => 'Unknown action.']]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => ['code' => 'METHOD_NOT_ALLOWED', 'message' => 'Use POST.']]);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = $_POST;
}
unset($body['source']);

try {
    if ($action === 'create') {
        $record = $crud->create($body);
        kili_record_audit('create', $resolvedSourceId, $record['id'], $record['title'] ?? '');
        echo json_encode(['success' => true, 'data' => $record, 'meta' => []]);
    } elseif ($action === 'update') {
        $id = $body['id'] ?? '';
        $record = $crud->update($id, $body);
        kili_record_audit('update', $resolvedSourceId, $id, $record['title'] ?? '');
        echo json_encode(['success' => true, 'data' => $record, 'meta' => []]);
    } else {
        $id = $body['id'] ?? '';
        $existing = $crud->get($id);
        $deleted = $crud->delete($id);
        if ($deleted) {
            kili_record_audit('delete', $resolvedSourceId, $id, $existing['title'] ?? '');
        }
        echo json_encode(['success' => $deleted, 'data' => ['id' => $id], 'meta' => []]);
    }
} catch (\InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => ['code' => 'VALIDATION_ERROR', 'message' => $e->getMessage()]]);
}
