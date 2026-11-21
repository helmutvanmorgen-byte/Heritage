<?php
session_start();

$dataFile = __DIR__ . '/json/monuments.json';
$monuments = file_exists($dataFile) ? json_decode(file_get_contents($dataFile), true) : [];
if (!is_array($monuments)) {
    $monuments = [];
}

// Filtrage des fiches actives (non supprimées)[cite: 4]
$activeMonuments = array_values(array_filter($monuments, fn($m) => empty($m['deleted_at'])));
$totalMonuments = count($activeMonuments);

// Extraction propre d'un champ texte (chaîne, liste ou tableau associatif)
$extractText = static function ($value, string $default = ''): string {
    if (is_array($value)) {
        if (isset($value['nom']) || isset($value['prenom'])) {
            return trim(($value['prenom'] ?? '') . ' ' . ($value['nom'] ?? ''));
        }
        $first = reset($value);
        if (is_array($first)) {
            return trim(($first['prenom'] ?? '') . ' ' . ($first['nom'] ?? ($first['label'] ?? $default)));
        }
        return trim((string)($first !== false ? $first : $default));
    }
    return trim((string)($value ?? $default));
};

// Récupération de l'image principale d'une notice[cite: 4]
$getPrimaryImage = static function (array $m): string {
    if (!empty($m['images']) && is_array($m['images'])) {
        foreach ($m['images'] as $img) {
            if (!empty($img['primary']) && !empty($img['file'])) {
                return '/uploads/' . $img['file'];
            }
        }
        if (!empty($m['images'][0]['file'])) {
            return '/uploads/' . $m['images'][0]['file'];
        }
    }
    return '';
};

// Préparation des données cartographiques, wilayas et typologies
$mapMonuments = [];
$wilayasMap = [];
$categoriesMap = [];

foreach ($activeMonuments as $m) {
    $titre = $extractText($m['titre'] ?? '', 'Sans titre');
    $commune = $extractText($m['commune'] ?? ($m['ville'] ?? ''));
    $wilaya = $extractText($m['wilaya'] ?? ($m['departement'] ?? ''));
    $categorie = $extractText($m['type_monument'] ?? ($m['categorie'] ?? ($m['domaine'] ?? '')));
    $auteur = $extractText($m['sculpteur'] ?? ($m['architecte'] ?? ($m['auteur'] ?? '')));
    $ref = $extractText($m['numero_fiche'] ?? '');

    if ($wilaya !== '') {
        $wilayasMap[$wilaya] = ($wilayasMap[$wilaya] ?? 0) + 1;
    }
    if ($categorie !== '') {
        $categoriesMap[$categorie] = ($categoriesMap[$categorie] ?? 0) + 1;
    }

    if (!empty($m['latitude']) && !empty($m['longitude'])) {
        $localisation = trim($commune . ($wilaya !== '' ? ' (' . $wilaya . ')' : ''));
        $mapMonuments[] = [
            'id' => $m['id'],
            'titre' => $titre,
            'commune' => $commune,
            'wilaya' => $wilaya,
            'categorie' => $categorie,
            'auteur' => $auteur,
            'localisation' => $localisation,
            'lat' => (float)$m['latitude'],
            'lng' => (float)$m['longitude'],
            'numero_fiche' => $ref
        ];
    }
}

ksort($wilayasMap, SORT_LOCALE_STRING);
arsort($categoriesMap);

// Dernières notices publiées (jusqu'à 6 fiches)
$latestMonuments = array_slice(array_reverse($activeMonuments), 0, 6);

ob_start();
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<div class="home-page">

    <!-- =========================================================
         1. BANDEAU ÉDITORIAL COMPACT
         ========================================================= -->
    <section class="atlas-intro" aria-label="Présentation de l'application">
        <div class="atlas-intro-main">
            <span class="atlas-kicker">Répertoire de la statuaire publique d'Afrique du Nord</span>
            <p class="atlas-subtitle">
                Inventaire et cartographique des monuments recensés en Afrique du Nord.
            </p>
        </div>

        <div class="atlas-intro-stat" aria-label="Nombre de monuments recensés">
            <span class="atlas-stat-number"><?= number_format($totalMonuments, 0, ',', ' ') ?></span>
            <span class="atlas-stat-label"><?= $totalMonuments > 1 ? 'monuments recensés' : 'monument recensé' ?></span>
        </div>
    </section>

    <!-- =========================================================
         2. ESPACE PRINCIPAL : CARTE À GAUCHE + REGISTRE À DROITE
         ========================================================= -->
    <div class="atlas-workspace">

        <!-- Colonne gauche : Carte interactive -->
        <section class="atlas-map-card" id="carte-monuments" aria-label="Carte des monuments">
            <div class="atlas-panel-header">
                <div class="atlas-panel-heading">
                    <h2>Cartographie du territoire</h2>
                    <span class="atlas-panel-hint">Cliquez sur un repère pour ouvrir la notice</span>
                </div>
                <button type="button" id="map-reset-btn" class="btn btn-secondary btn-xs">Recentrer</button>
            </div>

            <?php if (!empty($categoriesMap) || !empty($wilayasMap)): ?>
                <div class="atlas-filter-bar" aria-label="Filtres rapides de la carte">
                    <?php foreach (array_slice($categoriesMap, 0, 5, true) as $catName => $catCount): ?>
                        <button type="button"
                                class="atlas-chip js-map-preset"
                                data-filter-query="<?= htmlspecialchars($catName, ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($catName, ENT_QUOTES, 'UTF-8') ?>
                            <span>(<?= $catCount ?>)</span>
                        </button>
                    <?php endforeach; ?>
                    <?php foreach (array_slice($wilayasMap, 0, 5, true) as $wName => $wCount): ?>
                        <button type="button"
                                class="atlas-chip atlas-chip--wilaya js-map-preset"
                                data-filter-wilaya="<?= htmlspecialchars($wName, ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($wName, ENT_QUOTES, 'UTF-8') ?>
                            <span>(<?= $wCount ?>)</span>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div id="global-map" class="atlas-map-canvas"></div>
        </section>

        <!-- Colonne droite : Dernières notices publiées -->
        <section class="atlas-register-card" id="derniers-monuments" aria-label="Dernières notices publiées">
            <div class="atlas-panel-header">
                <div class="atlas-panel-heading">
                    <h2>Dernières notices publiées</h2>
                    <span class="atlas-panel-hint">Ajouts récents à l'inventaire</span>
                </div>
            </div>

            <?php if (empty($latestMonuments)): ?>
                <div class="empty-state">
                    <p>Aucun monument répertorié pour le moment[cite: 4].</p>
                </div>
            <?php else: ?>
                <div class="atlas-register-list">
                    <?php foreach ($latestMonuments as $m):
                        $titre = $extractText($m['titre'] ?? '', 'Sans titre');
                        $img = $getPrimaryImage($m);
                        $imgUrl = $img !== '' ? $img : '/img/no-photo.webp';
                        $commune = $extractText($m['commune'] ?? ($m['ville'] ?? ''));
                        $wilaya = $extractText($m['wilaya'] ?? ($m['departement'] ?? ''));
                        $type = $extractText($m['type_monument'] ?? ($m['categorie'] ?? ($m['domaine'] ?? '')));
                        $auteur = $extractText($m['sculpteur'] ?? ($m['architecte'] ?? ($m['auteur'] ?? '')));
                        $ref = $extractText($m['numero_fiche'] ?? 'N/A');
                        $locDisplay = trim($commune . ($wilaya !== '' ? ' (' . $wilaya . ')' : ''));
                    ?>
                        <a href="/inventaire_session/viewer_oeuvre.php?id=<?= urlencode((string)$m['id']) ?>" class="atlas-record-item">
                            <div class="atlas-record-thumb">
                                <img src="<?= htmlspecialchars($imgUrl, ENT_QUOTES, 'UTF-8') ?>"
                                     alt="<?= htmlspecialchars($titre, ENT_QUOTES, 'UTF-8') ?>"
                                     loading="lazy">
                            </div>

                            <div class="atlas-record-content">
                                <div class="atlas-record-meta">
                                    <span class="item-ref"><?= htmlspecialchars($ref, ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php if ($type !== ''): ?>
                                        <span class="atlas-record-tag"><?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endif; ?>
                                </div>

                                <h3 class="atlas-record-title"><?= htmlspecialchars($titre, ENT_QUOTES, 'UTF-8') ?></h3>

                                <div class="atlas-record-details">
                                    <span><?= htmlspecialchars($locDisplay !== '' ? $locDisplay : 'Algérie', ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php if ($auteur !== ''): ?>
                                        <span>&bull; <?= htmlspecialchars($auteur, ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <span class="atlas-record-arrow" aria-hidden="true">&rarr;</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const defaultCenter = [28.0339, 1.6596];
    const defaultZoom = 5;

    const map = L.map('global-map', {
        scrollWheelZoom: false
    }).setView(defaultCenter, defaultZoom);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 18,
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    const pinIcon = L.divIcon({
        className: 'custom-pin',
        html: '<div class="custom-pin-inner"></div>',
        iconSize: [22, 22],
        iconAnchor: [11, 22],
        popupAnchor: [0, -22]
    });

    const monuments = <?= json_encode($mapMonuments, JSON_UNESCAPED_UNICODE) ?>;
    const markersLayer = L.layerGroup().addTo(map);
    const resetBtn = document.getElementById('map-reset-btn');
    const presetButtons = document.querySelectorAll('.js-map-preset');

    let activeQuery = '';
    let activeWilaya = '';

    function escapeHtml(text) {
        const mapChars = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return String(text || '').replace(/[&<>"']/g, m => mapChars[m]);
    }

    function renderMarkers(fitToBounds = false) {
        markersLayer.clearLayers();
        const q = activeQuery.trim().toLowerCase();
        const w = activeWilaya.trim().toLowerCase();
        const bounds = [];

        monuments.forEach(function (m) {
            if (!m.lat || !m.lng) return;
            if (w && String(m.wilaya || '').toLowerCase() !== w) return;

            const haystack = `${m.titre} ${m.localisation} ${m.categorie} ${m.auteur} ${m.numero_fiche}`.toLowerCase();
            if (q && !haystack.includes(q)) return;

            bounds.push([m.lat, m.lng]);

            const marker = L.marker([m.lat, m.lng], { icon: pinIcon });
            const locHtml = m.localisation ? `<div class="map-popup-loc">${escapeHtml(m.localisation)}</div>` : '';
            const popupContent =
                `<div class="map-popup">` +
                `<strong class="map-popup-title">${escapeHtml(m.titre)}</strong>` +
                locHtml +
                `<div class="map-popup-ref"><small>Réf : ${escapeHtml(m.numero_fiche || 'N/A')}</small></div>` +
                `<a href="/inventaire_session/viewer_oeuvre.php?id=${encodeURIComponent(m.id)}" class="btn btn-primary btn-xs map-popup-btn">Voir la fiche</a>` +
                `</div>`;

            marker.bindPopup(popupContent);
            markersLayer.addLayer(marker);
        });

        if (fitToBounds && bounds.length > 0) {
            if (bounds.length === 1) {
                map.setView(bounds[0], 11);
            } else {
                map.fitBounds(bounds, { padding: [45, 45], maxZoom: 12 });
            }
        } else if (!q && !w) {
            map.setView(defaultCenter, defaultZoom);
        }
    }

    renderMarkers(false);

    presetButtons.forEach(btn => {
        btn.addEventListener('click', function () {
            const wasActive = this.classList.contains('active');
            presetButtons.forEach(b => b.classList.remove('active'));

            if (wasActive) {
                activeQuery = '';
                activeWilaya = '';
                renderMarkers(false);
            } else {
                this.classList.add('active');
                activeQuery = this.getAttribute('data-filter-query') || '';
                activeWilaya = this.getAttribute('data-filter-wilaya') || '';
                renderMarkers(true);
            }
        });
    });

    if (resetBtn) {
        resetBtn.addEventListener('click', function () {
            presetButtons.forEach(b => b.classList.remove('active'));
            activeQuery = '';
            activeWilaya = '';
            renderMarkers(false);
        });
    }
});
</script>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/includes/layout.php';
?>