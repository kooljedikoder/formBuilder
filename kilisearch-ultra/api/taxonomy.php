<?php

require_once __DIR__ . '/../bootstrap.php';

kili_require_app_auth_json();
header('Content-Type: application/json');

echo json_encode([
    'success' => true,
    'data' => kili_taxonomy_engine()->tree(),
    'meta' => [],
]);
