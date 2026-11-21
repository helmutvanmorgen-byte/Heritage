<?php
session_start();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/MonumentConfig.php';

$dataFile     = __DIR__ . '/json/monuments.json';
$allMonuments = file_exists($dataFile) ? json_decode(file_get_contents($dataFile), true) : [];
if (!is_array($allMonuments)) {
    $allMonuments = [];
}

/**
 * Normalise une chaîne pour la recherche :
 * supprime les balises/entités HTML, les majuscules, les accents,
 * les tirets, apostrophes et signes de ponctuation.
 */
function normalizeSearchString(string $str): string {
    if ($str === '') {
        return '';
    }

    // Nettoyage HTML éventuel et passage en minuscules
    $str = html_entity_decode(strip_tags($str), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $str = mb_strtolower($str, 'UTF-8');

    // Ligatures fréquentes en français
    $str = str_replace(['œ', 'æ'], ['oe', 'ae'], $str);

    // Suppression des accents et signes diacritiques
    if (class_exists('Normalizer')) {
        $normalized = Normalizer::normalize($str, Normalizer::FORM_D);
        if ($normalized !== false) {
            $str = preg_replace('/\p{Mn}/u', '', $normalized);
        }
    } else {
        $accents = [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
            'ç' => 'c',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ñ' => 'n',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ý' => 'y', 'ÿ' => 'y'
        ];
        $str = strtr($str, $accents);
    }

    // Remplacement des tirets, apostrophes, ponctuations et espaces multiples par un espace simple
    $str = preg_replace('/[^a-z0-9]+/u', ' ', $str);

    return trim((string)$str);
}

/**
 * Vérifie récursivement si une valeur (chaîne ou tableau) contient le texte recherché
 * sans tenir compte des accents, tirets ou de la ponctuation.
 */
function fieldContains($value, string $needle): bool {
    $normNeedle = normalizeSearchString($needle);
    if ($normNeedle === '') {
        return true;
    }

    if (is_array($value)) {
        foreach ($value as $item) {
            if (fieldContains($item, $needle)) {
                return true;
            }
        }
        return false;
    }

    if (is_scalar($value)) {
        $normHaystack = normalizeSearchString((string)$value);
        return $normHaystack !== '' && strpos($normHaystack, $normNeedle) !== false;
    }

    return false;
}

/**
 * Recherche un auteur dans auteurs_structures (format monument_form.php)
 * sans tenir compte des accents ni des tirets, avec filtrage optionnel par métier.
 */
function matchAuteur(array $monument, string $query, string $metierFilter = ''): bool {
    $normQuery        = normalizeSearchString($query);
    $normMetierFilter = normalizeSearchString($metierFilter);

    if ($normQuery === '') {
        return true;
    }

    if (!empty($monument['auteurs_structures']) && is_array($monument['auteurs_structures'])) {
        foreach ($monument['auteurs_structures'] as $aut) {
            if (!is_array($aut)) continue;

            $normNom    = normalizeSearchString((string)($aut['nom'] ?? ''));
            $normMetier = normalizeSearchString((string)($aut['metier'] ?? ''));

            if ($normNom === '') continue;

            if (strpos($normNom, $normQuery) !== false) {
                if ($normMetierFilter === '' || strpos($normMetier, $normMetierFilter) !== false) {
                    return true;
                }
            }
        }
    }

    // Compatibilité avec d'éventuelles anciennes fiches
    if ($metierFilter !== '' && !empty($monument[$metierFilter])) {
        return fieldContains($monument[$metierFilter], $query);
    }

    return false;
}

// Listes déroulantes issues de MonumentConfig (avec repli sur les valeurs existantes)
$materiauxList  = method_exists('MonumentConfig', 'getMateriaux') ? MonumentConfig::getMateriaux() : [];
$techniquesList = method_exists('MonumentConfig', 'getTechniques') ? MonumentConfig::getTechniques() : [];

if (empty($techniquesList)) {
    foreach ($allMonuments as $m) {
        if (!empty($m['deleted_at'])) continue;
        if (!empty($m['techniques'])) {
            foreach ((array)$m['techniques'] as $tech) {
                if (is_string($tech) && trim($tech) !== '' && !in_array($tech, $techniquesList, true)) {
                    $techniquesList[] = $tech;
                }
            }
        }
    }
    sort($techniquesList);
}

// Récupération des critères de recherche soumis en GET
$searchTitre      = trim($_GET['titre'] ?? '');
$searchDate       = trim($_GET['date'] ?? '');
$searchPersonnage = trim($_GET['personnage'] ?? '');
$searchAuteur     = trim($_GET['auteur'] ?? '');
$searchSculpteur  = trim($_GET['sculpteur'] ?? '');
$searchArchitecte = trim($_GET['architecte'] ?? '');
$searchFondeur    = trim($_GET['fondeur'] ?? '');
$searchMateriau   = trim($_GET['materiaux'] ?? '');
$searchTechnique  = trim($_GET['techniques'] ?? '');
$searchPays       = trim($_GET['pays'] ?? '');
$searchVille      = trim($_GET['ville'] ?? '');

$hasSearched = isset($_GET['do_search']) || count(array_filter([
    $searchTitre, $searchDate, $searchPersonnage, $searchAuteur,
    $searchSculpteur, $searchArchitecte, $searchFondeur,
    $searchMateriau, $searchTechnique, $searchPays, $searchVille
], fn($v) => $v !== '')) > 0;

$results = [];

if ($hasSearched) {
    foreach ($allMonuments as $m) {
        if (!empty($m['deleted_at'])) {
            continue;
        }

        // 1. Titre, attribution du titre ou appellation d'usage
        if ($searchTitre !== '') {
            $matchTitre = fieldContains($m['titre'] ?? '', $searchTitre)
                || fieldContains($m['attribution_titre'] ?? '', $searchTitre)
                || fieldContains($m['appellation_usage'] ?? '', $searchTitre);
            if (!$matchTitre) continue;
        }

        // 2. Dates (création, commande, inauguration, destruction)
        if ($searchDate !== '') {
            $dateFound = false;
            foreach (['annee_creation', 'date_commande', 'inauguration', 'destruction'] as $df) {
                if (!empty($m[$df]) && fieldContains($m[$df], $searchDate)) {
                    $dateFound = true;
                    break;
                }
            }
            if (!$dateFound) continue;
        }

        // 3. Personnage représenté & source de la représentation
        if ($searchPersonnage !== '') {
            $matchPerso = fieldContains($m['personnage'] ?? '', $searchPersonnage)
                || fieldContains($m['source_representation'] ?? '', $searchPersonnage);
            if (!$matchPerso) continue;
        }

        // 4. Auteurs (auteurs_structures de monument_form.php)
        if ($searchAuteur !== '' && !matchAuteur($m, $searchAuteur, '')) {
            continue;
        }
        if ($searchSculpteur !== '' && !matchAuteur($m, $searchSculpteur, 'sculpteur')) {
            continue;
        }
        if ($searchArchitecte !== '' && !matchAuteur($m, $searchArchitecte, 'architecte')) {
            continue;
        }
        if ($searchFondeur !== '' && !matchAuteur($m, $searchFondeur, 'fondeur')) {
            continue;
        }

        // 5. Matériaux & Techniques
        if ($searchMateriau !== '' && !fieldContains($m['materiaux'] ?? '', $searchMateriau)) {
            continue;
        }
        if ($searchTechnique !== '' && !fieldContains($m['techniques'] ?? '', $searchTechnique)) {
            continue;
        }

        // 6. Localisation (Pays & Commune)
        if ($searchPays !== '' && !fieldContains($m['pays'] ?? '', $searchPays)) {
            continue;
        }
        if ($searchVille !== '') {
            $matchVille = fieldContains($m['communes_actuelles'] ?? '', $searchVille)
                || fieldContains($m['commune_ancienne'] ?? '', $searchVille)
                || fieldContains($m['commune_origine'] ?? '', $searchVille);
            if (!$matchVille) continue;
        }

        $results[] = $m;
    }
}

ob_start();
?>
<link rel="stylesheet" href="style.css">

<style>
.search-advanced-wrapper {
    display: flex;
    gap: 24px;
    align-items: flex-start;
}
.search-form-section {
    flex: 2.3;
}
.search-help-section {
    flex: 1;
    background: var(--bg-light);
    padding: 20px;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
    border-left: 4px solid var(--accent);
}
.search-fieldset {
    border: 1px solid #e2e8f0;
    padding: 18px 20px;
    margin: 0 0 18px 0;
    background: #ffffff;
    border-radius: 7px;
}
.search-fieldset legend {
    font-weight: 700;
    font-size: 0.95rem;
    color: var(--accent);
    background: #ffffff;
    padding: 4px 12px;
    border: 1px solid #cbd5e0;
    border-radius: 5px;
    cursor: pointer;
    user-select: none;
}
.search-fieldset legend:hover {
    background: var(--secondary);
}
.search-fieldset.collapsed > *:not(legend) {
    display: none;
}
.search-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 14px 18px;
    margin-top: 12px;
}
.search-field label {
    display: block;
    font-weight: 600;
    margin-bottom: 5px;
    font-size: 0.82rem;
    color: var(--text-dark);
}
.search-field input[type="text"],
.search-field select {
    width: 100%;
    height: 36px;
    padding: 0 10px;
    border: 1px solid var(--border-color);
    border-radius: 4px;
    font-size: 0.88rem;
    color: var(--text-dark);
    background-color: #ffffff;
}
.search-field input[type="text"]:focus,
.search-field select:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 2px rgba(40, 156, 142, 0.15);
}
.search-actions-bar {
    margin-top: 20px;
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    align-items: center;
}
.search-results-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 18px;
    margin-top: 18px;
}
.search-result-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.search-result-media {
    height: 170px;
    padding: 8px;
    background: var(--bg-light);
    border-bottom: 1px solid #edf2f7;
    display: flex;
    align-items: center;
    justify-content: center;
}
.search-result-media img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
    border-radius: 4px;
}
.search-result-body {
    padding: 14px;
    flex: 1;
    display: flex;
    flex-direction: column;
}
.search-result-meta {
    font-size: 0.8rem;
    color: var(--text-muted);
    margin-bottom: 4px;
}
@media (max-width: 900px) {
    .search-advanced-wrapper {
        flex-direction: column;
    }
    .search-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="content-3 container">
    <div style="margin-bottom: 20px; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px;">
        <h2 style="margin-bottom: 4px;">Recherche avancée dans l'inventaire</h2>
        <p style="margin: 0; color: var(--text-muted); font-size: 0.9rem;">
            Combinez un ou plusieurs critères correspondant aux rubriques des fiches monuments.
        </p>
    </div>

    <div class="search-advanced-wrapper">
        <!-- Formulaire avec Fieldsets Accordéons -->
        <div class="search-form-section">
            <form method="GET" action="search.php">
                <input type="hidden" name="do_search" value="1">

                <!-- Accordéon 1 : Identification & Description -->
                <fieldset class="search-fieldset">
                    <legend>📂 Identification &amp; Description ▾</legend>
                    <div class="search-grid">
                        <div class="search-field">
                            <label for="f_titre">Titre / Appellation</label>
                            <input type="text" id="f_titre" name="titre" value="<?= htmlspecialchars($searchTitre) ?>" placeholder="Ex. La Nymphe, Monument aux morts...">
                        </div>
                        <div class="search-field">
                            <label for="f_date">Année / Date (création, inauguration...)</label>
                            <input type="text" id="f_date" name="date" value="<?= htmlspecialchars($searchDate) ?>" placeholder="Ex. 1889, 1920...">
                        </div>
                        <div class="search-field">
                            <label for="f_materiau">Matériau</label>
                            <select id="f_materiau" name="materiaux">
                                <option value="">-- Tous les matériaux --</option>
                                <?php foreach ($materiauxList as $mat): ?>
                                    <option value="<?= htmlspecialchars($mat) ?>" <?= $searchMateriau === $mat ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($mat) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="search-field">
                            <label for="f_technique">Technique</label>
                            <select id="f_technique" name="techniques">
                                <option value="">-- Toutes les techniques --</option>
                                <?php foreach ($techniquesList as $tech): ?>
                                    <option value="<?= htmlspecialchars($tech) ?>" <?= $searchTechnique === $tech ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($tech) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </fieldset>

                <!-- Accordéon 2 : Exécution & Personnages -->
                <fieldset class="search-fieldset">
                    <legend>📂 Exécution (Auteurs) &amp; Personnages ▾</legend>
                    <div class="search-grid">
                        <div class="search-field">
                            <label for="f_personnage">Personnage / Sujet représenté</label>
                            <input type="text" id="f_personnage" name="personnage" value="<?= htmlspecialchars($searchPersonnage) ?>">
                        </div>
                        <div class="search-field">
                            <label for="f_auteur">Auteur (tous métiers confondus)</label>
                            <input type="text" id="f_auteur" name="auteur" value="<?= htmlspecialchars($searchAuteur) ?>" placeholder="Nom d'un intervenant...">
                        </div>
                        <div class="search-field">
                            <label for="f_sculpteur">Sculpteur</label>
                            <input type="text" id="f_sculpteur" name="sculpteur" value="<?= htmlspecialchars($searchSculpteur) ?>">
                        </div>
                        <div class="search-field">
                            <label for="f_architecte">Architecte</label>
                            <input type="text" id="f_architecte" name="architecte" value="<?= htmlspecialchars($searchArchitecte) ?>">
                        </div>
                        <div class="search-field">
                            <label for="f_fondeur">Fondeur</label>
                            <input type="text" id="f_fondeur" name="fondeur" value="<?= htmlspecialchars($searchFondeur) ?>">
                        </div>
                    </div>
                </fieldset>

                <!-- Accordéon 3 : Localisation -->
                <fieldset class="search-fieldset">
                    <legend>📂 Localisation géographique ▾</legend>
                    <div class="search-grid">
                        <div class="search-field">
                            <label for="f_pays">Pays</label>
                            <select id="f_pays" name="pays">
                                <option value="">-- Tous les pays --</option>
                                <option value="France" <?= $searchPays === 'France' ? 'selected' : '' ?>>France</option>
                                <option value="Algérie" <?= $searchPays === 'Algérie' ? 'selected' : '' ?>>Algérie</option>
                            </select>
                        </div>
                        <div class="search-field">
                            <label for="f_ville">Commune (actuelle, ancienne ou d'origine)</label>
                            <input type="text" id="f_ville" name="ville" value="<?= htmlspecialchars($searchVille) ?>" placeholder="Ex. Poitiers, Oran...">
                        </div>
                    </div>
                </fieldset>

                <div class="search-actions-bar">
                    <a href="search.php" class="btn btn-secondary">✖ Réinitialiser</a>
                    <button type="submit" class="btn btn-primary">🔍 Rechercher</button>
                </div>
            </form>
        </div>

        <!-- Panneau d'aide -->
        <aside class="search-help-section">
            <h3 style="color: var(--accent); margin-bottom: 10px;">Aide à la recherche</h3>
            <p style="font-size: 0.88rem; margin-bottom: 12px;">
                Cliquez sur l'en-tête d'un bloc pour ouvrir ou replier ses champs de recherche.
            </p>
            <h4 style="margin-bottom: 6px;">Critères croisés &amp; tolérance</h4>
            <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 12px;">
                Tous les champs renseignés se combinent. La recherche ignore automatiquement les majuscules, les accents, les tirets et la ponctuation.
            </p>
            <h4 style="margin-bottom: 6px;">Auteurs &amp; Dates</h4>
            <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0;">
                La recherche par date interroge simultanément l'année de création, la commande, l'inauguration et la destruction.
            </p>
        </aside>
    </div>

    <!-- Résultats de la recherche -->
    <?php if ($hasSearched): ?>
        <div style="margin-top: 32px; padding-top: 22px; border-top: 2px solid var(--accent);">
            <h3>
                Résultats de la recherche
                <span class="dashboard-count"><?= count($results) ?> monument<?= count($results) > 1 ? 's' : '' ?> trouvé<?= count($results) > 1 ? 's' : '' ?></span>
            </h3>

            <?php if (empty($results)): ?>
                <div class="empty-state" style="margin-top: 15px;">
                    <p>Aucun monument ne correspond à vos critères de recherche.</p>
                </div>
            <?php else: ?>
                <div class="search-results-grid">
                    <?php foreach ($results as $m):
                        // Titre principal
                        if (!empty($m['titre']) && is_array($m['titre'])) {
                            $titreAffiche = trim((string)($m['titre'][0] ?? '')) !== '' ? $m['titre'][0] : 'Sans titre';
                        } else {
                            $titreAffiche = trim((string)($m['titre'] ?? '')) !== '' ? (string)$m['titre'] : 'Sans titre';
                        }

                        // Image principale (compatibilité images & photos_payload de monument_form.php)
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

                        // Localisation résumée
                        $commune = is_array($m['communes_actuelles'] ?? null)
                            ? ($m['communes_actuelles'][0] ?? '')
                            : (string)($m['communes_actuelles'] ?? '');
                        $dept = !empty($m['departement_france'])
                            ? $m['departement_france']
                            : ($m['wilaya_algerie'] ?? ($m['pays'] ?? ''));
                        $locText = implode(' • ', array_filter([$commune, $dept]));

                        // Auteurs résumés
                        $auteursText = [];
                        if (!empty($m['auteurs_structures']) && is_array($m['auteurs_structures'])) {
                            foreach ($m['auteurs_structures'] as $aut) {
                                if (!empty($aut['nom'])) {
                                    $auteursText[] = $aut['nom'];
                                }
                            }
                        }
                    ?>
                        <article class="search-result-card">
                            <div class="search-result-media">
                                <img src="<?= !empty($primaryImage) ? '/uploads/' . htmlspecialchars($primaryImage) : '/img/no-photo.webp' ?>"
                                     alt="<?= htmlspecialchars($titreAffiche) ?>">
                            </div>
                            <div class="search-result-body">
                                <div style="display: flex; justify-content: space-between; align-items: center; gap: 6px; margin-bottom: 6px;">
                                    <span class="item-ref"><?= htmlspecialchars($m['numero_fiche'] ?? '') ?></span>
                                    <?php if (!empty($m['type_oeuvre'])): ?>
                                        <span class="item-type">
                                            <?= htmlspecialchars(is_array($m['type_oeuvre']) ? implode(', ', $m['type_oeuvre']) : $m['type_oeuvre']) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <h4 class="item-title" style="margin: 4px 0 8px 0;">
                                    <a href="viewer_oeuvre.php?id=<?= (int)$m['id'] ?>"><?= htmlspecialchars($titreAffiche) ?></a>
                                </h4>

                                <?php if ($locText !== ''): ?>
                                    <div class="search-result-meta">📍 <?= htmlspecialchars($locText) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($auteursText)): ?>
                                    <div class="search-result-meta">🔨 <?= htmlspecialchars(implode(', ', array_unique($auteursText))) ?></div>
                                <?php endif; ?>

                                <div style="margin-top: auto; padding-top: 12px;">
                                    <a href="viewer_oeuvre.php?id=<?= (int)$m['id'] ?>" class="btn btn-primary" style="width: 100%;">👁️ Voir la fiche</a>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.search-fieldset legend').forEach(legend => {
        legend.addEventListener('click', () => {
            legend.parentElement.classList.toggle('collapsed');
        });
    });
});
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/includes/layout.php';
?>