<?php

require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json');

$records = kili_storage()->all();

echo json_encode([
    'success' => true,
    'data' => [
        'status' => 'ok',
        'records_indexed' => count($records),
        'storage_driver' => kili_config()['storage_driver'] ?? 'json',
    ],
    'meta' => [],
]);
