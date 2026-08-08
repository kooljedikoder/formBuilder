<?php

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../core/SchemaDetector.php';

use Kili\Core\SchemaDetector;

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

if (empty($rows)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => [
            'code' => 'VALIDATION_ERROR',
            'message' => 'No rows to inspect. Upload a CSV as multipart field "file", or POST JSON {"rows": [...]}.',
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

echo json_encode([
    'success' => true,
    'data' => $schema,
    'meta' => ['action' => 'detect', 'sample_size' => min(count($rows), 25)],
]);
