<?php

require_once __DIR__ . '/../bootstrap.php';

killi_require_admin_auth_json();
killi_require_feature_json('crud');

$sourceId = $_GET['source'] ?? killi_data_source_engine()->activeId();
$format = $_GET['format'] ?? 'json';

try {
    $storage = killi_storage_for_source($sourceId);
} catch (\InvalidArgumentException $e) {
    http_response_code(422);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => ['code' => 'VALIDATION_ERROR', 'message' => $e->getMessage()]]);
    exit;
}

$records = $storage->all();
$filenameBase = preg_replace('/[^a-z0-9_-]+/', '-', mb_strtolower($sourceId));

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filenameBase . '.csv"');

    $out = fopen('php://output', 'w');

    $columns = [];
    foreach ($records as $record) {
        foreach (array_keys($record) as $key) {
            if (!in_array($key, $columns, true)) {
                $columns[] = $key;
            }
        }
    }

    fputcsv($out, $columns);
    foreach ($records as $record) {
        fputcsv($out, array_map(function ($col) use ($record) {
            $value = $record[$col] ?? '';
            return is_array($value) ? implode('|', $value) : $value;
        }, $columns));
    }

    fclose($out);
    exit;
}

header('Content-Type: application/json');
header('Content-Disposition: attachment; filename="' . $filenameBase . '.json"');
echo json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
