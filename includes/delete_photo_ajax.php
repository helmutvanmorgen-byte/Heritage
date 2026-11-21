<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    exit;
}

$files = $_POST['files'] ?? [];

if (!empty($files) && is_array($files)) {
    $uploadDir = realpath(__DIR__ . '/../uploads/');

    if ($uploadDir) {
        foreach ($files as $file) {
            $safeFileName = basename($file);
            $filePath = $uploadDir . DIRECTORY_SEPARATOR . $safeFileName;

            if (file_exists($filePath) && is_file($filePath)) {
                unlink($filePath);
            }
        }
    }
}