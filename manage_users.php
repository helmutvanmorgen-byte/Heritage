<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    die("Accès refusé. Seul un administrateur peut accéder à cette page.");
}

$usersFile = __DIR__ . '/json/users.json';
$users = file_exists($usersFile) ? json_decode(file_get_contents($usersFile), true) : [];

// Ajouter un utilisateur
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $newId = empty($users) ? 1 : max(array_column($users, 'id')) + 1;
    $users[] = [
        'id' => $newId,
        'username' => trim($_POST['username']),
        'password' => password_hash($_POST['password'], PASSWORD_DEFAULT),
        'role' => $_POST['role']
    ];
    file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
    $_SESSION['flash_success'] = "Utilisateur créé avec succès.";
    header('Location: manage_users.php');
    exit;
}

// Supprimer un utilisateur
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $deleteId = (int)$_GET['delete'];
    if ($deleteId !== $_SESSION['user_id']) { // Empêche de s'auto-supprimer
        $users = array_filter($users, fn($u) => $u['id'] !== $deleteId);
        file_put_contents($usersFile, json_encode(array_values($users), JSON_PRETTY_PRINT));
        $_SESSION['flash_success'] = "Utilisateur supprimé.";
    }
    header('Location: manage_users.php');
    exit;
}

ob_start();
?>
<div class="content-3 container" style="max-width: 800px; margin: auto;">
    <h2>Gestion des comptes utilisateurs</h2>
    
    <?php if(!empty($_SESSION['flash_success'])): ?>
        <div class="alert alert-success"><?= htmlspecialchars($_SESSION['flash_success']) ?></div>
        <?php unset($_SESSION['flash_success']); ?>
    <?php endif; ?>

    <div class="card" style="margin-bottom: 2rem;">
        <h3>Créer un nouveau compte</h3>
        <form method="POST" style="display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end;">
            <input type="hidden" name="action" value="add">
            <div style="flex:1;"><label>Identifiant :</label><input type="text" name="username" required></div>
            <div style="flex:1;"><label>Mot de passe :</label><input type="password" name="password" required></div>
            <div style="flex:1;"><label>Rôle :</label>
                <select name="role" required>
                    <option value="invite">Invité (Lecture seule)</option>
                    <option value="contributeur">Contributeur (Crée et gère ses fiches)</option>
                    <option value="admin">Administrateur (Contrôle total)</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Créer</button>
        </form>
    </div>

    <div class="card">
        <h3>Comptes existants</h3>
        <table style="width: 100%; border-collapse: collapse;">
            <tr style="background: #f4f6f8; text-align: left;">
                <th style="padding: 10px; border-bottom: 2px solid #ddd;">ID</th>
                <th style="padding: 10px; border-bottom: 2px solid #ddd;">Identifiant</th>
                <th style="padding: 10px; border-bottom: 2px solid #ddd;">Rôle</th>
                <th style="padding: 10px; border-bottom: 2px solid #ddd;">Action</th>
            </tr>
            <?php foreach ($users as $u): ?>
            <tr>
                <td style="padding: 10px; border-bottom: 1px solid #eee;"><?= $u['id'] ?></td>
                <td style="padding: 10px; border-bottom: 1px solid #eee;"><strong><?= htmlspecialchars($u['username']) ?></strong></td>
                <td style="padding: 10px; border-bottom: 1px solid #eee;">
                    <span class="badge <?= $u['role'] === 'admin' ? 'badge-primary' : 'badge-secondary' ?>"><?= $u['role'] ?></span>
                </td>
                <td style="padding: 10px; border-bottom: 1px solid #eee;">
                    <?php if ($u['id'] !== $_SESSION['user_id']): ?>
                        <a href="manage_users.php?delete=<?= $u['id'] ?>" class="btn btn-danger" style="padding: 4px 8px; font-size: 0.8rem;" onclick="return confirm('Supprimer ce compte ?');">Supprimer</a>
                    <?php else: ?>
                        <span style="color: #999; font-size: 0.85rem;">(Vous)</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/includes/layout.php';
?>