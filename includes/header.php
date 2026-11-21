<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!defined('SITE_NAME')) {
    define('SITE_NAME', 'Patrimoine Monumental');
}

$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(SITE_NAME) ?></title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <?php foreach ($stylesheets ?? [] as $cssHref): ?>
        <link rel="stylesheet" href="<?= htmlspecialchars($cssHref) ?>">
    <?php endforeach; ?>
</head>
<body>
<header>
    <div class="header-container">
        <div class="header-title-wrapper">
            <a href="/index.php" class="header-title"><?= htmlspecialchars(SITE_NAME) ?></a>
        </div>

        <nav class="menu" aria-label="Navigation principale">
            <a href="/index.php" class="<?= $currentPath === '/index.php' || $currentPath === '/' ? 'active' : '' ?>">Accueil</a>
            <a href="/search.php" class="<?= $currentPath === '/search.php' ? 'active' : '' ?>">Recherche Avancée</a>

            <?php if (isset($_SESSION['user_id'])): ?>
                <a href="/inventaire_session/dashboard.php" class="<?= strpos($currentPath, '/inventaire_session/') === 0 && $currentPath !== '/inventaire_session/gestion_thesaurus.php' ? 'active' : '' ?>">Tableau de bord</a>
                <a href="/personne/dashboard.php" class="<?= strpos($currentPath, '/personne/') === 0 ? 'active' : '' ?>">Personnes</a>

                <?php if (($_SESSION['user_role'] ?? '') === 'admin'): ?>
                    <a href="/manage_users.php" class="<?= $currentPath === '/manage_users.php' ? 'active' : '' ?>">Utilisateurs</a>
                    <a href="/inventaire_session/gestion_thesaurus.php" class="<?= $currentPath === '/inventaire_session/gestion_thesaurus.php' ? 'active' : '' ?>">Thésaurus</a>
                <?php endif; ?>

                <div class="header-user">
                    <span class="user-badge">
                        Connecté : <strong><?= htmlspecialchars($_SESSION['username'] ?? '') ?></strong> (<?= htmlspecialchars($_SESSION['user_role'] ?? '') ?>)
                    </span>
                    <a href="/logout.php" class="nav-logout">Déconnexion</a>
                </div>
            <?php else: ?>
                <div class="header-user">
                    <a href="/login.php" class="<?= $currentPath === '/login.php' ? 'active' : '' ?>">Connexion</a>
                </div>
            <?php endif; ?>
        </nav>
    </div>
</header>
<main>