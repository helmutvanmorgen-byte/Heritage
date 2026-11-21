<?php
session_start();
date_default_timezone_set('Europe/Paris');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/ThesaurusManager.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Session expirée ou non connectée.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée.']);
    exit;
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Jeton de sécurité CSRF invalide.']);
    exit;
}

function normalizeMetierSlugProp(string $metier): string
{
    $normalized = mb_strtolower(trim($metier), 'UTF-8');
    $ascii = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized) : $normalized;
    $slug = preg_replace('/[^a-z0-9]+/', '_', $ascii ?: $normalized);
    return trim($slug, '_');
}

function isDetailedPersonMetierProp(string $metier): bool
{
    $slug = normalizeMetierSlugProp($metier);
    return in_array($slug, ['architecte', 'medailleur', 'sculpteur'], true);
}

function getMetierJsonFilenameProp(string $metier): string
{
    $map = [
        'sculpteur'    => 'sculpteurs.json',
        'architecte'   => 'architectes.json',
        'fondeur'      => 'fondeurs.json',
        'medailleur'   => 'medailleurs.json',
        'entrepreneur' => 'entrepreneurs.json',
        'marbrier'     => 'marbriers.json',
    ];

    $slug = normalizeMetierSlugProp($metier);
    if (isset($map[$slug])) {
        return $map[$slug];
    }
    return ($slug !== '' ? $slug . 's' : 'autres_auteurs') . '.json';
}

$jsonDir       = __DIR__ . '/../json';
$proposalsFile = $jsonDir . '/thesaurus_propositions.json';

if (!is_dir($jsonDir)) {
    mkdir($jsonDir, 0755, true);
}

$proposals = [];
if (file_exists($proposalsFile)) {
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($proposalsFile));
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $proposals = $decoded;
    }
}

$proposalType = trim($_POST['proposal_type'] ?? 'term');

// =========================================================================
// CAS 1 : PROPOSITION D'UNE FICHE DÉVELOPPÉE D'AUTEUR (MÉTIER)
// =========================================================================
if ($proposalType === 'person') {
    $metier     = trim($_POST['metier'] ?? '');
    $nom        = trim($_POST['nom'] ?? '');
    $prenom     = trim($_POST['prenom'] ?? '');
    $biographie = trim($_POST['biographie'] ?? '');
    $source     = trim($_POST['source'] ?? '');

    if ($metier === '') {
        echo json_encode(['success' => false, 'message' => 'Veuillez d\'abord sélectionner un métier.']);
        exit;
    }
    if ($nom === '') {
        echo json_encode(['success' => false, 'message' => 'Le nom de l\'auteur est obligatoire.']);
        exit;
    }

    $isDetailed = isDetailedPersonMetierProp($metier);

    if ($isDetailed) {
        $sexe           = trim($_POST['sexe'] ?? '');
        $naissanceAnnee = trim($_POST['naissance_annee'] ?? '');
        $naissanceLieu  = trim($_POST['naissance_lieu'] ?? '');
        $naissancePays  = trim($_POST['naissance_pays'] ?? '');
        $decesAnnee     = trim($_POST['deces_annee'] ?? '');
        $decesLieu      = trim($_POST['deces_lieu'] ?? '');
        $decesPays      = trim($_POST['deces_pays'] ?? '');

        $personData = [
            'nom'             => $nom,
            'prenom'          => $prenom,
            'sexe'            => $sexe,
            'naissance_annee' => $naissanceAnnee,
            'naissance_lieu'  => $naissanceLieu,
            'naissance_pays'  => $naissancePays,
            'deces_annee'     => $decesAnnee,
            'deces_lieu'      => $decesLieu,
            'deces_pays'      => $decesPays,
            'biographie'      => $biographie,
            'source'          => $source,
        ];
    } else {
        $dates = trim($_POST['dates'] ?? '');
        $lieu  = trim($_POST['lieu'] ?? '');

        $personData = [
            'nom'        => $nom,
            'prenom'     => $prenom,
            'dates'      => $dates,
            'lieu'       => $lieu,
            'biographie' => $biographie,
            'source'     => $source,
        ];
    }

    $filename   = getMetierJsonFilenameProp($metier);
    $metierFile = $jsonDir . '/' . $filename;
    $fullName   = trim($nom . ' ' . $prenom);

    // Vérifier si l'auteur existe déjà dans le JSON officiel du métier
    if (file_exists($metierFile)) {
        $rawM = preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($metierFile));
        $existingPersons = json_decode($rawM, true);
        if (is_array($existingPersons)) {
            foreach ($existingPersons as $ep) {
                if (is_array($ep)) {
                    $eNom    = trim((string)($ep['nom'] ?? ''));
                    $ePrenom = trim((string)($ep['prenom'] ?? ''));
                    if (mb_strtolower($eNom, 'UTF-8') === mb_strtolower($nom, 'UTF-8')
                        && mb_strtolower($ePrenom, 'UTF-8') === mb_strtolower($prenom, 'UTF-8')) {
                        echo json_encode([
                            'success' => false,
                            'message' => 'Cet auteur (« ' . $fullName . ' ») figure déjà dans la liste des ' . $metier . 's.'
                        ]);
                        exit;
                    }
                }
            }
        }
    }

    // Vérifier si une proposition identique est déjà en attente
    foreach ($proposals as $prop) {
        if (($prop['proposal_type'] ?? 'term') === 'person'
            && mb_strtolower((string)($prop['metier'] ?? ''), 'UTF-8') === mb_strtolower($metier, 'UTF-8')) {
            $pData = $prop['person_data'] ?? [];
            if (mb_strtolower(trim((string)($pData['nom'] ?? '')), 'UTF-8') === mb_strtolower($nom, 'UTF-8')
                && mb_strtolower(trim((string)($pData['prenom'] ?? '')), 'UTF-8') === mb_strtolower($prenom, 'UTF-8')) {
                echo json_encode([
                    'success'        => true,
                    'already_queued' => true,
                    'full_name'      => $fullName,
                    'message'        => 'Cet auteur est déjà en attente de validation par un administrateur.'
                ]);
                exit;
            }
        }
    }

    $proposals[] = [
        'id'              => uniqid('prop_', true),
        'proposal_type'   => 'person',
        'person_template' => $isDetailed ? 'detailed' : 'standard',
        'metier'          => $metier,
        'target_file'     => $filename,
        'list_label'      => 'Auteur — ' . $metier,
        'person_data'     => $personData,
        'user_id'         => (int)$_SESSION['user_id'],
        'username'        => $_SESSION['username'] ?? 'Utilisateur #' . (int)$_SESSION['user_id'],
        'created_at'      => date('d/m/Y à H:i'),
    ];

    if (file_put_contents($proposalsFile, json_encode($proposals, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Erreur lors de l\'enregistrement de la proposition.']);
        exit;
    }

    echo json_encode([
        'success'   => true,
        'full_name' => $fullName,
        'message'   => 'Fiche auteur envoyée à l\'administrateur pour validation.'
    ]);
    exit;
}

// =========================================================================
// CAS 2 : PROPOSITION D'UN TERME SIMPLE DU THÉSAURUS
// =========================================================================
$allowedLists = [
    'pays'                         => 'Pays',
    'departementsFrance'           => 'Départements (France)',
    'wilayas'                      => 'Wilayas (Algérie)',
    'typesLocalisation'            => 'Types de localisation',
    'departementsAlgerieAvant1962' => 'Départements (Algérie av. 1962)',
    'typesOeuvre'                  => 'Types d’œuvre',
    'materiaux'                    => 'Matériaux',
    'techniques'                   => 'Techniques',
    'typesInscriptions'            => 'Types d\'inscriptions',
    'metiers'                      => 'Liste des métiers',
    'partiesList'                  => 'Parties du monument',
    'typesDocumentation'           => 'Types de documentation',
    'typesDocumentPhoto'           => 'Types de document (Photos)',
];

$listKey = trim($_POST['list_key'] ?? '');
$term    = trim($_POST['term'] ?? '');

if (!isset($allowedLists[$listKey])) {
    echo json_encode(['success' => false, 'message' => 'Liste cible non reconnue.']);
    exit;
}

if ($term === '') {
    echo json_encode(['success' => false, 'message' => 'Veuillez saisir un terme valide.']);
    exit;
}

$thesaurusFile = $jsonDir . '/thesaurus.json';
$targetFile    = ($listKey === 'wilayas') ? ($jsonDir . '/wilayas.json') : $thesaurusFile;
$targetKey     = ($listKey === 'wilayas') ? null : $listKey;

$existingTerms = ThesaurusManager::getList($targetFile, $targetKey);
foreach ($existingTerms as $existing) {
    if (mb_strtolower(trim((string)$existing), 'UTF-8') === mb_strtolower($term, 'UTF-8')) {
        echo json_encode([
            'success' => false,
            'message' => 'Ce terme existe déjà dans la liste « ' . $allowedLists[$listKey] . ' ».'
        ]);
        exit;
    }
}

foreach ($proposals as $prop) {
    if (($prop['proposal_type'] ?? 'term') === 'term'
        && ($prop['list_key'] ?? '') === $listKey
        && mb_strtolower(trim((string)($prop['term'] ?? '')), 'UTF-8') === mb_strtolower($term, 'UTF-8')) {
        echo json_encode([
            'success'        => true,
            'already_queued' => true,
            'term'           => $prop['term'],
            'message'        => 'Ce terme est déjà en attente de validation par un administrateur.'
        ]);
        exit;
    }
}

$proposals[] = [
    'id'            => uniqid('prop_', true),
    'proposal_type' => 'term',
    'list_key'      => $listKey,
    'list_label'    => $allowedLists[$listKey],
    'term'          => $term,
    'user_id'       => (int)$_SESSION['user_id'],
    'username'      => $_SESSION['username'] ?? 'Utilisateur #' . (int)$_SESSION['user_id'],
    'created_at'    => date('d/m/Y à H:i'),
];

if (file_put_contents($proposalsFile, json_encode($proposals, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur lors de l\'enregistrement de la proposition.']);
    exit;
}

echo json_encode([
    'success' => true,
    'term'    => $term,
    'message' => 'Proposition envoyée. Elle sera intégrée après validation par un administrateur.'
]);