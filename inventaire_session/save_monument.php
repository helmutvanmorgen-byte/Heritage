<?php
ob_start();
session_start();
date_default_timezone_set('Europe/Paris');

// Décommenter temporairement les 2 lignes suivantes si une erreur persiste :
// ini_set('display_errors', '1');
// error_reporting(E_ALL);

if (file_exists(__DIR__ . '/../includes/db.php')) {
    require_once __DIR__ . '/../includes/db.php';
}
require_once __DIR__ . '/../includes/MonumentConfig.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit("Non connecté.");
}

if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
    $_SESSION['flash_error'] = "Action non autorisée (Erreur de sécurité CSRF).";
    header('Location: dashboard.php');
    exit;
}

$id = isset($_POST['id']) && is_numeric($_POST['id']) ? (int)$_POST['id'] : null;
$monument = [];

$dataFile = __DIR__ . '/../json/monuments.json';
if (!is_dir(dirname($dataFile))) {
    mkdir(dirname($dataFile), 0775, true);
}

$fp = fopen($dataFile, 'c+');
if ($fp && flock($fp, LOCK_EX)) {
    $size = filesize($dataFile);
    $json = $size > 0 ? preg_replace('/^\xEF\xBB\xBF/', '', (string)fread($fp, $size)) : '[]';
    $monuments = json_decode($json, true);
    if (!is_array($monuments)) {
        $monuments = [];
    }

    // 1. Nettoyage des champs JSON dynamiques (listes de chaînes)
    $structuredJsonFields = ['auteurs_structures', 'documentation_zotero', 'inscriptions_structures', 'photos_payload', 'linked_monuments', 'images'];
    $cfgJsonFields = (defined('MonumentConfig::JSON_FIELDS') && is_array(MonumentConfig::JSON_FIELDS))
        ? MonumentConfig::JSON_FIELDS
        : [];

    $allJsonFields = array_diff(
        array_unique(array_merge(
            $cfgJsonFields,
            [
                'titre', 'attribution_titre', 'appellation_usage', 'precisions_auteur',
                'source_representation', 'fondeur', 'architecte', 'type_oeuvre',
                'materiaux', 'techniques', 'personnage', 'communes_actuelles',
                'expositions', 'protections', 'bibliographie', 'sources', 'analogies'
            ]
        )),
        $structuredJsonFields
    );

    foreach ($allJsonFields as $field) {
        $rawList = [];
        if (isset($_POST[$field]) && !is_array($_POST[$field])) {
            $decoded = json_decode((string)$_POST[$field], true);
            if (is_array($decoded)) {
                $rawList = $decoded;
            }
        } elseif (is_array($_POST[$field] ?? null)) {
            $rawList = $_POST[$field];
        }

        $monument[$field] = array_values(array_filter(
            array_map(fn($v) => is_scalar($v) ? trim((string)$v) : '', $rawList),
            fn($v) => $v !== ''
        ));
    }

    // 2. Nettoyage des champs texte et simples
    $cfgTextFields = (defined('MonumentConfig::TEXT_FIELDS') && is_array(MonumentConfig::TEXT_FIELDS))
        ? MonumentConfig::TEXT_FIELDS
        : [];
    $cfgSimpleFields = (defined('MonumentConfig::SIMPLE_FIELDS') && is_array(MonumentConfig::SIMPLE_FIELDS))
        ? MonumentConfig::SIMPLE_FIELDS
        : [];
    $cfgDateFields = (defined('MonumentConfig::DATE_FIELDS') && is_array(MonumentConfig::DATE_FIELDS))
        ? MonumentConfig::DATE_FIELDS
        : [];

    $allDateFields = array_unique(array_merge(
        $cfgDateFields,
        ['annee_creation', 'date_commande', 'inauguration', 'destruction']
    ));

    $allTextSimpleFields = array_diff(
        array_unique(array_merge(
            $cfgTextFields,
            $cfgSimpleFields,
            [
                'pays', 'departement_france', 'wilaya_algerie', 'commune_ancienne',
                'departement_avant_1962', 'type_localisation', 'informations_localisation',
                'latitude', 'longitude', 'dimensions', 'description', 'historique',
                'prix_total_texte', 'souscription', 'subvention_commune', 'subvention_etat',
                'produit_fete', 'dons_particuliers', 'dons_entreprises', 'dons_autres',
                'emprunt', 'autres_subventions', 'commentaires_economie',
                'projet_definitif', 'emplacement_edifice', 'commune_origine',
                'proprietaires_actuels', 'numero_fiche', 'redacteur_notice',
                'mentions_legales', 'id_merimee', 'id_joconde', 'id_palissy', 'id_memoire'
            ]
        )),
        $allDateFields,
        $allJsonFields,
        $structuredJsonFields
    );

    foreach ($allTextSimpleFields as $field) {
        $val = $_POST[$field] ?? '';
        $monument[$field] = is_scalar($val) ? trim((string)$val) : '';
    }

    // 3. Traitement des dates complexes
    foreach ($allDateFields as $field) {
        if (isset($_POST[$field])) {
            $dateData = is_string($_POST[$field]) ? json_decode($_POST[$field], true) : $_POST[$field];
            if (!is_array($dateData)) {
                $dateData = ['modifier' => 'le', 'date1' => is_scalar($_POST[$field]) ? trim((string)$_POST[$field]) : '', 'date2' => ''];
            }
            if (($dateData['modifier'] ?? 'le') !== 'entre') {
                $dateData['date2'] = '';
            }
            $monument[$field] = $dateData;
        }
    }

    // 4. Champs spécifiques et sélecteurs
    $monument['oeuvre_localisee'] = in_array($_POST['oeuvre_localisee'] ?? '', ['oui', 'non'], true) ? $_POST['oeuvre_localisee'] : 'oui';
    $monument['commande_etat'] = in_array($_POST['commande_etat'] ?? '', ['oui', 'non', 'inconnu'], true) ? $_POST['commande_etat'] : 'inconnu';
    $monument['statut_publication'] = in_array($_POST['statut_publication'] ?? '', ['brouillon', 'a_valider', 'publie'], true) ? $_POST['statut_publication'] : 'brouillon';
    $monument['etat_conservation'] = in_array($_POST['etat_conservation'] ?? '', ['bon', 'degrade', 'peril', 'disparu'], true) ? $_POST['etat_conservation'] : '';

    // 5. Tableaux structurés (auteurs, documentation, inscriptions)
    foreach (['auteurs_structures', 'documentation_zotero', 'inscriptions_structures'] as $structField) {
        $rawStruct = isset($_POST[$structField]) && is_string($_POST[$structField])
            ? json_decode($_POST[$structField], true)
            : [];
        $monument[$structField] = is_array($rawStruct) ? array_values($rawStruct) : [];
    }

    // 6. Nettoyage et sauvegarde des photos structurées (onglet PHOTOS)
    $rawPhotosPayload = isset($_POST['photos_payload']) && is_string($_POST['photos_payload'])
        ? json_decode($_POST['photos_payload'], true)
        : [];
    $cleanPhotosPayload = ['actuelles' => [], 'maquettes' => [], 'anciennes' => []];

    if (is_array($rawPhotosPayload)) {
        foreach (['actuelles', 'maquettes', 'anciennes'] as $secKey) {
            if (!empty($rawPhotosPayload[$secKey]) && is_array($rawPhotosPayload[$secKey])) {
                foreach ($rawPhotosPayload[$secKey] as $photoItem) {
                    if (!is_array($photoItem)) {
                        continue;
                    }
                    $cleanPhotosPayload[$secKey][] = [
                        'titre'         => trim((string)($photoItem['titre'] ?? 'Sans titre')),
                        'type_document' => trim((string)($photoItem['type_document'] ?? '')),
                        'auteur'        => trim((string)($photoItem['auteur'] ?? '')),
                        'lieu'          => trim((string)($photoItem['lieu'] ?? '')),
                        'date'          => trim((string)($photoItem['date'] ?? '-')),
                        'description'   => trim((string)($photoItem['description'] ?? '-')),
                        'source'        => trim((string)($photoItem['source'] ?? '-')),
                        'file'          => basename(trim((string)($photoItem['file'] ?? $photoItem['filename'] ?? ''))),
                    ];
                }
            }
        }
    }
    $monument['photos_payload'] = $cleanPhotosPayload;

    if (empty($monument['titre'])) {
        flock($fp, LOCK_UN);
        fclose($fp);
        $_SESSION['flash_error'] = "Le titre est obligatoire.";
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'dashboard.php'));
        exit;
    }

    $currentDateTime = date('d/m/Y') . ' à ' . date('H:i:s');
    $userRole = (string)($_SESSION['user_role'] ?? '');
    $linkedMonuments = is_array($_POST['linked_monuments'] ?? null) ? array_values($_POST['linked_monuments']) : [];
    $imagesPost = is_array($_POST['images'] ?? null) ? array_values($_POST['images']) : [];

    if ($id) {
        $foundIndex = null;
        foreach ($monuments as $index => $m) {
            if ((int)($m['id'] ?? 0) === $id) {
                $foundIndex = $index;
                break;
            }
        }

        if ($foundIndex !== null) {
            $isOwnerOrAdmin = ($userRole === 'admin' || (int)($monuments[$foundIndex]['user_id'] ?? 0) === (int)$_SESSION['user_id']);

            if (!$isOwnerOrAdmin) {
                $monument['id'] = $id;
                $monument['images'] = $imagesPost;
                $monument['linked_monuments'] = $linkedMonuments;

                if (!isset($monuments[$foundIndex]['propositions']) || !is_array($monuments[$foundIndex]['propositions'])) {
                    $monuments[$foundIndex]['propositions'] = [];
                }

                $monuments[$foundIndex]['propositions'][] = [
                    'prop_id'       => uniqid('prop_'),
                    'proposer_name' => $_SESSION['username'] ?? 'Contributeur',
                    'date'          => $currentDateTime,
                    'data'          => $monument
                ];

                $encoded = json_encode($monuments, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                if ($encoded !== false) {
                    ftruncate($fp, 0);
                    rewind($fp);
                    fwrite($fp, $encoded);
                }
                flock($fp, LOCK_UN);
                fclose($fp);

                $_SESSION['flash_success'] = "Votre proposition a été envoyée pour validation.";
                header('Location: dashboard.php');
                exit;
            }

            // Mise à jour classique (Propriétaire ou Admin)
            $monument['id'] = $id;
            $monument['user_id'] = $monuments[$foundIndex]['user_id'];
            $monument['date_creation_notice'] = $monuments[$foundIndex]['date_creation_notice'] ?? $currentDateTime;
            $monument['date_modification_notice'] = $currentDateTime;
            $monument['numero_fiche'] = $monuments[$foundIndex]['numero_fiche'] ?? ('REF-' . date('Y') . '-' . strtoupper(substr(uniqid(), -5)));
            $monument['deleted_at'] = $monuments[$foundIndex]['deleted_at'] ?? null;
            $monument['images'] = $imagesPost;
            $monument['linked_monuments'] = $linkedMonuments;
            $monument['propositions'] = $monuments[$foundIndex]['propositions'] ?? [];

            // Versioning
            $oldVersion = $monuments[$foundIndex];
            unset($oldVersion['historique_versions'], $oldVersion['propositions']);
            $monument['historique_versions'] = is_array($monuments[$foundIndex]['historique_versions'] ?? null)
                ? $monuments[$foundIndex]['historique_versions']
                : [];
            array_unshift($monument['historique_versions'], ['date' => $currentDateTime, 'data' => $oldVersion]);
            $monument['historique_versions'] = array_slice($monument['historique_versions'], 0, 5);

            $monuments[$foundIndex] = $monument;
        } else {
            flock($fp, LOCK_UN);
            fclose($fp);
            $_SESSION['flash_error'] = "Monument introuvable.";
            header('Location: dashboard.php');
            exit;
        }
    } else {
        // Création d'une nouvelle fiche
        $maxId = 0;
        foreach ($monuments as $m) {
            if ((int)($m['id'] ?? 0) > $maxId) {
                $maxId = (int)$m['id'];
            }
        }

        $monument['id'] = $maxId + 1;
        $monument['user_id'] = (int)$_SESSION['user_id'];
        $monument['date_creation_notice'] = $currentDateTime;
        $monument['date_modification_notice'] = $currentDateTime;
        $monument['numero_fiche'] = 'REF-' . date('Y') . '-' . strtoupper(substr(uniqid(), -5));
        $monument['deleted_at'] = null;
        $monument['images'] = $imagesPost;
        $monument['linked_monuments'] = $linkedMonuments;
        $monument['historique_versions'] = [];
        $monument['propositions'] = [];

        $monuments[] = $monument;
    }

    $encoded = json_encode($monuments, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($encoded === false) {
        flock($fp, LOCK_UN);
        fclose($fp);
        $_SESSION['flash_error'] = "Erreur d'encodage JSON lors de la sauvegarde.";
        header('Location: dashboard.php');
        exit;
    }

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, $encoded);
    flock($fp, LOCK_UN);
    fclose($fp);
} else {
    $_SESSION['flash_error'] = "Erreur : Impossible de verrouiller le fichier de données.";
    header('Location: dashboard.php');
    exit;
}

$_SESSION['flash_success'] = "Enregistré avec succès.";
header('Location: dashboard.php');
exit;