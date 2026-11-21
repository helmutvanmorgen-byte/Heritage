<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Europe/Paris');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function normalizePersonneSlug(string $value): string
{
    $normalized = mb_strtolower(trim($value), 'UTF-8');
    $ascii = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized) : $normalized;
    $slug = preg_replace('/[^a-z0-9]+/', '_', $ascii ?: $normalized);
    $slug = trim($slug, '_');
    return rtrim($slug, 's');
}

$defaultMetiers = ['Sculpteur', 'Architecte', 'Fondeur', 'Médailleur', 'Entrepreneur', 'Marbrier'];

$thesaurusPath = __DIR__ . '/../json/thesaurus.json';
$metiersList = $defaultMetiers;

if (file_exists($thesaurusPath)) {
    $rawThesaurus = preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($thesaurusPath));
    $thesaurusData = json_decode($rawThesaurus, true);
    if (is_array($thesaurusData['metiers'] ?? null) && !empty($thesaurusData['metiers'])) {
        $metiersList = $thesaurusData['metiers'];
    }
}

$typesConfig = [];
$uniqueSlugs = [];

foreach ($metiersList as $metierLabel) {
    $metierLabel = trim((string)$metierLabel);
    if ($metierLabel === '') {
        continue;
    }
    
    $slug = normalizePersonneSlug($metierLabel);
    $baseKey = rtrim($slug, 's');
    if ($baseKey === 'medaille') {
        $baseKey = 'medailleur';
    }

    if ($baseKey !== '' && !isset($uniqueSlugs[$baseKey])) {
        $uniqueSlugs[$baseKey] = true;
        
        $plural = preg_match('/[sxz]$/i', $metierLabel) ? $metierLabel : $metierLabel . 's';
        $prefix = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $baseKey), 0, 5)) ?: 'PERS';
        
        $typesConfig[$baseKey] = [
            'json'   => $baseKey . 's.json',
            'prefix' => $prefix,
            'label'  => mb_strtolower($metierLabel, 'UTF-8'),
            'onglet' => $plural,
            'titre'  => 'Fiches des ' . mb_strtolower($plural, 'UTF-8')
        ];
    }
}

$jsonBaseDir = __DIR__ . '/../json';
if (!is_dir($jsonBaseDir)) {
    mkdir($jsonBaseDir, 0755, true);
}
foreach ($typesConfig as $cfgItem) {
    $targetJson = $jsonBaseDir . '/' . $cfgItem['json'];
    if (!file_exists($targetJson)) {
        file_put_contents($targetJson, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
}

function resolvePersonneType(?string $requestedType, array $typesConfig): array
{
    $slug = $requestedType !== null ? normalizePersonneSlug($requestedType) : '';
    $baseKey = rtrim($slug, 's');
    if ($baseKey === 'medaille') {
        $baseKey = 'medailleur';
    }
    
    $type = ($baseKey !== '' && array_key_exists($baseKey, $typesConfig))
        ? $baseKey
        : array_key_first($typesConfig);

    return [$type, $typesConfig[$type]];
}