<?php

require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json');

// Flat sector list for simple UI clients (chips, dropdowns). Clients that
// need the full Sector > Category > Subcategory tree should use
// api/taxonomy.php instead.
$sectors = array_map(
    fn($node) => ['name' => $node['sector'], 'category_count' => count($node['categories'] ?? [])],
    kili_taxonomy_engine()->tree()
);

echo json_encode([
    'success' => true,
    'data' => $sectors,
    'meta' => [],
]);
