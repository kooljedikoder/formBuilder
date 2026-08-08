<?php

require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json');

$query = trim($_GET['q'] ?? '');
$limit = max(1, min(50, (int) ($_GET['limit'] ?? 10)));
$offset = max(0, (int) ($_GET['offset'] ?? 0));

$filters = array_filter([
    'category' => $_GET['category'] ?? null,
    'sector' => $_GET['sector'] ?? null,
    'location' => $_GET['location'] ?? null,
], fn($v) => $v !== null && $v !== '');

if ($query === '') {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'Query parameter "q" is required'],
    ]);
    exit;
}

// Location + Taxonomy engines read the free-text query so "mechanic near vi"
// resolves to sector=Automotive/category=Vehicle Repair/subcategory=Mechanic
// and location=Victoria Island (via the "vi" alias) without the caller having
// to pass structured filters.
$locationMatch = kili_location_engine()->extractLocation($query);
$taxonomyMatch = kili_taxonomy_engine()->extractTaxonomy($query);

$context = [
    'location' => $locationMatch['name'] ?? null,
    'sector' => $taxonomyMatch['sector'] ?? null,
    'category' => $taxonomyMatch['category'] ?? null,
    'subcategory' => $taxonomyMatch['subcategory'] ?? null,
];

$result = kili_search_engine()->search($query, $filters, $context, $limit, $offset);
$sourceRegistry = kili_source_registry();
$locationEngine = kili_location_engine();

$userLat = isset($_GET['lat']) ? (float) $_GET['lat'] : null;
$userLng = isset($_GET['lng']) ? (float) $_GET['lng'] : null;
$nearMode = $userLat !== null && $userLng !== null;
$radiusKm = (float) ($_GET['radius_km'] ?? (kili_read_json(__DIR__ . '/../config/search.json')['default_near_radius_km'] ?? 15));

$results = array_map(function ($record) use ($sourceRegistry, $locationEngine, $userLat, $userLng) {
    $record['source'] = $sourceRegistry->resolve($record['source_id'] ?? null);
    if ($userLat !== null && $userLng !== null && isset($record['latitude'], $record['longitude'])) {
        $record['_distance_km'] = round($locationEngine->distanceKm($userLat, $userLng, (float) $record['latitude'], (float) $record['longitude']), 1);
    }
    return $record;
}, $result['results']);

if ($nearMode) {
    $results = array_values(array_filter($results, fn($r) => isset($r['_distance_km']) && $r['_distance_km'] <= $radiusKm));
    usort($results, fn($a, $b) => $a['_distance_km'] <=> $b['_distance_km']);
}

echo json_encode([
    'success' => true,
    'data' => $results,
    'meta' => [
        'total' => $nearMode ? count($results) : $result['total'],
        'limit' => $limit,
        'offset' => $offset,
        'query' => $result['query'],
        'tokens' => $result['tokens'],
        'detected' => array_filter($context, fn($v) => $v !== null),
        'near' => $nearMode ? ['lat' => $userLat, 'lng' => $userLng, 'radius_km' => $radiusKm] : null,
    ],
]);
