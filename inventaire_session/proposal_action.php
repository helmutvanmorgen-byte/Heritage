<?php
session_start();
require_once __DIR__ . '/includes/db.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit("Non connecté.");
}

$id = (int)($_GET['id'] ?? 0);
$propId = $_GET['prop_id'] ?? '';
$action = $_GET['action'] ?? ''; // 'accept' ou 'reject'

$dataFile = __DIR__ . '/json/monuments.json';
$fp = fopen($dataFile, 'c+');

if ($fp && flock($fp, LOCK_EX)) {
    $size = filesize($dataFile);
    $monuments = json_decode($size > 0 ? fread($fp, $size) : '[]', true) ?: [];

    $foundIndex = null;
    foreach ($monuments as $index => $m) {
        if ((int)$m['id'] === $id) { 
            $foundIndex = $index; 
            break; 
        }
    }

    if ($foundIndex !== null) {
        $isOwnerOrAdmin = ($_SESSION['user_role'] === 'admin' || (int)$monuments[$foundIndex]['user_id'] === (int)$_SESSION['user_id']);
        
        if ($isOwnerOrAdmin && isset($monuments[$foundIndex]['propositions'])) {
            foreach ($monuments[$foundIndex]['propositions'] as $pIndex => $p) {
                if ($p['prop_id'] === $propId) {
                    if ($action === 'accept') {
                        $newData = $p['data']; // Données modifiées par le contributeur
                        
                        // Sécurisation des champs vitaux
                        $newData['id'] = $monuments[$foundIndex]['id'];
                        $newData['user_id'] = $monuments[$foundIndex]['user_id'];
                        $newData['date_creation_notice'] = $monuments[$foundIndex]['date_creation_notice'];
                        $newData['numero_fiche'] = $monuments[$foundIndex]['numero_fiche'];
                        $newData['historique_versions'] = $monuments[$foundIndex]['historique_versions'] ?? [];
                        
                        // Archivage de la version actuelle avant remplacement
                        $oldVersion = $monuments[$foundIndex];
                        unset($oldVersion['historique_versions'], $oldVersion['propositions']);
                        
                        array_unshift($newData['historique_versions'], [
                            'date' => date('d/m/Y H:i:s'), 
                            'data' => $oldVersion
                        ]);
                        $newData['historique_versions'] = array_slice($newData['historique_versions'], 0, 5);
                        
                        $monuments[$foundIndex] = $newData;
                        $_SESSION['flash_success'] = "La proposition a été acceptée et appliquée à la notice.";
                    } else {
                        $_SESSION['flash_success'] = "La proposition a été refusée.";
                    }
                    
                    // Suppression de la proposition traitée
                    unset($monuments[$foundIndex]['propositions'][$pIndex]);
                    $monuments[$foundIndex]['propositions'] = array_values($monuments[$foundIndex]['propositions']);
                    break;
                }
            }
        }
    }
    
    ftruncate($fp, 0); 
    rewind($fp);
    fwrite($fp, json_encode($monuments, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    flock($fp, LOCK_UN); 
    fclose($fp);
}

header('Location: dashboard.php');
exit;