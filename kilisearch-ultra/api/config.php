<?php

require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json');

echo json_encode([
    'success' => true,
    'data' => kili_branding(),
    'meta' => ['version' => kili_config()['version'] ?? null],
]);
