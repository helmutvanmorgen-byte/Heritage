<?php
session_start();
date_default_timezone_set('Europe/Paris');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/MonumentConfig.php';
require_once __DIR__ . '/../includes/ThesaurusManager.php';

if (!isset($_SESSION['user_id'], $_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    die("Accès non autorisé.");
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$thesaurusFile = __DIR__ . '/../json/thesaurus.json';
$jsonDir       = __DIR__ . '/../json';
$proposalsFile = $jsonDir . '/thesaurus_propositions.json';

function ensureThesaurusKey(string $file, string $key, array $defaultValues): void
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
        file_put_contents(
            $file,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }
}

function getMetierJsonFilenameThesaurus(string $metier): string
{
    $map = [
        'sculpteur'    => 'sculpteurs.json',
        'architecte'   => 'architectes.json',
        'fondeur'      => 'fondeurs.json',
        'medailleur'   => 'medailleurs.json',
        'entrepreneur' => 'entrepreneurs.json',
        'marbrier'     => 'marbriers.json',
    ];

    $normalized = mb_strtolower(trim($metier), 'UTF-8');
    $ascii = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized) : $normalized;
    $slug = preg_replace('/[^a-z0-9]+/', '_', $ascii ?: $normalized);
    $slug = trim($slug, '_');

    if (isset($map[$slug])) {
        return $map[$slug];
    }
    return ($slug !== '' ? $slug . 's' : 'autres_auteurs') . '.json';
}

function ensureMetierFileExists(string $file): void
{
    $dir = dirname($file);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    if (!file_exists($file)) {
        file_put_contents($file, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
}

function loadAllProposalsThesaurus(string $file): array
{
    if (!file_exists($file)) {
        return [];
    }
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($file));
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function saveAllProposalsThesaurus(string $file, array $proposals): bool
{
    return file_put_contents(
        $file,
        json_encode(array_values($proposals), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    ) !== false;
}

ensureThesaurusKey($thesaurusFile, 'typesOeuvre', MonumentConfig::getTypes());
ensureThesaurusKey($thesaurusFile, 'materiaux', MonumentConfig::getMateriaux());
ensureThesaurusKey($thesaurusFile, 'techniques', MonumentConfig::getTechniques());
ensureThesaurusKey($thesaurusFile, 'typesDocumentation', [
    'Bibliographie', 'Sources', 'Webographie', 'Périodiques', 'Filmographie', 'Discographie'
]);
ensureThesaurusKey($thesaurusFile, 'metiers', [
    'Sculpteur', 'Architecte', 'Fondeur', 'Médailleur', 'Entrepreneur', 'Marbrier'
]);
ensureThesaurusKey($thesaurusFile, 'typesDocumentPhoto', [
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
]);

$listsConfig = [
    'pays'                 => ['title' => 'Pays', 'file' => $thesaurusFile, 'key' => 'pays'],
    'departements_france'  => ['title' => 'Départements (France)', 'file' => $thesaurusFile, 'key' => 'departementsFrance'],
    'departements_algerie' => ['title' => 'Départements (Algérie av. 1962)', 'file' => $thesaurusFile, 'key' => 'departementsAlgerieAvant1962'],
    'wilayas'              => ['title' => 'Wilayas (Algérie)', 'file' => $jsonDir . '/wilayas.json', 'key' => null],
    'types_localisation'   => ['title' => 'Types de localisation', 'file' => $thesaurusFile, 'key' => 'typesLocalisation'],
    'types_oeuvre'         => ['title' => 'Types d’œuvre', 'file' => $thesaurusFile, 'key' => 'typesOeuvre'],
    'materiaux'            => ['title' => 'Matériaux', 'file' => $thesaurusFile, 'key' => 'materiaux'],
    'techniques'           => ['title' => 'Techniques', 'file' => $thesaurusFile, 'key' => 'techniques'],
    'types_inscriptions'   => ['title' => 'Types d\'inscriptions', 'file' => $thesaurusFile, 'key' => 'typesInscriptions'],
    'types_documentation'  => ['title' => 'Types de documentation', 'file' => $thesaurusFile, 'key' => 'typesDocumentation'],
    'types_document_photo' => ['title' => 'Types de document (Photos)', 'file' => $thesaurusFile, 'key' => 'typesDocumentPhoto'],
    'parties'              => ['title' => 'Parties du monument', 'file' => $thesaurusFile, 'key' => 'partiesList'],
    'metiers'              => ['title' => 'Liste des métiers', 'file' => $thesaurusFile, 'key' => 'metiers'],
];

$allowedTargetKeys = ['wilayas'];
foreach ($listsConfig as $cfgItem) {
    if (!empty($cfgItem['key'])) {
        $allowedTargetKeys[] = $cfgItem['key'];
    }
}

$currentListKey = $_GET['list'] ?? 'pays';
if (!isset($listsConfig[$currentListKey])) {
    $currentListKey = 'pays';
}
$currentConf = $listsConfig[$currentListKey];
$sortOrder   = ($_GET['sort'] ?? 'az') === 'za' ? 'za' : 'az';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die("Erreur CSRF.");
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'approve_proposal' || $action === 'reject_proposal') {
        $propId       = trim($_POST['prop_id'] ?? '');
        $allProposals = loadAllProposalsThesaurus($proposalsFile);
        $foundProp    = null;

        foreach ($allProposals as $p) {
            if (($p['id'] ?? '') === $propId && ($p['proposal_type'] ?? 'term') === 'term') {
                $foundProp = $p;
                break;
            }
        }

        if ($foundProp) {
            if ($action === 'approve_proposal') {
                $targetKey     = $foundProp['list_key'] ?? '';
                $validatedTerm = trim($_POST['validated_term'] ?? ($foundProp['term'] ?? ''));

                if ($validatedTerm !== '' && in_array($targetKey, $allowedTargetKeys, true)) {
                    $targetFile = ($targetKey === 'wilayas') ? ($jsonDir . '/wilayas.json') : $thesaurusFile;
                    $jsonKey    = ($targetKey === 'wilayas') ? null : $targetKey;

                    ThesaurusManager::addTerm($targetFile, $jsonKey, $validatedTerm);

                    if ($targetKey === 'metiers') {
                        ensureMetierFileExists($jsonDir . '/' . getMetierJsonFilenameThesaurus($validatedTerm));
                    }

                    $_SESSION['flash_success'] = "Terme « " . $validatedTerm . " » validé et ajouté à « " . ($foundProp['list_label'] ?? $targetKey) . " ».";
                }
            } else {
                $_SESSION['flash_success'] = "Proposition refusée et supprimée.";
            }

            $allProposals = array_filter($allProposals, fn($p) => ($p['id'] ?? '') !== $propId);
            saveAllProposalsThesaurus($proposalsFile, $allProposals);
        }

        header("Location: gestion_thesaurus.php?list=" . urlencode($currentListKey) . "&sort=" . urlencode($sortOrder));
        exit;
    }

    $term = trim($_POST['term'] ?? '');

    if ($action === 'add' && $term !== '') {
        if (ThesaurusManager::addTerm($currentConf['file'], $currentConf['key'], $term)) {
            if ($currentListKey === 'metiers') {
                ensureMetierFileExists($jsonDir . '/' . getMetierJsonFilenameThesaurus($term));
            }
            $_SESSION['flash_success'] = "Terme ajouté avec succès.";
        }
    } elseif ($action === 'edit') {
        $oldTerm = $_POST['old_term'] ?? '';
        if ($oldTerm !== '' && $term !== '') {
            if (ThesaurusManager::editTerm($currentConf['file'], $currentConf['key'], $oldTerm, $term)) {
                if ($currentListKey === 'metiers') {
                    $oldFile = $jsonDir . '/' . getMetierJsonFilenameThesaurus($oldTerm);
                    $newFile = $jsonDir . '/' . getMetierJsonFilenameThesaurus($term);
                    if (file_exists($oldFile)) {
                        rename($oldFile, $newFile);
                    } else {
                        ensureMetierFileExists($newFile);
                    }
                }
                $_SESSION['flash_success'] = "Terme modifié avec succès.";
            }
        }
    } elseif ($action === 'delete') {
        if ($term !== '') {
            if (ThesaurusManager::deleteTerm($currentConf['file'], $currentConf['key'], $term)) {
                if ($currentListKey === 'metiers') {
                    $metierFile = $jsonDir . '/' . getMetierJsonFilenameThesaurus($term);
                    if (file_exists($metierFile)) {
                        @unlink($metierFile);
                    }
                }
                $_SESSION['flash_success'] = "Terme supprimé avec succès.";
            }
        }
    }

    header("Location: gestion_thesaurus.php?list=" . urlencode($currentListKey) . "&sort=" . urlencode($sortOrder));
    exit;
}

$flashSuccess = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_success']);

$allProposals     = loadAllProposalsThesaurus($proposalsFile);
$pendingProposals = array_values(array_filter($allProposals, fn($p) => ($p['proposal_type'] ?? 'term') === 'term'));

$terms = ThesaurusManager::getList($currentConf['file'], $currentConf['key']);
if ($sortOrder === 'za') {
    rsort($terms, SORT_NATURAL | SORT_FLAG_CASE);
} else {
    sort($terms, SORT_NATURAL | SORT_FLAG_CASE);
}

ob_start();
?>
<div class="content-2">
    <h2>Gestion du Thésaurus</h2>

    <?php if ($flashSuccess): ?>
        <div class="alert alert-success"><?= htmlspecialchars($flashSuccess) ?></div>
    <?php endif; ?>

    <?php if (!empty($pendingProposals)): ?>
        <div style="background: #fffbeb; border: 1px solid #f59e0b; border-radius: 6px; padding: 14px 16px; margin-bottom: 18px;">
            <h4 style="margin: 0 0 10px 0; color: #b45309; font-size: 0.95rem;">
                ⏳ Propositions de termes en attente de validation (<?= count($pendingProposals) ?>)
            </h4>
            <table class="standard-table" style="background: #fff; margin: 0;">
                <thead>
                    <tr>
                        <th style="width: 22%;">Liste cible</th>
                        <th>Terme proposé (modifiable avant validation)</th>
                        <th style="width: 18%;">Proposé par</th>
                        <th style="width: 185px; text-align: center;">Décision</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pendingProposals as $prop):
                        $pId = htmlspecialchars($prop['id']);
                    ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($prop['list_label'] ?? $prop['list_key'] ?? '') ?></strong></td>
                            <td>
                                <form method="post" id="form-prop-<?= $pId ?>" style="margin: 0;">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="prop_id" value="<?= $pId ?>">
                                    <input type="text" name="validated_term" value="<?= htmlspecialchars($prop['term'] ?? '') ?>" required style="width: 100%; height: 32px; padding: 0 8px; border: 1px solid #cbd5e0; border-radius: 4px; box-sizing: border-box;">
                                </form>
                            </td>
                            <td style="font-size: 0.85rem; color: #4a5568;">
                                <?= htmlspecialchars($prop['username'] ?? 'Inconnu') ?><br>
                                <span style="font-size: 0.78rem; color: #718096;"><?= htmlspecialchars($prop['created_at'] ?? '') ?></span>
                            </td>
                            <td style="text-align: center;">
                                <div style="display: flex; justify-content: center; gap: 6px;">
                                    <button type="submit" form="form-prop-<?= $pId ?>" name="action" value="approve_proposal" class="btn btn-success" style="height: 32px; padding: 0 10px; font-size: 0.82rem;">✅ Valider</button>
                                    <button type="submit" form="form-prop-<?= $pId ?>" name="action" value="reject_proposal" class="btn btn-danger" style="height: 32px; padding: 0 10px; font-size: 0.82rem;" onclick="return confirm('Refuser et supprimer cette proposition ?');">❌ Refuser</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <div class="thesaurus-layout">
        <div class="thesaurus-sidebar">
            <h4>Listes disponibles</h4>
            <ul>
                <?php foreach ($listsConfig as $k => $conf):
                    $count = count(ThesaurusManager::getList($conf['file'], $conf['key']));
                ?>
                    <li>
                        <a href="?list=<?= urlencode($k) ?>&sort=<?= htmlspecialchars($sortOrder) ?>" class="<?= ($currentListKey === $k) ? 'active' : '' ?>">
                            <?= htmlspecialchars($conf['title']) ?> <span style="font-size:0.8rem; color:var(--text-muted);">(<?= $count ?>)</span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div class="thesaurus-content">
            <div class="thesaurus-header" style="display: flex; justify-content: space-between; align-items: center; gap: 15px; flex-wrap: wrap; margin-bottom: 15px;">
                <h3 style="margin: 0;"><?= htmlspecialchars($currentConf['title']) ?></h3>
                
                <div style="display: flex; align-items: center; gap: 10px; flex-wrap: nowrap;">
                    <select onchange="changeSort(this.value)" aria-label="Ordre de tri" style="width: auto; min-width: 125px; height: 36px; padding: 0 10px; margin: 0; border: 1px solid var(--border-color); border-radius: 4px; background: #fff; flex-shrink: 0;">
                        <option value="az" <?= ($sortOrder === 'az') ? 'selected' : '' ?>>Tri : A à Z</option>
                        <option value="za" <?= ($sortOrder === 'za') ? 'selected' : '' ?>>Tri : Z à A</option>
                    </select>

                    <form method="post" class="inline-form" style="display: flex; align-items: center; gap: 8px; margin: 0; flex-wrap: nowrap;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="add">
                        <input type="text" name="term" placeholder="Nouveau terme..." required autocomplete="off" style="width: 190px; height: 36px; padding: 0 10px; margin: 0; border: 1px solid var(--border-color); border-radius: 4px; box-sizing: border-box;">
                        <button type="submit" class="btn btn-primary" style="height: 36px; padding: 0 14px; margin: 0; white-space: nowrap; flex-shrink: 0;">➕ Ajouter</button>
                    </form>
                </div>
            </div>

            <table class="standard-table">
                <thead>
                    <tr>
                        <th>Terme</th>
                        <th style="width: 100px; text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($terms)): ?>
                        <tr><td colspan="2" style="text-align:center; color:var(--text-muted); padding:20px;">Cette liste est vide.</td></tr>
                    <?php else: foreach ($terms as $term):
                        $termJsonAttr = htmlspecialchars(json_encode($term, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                        $keyJsonAttr  = htmlspecialchars(json_encode($currentListKey, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                    ?>
                        <tr>
                            <td><?= htmlspecialchars($term) ?></td>
                            <td style="text-align: center;">
                                <div style="display: flex; justify-content: center; align-items: center; gap: 15px;">
                                    <form method="post" class="inline-form" onsubmit="return promptEdit(this, <?= $termJsonAttr ?>)">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="action" value="edit">
                                        <input type="hidden" name="old_term" value="<?= htmlspecialchars($term) ?>">
                                        <input type="hidden" name="term" class="new-term-input">
                                        <button type="submit" class="action-icon" style="color:var(--primary); font-size:1.1rem;" title="Éditer">✏️</button>
                                    </form>
                                    
                                    <form method="post" class="inline-form" onsubmit="return confirmDeleteTerm(<?= $keyJsonAttr ?>, <?= $termJsonAttr ?>);">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="term" value="<?= htmlspecialchars($term) ?>">
                                        <button type="submit" class="action-icon" style="color:var(--danger); font-size:1.1rem;" title="Supprimer">✖</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function changeSort(sortValue) {
    const urlParams = new URLSearchParams(window.location.search);
    urlParams.set('sort', sortValue);
    window.location.search = urlParams.toString();
}

function promptEdit(form, currentTerm) {
    const newVal = prompt("Modifier le terme :", currentTerm);
    if (newVal !== null && newVal.trim() !== "" && newVal.trim() !== currentTerm) {
        form.querySelector('.new-term-input').value = newVal.trim();
        return true;
    }
    return false;
}

function confirmDeleteTerm(listKey, term) {
    if (listKey === 'metiers') {
        return confirm(`⚠️ ATTENTION : Supprimer le métier « ${term} » supprimera définitivement l'onglet correspondant, ainsi que toutes les fiches et le fichier de données associés dans la section Personnes.\n\nConfirmez-vous cette suppression ?`);
    }
    return confirm(`Voulez-vous vraiment supprimer le terme « ${term} » ?`);
}
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/layout.php';