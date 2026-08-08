<?php

require_once __DIR__ . '/../bootstrap.php';

kili_require_app_auth_json();
header('Content-Type: application/json');
kili_require_feature_json('attachments');

const MAX_UPLOAD_BYTES = 5 * 1024 * 1024; // 5MB

// Extension is derived from the *detected* MIME type below, never from the
// client-supplied filename or Content-Type — prevents extension spoofing
// and path traversal. SVG/HTML are deliberately excluded (script content
// risk); this is attachments, not a general file host.
const ALLOWED_UPLOAD_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
    'application/pdf' => 'pdf',
];

if (empty($_FILES['file']['tmp_name']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'No valid file uploaded (multipart field "file").'],
    ]);
    exit;
}

$tmpPath = $_FILES['file']['tmp_name'];
$size = (int) $_FILES['file']['size'];

if ($size > MAX_UPLOAD_BYTES) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => ['code' => 'FILE_TOO_LARGE', 'message' => 'File exceeds the 5MB limit.'],
    ]);
    exit;
}

// Sniff the real content, not the client-claimed type — a renamed .php
// posing as "photo.jpg" is rejected here regardless of its filename.
$finfo = new finfo(FILEINFO_MIME_TYPE);
$detectedMime = $finfo->file($tmpPath);

if (!isset(ALLOWED_UPLOAD_TYPES[$detectedMime])) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => ['code' => 'UNSUPPORTED_TYPE', 'message' => 'Only JPG, PNG, GIF, WEBP and PDF files are supported.'],
    ]);
    exit;
}

$extension = ALLOWED_UPLOAD_TYPES[$detectedMime];
$storedName = bin2hex(random_bytes(16)) . '.' . $extension;
$uploadsDir = __DIR__ . '/../storage/uploads';

if (!is_dir($uploadsDir)) {
    mkdir($uploadsDir, 0755, true);
}

$destination = $uploadsDir . '/' . $storedName;

if (!move_uploaded_file($tmpPath, $destination)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => ['code' => 'UPLOAD_FAILED', 'message' => 'Could not save the uploaded file.'],
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'data' => [
        'url' => '../storage/uploads/' . $storedName,
        'filename' => basename($_FILES['file']['name'] ?? 'attachment'),
        'mime' => $detectedMime,
        'size' => $size,
    ],
    'meta' => [],
]);
