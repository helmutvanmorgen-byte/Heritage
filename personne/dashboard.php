<?php
require_once __DIR__ . '/personne_config.php';
require_once __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['user_id'], $_SESSION['user_role'])) {
    header('Location: /login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$role   = $_SESSION['user_role'];

$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError   = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

[$type, $cfg] = resolvePersonneType($_GET['type'] ?? null, $typesConfig);

$search        = trim($_GET['search'] ?? '');
$sort          = $_GET['sort'] ?? 'nom_asc';
$audit         = $_GET['audit'] ?? '';
$deletedFilter = $_GET['deleted_filter'] ?? '0';

if (isset($_GET['audit'])) {
    $_SESSION['current_audit'] = $_GET['audit'];
}

$itemsPerPage = 15;
$page = max(1, (int)($_GET['page'] ?? 1));

$_SESSION['last_dashboard_url'] = $_SERVER['REQUEST_URI'];
$_SESSION['last_dashboard_url_by_type'][$type] = $_SERVER['REQUEST_URI'];

// Chargement des propositions d'auteurs en attente (administrateur uniquement)
$pendingPersonProposals = [];
$proposalsFile = __DIR__ . '/../json/thesaurus_propositions.json';
if ($role === 'admin' && file_exists($proposalsFile)) {
    $rawProps = preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($proposalsFile));
    $allProps = json_decode($rawProps, true);
    if (is_array($allProps)) {
        $pendingPersonProposals = array_values(array_filter(
            $allProps,
            fn($p) => ($p['proposal_type'] ?? 'term') === 'person'
        ));
    }
}

$dataFile = __DIR__ . '/../json/' . $cfg['json'];
$allItems = file_exists($dataFile) ? json_decode(file_get_contents($dataFile), true) : [];
if (!is_array($allItems)) {
    $allItems = [];
}

// --- PRÉ-CALCUL DES DOUBLONS ---
$counts = ['nom_seul' => [], 'nom_prenom' => [], 'dates' => [], 'lieux_dates' => []];

foreach ($allItems as $s) {
    if (!empty($s['deleted_at']) && $deletedFilter === '0') {
        continue;
    }

    $nom        = trim($s['nom'] ?? '');
    $prenom     = trim($s['prenom'] ?? '');
    $anneeNaiss = trim($s['naissance']['annee'] ?? '');
    $lieuNaiss  = trim($s['naissance']['lieu'] ?? '');
    $anneeDeces = trim($s['deces']['annee'] ?? '');
    $lieuDeces  = trim($s['deces']['lieu'] ?? '');

    $nomSeulKey = mb_strtolower($nom, 'UTF-8');
    if ($nomSeulKey !== '') {
        $counts['nom_seul'][$nomSeulKey] = ($counts['nom_seul'][$nomSeulKey] ?? 0) + 1;
    }

    $nomPrenomKey = mb_strtolower(trim($nom . ' ' . $prenom), 'UTF-8');
    if ($nomPrenomKey !== '') {
        $counts['nom_prenom'][$nomPrenomKey] = ($counts['nom_prenom'][$nomPrenomKey] ?? 0) + 1;
    }

    if ($anneeNaiss !== '' && $anneeDeces !== '') {
        $datesKey = $anneeNaiss . '|' . $anneeDeces;
        $counts['dates'][$datesKey] = ($counts['dates'][$datesKey] ?? 0) + 1;
    }

    $lieuxDatesKey = mb_strtolower($lieuNaiss, 'UTF-8') . '|' . $anneeNaiss . '|' . mb_strtolower($lieuDeces, 'UTF-8') . '|' . $anneeDeces;
    if ($lieuxDatesKey !== '|||') {
        $counts['lieux_dates'][$lieuxDatesKey] = ($counts['lieux_dates'][$lieuxDatesKey] ?? 0) + 1;
    }
}

// 1. Filtrage
$list = [];
foreach ($allItems as $s) {
    $isDeleted = !empty($s['deleted_at']);
    if ($role !== 'admin' && (int)($s['user_id'] ?? 0) !== $userId) {
        continue;
    }
    if ($role === 'admin') {
        if ($deletedFilter === '0' && $isDeleted) continue;
        if ($deletedFilter === '1' && !$isDeleted) continue;
    } elseif ($isDeleted) {
        continue;
    }

    $nom        = trim($s['nom'] ?? '');
    $prenom     = trim($s['prenom'] ?? '');
    $anneeNaiss = trim($s['naissance']['annee'] ?? '');
    $lieuNaiss  = trim($s['naissance']['lieu'] ?? '');
    $anneeDeces = trim($s['deces']['annee'] ?? '');
    $lieuDeces  = trim($s['deces']['lieu'] ?? '');
    $permalien  = trim($s['permalien'] ?? '');

    $estVide    = ($nom === '' && $prenom === '');
    $sansPrenom = ($nom !== '' && $prenom === '');
    $sansDates  = ($anneeNaiss === '' && $anneeDeces === '');
    $sansLien   = ($permalien === '' || $permalien === 'N/A');

    $nomSeulKey         = mb_strtolower($nom, 'UTF-8');
    $isDoublonNomSeul   = ($nomSeulKey !== '' && ($counts['nom_seul'][$nomSeulKey] ?? 0) > 1);
    $nomPrenomKey       = mb_strtolower(trim($nom . ' ' . $prenom), 'UTF-8');
    $isDoublonNomPrenom = ($nomPrenomKey !== '' && ($counts['nom_prenom'][$nomPrenomKey] ?? 0) > 1);

    $isDoublonDates = false;
    if ($anneeNaiss !== '' && $anneeDeces !== '') {
        $datesKey       = $anneeNaiss . '|' . $anneeDeces;
        $isDoublonDates = (($counts['dates'][$datesKey] ?? 0) > 1);
    }

    $isDoublonLieuxDates = false;
    $lieuxDatesKey = mb_strtolower($lieuNaiss, 'UTF-8') . '|' . $anneeNaiss . '|' . mb_strtolower($lieuDeces, 'UTF-8') . '|' . $anneeDeces;
    if ($lieuxDatesKey !== '|||') {
        $isDoublonLieuxDates = (($counts['lieux_dates'][$lieuxDatesKey] ?? 0) > 1);
    }

    $s['_doublons'] = [];
    if ($isDoublonNomPrenom) {
        $s['_doublons'][] = 'Doublon : Nom + Prénom';
    } elseif ($isDoublonNomSeul) {
        $s['_doublons'][] = 'Doublon : Nom seul';
    }

    if ($isDoublonLieuxDates) {
        $s['_doublons'][] = 'Doublon : Lieux + Dates (N&D)';
    } elseif ($isDoublonDates) {
        $s['_doublons'][] = 'Doublon : Années (N&D)';
    }

    if ($role === 'admin' && $audit !== '') {
        if ($audit === 'vide' && !$estVide) continue;
        if ($audit === 'sans_prenom' && !$sansPrenom) continue;
        if ($audit === 'sans_dates' && !$sansDates) continue;
        if ($audit === 'sans_lien' && !$sansLien) continue;
        if ($audit === 'toutes' && !($estVide || $sansPrenom || $sansDates || $sansLien)) continue;
        if ($audit === 'doublon_nom_seul' && !$isDoublonNomSeul) continue;
        if ($audit === 'doublon_nom_prenom' && !$isDoublonNomPrenom) continue;
        if ($audit === 'doublon_dates' && !$isDoublonDates) continue;
        if ($audit === 'doublon_lieux_dates' && !$isDoublonLieuxDates) continue;
        if ($audit === 'doublon_tous' && empty($s['_doublons'])) continue;
    }

    if ($search !== '') {
        $jsonString = mb_strtolower(json_encode($s, JSON_UNESCAPED_UNICODE), 'UTF-8');
        if (mb_strpos($jsonString, mb_strtolower($search, 'UTF-8')) === false) {
            continue;
        }
    }

    $list[] = $s;
}

// 2. Tri
usort($list, function ($a, $b) use ($sort) {
    $nomA        = trim(($a['nom'] ?? '') . ' ' . ($a['prenom'] ?? ''));
    $nomB        = trim(($b['nom'] ?? '') . ' ' . ($b['prenom'] ?? ''));
    $anneeNaissA = (int)($a['naissance']['annee'] ?? 0);
    $anneeNaissB = (int)($b['naissance']['annee'] ?? 0);
    $anneeDecesA = (int)($a['deces']['annee'] ?? 0);
    $anneeDecesB = (int)($b['deces']['annee'] ?? 0);

    switch ($sort) {
        case 'nom_desc':
            return strcasecmp($nomB, $nomA);
        case 'naissance_asc':
            return $anneeNaissA <=> $anneeNaissB;
        case 'naissance_desc':
            return $anneeNaissB <=> $anneeNaissA;
        case 'deces_asc':
            return $anneeDecesA <=> $anneeDecesB;
        case 'deces_desc':
            return $anneeDecesB <=> $anneeDecesA;
        case 'nom_asc':
        default:
            return strcasecmp($nomA, $nomB);
    }
});

$_SESSION['audit_list'] = array_column($list, 'id');

// 3. Pagination
$totalItems = count($list);
$totalPages = max(1, (int)ceil($totalItems / $itemsPerPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset    = ($page - 1) * $itemsPerPage;
$itemsPage = array_slice($list, $offset, $itemsPerPage);

$hasActiveFilters = ($search !== '' || $audit !== '' || $deletedFilter !== '0' || $sort !== 'nom_asc');

ob_start();
?>
<div class="content-3 dashboard-wrapper">

    <!-- ONGLETS PAR MÉTIER -->
    <nav class="tabs-header tabs-nav" aria-label="Catégories de personnes">
        <?php foreach ($typesConfig as $key => $tabCfg): ?>
            <a href="dashboard.php?type=<?= urlencode($key) ?>" class="tab-link <?= $type === $key ? 'active' : '' ?>">
                <?= htmlspecialchars($tabCfg['onglet']) ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <!-- EN-TÊTE -->
    <div class="dashboard-header-top">
        <div>
            <h2><?= htmlspecialchars($cfg['titre']) ?></h2>
            <span class="dashboard-count"><?= $totalItems ?> résultat(s)</span>
        </div>

        <?php if ($role === 'admin' && $deletedFilter === '1' && !empty($itemsPage)): ?>
            <form method="POST" action="personne_action.php" class="inline-form" onsubmit="return confirm('Voulez-vous vraiment vider toute la corbeille ?');">
                <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
                <input type="hidden" name="empty_trash" value="1">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <button type="submit" class="btn btn-danger">Vider la corbeille</button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($flashSuccess): ?>
        <div class="alert alert-success"><?= htmlspecialchars($flashSuccess) ?></div>
    <?php endif; ?>
    <?php if ($flashError): ?>
        <div class="alert alert-error"><?= htmlspecialchars($flashError) ?></div>
    <?php endif; ?>

    <!-- PROPOSITIONS D'AUTEURS EN ATTENTE DE VALIDATION (DEPUS MONUMENT_FORM.PHP) -->
    <?php if ($role === 'admin' && !empty($pendingPersonProposals)): ?>
        <div style="background: #fffbeb; border: 1px solid #f59e0b; border-radius: 6px; padding: 14px 16px; margin-bottom: 18px;">
            <h4 style="margin: 0 0 10px 0; color: #b45309; font-size: 0.95rem;">
                ⏳ Propositions d'auteurs en attente de validation (<?= count($pendingPersonProposals) ?>)
            </h4>
            <div class="table-responsive">
                <table class="standard-table" style="background: #fff; margin: 0;">
                    <thead>
                        <tr>
                            <th style="width: 14%;">Métier</th>
                            <th>Fiche proposée (modifiable avant validation)</th>
                            <th style="width: 15%;">Proposé par</th>
                            <th style="width: 170px; text-align: center;">Décision</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendingPersonProposals as $prop):
                            $pId = htmlspecialchars($prop['id'] ?? '');
                            $pd  = $prop['person_data'] ?? [];
                            $metierSlug = normalizePersonneSlug((string)($prop['metier'] ?? ''));
                            $isDetailed = in_array($metierSlug, ['architecte', 'medailleur', 'sculpteur'], true);
                        ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($prop['metier'] ?? '') ?></strong></td>
                                <td>
                                    <form method="post" action="personne_action.php" id="form-prop-<?= $pId ?>" style="margin: 0;">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
                                        <input type="hidden" name="prop_id" value="<?= $pId ?>">

                                        <?php if ($isDetailed): ?>
                                            <div style="display: grid; grid-template-columns: 1.3fr 1.1fr 0.8fr; gap: 6px; margin-bottom: 6px;">
                                                <input type="text" name="nom" value="<?= htmlspecialchars($pd['nom'] ?? '') ?>" placeholder="Nom *" required style="height: 30px; padding: 0 8px; border: 1px solid #cbd5e0; border-radius: 4px;">
                                                <input type="text" name="prenom" value="<?= htmlspecialchars($pd['prenom'] ?? '') ?>" placeholder="Prénom" style="height: 30px; padding: 0 8px; border: 1px solid #cbd5e0; border-radius: 4px;">
                                                <select name="sexe" style="height: 30px; padding: 0 6px; border: 1px solid #cbd5e0; border-radius: 4px;">
                                                    <option value="" <?= ($pd['sexe'] ?? '') === '' ? 'selected' : '' ?>>Sexe : N/S</option>
                                                    <option value="Homme" <?= ($pd['sexe'] ?? '') === 'Homme' ? 'selected' : '' ?>>Homme</option>
                                                    <option value="Femme" <?= ($pd['sexe'] ?? '') === 'Femme' ? 'selected' : '' ?>>Femme</option>
                                                </select>
                                            </div>
                                            <div style="display: grid; grid-template-columns: 0.7fr 1.6fr 1fr; gap: 6px; margin-bottom: 6px;">
                                                <input type="text" name="naissance[annee]" value="<?= htmlspecialchars($pd['naissance_annee'] ?? ($pd['naissance']['annee'] ?? '')) ?>" placeholder="Naiss. Année" style="height: 30px; padding: 0 8px; border: 1px solid #cbd5e0; border-radius: 4px;">
                                                <input type="text" name="naissance[lieu]" value="<?= htmlspecialchars($pd['naissance_lieu'] ?? ($pd['naissance']['lieu'] ?? '')) ?>" placeholder="Naiss. Lieu" style="height: 30px; padding: 0 8px; border: 1px solid #cbd5e0; border-radius: 4px;">
                                                <input type="text" name="naissance[pays]" value="<?= htmlspecialchars($pd['naissance_pays'] ?? ($pd['naissance']['pays'] ?? '')) ?>" placeholder="Naiss. Pays" style="height: 30px; padding: 0 8px; border: 1px solid #cbd5e0; border-radius: 4px;">
                                            </div>
                                            <div style="display: grid; grid-template-columns: 0.7fr 1.6fr 1fr; gap: 6px; margin-bottom: 6px;">
                                                <input type="text" name="deces[annee]" value="<?= htmlspecialchars($pd['deces_annee'] ?? ($pd['deces']['annee'] ?? '')) ?>" placeholder="Décès Année" style="height: 30px; padding: 0 8px; border: 1px solid #cbd5e0; border-radius: 4px;">
                                                <input type="text" name="deces[lieu]" value="<?= htmlspecialchars($pd['deces_lieu'] ?? ($pd['deces']['lieu'] ?? '')) ?>" placeholder="Décès Lieu" style="height: 30px; padding: 0 8px; border: 1px solid #cbd5e0; border-radius: 4px;">
                                                <input type="text" name="deces[pays]" value="<?= htmlspecialchars($pd['deces_pays'] ?? ($pd['deces']['pays'] ?? '')) ?>" placeholder="Décès Pays" style="height: 30px; padding: 0 8px; border: 1px solid #cbd5e0; border-radius: 4px;">
                                            </div>
                                            <div style="display: grid; grid-template-columns: 1.5fr 1fr; gap: 6px;">
                                                <input type="text" name="biographie" value="<?= htmlspecialchars($pd['biographie'] ?? '') ?>" placeholder="Biographie / Notes" style="height: 30px; padding: 0 8px; border: 1px solid #cbd5e0; border-radius: 4px;">
                                                <input type="text" name="permalien" value="<?= htmlspecialchars($pd['permalien'] ?? ($pd['source'] ?? '')) ?>" placeholder="Permalien / Source" style="height: 30px; padding: 0 8px; border: 1px solid #cbd5e0; border-radius: 4px;">
                                            </div>
                                        <?php else: ?>
                                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 6px; margin-bottom: 6px;">
                                                <input type="text" name="nom" value="<?= htmlspecialchars($pd['nom'] ?? '') ?>" placeholder="Nom / Raison sociale *" required style="height: 30px; padding: 0 8px; border: 1px solid #cbd5e0; border-radius: 4px;">
                                                <input type="text" name="prenom" value="<?= htmlspecialchars($pd['prenom'] ?? '') ?>" placeholder="Prénom" style="height: 30px; padding: 0 8px; border: 1px solid #cbd5e0; border-radius: 4px;">
                                                <input type="text" name="dates" value="<?= htmlspecialchars($pd['dates'] ?? '') ?>" placeholder="Dates" style="height: 30px; padding: 0 8px; border: 1px solid #cbd5e0; border-radius: 4px;">
                                                <input type="text" name="lieu" value="<?= htmlspecialchars($pd['lieu'] ?? '') ?>" placeholder="Lieu(x)" style="height: 30px; padding: 0 8px; border: 1px solid #cbd5e0; border-radius: 4px;">
                                            </div>
                                            <div style="display: grid; grid-template-columns: 1.5fr 1fr; gap: 6px;">
                                                <input type="text" name="biographie" value="<?= htmlspecialchars($pd['biographie'] ?? '') ?>" placeholder="Biographie / Notes" style="height: 30px; padding: 0 8px; border: 1px solid #cbd5e0; border-radius: 4px;">
                                                <input type="text" name="permalien" value="<?= htmlspecialchars($pd['permalien'] ?? ($pd['source'] ?? '')) ?>" placeholder="Permalien / Source" style="height: 30px; padding: 0 8px; border: 1px solid #cbd5e0; border-radius: 4px;">
                                            </div>
                                        <?php endif; ?>
                                    </form>
                                </td>
                                <td style="font-size: 0.85rem; color: #4a5568;">
                                    <?= htmlspecialchars($prop['username'] ?? 'Inconnu') ?><br>
                                    <span style="font-size: 0.78rem; color: #718096;"><?= htmlspecialchars($prop['created_at'] ?? '') ?></span>
                                </td>
                                <td class="table-actions-cell">
                                    <div class="table-actions-group">
                                        <button type="submit" form="form-prop-<?= $pId ?>" name="proposal_action" value="approve" class="btn btn-success btn-xs">Valider</button>
                                        <button type="submit" form="form-prop-<?= $pId ?>" name="proposal_action" value="reject" class="btn btn-danger btn-xs" onclick="return confirm('Refuser et supprimer cette proposition ?');">Refuser</button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- BARRE D'OUTILS & FILTRES UNIFIÉS -->
    <div class="dashboard-toolbar">
        <div class="dashboard-actions-left">
            <a href="personne_form.php?type=<?= urlencode($type) ?>" class="btn btn-primary">
                Nouveau <?= htmlspecialchars($cfg['label']) ?>
            </a>

            <?php if ($role === 'admin'): ?>
                <form method="POST" action="personne_action.php" class="inline-form" onsubmit="return confirm('Nettoyer automatiquement les doublons vides ?');">
                    <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
                    <input type="hidden" name="clean_empty_duplicates" value="1">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <button type="submit" class="btn btn-secondary">Nettoyer doublons vides</button>
                </form>
            <?php endif; ?>
        </div>

        <form method="get" class="dashboard-filters">
            <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">

            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Rechercher...">

            <?php if ($role === 'admin'): ?>
                <select name="audit" onchange="this.form.submit()">
                    <option value="">Toutes les fiches (Audit)</option>
                    <option value="toutes" <?= $audit === 'toutes' ? 'selected' : '' ?>>Toutes les anomalies</option>
                    <option value="vide" <?= $audit === 'vide' ? 'selected' : '' ?>>Fiches entièrement vides</option>
                    <option value="sans_prenom" <?= $audit === 'sans_prenom' ? 'selected' : '' ?>>Fiches sans prénom</option>
                    <option value="sans_dates" <?= $audit === 'sans_dates' ? 'selected' : '' ?>>Fiches sans dates</option>
                    <option value="sans_lien" <?= $audit === 'sans_lien' ? 'selected' : '' ?>>Fiches sans permalien</option>
                    <option value="doublon_tous" <?= $audit === 'doublon_tous' ? 'selected' : '' ?>>Tous les doublons</option>
                </select>

                <select name="deleted_filter" onchange="this.form.submit()">
                    <option value="0" <?= $deletedFilter === '0' ? 'selected' : '' ?>>Actifs</option>
                    <option value="1" <?= $deletedFilter === '1' ? 'selected' : '' ?>>Supprimés</option>
                    <option value="all" <?= $deletedFilter === 'all' ? 'selected' : '' ?>>Tous</option>
                </select>
            <?php endif; ?>

            <select name="sort" onchange="this.form.submit()">
                <option value="nom_asc" <?= $sort === 'nom_asc' ? 'selected' : '' ?>>Nom (A-Z)</option>
                <option value="nom_desc" <?= $sort === 'nom_desc' ? 'selected' : '' ?>>Nom (Z-A)</option>
                <option value="naissance_asc" <?= $sort === 'naissance_asc' ? 'selected' : '' ?>>Naissance (Chronologique)</option>
                <option value="naissance_desc" <?= $sort === 'naissance_desc' ? 'selected' : '' ?>>Naissance (Antéchronologique)</option>
            </select>

            <button type="submit" class="btn btn-primary">Filtrer</button>

            <?php if ($hasActiveFilters): ?>
                <a href="dashboard.php?type=<?= urlencode($type) ?>" class="btn btn-secondary">Réinitialiser</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- LISTE DES FICHES -->
    <?php if (empty($itemsPage)): ?>
        <div class="empty-state">
            Aucune fiche ne correspond à cette sélection.
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="standard-table">
                <thead>
                    <tr>
                        <th>Nom &amp; Prénom</th>
                        <th>Naissance / Décès</th>
                        <th>Anomalies / Doublons</th>
                        <th class="table-actions-cell">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($itemsPage as $s):
                    $nom         = trim($s['nom'] ?? '');
                    $prenom      = trim($s['prenom'] ?? '');
                    $nom_affiche = trim($nom . ' ' . $prenom);
                    if ($nom_affiche === '') {
                        $nom_affiche = ucfirst($cfg['label']) . ' sans nom';
                    }

                    $anneeNaiss = $s['naissance']['annee'] ?? '';
                    $lieuNaiss  = $s['naissance']['lieu'] ?? '';
                    $anneeDeces = $s['deces']['annee'] ?? '';
                    $lieuDeces  = $s['deces']['lieu'] ?? '';

                    $dates_affichees = 'Non renseigné';
                    if ($anneeNaiss || $anneeDeces) {
                        $pNaiss          = ($lieuNaiss ? $lieuNaiss . ' ' : '') . ($anneeNaiss ?: '?');
                        $pDeces          = ($lieuDeces ? $lieuDeces . ' ' : '') . ($anneeDeces ?: '?');
                        $dates_affichees = trim($pNaiss) . ' - ' . trim($pDeces);
                    } elseif (!empty($s['dates']) || !empty($s['lieu'])) {
                        $dates_affichees = trim(($s['dates'] ?? '') . ' ' . ($s['lieu'] ?? ''));
                    }

                    $alertes = [];
                    if ($nom === '' && $prenom === '') {
                        $alertes[] = 'Fiche vide';
                    }
                    if ($nom !== '' && $prenom === '') {
                        $alertes[] = 'Pas de prénom';
                    }
                    if ($anneeNaiss === '' && $anneeDeces === '' && empty($s['dates'])) {
                        $alertes[] = 'Pas de dates';
                    }
                    if (!empty($s['_doublons'])) {
                        $alertes = array_merge($alertes, $s['_doublons']);
                    }

                    $isDeleted = !empty($s['deleted_at']);
                ?>
                    <tr class="<?= $isDeleted ? 'row-deleted' : '' ?>">
                        <td>
                            <?php if (!$isDeleted): ?>
                                <a href="personne_form.php?type=<?= urlencode($type) ?>&amp;id=<?= (int)$s['id'] ?>" class="table-title-link">
                                    <strong><?= htmlspecialchars($nom_affiche) ?></strong>
                                </a>
                            <?php else: ?>
                                <span class="text-muted"><?= htmlspecialchars($nom_affiche) ?> (Supprimé)</span>
                            <?php endif; ?>
                        </td>
                        <td class="table-subtext"><?= htmlspecialchars($dates_affichees) ?></td>
                        <td>
                            <?php foreach ($alertes as $alerte):
                                $isDoublon = strpos($alerte, 'Doublon') !== false;
                            ?>
                                <span class="status-badge-inline <?= $isDoublon ? 'badge-deleted' : 'badge-valider' ?>">
                                    <?= htmlspecialchars($alerte) ?>
                                </span>
                            <?php endforeach; ?>
                        </td>
                        <td class="table-actions-cell">
                            <div class="table-actions-group">
                                <?php if (!$isDeleted): ?>
                                    <a href="personne_form.php?type=<?= urlencode($type) ?>&amp;id=<?= (int)$s['id'] ?>" class="btn btn-secondary btn-xs">
                                        Modifier
                                    </a>
                                    <form method="POST" action="personne_action.php" class="inline-form" onsubmit="return confirm('Mettre cette fiche à la corbeille ?');">
                                        <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
                                        <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                        <button type="submit" class="btn btn-danger btn-xs">Supprimer</button>
                                    </form>
                                <?php elseif ($role === 'admin'): ?>
                                    <form method="POST" action="personne_action.php" class="inline-form">
                                        <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
                                        <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                        <input type="hidden" name="restore" value="1">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                        <button type="submit" class="btn btn-success btn-xs">Restaurer</button>
                                    </form>
                                    <form method="POST" action="personne_action.php" class="inline-form" onsubmit="return confirm('Suppression définitive !');">
                                        <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
                                        <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                        <input type="hidden" name="permanent" value="1">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                        <button type="submit" class="btn btn-danger btn-xs">Supprimer définitivement</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php
                $baseQuery = [
                    'type'           => $type,
                    'sort'           => $sort,
                    'search'         => $search,
                    'deleted_filter' => $deletedFilter,
                    'audit'          => $audit
                ];
                ?>
                <?php if ($page > 1): ?>
                    <a href="?<?= htmlspecialchars(http_build_query(array_merge($baseQuery, ['page' => $page - 1]))) ?>" class="btn btn-secondary btn-xs">Précédent</a>
                <?php endif; ?>

                <span class="btn btn-primary btn-xs">Page <?= $page ?> / <?= $totalPages ?></span>

                <?php if ($page < $totalPages): ?>
                    <a href="?<?= htmlspecialchars(http_build_query(array_merge($baseQuery, ['page' => $page + 1]))) ?>" class="btn btn-secondary btn-xs">Suivant</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/layout.php';