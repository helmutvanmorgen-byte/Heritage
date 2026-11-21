<?php
require_once __DIR__ . '/personne_config.php';
require_once __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['user_id'], $_SESSION['user_role'])) {
    header('Location: /login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$role   = $_SESSION['user_role'];
$id     = isset($_GET['id']) ? (int) $_GET['id'] : null;

$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError   = $_SESSION['flash_error'] ?? null;
$oldInput     = $_SESSION['old_input'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error'], $_SESSION['old_input']);

[$type, $cfg] = resolvePersonneType($_GET['type'] ?? null, $typesConfig);

if (isset($_GET['audit'])) {
    $_SESSION['current_audit'] = $_GET['audit'];
}
$activeAudit = $_SESSION['current_audit'] ?? '';

$dataFile = __DIR__ . '/../json/' . $cfg['json'];
$allItems = file_exists($dataFile) ? json_decode(file_get_contents($dataFile), true) : [];
if (!is_array($allItems)) {
    $allItems = [];
}

// Navigation séquentielle (Précédent / Suivant)
$prevId = null;
$nextId = null;
if (isset($_SESSION['audit_list']) && is_array($_SESSION['audit_list']) && $id) {
    $currentIndex = array_search($id, $_SESSION['audit_list']);
    if ($currentIndex !== false) {
        if ($currentIndex > 0) {
            $prevId = $_SESSION['audit_list'][$currentIndex - 1];
        }
        if ($currentIndex < count($_SESSION['audit_list']) - 1) {
            $nextId = $_SESSION['audit_list'][$currentIndex + 1];
        }
    }
}

$personne = [
    'id'                       => '',
    'nom'                      => '',
    'prenom'                   => '',
    'sexe'                     => '',
    'naissance'                => ['lieu' => '', 'pays' => '', 'annee' => ''],
    'deces'                    => ['lieu' => '', 'pays' => '', 'annee' => ''],
    'biographie'               => '',
    'permalien'                => '',
    'numero_fiche'             => $cfg['prefix'] . '-' . date('Y') . '-' . strtoupper(substr(uniqid(), -5)),
    'date_creation_notice'     => date('d/m/Y à H:i:s'),
    'date_modification_notice' => date('d/m/Y à H:i:s'),
    'user_id'                  => $userId
];

if ($id) {
    $data = null;
    foreach ($allItems as $item) {
        if ((int)($item['id'] ?? 0) === $id && empty($item['deleted_at'])) {
            $data = $item;
            break;
        }
    }
    if (!$data) {
        die(ucfirst($cfg['label']) . ' introuvable ou supprimé.');
    }

    foreach ($personne as $k => $v) {
        if ($k === 'naissance' || $k === 'deces') {
            if (isset($data[$k]) && is_array($data[$k])) {
                $personne[$k] = array_merge($personne[$k], $data[$k]);
            }
        } elseif (isset($data[$k])) {
            $personne[$k] = $data[$k];
        }
    }

    // Récupération des champs hérités (propositions validées) pour ne pas masquer les données initiales
    if ($personne['naissance']['lieu'] === '' && !empty($data['lieu'])) {
        $personne['naissance']['lieu'] = (string)$data['lieu'];
    }
    if (!empty($data['dates']) && mb_strpos($personne['biographie'], (string)$data['dates']) === false) {
        $personne['biographie'] = trim("Dates renseignées : " . $data['dates'] . "\n" . $personne['biographie']);
    }

    $personne['date_modification_notice'] = date('d/m/Y à H:i:s');
}

// Restauration des données saisies si une erreur vient de se produire
if (is_array($oldInput) && ($oldInput['type'] ?? '') === $type && (int)($oldInput['id'] ?? 0) === (int)$id) {
    foreach (['nom', 'prenom', 'sexe', 'biographie', 'permalien'] as $field) {
        if (isset($oldInput[$field])) {
            $personne[$field] = $oldInput[$field];
        }
    }
    if (isset($oldInput['naissance']) && is_array($oldInput['naissance'])) {
        $personne['naissance'] = array_merge($personne['naissance'], $oldInput['naissance']);
    }
    if (isset($oldInput['deces']) && is_array($oldInput['deces'])) {
        $personne['deces'] = array_merge($personne['deces'], $oldInput['deces']);
    }
}

$backUrl = $_SESSION['last_dashboard_url_by_type'][$type] ?? ('dashboard.php?type=' . urlencode($type));

ob_start();
?>
<div class="content-2 dashboard-wrapper">

    <?php if (!$id): ?>
        <nav class="tabs-header tabs-nav" aria-label="Catégories de personnes">
            <?php foreach ($typesConfig as $key => $tabCfg): ?>
                <a href="personne_form.php?type=<?= urlencode($key) ?>" class="tab-link <?= $type === $key ? 'active' : '' ?>">
                    <?= htmlspecialchars($tabCfg['onglet']) ?>
                </a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <div class="dashboard-header-top">
        <h2><?= !empty($personne['id']) ? 'Modifier une fiche ' . htmlspecialchars($cfg['label']) : 'Nouvelle fiche ' . htmlspecialchars($cfg['label']) ?></h2>

        <div class="dashboard-actions-left">
            <?php if ($prevId): ?>
                <a href="personne_form.php?type=<?= urlencode($type) ?>&amp;id=<?= (int)$prevId ?>" class="btn btn-secondary btn-xs">Précédent</a>
            <?php endif; ?>

            <a href="<?= htmlspecialchars($backUrl) ?>" class="btn btn-secondary btn-xs">Retour à la liste</a>

            <?php if ($nextId): ?>
                <a href="personne_form.php?type=<?= urlencode($type) ?>&amp;id=<?= (int)$nextId ?>" class="btn btn-secondary btn-xs">Suivant</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($flashSuccess): ?>
        <div class="alert alert-success"><?= htmlspecialchars($flashSuccess) ?></div>
    <?php endif; ?>
    <?php if ($flashError): ?>
        <div class="alert alert-error"><?= htmlspecialchars($flashError) ?></div>
    <?php endif; ?>

    <div id="draft-banner" class="alert alert-error" style="display:none; justify-content:space-between; align-items:center;">
        <span>Des modifications non enregistrées ont été retrouvées pour cette fiche.</span>
        <div>
            <button type="button" id="restore-draft-btn" class="btn btn-primary btn-xs">Restaurer mes données</button>
            <button type="button" id="discard-draft-btn" class="btn btn-secondary btn-xs">Ignorer</button>
        </div>
    </div>

    <form method="post" action="save_personne.php" id="personne-form">
        <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
        <input type="hidden" name="id" value="<?= htmlspecialchars((string)$personne['id']) ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="audit" value="<?= htmlspecialchars($activeAudit) ?>">

        <div class="form-grid-2col">
            <div class="form-col">
                <fieldset>
                    <legend>Identité</legend>
                    <div class="form-row">
                        <label for="nom">Nom *</label>
                        <input type="text" id="nom" name="nom" value="<?= htmlspecialchars($personne['nom'] ?? '') ?>" required>
                    </div>
                    <div class="form-row">
                        <label for="prenom">Prénom</label>
                        <input type="text" id="prenom" name="prenom" value="<?= htmlspecialchars($personne['prenom'] ?? '') ?>">
                    </div>
                    <div class="form-row">
                        <label for="sexe">Sexe</label>
                        <select id="sexe" name="sexe">
                            <option value="" <?= ($personne['sexe'] ?? '') === '' ? 'selected' : '' ?>>-- Non spécifié --</option>
                            <option value="Homme" <?= ($personne['sexe'] ?? '') === 'Homme' ? 'selected' : '' ?>>Homme</option>
                            <option value="Femme" <?= ($personne['sexe'] ?? '') === 'Femme' ? 'selected' : '' ?>>Femme</option>
                        </select>
                    </div>
                </fieldset>

                <fieldset>
                    <legend>Naissance</legend>
                    <div class="form-inline-group">
                        <div>
                            <label for="naiss_annee">Année</label>
                            <input type="text" id="naiss_annee" name="naissance[annee]" value="<?= htmlspecialchars($personne['naissance']['annee'] ?? '') ?>">
                        </div>
                        <div>
                            <label for="naiss_lieu">Lieu</label>
                            <input type="text" id="naiss_lieu" name="naissance[lieu]" value="<?= htmlspecialchars($personne['naissance']['lieu'] ?? '') ?>">
                        </div>
                        <div>
                            <label for="naiss_pays">Pays</label>
                            <input type="text" id="naiss_pays" name="naissance[pays]" value="<?= htmlspecialchars($personne['naissance']['pays'] ?? '') ?>">
                        </div>
                    </div>
                </fieldset>

                <fieldset>
                    <legend>Décès</legend>
                    <div class="form-inline-group">
                        <div>
                            <label for="deces_annee">Année</label>
                            <input type="text" id="deces_annee" name="deces[annee]" value="<?= htmlspecialchars($personne['deces']['annee'] ?? '') ?>">
                        </div>
                        <div>
                            <label for="deces_lieu">Lieu</label>
                            <input type="text" id="deces_lieu" name="deces[lieu]" value="<?= htmlspecialchars($personne['deces']['lieu'] ?? '') ?>">
                        </div>
                        <div>
                            <label for="deces_pays">Pays</label>
                            <input type="text" id="deces_pays" name="deces[pays]" value="<?= htmlspecialchars($personne['deces']['pays'] ?? '') ?>">
                        </div>
                    </div>
                </fieldset>
            </div>

            <div class="form-col">
                <fieldset>
                    <legend>Ressources &amp; Notes</legend>
                    <div class="form-row">
                        <label for="permalien">Permalien</label>
                        <input type="url" id="permalien" name="permalien" value="<?= htmlspecialchars($personne['permalien'] ?? '') ?>" placeholder="https://...">
                    </div>
                    <div class="form-row form-row-full">
                        <label for="biographie">Biographie / Notes</label>
                        <textarea id="biographie" name="biographie" rows="6"><?= htmlspecialchars($personne['biographie'] ?? '') ?></textarea>
                    </div>
                </fieldset>

                <fieldset>
                    <legend>Traçabilité</legend>
                    <div class="form-row">
                        <label for="numero_fiche">Référence</label>
                        <input type="text" id="numero_fiche" value="<?= htmlspecialchars($personne['numero_fiche'] ?? '') ?>" readonly>
                    </div>
                    <div class="form-row">
                        <label for="date_creation">Date de création</label>
                        <input type="text" id="date_creation" value="<?= htmlspecialchars($personne['date_creation_notice'] ?? '') ?>" readonly>
                    </div>
                </fieldset>
            </div>
        </div>

        <div class="form-actions-center">
            <a href="<?= htmlspecialchars($backUrl) ?>" class="btn btn-secondary">Annuler</a>
            <button type="submit" name="save_action" value="stay" class="btn btn-secondary">Enregistrer</button>
            <button type="submit" name="save_action" value="back" class="btn btn-primary">Enregistrer et revenir à la liste</button>
        </div>
    </form>
</div>

<script>
(function () {
    const form = document.getElementById('personne-form');
    if (!form) return;

    const draftKey = 'personne_draft_' + <?= json_encode($type . '_' . ($personne['id'] ?: 'new')) ?>;
    const banner = document.getElementById('draft-banner');
    const restoreBtn = document.getElementById('restore-draft-btn');
    const discardBtn = document.getElementById('discard-draft-btn');
    const wasJustSaved = <?= isset($_GET['saved']) ? 'true' : 'false' ?>;

    if (wasJustSaved) {
        sessionStorage.removeItem(draftKey);
    }

    const trackedFields = Array.from(form.querySelectorAll('input[name], select[name], textarea[name]'))
        .filter(el => el.type !== 'hidden');

    const getSnapshot = () => {
        const data = {};
        trackedFields.forEach(el => { data[el.name] = el.value; });
        return data;
    };

    const initialSnapshot = JSON.stringify(getSnapshot());
    let isDirty = false;
    let isSubmitting = false;

    // Proposer la restauration si un brouillon existe (ex. après un retour navigateur)
    const savedDraft = sessionStorage.getItem(draftKey);
    if (savedDraft && savedDraft !== initialSnapshot) {
        banner.style.display = 'flex';
        restoreBtn.addEventListener('click', () => {
            try {
                const parsed = JSON.parse(savedDraft);
                trackedFields.forEach(el => {
                    if (Object.prototype.hasOwnProperty.call(parsed, el.name)) {
                        el.value = parsed[el.name];
                    }
                });
                isDirty = true;
            } catch (e) {}
            banner.style.display = 'none';
        });
        discardBtn.addEventListener('click', () => {
            sessionStorage.removeItem(draftKey);
            banner.style.display = 'none';
        });
    }

    const onFieldChange = () => {
        const current = JSON.stringify(getSnapshot());
        isDirty = (current !== initialSnapshot);
        if (isDirty) {
            sessionStorage.setItem(draftKey, current);
        } else {
            sessionStorage.removeItem(draftKey);
        }
    };

    trackedFields.forEach(el => {
        el.addEventListener('input', onFieldChange);
        el.addEventListener('change', onFieldChange);
    });

    form.addEventListener('submit', () => {
        isSubmitting = true;
        sessionStorage.removeItem(draftKey);
    });

    window.addEventListener('beforeunload', (e) => {
        if (isDirty && !isSubmitting) {
            e.preventDefault();
            e.returnValue = '';
        }
    });
})();
</script>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/layout.php';