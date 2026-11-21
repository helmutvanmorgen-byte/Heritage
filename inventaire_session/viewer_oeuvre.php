<?php
session_start();
if (!isset($_SESSION['user_id'], $_SESSION['user_role'])) {
    die("Non connecté.");
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$dataFile = __DIR__ . '/../json/monuments.json';
$allMonuments = file_exists($dataFile) ? json_decode(file_get_contents($dataFile), true) : [];
if (!is_array($allMonuments)) {
    $allMonuments = [];
}

$monument = null;
foreach ($allMonuments as $m) {
    if ((int)($m['id'] ?? 0) === $id && empty($m['deleted_at'])) {
        $monument = $m;
        break;
    }
}

if (!$monument) {
    die("Monument introuvable ou supprimé.");
}

// =========================================================================
// DROITS D'ÉDITION / PROPOSITION (ADMIN & CONTRIBUTEUR)
// =========================================================================
$currentUserId    = (int)$_SESSION['user_id'];
$currentRole      = mb_strtolower(trim((string)$_SESSION['user_role']), 'UTF-8');
$isAdmin          = in_array($currentRole, ['admin', 'administrateur'], true);
$isOwner          = ((int)($monument['user_id'] ?? 0) === $currentUserId);
$isContributor    = in_array($currentRole, ['contributeur', 'contributor', 'editeur', 'editor', 'utilisateur', 'user', 'membre'], true);
$canEditOrPropose = ($isAdmin || $isOwner || $isContributor);
$editButtonLabel  = ($isAdmin || $isOwner) ? '✏️ Modifier la fiche' : '💡 Proposer une correction';
$editUrl          = 'monument_form.php?id=' . (int)$monument['id'];

// =========================================================================
// HELPERS D'EXTRACTION ET DE FORMATAGE
// =========================================================================

$cleanArray = static function ($val): array {
    if (is_array($val)) {
        $out = [];
        foreach ($val as $item) {
            if (is_scalar($item) && trim((string)$item) !== '') {
                $out[] = trim((string)$item);
            }
        }
        return $out;
    }
    if (is_scalar($val) && trim((string)$val) !== '') {
        return [trim((string)$val)];
    }
    return [];
};

$formatRichText = static function ($text): string {
    $raw = trim((string)($text ?? ''));
    if ($raw === '') {
        return '';
    }
    $sanitized = strip_tags($raw, '<b><i><u><s><strong><em><br><p><ul><ol><li>');
    if (stripos($sanitized, '<br') === false && stripos($sanitized, '<p') === false) {
        $sanitized = nl2br($sanitized, false);
    }
    return $sanitized;
};

$formatComplexDate = static function ($valueData): string {
    $data = is_string($valueData) ? json_decode($valueData, true) : $valueData;
    if (!is_array($data)) {
        return trim((string)($valueData ?? ''));
    }
    $mod = $data['modifier'] ?? 'le';
    $d1  = $data['date1'] ?? '';
    $d2  = $data['date2'] ?? '';

    while (is_string($d1) && strpos($d1, '{"modifier') !== false) {
        $attempt = json_decode(stripslashes($d1), true) ?: json_decode($d1, true);
        if (is_array($attempt) && array_key_exists('date1', $attempt)) {
            $mod = $attempt['modifier'] ?? 'le';
            $d1  = $attempt['date1'] ?? '';
            $d2  = $attempt['date2'] ?? '';
        } else {
            $d1 = '';
            break;
        }
    }

    $d1 = trim((string)$d1, "\"'\\ \t\n\r\0\x0B");
    $d2 = trim((string)$d2, "\"'\\ \t\n\r\0\x0B");

    if ($d1 === '' && $d2 === '') {
        return '';
    }

    $labels = [
        'le'    => 'Le',
        'avant' => 'Avant',
        'apres' => 'Après',
        'entre' => 'Entre',
    ];
    $prefix = $labels[$mod] ?? '';

    if ($mod === 'entre' && $d1 !== '' && $d2 !== '') {
        return "Entre {$d1} et {$d2}";
    }
    return trim($prefix . ' ' . $d1);
};

$resolveMediaUrl = static function ($item): string {
    $path = '';
    if (is_array($item)) {
        foreach (['crop_data', 'cropped_url', 'image_url', 'url', 'path', 'src', 'file', 'filename', 'photo'] as $key) {
            if (!empty($item[$key]) && is_string($item[$key])) {
                $path = trim($item[$key]);
                break;
            }
        }
    } elseif (is_string($item)) {
        $path = trim($item);
    }

    if ($path === '') {
        return '';
    }
    if (preg_match('#^(https?://|data:|/)#i', $path)) {
        return $path;
    }
    if (strpos($path, 'uploads/') === 0) {
        return '/' . $path;
    }
    return '/uploads/' . $path;
};

$formatPhotoDate = static function (array $p): string {
    if (!empty($p['date']) && is_string($p['date']) && $p['date'] !== '-') {
        return trim($p['date']);
    }
    if (!empty($p['date_prise_vue']) && is_string($p['date_prise_vue'])) {
        return trim($p['date_prise_vue']);
    }
    $parts = array_filter([
        trim((string)($p['jour'] ?? '')),
        trim((string)($p['mois'] ?? '')),
        trim((string)($p['annee'] ?? '')),
    ], fn($v) => $v !== '');
    return implode('/', $parts);
};

// Helper d'affichage minimaliste (affiche toujours le libellé, même si vide)
$renderField = static function (string $label, string $value, bool $isHtml = false, bool $fullWidth = false): string {
    $trimmed = trim($value);
    $itemClass = 'min-field' . ($fullWidth ? ' min-field--full' : '');
    $labelHtml = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');

    if ($trimmed === '') {
        $valHtml = '<span class="min-empty">Non renseigné</span>';
    } elseif ($isHtml) {
        $valHtml = '<div class="min-richtext">' . $trimmed . '</div>';
    } else {
        $valHtml = htmlspecialchars($trimmed, ENT_QUOTES, 'UTF-8');
    }

    return "<div class=\"{$itemClass}\"><dt class=\"min-field-label\">{$labelHtml}</dt><dd class=\"min-field-value\">{$valHtml}</dd></div>";
};

// =========================================================================
// PRÉPARATION DE TOUTES LES DONNÉES DE LA NOTICE
// =========================================================================

// 1. Description & Caractéristiques
$titres            = $cleanArray($monument['titre'] ?? null);
$titrePrincipal    = !empty($titres) ? $titres[0] : 'Sans titre';
$tousLesTitres     = implode(' ; ', $titres);
$attributionsTitre = implode(' ; ', $cleanArray($monument['attribution_titre'] ?? null));
$appellationsUsage = implode(' ; ', $cleanArray($monument['appellation_usage'] ?? null));
$sourcesRep        = implode(' ; ', $cleanArray($monument['source_representation'] ?? null));
$personnages       = implode(', ', $cleanArray($monument['personnage'] ?? null));
$typesOeuvreList   = $cleanArray($monument['type_oeuvre'] ?? null);
$typesOeuvre       = implode(', ', $typesOeuvreList);
$materiaux         = implode(', ', $cleanArray($monument['materiaux'] ?? null));
$techniques        = implode(', ', $cleanArray($monument['techniques'] ?? null));
$dimensions        = trim((string)($monument['dimensions'] ?? ''));
$descriptionHtml   = $formatRichText($monument['description'] ?? '');

// 2. Localisation
$pays              = trim((string)($monument['pays'] ?? ''));
$deptFrance        = trim((string)($monument['departement_france'] ?? ''));
$wilayaAlgerie     = trim((string)($monument['wilaya_algerie'] ?? ($monument['wilaya'] ?? '')));
$communesActuelles = implode(', ', $cleanArray($monument['communes_actuelles'] ?? ($monument['commune'] ?? null)));
$typeLocalisation  = trim((string)($monument['type_localisation'] ?? ''));
$communeAncienne   = trim((string)($monument['commune_ancienne'] ?? ''));
$deptAvant1962     = trim((string)($monument['departement_avant_1962'] ?? ''));
$infosLocalisation = $formatRichText($monument['informations_localisation'] ?? '');
$latitude          = trim((string)($monument['latitude'] ?? ''));
$longitude         = trim((string)($monument['longitude'] ?? ''));
$hasCoords         = ($latitude !== '' && $longitude !== '' && is_numeric($latitude) && is_numeric($longitude));
$coordsText        = $hasCoords ? "{$latitude}, {$longitude}" : '';

// 3. Photographies
$photosPayload = is_array($monument['photos_payload'] ?? null) ? $monument['photos_payload'] : [];
$photoCategories = [
    'actuelles' => ['label' => 'Photographies actuelles', 'items' => is_array($photosPayload['actuelles'] ?? null) ? $photosPayload['actuelles'] : []],
    'maquettes' => ['label' => 'Maquettes et dessins', 'items' => is_array($photosPayload['maquettes'] ?? null) ? $photosPayload['maquettes'] : []],
    'anciennes' => ['label' => 'Photographies anciennes et cartes postales', 'items' => is_array($photosPayload['anciennes'] ?? null) ? $photosPayload['anciennes'] : []],
];

$totalPhotos = count($photoCategories['actuelles']['items'])
             + count($photoCategories['maquettes']['items'])
             + count($photoCategories['anciennes']['items']);

if ($totalPhotos === 0 && !empty($monument['images']) && is_array($monument['images'])) {
    $photoCategories['actuelles']['items'] = $monument['images'];
    $totalPhotos = count($monument['images']);
}

// 4. Inscriptions
$inscriptions = is_array($monument['inscriptions_structures'] ?? null) ? $monument['inscriptions_structures'] : [];

// 5. Historique
$dateCreation     = $formatComplexDate($monument['annee_creation'] ?? '');
$dateCommande     = $formatComplexDate($monument['date_commande'] ?? '');
$dateInauguration = $formatComplexDate($monument['inauguration'] ?? '');
$dateDestruction  = $formatComplexDate($monument['destruction'] ?? '');
$expositions      = implode(' ; ', $cleanArray($monument['expositions'] ?? null));
$historiqueHtml   = $formatRichText($monument['historique'] ?? '');

// 6. Exécution & Auteurs
$auteurs = is_array($monument['auteurs_structures'] ?? null) ? $monument['auteurs_structures'] : [];

// 7. Financement
$commandeEtatMap = [
    'oui'     => 'Oui',
    'non'     => 'Non',
    'inconnu' => 'Ne sais pas',
];
$commandeEtatRaw      = trim((string)($monument['commande_etat'] ?? ''));
$commandeEtatLabel    = $commandeEtatMap[$commandeEtatRaw] ?? $commandeEtatRaw;
$prixTotalTexte       = trim((string)($monument['prix_total_texte'] ?? ''));
$souscription         = trim((string)($monument['souscription'] ?? ''));
$subventionCommune    = trim((string)($monument['subvention_commune'] ?? ''));
$subventionEtat       = trim((string)($monument['subvention_etat'] ?? ''));
$produitFete          = trim((string)($monument['produit_fete'] ?? ''));
$donsParticuliers     = trim((string)($monument['dons_particuliers'] ?? ''));
$donsEntreprises      = trim((string)($monument['dons_entreprises'] ?? ''));
$donsAutres           = trim((string)($monument['dons_autres'] ?? ''));
$emprunt              = trim((string)($monument['emprunt'] ?? ''));
$autresSubventions    = trim((string)($monument['autres_subventions'] ?? ''));
$commentairesEconomie = $formatRichText($monument['commentaires_economie'] ?? '');

// 8. Documentation
$documentation = is_array($monument['documentation_zotero'] ?? null) ? $monument['documentation_zotero'] : [];

// 9. Protection
$protections          = implode(' ; ', $cleanArray($monument['protections'] ?? null));
$proprietairesActuels = trim((string)($monument['proprietaires_actuels'] ?? ''));
$idMerimee            = trim((string)($monument['id_merimee'] ?? ''));
$idJoconde            = trim((string)($monument['id_joconde'] ?? ''));
$idPalissy            = trim((string)($monument['id_palissy'] ?? ''));
$idMemoire            = trim((string)($monument['id_memoire'] ?? ''));

// 10. Analogies & Monuments liés
$analogies = $cleanArray($monument['analogies'] ?? null);
$linkedMonumentsResolved = [];
$linkedIds = is_array($monument['linked_monuments'] ?? null) ? $monument['linked_monuments'] : [];
if (!empty($linkedIds)) {
    foreach ($allMonuments as $am) {
        if (empty($am['deleted_at']) && in_array($am['id'], $linkedIds)) {
            $t = $am['titre'] ?? ['Sans titre'];
            $linkedMonumentsResolved[] = [
                'id'    => $am['id'],
                'titre' => is_array($t) ? ($t[0] ?? 'Sans titre') : (string)$t,
            ];
        }
    }
}

// 11. Gestion
$statutMap = [
    'publie'    => 'Publié',
    'brouillon' => 'Brouillon',
    'a_valider' => 'À valider',
];
$statutRaw        = trim((string)($monument['statut_publication'] ?? ''));
$statutLabel      = $statutMap[$statutRaw] ?? $statutRaw;
$numeroFiche      = trim((string)($monument['numero_fiche'] ?? ''));
$dateCreationNot  = trim((string)($monument['date_creation_notice'] ?? ''));
$dateModifNot     = trim((string)($monument['date_modification_notice'] ?? ''));
$redacteurNotice  = trim((string)($monument['redacteur_notice'] ?? ''));
$mentionsLegales  = trim((string)($monument['mentions_legales'] ?? ''));

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$currentUrl = $protocol . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '');
$previousPage = $_SERVER['HTTP_REFERER'] ?? 'dashboard.php';

ob_start();
?>
<?php if ($hasCoords): ?>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<?php endif; ?>

<div class="content-2 notice-minimal">

    <!-- =====================================================================
         BARRE SUPÉRIEURE : RETOUR & BOUTON MODIFIER / PROPOSER
         ===================================================================== -->
    <div class="notice-min-topbar">
        <a href="<?= htmlspecialchars($previousPage) ?>" class="btn btn-secondary">⬅ Retour</a>
        <?php if ($canEditOrPropose): ?>
            <a href="<?= htmlspecialchars($editUrl) ?>" class="btn btn-primary"><?= htmlspecialchars($editButtonLabel) ?></a>
        <?php endif; ?>
    </div>

    <!-- =====================================================================
         EN-TÊTE DE LA NOTICE & QR CODE (DIV pour ne pas écraser le header du site)
         ===================================================================== -->
    <div class="notice-min-header">
        <div>
            <div class="notice-min-meta-bar">
                <?php if ($numeroFiche !== ''): ?>
                    <span class="notice-min-ref"><?= htmlspecialchars($numeroFiche) ?></span>
                <?php endif; ?>
                <?php if ($statutLabel !== ''): ?>
                    <span class="notice-min-tag"><?= htmlspecialchars($statutLabel) ?></span>
                <?php endif; ?>
                <?php foreach ($typesOeuvreList as $to): ?>
                    <span class="notice-min-tag"><?= htmlspecialchars($to) ?></span>
                <?php endforeach; ?>
            </div>
            <h2 class="notice-min-title"><?= htmlspecialchars($titrePrincipal) ?></h2>
        </div>

        <div class="notice-min-qr">
            <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=<?= urlencode($currentUrl) ?>" alt="QR Code">
            <span>QR Code notice</span>
        </div>
    </div>

    <div class="notice-min-controls">
        <button type="button" class="notice-min-toggle-all" onclick="toggleAllAccordions(true)">Tout déplier</button>
        <button type="button" class="notice-min-toggle-all" onclick="toggleAllAccordions(false)">Tout replier</button>
    </div>

    <!-- =====================================================================
         1. PHOTOGRAPHIES ET DOCUMENTS ICONOGRAPHIQUES
         ===================================================================== -->
    <details class="min-accordion" open>
        <summary class="min-accordion-summary">
            <span>1. Photographies et documents iconographiques</span>
            <span class="min-accordion-summary-right">
                <span class="min-accordion-count"><?= $totalPhotos ?> média<?= $totalPhotos > 1 ? 's' : '' ?></span>
                <svg class="min-accordion-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
            </span>
        </summary>
        <div class="min-accordion-body">
            <?php if ($totalPhotos === 0): ?>
                <p class="min-empty">Aucune photographie ou illustration renseignée.</p>
            <?php else: ?>
                <?php foreach ($photoCategories as $catData): ?>
                    <?php if (!empty($catData['items'])): ?>
                        <div class="min-photo-group">
                            <h4 class="min-photo-group-title"><?= htmlspecialchars($catData['label']) ?> (<?= count($catData['items']) ?>)</h4>
                            <div class="min-photo-grid">
                                <?php foreach ($catData['items'] as $photo):
                                    $photoUrl = $resolveMediaUrl($photo);
                                    if ($photoUrl === '') continue;
                                    $pTitre  = is_array($photo) ? trim((string)($photo['titre'] ?? '')) : '';
                                    $pType   = is_array($photo) ? trim((string)($photo['type_document'] ?? ($photo['type_doc'] ?? ''))) : '';
                                    $pAuteur = is_array($photo) ? trim((string)($photo['auteur'] ?? '')) : '';
                                    $pLieu   = is_array($photo) ? trim((string)($photo['lieu'] ?? ($photo['lieu_prise_vue'] ?? ''))) : '';
                                    $pDate   = is_array($photo) ? $formatPhotoDate($photo) : '';
                                    $pDesc   = is_array($photo) ? trim((string)($photo['description'] ?? ($photo['desc'] ?? ''))) : '';
                                    $pSource = is_array($photo) ? trim((string)($photo['source'] ?? '')) : '';
                                    $captionLabel = $pTitre !== '' ? $pTitre : $catData['label'];
                                ?>
                                    <article class="min-photo-card">
                                        <div class="min-photo-thumb"
                                             onclick="openNoticeLightbox('<?= htmlspecialchars($photoUrl, ENT_QUOTES) ?>', '<?= htmlspecialchars($captionLabel, ENT_QUOTES) ?>')"
                                             title="Cliquer pour agrandir">
                                            <img src="<?= htmlspecialchars($photoUrl) ?>" alt="<?= htmlspecialchars($captionLabel) ?>" loading="lazy">
                                        </div>
                                        <div class="min-photo-meta">
                                            <strong class="photo-card-title"><?= htmlspecialchars($pTitre !== '' ? $pTitre : 'Sans titre') ?></strong>
                                            <div><strong>Type :</strong> <?= $pType !== '' ? htmlspecialchars($pType) : '<span class="min-empty">Non renseigné</span>' ?></div>
                                            <div><strong>Auteur :</strong> <?= ($pAuteur !== '' && $pAuteur !== '-') ? htmlspecialchars($pAuteur) : '<span class="min-empty">Non renseigné</span>' ?></div>
                                            <div><strong>Lieu :</strong> <?= ($pLieu !== '' && $pLieu !== '-') ? htmlspecialchars($pLieu) : '<span class="min-empty">Non renseigné</span>' ?></div>
                                            <div><strong>Date :</strong> <?= $pDate !== '' ? htmlspecialchars($pDate) : '<span class="min-empty">Non renseignée</span>' ?></div>
                                            <div><strong>Description :</strong> <?= ($pDesc !== '' && $pDesc !== '-') ? nl2br(htmlspecialchars($pDesc)) : '<span class="min-empty">Non renseignée</span>' ?></div>
                                            <div><strong>Source :</strong> <?= ($pSource !== '' && $pSource !== '-') ? htmlspecialchars($pSource) : '<span class="min-empty">Non renseignée</span>' ?></div>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </details>

    <!-- =====================================================================
         2. DESCRIPTION ET CARACTÉRISTIQUES
         ===================================================================== -->
    <details class="min-accordion" open>
        <summary class="min-accordion-summary">
            <span>2. Description et caractéristiques</span>
            <svg class="min-accordion-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
        </summary>
        <div class="min-accordion-body">
            <dl class="min-dl">
                <?= $renderField('Titre(s)', $tousLesTitres) ?>
                <?= $renderField('Attribution du titre', $attributionsTitre) ?>
                <?= $renderField('Appellation d\'usage', $appellationsUsage) ?>
                <?= $renderField('Source de la représentation', $sourcesRep) ?>
                <?= $renderField('Personnage(s) représenté(s)', $personnages) ?>
                <?= $renderField('Type d’œuvre', $typesOeuvre) ?>
                <?= $renderField('Matériaux', $materiaux) ?>
                <?= $renderField('Techniques', $techniques) ?>
                <?= $renderField('Dimensions (cm)', $dimensions) ?>
                <?= $renderField('Description', $descriptionHtml, true, true) ?>
            </dl>
        </div>
    </details>

    <!-- =====================================================================
         3. LOCALISATION
         ===================================================================== -->
    <details class="min-accordion" id="accordion-localisation" open>
        <summary class="min-accordion-summary">
            <span>3. Localisation</span>
            <svg class="min-accordion-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
        </summary>
        <div class="min-accordion-body">
            <dl class="min-dl">
                <?= $renderField('Pays', $pays) ?>
                <?= $renderField('Département (France)', $deptFrance) ?>
                <?= $renderField('Wilaya (Algérie)', $wilayaAlgerie) ?>
                <?= $renderField('Commune actuelle', $communesActuelles) ?>
                <?= $renderField('Type de localisation', $typeLocalisation) ?>
                <?= $renderField('Commune ancienne (avant 1962)', $communeAncienne) ?>
                <?= $renderField('Département avant 1962', $deptAvant1962) ?>
                <?= $renderField('Coordonnées GPS (Lat, Lng)', $coordsText) ?>
                <?= $renderField('Informations de localisation', $infosLocalisation, true, true) ?>
            </dl>

            <?php if ($hasCoords): ?>
                <div id="notice-map" class="min-map"></div>
            <?php endif; ?>
        </div>
    </details>

    <!-- =====================================================================
         4. INSCRIPTIONS
         ===================================================================== -->
    <details class="min-accordion" open>
        <summary class="min-accordion-summary">
            <span>4. Inscriptions</span>
            <span class="min-accordion-summary-right">
                <span class="min-accordion-count"><?= count($inscriptions) ?></span>
                <svg class="min-accordion-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
            </span>
        </summary>
        <div class="min-accordion-body">
            <?php if (empty($inscriptions)): ?>
                <p class="min-empty">Aucune inscription renseignée.</p>
            <?php else: ?>
                <table class="min-table">
                    <thead>
                        <tr>
                            <th style="width: 80px;">Photographie</th>
                            <th style="width: 24%;">Type d'inscription</th>
                            <th>Contenu / Retranscription</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($inscriptions as $insc):
                            $inscPhotoUrl = $resolveMediaUrl($insc);
                            $inscType     = trim((string)($insc['type'] ?? ($insc['type_inscription'] ?? '')));
                            $inscTexte    = trim((string)($insc['contenu'] ?? ($insc['retranscription'] ?? ($insc['texte'] ?? ''))));
                        ?>
                            <tr>
                                <td>
                                    <?php if ($inscPhotoUrl !== ''): ?>
                                        <img src="<?= htmlspecialchars($inscPhotoUrl) ?>"
                                             alt="Inscription"
                                             class="min-table-thumb"
                                             onclick="openNoticeLightbox('<?= htmlspecialchars($inscPhotoUrl, ENT_QUOTES) ?>', '<?= htmlspecialchars($inscType ?: 'Inscription', ENT_QUOTES) ?>')">
                                    <?php else: ?>
                                        <span class="min-empty">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= $inscType !== '' ? htmlspecialchars($inscType) : '<span class="min-empty">Non renseigné</span>' ?></td>
                                <td><?= $inscTexte !== '' ? nl2br(htmlspecialchars($inscTexte)) : '<span class="min-empty">Non renseigné</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </details>

    <!-- =====================================================================
         5. HISTORIQUE
         ===================================================================== -->
    <details class="min-accordion" open>
        <summary class="min-accordion-summary">
            <span>5. Historique</span>
            <svg class="min-accordion-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
        </summary>
        <div class="min-accordion-body">
            <dl class="min-dl">
                <?= $renderField('Année de création', $dateCreation) ?>
                <?= $renderField('Date de commande', $dateCommande) ?>
                <?= $renderField('Date d\'inauguration', $dateInauguration) ?>
                <?= $renderField('Date de destruction', $dateDestruction) ?>
                <?= $renderField('Expositions', $expositions, false, true) ?>
                <?= $renderField('Récit historique', $historiqueHtml, true, true) ?>
            </dl>
        </div>
    </details>

    <!-- =====================================================================
         6. EXÉCUTION ET AUTEURS
         ===================================================================== -->
    <details class="min-accordion" open>
        <summary class="min-accordion-summary">
            <span>6. Exécution et auteurs</span>
            <span class="min-accordion-summary-right">
                <span class="min-accordion-count"><?= count($auteurs) ?></span>
                <svg class="min-accordion-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
            </span>
        </summary>
        <div class="min-accordion-body">
            <?php if (empty($auteurs)): ?>
                <p class="min-empty">Aucun auteur renseigné.</p>
            <?php else: ?>
                <table class="min-table">
                    <thead>
                        <tr>
                            <th>Métier</th>
                            <th>Auteur</th>
                            <th>A signé le monument ?</th>
                            <th>Partie du monument</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($auteurs as $aut):
                            $aMetier = trim((string)($aut['metier'] ?? ''));
                            $aNom    = trim((string)($aut['auteur'] ?? ($aut['nom'] ?? '')));
                            $aSigne  = !empty($aut['a_signe']) || !empty($aut['signe']);
                            $aPartie = trim((string)($aut['partie'] ?? ''));
                        ?>
                            <tr>
                                <td><?= $aMetier !== '' ? htmlspecialchars($aMetier) : '<span class="min-empty">Non renseigné</span>' ?></td>
                                <td><strong><?= $aNom !== '' ? htmlspecialchars($aNom) : '<span class="min-empty">Non renseigné</span>' ?></strong></td>
                                <td><?= $aSigne ? 'Oui' : 'Non' ?></td>
                                <td><?= $aPartie !== '' ? htmlspecialchars($aPartie) : '<span class="min-empty">Non renseignée</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </details>

    <!-- =====================================================================
         7. FINANCEMENT
         ===================================================================== -->
    <details class="min-accordion" open>
        <summary class="min-accordion-summary">
            <span>7. Financement</span>
            <svg class="min-accordion-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
        </summary>
        <div class="min-accordion-body">
            <dl class="min-dl">
                <?= $renderField('Prix total', $prixTotalTexte) ?>
                <?= $renderField('Commande de l\'État', $commandeEtatLabel) ?>
                <?= $renderField('Souscription', $souscription) ?>
                <?= $renderField('Subvention commune', $subventionCommune) ?>
                <?= $renderField('Subvention de l\'État', $subventionEtat) ?>
                <?= $renderField('Produit fête', $produitFete) ?>
                <?= $renderField('Dons de particuliers', $donsParticuliers) ?>
                <?= $renderField('Dons d\'entreprises', $donsEntreprises) ?>
                <?= $renderField('Dons (autres)', $donsAutres) ?>
                <?= $renderField('Emprunt', $emprunt) ?>
                <?= $renderField('Autres subventions', $autresSubventions) ?>
                <?= $renderField('Commentaires financement', $commentairesEconomie, true, true) ?>
            </dl>
        </div>
    </details>

    <!-- =====================================================================
         8. DOCUMENTATION ET BIBLIOGRAPHIE
         ===================================================================== -->
    <details class="min-accordion" open>
        <summary class="min-accordion-summary">
            <span>8. Documentation et bibliographie</span>
            <span class="min-accordion-summary-right">
                <span class="min-accordion-count"><?= count($documentation) ?></span>
                <svg class="min-accordion-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
            </span>
        </summary>
        <div class="min-accordion-body">
            <?php if (empty($documentation)): ?>
                <p class="min-empty">Aucune référence documentaire renseignée.</p>
            <?php else: ?>
                <table class="min-table">
                    <thead>
                        <tr>
                            <th style="width: 13%;">Type</th>
                            <th style="width: 24%;">Titre</th>
                            <th style="width: 18%;">Auteur(s)</th>
                            <th style="width: 8%;">Année</th>
                            <th style="width: 17%;">Gallica / Extrait</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($documentation as $doc):
                            $dType    = trim((string)($doc['type'] ?? ''));
                            $dTitre   = trim((string)($doc['titre'] ?? ''));
                            $dAuteur  = trim((string)($doc['auteur'] ?? ($doc['auteurs'] ?? '')));
                            $dAnnee   = trim((string)($doc['annee'] ?? ''));
                            $dGallica = trim((string)($doc['lien_gallica'] ?? ($doc['url'] ?? '')));
                            $dImg     = $resolveMediaUrl($doc);
                            $dNotes   = trim((string)($doc['notes'] ?? ''));
                        ?>
                            <tr>
                                <td><?= $dType !== '' ? htmlspecialchars($dType) : '<span class="min-empty">—</span>' ?></td>
                                <td><strong><?= $dTitre !== '' ? htmlspecialchars($dTitre) : '<span class="min-empty">Non renseigné</span>' ?></strong></td>
                                <td><?= $dAuteur !== '' ? htmlspecialchars($dAuteur) : '<span class="min-empty">Non renseigné</span>' ?></td>
                                <td><?= $dAnnee !== '' ? htmlspecialchars($dAnnee) : '<span class="min-empty">—</span>' ?></td>
                                <td>
                                    <?php if ($dImg !== ''): ?>
                                        <img src="<?= htmlspecialchars($dImg) ?>"
                                             alt="Extrait documentaire"
                                             class="min-table-thumb"
                                             style="display:block; margin-bottom:4px;"
                                             onclick="openNoticeLightbox('<?= htmlspecialchars($dImg, ENT_QUOTES) ?>', '<?= htmlspecialchars($dTitre ?: 'Extrait documentaire', ENT_QUOTES) ?>')">
                                    <?php endif; ?>
                                    <?php if ($dGallica !== ''): ?>
                                        <a href="<?= htmlspecialchars($dGallica) ?>" target="_blank" rel="noopener noreferrer">Consulter sur Gallica &nearr;</a>
                                    <?php elseif ($dImg === ''): ?>
                                        <span class="min-empty">Non renseigné</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= $dNotes !== '' ? nl2br(htmlspecialchars($dNotes)) : '<span class="min-empty">Non renseignée</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </details>

    <!-- =====================================================================
         9. PROTECTION ET BASES PATRIMONIALES
         ===================================================================== -->
    <details class="min-accordion" open>
        <summary class="min-accordion-summary">
            <span>9. Protection et bases patrimoniales</span>
            <svg class="min-accordion-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
        </summary>
        <div class="min-accordion-body">
            <dl class="min-dl">
                <?= $renderField('Protection(s)', $protections) ?>
                <?= $renderField('Propriétaire actuel', $proprietairesActuels) ?>
                <?= $renderField('ID Base Mérimée', $idMerimee) ?>
                <?= $renderField('ID Base Joconde', $idJoconde) ?>
                <?= $renderField('ID Base Palissy', $idPalissy) ?>
                <?= $renderField('ID Base Mémoire', $idMemoire) ?>
            </dl>
        </div>
    </details>

    <!-- =====================================================================
         10. ANALOGIES ET MONUMENTS LIÉS
         ===================================================================== -->
    <details class="min-accordion" open>
        <summary class="min-accordion-summary">
            <span>10. Analogies et monuments liés</span>
            <svg class="min-accordion-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
        </summary>
        <div class="min-accordion-body">
            <dl class="min-dl">
                <div class="min-field min-field--full">
                    <dt class="min-field-label">Analogies</dt>
                    <dd class="min-field-value">
                        <?php if (empty($analogies)): ?>
                            <span class="min-empty">Non renseigné</span>
                        <?php else: ?>
                            <ul class="min-list">
                                <?php foreach ($analogies as $an): ?>
                                    <li><?= htmlspecialchars($an) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </dd>
                </div>

                <div class="min-field min-field--full">
                    <dt class="min-field-label">Monuments liés dans l'inventaire</dt>
                    <dd class="min-field-value">
                        <?php if (empty($linkedMonumentsResolved)): ?>
                            <span class="min-empty">Aucun monument lié</span>
                        <?php else: ?>
                            <div class="min-linked-list">
                                <?php foreach ($linkedMonumentsResolved as $lm): ?>
                                    <a href="viewer_oeuvre.php?id=<?= urlencode((string)$lm['id']) ?>" class="min-linked-pill">
                                        <?= htmlspecialchars($lm['titre']) ?> &rarr;
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </dd>
                </div>
            </dl>
        </div>
    </details>

    <!-- =====================================================================
         11. INFORMATIONS DE GESTION
         ===================================================================== -->
    <details class="min-accordion" open>
        <summary class="min-accordion-summary">
            <span>11. Informations de gestion</span>
            <svg class="min-accordion-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
        </summary>
        <div class="min-accordion-body">
            <dl class="min-dl">
                <?= $renderField('Référence notice', $numeroFiche) ?>
                <?= $renderField('Statut de publication', $statutLabel) ?>
                <?= $renderField('Création de la notice', $dateCreationNot) ?>
                <?= $renderField('Dernière modification', $dateModifNot) ?>
                <?= $renderField('Rédacteur', $redacteurNotice) ?>
                <?= $renderField('Mentions légales', $mentionsLegales) ?>
            </dl>
        </div>
    </details>

    <!-- =====================================================================
         BARRE D'ACTIONS BAS DE PAGE
         ===================================================================== -->
    <div class="notice-min-actions">
        <a href="<?= htmlspecialchars($previousPage) ?>" class="btn btn-secondary">⬅ Retour</a>
        <?php if ($canEditOrPropose): ?>
            <a href="<?= htmlspecialchars($editUrl) ?>" class="btn btn-primary"><?= htmlspecialchars($editButtonLabel) ?></a>
        <?php endif; ?>
    </div>
</div>

<!-- VISIONNEUSE LIGHTBOX POUR LES PHOTOS -->
<div class="notice-lightbox" id="notice-lightbox" onclick="closeNoticeLightbox()">
    <div class="notice-lightbox-inner" onclick="event.stopPropagation();">
        <button type="button" class="notice-lightbox-close" onclick="closeNoticeLightbox()" aria-label="Fermer">✖</button>
        <img id="notice-lightbox-img" src="" alt="">
        <div id="notice-lightbox-caption" class="notice-lightbox-caption"></div>
    </div>
</div>

<script>
function toggleAllAccordions(openState) {
    document.querySelectorAll('.min-accordion').forEach(acc => {
        acc.open = openState;
    });
}

function openNoticeLightbox(src, caption) {
    const box = document.getElementById('notice-lightbox');
    const img = document.getElementById('notice-lightbox-img');
    const cap = document.getElementById('notice-lightbox-caption');
    img.src = src;
    img.alt = caption || '';
    cap.textContent = caption || '';
    box.classList.add('is-open');
}

function closeNoticeLightbox() {
    document.getElementById('notice-lightbox').classList.remove('is-open');
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        closeNoticeLightbox();
    }
});

<?php if ($hasCoords): ?>
document.addEventListener('DOMContentLoaded', function () {
    const lat = <?= (float)$latitude ?>;
    const lng = <?= (float)$longitude ?>;
    const map = L.map('notice-map', { scrollWheelZoom: false }).setView([lat, lng], 13);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 18,
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);
    L.marker([lat, lng]).addTo(map).bindPopup(<?= json_encode($titrePrincipal) ?>);

    const locAccordion = document.getElementById('accordion-localisation');
    if (locAccordion) {
        locAccordion.addEventListener('toggle', function () {
            if (locAccordion.open) {
                setTimeout(() => map.invalidateSize(), 150);
            }
        });
    }
});
<?php endif; ?>
</script>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/layout.php';
?>