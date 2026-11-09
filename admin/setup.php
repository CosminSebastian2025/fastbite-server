<?php
require __DIR__ . '/inc.php';

$dbError = '';
$configured = false;
$created = false;
$errors = array();
$name = '';
$email = '';

try {
    $pdo = admin_db();
    $pdo->exec('CREATE TABLE IF NOT EXISTS admin_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        email VARCHAR(190) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $count = (int)$pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
    $configured = $count > 0;
} catch (Throwable $e) {
    error_log('FastBite admin setup: ' . $e->getMessage());
    $dbError = 'Connessione al database non riuscita. Controlla le credenziali in inc.php.';
    $pdo = null;
}

if ($pdo && !$configured && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name = post_value('name');
    $email = strtolower(post_value('email'));
    $password = isset($_POST['password']) ? (string)$_POST['password'] : '';
    $confirm = isset($_POST['password_confirm']) ? (string)$_POST['password_confirm'] : '';

    if ($name === '') {
        $errors[] = 'Inserisci il nome dell\'amministratore.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Inserisci un indirizzo email valido.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'La password deve contenere almeno 8 caratteri.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Le due password non coincidono.';
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare('SELECT id FROM admin_users WHERE email = ?');
            $stmt->execute(array($email));
            if ($stmt->fetch()) {
                $errors[] = 'Esiste già un amministratore con questa email.';
            } else {
                $stmt = $pdo->prepare('INSERT INTO admin_users (name, email, password) VALUES (?, ?, ?)');
                $stmt->execute(array($name, $email, password_hash($password, PASSWORD_DEFAULT)));
                $created = true;
                $configured = true;
            }
        } catch (Throwable $e) {
            error_log('FastBite admin setup insert: ' . $e->getMessage());
            $errors[] = 'Impossibile creare l\'amministratore. Riprova.';
        }
    }
}
?>
<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Prima configurazione · FastBite Admin</title>
    <link rel="stylesheet" href="assets/admin.css">
</head>
<body class="auth-body">
<div class="auth-card wide">
    <div class="auth-brand">🍔 FastBite <span>Admin</span></div>

    <?php if ($dbError !== ''): ?>
        <div class="alert error"><?php echo h($dbError); ?></div>
        <p class="muted">Apri <code>inc.php</code> e verifica host, nome database, utente e password MySQL.</p>
    <?php elseif ($created): ?>
        <div class="alert ok">Amministratore creato. Ora puoi accedere.</div>
        <p class="muted">Per sicurezza elimina <code>setup.php</code> dal server.</p>
        <a class="btn primary" href="login.php">Vai al login</a>
    <?php elseif ($configured): ?>
        <div class="alert ok">Pannello già configurato.</div>
        <p class="muted">Esiste già un amministratore. Elimina <code>setup.php</code> dal server.</p>
        <a class="btn primary" href="login.php">Vai al login</a>
    <?php else: ?>
        <p class="muted">Crea il primo account amministratore. Questa pagina funziona una sola volta.</p>

        <?php foreach ($errors as $error): ?>
            <div class="alert error"><?php echo h($error); ?></div>
        <?php endforeach; ?>

        <form method="post" action="setup.php" class="stack">
            <?php echo csrf_field(); ?>
            <label>Nome
                <input type="text" name="name" value="<?php echo h($name); ?>" required>
            </label>
            <label>Email
                <input type="email" name="email" value="<?php echo h($email); ?>" required>
            </label>
            <label>Password (almeno 8 caratteri)
                <input type="password" name="password" required>
            </label>
            <label>Conferma password
                <input type="password" name="password_confirm" required>
            </label>
            <button type="submit" class="btn primary">Crea amministratore</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
