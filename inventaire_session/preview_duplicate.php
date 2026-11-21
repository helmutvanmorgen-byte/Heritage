<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

if (empty($_SESSION['user_id']) || empty($_SESSION['user_role'])) {
    die("Non connecté.");
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$userId   = (int)$_SESSION['user_id'];
$role     = $_SESSION['user_role'];
$sourceId = (int)($_GET['id'] ?? 0);

if (!$sourceId) {
    die("ID manquant.");
}

$dataFile  = __DIR__ . '/../json/monuments.json';
$monuments = file_exists($dataFile) ? json_decode(file_get_contents($dataFile), true) : [];
if (!is_array($monuments)) {
    $monuments = [];
}

// Index des titres pour résoudre les monuments liés (ID -> Titre)
$monumentTitlesMap = [];
$monument = null;
foreach ($monuments as $m) {
    $mId = (int)($m['id'] ?? 0);
    $t   = $m['titre'] ?? 'Sans titre';
    $monumentTitlesMap[$mId] = is_array($t) ? ($t[0] ?? 'Sans titre') : (string)$t;

    if ($mId === $sourceId && empty($m['deleted_at'])) {
        $monument = $m;
    }
}

if (!$monument || ($role !== 'admin' && (int)$monument['user_id'] !== $userId)) {
    die("Accès refusé ou fiche introuvable.");
}

// Champs système, médias et onglet GESTION (régénérés automatiquement)
$excludeFields = [
    'id', 'user_id', 'created_at', 'updated_at', 'deleted_at',
    'propositions', 'historique_versions', 'images', 'photos_payload',
    'numero_fiche', 'date_creation_notice', 'date_modification_notice',
    'redacteur_notice', 'statut_publication', 'mentions_legales'
];

// Correspondance exacte avec tous les onglets de monument_form.php
$sectionsConfig = [
    'localisation' => [
        'title'  => '📍 Localisation',
        'fields' => [
            'pays'                      => 'Pays',
            'departement_france'        => 'Département (France)',
            'wilaya_algerie'            => 'Wilaya (Algérie)',
            'type_localisation'         => 'Type de localisation',
            'communes_actuelles'        => 'Commune actuelle',
            'commune_ancienne'          => 'Commune ancienne (av. 1962)',
            'departement_avant_1962'    => 'Département avant 1962',
            'informations_localisation' => 'Informations de localisation',
            'latitude'                  => 'Latitude',
            'longitude'                 => 'Longitude'
        ]
    ],
    'description' => [
        'title'  => '📝 Description',
        'fields' => [
            'titre'                 => 'Titre',
            'attribution_titre'     => 'Attribution titre',
            'appellation_usage'     => 'Appellation d\'usage',
            'source_representation' => 'Source de la représentation',
            'personnage'            => 'Personnage(s)',
            'type_oeuvre'           => 'Type d’œuvre',
            'materiaux'             => 'Matériaux',
            'techniques'            => 'Techniques',
            'dimensions'            => 'Dimensions (cm)',
            'description'           => 'Description'
        ]
    ],
    'inscription' => [
        'title'  => '✍️ Inscription',
        'fields' => [
            'inscriptions_structures' => 'Inscriptions & retranscriptions'
        ]
    ],
    'historique' => [
        'title'  => '🏛️ Historique',
        'fields' => [
            'annee_creation' => 'Année de création',
            'date_commande'  => 'Date de commande',
            'inauguration'   => 'Date d\'inauguration',
            'destruction'    => 'Date de destruction',
            'historique'     => 'Historique',
            'expositions'    => 'Expositions'
        ]
    ],
    'execution' => [
        'title'  => '🔨 Exécution',
        'fields' => [
            'auteurs_structures' => 'Auteurs (sculpteur, architecte, fondeur...)'
        ]
    ],
    'financement' => [
        'title'  => '💰 Financement',
        'fields' => [
            'prix_total_texte'      => 'Prix total (texte)',
            'commande_etat'         => 'Commande de l\'État',
            'souscription'          => 'Souscription',
            'subvention_commune'    => 'Subvention commune',
            'subvention_etat'       => 'Subvention de l\'État',
            'produit_fete'          => 'Produit fête',
            'dons_particuliers'     => 'Dons de particuliers',
            'dons_entreprises'      => 'Dons d\'entreprises',
            'dons_autres'           => 'Dons (autres)',
            'emprunt'               => 'Emprunt',
            'autres_subventions'    => 'Autres subventions',
            'commentaires_economie' => 'Commentaires financement'
        ]
    ],
    'documentation' => [
        'title'  => '📚 Documentation',
        'fields' => [
            'documentation_zotero' => 'Références documentaires & Gallica'
        ]
    ],
    'protection_analogie' => [
        'title'  => '🛡️ Protection & Analogie',
        'fields' => [
            'protections'           => 'Protections',
            'proprietaires_actuels' => 'Propriétaire actuel',
            'id_merimee'            => 'ID Base Mérimée',
            'id_joconde'            => 'ID Base Joconde',
            'id_palissy'            => 'ID Base Palissy',
            'id_memoire'            => 'ID Base Mémoire',
            'analogies'             => 'Analogies',
            'linked_monuments'      => 'Monuments liés'
        ]
    ]
];

function formatPreviewField(string $key, $value, array $titlesMap): string {
    if ($value === null || $value === '' || $value === []) {
        return '';
    }

    if ($key === 'commande_etat') {
        $map = ['oui' => 'Oui', 'non' => 'Non', 'inconnu' => 'Ne sais pas'];
        return $map[(string)$value] ?? (string)$value;
    }

    if ($key === 'auteurs_structures' && is_array($value)) {
        $parts = [];
        foreach ($value as $aut) {
            if (!is_array($aut)) continue;
            $label = trim(($aut['metier'] ?? '') . ' : ' . ($aut['nom'] ?? ''), ' :');
            if (!empty($aut['partie'])) $label .= ' (' . $aut['partie'] . ')';
            if (!empty($aut['a_signe'])) $label .= ' [signé]';
            if ($label !== '') $parts[] = $label;
        }
        return implode(' ; ', $parts);
    }

    if ($key === 'inscriptions_structures' && is_array($value)) {
        $parts = [];
        foreach ($value as $ins) {
            if (!is_array($ins)) continue;
            $txt = '';
            if (!empty($ins['type'])) $txt .= '[' . $ins['type'] . '] ';
            if (!empty($ins['contenu'])) $txt .= $ins['contenu'];
            if (!empty($ins['photo'])) $txt .= ($txt !== '' ? ' ' : '') . '(📷 photo jointe)';
            if (trim($txt) !== '') $parts[] = trim($txt);
        }
        return implode(' ; ', $parts);
    }

    if ($key === 'documentation_zotero' && is_array($value)) {
        $parts = [];
        foreach ($value as $doc) {
            if (!is_array($doc)) continue;
            $txt = '';
            if (!empty($doc['type'])) $txt .= '[' . $doc['type'] . '] ';
            $txt .= $doc['titre'] ?? '';
            $meta = array_filter([$doc['auteur'] ?? '', $doc['annee'] ?? '']);
            if (!empty($meta)) $txt .= ' (' . implode(', ', $meta) . ')';
            if (trim($txt) !== '') $parts[] = trim($txt);
        }
        return implode(' ; ', $parts);
    }

    if ($key === 'linked_monuments' && is_array($value)) {
        $parts = [];
        foreach ($value as $linkedId) {
            $idInt = (int)$linkedId;
            $parts[] = $titlesMap[$idInt] ?? ('Fiche #' . $idInt);
        }
        return implode(', ', $parts);
    }

    if (is_array($value)) {
        // Dates complexes (le / avant / après / entre)
        if (array_key_exists('modifier', $value) || array_key_exists('date1', $value)) {
            $d1 = trim((string)($value['date1'] ?? ''));
            $d2 = trim((string)($value['date2'] ?? ''));
            if ($d1 === '' && $d2 === '') return '';
            $mod = $value['modifier'] ?? 'le';
            return trim($mod . ' ' . $d1 . ($d2 !== '' ? ' et ' . $d2 : ''));
        }

        // Tableaux dynamiques simples
        $flat = array_filter(array_map(function ($v) {
            return is_scalar($v) ? trim((string)$v) : '';
        }, $value), fn($v) => $v !== '');
        return implode(', ', $flat);
    }

    return trim(strip_tags((string)$value));
}

$groupedSections = [];
$mappedKeys      = [];

foreach ($sectionsConfig as $secKey => $secInfo) {
    $items = [];
    foreach ($secInfo['fields'] as $fieldKey => $label) {
        if (in_array($fieldKey, $excludeFields, true)) continue;
        $rawVal  = $monument[$fieldKey] ?? null;
        $display = formatPreviewField($fieldKey, $rawVal, $monumentTitlesMap);
        $items[] = [
            'key'     => $fieldKey,
            'label'   => $label,
            'value'   => $display,
            'isEmpty' => ($display === '')
        ];
        $mappedKeys[] = $fieldKey;
    }
    $groupedSections[$secKey] = [
        'title' => $secInfo['title'],
        'items' => $items
    ];
}

// Conservation d'éventuelles anciennes données non vides hors schéma actuel
$legacyItems = [];
foreach ($monument as $k => $v) {
    if (in_array($k, $excludeFields, true) || in_array($k, $mappedKeys, true)) continue;
    $display = formatPreviewField($k, $v, $monumentTitlesMap);
    if ($display !== '') {
        $legacyItems[] = [
            'key'     => $k,
            'label'   => ucfirst(str_replace('_', ' ', $k)),
            'value'   => $display,
            'isEmpty' => false
        ];
    }
}
if (!empty($legacyItems)) {
    $groupedSections['legacy'] = [
        'title' => '📎 Autres données enregistrées',
        'items' => $legacyItems
    ];
}

// Comptage de toutes les photos (photos_payload + images)
$photoCount = 0;
if (!empty($monument['photos_payload']) && is_array($monument['photos_payload'])) {
    foreach (['actuelles', 'maquettes', 'anciennes'] as $pSec) {
        if (!empty($monument['photos_payload'][$pSec]) && is_array($monument['photos_payload'][$pSec])) {
            $photoCount += count($monument['photos_payload'][$pSec]);
        }
    }
}
if (!empty($monument['images']) && is_array($monument['images'])) {
    $photoCount += count($monument['images']);
}

$titreNotice = is_array($monument['titre'] ?? null)
    ? ($monument['titre'][0] ?? 'Sans titre')
    : ($monument['titre'] ?? 'Sans titre');

ob_start();
?>
<div class="content-3 dup-container">
    <div class="dup-header">
        <div>
            <h2>Prévisualisation de la duplication</h2>
            <p class="dup-subtitle">
                Notice source : <strong><?= htmlspecialchars($titreNotice) ?></strong>
                <span class="dup-ref-badge"><?= htmlspecialchars($monument['numero_fiche'] ?? '') ?></span>
                — Les informations de l'onglet <em>Gestion</em> seront générées automatiquement.
            </p>
        </div>
        <div class="dup-counter-badge">
            <span id="dup-selected-count">0</span> champ(s) sélectionné(s)
        </div>
    </div>

    <form method="post" action="duplicate_monument.php" id="duplicate-form">
        <input type="hidden" name="id" value="<?= $sourceId ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

        <div class="dup-toolbar">
            <div class="dup-toolbar-actions">
                <button type="button" class="btn-secondary dup-btn-sm" onclick="setDuplicateSelection('filled')">✨ Champs remplis uniquement</button>
                <button type="button" class="btn-secondary dup-btn-sm" onclick="setDuplicateSelection('all')">☑️ Tout cocher</button>
                <button type="button" class="btn-secondary dup-btn-sm" onclick="setDuplicateSelection('none')">⬜ Tout décocher</button>
            </div>
        </div>

        <div class="dup-sections-grid">
            <?php foreach ($groupedSections as $secKey => $section): ?>
                <div class="dup-section-card" data-section="<?= htmlspecialchars($secKey) ?>">
                    <div class="dup-section-header">
                        <span class="dup-section-title"><?= htmlspecialchars($section['title']) ?></span>
                        <label class="dup-section-toggle" title="Cocher / décocher toute cette rubrique">
                            <input type="checkbox" class="dup-sec-cb" onchange="toggleSectionFields(this)">
                            <span>Tout</span>
                        </label>
                    </div>

                    <div class="dup-section-body">
                        <?php foreach ($section['items'] as $item): ?>
                            <label class="dup-field-row <?= $item['isEmpty'] ? 'is-empty' : 'is-filled' ?>">
                                <input type="checkbox"
                                       class="dup-field-cb"
                                       name="fields[]"
                                       value="<?= htmlspecialchars($item['key']) ?>"
                                       data-filled="<?= $item['isEmpty'] ? '0' : '1' ?>"
                                       <?= !$item['isEmpty'] ? 'checked' : '' ?>>

                                <span class="dup-field-name"><?= htmlspecialchars($item['label']) ?></span>

                                <span class="dup-field-val">
                                    <?php if (!$item['isEmpty']): ?>
                                        <?= htmlspecialchars(mb_strimwidth($item['value'], 0, 120, '...', 'UTF-8')) ?>
                                    <?php else: ?>
                                        <span class="dup-empty-tag">Vide</span>
                                    <?php endif; ?>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="dup-footer">
            <label class="dup-media-option">
                <input type="checkbox" name="copy_images" value="1" checked>
                <span>
                    Dupliquer également l'onglet Photos (photographies actuelles, maquettes, anciennes)
                    <span class="dup-media-count">(<?= $photoCount ?> média<?= $photoCount > 1 ? 's' : '' ?>)</span>
                </span>
            </label>

            <div class="dup-footer-buttons">
                <a href="dashboard.php" class="btn-secondary dup-btn-action">⬅ Annuler</a>
                <button type="submit" class="btn-primary dup-btn-action">📋 Dupliquer la fiche</button>
            </div>
        </div>
    </form>
</div>

<script>
function updateDuplicateCounters() {
    const allCbs = document.querySelectorAll('.dup-field-cb');
    const checkedCount = Array.from(allCbs).filter(cb => cb.checked).length;
    const countEl = document.getElementById('dup-selected-count');
    if (countEl) countEl.textContent = checkedCount;

    document.querySelectorAll('.dup-field-row').forEach(row => {
        const cb = row.querySelector('.dup-field-cb');
        row.classList.toggle('is-checked', cb && cb.checked);
    });

    document.querySelectorAll('.dup-section-card').forEach(card => {
        const secCb = card.querySelector('.dup-sec-cb');
        const cbs = Array.from(card.querySelectorAll('.dup-field-cb'));
        if (secCb && cbs.length > 0) {
            const allChecked = cbs.every(c => c.checked);
            const someChecked = cbs.some(c => c.checked);
            secCb.checked = allChecked;
            secCb.indeterminate = !allChecked && someChecked;
        }
    });
}

function setDuplicateSelection(mode) {
    document.querySelectorAll('.dup-field-cb').forEach(cb => {
        if (mode === 'all') cb.checked = true;
        else if (mode === 'none') cb.checked = false;
        else if (mode === 'filled') cb.checked = (cb.getAttribute('data-filled') === '1');
    });
    updateDuplicateCounters();
}

function toggleSectionFields(secCheckbox) {
    const card = secCheckbox.closest('.dup-section-card');
    if (!card) return;
    card.querySelectorAll('.dup-field-cb').forEach(cb => {
        cb.checked = secCheckbox.checked;
    });
    updateDuplicateCounters();
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.dup-field-cb').forEach(cb => {
        cb.addEventListener('change', updateDuplicateCounters);
    });
    updateDuplicateCounters();
});
</script>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/layout.php';
?>