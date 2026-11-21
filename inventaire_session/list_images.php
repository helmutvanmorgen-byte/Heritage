<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode([]);
    exit;
}

$folder = basename(trim((string)($_GET['folder'] ?? '')));
$baseUploadsDir = realpath(__DIR__ . '/../uploads');

if ($folder === '' || !$baseUploadsDir) {
    echo json_encode([]);
    exit;
}

$uploadDir = realpath($baseUploadsDir . DIRECTORY_SEPARATOR . $folder);
if (
    !$uploadDir ||
    !is_dir($uploadDir) ||
    strpos($uploadDir, $baseUploadsDir . DIRECTORY_SEPARATOR) !== 0
) {
    echo json_encode([]);
    exit;
}

$images = [];
$files = scandir($uploadDir);
if ($files !== false) {
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        if (is_file($uploadDir . DIRECTORY_SEPARATOR . $file) && preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $file)) {
            $images[] = $folder . '/' . $file;
        }
    }
    sort($images, SORT_NATURAL | SORT_FLAG_CASE);
}

echo json_encode($images);