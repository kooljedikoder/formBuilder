<?php

require_once __DIR__ . '/../bootstrap.php';

killi_require_admin_auth_json();
header('Content-Type: application/json');

echo json_encode([
    'success' => true,
    'data' => killi_submissions_storage()->all(),
    'meta' => [],
]);
