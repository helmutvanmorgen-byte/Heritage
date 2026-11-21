<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit;
}

$q = strtolower(trim($_GET['q'] ?? ''));
if ($q === '') {
    echo json_encode([]);
    exit;
}

$dataFile = __DIR__ . '/../json/monuments.json';
$monuments = file_exists($dataFile) ? json_decode(file_get_contents($dataFile), true) : [];

$results = [];
foreach ($monuments as $m) {
    if (!empty($m['deleted_at'])) continue;
    
    $titres = $m['titre'] ?? [];
    if (!is_array($titres)) $titres = [$titres];
    
    foreach ($titres as $t) {
        if (strpos(strtolower($t), $q) !== false) {
            $results[] = [
                'id' => $m['id'],
                'titre' => $t
            ];
            break; // On a trouvé une correspondance pour ce monument, on passe au suivant
        }
    }
    if (count($results) >= 10) break; // Limite à 10 résultats comme en SQL
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($results);
?>