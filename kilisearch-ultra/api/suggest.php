<?php

require_once __DIR__ . '/../bootstrap.php';

kili_require_app_auth_json();
header('Content-Type: application/json');

$prefix = trim($_GET['q'] ?? '');
$suggestions = $prefix === '' ? [] : kili_search_engine()->suggest($prefix, 6);

echo json_encode([
    'success' => true,
    'data' => $suggestions,
    'meta' => [],
]);
