<?php
require_once __DIR__ . '/personne_config.php';
require_once __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['user_id'], $_SESSION['user_role'])) {
    die('Non connecté.');
}

if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    die('Action non autorisée (Erreur de sécurité CSRF).');
}

$userId               = (int) $_SESSION['user_id'];
$role                 = $_SESSION['user_role'];
$id                   = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$permanent            = !empty($_POST['permanent']);
$restore              = !empty($_POST['restore']);
$emptyTrash           = !empty($_POST['empty_trash']);
$cleanEmptyDuplicates = !empty($_POST['clean_empty_duplicates']);
$proposalAction       = trim($_POST['proposal_action'] ?? '');

[$type, $cfg] = resolvePersonneType($_POST['type'] ?? $_GET['type'] ?? null, $typesConfig);

$defaultRedirect = 'dashboard.php?type=' . urlencode($type);

function ensureItemIds(array &$items): void {
    $maxId = 0;
    $modified = false;
    foreach ($items as $item) {
        if (isset($item['id']) && (int)$item['id'] > $maxId) {
            $maxId = (int)$item['id'];
        }
    }
    foreach ($items as &$item) {
        if (!isset($item['id']) || (int)$item['id'] <= 0) {
            $maxId++;
            $item['id'] = $maxId;
            $modified = true;
        }
    }
    unset($item);
    if ($modified) {
        global $dataFile;
        file_put_contents($dataFile, json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
}

if ($proposalAction === 'approve' || $proposalAction === 'reject') {
    if ($role !== 'admin') {
        $_SESSION['flash_error'] = 'Réservé aux administrateurs.';
        header('Location: ' . ($_SESSION['last_dashboard_url'] ?? $defaultRedirect));
        exit;
    }

    $propId        = trim($_POST['prop_id'] ?? '');
    $proposalsFile = __DIR__ . '/../json/thesaurus_propositions.json';
    $allProposals  = [];

    if (file_exists($proposalsFile)) {
        $rawProps = preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($proposalsFile));
        $decodedProps = json_decode($rawProps, true);
        if (is_array($decodedProps)) {
            $allProposals = $decodedProps;
        }
    }

    $foundProp = null;
    foreach ($allProposals as $p) {
        if (($p['id'] ?? '') === $propId && ($p['proposal_type'] ?? 'term') === 'person') {
            $foundProp = $p;
            break;
        }
    }

    if ($foundProp) {
        if ($proposalAction === 'approve') {
            [$targetType, $targetCfg] = resolvePersonneType((string)($foundProp['metier'] ?? ''), $typesConfig);
            $targetFile = __DIR__ . '/../json/' . $targetCfg['json'];

            $targetItems = [];
            if (file_exists($targetFile)) {
                $rawTarget = preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($targetFile));
                $decodedTarget = json_decode($rawTarget, true);
                if (is_array($decodedTarget)) {
                    $targetItems = $decodedTarget;
                }
            }
            ensureItemIds($targetItems);

            $pOrig      = $foundProp['person_data'] ?? [];
            $nom        = trim($_POST['nom'] ?? ($pOrig['nom'] ?? ''));
            $prenom     = trim($_POST['prenom'] ?? ($pOrig['prenom'] ?? ''));
            $sexe       = trim($_POST['sexe'] ?? ($pOrig['sexe'] ?? ''));
            $biographie = trim($_POST['biographie'] ?? ($pOrig['biographie'] ?? ''));
            $permalien  = trim($_POST['permalien'] ?? ($pOrig['permalien'] ?? ($pOrig['source'] ?? '')));

            if (!in_array($sexe, ['', 'Homme', 'Femme'], true)) {
                $sexe = '';
            }

            $naissance = [
                'annee' => trim($_POST['naissance']['annee'] ?? ($pOrig['naissance_annee'] ?? ($pOrig['naissance']['annee'] ?? ''))),
                'lieu'  => trim($_POST['naissance']['lieu'] ?? ($pOrig['naissance_lieu'] ?? ($pOrig['naissance']['lieu'] ?? ''))),
                'pays'  => trim($_POST['naissance']['pays'] ?? ($pOrig['naissance_pays'] ?? ($pOrig['naissance']['pays'] ?? '')))
            ];

            $deces = [
                'annee' => trim($_POST['deces']['annee'] ?? ($pOrig['deces_annee'] ?? ($pOrig['deces']['annee'] ?? ''))),
                'lieu'  => trim($_POST['deces']['lieu'] ?? ($pOrig['deces_lieu'] ?? ($pOrig['deces']['lieu'] ?? ''))),
                'pays'  => trim($_POST['deces']['pays'] ?? ($pOrig['deces_pays'] ?? ($pOrig['deces']['pays'] ?? '')))
            ];

            $datesStd = trim($_POST['dates'] ?? ($pOrig['dates'] ?? ''));
            $lieuStd  = trim($_POST['lieu'] ?? ($pOrig['lieu'] ?? ''));
            if ($naissance['lieu'] === '' && $lieuStd !== '') {
                $naissance['lieu'] = $lieuStd;
            }

            if ($nom !== '') {
                $maxId = 0;
                foreach ($targetItems as $ti) {
                    if (isset($ti['id']) && (int)$ti['id'] > $maxId) {
                        $maxId = (int)$ti['id'];
                    }
                }

                $now = date('d/m/Y à H:i:s');
                $newPerson = [
                    'id'                       => $maxId + 1,
                    'nom'                      => $nom,
                    'prenom'                   => $prenom,
                    'sexe'                     => $sexe,
                    'naissance'                => $naissance,
                    'deces'                    => $deces,
                    'biographie'               => $biographie,
                    'permalien'                => $permalien,
                    'numero_fiche'             => $targetCfg['prefix'] . '-' . date('Y') . '-' . strtoupper(substr(uniqid(), -5)),
                    'date_creation_notice'     => $now,
                    'date_modification_notice' => $now,
                    'user_id'                  => (int)($foundProp['user_id'] ?? $userId)
                ];

                if ($datesStd !== '') {
                    $newPerson['dates'] = $datesStd;
                }
                if ($lieuStd !== '') {
                    $newPerson['lieu'] = $lieuStd;
                }

                $targetItems[] = $newPerson;
                file_put_contents($targetFile, json_encode($targetItems, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

                $_SESSION['flash_success'] = 'Fiche ' . $targetCfg['label'] . ' « ' . trim($nom . ' ' . $prenom) . ' » validée et ajoutée.';
            }
        } else {
            $_SESSION['flash_success'] = 'Proposition d\'auteur refusée et supprimée.';
        }

        $allProposals = array_values(array_filter($allProposals, fn($p) => ($p['id'] ?? '') !== $propId));
        file_put_contents($proposalsFile, json_encode($allProposals, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    header('Location: ' . ($_SESSION['last_dashboard_url'] ?? $defaultRedirect));
    exit;
}

$dataFile = __DIR__ . '/../json/' . $cfg['json'];
$items = [];
if (file_exists($dataFile)) {
    $content = file_get_contents($dataFile);
    if ($content !== false && trim($content) !== '') {
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            $items = $decoded;
        }
    }
}
ensureItemIds($items);

if ($cleanEmptyDuplicates) {
    if ($role !== 'admin') {
        $_SESSION['flash_error'] = 'Réservé aux administrateurs.';
    } else {
        $now           = date('Y-m-d H:i:s');
        $deletedCount  = 0;
        $groupedByName = [];

        foreach ($items as $index => $s) {
            if (empty($s['deleted_at'])) {
                $nom = trim(mb_strtolower($s['nom'] ?? '', 'UTF-8'));
                if ($nom !== '') {
                    $groupedByName[$nom][] = $index;
                }
            }
        }

        foreach ($groupedByName as $indices) {
            if (count($indices) > 1) {
                $scores = [];
                foreach ($indices as $index) {
                    $s     = $items[$index];
                    $score = 0;
                    if (trim($s['prenom'] ?? '') !== '') $score++;
                    if (trim($s['naissance']['annee'] ?? '') !== '') $score++;
                    if (trim($s['naissance']['lieu'] ?? '') !== '') $score++;
                    if (trim($s['deces']['annee'] ?? '') !== '') $score++;
                    if (trim($s['deces']['lieu'] ?? '') !== '') $score++;
                    if (trim($s['biographie'] ?? '') !== '') $score++;
                    if (trim($s['permalien'] ?? '') !== '' && trim($s['permalien'] ?? '') !== 'N/A') $score++;
                    $scores[$index] = $score;
                }

                arsort($scores);
                $bestIndex = array_key_first($scores);

                foreach ($scores as $index => $score) {
                    if ($index !== $bestIndex && $score === 0) {
                        $items[$index]['deleted_at'] = $now;
                        $deletedCount++;
                    }
                }
            }
        }

        if ($deletedCount > 0) {
            file_put_contents($dataFile, json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
            $_SESSION['flash_success'] = "$deletedCount doublon(s) vide(s) mis à la corbeille.";
        } else {
            $_SESSION['flash_success'] = 'Aucun doublon vide détecté.';
        }
    }

    header('Location: ' . ($_SESSION['last_dashboard_url'] ?? $defaultRedirect));
    exit;
}

if ($emptyTrash) {
    if ($role !== 'admin') {
        $_SESSION['flash_error'] = 'Réservé aux administrateurs.';
    } else {
        $items = array_values(array_filter($items, fn($s) => empty($s['deleted_at'])));
        file_put_contents($dataFile, json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        $_SESSION['flash_success'] = 'La corbeille a été vidée avec succès.';
    }

    header('Location: ' . ($_SESSION['last_dashboard_url'] ?? $defaultRedirect . '&deleted_filter=1'));
    exit;
}

if (!$id) {
    $_SESSION['flash_error'] = 'ID invalide.';
    header('Location: ' . ($_SESSION['last_dashboard_url'] ?? $defaultRedirect));
    exit;
}

$foundIndex = null;
foreach ($items as $index => $s) {
    if ((int)($s['id'] ?? 0) === $id) {
        $foundIndex = $index;
        break;
    }
}

if ($foundIndex !== null) {
    if ($role !== 'admin' && (int)($items[$foundIndex]['user_id'] ?? 0) !== $userId) {
        $_SESSION['flash_error'] = 'Autorisation refusée.';
        header('Location: ' . ($_SESSION['last_dashboard_url'] ?? $defaultRedirect));
        exit;
    }

    if ($permanent) {
        if ($role !== 'admin') {
            $_SESSION['flash_error'] = 'Réservé aux administrateurs.';
        } else {
            array_splice($items, $foundIndex, 1);
            $_SESSION['flash_success'] = 'Supprimé définitivement.';
        }
    } elseif ($restore) {
        $items[$foundIndex]['deleted_at'] = null;
        $_SESSION['flash_success'] = 'Restauré avec succès.';
    } else {
        $items[$foundIndex]['deleted_at'] = date('Y-m-d H:i:s');
        $_SESSION['flash_success'] = 'Mis à la corbeille.';
    }

    file_put_contents($dataFile, json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

header('Location: ' . ($_SESSION['last_dashboard_url'] ?? $defaultRedirect));
exit;