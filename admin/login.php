<?php
require __DIR__ . '/inc.php';

if (current_admin()) {
    redirect('index.php');
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = strtolower(post_value('email'));
    $password = isset($_POST['password']) ? (string)$_POST['password'] : '';

    $lockedUntil = isset($_SESSION['login_locked_until']) ? (int)$_SESSION['login_locked_until'] : 0;
    if ($lockedUntil > time()) {
        $error = 'Troppi tentativi. Riprova tra ' . (int)ceil(($lockedUntil - time()) / 60) . ' minuti.';
    } elseif ($email === '' || $password === '') {
        $error = 'Inserisci email e password.';
    } else {
        try {
            $stmt = admin_db()->prepare('SELECT id, name, email, password FROM admin_users WHERE email = ?');
            $stmt->execute(array($email));
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
                    $update = admin_db()->prepare('UPDATE admin_users SET password = ? WHERE id = ?');
                    $update->execute(array(password_hash($password, PASSWORD_DEFAULT), $user['id']));
                }
                unset($_SESSION['login_attempts'], $_SESSION['login_locked_until']);
                session_regenerate_id(true);
                $_SESSION['admin'] = array(
                    'id' => (int)$user['id'],
                    'name' => $user['name'],
                    'email' => $user['email'],
                );
                redirect('index.php');
            }

            $attempts = isset($_SESSION['login_attempts']) ? (int)$_SESSION['login_attempts'] : 0;
            $attempts++;
            $_SESSION['login_attempts'] = $attempts;
            if ($attempts >= 5) {
                $_SESSION['login_locked_until'] = time() + 300;
                $_SESSION['login_attempts'] = 0;
                $error = 'Troppi tentativi. Riprova tra 5 minuti.';
            } else {
                $error = 'Email o password non validi.';
            }
        } catch (Throwable $e) {
            error_log('FastBite admin login: ' . $e->getMessage());
            $error = 'Errore di connessione al database.';
        }
    }
}
?>
<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Accedi · FastBite Admin</title>
    <link rel="stylesheet" href="assets/admin.css">
</head>
<body class="auth-body">
<div class="auth-card">
    <div class="auth-brand">🍔 FastBite <span>Admin</span></div>
    <p class="muted">Accedi per gestire prodotti, categorie e ordini.</p>

    <?php if ($error !== ''): ?>
        <div class="alert error"><?php echo h($error); ?></div>
    <?php endif; ?>

    <form method="post" action="login.php" class="stack">
        <?php echo csrf_field(); ?>
        <label>Email
            <input type="email" name="email" value="<?php echo h($email); ?>" required autofocus>
        </label>
        <label>Password
            <input type="password" name="password" required>
        </label>
        <button type="submit" class="btn primary">Accedi</button>
    </form>
</div>
</body>
</html>
