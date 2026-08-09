<?php

require_once __DIR__ . '/../bootstrap.php';

killi_require_app_auth_json();
header('Content-Type: application/json');

echo json_encode([
    'success' => true,
    'data' => killi_location_engine()->tree(),
    'meta' => [],
]);
