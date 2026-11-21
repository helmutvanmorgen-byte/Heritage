<?php
session_start();
date_default_timezone_set('Europe/Paris');
require_once __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['user_id'], $_SESSION['user_role'])) {
    http_response_code(403);
    die("Non connecté.");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard.php");
    exit;
}

if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    die("Action non autorisée (Erreur de sécurité CSRF).");
}

$userId  = (int) $_SESSION['user_id'];
$role    = (string) $_SESSION['user_role'];
$isAdmin = in_array(mb_strtolower(trim($role), 'UTF-8'), ['admin', 'administrateur'], true);

$id        = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$permanent = !empty($_POST['permanent']);
$restore   = !empty($_POST['restore']);
$publish   = !empty($_POST['publish']);

if (!$id) {
    $_SESSION['flash_error'] = "ID invalide pour l'opération.";
    header("Location: dashboard.php");
    exit;
}

function extractMonumentFiles(array $monument): array
{
    $files = [];

    if (!empty($monument['images']) && is_array($monument['images'])) {
        foreach ($monument['images'] as $img) {
            if (!empty($img['file'])) {
                $files[] = basename((string)$img['file']);
            }
        }
    }

    if (!empty($monument['photos_payload']) && is_array($monument['photos_payload'])) {
        foreach (['actuelles', 'maquettes', 'anciennes'] as $sec) {
            if (!empty($monument['photos_payload'][$sec]) && is_array($monument['photos_payload'][$sec])) {
                foreach ($monument['photos_payload'][$sec] as $item) {
                    $f = $item['file'] ?? $item['filename'] ?? '';
                    if ($f !== '') {
                        $files[] = basename((string)$f);
                    }
                }
            }
        }
    }

    if (!empty($monument['documentation_zotero']) && is_array($monument['documentation_zotero'])) {
        foreach ($monument['documentation_zotero'] as $doc) {
            if (!empty($doc['image_extrait'])) {
                $files[] = basename((string)$doc['image_extrait']);
            }
        }
    }

    if (!empty($monument['inscriptions_structures']) && is_array($monument['inscriptions_structures'])) {
        foreach ($monument['inscriptions_structures'] as $insc) {
            if (!empty($insc['photo'])) {
                $files[] = basename((string)$insc['photo']);
            }
        }
    }

    return array_values(array_unique(array_filter($files)));
}

$dataFile = __DIR__ . '/../json/monuments.json';
if (!is_dir(dirname($dataFile))) {
    mkdir(dirname($dataFile), 0755, true);
}

$fp = fopen($dataFile, 'c+');
if (!$fp || !flock($fp, LOCK_EX)) {
    if ($fp) {
        fclose($fp);
    }
    $_SESSION['flash_error'] = "Erreur : impossible de verrouiller le fichier de données.";
    header("Location: dashboard.php");
    exit;
}

$size = filesize($dataFile);
$rawJson = $size > 0 ? fread($fp, $size) : '[]';
$monuments = json_decode($rawJson, true);
if (!is_array($monuments)) {
    $monuments = [];
}

$foundIndex = null;
foreach ($monuments as $index => $m) {
    if ((int)($m['id'] ?? 0) === $id) {
        $foundIndex = $index;
        break;
    }
}

$filesToCleanup = [];
$hasModified = false;

if ($foundIndex !== null) {
    if (!$isAdmin && (int)($monuments[$foundIndex]['user_id'] ?? 0) !== $userId) {
        flock($fp, LOCK_UN);
        fclose($fp);
        $_SESSION['flash_error'] = "Vous n'avez pas l'autorisation d'agir sur cette fiche.";
        header("Location: dashboard.php");
        exit;
    }

    if ($permanent) {
        if (!$isAdmin) {
            $_SESSION['flash_error'] = "Seul un administrateur peut supprimer définitivement une fiche.";
        } else {
            $filesToCleanup = extractMonumentFiles($monuments[$foundIndex]);
            array_splice($monuments, $foundIndex, 1);
            $hasModified = true;
            $_SESSION['flash_success'] = "La fiche a été supprimée définitivement.";
        }
    } elseif ($publish) {
        $monuments[$foundIndex]['statut_publication'] = 'publie';
        $monuments[$foundIndex]['date_modification_notice'] = date('d/m/Y') . ' à ' . date('H:i:s');
        $hasModified = true;
        $_SESSION['flash_success'] = "La fiche a été publiée avec succès.";
    } elseif ($restore) {
        $monuments[$foundIndex]['deleted_at'] = null;
        unset($monuments[$foundIndex]['is_deleted']);
        $hasModified = true;
        $_SESSION['flash_success'] = "La fiche a été restaurée avec succès.";
    } else {
        $monuments[$foundIndex]['deleted_at'] = date('Y-m-d H:i:s');
        $hasModified = true;
        $_SESSION['flash_success'] = "La fiche a été mise en corbeille avec succès.";
    }

    if ($hasModified) {
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($monuments, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
} else {
    $_SESSION['flash_error'] = "Fiche inexistante.";
}

flock($fp, LOCK_UN);
fclose($fp);

// Nettoyage des fichiers devenus orphelins après suppression définitive
if (!empty($filesToCleanup)) {
    $stillReferenced = [];
    foreach ($monuments as $remainingMonument) {
        foreach (extractMonumentFiles($remainingMonument) as $refFile) {
            $stillReferenced[$refFile] = true;
        }
    }

    $uploadsDir = realpath(__DIR__ . '/../uploads');
    if ($uploadsDir) {
        foreach ($filesToCleanup as $file) {
            if (!isset($stillReferenced[$file])) {
                $targetPath = $uploadsDir . DIRECTORY_SEPARATOR . $file;
                if (is_file($targetPath)) {
                    @unlink($targetPath);
                }
            }
        }
    }
}

header("Location: dashboard.php");
exit;