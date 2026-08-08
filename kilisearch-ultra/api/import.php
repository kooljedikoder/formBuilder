<?php

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../core/SchemaDetector.php';

use Kili\Core\SchemaDetector;

kili_require_admin_auth_json();
header('Content-Type: application/json');

/** @return array<int, array<string, mixed>> */
function kili_parse_csv_upload(string $path): array
{
    $rows = [];
    $handle = fopen($path, 'r');
    if ($handle === false) {
        return [];
    }

    $header = fgetcsv($handle);
    if ($header === false) {
        fclose($handle);
        return [];
    }

    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) !== count($header)) {
            continue;
        }
        $rows[] = array_combine($header, $row);
    }
    fclose($handle);

    return $rows;
}

$action = $_GET['action'] ?? 'detect';
$body = [];
$rows = [];

if (!empty($_FILES['file']['tmp_name'])) {
    $rows = kili_parse_csv_upload($_FILES['file']['tmp_name']);
} else {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $rows = $body['rows'] ?? [];
}

// Rows can also come straight from a live database table (a configured
// connection profile + table name) instead of an upload — same detect/
// preview/import/publish flow either way, since it's all just "rows" by
// the time SchemaDetector sees them.
if (empty($rows) && !empty($body['connection']) && !empty($body['table'])) {
    try {
        $rows = kili_connection_manager()->fetchRows($body['connection'], $body['table'], (int) ($body['limit'] ?? 200));
    } catch (\Throwable $e) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'error' => ['code' => 'CONNECTION_ERROR', 'message' => $e->getMessage()],
        ]);
        exit;
    }
}

if (empty($rows)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => [
            'code' => 'VALIDATION_ERROR',
            'message' => 'No rows to inspect. Upload a CSV as multipart field "file", POST JSON {"rows": [...]}, or POST {"connection": "...", "table": "..."}.',
        ],
    ]);
    exit;
}

$detector = new SchemaDetector();
$schema = $detector->detect($rows);

if ($action === 'preview') {
    $mapping = $body['mapping'] ?? $schema['mapping'];
    $preview = $detector->applyMapping(array_slice($rows, 0, 5), $mapping);

    echo json_encode([
        'success' => true,
        'data' => ['schema' => $schema, 'preview' => $preview],
        'meta' => ['action' => 'preview'],
    ]);
    exit;
}

if ($action === 'import') {
    $mapping = $body['mapping'] ?? $schema['mapping'];
    $mapped = $detector->applyMapping($rows, $mapping);

    $sourceId = $body['source_id'] ?? 'src-import';
    kili_ensure_source($sourceId, 'import', $body['source_name'] ?? 'Imported Data');

    $storage = kili_storage();
    $imported = [];
    foreach ($mapped as $record) {
        if (empty($record['title'])) {
            continue; // A record without even a title isn't a usable listing.
        }
        $record += [
            'status' => 'active',
            'verified' => false,
            'tags' => [],
        ];
        $record['source_id'] = $sourceId;
        $imported[] = $storage->save($record);
    }

    // No separate re-index step: the JsonAdapter file IS the index in this
    // zero-DB architecture, so imported records are searchable immediately
    // on the next request.
    echo json_encode([
        'success' => true,
        'data' => ['imported' => count($imported), 'skipped' => count($mapped) - count($imported), 'records' => $imported],
        'meta' => ['action' => 'import', 'source_id' => $sourceId],
    ]);
    exit;
}

if ($action === 'publish') {
    $name = trim($body['name'] ?? '');
    if ($name === '') {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'error' => ['code' => 'VALIDATION_ERROR', 'message' => '"name" is required to publish a new data source.'],
        ]);
        exit;
    }

    $mapping = $body['mapping'] ?? $schema['mapping'];
    $provenanceType = 'import';
    if (!empty($body['connection'])) {
        $connectionProfile = kili_connection_manager()->profile($body['connection']);
        $provenanceType = $connectionProfile['driver'] ?? 'database';
    }
    $newSource = kili_publish_data_source($name, $rows, $mapping, $provenanceType);

    if (!empty($body['activate'])) {
        kili_set_active_data_source($newSource['id']);
    }

    echo json_encode([
        'success' => true,
        'data' => ['source' => $newSource, 'activated' => !empty($body['activate'])],
        'meta' => ['action' => 'publish'],
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'data' => $schema,
    'meta' => ['action' => 'detect', 'sample_size' => min(count($rows), 25)],
]);
