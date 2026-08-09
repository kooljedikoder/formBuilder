<?php

require_once __DIR__ . '/../bootstrap.php';

killi_require_app_auth_json();
header('Content-Type: application/json');

$records = killi_storage()->all();
$activeSource = killi_data_source_engine()->active();

echo json_encode([
    'success' => true,
    'data' => [
        'status' => 'ok',
        'records_indexed' => count($records),
        'storage_driver' => killi_config()['storage_driver'] ?? 'json',
        'active_data_source' => $activeSource['name'] ?? null,
    ],
    'meta' => [],
]);
