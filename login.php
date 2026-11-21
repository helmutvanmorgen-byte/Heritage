<?php
session_start();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $usersFile = __DIR__ . '/json/users.json';
    $users = file_exists($usersFile) ? json_decode(file_get_contents($usersFile), true) : [];

    $authenticatedUser = null;
    foreach ($users as $user) {
        if ($user['username'] === $username && password_verify($password, $user['password'])) {
            $authenticatedUser = $user;
            break;
        }
    }

    if ($authenticatedUser) {
        $_SESSION['user_id'] = $authenticatedUser['id'];
        $_SESSION['username'] = $authenticatedUser['username'];
        $_SESSION['user_role'] = $authenticatedUser['role'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        
        // Redirection corrigée vers le sous-dossier exact
        header('Location: /inventaire_session/dashboard.php');
        exit;
    } else {
        $error = "Identifiant ou mot de passe incorrect.";
    }
}

ob_start();
?>
<div style="max-width: 400px; margin: 40px auto;" class="card">
    <h2>Connexion</h2>
    <?php if ($error): ?>
        <p style="color: red;"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>
    <form method="POST">
        <div style="margin-bottom: 15px;">
            <label style="display: block; margin-bottom: 5px;">Nom d'utilisateur :</label>
            <input type="text" name="username" required style="width: 100%; padding: 8px; box-sizing: border-box;">
        </div>
        <div style="margin-bottom: 15px;">
            <label style="display: block; margin-bottom: 5px;">Mot de passe :</label>
            <input type="password" name="password" required style="width: 100%; padding: 8px; box-sizing: border-box;">
        </div>
        <button type="submit" class="btn btn-primary" style="width: 100%;">Se connecter</button>
    </form>
    <p style="margin-top: 15px; font-size: 0.85rem; color: #666;">
        Comptes de test (Mot de passe pour tous : <code>password</code>) :<br>
        - Admin : <code>admin</code><br>
        - Contributeur : <code>contributeur</code><br>
        - Invité : <code>invite</code>
    </p>
</div>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/includes/layout.php';
?>