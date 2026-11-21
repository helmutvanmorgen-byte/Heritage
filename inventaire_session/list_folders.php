<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode([]);
    exit;
}

$uploadDir = realpath(__DIR__ . '/../uploads');
$folders = [];

if ($uploadDir && is_dir($uploadDir)) {
    $items = scandir($uploadDir);
    if ($items !== false) {
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            if (is_dir($uploadDir . DIRECTORY_SEPARATOR . $item)) {
                $folders[] = $item;
            }
        }
        sort($folders, SORT_NATURAL | SORT_FLAG_CASE);
    }
}

echo json_encode($folders);
?>