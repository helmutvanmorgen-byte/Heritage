<?php
session_start();
date_default_timezone_set('Europe/Paris');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/MonumentConfig.php';
require_once __DIR__ . '/../includes/ThesaurusManager.php';

if (!isset($_SESSION['user_id'], $_SESSION['user_role'])) {
    die("Non connecté.");
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

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

$userId = $_SESSION['user_id'];
$role = $_SESSION['user_role'];
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;

$monument = MonumentConfig::getDefaultMonument();
$linkedMonumentsData = [];
$isOwnerOrAdmin = true;

$dataFile = __DIR__ . '/../json/monuments.json';
$allMonuments = file_exists($dataFile) ? json_decode(file_get_contents($dataFile), true) : [];
if (!is_array($allMonuments)) $allMonuments = [];

$allMonumentsSearch = [];
foreach ($allMonuments as $am) {
    if (empty($am['deleted_at']) && (int)($am['id'] ?? 0) !== (int)$id) {
        $t = $am['titre'] ?? ['Sans titre'];
        $allMonumentsSearch[] = [
            'id' => $am['id'],
            'titre' => is_array($t) ? ($t[0] ?? 'Sans titre') : $t
        ];
    }
}

$thesaurusFile = __DIR__ . '/../json/thesaurus.json';
$jsonDir       = __DIR__ . '/../json';

function ensureThesaurusKeyForm(string $file, string $key, array $defaultValues): void
{
    $dir = dirname($file);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $data = [];
    if (file_exists($file)) {
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($file));
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }
    if (!array_key_exists($key, $data)) {
        $data[$key] = array_values($defaultValues);
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
}

function getMetierMetaForm(string $metier): array
{
    $map = [
        'sculpteur'    => ['json' => 'sculpteurs.json',    'prefix' => 'SCULP'],
        'architecte'   => ['json' => 'architectes.json',   'prefix' => 'ARCHI'],
        'fondeur'      => ['json' => 'fondeurs.json',      'prefix' => 'FOND'],
        'medailleur'   => ['json' => 'medailleurs.json',   'prefix' => 'MEDAIL'],
        'entrepreneur' => ['json' => 'entrepreneurs.json', 'prefix' => 'ENTREP'],
        'marbrier'     => ['json' => 'marbriers.json',     'prefix' => 'MARBR'],
    ];
    $normalized = mb_strtolower(trim($metier), 'UTF-8');
    $ascii = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized) : $normalized;
    $slug = preg_replace('/[^a-z0-9]+/', '_', $ascii ?: $normalized);
    $slug = trim($slug, '_');

    if (isset($map[$slug])) {
        return $map[$slug];
    }
    return [
        'json'   => ($slug !== '' ? $slug . 's' : 'autres_auteurs') . '.json',
        'prefix' => strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $slug), 0, 5) ?: 'PERS')
    ];
}

function loadMetierPersonsForm(string $file, array $defaultNames = [], string $prefix = 'PERS'): array
{
    $dir = dirname($file);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    if (!file_exists($file)) {
        $initialData = [];
        $now = date('d/m/Y à H:i:s');
        foreach ($defaultNames as $idx => $name) {
            $clean = trim((string)$name);
            if ($clean !== '') {
                $initialData[] = [
                    'id'                       => $idx + 1,
                    'nom'                      => $clean,
                    'prenom'                   => '',
                    'sexe'                     => '',
                    'naissance'                => ['lieu' => '', 'pays' => '', 'annee' => ''],
                    'deces'                    => ['lieu' => '', 'pays' => '', 'annee' => ''],
                    'biographie'               => '',
                    'permalien'                => '',
                    'numero_fiche'             => $prefix . '-' . date('Y') . '-' . strtoupper(substr(uniqid(), -5)),
                    'date_creation_notice'     => $now,
                    'date_modification_notice' => $now,
                    'user_id'                  => 1
                ];
            }
        }
        file_put_contents($file, json_encode($initialData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    $raw = preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($file));
    $items = json_decode($raw, true);
    $list = [];
    if (is_array($items)) {
        foreach ($items as $item) {
            if (is_array($item)) {
                if (!empty($item['deleted_at'])) {
                    continue;
                }
                $nom = trim((string)($item['nom'] ?? ''));
                $prenom = trim((string)($item['prenom'] ?? ''));
                if ($nom !== '' || $prenom !== '') {
                    $list[] = trim($nom . ' ' . $prenom);
                }
            } elseif (is_string($item) && trim($item) !== '') {
                $list[] = trim($item);
            }
        }
    }
    $list = array_values(array_unique($list));
    sort($list, SORT_NATURAL | SORT_FLAG_CASE);
    return $list;
}

ensureThesaurusKeyForm($thesaurusFile, 'typesOeuvre', MonumentConfig::getTypes());
ensureThesaurusKeyForm($thesaurusFile, 'materiaux', MonumentConfig::getMateriaux());
ensureThesaurusKeyForm($thesaurusFile, 'techniques', MonumentConfig::getTechniques());
ensureThesaurusKeyForm($thesaurusFile, 'typesDocumentation', [
    'Bibliographie', 'Sources', 'Webographie', 'Périodiques', 'Filmographie', 'Discographie'
]);
ensureThesaurusKeyForm($thesaurusFile, 'metiers', [
    'Sculpteur', 'Architecte', 'Fondeur', 'Médailleur', 'Entrepreneur', 'Marbrier'
]);

$defaultTypesDocumentPhoto = [
    'affiche', 'album', 'ambrotype', 'autochrome', 'blueprint', 'brochure',
    'calque', 'carte', 'carte postale', 'catalogue', 'chromolithographie',
    'circulaire', 'collage', 'copie numérique', 'coupe', 'croquis', 'cyanotype',
    'daguerréotype', 'dépliant', 'dessin', 'diagramme', 'diapositive',
    'eau-forte', 'ektachrome', 'élévation', 'enluminure', 'éphémère',
    'esquisse', 'estampe', 'ex-libris', 'fac-similé', 'faire-part', 'ferrotype',
    'flyer', 'fresque', 'gravure', 'imprimé', 'linogravure', 'lithographie',
    'livre', 'maquette', 'menu', 'microfiche', 'microfilm', 'microforme',
    'miniature', 'monotype', 'négatif', 'négatif sur plaque de verre',
    'négatif sur support souple', "numérisation directe de l'objet",
    'partition', 'peinture', 'périodique', 'photocopie', 'photogramme',
    'photographie', 'photographie numérique', 'plan', 'planche',
    'planche-contact', 'plaque de verre', 'polaroid', 'polycopié',
    'positif sur plaque de verre', 'prospectus', 'radiographie', 'relevé',
    'schéma', 'sérigraphie', 'silhouette', 'timbre-poste',
    'tirage argentique', 'tirage contact', 'tirage diazoïque',
    'tirage photographique', 'tract', 'transparent', 'vignette',
    'vue stéréoscopique', 'xylographie'
];
ensureThesaurusKeyForm($thesaurusFile, 'typesDocumentPhoto', $defaultTypesDocumentPhoto);

$paysList                      = ThesaurusManager::getList($thesaurusFile, 'pays');
$departementsFrance            = ThesaurusManager::getList($thesaurusFile, 'departementsFrance');
$departementsAlgerieAvant1962 = ThesaurusManager::getList($thesaurusFile, 'departementsAlgerieAvant1962');
$typesLocalisation             = ThesaurusManager::getList($thesaurusFile, 'typesLocalisation');
$typesOeuvreList               = ThesaurusManager::getList($thesaurusFile, 'typesOeuvre');
$materiauxList                 = ThesaurusManager::getList($thesaurusFile, 'materiaux');
$techniquesList                = ThesaurusManager::getList($thesaurusFile, 'techniques');
$typesInscriptions             = ThesaurusManager::getList($thesaurusFile, 'typesInscriptions');
$typesDocumentation            = ThesaurusManager::getList($thesaurusFile, 'typesDocumentation');
$typesDocumentPhotoList        = ThesaurusManager::getList($thesaurusFile, 'typesDocumentPhoto') ?: $defaultTypesDocumentPhoto;
$partiesList                   = ThesaurusManager::getList($thesaurusFile, 'partiesList');
$metiersList                   = ThesaurusManager::getList($thesaurusFile, 'metiers');

$wilayasAlgerie = [];
$wilayasMap = [];
$wilayasFile = __DIR__ . '/../json/wilayas.json';
if (file_exists($wilayasFile)) {
    $rawWilayas = preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($wilayasFile));
    $wData = json_decode($rawWilayas, true);
    if (is_array($wData)) {
        $wilayasMap = $wData;
        $wilayasAlgerie = array_keys($wData);
    }
}

$communesAnciennesList = [];
$communesFile = __DIR__ . '/../json/communes_anciennes.json';
if (file_exists($communesFile)) {
    $communesData = json_decode(file_get_contents($communesFile), true);
    if (is_array($communesData)) {
        foreach ($communesData as $c) {
            $communesAnciennesList[] = trim(is_array($c) ? ($c['nom'] ?? '') : $c);
        }
        sort($communesAnciennesList);
    }
}

$auteursDataJS = [];
foreach ($metiersList as $metierName) {
    $metierName = trim((string)$metierName);
    if ($metierName === '') {
        continue;
    }
    $meta       = getMetierMetaForm($metierName);
    $metierFile = $jsonDir . '/' . $meta['json'];
    $defaults   = (mb_strtolower($metierName, 'UTF-8') === 'fondeur') ? MonumentConfig::getFondeurs() : [];
    $auteursDataJS[$metierName] = loadMetierPersonsForm($metierFile, $defaults, $meta['prefix']);
}

if ($id) {
    $data = null;
    foreach ($allMonuments as $m) {
        if ((int)$m['id'] === $id && empty($m['deleted_at'])) {
            $data = $m;
            break;
        }
    }

    if (!$data) die("Monument introuvable ou supprimé.");

    if ($role !== 'admin' && (int)$data['user_id'] !== $userId) {
        $isOwnerOrAdmin = false;
    }

    foreach (MonumentConfig::JSON_FIELDS as $field) {
        if (array_key_exists($field, $data)) {
            $monument[$field] = is_array($data[$field]) && !empty($data[$field]) ? $data[$field] : [''];
        }
    }
    foreach (array_merge(MonumentConfig::SIMPLE_FIELDS, MonumentConfig::TEXT_FIELDS, MonumentConfig::DATE_FIELDS) as $field) {
        if (array_key_exists($field, $data)) {
            $monument[$field] = $data[$field] ?? $monument[$field];
        }
    }

    $extraFields = [
        'pays', 'departement_france', 'wilaya_algerie', 'commune_ancienne', 'departement_avant_1962', 'type_localisation',
        'source_representation', 'numero_fiche',
        'date_creation_notice', 'date_modification_notice', 'redacteur_notice', 'mentions_legales',
        'statut_publication', 'etat_conservation', 'id_merimee', 'id_joconde', 'id_palissy', 'id_memoire', 'informations_localisation'
    ];
    foreach ($extraFields as $f) {
        if (isset($data[$f])) {
            $monument[$f] = $data[$f];
        }
    }

    $monument['auteurs_structures'] = $data['auteurs_structures'] ?? [];
    $monument['documentation_zotero'] = $data['documentation_zotero'] ?? [];
    $monument['inscriptions_structures'] = $data['inscriptions_structures'] ?? [];
    $monument['photos_payload'] = $data['photos_payload'] ?? ['actuelles' => [], 'maquettes' => [], 'anciennes' => []];

    $monument['date_modification_notice'] = date('d.m.Y') . ' à ' . date('H:i:s');
    if (empty($monument['date_creation_notice'])) {
        $monument['date_creation_notice'] = date('d.m.Y') . ' à ' . date('H:i:s');
    }

    $linkedIds = $data['linked_monuments'] ?? [];
    foreach ($allMonuments as $am) {
        if (in_array($am['id'], $linkedIds)) {
            $titres = $am['titre'] ?? ['Sans titre'];
            $linkedMonumentsData[] = [
                'id'    => $am['id'],
                'titre' => is_array($titres) ? $titres[0] : $titres
            ];
        }
    }
    $monument['linked_monuments'] = $linkedMonumentsData;
} else {
    $monument['date_creation_notice'] = date('d.m.Y') . ' à ' . date('H:i:s');
    $monument['date_modification_notice'] = date('d.m.Y') . ' à ' . date('H:i:s');
    $monument['numero_fiche'] = 'REF-' . date('Y') . '-' . strtoupper(substr(uniqid(), -5));
    $monument['redacteur_notice'] = $_SESSION['username'] ?? '';
    $monument['pays'] = 'France';
    $monument['commande_etat'] = '';
    $monument['linked_monuments'] = [];
    $monument['auteurs_structures'] = [];
    $monument['documentation_zotero'] = [];
    $monument['inscriptions_structures'] = [];
    $monument['photos_payload'] = ['actuelles' => [], 'maquettes' => [], 'anciennes' => []];
}

$mentionsLegalesVal = !empty(trim((string)($monument['mentions_legales'] ?? '')))
    ? $monument['mentions_legales']
    : $siteName . ' - ' . date('Y');

function renderProposeBtn(string $listKey, string $listLabel, string $targetSelectSelector = ''): string
{
    $k = htmlspecialchars($listKey, ENT_QUOTES, 'UTF-8');
    $l = htmlspecialchars($listLabel, ENT_QUOTES, 'UTF-8');
    $s = htmlspecialchars($targetSelectSelector, ENT_QUOTES, 'UTF-8');

    $thesaurusIcon = '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . '<path d="M4 17.5v-13A2.5 2.5 0 0 1 6.5 2H13"/>'
        . '<path d="M20 12v8H6.5a2.5 2.5 0 0 1 0-5H20"/>'
        . '<line x1="8" y1="7" x2="12" y2="7"/>'
        . '<line x1="8" y1="10.5" x2="13" y2="10.5"/>'
        . '<line x1="18.5" y1="2.5" x2="18.5" y2="8.5"/>'
        . '<line x1="15.5" y1="5.5" x2="21.5" y2="5.5"/>'
        . '</svg>';

    return "<button type='button' class='btn-secondary btn-propose-thesaurus' "
        . "onclick=\"openProposeTermModal('{$k}', '{$l}', '{$s}')\" "
        . "title='Proposer un nouveau terme au thésaurus' "
        . "style='height:34px; width:34px; padding:0; display:inline-flex; align-items:center; justify-content:center; flex-shrink:0; background:#ebf8ff; border:1px solid #3182ce; color:#2b6cb0; border-radius:4px; cursor:pointer;'>"
        . $thesaurusIcon . "</button>";
}

function renderDynamicField($field, $label, $values, $options = [], $thesaurusKey = null) {
    if (!is_array($values) || empty($values)) $values = [''];
    $optionsJson = htmlspecialchars(json_encode($options), ENT_QUOTES, 'UTF-8');

    echo "<div class='form-row dynamic-field-wrapper' data-field='$field'>";
    echo "<label>$label :</label>";
    echo "<div class='dynamic-content-area' style='flex:1; display:flex; align-items:flex-start; gap:8px;'>";
    echo "<div id='{$field}_container' class='lines-container' style='flex:1; display:flex; flex-direction:column; gap:6px;'>";

    foreach ($values as $index => $val) {
        $isLast = ($index === count($values) - 1);
        echo "<div class='dynamic-line' style='display:flex; align-items:center; gap:6px;'>";
        if (!empty($options)) {
            $attrKey = $thesaurusKey ? " data-thesaurus-key='" . htmlspecialchars($thesaurusKey, ENT_QUOTES, 'UTF-8') . "'" : "";
            echo "<select class='dynamic-input'{$attrKey}>";
            echo "<option value=''>-- Sélectionner --</option>";
            foreach ($options as $opt) {
                $selected = ($val === $opt) ? 'selected' : '';
                echo "<option value='" . htmlspecialchars($opt) . "' $selected>" . htmlspecialchars($opt) . "</option>";
            }
            echo "</select>";
        } else {
            echo "<input type='text' class='dynamic-input' value='" . htmlspecialchars($val) . "' autocomplete='off'>";
        }
        if ($isLast) {
            echo "<button type='button' class='btn-secondary dyn-action-btn' onclick='addDynamicLine(this, $optionsJson)' title='Ajouter une cellule' style='height:34px; min-width:34px; padding:0; flex-shrink:0;'>+</button>";
        } else {
            echo "<button type='button' class='btn-secondary dyn-action-btn' onclick='removeDynamicLine(this, $optionsJson)' title='Supprimer cette cellule' style='height:34px; min-width:34px; padding:0; flex-shrink:0; color:#c53030;'>-</button>";
        }
        echo "</div>";
    }
    echo "</div>";

    if ($thesaurusKey !== null) {
        echo renderProposeBtn($thesaurusKey, $label, "#{$field}_container select");
    }

    echo "</div>";
    echo "<input type='hidden' id='input_{$field}' name='{$field}' value='" . htmlspecialchars(json_encode($values)) . "'></div>";
}

function renderComplexDateField($field, $label, $valueData) {
    $data = is_string($valueData) ? json_decode($valueData, true) : $valueData;
    if (!is_array($data)) {
        $data = ['modifier' => 'le', 'date1' => (string)$valueData, 'date2' => ''];
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

    $d1 = trim((string)$d1, '"\'\\');
    $d2 = trim((string)$d2, '"\'\\');

    echo "<div class='form-row date-complex-row' data-field='$field'>";
    echo "<label>$label :</label>";
    echo "<div style='display:flex; gap:8px; align-items:center; flex:1; flex-wrap:wrap;'>";
    echo "<select name='{$field}[modifier]' class='date-modifier'>";
    echo "<option value='le' " . ($mod == 'le' ? 'selected' : '') . ">Le</option>";
    echo "<option value='avant' " . ($mod == 'avant' ? 'selected' : '') . ">Avant</option>";
    echo "<option value='apres' " . ($mod == 'apres' ? 'selected' : '') . ">Après</option>";
    echo "<option value='entre' " . ($mod == 'entre' ? 'selected' : '') . ">Entre</option>";
    echo "</select>";
    echo "<div class='date-inputs' style='display:flex; gap:6px; align-items:center; flex:1;'>";
    echo "<span class='d1-label'>" . ($mod == 'entre' ? 'Début :' : 'Date :') . "</span>";
    echo "<input type='text' name='{$field}[date1]' value='" . htmlspecialchars($d1) . "'>";
    echo "<span class='d2-container' style='display:" . ($mod == 'entre' ? 'inline-flex' : 'none') . "; align-items:center; gap:5px;'>";
    echo "<span class='d2-label'>Fin :</span>";
    echo "<input type='text' name='{$field}[date2]' value='" . htmlspecialchars($d2) . "'>";
    echo "</span></div></div></div>";
}

function renderRichTextToolbar() {
    echo '<div class="rt-toolbar">
        <button type="button" class="btn-rt" data-tag="b" title="Gras"><b>B</b></button>
        <button type="button" class="btn-rt" data-tag="i" title="Italique"><i>I</i></button>
        <button type="button" class="btn-rt" data-tag="u" title="Souligné"><u>U</u></button>
        <button type="button" class="btn-rt" data-tag="s" title="Barré"><s>S</s></button>
    </div>';
}

ob_start();
?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<div class="content-2 monument-form-container">
<h2><?= !empty($monument['id']) ? 'Modifier une fiche' : 'Nouvelle fiche monument' ?></h2>

<form method="post" action="save_monument.php" enctype="multipart/form-data" onsubmit="return handleFormSubmit()" id="monument-form">
    <input type="hidden" name="id" value="<?= htmlspecialchars($monument['id'] ?? '') ?>">
    <input type="hidden" name="csrf_token" id="global_csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

    <!-- CHAMPS MASQUÉS POUR TRANSMETTRE LES DONNÉES STRUCTURÉES -->
    <input type="hidden" id="auteurs_data" name="auteurs_structures" value="<?= htmlspecialchars(json_encode($monument['auteurs_structures'])) ?>">
    <input type="hidden" id="documentation_data" name="documentation_zotero" value="<?= htmlspecialchars(json_encode($monument['documentation_zotero'])) ?>">
    <input type="hidden" id="inscriptions_data" name="inscriptions_structures" value="<?= htmlspecialchars(json_encode($monument['inscriptions_structures'])) ?>">
    <input type="hidden" id="photos_payload_data" name="photos_payload" value="<?= htmlspecialchars(json_encode($monument['photos_payload'])) ?>">

    <!-- NAVIGATION PAR ONGLETS -->
    <ul class="tabs-header">
        <li class="tab-link active" data-tab="tab-localisation">LOCALISATION</li>
        <li class="tab-link" data-tab="tab-photos">PHOTOS</li>
        <li class="tab-link" data-tab="tab-description">DESCRIPTION</li>
        <li class="tab-link" data-tab="tab-inscription">INSCRIPTION</li>
        <li class="tab-link" data-tab="tab-historique">HISTORIQUE</li>
        <li class="tab-link" data-tab="tab-execution">EXÉCUTION</li>
        <li class="tab-link" data-tab="tab-financement">FINANCEMENT</li>
        <li class="tab-link" data-tab="tab-documentation">DOCUMENTATION</li>
        <li class="tab-link" data-tab="tab-protection">PROTECTION</li>
        <li class="tab-link" data-tab="tab-analogie">ANALOGIE</li>
        <li class="tab-link" data-tab="tab-gestion">GESTION</li>
    </ul>

    <!-- ONGLET : LOCALISATION -->
    <div id="tab-localisation" class="tab-content active">
        <h4>LOCALISATION</h4>

        <div class="form-grid-2col">
            <div class="form-col">
                <div class="form-row">
                    <label>Pays :</label>
                    <div style="flex:1; display:flex; align-items:center; gap:6px;">
                        <select id="select_pays" name="pays" data-thesaurus-key="pays" style="flex:1;">
                            <option value="">-- Sélectionner un pays --</option>
                            <?php foreach ($paysList as $p): ?>
                                <option value="<?= htmlspecialchars($p) ?>" <?= ($monument['pays'] ?? '') === $p ? 'selected' : '' ?>><?= htmlspecialchars($p) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= renderProposeBtn('pays', 'Pays', '#select_pays') ?>
                    </div>
                </div>

                <div class="form-row" id="row_dept_france">
                    <label>Département :</label>
                    <div style="flex:1; display:flex; align-items:center; gap:6px;">
                        <select id="select_departement_france" name="departement_france" data-thesaurus-key="departementsFrance" style="flex:1;">
                            <option value="">-- Sélectionner un département --</option>
                            <?php foreach ($departementsFrance as $dept): ?>
                                <option value="<?= htmlspecialchars($dept) ?>" <?= ($monument['departement_france'] ?? '') === $dept ? 'selected' : '' ?>><?= htmlspecialchars($dept) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= renderProposeBtn('departementsFrance', 'Départements (France)', '#select_departement_france') ?>
                    </div>
                </div>

                <div class="form-row" id="row_wilaya" style="display:none;">
                    <label>Wilaya :</label>
                    <div style="flex:1; display:flex; align-items:center; gap:6px;">
                        <select id="select_wilaya" name="wilaya_algerie" data-thesaurus-key="wilayas" style="flex:1;">
                            <option value="">-- Sélectionner une wilaya --</option>
                            <?php foreach ($wilayasAlgerie as $w): ?>
                                <option value="<?= htmlspecialchars($w) ?>" <?= ($monument['wilaya_algerie'] ?? '') === $w ? 'selected' : '' ?>><?= htmlspecialchars($w) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= renderProposeBtn('wilayas', 'Wilayas (Algérie)', '#select_wilaya') ?>
                    </div>
                </div>
            </div>

            <div class="form-col">
                <div class="form-row">
                    <label>Type de localisation :</label>
                    <div style="flex:1; display:flex; align-items:center; gap:6px;">
                        <select id="select_type_localisation" name="type_localisation" data-thesaurus-key="typesLocalisation" style="flex:1;">
                            <option value="">-- Sélectionner --</option>
                            <?php foreach ($typesLocalisation as $typeLoc): ?>
                                <option value="<?= htmlspecialchars($typeLoc) ?>" <?= ($monument['type_localisation'] ?? '') === $typeLoc ? 'selected' : '' ?>><?= htmlspecialchars($typeLoc) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= renderProposeBtn('typesLocalisation', 'Types de localisation', '#select_type_localisation') ?>
                    </div>
                </div>

                <div class="form-row dynamic-field-wrapper" data-field="communes_actuelles">
                    <label>Commune actuelle :</label>
                    <div class="dynamic-content-area" style="flex:1; display:flex; flex-direction:column;">
                        <div id="communes_actuelles_container" class="lines-container">
                            <?php $valCommune = $monument['communes_actuelles'][0] ?? ''; ?>
                            <div class="dynamic-line" style="display:flex; align-items:center; gap:6px;">
                                <select id="select_commune_actuelle" name="communes_actuelles[]" class="dynamic-input" data-selected="<?= htmlspecialchars($valCommune) ?>">
                                    <option value="">-- Sélectionner d'abord un département ou une wilaya --</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div id="row_algerie_historique" style="display:none; background:#f8fafc; padding:12px; border:1px solid #cbd5e0; margin:10px 0; border-radius:4px;">
            <h4 style="margin-top:0; margin-bottom:10px; color:#4a5568; font-size: 0.9rem;">Algérie - Avant 1962</h4>
            <div class="form-grid-2col">
                <div class="form-col">
                    <div class="form-row">
                        <label>Commune ancienne :</label>
                        <input type="text" list="communes_anciennes_list" name="commune_ancienne" value="<?= htmlspecialchars($monument['commune_ancienne'] ?? '') ?>" autocomplete="off">
                        <datalist id="communes_anciennes_list">
                            <?php foreach ($communesAnciennesList as $c): ?>
                                <option value="<?= htmlspecialchars($c) ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                </div>

                <div class="form-col">
                    <div class="form-row">
                        <label>Dpt. avant 1962 :</label>
                        <div style="flex:1; display:flex; align-items:center; gap:6px;">
                            <select id="select_dept_avant_1962" name="departement_avant_1962" data-thesaurus-key="departementsAlgerieAvant1962" style="flex:1;">
                                <option value="">-- Sélectionner --</option>
                                <?php foreach ($departementsAlgerieAvant1962 as $d): ?>
                                    <option value="<?= htmlspecialchars($d) ?>" <?= ($monument['departement_avant_1962'] ?? '') === $d ? 'selected' : '' ?>><?= htmlspecialchars($d) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?= renderProposeBtn('departementsAlgerieAvant1962', 'Départements (Algérie av. 1962)', '#select_dept_avant_1962') ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="form-row form-row-full">
            <label for="informations_localisation">Informations de localisation :</label>
            <div class="rt-editor-box">
                <?php renderRichTextToolbar(); ?>
                <textarea id="informations_localisation" name="informations_localisation" class="photo-textarea" rows="4"><?= htmlspecialchars($monument['informations_localisation'] ?? '') ?></textarea>
            </div>
        </div>

        <hr style="margin: 15px 0; border-top: 1px solid #e2e8f0;">
        <h4 style="margin-bottom: 10px; color: #4a5568; font-size: 0.9rem;">Coordonnées géographiques</h4>
        <input type="hidden" id="latitude" name="latitude" value="<?= htmlspecialchars($monument['latitude'] ?? '') ?>">
        <input type="hidden" id="longitude" name="longitude" value="<?= htmlspecialchars($monument['longitude'] ?? '') ?>">
        <div id="map" style="height:360px; width:100%; border:1px solid #ccc; border-radius:6px;"></div>
    </div>

    <!-- ONGLET : PHOTOS -->
    <div id="tab-photos" class="tab-content" style="background:#f4f5f7;">
        <h4>PHOTOS</h4>
        <?php
        $photoSections = [
            ['id' => 'actuelles', 'title' => 'PHOTOGRAPHIES ACTUELLES',                    'has_date' => true],
            ['id' => 'maquettes', 'title' => 'MAQUETTES ET DESSINS',                       'has_date' => true],
            ['id' => 'anciennes', 'title' => 'PHOTOGRAPHIES ANCIENNES ET CARTES POSTALES', 'has_date' => true],
        ];

        foreach ($photoSections as $sec):
        ?>
        <div class="photo-section-block" style="margin-bottom: 20px;">
            <h4 class="photo-section-title" style="border-bottom: 1px solid #cbd5e0; padding-bottom: 5px; margin-bottom: 10px; font-size: 0.88rem; color: #2d3748;"><?= $sec['title'] ?></h4>
            <table class="standard-table">
                <thead>
                    <tr>
                        <th style="width: 70px;">Image</th>
                        <th>Titre</th>
                        <th>Type de document</th>
                        <th>Auteur</th>
                        <th>Lieu de prise de vue</th>
                        <?php if ($sec['has_date']): ?><th>Date de prise de vue</th><?php endif; ?>
                        <th>Description</th>
                        <th>Source</th>
                        <th style="width: 75px; text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody id="tbody-<?= $sec['id'] ?>"></tbody>
            </table>
            <div style="text-align: left; margin-top: 8px;">
                <button type="button" class="btn-secondary" onclick="openPhotoModal('<?= $sec['id'] ?>', <?= $sec['has_date'] ? 'true' : 'false' ?>)" style="padding:5px 12px; font-weight:bold; font-size:0.83rem;">➕ Ajouter une photo</button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ONGLET : DESCRIPTION -->
    <div id="tab-description" class="tab-content">
        <h4>DESCRIPTION</h4>

        <div class="form-grid-2col">
            <div class="form-col">
                <?php renderDynamicField('titre', 'Titre', $monument['titre']); ?>
                <?php renderDynamicField('attribution_titre', 'Attribution titre', $monument['attribution_titre']); ?>
                <?php renderDynamicField('appellation_usage', 'Appellation d\'usage', $monument['appellation_usage']); ?>
                <?php renderDynamicField('source_representation', 'Source de la représentation', is_array($monument['source_representation'] ?? null) ? $monument['source_representation'] : [$monument['source_representation'] ?? '']); ?>
                <?php renderDynamicField('personnage', 'Personnage(s)', $monument['personnage']); ?>
            </div>

            <div class="form-col">
                <?php renderDynamicField('type_oeuvre', 'Type d’œuvre', $monument['type_oeuvre'], $typesOeuvreList, 'typesOeuvre'); ?>
                <?php renderDynamicField('materiaux', 'Matériaux', $monument['materiaux'], $materiauxList, 'materiaux'); ?>
                <?php renderDynamicField('techniques', 'Techniques', $monument['techniques'], $techniquesList, 'techniques'); ?>
                <div class="form-row">
                    <label>Dimensions (cm) :</label>
                    <input type="text" name="dimensions" value="<?= htmlspecialchars($monument['dimensions'] ?? '') ?>">
                </div>
            </div>
        </div>

        <div class="form-row form-row-full">
            <label>Description :</label>
            <div class="rt-editor-box">
                <?php renderRichTextToolbar(); ?>
                <textarea name="description" class="photo-textarea"><?= htmlspecialchars($monument['description'] ?? '') ?></textarea>
            </div>
        </div>
    </div>

    <!-- ONGLET : INSCRIPTION -->
    <div id="tab-inscription" class="tab-content" style="background:#f8fafc;">
        <h4>INSCRIPTION</h4>

        <table class="standard-table" style="margin-bottom: 10px;">
            <thead>
                <tr>
                    <th style="width: 95px;">Photographie</th>
                    <th style="width: 22%;">Type d'inscription</th>
                    <th style="width: 53%;">Contenu / Retranscription</th>
                    <th style="width: 15%; text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody id="inscriptions-tbody"></tbody>
        </table>

        <div style="text-align: left;">
            <button type="button" class="btn-secondary" onclick="openInscriptionModal()" style="padding:5px 12px; font-weight:bold; font-size:0.83rem;">➕ Ajouter une inscription</button>
        </div>
    </div>

    <!-- ONGLET : HISTORIQUE -->
    <div id="tab-historique" class="tab-content" style="background:#f8fafc;">
        <h4>HISTORIQUE</h4>

        <div class="form-grid-2col">
            <div class="form-col">
                <?php renderComplexDateField('annee_creation', 'Année de création', $monument['annee_creation']); ?>
                <?php renderComplexDateField('date_commande', 'Date de commande', $monument['date_commande']); ?>
                <?php renderComplexDateField('inauguration', "Date d'inauguration", $monument['inauguration']); ?>
                <?php renderComplexDateField('destruction', 'Date de destruction', $monument['destruction']); ?>
            </div>

            <div class="form-col">
                <div class="form-row form-row-full" style="margin-top:0;">
                    <label>Historique :</label>
                    <div class="rt-editor-box">
                        <?php renderRichTextToolbar(); ?>
                        <textarea name="historique" class="photo-textarea" style="min-height: 95px;"><?= htmlspecialchars($monument['historique'] ?? '') ?></textarea>
                    </div>
                </div>

                <?php renderDynamicField('expositions', 'Expositions', $monument['expositions']); ?>
            </div>
        </div>
    </div>

    <!-- ONGLET : EXÉCUTION -->
    <div id="tab-execution" class="tab-content" style="background:#f0f4f8;">
        <h4>EXÉCUTION</h4>
        <table class="standard-table" style="margin-bottom: 10px;">
            <thead>
                <tr>
                    <th>Métier</th>
                    <th>Auteur</th>
                    <th>A signé le monument ?</th>
                    <th>Partie</th>
                    <th style="text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody id="auteurs-tbody"></tbody>
        </table>
        <div style="text-align: left;">
            <button type="button" class="btn-secondary" onclick="openAuteurModal()" style="padding:5px 12px; font-weight:bold; font-size:0.83rem;">➕ Ajouter un auteur</button>
        </div>
    </div>

    <!-- ONGLET : FINANCEMENT -->
    <div id="tab-financement" class="tab-content" style="background:#f8fafc;">
        <h4>FINANCEMENT</h4>

        <div class="form-grid-2col">
            <div class="form-col">
                <div class="form-row">
                    <label>Prix total :</label>
                    <input type="text" name="prix_total_texte" value="<?= htmlspecialchars($monument['prix_total_texte'] ?? '') ?>" placeholder="Prix total">
                </div>
<div class="form-row">
    <label>Commande de l'État :</label>
    <select name="commande_etat" autocomplete="off">
        <option value="" <?= $commandeEtat === '' ? 'selected' : '' ?>>-- Sélectionner --</option>
        <option value="oui" <?= $commandeEtat === 'oui' ? 'selected' : '' ?>>Oui</option>
        <option value="non" <?= $commandeEtat === 'non' ? 'selected' : '' ?>>Non</option>
        <option value="inconnu" <?= $commandeEtat === 'inconnu' ? 'selected' : '' ?>>Ne sais pas</option>
    </select>
</div>
                <div class="form-row">
                    <label>Souscription :</label>
                    <input type="text" name="souscription" value="<?= htmlspecialchars($monument['souscription'] ?? '') ?>" placeholder="Souscription">
                </div>
                <div class="form-row">
                    <label>Subvention commune :</label>
                    <input type="text" name="subvention_commune" value="<?= htmlspecialchars($monument['subvention_commune'] ?? '') ?>" placeholder="Subvention de la commune">
                </div>
                <div class="form-row">
                    <label>Subvention de l'État :</label>
                    <input type="text" name="subvention_etat" value="<?= htmlspecialchars($monument['subvention_etat'] ?? '') ?>" placeholder="Subvention de l'état">
                </div>
                <div class="form-row">
                    <label>Produit fête :</label>
                    <input type="text" name="produit_fete" value="<?= htmlspecialchars($monument['produit_fete'] ?? '') ?>" placeholder="Produit fête">
                </div>
            </div>

            <div class="form-col">
                <div class="form-row">
                    <label>Dons de particuliers :</label>
                    <input type="text" name="dons_particuliers" value="<?= htmlspecialchars($monument['dons_particuliers'] ?? '') ?>" placeholder="Dons de particuliers">
                </div>
                <div class="form-row">
                    <label>Dons d'entreprises :</label>
                    <input type="text" name="dons_entreprises" value="<?= htmlspecialchars($monument['dons_entreprises'] ?? '') ?>" placeholder="Dons d'entreprises">
                </div>
                <div class="form-row">
                    <label>Dons (autres) :</label>
                    <input type="text" name="dons_autres" value="<?= htmlspecialchars($monument['dons_autres'] ?? '') ?>" placeholder="Dons (autres)">
                </div>
                <div class="form-row">
                    <label>Emprunt :</label>
                    <input type="text" name="emprunt" value="<?= htmlspecialchars($monument['emprunt'] ?? '') ?>" placeholder="Emprunt">
                </div>
                <div class="form-row">
                    <label>Autres subventions :</label>
                    <input type="text" name="autres_subventions" value="<?= htmlspecialchars($monument['autres_subventions'] ?? '') ?>" placeholder="Autres subventions">
                </div>
            </div>
        </div>

        <div class="form-row form-row-full">
            <label>Commentaires :</label>
            <div class="rt-editor-box">
                <?php renderRichTextToolbar(); ?>
                <textarea name="commentaires_economie" class="photo-textarea"><?= htmlspecialchars($monument['commentaires_economie'] ?? '') ?></textarea>
            </div>
        </div>
    </div>

    <!-- ONGLET : DOCUMENTATION -->
    <div id="tab-documentation" class="tab-content" style="background:#f8fafc;">
        <h4>DOCUMENTATION</h4>

        <table class="standard-table" style="margin-bottom:10px;">
            <thead>
                <tr>
                    <th style="width: 13%;">Type</th>
                    <th style="width: 24%;">Titre</th>
                    <th style="width: 18%;">Auteur(s)</th>
                    <th style="width: 8%;">Année</th>
                    <th style="width: 15%;">Extrait / Gallica</th>
                    <th style="width: 14%;">Notes</th>
                    <th style="width: 8%; text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody id="documentation-tbody"></tbody>
        </table>

        <div style="text-align: left;">
            <button type="button" class="btn-secondary" onclick="openDocumentationModal()" style="padding:5px 12px; font-weight:bold; font-size:0.83rem;">➕ Ajouter une référence</button>
        </div>
    </div>

    <!-- ONGLET : PROTECTION -->
    <div id="tab-protection" class="tab-content" style="background:#f8fafc;">
        <h4>PROTECTION</h4>
        <?php renderDynamicField('protections', 'Protections', $monument['protections']); ?>

        <div class="form-grid-2col">
            <div class="form-col">
                <div class="form-row">
                    <label>Propriétaire actuel :</label>
                    <input type="text" name="proprietaires_actuels" value="<?= htmlspecialchars($monument['proprietaires_actuels'] ?? '') ?>">
                </div>
                <div class="form-row">
                    <label>ID Base Mérimée :</label>
                    <input type="text" name="id_merimee" value="<?= htmlspecialchars($monument['id_merimee'] ?? '') ?>" placeholder="Ex: PA00000000">
                </div>
                <div class="form-row">
                    <label>ID Base Joconde :</label>
                    <input type="text" name="id_joconde" value="<?= htmlspecialchars($monument['id_joconde'] ?? '') ?>" placeholder="Ex: 000PE000000">
                </div>
            </div>

            <div class="form-col">
                <div class="form-row">
                    <label>ID Base Palissy :</label>
                    <input type="text" name="id_palissy" value="<?= htmlspecialchars($monument['id_palissy'] ?? '') ?>" placeholder="Ex: PM00000000">
                </div>
                <div class="form-row">
                    <label>ID Base Mémoire :</label>
                    <input type="text" name="id_memoire" value="<?= htmlspecialchars($monument['id_memoire'] ?? '') ?>" placeholder="Ex: AP0000000">
                </div>
            </div>
        </div>
    </div>

    <!-- ONGLET : ANALOGIE -->
    <div id="tab-analogie" class="tab-content">
        <h4>ANALOGIE</h4>
        <?php renderDynamicField('analogies', 'Analogies', is_array($monument['analogies'] ?? null) ? $monument['analogies'] : [$monument['analogies'] ?? '']); ?>
        <div class="form-row" style="align-items: flex-start;">
            <label for="link-search" style="margin-top:6px;">Monuments liés :</label>
            <div style="flex: 1; width: 100%;">
                <input type="text" id="link-search" placeholder="Chercher un monument..." autocomplete="off" style="width:100%;">
                <div id="link-suggestions" style="border: 1px solid #cbd5e0; border-top:none; max-height: 150px; overflow-y: auto; background: #fff;"></div>
                <div id="linked-container" style="margin-top: 8px; display: flex; gap: 8px; flex-wrap: wrap;">
                    <?php foreach (($monument['linked_monuments'] ?? []) as $lm): ?>
                        <div class="linked-item tag-box" style="background: #e0f7fa; padding: 4px 8px; border-radius: 4px; display: inline-flex; align-items: center; border: 1px solid #b2ebf2; font-size:0.83rem;">
                            <?= htmlspecialchars($lm['titre'] ?? '') ?>
                            <button type="button" onclick="this.parentNode.remove()" class="btn-secondary" style="padding: 1px 5px; margin-left: 8px; cursor: pointer;">✖</button>
                            <input type="hidden" name="linked_monuments[]" value="<?= htmlspecialchars($lm['id'] ?? '') ?>">
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ONGLET : GESTION -->
    <div id="tab-gestion" class="tab-content">
        <h4>GESTION</h4>
        <div class="form-grid-2col">
            <div class="form-col">
                <div class="form-row">
                    <label>Création notice :</label>
                    <input type="text" name="date_creation_notice" value="<?= htmlspecialchars($monument['date_creation_notice'] ?? '') ?>" readonly style="background:#edf2f7; cursor:not-allowed;">
                </div>
                <div class="form-row">
                    <label>Dernière modif. :</label>
                    <input type="text" name="date_modification_notice" value="<?= htmlspecialchars($monument['date_modification_notice'] ?? '') ?>" readonly style="background:#edf2f7; cursor:not-allowed;">
                </div>
                <div class="form-row">
                    <label>Rédacteur :</label>
                    <input type="text" name="redacteur_notice" value="<?= htmlspecialchars($monument['redacteur_notice'] ?? '') ?>">
                </div>
            </div>

            <div class="form-col">
                <div class="form-row">
                    <label>Statut publication :</label>
                    <select name="statut_publication">
                        <option value="brouillon" <?= ($monument['statut_publication'] ?? '') === 'brouillon' ? 'selected' : '' ?>>Brouillon</option>
                        <option value="publie" <?= ($monument['statut_publication'] ?? '') === 'publie' ? 'selected' : '' ?>>Publié</option>
                    </select>
                </div>
                <div class="form-row">
                    <label>Référence notice :</label>
                    <input type="text" name="numero_fiche" value="<?= htmlspecialchars($monument['numero_fiche'] ?? '') ?>" readonly style="background:#edf2f7; cursor:not-allowed;">
                </div>
                <div class="form-row">
                    <label>Mentions légales :</label>
                    <input type="text" id="mentions_legales" name="mentions_legales" value="<?= htmlspecialchars($mentionsLegalesVal) ?>">
                </div>
            </div>
        </div>
    </div>

    <!-- BOUTONS DE SAUVEGARDE -->
    <div class="form-actions" style="margin-top:20px; padding-top:15px; border-top: 1px solid #e2e8f0; text-align:center; display:flex; justify-content:center; flex-direction:column; align-items:center; gap:0.8rem;">
        <?php if ($isOwnerOrAdmin): ?>
            <div style="display:flex; gap:1rem;">
                <a href="dashboard.php" class="btn-secondary" style="padding:8px 18px;">⬅ Retour</a>
                <button type="submit" class="btn-primary" style="padding:8px 22px; font-size:0.95rem;">💾 Enregistrer la notice</button>
            </div>
        <?php else: ?>
            <div style="background: #fff3cd; color: #856404; padding: 8px 12px; border-radius: 6px; font-size: 0.85rem; max-width: 600px;">
                Vous n'êtes pas l'auteur de cette fiche. Votre sauvegarde sera envoyée comme proposition à l'administrateur ou au créateur.
            </div>
            <div style="display:flex; gap:1rem;">
                <a href="dashboard.php" class="btn-secondary" style="padding:8px 18px;">⬅ Retour</a>
                <button type="submit" class="btn-primary" style="padding:8px 22px; font-size:0.95rem; background-color: var(--secondary);">💡 Proposer la modification</button>
            </div>
        <?php endif; ?>
    </div>
</form>
</div>

<!-- ============================================== -->
<!-- MODALES CENTRALISÉES ET HARMONISÉES             -->
<!-- ============================================== -->

<!-- 1. Modal Exécution (Auteurs) -->
<div class="standard-modal-overlay" id="auteur-modal-overlay">
    <div class="standard-modal">
        <h3>Ajouter / Éditer un auteur</h3>
        <button type="button" class="standard-modal-close" onclick="document.getElementById('auteur-modal-overlay').style.display='none'">✖</button>

        <div class="modal-row">
            <label style="width: 150px; font-weight: bold; color: #4a5568;">Métier :</label>
            <div style="flex:1; display:flex; align-items:center; gap:6px;">
                <select id="modal_metier" data-thesaurus-key="metiers" style="flex:1; height:34px; padding:3px 8px; border:1px solid #cbd5e0; border-radius:4px;">
                    <option value="">-- Sélectionner un métier --</option>
                    <?php foreach (array_keys($auteursDataJS) as $metier): ?>
                        <option value="<?= htmlspecialchars($metier) ?>"><?= htmlspecialchars($metier) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= renderProposeBtn('metiers', 'Liste des métiers', '#modal_metier') ?>
            </div>
        </div>
        <div class="modal-row" style="align-items: flex-start;">
            <label style="width: 150px; font-weight: bold; color: #4a5568; margin-top: 6px;">Auteur :</label>
            <div style="flex:1; display:flex; flex-direction:column; gap:6px;">
                <div style="display:flex; align-items:center; gap:6px;">
                    <select id="modal_auteur_select" style="flex:1; height:34px; padding:3px 8px; border:1px solid #cbd5e0; border-radius:4px;">
                        <option value="">-- Sélectionner d'abord un métier --</option>
                    </select>
                    <button type="button" class="btn-secondary btn-propose-thesaurus" onclick="openProposeAuteurModal()" title="Proposer une fiche auteur développée à faire valider par l’administrateur" style="height:34px; width:34px; padding:0; display:inline-flex; align-items:center; justify-content:center; flex-shrink:0; background:#ebf8ff; border:1px solid #3182ce; color:#2b6cb0; border-radius:4px; cursor:pointer;">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                            <circle cx="8.5" cy="7" r="4"/>
                            <line x1="20" y1="8" x2="20" y2="14"/>
                            <line x1="23" y1="11" x2="17" y2="11"/>
                        </svg>
                    </button>
                </div>
                <input type="text" id="modal_auteur_new" placeholder="Saisir le nom et prénom (nouvel auteur)..." style="display:none; height:34px; padding:3px 8px; border-radius:4px; border:1px solid #38a169;">
            </div>
        </div>
        <div class="modal-row">
            <label style="width: 150px; font-weight: bold; color: #4a5568;">A signé ? :</label>
            <input type="checkbox" id="modal_a_signe">
        </div>
        <div class="modal-row">
            <label style="width: 150px; font-weight: bold; color: #4a5568;">Partie :</label>
            <div style="flex:1; display:flex; align-items:center; gap:6px;">
                <select id="modal_partie" data-thesaurus-key="partiesList" style="flex:1; height:34px; padding:3px 8px; border:1px solid #cbd5e0; border-radius:4px;">
                    <option value="">-- Sélectionner une partie --</option>
                    <?php foreach ($partiesList as $partie): ?>
                        <option value="<?= htmlspecialchars($partie) ?>"><?= htmlspecialchars($partie) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= renderProposeBtn('partiesList', 'Parties du monument', '#modal_partie') ?>
            </div>
        </div>

        <div style="text-align:right; margin-top:20px; padding-top: 12px; border-top: 1px solid #e2e8f0;">
            <button type="button" class="btn-primary" id="btn-save-auteur" style="padding:6px 18px;">✔ Valider</button>
        </div>
        <input type="hidden" id="modal_edit_index_auteur" value="-1">
    </div>
</div>

<!-- 2. Modal Documentation -->
<div class="standard-modal-overlay" id="documentation-modal-overlay">
    <div class="standard-modal" id="documentation-modal-box">
        <h3>Ajouter / Éditer une référence documentaire</h3>
        <button type="button" class="standard-modal-close" onclick="document.getElementById('documentation-modal-overlay').style.display='none'">✖</button>

        <div class="modal-row">
            <label style="width: 140px; min-width: 140px; font-weight: bold; color: #4a5568;">Type de doc. :</label>
            <div style="flex:1; display:flex; align-items:center; gap:6px;">
                <select id="doc_type" data-thesaurus-key="typesDocumentation" style="flex:1; height:34px; padding:3px 8px; border:1px solid #cbd5e0; border-radius:4px;">
                    <?php foreach ($typesDocumentation as $typeDoc): ?>
                        <option value="<?= htmlspecialchars($typeDoc) ?>"><?= htmlspecialchars($typeDoc) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= renderProposeBtn('typesDocumentation', 'Types de documentation', '#doc_type') ?>
            </div>
        </div>
        <div class="modal-row">
            <label style="width: 140px; min-width: 140px; font-weight: bold; color: #4a5568;">Titre :</label>
            <input type="text" id="doc_titre" placeholder="Titre de l'ouvrage ou de l'article" style="flex:1; height:34px; padding:3px 8px; border:1px solid #cbd5e0; border-radius:4px;">
        </div>
        <div class="modal-row">
            <label style="width: 140px; min-width: 140px; font-weight: bold; color: #4a5568;">Auteur(s) :</label>
            <input type="text" id="doc_auteur" placeholder="Auteur, réalisateur, artiste..." style="flex:1; height:34px; padding:3px 8px; border:1px solid #cbd5e0; border-radius:4px;">
        </div>
        <div class="modal-row">
            <label style="width: 140px; min-width: 140px; font-weight: bold; color: #4a5568;">Année :</label>
            <input type="text" id="doc_annee" placeholder="Ex: 2024" style="flex:1; height:34px; padding:3px 8px; border:1px solid #cbd5e0; border-radius:4px;">
        </div>

        <div class="modal-row">
            <label style="width: 140px; min-width: 140px; font-weight: bold; color: #4a5568;">Lien Gallica :</label>
            <div style="flex:1; display:flex; gap:6px; align-items:center;">
                <input type="text" id="doc_lien_gallica" placeholder="https://gallica.bnf.fr/ark:/12148/bpt6k64497g/f9" style="flex:1; height:34px; padding:3px 8px; border:1px solid #cbd5e0; border-radius:4px;">
                <button type="button" class="btn-secondary" id="btn-extract-gallica" style="height:34px; padding:0 10px; font-weight:bold; white-space:nowrap; cursor:pointer;">🖼️ Extraire l'image</button>
            </div>
        </div>

        <div id="gallica-crop-panel" class="gallica-crop-panel" style="display:none;">
            <div id="gallica-iiif-info" style="font-size:0.76rem; color:#4a5568; margin-bottom:6px; word-break:break-all;"></div>
            <div style="font-size:0.78rem; color:#2b6cb0; margin-bottom:6px;">
                💡 <em>Cliquez sur <strong>100%</strong> ou <strong>➕</strong> pour agrandir le texte, faites défiler jusqu'au passage voulu, puis tracez un cadre à la souris.</em>
            </div>

            <div class="gallica-zoom-toolbar">
                <button type="button" class="btn-secondary" id="btn-gallica-zoom-out" title="Dézoomer">➖</button>
                <input type="range" id="gallica-zoom-slider" min="15" max="250" value="40" step="5" style="width:120px; cursor:pointer;">
                <button type="button" class="btn-secondary" id="btn-gallica-zoom-in" title="Zoomer">➕</button>
                <span id="gallica-zoom-label" style="font-weight:bold; min-width:45px; text-align:center; color:#2d3748;">40%</span>
                <button type="button" class="btn-secondary" id="btn-gallica-zoom-fit" title="Ajuster à la largeur">Ajuster</button>
                <button type="button" class="btn-secondary" id="btn-gallica-zoom-100" title="Taille réelle haute définition">100% (Texte réel)</button>
                <button type="button" class="btn-secondary" id="btn-gallica-expand-modal" style="margin-left:auto;" title="Agrandir ou réduire la modale">⛶ Agrandir la fenêtre</button>
            </div>

            <div class="gallica-viewport" id="gallica-viewport">
                <div class="gallica-stage" id="gallica-stage">
                    <img id="gallica-preview-img" src="" alt="Aperçu Gallica">
                    <div class="gallica-selection-box" id="gallica-selection-box"></div>
                </div>
            </div>

            <div class="gallica-crop-actions">
                <button type="button" class="btn-primary" id="btn-apply-gallica-crop" style="padding:4px 10px; font-size:0.8rem;">✂️ Rogner la sélection</button>
                <button type="button" class="btn-secondary" id="btn-reset-gallica-crop" style="padding:4px 10px; font-size:0.8rem;">↩️ Page entière</button>
                <button type="button" class="btn-secondary" id="btn-clear-gallica-img" style="padding:4px 10px; font-size:0.8rem; color:#c53030;">🗑️ Retirer l'image</button>
                <span id="gallica-crop-status" style="font-size:0.78rem; color:#2f855a; font-weight:bold;"></span>
            </div>
        </div>

        <div class="modal-row" style="align-items:flex-start;">
            <label style="width: 140px; min-width: 140px; font-weight: bold; color: #4a5568; margin-top:6px;">Notes & Liens :</label>
            <textarea id="doc_notes" placeholder="Maison d'édition, autres URL, observations..." rows="2" style="flex:1; padding:6px 8px; border:1px solid #cbd5e0; border-radius:4px;"></textarea>
        </div>

        <div style="text-align:right; margin-top:15px; padding-top: 12px; border-top: 1px solid #e2e8f0;">
            <button type="button" class="btn-primary" id="btn-save-documentation" style="padding:6px 18px;">✔ Valider</button>
        </div>
        <input type="hidden" id="modal_edit_index_doc" value="-1">
    </div>
</div>

<!-- 3. Modal Inscriptions -->
<div class="standard-modal-overlay" id="inscription-modal-overlay">
    <div class="standard-modal" id="inscription-modal-box" style="max-width: 900px;">
        <h3>Ajouter / Éditer une inscription</h3>
        <button type="button" class="standard-modal-close" onclick="document.getElementById('inscription-modal-overlay').style.display='none'">✖</button>

        <div style="display:flex; gap:18px; align-items:flex-start; flex-wrap:wrap;">
            <div style="flex:1.25; min-width:320px;">
                <div class="modal-row" style="margin-bottom:8px;">
                    <label style="width: 120px; min-width: 120px; font-weight: bold; color: #4a5568;">Fichier :</label>
                    <input type="file" id="modal_inscription_photo_file" accept="image/*" style="flex:1; font-size:0.8rem;">
                </div>

                <div class="modal-row" style="margin-bottom:10px;">
                    <label style="width: 120px; min-width: 120px; font-weight: bold; color: #4a5568;">Ou lien Gallica :</label>
                    <div style="flex:1; display:flex; gap:6px; align-items:center;">
                        <input type="text" id="insc_lien_gallica" placeholder="https://gallica.bnf.fr/ark:/12148/..." style="flex:1; height:34px; padding:3px 8px; border:1px solid #cbd5e0; border-radius:4px;">
                        <button type="button" class="btn-secondary" id="btn-extract-insc-gallica" style="height:34px; padding:0 10px; font-weight:bold; white-space:nowrap; cursor:pointer;">🖼️ Extraire</button>
                    </div>
                </div>

                <div id="insc-crop-panel" class="gallica-crop-panel" style="display:none; margin-bottom:0;">
                    <div id="insc-iiif-info" style="font-size:0.76rem; color:#4a5568; margin-bottom:6px; word-break:break-all;"></div>
                    <div style="font-size:0.78rem; color:#2b6cb0; margin-bottom:6px;">
                        💡 <em>Zoomez sur l'inscription (<strong>100%</strong> ou <strong>➕</strong>), tracez un cadre à la souris puis cliquez sur <strong>✂️ Rogner</strong>.</em>
                    </div>

                    <div class="gallica-zoom-toolbar">
                        <button type="button" class="btn-secondary" id="btn-insc-zoom-out" title="Dézoomer">➖</button>
                        <input type="range" id="insc-zoom-slider" min="15" max="250" value="40" step="5" style="width:105px; cursor:pointer;">
                        <button type="button" class="btn-secondary" id="btn-insc-zoom-in" title="Zoomer">➕</button>
                        <span id="insc-zoom-label" style="font-weight:bold; min-width:42px; text-align:center; color:#2d3748;">40%</span>
                        <button type="button" class="btn-secondary" id="btn-insc-zoom-fit" title="Ajuster à la largeur">Ajuster</button>
                        <button type="button" class="btn-secondary" id="btn-insc-zoom-100" title="Taille réelle haute définition">100%</button>
                        <button type="button" class="btn-secondary" id="btn-insc-expand-modal" style="margin-left:auto;" title="Agrandir ou réduire la modale">⛶ Agrandir</button>
                    </div>

                    <div class="gallica-viewport" id="insc-viewport">
                        <div class="gallica-stage" id="insc-stage">
                            <img id="insc-preview-img" src="" alt="Aperçu photo inscription">
                            <div class="gallica-selection-box" id="insc-selection-box"></div>
                        </div>
                    </div>

                    <div class="gallica-crop-actions">
                        <button type="button" class="btn-primary" id="btn-apply-insc-crop" style="padding:4px 10px; font-size:0.8rem;">✂️ Rogner la sélection</button>
                        <button type="button" class="btn-secondary" id="btn-reset-insc-crop" style="padding:4px 10px; font-size:0.8rem;">↩️ Image entière</button>
                        <button type="button" class="btn-secondary" id="btn-clear-insc-img" style="padding:4px 10px; font-size:0.8rem; color:#c53030;">🗑️ Retirer l'image</button>
                        <span id="insc-crop-status" style="font-size:0.78rem; color:#2f855a; font-weight:bold;"></span>
                    </div>
                </div>
            </div>

            <div style="flex:1; min-width:270px;">
                <div style="margin-bottom:12px;">
                    <label for="modal_inscription_type" style="display:block; font-weight:bold; color:#4a5568; margin-bottom:4px; font-size:0.83rem;">Type :</label>
                    <div style="display:flex; align-items:center; gap:6px;">
                        <select id="modal_inscription_type" data-thesaurus-key="typesInscriptions" style="flex:1; height:34px; padding:3px 8px; border:1px solid #cbd5e0; border-radius:4px;">
                            <option value="">-- Sélectionner un type --</option>
                            <?php foreach ($typesInscriptions as $typeInst): ?>
                                <option value="<?= htmlspecialchars($typeInst) ?>"><?= htmlspecialchars($typeInst) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= renderProposeBtn('typesInscriptions', 'Types d\'inscriptions', '#modal_inscription_type') ?>
                    </div>
                </div>

                <div>
                    <label for="modal_inscription_contenu" style="display:block; font-weight:bold; color:#4a5568; margin-bottom:4px; font-size:0.83rem;">Contenu / Retranscription :</label>
                    <textarea id="modal_inscription_contenu" placeholder="Saisir le texte de l'inscription..." rows="12" style="width:100%; padding:6px 8px; border:1px solid #cbd5e0; border-radius:4px; box-sizing:border-box; font-family:inherit; resize:vertical;"></textarea>
                </div>
            </div>
        </div>

        <div style="text-align:right; margin-top:20px; padding-top: 12px; border-top: 1px solid #e2e8f0;">
            <button type="button" class="btn-primary" id="btn-save-inscription" style="padding:6px 18px;">✔ Valider</button>
        </div>
        <input type="hidden" id="modal_edit_index_inscription" value="-1">
    </div>
</div>

<!-- 4. Modal Photos -->
<div class="standard-modal-overlay" id="photo-modal-overlay">
    <div class="standard-modal" id="photo-modal-box" style="max-width: 650px;">
        <h3 id="photo-modal-title">Ajouter une photo</h3>
        <button type="button" class="standard-modal-close" onclick="document.getElementById('photo-modal-overlay').style.display='none'">✖</button>

        <div class="modal-row" style="margin-bottom: 8px;">
            <label style="width: 165px; min-width: 165px; font-weight: bold; color: #4a5568;">Fichier :</label>
            <div style="flex: 1;">
                <input type="file" id="modal_photo_file" accept="image/*">
                <span id="file-name-label" style="font-size:0.8rem; color:#666; display:block; margin-top:4px;">Aucun fichier sélectionné.</span>
            </div>
        </div>

        <div class="modal-row">
            <label for="photo_lien_gallica" style="width: 165px; min-width: 165px; font-weight: bold; color: #4a5568;">Ou lien Gallica :</label>
            <div style="flex: 1; display: flex; gap: 6px; align-items: center;">
                <input type="text" id="photo_lien_gallica" placeholder="https://gallica.bnf.fr/ark:/12148/..." style="flex: 1; height: 34px; padding: 3px 8px; border: 1px solid #cbd5e0; border-radius: 4px;">
                <button type="button" class="btn-secondary" id="btn-extract-photo-gallica" style="height: 34px; padding: 0 10px; font-weight: bold; white-space: nowrap; cursor: pointer;">🖼️ Extraire</button>
            </div>
        </div>

        <div id="photo-crop-panel" class="gallica-crop-panel" style="display: none;">
            <div id="photo-iiif-info" style="font-size: 0.76rem; color: #4a5568; margin-bottom: 6px; word-break: break-all;"></div>
            <div style="font-size: 0.78rem; color: #2b6cb0; margin-bottom: 6px;">
                💡 <em>Zoomez sur l’image (<strong>100%</strong> ou <strong>➕</strong>), tracez un cadre à la souris puis cliquez sur <strong>✂️ Rogner la sélection</strong>.</em>
            </div>

            <div class="gallica-zoom-toolbar">
                <button type="button" class="btn-secondary" id="btn-photo-zoom-out" title="Dézoomer">➖</button>
                <input type="range" id="photo-zoom-slider" min="15" max="250" value="40" step="5" style="width: 110px; cursor: pointer;">
                <button type="button" class="btn-secondary" id="btn-photo-zoom-in" title="Zoomer">➕</button>
                <span id="photo-zoom-label" style="font-weight: bold; min-width: 45px; text-align: center; color: #2d3748;">40%</span>
                <button type="button" class="btn-secondary" id="btn-photo-zoom-fit" title="Ajuster à la largeur">Ajuster</button>
                <button type="button" class="btn-secondary" id="btn-photo-zoom-100" title="Taille réelle haute définition">100%</button>
                <button type="button" class="btn-secondary" id="btn-photo-expand-modal" style="margin-left: auto;" title="Agrandir ou réduire la modale">⛶ Agrandir</button>
            </div>

            <div class="gallica-viewport" id="photo-viewport">
                <div class="gallica-stage" id="photo-stage">
                    <img id="photo-preview-img" src="" alt="Aperçu Gallica">
                    <div class="gallica-selection-box" id="photo-selection-box"></div>
                </div>
            </div>

            <div class="gallica-crop-actions">
                <button type="button" class="btn-primary" id="btn-apply-photo-crop" style="padding: 4px 10px; font-size: 0.8rem;">✂️ Rogner la sélection</button>
                <button type="button" class="btn-secondary" id="btn-reset-photo-crop" style="padding: 4px 10px; font-size: 0.8rem;">↩️ Page entière</button>
                <button type="button" class="btn-secondary" id="btn-clear-photo-img" style="padding: 4px 10px; font-size: 0.8rem; color: #c53030;">🗑️ Retirer l’image</button>
                <span id="photo-crop-status" style="font-size: 0.78rem; color: #2f855a; font-weight: bold;"></span>
            </div>
        </div>

        <div class="modal-row">
            <label for="modal_photo_titre" style="width: 165px; min-width: 165px; font-weight: bold; color: #4a5568;">Titre :</label>
            <input type="text" id="modal_photo_titre" placeholder="Titre de la photo" style="flex: 1; height:34px; padding: 3px 8px; border: 1px solid #cbd5e0; border-radius: 4px;">
        </div>
        <div class="modal-row">
            <label for="modal_photo_type_doc" style="width: 165px; min-width: 165px; font-weight: bold; color: #4a5568;">Type de document :</label>
            <div style="flex: 1; display:flex; align-items:center; gap:6px;">
                <select id="modal_photo_type_doc" data-thesaurus-key="typesDocumentPhoto" style="flex: 1; height:34px; padding: 3px 8px; border: 1px solid #cbd5e0; border-radius: 4px;">
                    <option value="">-- Sélectionner un type --</option>
                    <?php foreach ($typesDocumentPhotoList as $typeDocPhoto): ?>
                        <option value="<?= htmlspecialchars($typeDocPhoto) ?>"><?= htmlspecialchars($typeDocPhoto) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= renderProposeBtn('typesDocumentPhoto', 'Types de document (Photos)', '#modal_photo_type_doc') ?>
            </div>
        </div>
        <div class="modal-row">
            <label for="modal_photo_auteur" style="width: 165px; min-width: 165px; font-weight: bold; color: #4a5568;">Auteur :</label>
            <input type="text" id="modal_photo_auteur" placeholder="Nom du photographe ou de l'auteur" style="flex: 1; height:34px; padding: 3px 8px; border: 1px solid #cbd5e0; border-radius: 4px;">
        </div>
        <div class="modal-row">
            <label for="modal_photo_lieu" style="width: 165px; min-width: 165px; font-weight: bold; color: #4a5568;">Lieu de la prise de vue :</label>
            <input type="text" id="modal_photo_lieu" placeholder="Ville, édifice, emplacement..." style="flex: 1; height:34px; padding: 3px 8px; border: 1px solid #cbd5e0; border-radius: 4px;">
        </div>
        <div class="modal-row" id="row_photo_date">
            <label style="width: 165px; min-width: 165px; font-weight: bold; color: #4a5568;">Date de prise de vue :</label>
            <div style="flex: 1; display:flex; gap: 8px; align-items:center;">
                <input type="text" id="modal_photo_jour" placeholder="JJ" maxlength="2" style="width: 45px; height:34px; padding: 3px 6px; border: 1px solid #cbd5e0; border-radius: 4px;">
                <input type="text" id="modal_photo_mois" placeholder="MM" maxlength="2" style="width: 45px; height:34px; padding: 3px 6px; border: 1px solid #cbd5e0; border-radius: 4px;">
                <input type="text" id="modal_photo_annee" placeholder="AAAA" maxlength="4" style="width: 65px; height:34px; padding: 3px 6px; border: 1px solid #cbd5e0; border-radius: 4px;">
                <button type="button" class="btn-secondary" onclick="clearPhotoDate()" style="background:#c53030; color:#fff; border:none; height:34px; padding:0 10px; border-radius:4px; cursor:pointer;" title="Effacer la date">🗑</button>
            </div>
        </div>
        <div class="modal-row" style="align-items:flex-start;">
            <label for="modal_photo_desc" style="width: 165px; min-width: 165px; font-weight: bold; color: #4a5568; margin-top:6px;">Description :</label>
            <textarea id="modal_photo_desc" style="flex:1; min-height:65px; padding:6px 8px; border:1px solid #cbd5e0; border-radius:4px;"></textarea>
        </div>
        <div class="modal-row" style="align-items:flex-start;">
            <label for="modal_photo_source" style="width: 165px; min-width: 165px; font-weight: bold; color: #4a5568; margin-top:6px;">Source :</label>
            <textarea id="modal_photo_source" style="flex:1; min-height:50px; padding:6px 8px; border:1px solid #cbd5e0; border-radius:4px;"></textarea>
        </div>

        <div style="text-align:right; margin-top:20px; padding-top: 12px; border-top: 1px solid #e2e8f0;">
            <button type="button" class="btn-primary" id="btn-save-photo" style="padding:6px 18px;">✔ Valider et Uploader</button>
        </div>
        <input type="hidden" id="modal_photo_target_section">
        <input type="hidden" id="modal_photo_has_date">
        <input type="hidden" id="modal_photo_edit_index" value="-1">
    </div>
</div>

<!-- 5. Modal Visionneuse d'image en grand -->
<div class="standard-modal-overlay" id="image-viewer-overlay" onclick="this.style.display='none'">
    <div class="standard-modal" style="width: auto; max-width: 90vw; background: transparent; box-shadow: none; text-align: center; padding: 0; overflow: visible;" onclick="event.stopPropagation();">
        <button type="button" class="standard-modal-close" style="color: #fff; right: 0; top: -35px; font-size: 28px; text-shadow: 0 2px 4px rgba(0,0,0,0.5);" onclick="document.getElementById('image-viewer-overlay').style.display='none'">✖</button>
        <img id="viewer-img-hd" src="" alt="Aperçu en grand" style="max-width: 100%; max-height: 85vh; border-radius: 8px; box-shadow: 0 5px 25px rgba(0,0,0,0.5);">
    </div>
</div>

<!-- 6. Modal Proposition d'un nouveau terme simple au Thésaurus -->
<div class="standard-modal-overlay" id="propose-term-modal-overlay" style="z-index: 10500;">
    <div class="standard-modal" style="max-width: 480px;">
        <h3>Proposer un nouveau terme</h3>
        <button type="button" class="standard-modal-close" onclick="closeProposeTermModal()">✖</button>

        <p style="font-size: 0.85rem; color: #4a5568; margin-top: 0; margin-bottom: 12px;">
            Liste concernée : <strong id="propose-list-label" style="color: #2b6cb0;"></strong>
        </p>

        <div class="modal-row">
            <label for="propose-term-input" style="width: 130px; font-weight: bold; color: #4a5568;">Nouveau terme :</label>
            <input type="text" id="propose-term-input" placeholder="Saisir le terme à soumettre..." autocomplete="off" style="flex: 1; height: 34px; padding: 4px 10px; border: 1px solid #cbd5e0; border-radius: 4px;">
        </div>

        <div id="propose-term-feedback" style="display: none; margin-top: 10px; padding: 8px 12px; border-radius: 4px; font-size: 0.84rem;"></div>

        <p style="font-size: 0.78rem; color: #718096; margin-top: 10px; margin-bottom: 0;">
            Ce terme sera transmis à l'administrateur pour validation avant intégration définitive au thésaurus.
        </p>

        <div style="text-align: right; margin-top: 18px; padding-top: 12px; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end; gap: 8px;">
            <button type="button" class="btn-secondary" onclick="closeProposeTermModal()" style="padding: 6px 14px;">Annuler</button>
            <button type="button" class="btn-primary" id="btn-submit-propose-term" onclick="submitProposeTerm()" style="padding: 6px 16px;">📩 Envoyer à l'administrateur</button>
        </div>

        <input type="hidden" id="propose-list-key">
        <input type="hidden" id="propose-target-selector">
    </div>
</div>

<!-- 7. Modal Proposition d'un nouvel auteur (Fiche développée) -->
<div class="standard-modal-overlay" id="propose-auteur-modal-overlay" style="z-index: 10500;">
    <div class="standard-modal" style="max-width: 640px; max-height: 90vh; overflow-y: auto;">
        <h3>Proposer un nouvel auteur</h3>
        <button type="button" class="standard-modal-close" onclick="closeProposeAuteurModal()">✖</button>

        <p style="font-size: 0.85rem; color: #4a5568; margin-top: 0; margin-bottom: 10px;">
            Métier concerné : <strong id="propose-auteur-metier-label" style="color: #16989d;"></strong>
        </p>

        <!-- MODE A : PERSONNES PHYSIQUES (Identité / Naissance / Décès) -->
        <div id="propose-auteur-detailed-fields" style="display: none;">
            <div class="person-fieldset-card">
                <span class="person-fieldset-badge">Identité</span>
                <div class="person-id-row">
                    <label for="propose-auteur-nom-det">Nom *</label>
                    <input type="text" id="propose-auteur-nom-det" autocomplete="off">
                </div>
                <div class="person-id-row">
                    <label for="propose-auteur-prenom-det">Prénom</label>
                    <input type="text" id="propose-auteur-prenom-det" autocomplete="off">
                </div>
                <div class="person-id-row">
                    <label for="propose-auteur-sexe">Sexe</label>
                    <select id="propose-auteur-sexe">
                        <option value="">-- Non spécifié --</option>
                        <option value="Homme">Homme</option>
                        <option value="Femme">Femme</option>
                    </select>
                </div>
            </div>

            <div class="person-fieldset-card">
                <span class="person-fieldset-badge">Naissance</span>
                <div class="person-3col-grid">
                    <div class="col-annee">
                        <label for="propose-auteur-naiss-annee">Année</label>
                        <input type="text" id="propose-auteur-naiss-annee" autocomplete="off">
                    </div>
                    <div class="col-lieu">
                        <label for="propose-auteur-naiss-lieu">Lieu</label>
                        <input type="text" id="propose-auteur-naiss-lieu" autocomplete="off">
                    </div>
                    <div class="col-pays">
                        <label for="propose-auteur-naiss-pays">Pays</label>
                        <input type="text" id="propose-auteur-naiss-pays" autocomplete="off">
                    </div>
                </div>
            </div>

            <div class="person-fieldset-card">
                <span class="person-fieldset-badge">Décès</span>
                <div class="person-3col-grid">
                    <div class="col-annee">
                        <label for="propose-auteur-deces-annee">Année</label>
                        <input type="text" id="propose-auteur-deces-annee" autocomplete="off">
                    </div>
                    <div class="col-lieu">
                        <label for="propose-auteur-deces-lieu">Lieu</label>
                        <input type="text" id="propose-auteur-deces-lieu" autocomplete="off">
                    </div>
                    <div class="col-pays">
                        <label for="propose-auteur-deces-pays">Pays</label>
                        <input type="text" id="propose-auteur-deces-pays" autocomplete="off">
                    </div>
                </div>
            </div>

            <div class="person-fieldset-card">
                <span class="person-fieldset-badge">Ressources &amp; Notes</span>
                <div class="person-id-row">
                    <label for="propose-auteur-source-det">Permalien</label>
                    <input type="url" id="propose-auteur-source-det" placeholder="https://..." autocomplete="off">
                </div>
                <div class="person-id-row" style="align-items: flex-start;">
                    <label for="propose-auteur-bio-det" style="margin-top: 6px;">Biographie / Notes</label>
                    <textarea id="propose-auteur-bio-det" rows="3" style="flex: 1; padding: 6px 10px; border: 1px solid #cbd5e0; border-radius: 5px; font-family: inherit;"></textarea>
                </div>
            </div>
        </div>

        <!-- MODE B : ATELIERS / ENTREPRISES (Fondeurs, Entrepreneurs, Marbriers...) -->
        <div id="propose-auteur-standard-fields" style="display: none;">
            <div class="person-fieldset-card">
                <span class="person-fieldset-badge">Fiche thésaurus</span>
                <div class="person-id-row">
                    <label for="propose-auteur-nom-std">Nom / Raison sociale *</label>
                    <input type="text" id="propose-auteur-nom-std" placeholder="Ex : Fonderie Barbedienne" autocomplete="off">
                </div>
                <div class="person-id-row">
                    <label for="propose-auteur-prenom-std">Prénom</label>
                    <input type="text" id="propose-auteur-prenom-std" placeholder="Optionnel" autocomplete="off">
                </div>
                <div class="person-id-row">
                    <label for="propose-auteur-dates-std">Dates</label>
                    <input type="text" id="propose-auteur-dates-std" placeholder="Ex : 1838 - 1921" autocomplete="off">
                </div>
                <div class="person-id-row">
                    <label for="propose-auteur-lieu-std">Lieu(x)</label>
                    <input type="text" id="propose-auteur-lieu-std" placeholder="Ville, atelier, fonderie..." autocomplete="off">
                </div>
                <div class="person-id-row">
                    <label for="propose-auteur-source-std">Permalien / Source</label>
                    <input type="text" id="propose-auteur-source-std" placeholder="https://... ou référence" autocomplete="off">
                </div>
                <div class="person-id-row" style="align-items: flex-start;">
                    <label for="propose-auteur-bio-std" style="margin-top: 6px;">Biographie / Notes</label>
                    <textarea id="propose-auteur-bio-std" rows="3" style="flex: 1; padding: 6px 10px; border: 1px solid #cbd5e0; border-radius: 5px; font-family: inherit;"></textarea>
                </div>
            </div>
        </div>

        <div id="propose-auteur-feedback" style="display: none; margin-top: 12px; padding: 8px 12px; border-radius: 4px; font-size: 0.84rem;"></div>

        <p style="font-size: 0.78rem; color: #718096; margin-top: 10px; margin-bottom: 0;">
            Cette fiche auteur sera transmise à l'administrateur pour validation avant intégration définitive.
        </p>

        <div style="text-align: right; margin-top: 16px; padding-top: 12px; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end; gap: 8px;">
            <button type="button" class="btn-secondary" onclick="closeProposeAuteurModal()" style="padding: 6px 14px;">Annuler</button>
            <button type="button" class="btn-primary" id="btn-submit-propose-auteur" onclick="submitProposeAuteur()" style="padding: 6px 16px;">📩 Envoyer à l'administrateur</button>
        </div>

        <input type="hidden" id="propose-auteur-metier-val">
        <input type="hidden" id="propose-auteur-mode">
    </div>
</div>

<!-- INJECTION DES DONNÉES PHP VERS LE SCRIPT EXTERNE -->
<script>
window.MONUMENT_CONFIG = {
    siteName: <?= json_encode($siteName) ?>,
    metiersData: <?= json_encode($auteursDataJS) ?>,
    wilayasMap: <?= json_encode($wilayasMap) ?>,
    allMonumentsSearch: <?= json_encode($allMonumentsSearch) ?>
};

function openProposeTermModal(listKey, listLabel, targetSelector) {
    document.getElementById('propose-list-key').value = listKey;
    document.getElementById('propose-list-label').textContent = listLabel;
    document.getElementById('propose-target-selector').value = targetSelector || '';

    const input = document.getElementById('propose-term-input');
    const feedback = document.getElementById('propose-term-feedback');
    input.value = '';
    feedback.style.display = 'none';
    feedback.textContent = '';

    document.getElementById('propose-term-modal-overlay').style.display = 'flex';
    setTimeout(() => input.focus(), 50);
}

function closeProposeTermModal() {
    document.getElementById('propose-term-modal-overlay').style.display = 'none';
}

function submitProposeTerm() {
    const listKey        = document.getElementById('propose-list-key').value;
    const termInput      = document.getElementById('propose-term-input');
    const term           = termInput.value.trim();
    const targetSelector = document.getElementById('propose-target-selector').value;
    const feedback       = document.getElementById('propose-term-feedback');
    const submitBtn      = document.getElementById('btn-submit-propose-term');
    const csrfToken      = document.getElementById('global_csrf_token').value;

    if (!term) {
        feedback.style.display = 'block';
        feedback.style.background = '#fed7d7';
        feedback.style.color = '#9b2c2c';
        feedback.textContent = 'Veuillez saisir un terme avant d\'envoyer.';
        termInput.focus();
        return;
    }

    submitBtn.disabled = true;

    const formData = new URLSearchParams();
    formData.append('csrf_token', csrfToken);
    formData.append('proposal_type', 'term');
    formData.append('list_key', listKey);
    formData.append('term', term);

    fetch('propose_thesaurus.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData.toString()
    })
    .then(res => res.json())
    .then(data => {
        submitBtn.disabled = false;
        feedback.style.display = 'block';

        if (data.success) {
            feedback.style.background = '#c6f6d5';
            feedback.style.color = '#22543d';
            feedback.textContent = data.message;

            if (targetSelector) {
                const selects = document.querySelectorAll(targetSelector);
                selects.forEach((sel, idx) => {
                    let exists = Array.from(sel.options).some(o => o.value.toLowerCase() === term.toLowerCase());
                    if (!exists) {
                        const opt = document.createElement('option');
                        opt.value = term;
                        opt.textContent = term + ' (en attente de validation)';
                        sel.appendChild(opt);
                    }
                    if (idx === selects.length - 1) {
                        sel.value = term;
                        sel.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                });
            }

            if (listKey === 'metiers' && window.MONUMENT_CONFIG && window.MONUMENT_CONFIG.metiersData) {
                if (!window.MONUMENT_CONFIG.metiersData[term]) {
                    window.MONUMENT_CONFIG.metiersData[term] = [];
                }
            }

            setTimeout(() => closeProposeTermModal(), 1100);
        } else {
            feedback.style.background = '#fed7d7';
            feedback.style.color = '#9b2c2c';
            feedback.textContent = data.message || 'Une erreur est survenue.';
        }
    })
    .catch(() => {
        submitBtn.disabled = false;
        feedback.style.display = 'block';
        feedback.style.background = '#fed7d7';
        feedback.style.color = '#9b2c2c';
        feedback.textContent = 'Erreur réseau lors de l\'envoi de la proposition.';
    });
}

document.getElementById('propose-term-input')?.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        submitProposeTerm();
    }
});

// =========================================================================
// MODALE DE PROPOSITION D'UN NOUVEL AUTEUR (IDENTITÉ / NAISSANCE / DÉCÈS)
// =========================================================================
function isDetailedMetierJS(metier) {
    const clean = metier.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
    return ['architecte', 'medailleur', 'sculpteur'].includes(clean);
}

function openProposeAuteurModal() {
    const metierSelect = document.getElementById('modal_metier');
    const metier = metierSelect ? metierSelect.value.trim() : '';

    if (!metier) {
        alert('Veuillez d\'abord sélectionner un métier dans la liste déroulante.');
        if (metierSelect) metierSelect.focus();
        return;
    }

    const isDetailed = isDetailedMetierJS(metier);
    document.getElementById('propose-auteur-metier-val').value = metier;
    document.getElementById('propose-auteur-metier-label').textContent = metier;
    document.getElementById('propose-auteur-mode').value = isDetailed ? 'detailed' : 'standard';

    const detailedBox = document.getElementById('propose-auteur-detailed-fields');
    const standardBox = document.getElementById('propose-auteur-standard-fields');

    if (isDetailed) {
        detailedBox.style.display = 'block';
        standardBox.style.display = 'none';
        document.getElementById('propose-auteur-nom-det').value = '';
        document.getElementById('propose-auteur-prenom-det').value = '';
        document.getElementById('propose-auteur-sexe').value = '';
        document.getElementById('propose-auteur-naiss-annee').value = '';
        document.getElementById('propose-auteur-naiss-lieu').value = '';
        document.getElementById('propose-auteur-naiss-pays').value = '';
        document.getElementById('propose-auteur-deces-annee').value = '';
        document.getElementById('propose-auteur-deces-lieu').value = '';
        document.getElementById('propose-auteur-deces-pays').value = '';
        document.getElementById('propose-auteur-bio-det').value = '';
        document.getElementById('propose-auteur-source-det').value = '';
    } else {
        detailedBox.style.display = 'none';
        standardBox.style.display = 'block';
        document.getElementById('propose-auteur-nom-std').value = '';
        document.getElementById('propose-auteur-prenom-std').value = '';
        document.getElementById('propose-auteur-dates-std').value = '';
        document.getElementById('propose-auteur-lieu-std').value = '';
        document.getElementById('propose-auteur-bio-std').value = '';
        document.getElementById('propose-auteur-source-std').value = '';
    }

    const feedback = document.getElementById('propose-auteur-feedback');
    feedback.style.display = 'none';
    feedback.textContent = '';

    document.getElementById('propose-auteur-modal-overlay').style.display = 'flex';
    setTimeout(() => {
        const firstInput = document.getElementById(isDetailed ? 'propose-auteur-nom-det' : 'propose-auteur-nom-std');
        if (firstInput) firstInput.focus();
    }, 50);
}

function closeProposeAuteurModal() {
    document.getElementById('propose-auteur-modal-overlay').style.display = 'none';
}

function submitProposeAuteur() {
    const metier    = document.getElementById('propose-auteur-metier-val').value.trim();
    const mode      = document.getElementById('propose-auteur-mode').value;
    const feedback  = document.getElementById('propose-auteur-feedback');
    const submitBtn = document.getElementById('btn-submit-propose-auteur');
    const csrfToken = document.getElementById('global_csrf_token').value;

    const formData = new URLSearchParams();
    formData.append('csrf_token', csrfToken);
    formData.append('proposal_type', 'person');
    formData.append('metier', metier);

    let nom = '';
    let prenom = '';

    if (mode === 'detailed') {
        const nomInput = document.getElementById('propose-auteur-nom-det');
        nom    = nomInput.value.trim();
        prenom = document.getElementById('propose-auteur-prenom-det').value.trim();

        if (!nom) {
            feedback.style.display = 'block';
            feedback.style.background = '#fed7d7';
            feedback.style.color = '#9b2c2c';
            feedback.textContent = 'Veuillez saisir le nom de l\'auteur.';
            nomInput.focus();
            return;
        }

        const sourceVal = document.getElementById('propose-auteur-source-det').value.trim();
        formData.append('nom', nom);
        formData.append('prenom', prenom);
        formData.append('sexe', document.getElementById('propose-auteur-sexe').value);
        formData.append('naissance_annee', document.getElementById('propose-auteur-naiss-annee').value.trim());
        formData.append('naissance_lieu', document.getElementById('propose-auteur-naiss-lieu').value.trim());
        formData.append('naissance_pays', document.getElementById('propose-auteur-naiss-pays').value.trim());
        formData.append('deces_annee', document.getElementById('propose-auteur-deces-annee').value.trim());
        formData.append('deces_lieu', document.getElementById('propose-auteur-deces-lieu').value.trim());
        formData.append('deces_pays', document.getElementById('propose-auteur-deces-pays').value.trim());
        formData.append('biographie', document.getElementById('propose-auteur-bio-det').value.trim());
        formData.append('permalien', sourceVal);
        formData.append('source', sourceVal);
    } else {
        const nomInput = document.getElementById('propose-auteur-nom-std');
        nom    = nomInput.value.trim();
        prenom = document.getElementById('propose-auteur-prenom-std').value.trim();

        if (!nom) {
            feedback.style.display = 'block';
            feedback.style.background = '#fed7d7';
            feedback.style.color = '#9b2c2c';
            feedback.textContent = 'Veuillez saisir le nom ou la raison sociale de l\'auteur.';
            nomInput.focus();
            return;
        }

        const sourceVal = document.getElementById('propose-auteur-source-std').value.trim();
        formData.append('nom', nom);
        formData.append('prenom', prenom);
        formData.append('dates', document.getElementById('propose-auteur-dates-std').value.trim());
        formData.append('lieu', document.getElementById('propose-auteur-lieu-std').value.trim());
        formData.append('biographie', document.getElementById('propose-auteur-bio-std').value.trim());
        formData.append('permalien', sourceVal);
        formData.append('source', sourceVal);
    }

    submitBtn.disabled = true;

    fetch('propose_thesaurus.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData.toString()
    })
    .then(res => res.json())
    .then(data => {
        submitBtn.disabled = false;
        feedback.style.display = 'block';

        if (data.success) {
            feedback.style.background = '#c6f6d5';
            feedback.style.color = '#22543d';
            feedback.textContent = data.message;

            const fullName = data.full_name || (nom + (prenom ? ' ' + prenom : ''));

            if (window.MONUMENT_CONFIG && window.MONUMENT_CONFIG.metiersData) {
                if (!Array.isArray(window.MONUMENT_CONFIG.metiersData[metier])) {
                    window.MONUMENT_CONFIG.metiersData[metier] = [];
                }
                if (!window.MONUMENT_CONFIG.metiersData[metier].includes(fullName)) {
                    window.MONUMENT_CONFIG.metiersData[metier].push(fullName);
                }
            }

            const auteurSelect = document.getElementById('modal_auteur_select');
            if (auteurSelect) {
                const exists = Array.from(auteurSelect.options).some(o => o.value.toLowerCase() === fullName.toLowerCase());
                if (!exists) {
                    const opt = document.createElement('option');
                    opt.value = fullName;
                    opt.textContent = fullName + ' (en attente de validation)';
                    auteurSelect.appendChild(opt);
                }
                auteurSelect.value = fullName;
                auteurSelect.dispatchEvent(new Event('change', { bubbles: true }));
            }

            setTimeout(() => closeProposeAuteurModal(), 1100);
        } else {
            feedback.style.background = '#fed7d7';
            feedback.style.color = '#9b2c2c';
            feedback.textContent = data.message || 'Une erreur est survenue.';
        }
    })
    .catch(() => {
        submitBtn.disabled = false;
        feedback.style.display = 'block';
        feedback.style.background = '#fed7d7';
        feedback.style.color = '#9b2c2c';
        feedback.textContent = 'Erreur réseau lors de l\'envoi de la proposition.';
    });
}
</script>
<script src="monument_form.js"></script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/layout.php';
?>