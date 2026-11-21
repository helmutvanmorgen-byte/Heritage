<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['user_id'], $_SESSION['user_role'])) {
    header('Location: /login.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$userId  = (int) $_SESSION['user_id'];
$role    = (string) $_SESSION['user_role'];
$isAdmin = in_array(mb_strtolower(trim($role), 'UTF-8'), ['admin', 'administrateur'], true);

$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError   = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Paramètres de filtrage, tri et pagination
$search        = trim((string)($_GET['search'] ?? ''));
$deletedFilter = (string)($_GET['deleted_filter'] ?? '0');
if (!in_array($deletedFilter, ['0', '1', 'all'], true)) {
    $deletedFilter = '0';
}

$statusFilter = (string)($_GET['status_filter'] ?? 'all');
if (!in_array($statusFilter, ['all', 'publie', 'brouillon', 'propositions'], true)) {
    $statusFilter = 'all';
}

$typeFilter = trim((string)($_GET['type_filter'] ?? ''));

$sort = (string)($_GET['sort'] ?? 'recent');
if (!in_array($sort, ['recent', 'oldest', 'updated', 'title_asc', 'title_desc', 'ref_asc'], true)) {
    $sort = 'recent';
}

$itemsPerPage = (int)($_GET['per_page'] ?? 12);
if (!in_array($itemsPerPage, [12, 24, 48, 96], true)) {
    $itemsPerPage = 12;
}

$page = max(1, (int)($_GET['page'] ?? 1));

// Chargement des données
$dataFile     = __DIR__ . '/../json/monuments.json';
$allMonuments = file_exists($dataFile) ? json_decode(file_get_contents($dataFile), true) : [];
if (!is_array($allMonuments)) {
    $allMonuments = [];
}

// Statistiques globales & types disponibles (pour l'utilisateur courant)
$stats = [
    'actifs'       => 0,
    'publies'      => 0,
    'brouillons'   => 0,
    'propositions' => 0,
    'corbeille'    => 0,
];
$availableTypes = [];
$accessibleMonuments = [];

foreach ($allMonuments as $index => $m) {
    // Cloisonnement par propriétaire pour les non-administrateurs
    if (!$isAdmin && (int)($m['user_id'] ?? 0) !== $userId) {
        continue;
    }

    $isDeleted = (!empty($m['deleted_at']) && $m['deleted_at'] !== '0000-00-00 00:00:00' && $m['deleted_at'] !== 'null')
        || !empty($m['is_deleted'])
        || (($m['statut_publication'] ?? '') === 'supprime');

    $statutKey = $m['statut_publication'] ?? 'brouillon';
    $hasProps  = !empty($m['propositions']) && is_array($m['propositions']) && !$isDeleted;

    if ($isDeleted) {
        $stats['corbeille']++;
    } else {
        $stats['actifs']++;
        if ($statutKey === 'publie') {
            $stats['publies']++;
        } else {
            $stats['brouillons']++;
        }
        if ($hasProps) {
            $stats['propositions']++;
        }
    }

    // Collecte des types d'œuvres existants
    if (!empty($m['type_oeuvre'])) {
        $types = is_array($m['type_oeuvre']) ? $m['type_oeuvre'] : [$m['type_oeuvre']];
        foreach ($types as $t) {
            $tClean = trim((string)$t);
            if ($tClean !== '') {
                $availableTypes[$tClean] = true;
            }
        }
    }

    $m['_original_index']      = $index;
    $m['_is_deleted_resolved'] = $isDeleted;
    $m['_has_propositions']    = $hasProps;
    $accessibleMonuments[]     = $m;
}

$availableTypes = array_keys($availableTypes);
natcasesort($availableTypes);

// Filtrage
$monumentsList = [];
foreach ($accessibleMonuments as $m) {
    $isDeleted = $m['_is_deleted_resolved'];
    $statutKey = $m['statut_publication'] ?? 'brouillon';

    if ($deletedFilter === '0' && $isDeleted) {
        continue;
    }
    if ($deletedFilter === '1' && !$isDeleted) {
        continue;
    }

    if ($statusFilter === 'publie' && $statutKey !== 'publie') {
        continue;
    }
    if ($statusFilter === 'brouillon' && $statutKey !== 'brouillon') {
        continue;
    }
    if ($statusFilter === 'propositions' && empty($m['_has_propositions'])) {
        continue;
    }

    if ($typeFilter !== '') {
        $mTypes = is_array($m['type_oeuvre'] ?? null)
            ? array_map('trim', $m['type_oeuvre'])
            : [trim((string)($m['type_oeuvre'] ?? ''))];
        if (!in_array($typeFilter, $mTypes, true)) {
            continue;
        }
    }

    if ($search !== '') {
        $jsonString = mb_strtolower(json_encode($m, JSON_UNESCAPED_UNICODE), 'UTF-8');
        if (mb_strpos($jsonString, mb_strtolower($search, 'UTF-8')) === false) {
            continue;
        }
    }

    $monumentsList[] = $m;
}

// Tri
usort($monumentsList, static function (array $a, array $b) use ($sort): int {
    $getTitle = static function (array $item): string {
        if (!empty($item['titre']) && is_array($item['titre'])) {
            return trim((string)($item['titre'][0] ?? ''));
        }
        return trim((string)($item['titre'] ?? ''));
    };

    switch ($sort) {
        case 'oldest':
            return $a['_original_index'] <=> $b['_original_index'];

        case 'updated':
            $dateA = (string)($a['date_modification_notice'] ?? $a['date_creation_notice'] ?? '');
            $dateB = (string)($b['date_modification_notice'] ?? $b['date_creation_notice'] ?? '');
            return strcmp($dateB, $dateA) ?: ($b['_original_index'] <=> $a['_original_index']);

        case 'title_asc':
            return strnatcasecmp($getTitle($a), $getTitle($b));

        case 'title_desc':
            return strnatcasecmp($getTitle($b), $getTitle($a));

        case 'ref_asc':
            return strnatcasecmp((string)($a['numero_fiche'] ?? ''), (string)($b['numero_fiche'] ?? ''));

        case 'recent':
        default:
            return $b['_original_index'] <=> $a['_original_index'];
    }
});

$totalItems = count($monumentsList);
$totalPages = max(1, (int)ceil($totalItems / $itemsPerPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $itemsPerPage;
$monuments  = array_slice($monumentsList, $offset, $itemsPerPage);

// Helper d'URL conservant les paramètres actifs
$buildQueryUrl = static function (array $overrides = []) use ($search, $deletedFilter, $statusFilter, $typeFilter, $sort, $itemsPerPage, $page): string {
    $params = array_merge([
        'search'         => $search,
        'deleted_filter' => $deletedFilter,
        'status_filter'  => $statusFilter,
        'type_filter'    => $typeFilter,
        'sort'           => $sort,
        'per_page'       => $itemsPerPage,
        'page'           => $page,
    ], $overrides);

    $cleaned = [];
    foreach ($params as $k => $v) {
        if ($v === '' || $v === null) {
            continue;
        }
        if ($k === 'deleted_filter' && $v === '0') continue;
        if ($k === 'status_filter' && $v === 'all') continue;
        if ($k === 'sort' && $v === 'recent') continue;
        if ($k === 'per_page' && (int)$v === 12) continue;
        if ($k === 'page' && (int)$v === 1) continue;
        $cleaned[$k] = $v;
    }

    return 'dashboard.php' . (!empty($cleaned) ? '?' . http_build_query($cleaned) : '');
};

// Formatage propre des dates (gère "21/11/2026 à 09:56:27" et formats ISO)
$formatDateShort = static function (string $rawDate): string {
    $rawDate = trim($rawDate);
    if ($rawDate === '') {
        return '';
    }
    if (preg_match('/^(\d{2}\/\d{2}\/\d{4})/', $rawDate, $matches)) {
        return $matches[1];
    }
    try {
        $dt = new DateTime($rawDate);
        return $dt->format('d/m/Y');
    } catch (Exception $e) {
        return $rawDate;
    }
};

// Préparation des données d'affichage pour chaque notice
$preparedMonuments = [];
$statutLabels = [
    'publie'    => ['label' => 'Publié',    'class' => 'badge-publie'],
    'brouillon' => ['label' => 'Brouillon', 'class' => 'badge-brouillon'],
];

foreach ($monuments as $m) {
    if (!empty($m['titre']) && is_array($m['titre'])) {
        $titreAffiche = trim((string)($m['titre'][0] ?? '')) !== '' ? $m['titre'][0] : 'Sans titre';
    } else {
        $titreAffiche = trim((string)($m['titre'] ?? '')) !== '' ? (string)$m['titre'] : 'Sans titre';
    }

    $primaryImage = '';
    if (!empty($m['images']) && is_array($m['images'])) {
        foreach ($m['images'] as $img) {
            if (!empty($img['primary']) && !empty($img['file'])) {
                $primaryImage = $img['file'];
                break;
            }
        }
        if (empty($primaryImage) && !empty($m['images'][0]['file'])) {
            $primaryImage = $m['images'][0]['file'];
        }
    } elseif (!empty($m['photos_payload']) && is_array($m['photos_payload'])) {
        foreach (['actuelles', 'maquettes', 'anciennes'] as $sec) {
            if (!empty($m['photos_payload'][$sec][0]['file'])) {
                $primaryImage = $m['photos_payload'][$sec][0]['file'];
                break;
            }
        }
    }

    $commune = is_array($m['communes_actuelles'] ?? null)
        ? ($m['communes_actuelles'][0] ?? '')
        : (string)($m['communes_actuelles'] ?? '');
    $dept = !empty($m['departement_france'])
        ? $m['departement_france']
        : ($m['wilaya_algerie'] ?? ($m['pays'] ?? ''));
    $localisation = implode(' • ', array_filter([$commune, $dept]));

    $typeOeuvre = is_array($m['type_oeuvre'] ?? null)
        ? implode(', ', array_filter($m['type_oeuvre']))
        : (string)($m['type_oeuvre'] ?? '');

    $statutKey  = $m['statut_publication'] ?? 'brouillon';
    $statutInfo = $statutLabels[$statutKey] ?? $statutLabels['brouillon'];
    $isDeleted  = !empty($m['_is_deleted_resolved']);
    $rawDate    = (string)($m['date_modification_notice'] ?? ($m['date_creation_notice'] ?? ''));

    $preparedMonuments[] = [
        'id'           => (int)$m['id'],
        'titre'        => $titreAffiche,
        'numero_fiche' => (string)($m['numero_fiche'] ?? ''),
        'image_url'    => !empty($primaryImage) ? '/uploads/' . htmlspecialchars($primaryImage, ENT_QUOTES, 'UTF-8') : '/img/no-photo.webp',
        'localisation' => $localisation,
        'type_oeuvre'  => $typeOeuvre,
        'date_maj'     => $formatDateShort($rawDate),
        'date_full'    => $rawDate,
        'statut_key'   => $statutKey,
        'statut_label' => $statutInfo['label'],
        'statut_class' => $statutInfo['class'],
        'is_deleted'   => $isDeleted,
        'can_restore'  => $isAdmin || ((int)($m['user_id'] ?? 0) === $userId),
        'propositions' => (!empty($m['propositions']) && !$isDeleted && ($isAdmin || (int)($m['user_id'] ?? 0) === $userId))
            ? $m['propositions']
            : [],
    ];
}

$hasActiveFilters = ($search !== '' || $deletedFilter !== '0' || $statusFilter !== 'all' || $typeFilter !== '' || $sort !== 'recent');

ob_start();
?>
<div class="content-3 container dashboard-wrapper">
    <div class="dashboard-header-top">
        <div class="dashboard-title-group">
            <h2><?= $deletedFilter === '1' ? 'Corbeille' : 'Mes fiches d\'inventaire' ?></h2>
            <span class="dashboard-count">
                <?= $totalItems ?> fiche<?= $totalItems > 1 ? 's' : '' ?> affichée<?= $totalItems > 1 ? 's' : '' ?>
                <?php if ($totalPages > 1): ?>
                    — Page <?= $page ?> sur <?= $totalPages ?>
                <?php endif; ?>
            </span>
        </div>

        <div class="dashboard-header-actions">
            <div class="view-switcher" role="group" aria-label="Mode d'affichage">
                <button type="button" class="view-btn active" data-view="grid" onclick="setDashboardView('grid')" title="Affichage en grille de cartes">
                    ⊞ Grille
                </button>
                <button type="button" class="view-btn" data-view="list" onclick="setDashboardView('list')" title="Affichage en ligne détaillée">
                    ☰ En ligne
                </button>
                <button type="button" class="view-btn" data-view="table" onclick="setDashboardView('table')" title="Affichage en tableau compact">
                    ▤ Tableau
                </button>
            </div>

            <a href="monument_form.php" class="btn btn-primary">➕ Nouvelle fiche</a>
        </div>
    </div>

    <?php if ($flashSuccess): ?>
        <div class="alert alert-success"><?= htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($flashError): ?>
        <div class="alert alert-error"><?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <!-- Compteurs rapides cliquables -->
    <div class="dashboard-kpi-bar">
        <a href="<?= htmlspecialchars($buildQueryUrl(['deleted_filter' => '0', 'status_filter' => 'all', 'page' => 1]), ENT_QUOTES, 'UTF-8') ?>"
           class="kpi-card <?= ($deletedFilter === '0' && $statusFilter === 'all') ? 'is-active' : '' ?>">
            <span class="kpi-label">Fiches actives</span>
            <span class="kpi-value"><?= $stats['actifs'] ?></span>
        </a>

        <a href="<?= htmlspecialchars($buildQueryUrl(['deleted_filter' => '0', 'status_filter' => 'publie', 'page' => 1]), ENT_QUOTES, 'UTF-8') ?>"
           class="kpi-card <?= ($deletedFilter === '0' && $statusFilter === 'publie') ? 'is-active' : '' ?>">
            <span class="kpi-label">Publiées</span>
            <span class="kpi-value"><?= $stats['publies'] ?></span>
        </a>

        <a href="<?= htmlspecialchars($buildQueryUrl(['deleted_filter' => '0', 'status_filter' => 'brouillon', 'page' => 1]), ENT_QUOTES, 'UTF-8') ?>"
           class="kpi-card <?= ($deletedFilter === '0' && $statusFilter === 'brouillon') ? 'is-active' : '' ?>">
            <span class="kpi-label">Brouillons</span>
            <span class="kpi-value"><?= $stats['brouillons'] ?></span>
        </a>

        <?php if ($stats['propositions'] > 0 || $statusFilter === 'propositions'): ?>
            <a href="<?= htmlspecialchars($buildQueryUrl(['deleted_filter' => '0', 'status_filter' => 'propositions', 'page' => 1]), ENT_QUOTES, 'UTF-8') ?>"
               class="kpi-card kpi-warning <?= ($deletedFilter === '0' && $statusFilter === 'propositions') ? 'is-active' : '' ?>">
                <span class="kpi-label">Propositions</span>
                <span class="kpi-value"><?= $stats['propositions'] ?></span>
            </a>
        <?php endif; ?>

        <a href="<?= htmlspecialchars($buildQueryUrl(['deleted_filter' => '1', 'status_filter' => 'all', 'page' => 1]), ENT_QUOTES, 'UTF-8') ?>"
           class="kpi-card <?= $deletedFilter === '1' ? 'is-active' : '' ?>">
            <span class="kpi-label">Corbeille</span>
            <span class="kpi-value"><?= $stats['corbeille'] ?></span>
        </a>
    </div>

    <!-- Barre de recherche, filtres et tri sur une ligne -->
    <div class="dashboard-toolbar">
        <form method="get" action="dashboard.php" class="dashboard-filters-grid">
            <div class="search-input-wrap">
                <input type="text"
                       id="dashboard-search-input"
                       name="search"
                       value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="Rechercher un titre, une réf., un lieu..."
                       aria-label="Rechercher">
                <span class="search-kbd-hint" title="Appuyez sur / pour rechercher">/</span>
            </div>

            <select name="status_filter" class="filter-select" onchange="this.form.submit()" aria-label="Filtrer par statut">
                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>Tous les statuts</option>
                <option value="publie" <?= $statusFilter === 'publie' ? 'selected' : '' ?>>Publiés</option>
                <option value="brouillon" <?= $statusFilter === 'brouillon' ? 'selected' : '' ?>>Brouillons</option>
                <option value="propositions" <?= $statusFilter === 'propositions' ? 'selected' : '' ?>>Avec propositions</option>
            </select>

            <?php if (!empty($availableTypes)): ?>
                <select name="type_filter" class="filter-select" onchange="this.form.submit()" aria-label="Filtrer par type d'œuvre">
                    <option value="">Tous les types</option>
                    <?php foreach ($availableTypes as $typeOption): ?>
                        <option value="<?= htmlspecialchars($typeOption, ENT_QUOTES, 'UTF-8') ?>" <?= $typeFilter === $typeOption ? 'selected' : '' ?>>
                            <?= htmlspecialchars($typeOption, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>

            <select name="deleted_filter" class="filter-select" onchange="this.form.submit()" aria-label="Filtrer par état">
                <option value="0" <?= $deletedFilter === '0' ? 'selected' : '' ?>>Actifs</option>
                <option value="1" <?= $deletedFilter === '1' ? 'selected' : '' ?>>Corbeille</option>
                <option value="all" <?= $deletedFilter === 'all' ? 'selected' : '' ?>>Actifs + Corbeille</option>
            </select>

            <select name="sort" class="filter-select" onchange="this.form.submit()" aria-label="Trier par">
                <option value="recent" <?= $sort === 'recent' ? 'selected' : '' ?>>Plus récentes</option>
                <option value="updated" <?= $sort === 'updated' ? 'selected' : '' ?>>Modifiées récemment</option>
                <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Plus anciennes</option>
                <option value="title_asc" <?= $sort === 'title_asc' ? 'selected' : '' ?>>Titre (A → Z)</option>
                <option value="title_desc" <?= $sort === 'title_desc' ? 'selected' : '' ?>>Titre (Z → A)</option>
                <option value="ref_asc" <?= $sort === 'ref_asc' ? 'selected' : '' ?>>Référence</option>
            </select>

            <select name="per_page" class="filter-select" onchange="this.form.submit()" aria-label="Fiches par page">
                <?php foreach ([12, 24, 48, 96] as $limitOption): ?>
                    <option value="<?= $limitOption ?>" <?= $itemsPerPage === $limitOption ? 'selected' : '' ?>>
                        <?= $limitOption ?> / page
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="btn btn-primary">🔍 Filtrer</button>

            <?php if ($hasActiveFilters): ?>
                <a href="dashboard.php" class="btn btn-secondary" title="Réinitialiser tous les filtres">✖</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if (empty($preparedMonuments)): ?>
        <div class="empty-state">
            <p><?= $deletedFilter === '1' ? 'La corbeille est vide.' : 'Aucune fiche enregistrée pour ces critères.' ?></p>
            <?php if ($hasActiveFilters): ?>
                <a href="dashboard.php" class="btn btn-secondary">Afficher toutes les fiches actives</a>
            <?php else: ?>
                <a href="monument_form.php" class="btn btn-primary">➕ Créer ma première fiche</a>
            <?php endif; ?>
        </div>
    <?php else: ?>

        <!-- =========================================================
             MODE 1 & 2 : GRILLE DE CARTES OU LISTE EN LIGNE
             ========================================================= -->
        <div id="dashboard-cards-view" class="items-container view-grid">
        <?php foreach ($preparedMonuments as $item): ?>
            <article class="item-card <?= $item['is_deleted'] ? 'is-deleted' : '' ?>">
                <div class="item-media">
                    <img src="<?= $item['image_url'] ?>" alt="<?= htmlspecialchars($item['titre'], ENT_QUOTES, 'UTF-8') ?>" class="item-img" loading="lazy">
                    <span class="status-badge <?= $item['is_deleted'] ? 'badge-deleted' : $item['statut_class'] ?>">
                        <?= $item['is_deleted'] ? 'Corbeille' : htmlspecialchars($item['statut_label'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </div>

                <div class="item-body">
                    <div class="item-meta-top">
                        <?php if ($item['numero_fiche'] !== ''): ?>
                            <span class="item-ref"
                                  onclick="copyRef('<?= htmlspecialchars($item['numero_fiche'], ENT_QUOTES, 'UTF-8') ?>')"
                                  title="Cliquer pour copier la référence">
                                <?= htmlspecialchars($item['numero_fiche'], ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        <?php endif; ?>
                        <span class="status-badge-inline list-only-badge <?= $item['is_deleted'] ? 'badge-deleted' : $item['statut_class'] ?>">
                            <?= $item['is_deleted'] ? 'Corbeille' : htmlspecialchars($item['statut_label'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                        <?php if ($item['type_oeuvre'] !== ''): ?>
                            <span class="item-type"><?= htmlspecialchars($item['type_oeuvre'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                    </div>

                    <h4 class="item-title">
                        <a href="viewer_oeuvre.php?id=<?= $item['id'] ?>"><?= htmlspecialchars($item['titre'], ENT_QUOTES, 'UTF-8') ?></a>
                    </h4>

                    <div class="item-details">
                        <?php if ($item['localisation'] !== ''): ?>
                            <span class="item-detail-tag">📍 <?= htmlspecialchars($item['localisation'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                        <?php if ($item['date_maj'] !== ''): ?>
                            <span class="item-detail-tag" title="<?= htmlspecialchars($item['date_full'], ENT_QUOTES, 'UTF-8') ?>">🕒 <?= htmlspecialchars($item['date_maj'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($item['propositions'])): ?>
                        <div class="item-propositions">
                            <strong class="proposition-alert-title">⚠️ <?= count($item['propositions']) ?> proposition(s) en attente</strong>
                            <?php foreach ($item['propositions'] as $prop): ?>
                                <div class="card-proposition-item">
                                    Par <strong><?= htmlspecialchars($prop['proposer_name'] ?? 'Inconnu', ENT_QUOTES, 'UTF-8') ?></strong> (<?= htmlspecialchars($prop['date'] ?? '', ENT_QUOTES, 'UTF-8') ?>)
                                    <div class="prop-actions">
                                        <a href="proposal_action.php?id=<?= $item['id'] ?>&prop_id=<?= urlencode($prop['prop_id']) ?>&action=accept" class="btn btn-success btn-xs" onclick="return confirm('Remplacer votre notice par cette version ?');">✅ Accepter</a>
                                        <a href="proposal_action.php?id=<?= $item['id'] ?>&prop_id=<?= urlencode($prop['prop_id']) ?>&action=reject" class="btn btn-danger btn-xs">❌ Refuser</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="item-actions">
                    <?php if (!$item['is_deleted']): ?>
                        <?php if ($item['statut_key'] === 'brouillon'): ?>
                            <form method="POST" action="monument_action.php" class="inline-form">
                                <input type="hidden" name="id" value="<?= $item['id'] ?>">
                                <input type="hidden" name="publish" value="1">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                <button type="submit" class="btn btn-success">🚀 Publier</button>
                            </form>
                        <?php endif; ?>
                        <a href="monument_form.php?id=<?= $item['id'] ?>" class="btn btn-secondary">✏️ Modifier</a>
                        <a href="preview_duplicate.php?id=<?= $item['id'] ?>" class="btn btn-secondary">📋 Dupliquer</a>
                        <a href="viewer_oeuvre.php?id=<?= $item['id'] ?>" class="btn btn-primary">👁️ Voir</a>
                        <form method="POST" action="monument_action.php" class="inline-form" onsubmit="return confirm('Voulez-vous vraiment mettre cette fiche à la corbeille ?');">
                            <input type="hidden" name="id" value="<?= $item['id'] ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                            <button type="submit" class="btn btn-danger">🗑️ Supprimer</button>
                        </form>
                    <?php else: ?>
                        <?php if ($item['can_restore']): ?>
                            <form method="POST" action="monument_action.php" class="inline-form">
                                <input type="hidden" name="id" value="<?= $item['id'] ?>">
                                <input type="hidden" name="restore" value="1">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                <button type="submit" class="btn btn-success">♻️ Restaurer</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($isAdmin): ?>
                            <form method="POST" action="monument_action.php" class="inline-form" onsubmit="return confirm('Attention : Cette suppression est définitive !');">
                                <input type="hidden" name="id" value="<?= $item['id'] ?>">
                                <input type="hidden" name="permanent" value="1">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                <button type="submit" class="btn btn-danger">❌ Supprimer</button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
        </div>

        <!-- =========================================================
             MODE 3 : TABLEAU COMPACT
             ========================================================= -->
        <div id="dashboard-table-view" class="table-responsive" style="display: none;">
            <table class="standard-table dashboard-table">
                <thead>
                    <tr>
                        <th style="width: 64px;">Aperçu</th>
                        <th style="width: 155px;">Référence</th>
                        <th>Titre &amp; Type</th>
                        <th>Localisation</th>
                        <th style="width: 110px;">Mise à jour</th>
                        <th style="width: 105px;">Statut</th>
                        <th style="width: 245px; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($preparedMonuments as $item): ?>
                        <tr class="<?= $item['is_deleted'] ? 'row-deleted' : '' ?>">
                            <td>
                                <img src="<?= $item['image_url'] ?>" alt="" class="table-thumb" loading="lazy">
                            </td>
                            <td>
                                <?php if ($item['numero_fiche'] !== ''): ?>
                                    <span class="item-ref"
                                          onclick="copyRef('<?= htmlspecialchars($item['numero_fiche'], ENT_QUOTES, 'UTF-8') ?>')"
                                          title="Cliquer pour copier la référence">
                                        <?= htmlspecialchars($item['numero_fiche'], ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><a href="viewer_oeuvre.php?id=<?= $item['id'] ?>" class="table-title-link"><?= htmlspecialchars($item['titre'], ENT_QUOTES, 'UTF-8') ?></a></strong>
                                <?php if ($item['type_oeuvre'] !== ''): ?>
                                    <div class="table-subtext"><?= htmlspecialchars($item['type_oeuvre'], ENT_QUOTES, 'UTF-8') ?></div>
                                <?php endif; ?>
                                <?php if (!empty($item['propositions'])): ?>
                                    <div class="table-prop-badge">⚠️ <?= count($item['propositions']) ?> proposition(s)</div>
                                <?php endif; ?>
                            </td>
                            <td><?= $item['localisation'] !== '' ? htmlspecialchars($item['localisation'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>' ?></td>
                            <td style="white-space: nowrap;" title="<?= htmlspecialchars($item['date_full'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= $item['date_maj'] !== '' ? htmlspecialchars($item['date_maj'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>' ?>
                            </td>
                            <td>
                                <span class="status-badge-inline <?= $item['is_deleted'] ? 'badge-deleted' : $item['statut_class'] ?>">
                                    <?= $item['is_deleted'] ? 'Corbeille' : htmlspecialchars($item['statut_label'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>
                            <td class="table-actions-cell">
                                <div class="table-actions-group">
                                    <?php if (!$item['is_deleted']): ?>
                                        <?php if ($item['statut_key'] === 'brouillon'): ?>
                                            <form method="POST" action="monument_action.php" class="inline-form">
                                                <input type="hidden" name="id" value="<?= $item['id'] ?>">
                                                <input type="hidden" name="publish" value="1">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                <button type="submit" class="btn btn-success btn-table" title="Publier">🚀</button>
                                            </form>
                                        <?php endif; ?>
                                        <a href="monument_form.php?id=<?= $item['id'] ?>" class="btn btn-secondary btn-table" title="Modifier">✏️</a>
                                        <a href="preview_duplicate.php?id=<?= $item['id'] ?>" class="btn btn-secondary btn-table" title="Dupliquer">📋</a>
                                        <a href="viewer_oeuvre.php?id=<?= $item['id'] ?>" class="btn btn-primary btn-table" title="Voir">👁️</a>
                                        <form method="POST" action="monument_action.php" class="inline-form" onsubmit="return confirm('Voulez-vous vraiment mettre cette fiche à la corbeille ?');">
                                            <input type="hidden" name="id" value="<?= $item['id'] ?>">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                            <button type="submit" class="btn btn-danger btn-table" title="Supprimer">🗑️</button>
                                        </form>
                                    <?php else: ?>
                                        <?php if ($item['can_restore']): ?>
                                            <form method="POST" action="monument_action.php" class="inline-form">
                                                <input type="hidden" name="id" value="<?= $item['id'] ?>">
                                                <input type="hidden" name="restore" value="1">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                <button type="submit" class="btn btn-success btn-table" title="Restaurer">♻️</button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($isAdmin): ?>
                                            <form method="POST" action="monument_action.php" class="inline-form" onsubmit="return confirm('Attention : Cette suppression est définitive !');">
                                                <input type="hidden" name="id" value="<?= $item['id'] ?>">
                                                <input type="hidden" name="permanent" value="1">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                <button type="submit" class="btn btn-danger btn-table" title="Supprimer définitivement">❌</button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination intelligente avec ellipses -->
        <?php if ($totalPages > 1): ?>
            <?php
            $pagesToShow = [];
            for ($p = 1; $p <= $totalPages; $p++) {
                if ($p === 1 || $p === $totalPages || abs($p - $page) <= 2) {
                    $pagesToShow[] = $p;
                }
            }
            ?>
            <nav class="pagination" aria-label="Pagination">
                <?php if ($page > 1): ?>
                    <a href="<?= htmlspecialchars($buildQueryUrl(['page' => $page - 1]), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary" title="Page précédente">←</a>
                <?php endif; ?>

                <?php
                $prevPage = 0;
                foreach ($pagesToShow as $p):
                    if ($prevPage > 0 && $p - $prevPage > 1): ?>
                        <span class="pagination-ellipsis">…</span>
                    <?php endif; ?>
                    <a href="<?= htmlspecialchars($buildQueryUrl(['page' => $p]), ENT_QUOTES, 'UTF-8') ?>"
                       class="btn <?= $p === $page ? 'btn-primary' : 'btn-secondary' ?>">
                        <?= $p ?>
                    </a>
                <?php
                    $prevPage = $p;
                endforeach;
                ?>

                <?php if ($page < $totalPages): ?>
                    <a href="<?= htmlspecialchars($buildQueryUrl(['page' => $page + 1]), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary" title="Page suivante">→</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<div id="db-toast" class="db-toast" role="status" aria-live="polite"></div>

<script>
function setDashboardView(viewMode) {
    const cardsContainer = document.getElementById('dashboard-cards-view');
    const tableContainer = document.getElementById('dashboard-table-view');
    const buttons = document.querySelectorAll('.view-switcher .view-btn');

    buttons.forEach(btn => {
        btn.classList.toggle('active', btn.getAttribute('data-view') === viewMode);
    });

    if (viewMode === 'table') {
        if (cardsContainer) cardsContainer.style.display = 'none';
        if (tableContainer) tableContainer.style.display = 'block';
    } else {
        if (tableContainer) tableContainer.style.display = 'none';
        if (cardsContainer) {
            cardsContainer.style.display = '';
            cardsContainer.classList.remove('view-grid', 'view-list');
            cardsContainer.classList.add(viewMode === 'list' ? 'view-list' : 'view-grid');
        }
    }

    try {
        localStorage.setItem('dashboard_view_mode', viewMode);
    } catch (e) {}
}

let toastTimer = null;
function showToast(message) {
    const toast = document.getElementById('db-toast');
    if (!toast) return;
    toast.textContent = message;
    toast.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toast.classList.remove('show'), 2200);
}

function copyRef(refText) {
    if (!refText) return;
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(refText).then(() => {
            showToast('Référence copiée : ' + refText);
        });
    }
}

document.addEventListener('DOMContentLoaded', () => {
    let savedView = 'grid';
    try {
        savedView = localStorage.getItem('dashboard_view_mode') || 'grid';
    } catch (e) {}
    if (!['grid', 'list', 'table'].includes(savedView)) savedView = 'grid';
    setDashboardView(savedView);

    const searchInput = document.getElementById('dashboard-search-input');
    document.addEventListener('keydown', (e) => {
        if (e.key === '/' && searchInput && document.activeElement.tagName !== 'INPUT' && document.activeElement.tagName !== 'TEXTAREA') {
            e.preventDefault();
            searchInput.focus();
            searchInput.select();
        }
    });
});
</script>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/layout.php';
?>