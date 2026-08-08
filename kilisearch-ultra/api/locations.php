<?php

require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json');

echo json_encode([
    'success' => true,
    'data' => kili_location_engine()->tree(),
    'meta' => [],
]);
