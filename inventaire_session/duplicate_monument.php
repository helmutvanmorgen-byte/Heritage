<?php
session_start();
date_default_timezone_set('Europe/Paris');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/MonumentConfig.php';

if (!isset($_SESSION['user_id'], $_SESSION['user_role'])) {
    die("Non connecté.");
}

if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    die("Erreur CSRF.");
}

$userId       = (int)$_SESSION['user_id'];
$role         = $_SESSION['user_role'];
$sourceId     = (int)($_POST['id'] ?? 0);
$fieldsToCopy = $_POST['fields'] ?? [];
$copyImages   = !empty($_POST['copy_images']);

if (!$sourceId || (empty($fieldsToCopy) && !$copyImages)) {
    $_SESSION['flash_error'] = "Veuillez sélectionner au moins un élément à dupliquer.";
    header("Location: dashboard.php");
    exit;
}

$dataFile  = __DIR__ . '/../json/monuments.json';
$monuments = file_exists($dataFile) ? json_decode(file_get_contents($dataFile), true) : [];
if (!is_array($monuments)) {
    $monuments = [];
}

$sourceData = null;
foreach ($monuments as $m) {
    if ((int)($m['id'] ?? 0) === $sourceId) {
        $sourceData = $m;
        break;
    }
}

if (!$sourceData || ($role !== 'admin' && (int)$sourceData['user_id'] !== $userId)) {
    $_SESSION['flash_error'] = "Accès refusé ou fiche introuvable.";
    header("Location: dashboard.php");
    exit;
}

// Récupération du nom du site pour les mentions légales
$siteName = defined('SITE_NAME') ? SITE_NAME : 'Patrimoine Monumental';
if (!defined('SITE_NAME')) {
    $headerPath = __DIR__ . '/../includes/header.php';
    if (!file_exists($headerPath)) {
        $headerPath = __DIR__ . '/../header.php';
    }
    if (file_exists($headerPath)) {
        $headerContent = file_get_contents($headerPath);
        if (preg_match("/define\(\s*['\"]SITE_NAME['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $headerContent, $m)) {
            $siteName = $m[1];
        }
    }
}

// Champs système, médias et onglet GESTION à ne jamais recopier
$excludeFields = [
    'id', 'user_id', 'created_at', 'updated_at', 'deleted_at',
    'propositions', 'historique_versions', 'images', 'photos_payload',
    'numero_fiche', 'date_creation_notice', 'date_modification_notice',
    'redacteur_notice', 'statut_publication', 'mentions_legales'
];

$currentDateTime = date('d.m.Y') . ' à ' . date('H:i:s');

// Base propre issue de MonumentConfig pour garantir la compatibilité avec monument_form.php
$newData = MonumentConfig::getDefaultMonument();

// Copie de tous les champs sélectionnés dans preview_duplicate.php
foreach ($fieldsToCopy as $field) {
    if (in_array($field, $excludeFields, true)) {
        continue;
    }
    if (array_key_exists($field, $sourceData)) {
        $newData[$field] = $sourceData[$field];
    }
}

// Copie des photographies (onglet Photos : photos_payload + éventuelles images)
if ($copyImages) {
    $newData['images'] = is_array($sourceData['images'] ?? null) ? $sourceData['images'] : [];
    $srcPayload = is_array($sourceData['photos_payload'] ?? null) ? $sourceData['photos_payload'] : [];
    $newData['photos_payload'] = [
        'actuelles' => is_array($srcPayload['actuelles'] ?? null) ? $srcPayload['actuelles'] : [],
        'maquettes' => is_array($srcPayload['maquettes'] ?? null) ? $srcPayload['maquettes'] : [],
        'anciennes' => is_array($srcPayload['anciennes'] ?? null) ? $srcPayload['anciennes'] : []
    ];
} else {
    $newData['images'] = [];
    $newData['photos_payload'] = [
        'actuelles' => [],
        'maquettes' => [],
        'anciennes' => []
    ];
}

// Normalisation des tableaux structurés pour monument_form.php
$newData['auteurs_structures']      = is_array($newData['auteurs_structures'] ?? null) ? $newData['auteurs_structures'] : [];
$newData['documentation_zotero']    = is_array($newData['documentation_zotero'] ?? null) ? $newData['documentation_zotero'] : [];
$newData['inscriptions_structures'] = is_array($newData['inscriptions_structures'] ?? null) ? $newData['inscriptions_structures'] : [];
$newData['linked_monuments']        = is_array($newData['linked_monuments'] ?? null) ? $newData['linked_monuments'] : [];

// Si aucun titre n'a été copié, indiquer clairement qu'il s'agit d'une copie
if (empty($newData['titre']) || (is_array($newData['titre']) && trim((string)($newData['titre'][0] ?? '')) === '')) {
    $origTitre = is_array($sourceData['titre'] ?? null)
        ? ($sourceData['titre'][0] ?? 'Sans titre')
        : ($sourceData['titre'] ?? 'Sans titre');
    $newData['titre'] = [$origTitre . ' (Copie)'];
}

// Génération d'un nouvel identifiant unique
$maxId = 0;
foreach ($monuments as $m) {
    if ((int)($m['id'] ?? 0) > $maxId) {
        $maxId = (int)$m['id'];
    }
}

// Écriture des métadonnées système et de l'onglet GESTION
$newData['id']                       = $maxId + 1;
$newData['user_id']                  = $userId;
$newData['date_creation_notice']     = $currentDateTime;
$newData['date_modification_notice'] = $currentDateTime;
$newData['redacteur_notice']         = $_SESSION['username'] ?? '';
$newData['statut_publication']       = 'brouillon';
$newData['numero_fiche']             = 'REF-' . date('Y') . '-' . strtoupper(substr(uniqid(), -5));
$newData['mentions_legales']         = $siteName . ' - ' . date('Y');
$newData['deleted_at']               = null;

$monuments[] = $newData;

file_put_contents($dataFile, json_encode($monuments, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

$_SESSION['flash_success'] = "Fiche dupliquée avec succès (Réf : " . $newData['numero_fiche'] . ").";
header("Location: dashboard.php");
exit;