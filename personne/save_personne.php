<?php
require_once __DIR__ . '/personne_config.php';
require_once __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['user_id'], $_SESSION['user_role'])) {
    die('Non autorisé.');
}

if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    die('Erreur de sécurité : Jeton CSRF invalide.');
}

$userId     = (int) $_SESSION['user_id'];
$role       = $_SESSION['user_role'];
$id         = isset($_POST['id']) && $_POST['id'] !== '' ? (int) $_POST['id'] : null;
$saveAction = $_POST['save_action'] ?? 'back';

[$type, $cfg] = resolvePersonneType($_POST['type'] ?? null, $typesConfig);

$nom        = trim($_POST['nom'] ?? '');
$prenom     = trim($_POST['prenom'] ?? '');
$sexe       = trim($_POST['sexe'] ?? '');
$biographie = trim($_POST['biographie'] ?? '');
$permalien  = trim($_POST['permalien'] ?? '');

if (!in_array($sexe, ['', 'Homme', 'Femme'], true)) {
    $sexe = '';
}

$naissance = [
    'annee' => trim($_POST['naissance']['annee'] ?? ''),
    'lieu'  => trim($_POST['naissance']['lieu'] ?? ''),
    'pays'  => trim($_POST['naissance']['pays'] ?? '')
];

$deces = [
    'annee' => trim($_POST['deces']['annee'] ?? ''),
    'lieu'  => trim($_POST['deces']['lieu'] ?? ''),
    'pays'  => trim($_POST['deces']['pays'] ?? '')
];

// Conserver les données saisies en cas d'erreur de validation
$_SESSION['old_input'] = [
    'type'       => $type,
    'id'         => $id,
    'nom'        => $nom,
    'prenom'     => $prenom,
    'sexe'       => $sexe,
    'naissance'  => $naissance,
    'deces'      => $deces,
    'biographie' => $biographie,
    'permalien'  => $permalien,
];

if ($nom === '') {
    $_SESSION['flash_error'] = 'Le nom du ' . $cfg['label'] . ' est obligatoire.';
    header('Location: personne_form.php?type=' . urlencode($type) . ($id ? '&id=' . $id : ''));
    exit;
}

$dataFile = __DIR__ . '/../json/' . $cfg['json'];
$allItems = file_exists($dataFile) ? json_decode(file_get_contents($dataFile), true) : [];
if (!is_array($allItems)) {
    $allItems = [];
}

$now = date('d/m/Y à H:i:s');
$savedId = $id;

if ($id) {
    $found = false;
    foreach ($allItems as &$item) {
        if ((int)($item['id'] ?? 0) === $id) {
            if ($role !== 'admin' && (int)($item['user_id'] ?? 0) !== $userId) {
                unset($_SESSION['old_input']);
                $_SESSION['flash_error'] = 'Autorisation refusée.';
                header('Location: ' . ($_SESSION['last_dashboard_url_by_type'][$type] ?? 'dashboard.php?type=' . urlencode($type)));
                exit;
            }

            // Mise à jour ciblée : toutes les autres clés initiales (id, numero_fiche, date_creation_notice, user_id, etc.) sont préservées
            $item['nom']                      = $nom;
            $item['prenom']                   = $prenom;
            $item['sexe']                     = $sexe;
            $item['naissance']                = array_merge($item['naissance'] ?? [], $naissance);
            $item['deces']                    = array_merge($item['deces'] ?? [], $deces);
            $item['biographie']               = $biographie;
            $item['permalien']                = $permalien;
            $item['date_modification_notice'] = $now;
            $found = true;
            break;
        }
    }
    unset($item);

    if (!$found) {
        unset($_SESSION['old_input']);
        $_SESSION['flash_error'] = ucfirst($cfg['label']) . ' introuvable.';
        header('Location: ' . ($_SESSION['last_dashboard_url_by_type'][$type] ?? 'dashboard.php?type=' . urlencode($type)));
        exit;
    }

    $_SESSION['flash_success'] = 'Fiche ' . $cfg['label'] . ' mise à jour avec succès.';
} else {
    $maxId = 0;
    foreach ($allItems as $item) {
        if (isset($item['id']) && (int)$item['id'] > $maxId) {
            $maxId = (int)$item['id'];
        }
    }

    $savedId = $maxId + 1;
    $allItems[] = [
        'id'                       => $savedId,
        'nom'                      => $nom,
        'prenom'                   => $prenom,
        'sexe'                     => $sexe,
        'naissance'                => $naissance,
        'deces'                    => $deces,
        'biographie'               => $biographie,
        'permalien'                => $permalien,
        'numero_fiche'             => $cfg['prefix'] . '-' . date('Y') . '-' . strtoupper(substr(uniqid(), -5)),
        'date_creation_notice'     => $now,
        'date_modification_notice' => $now,
        'user_id'                  => $userId
    ];

    $_SESSION['flash_success'] = 'Nouveau ' . $cfg['label'] . ' créé avec succès.';
}

file_put_contents($dataFile, json_encode($allItems, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

// Enregistrement réussi : on nettoie le brouillon de session
unset($_SESSION['old_input']);

if ($saveAction === 'stay') {
    header('Location: personne_form.php?type=' . urlencode($type) . '&id=' . (int)$savedId . '&saved=1');
    exit;
}

$redirectUrl = $_SESSION['last_dashboard_url_by_type'][$type] ?? ('dashboard.php?type=' . urlencode($type));
header('Location: ' . $redirectUrl);
exit;