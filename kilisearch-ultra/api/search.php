<?php

require_once __DIR__ . '/../bootstrap.php';

killi_require_app_auth_json();
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
$context = killi_extract_context($query);

$result = killi_search_engine()->search($query, $filters, $context, $limit, $offset);
$locationEngine = killi_location_engine();

$userLat = isset($_GET['lat']) ? (float) $_GET['lat'] : null;
$userLng = isset($_GET['lng']) ? (float) $_GET['lng'] : null;
$nearMode = $userLat !== null && $userLng !== null;
$radiusKm = (float) ($_GET['radius_km'] ?? (killi_read_json(__DIR__ . '/../config/search.json')['default_near_radius_km'] ?? 15));

$results = array_map(function ($record) use ($locationEngine, $userLat, $userLng) {
    if ($userLat !== null && $userLng !== null && isset($record['latitude'], $record['longitude'])) {
        $record['_distance_km'] = round($locationEngine->distanceKm($userLat, $userLng, (float) $record['latitude'], (float) $record['longitude']), 1);
    }
    return $record;
}, killi_resolve_sources($result['results']));

if ($nearMode) {
    $results = array_values(array_filter($results, fn($r) => isset($r['_distance_km']) && $r['_distance_km'] <= $radiusKm));
    usort($results, fn($a, $b) => $a['_distance_km'] <=> $b['_distance_km']);
}

$total = $nearMode ? count($results) : $result['total'];

// Reply text comes from the same rule-based ConversationEngine used by
// api/chat.php (config/conversation.json), so chip/near-me searches and
// free-text chat messages read consistently and an admin can edit the
// wording in one place.
$reply = killi_conversation_engine()->respond('find_service', [
    'query' => $result['query'],
    'count' => $total,
    'location' => $nearMode ? null : $context['location'],
]);

echo json_encode([
    'success' => true,
    'data' => $results,
    'meta' => [
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset,
        'query' => $result['query'],
        'tokens' => $result['tokens'],
        'detected' => array_filter($context, fn($v) => $v !== null),
        'near' => $nearMode ? ['lat' => $userLat, 'lng' => $userLng, 'radius_km' => $radiusKm] : null,
        'reply' => $reply,
    ],
]);
