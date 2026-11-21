<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Session expirée ou non autorisée.']);
    exit;
}

$rawUrl = trim($_GET['url'] ?? '');

// Extraction de l'identifiant ARK et du folio depuis une URL Gallica (native ou IIIF)
if (!preg_match('#^https?://gallica\.bnf\.fr/(?:iiif/)?(ark:/\d+/[a-zA-Z0-9_-]+)(?:/(f\d+))?#i', $rawUrl, $matches)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'URL Gallica invalide.']);
    exit;
}

$arkId = $matches[1];
$folio = !empty($matches[2]) ? strtolower($matches[2]) : 'f1';

// URL IIIF native en priorité, puis repli automatique si Gallica bride /full/full/ (fréquent sur btv1b)
$candidateUrls = [
    "https://gallica.bnf.fr/iiif/{$arkId}/{$folio}/full/full/0/native.jpg",
    "https://gallica.bnf.fr/iiif/{$arkId}/{$folio}/full/full/0/default.jpg",
    "https://gallica.bnf.fr/iiif/{$arkId}/{$folio}/full/2000,/0/default.jpg",
    "https://gallica.bnf.fr/{$arkId}/{$folio}.highres"
];

function downloadGallicaImage(string $url, int &$lastHttpCode = 0, string &$lastError = ''): ?array {
    $headers = [
        'Accept: image/avif,image/webp,image/apng,image/jpeg,image/*,*/*;q=0.8',
        'Accept-Language: fr-FR,fr;q=0.9,en;q=0.8',
        'Referer: https://gallica.bnf.fr/'
    ];
    $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    $data = false;
    $contentType = 'image/jpeg';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT      => $userAgent,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        $data = curl_exec($ch);
        $lastHttpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $detectedType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        if ($detectedType) {
            $contentType = trim(explode(';', $detectedType)[0]);
        }
        if ($data === false) {
            $lastError = curl_error($ch);
        }
        curl_close($ch);

        if ($lastHttpCode !== 200) {
            $data = false;
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'timeout'         => 45,
                'follow_location' => 1,
                'header'          => "User-Agent: {$userAgent}\r\n" . implode("\r\n", $headers) . "\r\n"
            ]
        ]);
        $data = @file_get_contents($url, false, $context);
        if ($data === false) {
            $lastError = 'Échec file_get_contents (allow_url_fopen désactivé ou erreur réseau).';
        }
    }

    if ($data === false || strlen($data) < 200) {
        return null;
    }

    // Vérification de la signature binaire pour s'assurer qu'il s'agit bien d'une image et non d'une page HTML
    $isJpeg = (strncmp($data, "\xFF\xD8\xFF", 3) === 0);
    $isPng  = (strncmp($data, "\x89PNG\r\n\x1A\n", 8) === 0);
    $isGif  = (strncmp($data, "GIF8", 4) === 0);
    $isWebp = (substr($data, 0, 4) === 'RIFF' && substr($data, 8, 4) === 'WEBP');

    if (!$isJpeg && !$isPng && !$isGif && !$isWebp) {
        return null;
    }

    if ($isPng) $contentType = 'image/png';
    elseif ($isWebp) $contentType = 'image/webp';
    elseif ($isGif) $contentType = 'image/gif';
    else $contentType = 'image/jpeg';

    return ['data' => $data, 'contentType' => $contentType];
}

$lastHttpCode = 0;
$lastError = '';
$result = null;

foreach ($candidateUrls as $candidateUrl) {
    $result = downloadGallicaImage($candidateUrl, $lastHttpCode, $lastError);
    if ($result !== null) {
        break;
    }
}

if ($result === null) {
    http_response_code(502);
    header('Content-Type: application/json; charset=utf-8');
    $detail = $lastHttpCode ? " (Code HTTP Gallica : {$lastHttpCode})" : ($lastError ? " ({$lastError})" : '');
    echo json_encode([
        'success' => false,
        'message' => "Impossible de récupérer l'image depuis Gallica{$detail}. Vérifiez que le document et le folio existent."
    ]);
    exit;
}

header('Content-Type: ' . $result['contentType']);
header('Cache-Control: public, max-age=3600');
echo $result['data'];