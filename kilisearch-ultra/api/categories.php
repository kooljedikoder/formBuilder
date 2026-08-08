<?php

require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json');

echo json_encode([
    'success' => true,
    'data' => kili_read_json(__DIR__ . '/../data/categories.json'),
    'meta' => [],
]);
