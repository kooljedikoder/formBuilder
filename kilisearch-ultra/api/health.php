<?php

require_once __DIR__ . '/../bootstrap.php';

kili_require_app_auth_json();
header('Content-Type: application/json');

$records = kili_storage()->all();
$activeSource = kili_data_source_engine()->active();

echo json_encode([
    'success' => true,
    'data' => [
        'status' => 'ok',
        'records_indexed' => count($records),
        'storage_driver' => kili_config()['storage_driver'] ?? 'json',
        'active_data_source' => $activeSource['name'] ?? null,
    ],
    'meta' => [],
]);
