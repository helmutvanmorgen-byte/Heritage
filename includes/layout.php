<?php
// Déclaration centralisée des feuilles de style (remplacez le 2e nom par le vôtre)
$stylesheets = [
    '/css/style.css',
    '/css/style_metier.css',
];

// Ajout ponctuel d'une feuille spécifique si une page définit $extraCss
if (!empty($extraCss)) {
    foreach ((array) $extraCss as $cssFile) {
        if (!in_array($cssFile, $stylesheets, true)) {
            $stylesheets[] = $cssFile;
        }
    }
}

require_once __DIR__ . '/header.php';
echo $content ?? '';
require_once __DIR__ . '/footer.php';