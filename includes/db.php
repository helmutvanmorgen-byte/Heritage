<?php
/**
 * Fichier de configuration et d'initialisation de la base de données (JSON)
 */

// Sécurité : Empêcher l'accès direct au fichier si besoin, ou démarrer la session proprement
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Définition du fuseau horaire par défaut
date_default_timezone_set('Europe/Paris');

// Chemins absolus utiles pour les fichiers JSON
define('JSON_DIR', __DIR__ . '/../json/');
define('UPLOADS_DIR', __DIR__ . '/../uploads/');

// Vérification et création automatique des dossiers de stockage JSON s'ils n'existent pas
if (!is_dir(JSON_DIR)) {
    mkdir(JSON_DIR, 0777, true);
}

if (!is_dir(UPLOADS_DIR)) {
    mkdir(UPLOADS_DIR, 0777, true);
}