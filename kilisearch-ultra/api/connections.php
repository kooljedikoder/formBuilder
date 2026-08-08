<?php

require_once __DIR__ . '/../bootstrap.php';

kili_require_admin_auth_json();
header('Content-Type: application/json');

$manager = kili_connection_manager();
$action = $_GET['action'] ?? 'list';
$body = json_decode(file_get_contents('php://input'), true) ?: [];

if ($action === 'test') {
    // Test a not-yet-saved profile (credentials in the request body) or
    // an already-saved one by name — either way, nothing is persisted.
    if (!empty($body['name']) && empty($body['host'])) {
        $result = $manager->testConnection($body['name']);
    } else {
        $tmpEnv = kili_load_env();
        $upper = strtoupper($body['name'] ?? 'test');
        $tmpEnv['KILI_DB_PROFILES'] = $upper;
        $tmpEnv['KILI_DB_' . $upper . '_DRIVER'] = $body['driver'] ?? 'mysql';
        $tmpEnv['KILI_DB_' . $upper . '_HOST'] = $body['host'] ?? '';
        $tmpEnv['KILI_DB_' . $upper . '_PORT'] = $body['port'] ?? '';
        $tmpEnv['KILI_DB_' . $upper . '_DATABASE'] = $body['database'] ?? '';
        $tmpEnv['KILI_DB_' . $upper . '_USERNAME'] = $body['username'] ?? '';
        $tmpEnv['KILI_DB_' . $upper . '_PASSWORD'] = $body['password'] ?? '';
        $tmpManager = new \Kili\Core\ConnectionManager($tmpEnv);
        $result = $tmpManager->testConnection($upper);
    }

    echo json_encode(['success' => true, 'data' => $result, 'meta' => []]);
    exit;
}

if ($action === 'save') {
    $name = trim($body['name'] ?? '');
    if ($name === '' || !preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'A profile "name" using only letters/numbers/underscore is required.']]);
        exit;
    }

    kili_save_env_profile($name, $body);
    echo json_encode(['success' => true, 'data' => ['name' => $name], 'meta' => []]);
    exit;
}

if ($action === 'tables') {
    $name = $body['name'] ?? ($_GET['name'] ?? '');
    try {
        $tables = kili_connection_manager()->listTables($name);
        echo json_encode(['success' => true, 'data' => ['tables' => $tables], 'meta' => []]);
    } catch (\Throwable $e) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => ['code' => 'CONNECTION_ERROR', 'message' => $e->getMessage()]]);
    }
    exit;
}

// Default: list configured profiles, credentials masked.
echo json_encode([
    'success' => true,
    'data' => array_map(fn($name) => $manager->profile($name), $manager->profileNames()),
    'meta' => [],
]);
