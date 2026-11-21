// =========================================================
// VARIABLES GLOBALES & ÉTAT DE SESSION
// =========================================================
const config = window.MONUMENT_CONFIG || {};
const metiersDataJS = config.metiersData || {};
const wilayasMapJS = config.wilayasMap || {};
const allMonumentsSearchJS = config.allMonumentsSearch || [];

let sessionUploadedFiles = [];
let isFormSubmitting = false;
let hasUnsavedChanges = false;
let allowNavigation = false;

let auteursData = [];
let documentationData = [];
let inscriptionsData = [];
let photosPayloadData = { actuelles: [], maquettes: [], anciennes: [] };

let docCropper = null;
let inscCropper = null;

function markFormDirty() {
    hasUnsavedChanges = true;
}

function escapeHtml(str) {
    return String(str || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// =========================================================
// INITIALISATION PRINCIPALE AU CHARGEMENT DU DOM
// =========================================================
document.addEventListener('DOMContentLoaded', function () {
    try { auteursData = JSON.parse(document.getElementById('auteurs_data').value) || []; } catch (e) { auteursData = []; }
    try { documentationData = JSON.parse(document.getElementById('documentation_data').value) || []; } catch (e) { documentationData = []; }
    try { inscriptionsData = JSON.parse(document.getElementById('inscriptions_data').value) || []; } catch (e) { inscriptionsData = []; }
    try {
        const pData = JSON.parse(document.getElementById('photos_payload_data').value);
        if (pData && typeof pData === 'object') {
            photosPayloadData.actuelles = Array.isArray(pData.actuelles) ? pData.actuelles : [];
            photosPayloadData.maquettes = Array.isArray(pData.maquettes) ? pData.maquettes : [];
            photosPayloadData.anciennes = Array.isArray(pData.anciennes) ? pData.anciennes : [];

            // Migration automatique d'éventuelles anciennes photos d'inscriptions vers l'onglet Inscription
            if (Array.isArray(pData.inscriptions) && pData.inscriptions.length > 0) {
                pData.inscriptions.forEach(oldItem => {
                    const file = oldItem.file || oldItem.filename || '';
                    const alreadyMigrated = file && inscriptionsData.some(ins => ins.photo === file);
                    if (file && !alreadyMigrated) {
                        const textParts = [oldItem.titre, oldItem.description]
                            .filter(v => v && v !== '-' && v !== 'Sans titre');
                        inscriptionsData.push({
                            type: 'Inscription',
                            contenu: textParts.join(' — '),
                            photo: file
                        });
                    }
                });
            }
        }
    } catch (e) {}

    const mentionsInput = document.getElementById('mentions_legales');
    if (mentionsInput && mentionsInput.value.trim() === '') {
        const headerTitleEl = document.querySelector('.header-title');
        const siteTitle = headerTitleEl ? headerTitleEl.textContent.trim() : (config.siteName || 'Patrimoine Monumental');
        const currentYear = new Date().getFullYear();
        mentionsInput.value = `${siteTitle} - ${currentYear}`;
    }

    initTabs();
    initDynamicFields();
    initComplexDates();
    initRichTextToolbars();
    initLocalisation();
    initMap();
    initDocumentationCropper();
    initInscriptionCropper();
    initUnsavedChangesGuard();

    renderAuteursTable();
    renderDocumentationTable();
    renderInscriptionsTable();
    ['actuelles', 'maquettes', 'anciennes'].forEach(sec => renderPhotoTable(sec));

    initLinkedMonumentsSearch();
});

// =========================================================
// PROTECTION CONTRE LA PERTE DES DONNÉES NON ENREGISTRÉES
// =========================================================
function initUnsavedChangesGuard() {
    const form = document.getElementById('monument-form');

    if (form) {
        form.addEventListener('input', function (e) {
            if (e.isTrusted) markFormDirty();
        });
        form.addEventListener('change', function (e) {
            if (e.isTrusted) markFormDirty();
        });
    }

    let pendingTargetUrl = null;
    let leaveModal = document.getElementById('unsaved-leave-modal-overlay');

    if (!leaveModal) {
        leaveModal = document.createElement('div');
        leaveModal.id = 'unsaved-leave-modal-overlay';
        leaveModal.className = 'standard-modal-overlay';
        leaveModal.style.zIndex = '10000';
        leaveModal.innerHTML = `
            <div class="standard-modal" style="max-width: 480px;">
                <h3 style="color: #c53030; display: flex; align-items: center; gap: 8px;">
                    ⚠️ Modifications non enregistrées
                </h3>
                <button type="button" class="standard-modal-close" id="btn-close-leave-modal">✖</button>
                <p style="color: #2d3748; line-height: 1.5; margin: 10px 0 18px 0; font-size: 0.9rem;">
                    Vous n'avez pas enregistré cette fiche.<br>
                    <strong>Si vous quittez cette page maintenant, toutes les informations saisies ou modifiées seront définitivement perdues.</strong>
                </p>
                <div style="display: flex; justify-content: flex-end; gap: 10px; padding-top: 12px; border-top: 1px solid #e2e8f0;">
                    <button type="button" class="btn-primary" id="btn-stay-on-form" style="padding: 7px 16px; background:#2b6cb0; color:#fff; border:none; border-radius:4px; cursor:pointer;">
                        Rester sur la fiche
                    </button>
                    <button type="button" class="btn-secondary" id="btn-confirm-leave-form" style="padding: 7px 16px; background: #c53030; color: #ffffff; border: none; border-radius:4px; cursor:pointer;">
                        Quitter sans enregistrer
                    </button>
                </div>
            </div>
        `;
        document.body.appendChild(leaveModal);
    }

    const closeLeaveModal = () => {
        leaveModal.style.display = 'none';
        pendingTargetUrl = null;
    };

    document.getElementById('btn-close-leave-modal').addEventListener('click', closeLeaveModal);
    document.getElementById('btn-stay-on-form').addEventListener('click', closeLeaveModal);

    document.getElementById('btn-confirm-leave-form').addEventListener('click', function () {
        if (!pendingTargetUrl) return;
        allowNavigation = true;
        window.location.href = pendingTargetUrl;
    });

    document.addEventListener('click', function (e) {
        if (isFormSubmitting || allowNavigation || !hasUnsavedChanges) return;
        if (e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) return;

        const link = e.target.closest('a[href]');
        if (!link) return;

        const rawHref = (link.getAttribute('href') || '').trim();
        if (
            !rawHref ||
            rawHref.startsWith('#') ||
            rawHref.startsWith('javascript:') ||
            rawHref.startsWith('mailto:') ||
            rawHref.startsWith('tel:') ||
            link.target === '_blank' ||
            link.hasAttribute('download')
        ) {
            return;
        }

        e.preventDefault();
        e.stopPropagation();
        pendingTargetUrl = link.href;
        leaveModal.style.display = 'flex';
    }, true);

    window.addEventListener('beforeunload', function (e) {
        if (!isFormSubmitting && !allowNavigation && hasUnsavedChanges) {
            e.preventDefault();
            e.returnValue = '';
        }
    });
}

// =========================================================
// 1. GESTION DES ONGLETS & BARRES DE TEXTE ENRICHI
// =========================================================
function initTabs() {
    const tabs = document.querySelectorAll('.tab-link');
    const contents = document.querySelectorAll('.tab-content');

    contents.forEach((c, index) => {
        if (index !== 0) c.style.display = 'none';
    });

    tabs.forEach(tab => {
        tab.addEventListener('click', function (e) {
            e.preventDefault();
            const targetId = this.getAttribute('data-tab');
            const target = document.getElementById(targetId);
            if (!target) return;

            tabs.forEach(t => t.classList.remove('active'));
            contents.forEach(c => {
                c.classList.remove('active');
                c.style.display = 'none';
            });

            this.classList.add('active');
            target.classList.add('active');
            target.style.display = 'block';

            if (targetId === 'tab-localisation' && typeof window.monumentMap !== 'undefined') {
                setTimeout(() => { window.monumentMap.invalidateSize(); }, 200);
            }
        });
    });
}

function initRichTextToolbars() {
    document.querySelectorAll('.rt-editor-box').forEach(box => {
        const textarea = box.querySelector('textarea');
        if (!textarea) return;

        box.querySelectorAll('.btn-rt').forEach(btn => {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                const tag = this.getAttribute('data-tag') || 'strong';
                const start = textarea.selectionStart;
                const end = textarea.selectionEnd;
                const selected = textarea.value.substring(start, end);
                const replacement = `<${tag}>${selected}</${tag}>`;

                textarea.value = textarea.value.substring(0, start) + replacement + textarea.value.substring(end);
                textarea.focus();
                textarea.selectionStart = start + tag.length + 2;
                textarea.selectionEnd = start + tag.length + 2 + selected.length;
                markFormDirty();
            });
        });
    });
}

// =========================================================
// 2. CHAMPS DYNAMIQUES (+ / -)
// =========================================================
function syncDynamicField(wrapper) {
    if (!wrapper) return;
    const field = wrapper.getAttribute('data-field');
    const hiddenInput = document.getElementById('input_' + field);
    if (!hiddenInput) return;

    const values = Array.from(wrapper.querySelectorAll('.dynamic-input')).map(el => el.value);
    hiddenInput.value = JSON.stringify(values);
}

function initDynamicFields() {
    document.querySelectorAll('.dynamic-field-wrapper').forEach(wrapper => {
        wrapper.addEventListener('input', () => syncDynamicField(wrapper));
        wrapper.addEventListener('change', () => syncDynamicField(wrapper));
    });
}

window.addDynamicLine = function (btn, options = []) {
    const wrapper = btn.closest('.dynamic-field-wrapper');
    const container = wrapper.querySelector('.lines-container');

    btn.textContent = '-';
    btn.title = 'Supprimer la ligne';
    btn.style.color = '#c53030';
    btn.onclick = function () {
        window.removeDynamicLine(this, options);
    };

    const newLine = document.createElement('div');
    newLine.className = 'dynamic-line';
    newLine.style.cssText = 'display:flex; align-items:center; gap:6px;';

    if (Array.isArray(options) && options.length > 0) {
        const select = document.createElement('select');
        select.className = 'dynamic-input';
        const defaultOpt = document.createElement('option');
        defaultOpt.value = '';
        defaultOpt.textContent = '-- Sélectionner --';
        select.appendChild(defaultOpt);

        options.forEach(optVal => {
            const opt = document.createElement('option');
            opt.value = optVal;
            opt.textContent = optVal;
            select.appendChild(opt);
        });
        newLine.appendChild(select);
    } else {
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'dynamic-input';
        input.autocomplete = 'off';
        newLine.appendChild(input);
    }

    const addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'btn-secondary dyn-action-btn';
    addBtn.textContent = '+';
    addBtn.title = 'Ajouter une ligne';
    addBtn.onclick = function () {
        window.addDynamicLine(this, options);
    };
    newLine.appendChild(addBtn);

    container.appendChild(newLine);
    syncDynamicField(wrapper);
    markFormDirty();
};

window.removeDynamicLine = function (btn, options = []) {
    const wrapper = btn.closest('.dynamic-field-wrapper');
    const line = btn.closest('.dynamic-line');
    if (line) line.remove();
    syncDynamicField(wrapper);
    markFormDirty();
};

// =========================================================
// 3. DATES COMPLEXES (LE / AVANT / APRÈS / ENTRE)
// =========================================================
function initComplexDates() {
    document.querySelectorAll('.date-modifier').forEach(select => {
        select.addEventListener('change', function () {
            const row = this.closest('.date-complex-row');
            const d1Label = row.querySelector('.d1-label');
            const d2Container = row.querySelector('.d2-container');

            if (this.value === 'entre') {
                d1Label.textContent = 'Début :';
                d2Container.style.display = 'inline-flex';
            } else {
                d1Label.textContent = 'Date :';
                d2Container.style.display = 'none';
                const d2Input = d2Container.querySelector('input');
                if (d2Input) d2Input.value = '';
            }
        });
    });
}

// =========================================================
// 4. LOCALISATION (PAYS, DÉPARTEMENTS, WILAYAS, COMMUNES)
// =========================================================
function initLocalisation() {
    const selectPays = document.getElementById('select_pays');
    const rowDeptFrance = document.getElementById('row_dept_france');
    const rowWilaya = document.getElementById('row_wilaya');
    const rowAlgerieHist = document.getElementById('row_algerie_historique');
    const selectDept = document.getElementById('select_departement_france');
    const selectWilaya = document.getElementById('select_wilaya');
    const selectCommune = document.getElementById('select_commune_actuelle');

    if (!selectPays) return;

    function updatePaysView() {
        const val = selectPays.value;
        rowDeptFrance.style.display = (val === 'France') ? 'flex' : 'none';
        rowWilaya.style.display = (val === 'Algérie') ? 'flex' : 'none';
        rowAlgerieHist.style.display = (val === 'Algérie') ? 'block' : 'none';

        if (val === 'France') {
            if (selectDept && selectDept.value) {
                selectDept.dispatchEvent(new Event('change'));
            } else if (selectCommune) {
                selectCommune.innerHTML = '<option value="">-- Sélectionner d\'abord un département --</option>';
            }
        } else if (val === 'Algérie') {
            if (selectWilaya && selectWilaya.value) {
                selectWilaya.dispatchEvent(new Event('change'));
            } else if (selectCommune) {
                selectCommune.innerHTML = '<option value="">-- Sélectionner d\'abord une wilaya --</option>';
            }
        } else if (selectCommune) {
            selectCommune.innerHTML = '<option value="">-- Sélectionner d\'abord un département ou une wilaya --</option>';
        }
    }

    if (selectDept && selectCommune) {
        selectDept.addEventListener('change', async function () {
            if (selectPays.value !== 'France') return;
            const deptVal = this.value;
            if (!deptVal) {
                selectCommune.innerHTML = '<option value="">-- Sélectionner d\'abord un département --</option>';
                return;
            }

            const match = deptVal.match(/^(\d{2,3}|2A|2B)/);
            if (match) {
                const codeDept = match[1];
                selectCommune.innerHTML = '<option value="">Chargement des communes...</option>';
                try {
                    const response = await fetch(`https://geo.api.gouv.fr/departements/${codeDept}/communes?fields=nom,code&format=json`);
                    if (response.ok) {
                        const communes = await response.json();
                        const selectedCommune = selectCommune.getAttribute('data-selected');
                        selectCommune.innerHTML = '<option value="">-- Sélectionner une commune --</option>';
                        communes.forEach(c => {
                            const opt = document.createElement('option');
                            opt.value = c.nom;
                            opt.textContent = c.nom;
                            if (c.nom === selectedCommune) opt.selected = true;
                            selectCommune.appendChild(opt);
                        });
                    }
                } catch (e) {
                    console.error('Erreur API GéoGouv :', e);
                    selectCommune.innerHTML = '<option value="">Erreur de chargement des communes</option>';
                }
            }
        });
    }

    if (selectWilaya && selectCommune) {
        selectWilaya.addEventListener('change', function () {
            if (selectPays.value !== 'Algérie') return;
            const wilayaVal = this.value;
            if (!wilayaVal || !wilayasMapJS[wilayaVal]) {
                selectCommune.innerHTML = '<option value="">-- Sélectionner d\'abord une wilaya --</option>';
                return;
            }

            const communes = wilayasMapJS[wilayaVal];
            const selectedCommune = selectCommune.getAttribute('data-selected');
            selectCommune.innerHTML = '<option value="">-- Sélectionner une commune --</option>';

            communes.forEach(communeStr => {
                const opt = document.createElement('option');
                opt.value = communeStr;
                opt.textContent = communeStr;
                if (communeStr === selectedCommune) opt.selected = true;
                selectCommune.appendChild(opt);
            });
        });
    }

    selectPays.addEventListener('change', updatePaysView);
    updatePaysView();
}

// =========================================================
// 5. CARTE INTERACTIVE (LEAFLET)
// =========================================================
function initMap() {
    const mapEl = document.getElementById('map');
    if (!mapEl || typeof L === 'undefined') return;

    const latInput = document.getElementById('latitude');
    const lngInput = document.getElementById('longitude');

    const lat = parseFloat(latInput.value) || 46.603354;
    const lng = parseFloat(lngInput.value) || 1.888334;

    window.monumentMap = L.map('map').setView([lat, lng], 5);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(window.monumentMap);

    const marker = L.marker([lat, lng], { draggable: true }).addTo(window.monumentMap);

    marker.on('dragend', () => {
        latInput.value = marker.getLatLng().lat.toFixed(6);
        lngInput.value = marker.getLatLng().lng.toFixed(6);
        markFormDirty();
    });

    window.monumentMap.on('click', e => {
        marker.setLatLng(e.latlng);
        latInput.value = e.latlng.lat.toFixed(6);
        lngInput.value = e.latlng.lng.toFixed(6);
        markFormDirty();
    });

    setTimeout(() => {
        window.monumentMap.invalidateSize();
    }, 300);
}

// =========================================================
// 6. GESTION DES AUTEURS (ONGLET EXÉCUTION)
// =========================================================
const modalMetier = document.getElementById('modal_metier');
if (modalMetier) {
    modalMetier.addEventListener('change', function () {
        const metier = this.value;
        const auteurSelect = document.getElementById('modal_auteur_select');
        const auteurNew = document.getElementById('modal_auteur_new');

        auteurSelect.innerHTML = '<option value="">-- Sélectionner un auteur --</option>';
        auteurNew.style.display = 'none';
        auteurNew.value = '';

        if (metier && metiersDataJS[metier]) {
            metiersDataJS[metier].forEach(nom => {
                const opt = document.createElement('option');
                opt.value = nom;
                opt.textContent = nom;
                auteurSelect.appendChild(opt);
            });
        }

        const optNew = document.createElement('option');
        optNew.value = '_new_';
        optNew.textContent = '➕ Ajouter une nouvelle personne...';
        optNew.style.fontWeight = 'bold';
        optNew.style.color = '#c53030';
        auteurSelect.appendChild(optNew);
    });
}

const modalAuteurSelect = document.getElementById('modal_auteur_select');
if (modalAuteurSelect) {
    modalAuteurSelect.addEventListener('change', function () {
        const auteurNew = document.getElementById('modal_auteur_new');
        if (this.value === '_new_') {
            auteurNew.style.display = 'block';
            auteurNew.focus();
        } else {
            auteurNew.style.display = 'none';
        }
    });
}

function renderAuteursTable() {
    const tbody = document.getElementById('auteurs-tbody');
    if (!tbody) return;
    tbody.innerHTML = '';

    auteursData.forEach((aut, index) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><strong style="color:#4a5568;">${escapeHtml(aut.metier)}</strong></td>
            <td>${escapeHtml(aut.nom)}</td>
            <td>${aut.a_signe ? 'Oui' : 'Non'}</td>
            <td>${escapeHtml(aut.partie)}</td>
            <td style="text-align: center;">
                <span class="action-icon" style="color:#3182ce;" onclick="editAuteur(${index})" title="Éditer">✏️</span>
                <span class="action-icon" style="color:#c53030;" onclick="deleteAuteur(${index})" title="Supprimer">✖</span>
            </td>
        `;
        tbody.appendChild(tr);
    });
    document.getElementById('auteurs_data').value = JSON.stringify(auteursData);
}

window.openAuteurModal = function () {
    document.getElementById('modal_metier').value = '';
    document.getElementById('modal_metier').dispatchEvent(new Event('change'));
    document.getElementById('modal_auteur_new').value = '';
    document.getElementById('modal_a_signe').checked = false;
    document.getElementById('modal_partie').value = '';
    document.getElementById('modal_edit_index_auteur').value = '-1';
    document.getElementById('auteur-modal-overlay').style.display = 'flex';
};

const btnSaveAuteur = document.getElementById('btn-save-auteur');
if (btnSaveAuteur) {
    btnSaveAuteur.addEventListener('click', () => {
        const metier = document.getElementById('modal_metier').value;
        let nom = document.getElementById('modal_auteur_select').value;

        if (nom === '_new_') nom = document.getElementById('modal_auteur_new').value.trim();
        if (!metier) { alert('Veuillez sélectionner un métier.'); return; }
        if (!nom) { alert('Veuillez renseigner un auteur.'); return; }

        const newAuteur = {
            metier: metier,
            nom: nom,
            a_signe: document.getElementById('modal_a_signe').checked,
            partie: document.getElementById('modal_partie').value
        };

        const editIndex = parseInt(document.getElementById('modal_edit_index_auteur').value, 10);
        if (editIndex >= 0) auteursData[editIndex] = newAuteur;
        else auteursData.push(newAuteur);

        renderAuteursTable();
        markFormDirty();
        document.getElementById('auteur-modal-overlay').style.display = 'none';
    });
}

window.editAuteur = function (index) {
    const aut = auteursData[index];
    const metierSelect = document.getElementById('modal_metier');
    metierSelect.value = aut.metier || '';
    metierSelect.dispatchEvent(new Event('change'));

    const selectAuteur = document.getElementById('modal_auteur_select');
    const inputAuteurNew = document.getElementById('modal_auteur_new');
    const optionExists = Array.from(selectAuteur.options).some(opt => opt.value === aut.nom);

    if (optionExists && aut.nom !== '') {
        selectAuteur.value = aut.nom;
        inputAuteurNew.style.display = 'none';
    } else if (aut.nom !== '') {
        selectAuteur.value = '_new_';
        inputAuteurNew.value = aut.nom;
        inputAuteurNew.style.display = 'block';
    }

    document.getElementById('modal_a_signe').checked = aut.a_signe || false;
    document.getElementById('modal_partie').value = aut.partie || '';
    document.getElementById('modal_edit_index_auteur').value = index;
    document.getElementById('auteur-modal-overlay').style.display = 'flex';
};

window.deleteAuteur = function (index) {
    if (confirm('Supprimer cet auteur ?')) {
        auteursData.splice(index, 1);
        renderAuteursTable();
        markFormDirty();
    }
};

// =========================================================
// 7. MOTEUR RÉUTILISABLE D'EXTRACTION GALLICA, ZOOM & ROGNAGE
//    (PARTAGÉ ENTRE DOCUMENTATION ET INSCRIPTION)
// =========================================================
function convertGallicaToIIIF(rawUrl) {
    const cleaned = (rawUrl || '').trim();
    if (!cleaned) return null;

    const match = cleaned.match(/(?:https?:\/\/)?gallica\.bnf\.fr\/(?:iiif\/)?(ark:\/\d+\/[a-zA-Z0-9_-]+)(?:\/(f\d+))?/i);
    if (!match) return null;

    const arkId = match[1];
    const folio = match[2] ? match[2].toLowerCase() : 'f1';
    const nativeUrl = cleaned.includes('/iiif/')
        ? `https://gallica.bnf.fr/${arkId}/${folio}`
        : (cleaned.startsWith('http') ? cleaned : `https://${cleaned}`);

    const iiifUrl = `https://gallica.bnf.fr/iiif/${arkId}/${folio}/full/full/0/native.jpg`;
    const candidateUrls = [
        iiifUrl,
        `https://gallica.bnf.fr/iiif/${arkId}/${folio}/full/full/0/default.jpg`,
        `https://gallica.bnf.fr/iiif/${arkId}/${folio}/full/2000,/0/default.jpg`
    ];

    return { nativeUrl, iiifUrl, candidateUrls };
}

async function fetchGallicaImageDirect(candidateUrls) {
    for (const url of candidateUrls) {
        try {
            const response = await fetch(url, { mode: 'cors', credentials: 'omit' });
            if (!response.ok) continue;

            const blob = await response.blob();
            if (blob && blob.size > 200 && blob.type.startsWith('image/')) {
                return { blob, usedUrl: url };
            }
        } catch (_) {}
    }
    return null;
}

async function dataUrlToUploadedFilename(dataUrl, filename = 'extrait_rogne.jpg') {
    const res = await fetch(dataUrl);
    const blob = await res.blob();
    const formData = new FormData();
    formData.append('photo', blob, filename);

    const uploadRes = await fetch('../includes/upload_photo.php', {
        method: 'POST',
        body: formData
    });
    const result = await uploadRes.json();
    if (!result.success) {
        throw new Error(result.message || 'Erreur lors de la sauvegarde de l\'image.');
    }
    sessionUploadedFiles.push(result.filename);
    return result.filename;
}

function createImageCropper(cfg) {
    const modalBox = document.getElementById(cfg.modalBoxId);
    const panel = document.getElementById(cfg.panelId);
    const info = document.getElementById(cfg.infoId);
    const viewport = document.getElementById(cfg.viewportId);
    const stage = document.getElementById(cfg.stageId);
    const img = document.getElementById(cfg.imgId);
    const selBox = document.getElementById(cfg.selBoxId);
    const status = document.getElementById(cfg.statusId);

    const btnZoomOut = document.getElementById(cfg.zoomOutId);
    const btnZoomIn = document.getElementById(cfg.zoomInId);
    const zoomSlider = document.getElementById(cfg.zoomSliderId);
    const zoomLabel = document.getElementById(cfg.zoomLabelId);
    const btnZoomFit = document.getElementById(cfg.zoomFitId);
    const btnZoom100 = document.getElementById(cfg.zoom100Id);
    const btnExpandModal = document.getElementById(cfg.expandModalId);

    const btnApplyCrop = document.getElementById(cfg.applyCropId);
    const btnResetCrop = document.getElementById(cfg.resetCropId);
    const btnClearImg = document.getElementById(cfg.clearImgId);

    const state = {
        nativeUrl: '',
        iiifUrl: '',
        fullImageDataUrl: '',
        currentDataUrl: '',
        existingFilename: '',
        removedExisting: false,
        hasNewImage: false,
        selection: null,
        zoom: null
    };

    function setZoom(percent) {
        if (!img || !img.naturalWidth) return;

        const clamped = Math.max(15, Math.min(250, Math.round(percent)));
        const prevZoom = state.zoom;

        const centerRatioX = (viewport && viewport.scrollWidth > viewport.clientWidth)
            ? (viewport.scrollLeft + viewport.clientWidth / 2) / viewport.scrollWidth
            : 0.5;
        const centerRatioY = (viewport && viewport.scrollHeight > viewport.clientHeight)
            ? (viewport.scrollTop + viewport.clientHeight / 2) / viewport.scrollHeight
            : 0.5;

        if (state.selection && prevZoom && prevZoom > 0) {
            const ratio = clamped / prevZoom;
            state.selection.x *= ratio;
            state.selection.y *= ratio;
            state.selection.w *= ratio;
            state.selection.h *= ratio;

            if (selBox) {
                selBox.style.left = state.selection.x + 'px';
                selBox.style.top = state.selection.y + 'px';
                selBox.style.width = state.selection.w + 'px';
                selBox.style.height = state.selection.h + 'px';
            }
        }

        state.zoom = clamped;
        img.style.width = Math.round(img.naturalWidth * (clamped / 100)) + 'px';
        img.style.height = Math.round(img.naturalHeight * (clamped / 100)) + 'px';

        if (zoomSlider) zoomSlider.value = clamped;
        if (zoomLabel) zoomLabel.textContent = clamped + '%';

        if (viewport) {
            viewport.scrollLeft = Math.max(0, centerRatioX * viewport.scrollWidth - viewport.clientWidth / 2);
            viewport.scrollTop = Math.max(0, centerRatioY * viewport.scrollHeight - viewport.clientHeight / 2);
        }
    }

    function fitToWidth() {
        if (!img || !img.naturalWidth || !viewport) return;
        const availableWidth = Math.max(260, viewport.clientWidth - 24);
        const fitPercent = Math.min(100, Math.max(15, Math.floor((availableWidth / img.naturalWidth) * 100)));
        setZoom(fitPercent);
    }

    function applyCrop() {
        if (!img || !state.selection || state.selection.w < 5 || state.selection.h < 5) {
            return false;
        }

        const scaleX = img.naturalWidth / img.clientWidth;
        const scaleY = img.naturalHeight / img.clientHeight;

        const sx = Math.round(state.selection.x * scaleX);
        const sy = Math.round(state.selection.y * scaleY);
        const sw = Math.round(state.selection.w * scaleX);
        const sh = Math.round(state.selection.h * scaleY);

        const canvas = document.createElement('canvas');
        canvas.width = sw;
        canvas.height = sh;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(img, sx, sy, sw, sh, 0, 0, sw, sh);

        const croppedDataUrl = canvas.toDataURL('image/jpeg', 0.92);
        state.currentDataUrl = croppedDataUrl;
        state.hasNewImage = true;
        state.removedExisting = false;
        state.selection = null;
        state.zoom = null;

        img.src = croppedDataUrl;
        if (selBox) selBox.style.display = 'none';
        if (status) status.textContent = `✔ Zone rognée (${sw} × ${sh} px)`;

        return true;
    }

    function loadFromBlob(blob, infoHtml = '') {
        const reader = new FileReader();
        reader.onloadend = function () {
            state.fullImageDataUrl = reader.result;
            state.currentDataUrl = reader.result;
            state.hasNewImage = true;
            state.removedExisting = false;
            state.selection = null;
            state.zoom = null;

            if (modalBox) modalBox.classList.add('has-gallica');
            if (panel) panel.style.display = 'block';
            if (info) info.innerHTML = infoHtml;
            if (status) status.textContent = '';
            if (selBox) selBox.style.display = 'none';
            if (img) img.src = reader.result;
        };
        reader.readAsDataURL(blob);
    }

    function loadExisting(filename, infoHtml = '') {
        reset();
        if (!filename) return;
        state.existingFilename = filename;
        if (modalBox) modalBox.classList.add('has-gallica');
        if (panel) panel.style.display = 'block';
        if (info) info.innerHTML = infoHtml;
        if (img) img.src = `/uploads/${filename}`;
    }

    function reset() {
        state.nativeUrl = '';
        state.iiifUrl = '';
        state.fullImageDataUrl = '';
        state.currentDataUrl = '';
        state.existingFilename = '';
        state.removedExisting = false;
        state.hasNewImage = false;
        state.selection = null;
        state.zoom = null;

        if (modalBox) {
            modalBox.classList.remove('has-gallica');
            modalBox.classList.remove('modal-expanded');
        }
        if (btnExpandModal) btnExpandModal.textContent = cfg.expandDefaultLabel || '⛶ Agrandir la fenêtre';
        if (panel) panel.style.display = 'none';
        if (img) {
            img.src = '';
            img.style.width = '';
            img.style.height = '';
        }
        if (selBox) selBox.style.display = 'none';
        if (status) status.textContent = '';
        if (info) info.innerHTML = '';
    }

    async function extractFromGallicaUrl(rawUrl) {
        const converted = convertGallicaToIIIF(rawUrl);
        if (!converted) {
            alert('Lien Gallica non reconnu. Format attendu : https://gallica.bnf.fr/ark:/12148/btv1b53111947b/f1');
            return false;
        }

        state.nativeUrl = converted.nativeUrl;
        state.iiifUrl = converted.iiifUrl;
        state.zoom = null;
        state.selection = null;
        if (status) status.textContent = '';
        if (selBox) selBox.style.display = 'none';

        let blob = null;
        let usedUrl = converted.iiifUrl;

        const directResult = await fetchGallicaImageDirect(converted.candidateUrls);
        if (directResult) {
            blob = directResult.blob;
            usedUrl = directResult.usedUrl;
            state.iiifUrl = usedUrl;
        } else {
            const proxyUrl = `../includes/fetch_gallica.php?url=${encodeURIComponent(converted.iiifUrl)}`;
            const proxyResponse = await fetch(proxyUrl);
            if (!proxyResponse.ok) {
                let errMsg = 'Impossible d\'extraire l\'image depuis Gallica. Vérifiez que le document et le folio existent.';
                try {
                    const errJson = await proxyResponse.json();
                    if (errJson && errJson.message) errMsg = errJson.message;
                } catch (_) {}
                throw new Error(errMsg);
            }
            blob = await proxyResponse.blob();
        }

        loadFromBlob(
            blob,
            `<strong>URL IIIF générée :</strong> <a href="${usedUrl}" target="_blank" rel="noopener noreferrer" style="color:#2b6cb0;">${usedUrl}</a>`
        );
        return true;
    }

    async function resolveFilenameForSave(saveBtn, defaultBtnLabel, uploadPrefix) {
        if (state.selection && state.selection.w >= 5 && state.selection.h >= 5) {
            applyCrop();
        }

        if (state.hasNewImage && state.currentDataUrl) {
            if (saveBtn) {
                saveBtn.disabled = true;
                saveBtn.textContent = 'Enregistrement image...';
            }
            try {
                const newFilename = await dataUrlToUploadedFilename(state.currentDataUrl, `${uploadPrefix}.jpg`);
                if (state.existingFilename && sessionUploadedFiles.includes(state.existingFilename)) {
                    const delData = new FormData();
                    delData.append('files[]', state.existingFilename);
                    navigator.sendBeacon('../includes/delete_photo_ajax.php', delData);
                    sessionUploadedFiles = sessionUploadedFiles.filter(f => f !== state.existingFilename);
                }
                return newFilename;
            } finally {
                if (saveBtn) {
                    saveBtn.disabled = false;
                    saveBtn.textContent = defaultBtnLabel;
                }
            }
        }

        if (state.removedExisting) {
            if (state.existingFilename && sessionUploadedFiles.includes(state.existingFilename)) {
                const delData = new FormData();
                delData.append('files[]', state.existingFilename);
                navigator.sendBeacon('../includes/delete_photo_ajax.php', delData);
                sessionUploadedFiles = sessionUploadedFiles.filter(f => f !== state.existingFilename);
            }
            return '';
        }

        return state.existingFilename || '';
    }

    // Événements du composant
    if (img) {
        img.addEventListener('load', function () {
            if (!img.naturalWidth) return;
            if (state.zoom === null) {
                fitToWidth();
            }
        });
    }

    if (btnZoomOut) btnZoomOut.addEventListener('click', () => setZoom((state.zoom || 50) - 15));
    if (btnZoomIn) btnZoomIn.addEventListener('click', () => setZoom((state.zoom || 50) + 15));
    if (zoomSlider) {
        zoomSlider.addEventListener('input', function () {
            setZoom(parseInt(this.value, 10));
        });
    }
    if (btnZoomFit) btnZoomFit.addEventListener('click', fitToWidth);
    if (btnZoom100) btnZoom100.addEventListener('click', () => setZoom(100));
    if (btnExpandModal && modalBox) {
        btnExpandModal.addEventListener('click', function () {
            const isExpanded = modalBox.classList.toggle('modal-expanded');
            this.textContent = isExpanded
                ? (cfg.expandActiveLabel || '🗗 Réduire la fenêtre')
                : (cfg.expandDefaultLabel || '⛶ Agrandir la fenêtre');
            if (img && img.naturalWidth && state.zoom !== 100) {
                fitToWidth();
            }
        });
    }

    if (viewport) {
        viewport.addEventListener('wheel', function (e) {
            if (e.ctrlKey || e.metaKey) {
                e.preventDefault();
                const delta = e.deltaY < 0 ? 15 : -15;
                setZoom((state.zoom || 50) + delta);
            }
        }, { passive: false });
    }

    // Sélection rectangulaire à la souris pour rognage
    let isDrawing = false;
    let startX = 0;
    let startY = 0;

    if (stage && selBox) {
        stage.addEventListener('mousedown', function (e) {
            if (e.button !== 0) return;
            const rect = stage.getBoundingClientRect();
            startX = Math.max(0, Math.min(e.clientX - rect.left, rect.width));
            startY = Math.max(0, Math.min(e.clientY - rect.top, rect.height));

            isDrawing = true;
            state.selection = { x: startX, y: startY, w: 0, h: 0 };

            selBox.style.left = startX + 'px';
            selBox.style.top = startY + 'px';
            selBox.style.width = '0px';
            selBox.style.height = '0px';
            selBox.style.display = 'block';
        });

        window.addEventListener('mousemove', function (e) {
            if (!isDrawing) return;
            const rect = stage.getBoundingClientRect();
            const currX = Math.max(0, Math.min(e.clientX - rect.left, rect.width));
            const currY = Math.max(0, Math.min(e.clientY - rect.top, rect.height));

            const x = Math.min(startX, currX);
            const y = Math.min(startY, currY);
            const w = Math.abs(currX - startX);
            const h = Math.abs(currY - startY);

            state.selection = { x, y, w, h };
            selBox.style.left = x + 'px';
            selBox.style.top = y + 'px';
            selBox.style.width = w + 'px';
            selBox.style.height = h + 'px';
        });

        window.addEventListener('mouseup', function () {
            if (!isDrawing) return;
            isDrawing = false;
            if (state.selection && (state.selection.w < 5 || state.selection.h < 5)) {
                selBox.style.display = 'none';
                state.selection = null;
            }
        });
    }

    if (btnApplyCrop) {
        btnApplyCrop.addEventListener('click', function () {
            if (!applyCrop()) {
                alert('Tracez d\'abord un cadre sur l\'image avec la souris avant de rogner.');
            }
        });
    }

    if (btnResetCrop) {
        btnResetCrop.addEventListener('click', function () {
            if (state.fullImageDataUrl) {
                state.currentDataUrl = state.fullImageDataUrl;
                state.hasNewImage = true;
                state.selection = null;
                state.zoom = null;
                if (img) img.src = state.fullImageDataUrl;
                if (selBox) selBox.style.display = 'none';
                if (status) status.textContent = 'Image entière restaurée.';
            } else if (cfg.onFallbackReset) {
                cfg.onFallbackReset();
            }
        });
    }

    if (btnClearImg) {
        btnClearImg.addEventListener('click', function () {
            const prevExisting = state.existingFilename;
            reset();
            if (prevExisting) {
                state.existingFilename = prevExisting;
                state.removedExisting = true;
            }
            if (cfg.onClear) cfg.onClear();
        });
    }

    return {
        state,
        loadFromBlob,
        loadExisting,
        reset,
        extractFromGallicaUrl,
        resolveFilenameForSave
    };
}

// =========================================================
// 8. GESTION DE LA DOCUMENTATION (GALLICA IIIF, ZOOM & ROGNAGE)
// =========================================================
function initDocumentationCropper() {
    const btnExtract = document.getElementById('btn-extract-gallica');
    if (!btnExtract) return;

    docCropper = createImageCropper({
        modalBoxId: 'documentation-modal-box',
        panelId: 'gallica-crop-panel',
        infoId: 'gallica-iiif-info',
        viewportId: 'gallica-viewport',
        stageId: 'gallica-stage',
        imgId: 'gallica-preview-img',
        selBoxId: 'gallica-selection-box',
        statusId: 'gallica-crop-status',
        zoomOutId: 'btn-gallica-zoom-out',
        zoomInId: 'btn-gallica-zoom-in',
        zoomSliderId: 'gallica-zoom-slider',
        zoomLabelId: 'gallica-zoom-label',
        zoomFitId: 'btn-gallica-zoom-fit',
        zoom100Id: 'btn-gallica-zoom-100',
        expandModalId: 'btn-gallica-expand-modal',
        applyCropId: 'btn-apply-gallica-crop',
        resetCropId: 'btn-reset-gallica-crop',
        clearImgId: 'btn-clear-gallica-img',
        expandDefaultLabel: '⛶ Agrandir la fenêtre',
        expandActiveLabel: '🗗 Réduire la fenêtre',
        onFallbackReset: () => {
            if (document.getElementById('doc_lien_gallica').value.trim()) {
                btnExtract.click();
            }
        }
    });

    btnExtract.addEventListener('click', async function () {
        const inputUrl = document.getElementById('doc_lien_gallica').value.trim();
        btnExtract.disabled = true;
        btnExtract.textContent = '⏳ Chargement...';
        try {
            await docCropper.extractFromGallicaUrl(inputUrl);
        } catch (err) {
            console.error(err);
            alert(err.message);
        } finally {
            btnExtract.disabled = false;
            btnExtract.textContent = '🖼️ Extraire l\'image';
        }
    });
}

function renderDocumentationTable() {
    const tbody = document.getElementById('documentation-tbody');
    if (!tbody) return;
    tbody.innerHTML = '';

    documentationData.forEach((doc, index) => {
        const tr = document.createElement('tr');

        let gallicaCellHtml = '';
        if (doc.image_extrait) {
            gallicaCellHtml += `<img src="/uploads/${doc.image_extrait}" alt="Extrait Gallica" class="photo-thumbnail" style="margin-bottom:4px;" onclick="openImageViewer('/uploads/${doc.image_extrait}')" title="Agrandir l'image extraite">`;
        }
        if (doc.lien_gallica) {
            gallicaCellHtml += `<a href="${escapeHtml(doc.lien_gallica)}" target="_blank" rel="noopener noreferrer" style="font-size:0.78rem; color:#2b6cb0; font-weight:bold; text-decoration:underline; display:inline-block;">🔗 Voir sur Gallica</a>`;
        }
        if (!gallicaCellHtml) {
            gallicaCellHtml = '<span style="color:#a0aec0;">-</span>';
        }

        tr.innerHTML = `
            <td><span style="background:#e2e8f0; padding:3px 8px; border-radius:4px; font-size:0.8rem; font-weight:bold; color:#2d3748;">${escapeHtml(doc.type)}</span></td>
            <td><strong>${escapeHtml(doc.titre)}</strong></td>
            <td>${escapeHtml(doc.auteur)}</td>
            <td>${escapeHtml(doc.annee)}</td>
            <td>${gallicaCellHtml}</td>
            <td><div style="max-height:45px; overflow:hidden; text-overflow:ellipsis;" title="${escapeHtml(doc.notes)}">${escapeHtml(doc.notes)}</div></td>
            <td style="text-align: center;">
                <span class="action-icon" style="color:#3182ce;" onclick="editDocumentation(${index})" title="Éditer">✏️</span>
                <span class="action-icon" style="color:#c53030;" onclick="deleteDocumentation(${index})" title="Supprimer">✖</span>
            </td>
        `;
        tbody.appendChild(tr);
    });
    document.getElementById('documentation_data').value = JSON.stringify(documentationData);
}

window.openDocumentationModal = function () {
    document.getElementById('doc_type').value = 'Bibliographie';
    document.getElementById('doc_titre').value = '';
    document.getElementById('doc_auteur').value = '';
    document.getElementById('doc_annee').value = '';
    document.getElementById('doc_lien_gallica').value = '';
    document.getElementById('doc_notes').value = '';
    document.getElementById('modal_edit_index_doc').value = '-1';
    if (docCropper) docCropper.reset();
    document.getElementById('documentation-modal-overlay').style.display = 'flex';
};

const btnSaveDoc = document.getElementById('btn-save-documentation');
if (btnSaveDoc) {
    btnSaveDoc.addEventListener('click', async function () {
        const type = document.getElementById('doc_type').value;
        const titre = document.getElementById('doc_titre').value.trim();
        const auteur = document.getElementById('doc_auteur').value.trim();
        const annee = document.getElementById('doc_annee').value.trim();
        const rawGallica = document.getElementById('doc_lien_gallica').value.trim();
        const notes = document.getElementById('doc_notes').value.trim();

        if (!titre) {
            alert('Veuillez renseigner au moins un titre.');
            return;
        }

        const converted = convertGallicaToIIIF(rawGallica);
        const lienGallicaNatif = rawGallica;
        const lienGallicaIIIF = converted ? converted.iiifUrl : (docCropper ? docCropper.state.iiifUrl : '');

        let imageExtrait = '';
        try {
            imageExtrait = docCropper
                ? await docCropper.resolveFilenameForSave(btnSaveDoc, '✔ Valider', 'gallica_extrait')
                : '';
        } catch (err) {
            alert(err.message);
            return;
        }

        const docObj = {
            type,
            titre,
            auteur,
            annee,
            lien_gallica: lienGallicaNatif,
            lien_gallica_iiif: lienGallicaIIIF,
            image_extrait: imageExtrait,
            notes
        };

        const editIndex = parseInt(document.getElementById('modal_edit_index_doc').value, 10);
        if (editIndex >= 0) documentationData[editIndex] = docObj;
        else documentationData.push(docObj);

        renderDocumentationTable();
        markFormDirty();
        if (docCropper) docCropper.reset();
        document.getElementById('documentation-modal-overlay').style.display = 'none';
    });
}

window.editDocumentation = function (index) {
    const doc = documentationData[index];
    if (docCropper) docCropper.reset();

    document.getElementById('doc_type').value = doc.type || 'Bibliographie';
    document.getElementById('doc_titre').value = doc.titre || '';
    document.getElementById('doc_auteur').value = doc.auteur || '';
    document.getElementById('doc_annee').value = doc.annee || '';
    document.getElementById('doc_lien_gallica').value = doc.lien_gallica || '';
    document.getElementById('doc_notes').value = doc.notes || '';
    document.getElementById('modal_edit_index_doc').value = index;

    if (docCropper) {
        docCropper.state.nativeUrl = doc.lien_gallica || '';
        docCropper.state.iiifUrl = doc.lien_gallica_iiif || '';
        if (doc.image_extrait) {
            const infoHtml = doc.lien_gallica_iiif
                ? `<strong>URL IIIF :</strong> <a href="${escapeHtml(doc.lien_gallica_iiif)}" target="_blank" style="color:#2b6cb0;">${escapeHtml(doc.lien_gallica_iiif)}</a>`
                : '';
            docCropper.loadExisting(doc.image_extrait, infoHtml);
        }
    }

    document.getElementById('documentation-modal-overlay').style.display = 'flex';
};

window.deleteDocumentation = function (index) {
    if (confirm('Supprimer cette référence documentaire ?')) {
        const doc = documentationData[index];
        if (doc && doc.image_extrait && sessionUploadedFiles.includes(doc.image_extrait)) {
            const formData = new FormData();
            formData.append('files[]', doc.image_extrait);
            navigator.sendBeacon('../includes/delete_photo_ajax.php', formData);
            sessionUploadedFiles = sessionUploadedFiles.filter(f => f !== doc.image_extrait);
        }
        documentationData.splice(index, 1);
        renderDocumentationTable();
        markFormDirty();
    }
};

// =========================================================
// 9. GESTION DES INSCRIPTIONS (PHOTO + ZOOM/ROGNAGE + RETRANSCRIPTION)
// =========================================================
function initInscriptionCropper() {
    const fileInput = document.getElementById('modal_inscription_photo_file');
    const btnExtract = document.getElementById('btn-extract-insc-gallica');

    inscCropper = createImageCropper({
        modalBoxId: 'inscription-modal-box',
        panelId: 'insc-crop-panel',
        infoId: 'insc-iiif-info',
        viewportId: 'insc-viewport',
        stageId: 'insc-stage',
        imgId: 'insc-preview-img',
        selBoxId: 'insc-selection-box',
        statusId: 'insc-crop-status',
        zoomOutId: 'btn-insc-zoom-out',
        zoomInId: 'btn-insc-zoom-in',
        zoomSliderId: 'insc-zoom-slider',
        zoomLabelId: 'insc-zoom-label',
        zoomFitId: 'btn-insc-zoom-fit',
        zoom100Id: 'btn-insc-zoom-100',
        expandModalId: 'btn-insc-expand-modal',
        applyCropId: 'btn-apply-insc-crop',
        resetCropId: 'btn-reset-insc-crop',
        clearImgId: 'btn-clear-insc-img',
        expandDefaultLabel: '⛶ Agrandir',
        expandActiveLabel: '🗗 Réduire',
        onFallbackReset: () => {
            if (document.getElementById('insc_lien_gallica')?.value.trim() && btnExtract) {
                btnExtract.click();
            }
        },
        onClear: () => {
            if (fileInput) fileInput.value = '';
        }
    });

    if (fileInput) {
        fileInput.addEventListener('change', function () {
            if (this.files && this.files.length > 0) {
                const file = this.files[0];
                inscCropper.loadFromBlob(file, `<strong>Fichier sélectionné :</strong> ${escapeHtml(file.name)}`);
            }
        });
    }

    if (btnExtract) {
        btnExtract.addEventListener('click', async function () {
            const inputUrl = document.getElementById('insc_lien_gallica').value.trim();
            btnExtract.disabled = true;
            btnExtract.textContent = '⏳...';
            try {
                await inscCropper.extractFromGallicaUrl(inputUrl);
            } catch (err) {
                console.error(err);
                alert(err.message);
            } finally {
                btnExtract.disabled = false;
                btnExtract.textContent = '🖼️ Extraire';
            }
        });
    }
}

function renderInscriptionsTable() {
    const tbody = document.getElementById('inscriptions-tbody');
    if (!tbody) return;
    tbody.innerHTML = '';

    inscriptionsData.forEach((insc, index) => {
        const tr = document.createElement('tr');
        const photoFile = insc.photo || '';
        const photoCellHtml = photoFile
            ? `<img src="/uploads/${photoFile}" alt="Photo inscription" class="photo-thumbnail"
                    style="width:70px; height:70px;"
                    onclick="openImageViewer('/uploads/${photoFile}')"
                    title="Cliquez pour agrandir la photo">`
            : `<span style="color:#a0aec0; font-size:0.78rem;">Aucune photo</span>`;

        tr.innerHTML = `
            <td style="text-align:center;">${photoCellHtml}</td>
            <td><span style="background:#e2e8f0; padding:3px 8px; border-radius:4px; font-size:0.8rem; font-weight:bold; color:#2d3748;">${escapeHtml(insc.type || 'Non défini')}</span></td>
            <td style="white-space: pre-wrap; line-height:1.45;">${escapeHtml(insc.contenu || '')}</td>
            <td style="text-align: center;">
                <span class="action-icon" style="color:#3182ce;" onclick="editInscription(${index})" title="Éditer">✏️</span>
                <span class="action-icon" style="color:#c53030;" onclick="deleteInscription(${index})" title="Supprimer">✖</span>
            </td>
        `;
        tbody.appendChild(tr);
    });
    document.getElementById('inscriptions_data').value = JSON.stringify(inscriptionsData);
}

window.openInscriptionModal = function () {
    document.getElementById('modal_inscription_type').value = '';
    document.getElementById('modal_inscription_contenu').value = '';
    document.getElementById('modal_edit_index_inscription').value = '-1';

    const fileInput = document.getElementById('modal_inscription_photo_file');
    if (fileInput) fileInput.value = '';
    const gallicaInput = document.getElementById('insc_lien_gallica');
    if (gallicaInput) gallicaInput.value = '';

    if (inscCropper) inscCropper.reset();

    document.getElementById('inscription-modal-overlay').style.display = 'flex';
};

const btnSaveInsc = document.getElementById('btn-save-inscription');
if (btnSaveInsc) {
    btnSaveInsc.addEventListener('click', async function () {
        const type = document.getElementById('modal_inscription_type').value.trim();
        const contenu = document.getElementById('modal_inscription_contenu').value.trim();

        const hasPendingImage = inscCropper && (
            inscCropper.state.hasNewImage ||
            (inscCropper.state.existingFilename && !inscCropper.state.removedExisting)
        );

        if (!contenu && !hasPendingImage) {
            alert('Veuillez renseigner une retranscription ou ajouter une photo de l\'inscription.');
            return;
        }

        let finalPhoto = '';
        try {
            finalPhoto = inscCropper
                ? await inscCropper.resolveFilenameForSave(btnSaveInsc, '✔ Valider', 'inscription')
                : '';
        } catch (err) {
            alert(err.message);
            return;
        }

        const inscObj = { type, contenu, photo: finalPhoto };
        const editIndex = parseInt(document.getElementById('modal_edit_index_inscription').value, 10);

        if (editIndex >= 0) inscriptionsData[editIndex] = inscObj;
        else inscriptionsData.push(inscObj);

        renderInscriptionsTable();
        markFormDirty();
        if (inscCropper) inscCropper.reset();
        document.getElementById('inscription-modal-overlay').style.display = 'none';
    });
}

window.editInscription = function (index) {
    const insc = inscriptionsData[index];
    if (inscCropper) inscCropper.reset();

    document.getElementById('modal_inscription_type').value = insc.type || '';
    document.getElementById('modal_inscription_contenu').value = insc.contenu || '';
    document.getElementById('modal_edit_index_inscription').value = index;

    const fileInput = document.getElementById('modal_inscription_photo_file');
    if (fileInput) fileInput.value = '';
    const gallicaInput = document.getElementById('insc_lien_gallica');
    if (gallicaInput) gallicaInput.value = '';

    if (inscCropper && insc.photo) {
        inscCropper.loadExisting(insc.photo, '<strong>Photo enregistrée :</strong> vous pouvez la zoomer ou la rogner ci-dessous.');
    }

    document.getElementById('inscription-modal-overlay').style.display = 'flex';
};

window.deleteInscription = function (index) {
    if (confirm('Supprimer cette inscription ?')) {
        const insc = inscriptionsData[index];
        if (insc && insc.photo && sessionUploadedFiles.includes(insc.photo)) {
            const formData = new FormData();
            formData.append('files[]', insc.photo);
            navigator.sendBeacon('../includes/delete_photo_ajax.php', formData);
            sessionUploadedFiles = sessionUploadedFiles.filter(f => f !== insc.photo);
        }
        inscriptionsData.splice(index, 1);
        renderInscriptionsTable();
        markFormDirty();
    }
};

// =========================================================
// 10. GESTION DES PHOTOS, VISIONNEUSE HD & NETTOYAGE SESSION
// =========================================================
const modalPhotoFile = document.getElementById('modal_photo_file');
if (modalPhotoFile) {
    modalPhotoFile.addEventListener('change', function () {
        const label = document.getElementById('file-name-label');
        if (this.files && this.files.length > 0) {
            label.textContent = 'Fichier sélectionné : ' + this.files[0].name;
            label.style.color = '#2b6cb0';
        } else {
            label.textContent = 'Aucun fichier sélectionné.';
            label.style.color = '#666';
        }
    });
}

window.clearPhotoDate = function () {
    document.getElementById('modal_photo_jour').value = '';
    document.getElementById('modal_photo_mois').value = '';
    document.getElementById('modal_photo_annee').value = '';
};

window.openPhotoModal = function (sectionId, hasDate) {
    document.getElementById('photo-modal-title').textContent = 'Ajouter une photo';
    document.getElementById('modal_photo_file').value = '';
    document.getElementById('file-name-label').textContent = 'Aucun fichier sélectionné.';
    document.getElementById('file-name-label').style.color = '#666';
    document.getElementById('modal_photo_titre').value = '';
    document.getElementById('modal_photo_type_doc').value = '';
    document.getElementById('modal_photo_auteur').value = '';
    document.getElementById('modal_photo_lieu').value = '';
    document.getElementById('modal_photo_desc').value = '';
    document.getElementById('modal_photo_source').value = '';
    window.clearPhotoDate();

    document.getElementById('modal_photo_target_section').value = sectionId;
    document.getElementById('modal_photo_has_date').value = hasDate;
    document.getElementById('modal_photo_edit_index').value = '-1';
    document.getElementById('btn-save-photo').textContent = '✔ Valider et Uploader';

    const dateRow = document.getElementById('row_photo_date');
    dateRow.style.display = hasDate ? 'flex' : 'none';
    document.getElementById('photo-modal-overlay').style.display = 'flex';
};

window.editPhotoItem = function (sectionId, index) {
    const items = photosPayloadData[sectionId] || [];
    const item = items[index];
    if (!item) return;

    const existingFile = item.file || item.filename || '';

    document.getElementById('photo-modal-title').textContent = 'Modifier la photo';
    document.getElementById('modal_photo_file').value = '';
    const label = document.getElementById('file-name-label');
    if (existingFile) {
        label.textContent = 'Fichier actuel : ' + existingFile + ' (laisser vide pour conserver)';
        label.style.color = '#2b6cb0';
    } else {
        label.textContent = 'Aucun fichier sélectionné.';
        label.style.color = '#666';
    }

    document.getElementById('modal_photo_titre').value = (item.titre && item.titre !== 'Sans titre') ? item.titre : '';

    const typeDocSelect = document.getElementById('modal_photo_type_doc');
    const typeDocVal = item.type_document || '';
    if (typeDocVal !== '' && !Array.from(typeDocSelect.options).some(o => o.value === typeDocVal)) {
        const opt = document.createElement('option');
        opt.value = typeDocVal;
        opt.textContent = typeDocVal;
        typeDocSelect.appendChild(opt);
    }
    typeDocSelect.value = typeDocVal;

    document.getElementById('modal_photo_auteur').value = (item.auteur && item.auteur !== '-') ? item.auteur : '';
    document.getElementById('modal_photo_lieu').value = (item.lieu && item.lieu !== '-') ? item.lieu : '';
    document.getElementById('modal_photo_desc').value = (item.description && item.description !== '-') ? item.description : '';
    document.getElementById('modal_photo_source').value = (item.source && item.source !== '-') ? item.source : '';

    window.clearPhotoDate();
    if (item.date && item.date !== '-') {
        const parts = String(item.date).split('/');
        if (parts.length === 3) {
            document.getElementById('modal_photo_jour').value = parts[0];
            document.getElementById('modal_photo_mois').value = parts[1];
            document.getElementById('modal_photo_annee').value = parts[2];
        } else if (parts.length === 2) {
            document.getElementById('modal_photo_mois').value = parts[0];
            document.getElementById('modal_photo_annee').value = parts[1];
        } else if (parts.length === 1) {
            document.getElementById('modal_photo_annee').value = parts[0];
        }
    }

    document.getElementById('modal_photo_target_section').value = sectionId;
    document.getElementById('modal_photo_has_date').value = 'true';
    document.getElementById('modal_photo_edit_index').value = String(index);
    document.getElementById('btn-save-photo').textContent = '✔ Enregistrer les modifications';

    document.getElementById('row_photo_date').style.display = 'flex';
    document.getElementById('photo-modal-overlay').style.display = 'flex';
};

window.openImageViewer = function (imageSrc) {
    document.getElementById('viewer-img-hd').src = imageSrc;
    document.getElementById('image-viewer-overlay').style.display = 'flex';
};

const btnSavePhoto = document.getElementById('btn-save-photo');
if (btnSavePhoto) {
    btnSavePhoto.addEventListener('click', async function () {
        const sectionId = document.getElementById('modal_photo_target_section').value;
        const hasDate = document.getElementById('modal_photo_has_date').value === 'true';
        const editIndex = parseInt(document.getElementById('modal_photo_edit_index').value, 10);
        const fileInput = document.getElementById('modal_photo_file');

        const existingItem = (editIndex >= 0 && photosPayloadData[sectionId])
            ? photosPayloadData[sectionId][editIndex]
            : null;
        let finalFilename = existingItem ? (existingItem.file || existingItem.filename || '') : '';

        if (fileInput.files.length === 0 && !finalFilename) {
            alert('Veuillez sélectionner un fichier image.');
            return;
        }

        const btn = this;
        const defaultBtnText = btn.textContent;
        btn.disabled = true;
        btn.textContent = fileInput.files.length > 0 ? 'Upload en cours...' : 'Enregistrement...';

        try {
            if (fileInput.files.length > 0) {
                const formData = new FormData();
                formData.append('photo', fileInput.files[0]);

                const response = await fetch('../includes/upload_photo.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();

                if (!result.success) {
                    alert('Erreur lors de l\'upload : ' + (result.message || 'Erreur inconnue'));
                    btn.disabled = false;
                    btn.textContent = defaultBtnText;
                    return;
                }

                if (finalFilename && sessionUploadedFiles.includes(finalFilename)) {
                    const delData = new FormData();
                    delData.append('files[]', finalFilename);
                    navigator.sendBeacon('../includes/delete_photo_ajax.php', delData);
                    sessionUploadedFiles = sessionUploadedFiles.filter(f => f !== finalFilename);
                }

                finalFilename = result.filename;
                sessionUploadedFiles.push(finalFilename);
            }

            const titre = document.getElementById('modal_photo_titre').value.trim() || 'Sans titre';
            const typeDocument = document.getElementById('modal_photo_type_doc').value.trim();
            const auteur = document.getElementById('modal_photo_auteur').value.trim() || '-';
            const lieu = document.getElementById('modal_photo_lieu').value.trim() || '-';
            const desc = document.getElementById('modal_photo_desc').value.trim() || '-';
            const source = document.getElementById('modal_photo_source').value.trim() || '-';

            let dateStr = '';
            if (hasDate) {
                const j = document.getElementById('modal_photo_jour').value.trim();
                const m = document.getElementById('modal_photo_mois').value.trim();
                const a = document.getElementById('modal_photo_annee').value.trim();
                dateStr = [j, m, a].filter(Boolean).join('/') || '-';
            }

            const photoEntry = {
                titre: titre,
                type_document: typeDocument,
                auteur: auteur,
                lieu: lieu,
                date: dateStr,
                description: desc,
                source: source,
                file: finalFilename
            };

            if (!photosPayloadData[sectionId]) photosPayloadData[sectionId] = [];
            if (editIndex >= 0 && photosPayloadData[sectionId][editIndex]) {
                photosPayloadData[sectionId][editIndex] = photoEntry;
            } else {
                photosPayloadData[sectionId].push(photoEntry);
            }

            renderPhotoTable(sectionId);
            markFormDirty();
            document.getElementById('photo-modal-overlay').style.display = 'none';
        } catch (err) {
            console.error(err);
            alert('Erreur réseau lors de l\'upload de la photo.');
        } finally {
            btn.disabled = false;
            btn.textContent = defaultBtnText;
        }
    });
}

function renderPhotoTable(sectionId) {
    const tbody = document.getElementById('tbody-' + sectionId);
    if (!tbody) return;
    tbody.innerHTML = '';

    const items = photosPayloadData[sectionId] || [];
    items.forEach((item, index) => {
        const tr = document.createElement('tr');
        const fileName = item.file || item.filename || '';
        const typeDocHtml = item.type_document
            ? `<span style="background:#e2e8f0; padding:2px 7px; border-radius:4px; font-size:0.78rem; font-weight:bold; color:#2d3748;">${escapeHtml(item.type_document)}</span>`
            : '<span style="color:#a0aec0;">-</span>';

        tr.innerHTML = `
            <td>
                <img src="/uploads/${fileName}" alt="Miniature" class="photo-thumbnail"
                     onclick="openImageViewer('/uploads/${fileName}')"
                     title="Cliquez pour voir en grand">
            </td>
            <td><strong>${escapeHtml(item.titre || 'Sans titre')}</strong></td>
            <td>${typeDocHtml}</td>
            <td>${escapeHtml(item.auteur || '-')}</td>
            <td>${escapeHtml(item.lieu || '-')}</td>
            <td>${escapeHtml(item.date || '-')}</td>
            <td><div style="max-height:40px; overflow:hidden; text-overflow:ellipsis;" title="${escapeHtml(item.description)}">${escapeHtml(item.description || '-')}</div></td>
            <td><div style="max-height:40px; overflow:hidden; text-overflow:ellipsis;" title="${escapeHtml(item.source)}">${escapeHtml(item.source || '-')}</div></td>
            <td style="text-align:center; white-space:nowrap;">
                <span class="action-icon" style="color:#3182ce;" onclick="editPhotoItem('${sectionId}', ${index})" title="Éditer">✏️</span>
                <span class="action-icon" style="color:#c53030;" onclick="removePhotoItem('${sectionId}', ${index})" title="Supprimer">✖</span>
            </td>
        `;
        tbody.appendChild(tr);
    });

    document.getElementById('photos_payload_data').value = JSON.stringify(photosPayloadData);
}

window.removePhotoItem = function (sectionId, index) {
    if (confirm('Supprimer cette photo ?') && photosPayloadData[sectionId]) {
        const item = photosPayloadData[sectionId][index];
        const fileName = item.file || item.filename || '';

        if (sessionUploadedFiles.includes(fileName)) {
            const formData = new FormData();
            formData.append('files[]', fileName);
            navigator.sendBeacon('../includes/delete_photo_ajax.php', formData);
            sessionUploadedFiles = sessionUploadedFiles.filter(f => f !== fileName);
        }

        photosPayloadData[sectionId].splice(index, 1);
        renderPhotoTable(sectionId);
        markFormDirty();
    }
};

window.addEventListener('pagehide', function () {
    if (!isFormSubmitting && sessionUploadedFiles.length > 0) {
        const formData = new FormData();
        sessionUploadedFiles.forEach(f => formData.append('files[]', f));
        navigator.sendBeacon('../includes/delete_photo_ajax.php', formData);
    }
});

// =========================================================
// 11. RECHERCHE DE MONUMENTS LIÉS (ONGLET ANALOGIE)
// =========================================================
function initLinkedMonumentsSearch() {
    const searchInput = document.getElementById('link-search');
    const suggestionsBox = document.getElementById('link-suggestions');
    const linkedContainer = document.getElementById('linked-container');

    if (!searchInput || !suggestionsBox || !linkedContainer) return;

    linkedContainer.addEventListener('click', function (e) {
        if (e.target.closest('button')) {
            markFormDirty();
        }
    });

    searchInput.addEventListener('input', function () {
        const query = this.value.trim().toLowerCase();
        suggestionsBox.innerHTML = '';
        if (query.length < 2) return;

        const matches = allMonumentsSearchJS.filter(m => m.titre.toLowerCase().includes(query)).slice(0, 10);
        matches.forEach(m => {
            const div = document.createElement('div');
            div.textContent = m.titre;
            div.style.cssText = 'padding:6px 10px; cursor:pointer; border-bottom:1px solid #eee; font-size:0.83rem;';
            div.addEventListener('mouseover', () => { div.style.background = '#edf2f7'; });
            div.addEventListener('mouseout', () => { div.style.background = '#fff'; });
            div.addEventListener('click', () => {
                const alreadyLinked = Array.from(linkedContainer.querySelectorAll('input[type="hidden"]')).some(inp => inp.value == m.id);
                if (!alreadyLinked) {
                    const tag = document.createElement('div');
                    tag.className = 'linked-item tag-box';
                    tag.style.cssText = 'background:#e0f7fa; padding:4px 8px; border-radius:4px; display:inline-flex; align-items:center; border:1px solid #b2ebf2; font-size:0.83rem;';
                    tag.innerHTML = `${escapeHtml(m.titre)} <button type="button" onclick="this.parentNode.remove()" class="btn-secondary" style="padding:1px 5px; margin-left:8px; cursor:pointer;">✖</button><input type="hidden" name="linked_monuments[]" value="${m.id}">`;
                    linkedContainer.appendChild(tag);
                    markFormDirty();
                }
                searchInput.value = '';
                suggestionsBox.innerHTML = '';
            });
            suggestionsBox.appendChild(div);
        });
    });
}

// =========================================================
// 12. SOUMISSION GLOBALE DU FORMULAIRE
// =========================================================
window.handleFormSubmit = function () {
    isFormSubmitting = true;

    document.querySelectorAll('.dynamic-field-wrapper').forEach(wrapper => syncDynamicField(wrapper));

    document.getElementById('auteurs_data').value = JSON.stringify(auteursData);
    document.getElementById('documentation_data').value = JSON.stringify(documentationData);
    document.getElementById('inscriptions_data').value = JSON.stringify(inscriptionsData);
    document.getElementById('photos_payload_data').value = JSON.stringify(photosPayloadData);

    return true;
};