<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Non autorisé']);
    exit;
}

if (empty($_FILES) && empty($_POST) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    echo json_encode(['success' => false, 'message' => 'Le fichier est trop lourd et dépasse la limite autorisée par le serveur (post_max_size).']);
    exit;
}

if (!isset($_FILES['photo'])) {
    echo json_encode(['success' => false, 'message' => 'Aucun fichier n\'a été reçu par le serveur.']);
    exit;
}

if ($_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE   => 'Le fichier est trop lourd (dépasse la limite upload_max_filesize de PHP).',
        UPLOAD_ERR_FORM_SIZE  => 'Le fichier dépasse la taille maximale autorisée par le formulaire.',
        UPLOAD_ERR_PARTIAL    => 'Le fichier n\'a été que partiellement envoyé.',
        UPLOAD_ERR_NO_FILE    => 'Aucun fichier n\'a été envoyé.',
        UPLOAD_ERR_NO_TMP_DIR => 'Dossier temporaire manquant sur le serveur.',
        UPLOAD_ERR_CANT_WRITE => 'Échec de l\'écriture du fichier sur le disque. Vérifiez les permissions.',
        UPLOAD_ERR_EXTENSION  => 'Une extension PHP a bloqué l\'envoi du fichier.'
    ];
    $errorCode = $_FILES['photo']['error'];
    $errorMsg = $uploadErrors[$errorCode] ?? 'Erreur d\'upload inconnue (code ' . $errorCode . ').';

    echo json_encode(['success' => false, 'message' => $errorMsg]);
    exit;
}

$file = $_FILES['photo'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'tif', 'tiff'];

if (!in_array($ext, $allowed)) {
    echo json_encode(['success' => false, 'message' => 'Format non autorisé. Sont acceptés : JPG, PNG, GIF, WEBP, TIF.']);
    exit;
}

$uploadDir = realpath(__DIR__ . '/../uploads');
if (!$uploadDir) {
    $uploadDir = __DIR__ . '/../uploads';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
}

$filename = 'IMG_' . date('YmdHis') . '_' . substr(uniqid(), -5) . '.' . $ext;
$destination = $uploadDir . '/' . $filename;

if (move_uploaded_file($file['tmp_name'], $destination)) {
    echo json_encode(['success' => true, 'filename' => $filename]);
} else {
    echo json_encode(['success' => false, 'message' => 'Erreur lors du déplacement du fichier vers le dossier uploads.']);
}