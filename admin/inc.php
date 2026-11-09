<?php
/**
 * FastBite Admin - configurazione, connessione DB e helper condivisi.
 * PHP 7.1+ / MySQL-MariaDB
 *
 * Credenziali di default XAMPP. Se il tuo MySQL usa altre credenziali
 * modifica le costanti qui sotto (oppure imposta le variabili d'ambiente).
 */

define('ADMIN_DB_HOST', getenv('FASTBITE_DB_HOST') !== false ? getenv('FASTBITE_DB_HOST') : '127.0.0.1');
define('ADMIN_DB_PORT', getenv('FASTBITE_DB_PORT') !== false ? (int)getenv('FASTBITE_DB_PORT') : 3306);
define('ADMIN_DB_NAME', getenv('FASTBITE_DB_NAME') !== false ? getenv('FASTBITE_DB_NAME') : 'AppFood');
define('ADMIN_DB_USER', getenv('FASTBITE_DB_USER') !== false ? getenv('FASTBITE_DB_USER') : 'root');
define('ADMIN_DB_PASS', getenv('FASTBITE_DB_PASS') !== false ? getenv('FASTBITE_DB_PASS') : '');

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

if (session_status() === PHP_SESSION_NONE) {
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params(array(
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ));
    } else {
        session_set_cookie_params(0, '/', '', false, true);
    }
    session_name('fastbite_admin');
    session_start();
}

/** Connessione PDO condivisa. */
function admin_db()
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . ADMIN_DB_HOST . ';port=' . ADMIN_DB_PORT
            . ';dbname=' . ADMIN_DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, ADMIN_DB_USER, ADMIN_DB_PASS, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ));
    }
    return $pdo;
}

/** Escape per l'output HTML. */
function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect($url)
{
    header('Location: ' . $url);
    exit;
}

function post_value($key, $default = '')
{
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

function get_value($key, $default = '')
{
    return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
}

function csrf_token()
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field()
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function csrf_check()
{
    $sent = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    if ($sent === '' || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
        http_response_code(400);
        exit('Richiesta non valida (CSRF). Ricarica la pagina e riprova.');
    }
}

function flash_set($type, $message)
{
    $_SESSION['flash'] = array('type' => $type, 'message' => $message);
}

function flash_render()
{
    if (empty($_SESSION['flash'])) {
        return;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    $type = $flash['type'] === 'error' ? 'error' : 'ok';
    echo '<div class="alert ' . $type . '">' . h($flash['message']) . '</div>';
}

function current_admin()
{
    return isset($_SESSION['admin']) ? $_SESSION['admin'] : null;
}

function require_admin()
{
    if (!current_admin()) {
        redirect('login.php');
    }
}

function money($value)
{
    return '€ ' . number_format((float)$value, 2, ',', '.');
}

function format_date($value)
{
    if (empty($value)) {
        return '—';
    }
    $ts = strtotime((string)$value);
    return $ts ? date('d/m/Y H:i', $ts) : (string)$value;
}

function payment_label($method)
{
    $method = (string)$method;
    if ($method === 'CARTA_DI_CREDITO' || $method === 'card' || $method === 'CARD') {
        return 'Carta di credito';
    }
    if ($method === 'CASH' || $method === 'cash') {
        return 'Contanti al ritiro';
    }
    if ($method === 'PAYPAL' || $method === 'paypal') {
        return 'PayPal';
    }
    return $method !== '' ? $method : '—';
}

function status_class($status)
{
    $s = strtolower((string)$status);
    if (strpos($s, 'complet') !== false || $s === 'completed') {
        return 'badge ok';
    }
    if (strpos($s, 'annull') !== false || strpos($s, 'cancel') !== false) {
        return 'badge danger';
    }
    if (strpos($s, 'pront') !== false) {
        return 'badge info';
    }
    return 'badge warn';
}

/** Riallinea il conteggio prodotti per categoria (usato dall'app). */
function recalc_category_counts(PDO $pdo)
{
    $pdo->exec('UPDATE categories c SET c.count = (
        SELECT COUNT(*) FROM products p
        WHERE p.category = c.nameCategory OR p.category = c.idCategory
    )');
}

/** Intestazione comune delle pagine del pannello. */
function admin_header($title, $active)
{
    $admin = current_admin();
    ?>
<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo h($title); ?> · FastBite Admin</title>
    <link rel="stylesheet" href="assets/admin.css">
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <div class="brand">🍔 FastBite <span>Admin</span></div>
        <nav>
            <a class="<?php echo $active === 'dashboard' ? 'active' : ''; ?>" href="index.php">Dashboard</a>
            <a class="<?php echo $active === 'products' ? 'active' : ''; ?>" href="products.php">Prodotti</a>
            <a class="<?php echo $active === 'categories' ? 'active' : ''; ?>" href="categories.php">Categorie</a>
            <a class="<?php echo $active === 'orders' ? 'active' : ''; ?>" href="orders.php">Ordini</a>
        </nav>
        <div class="side-foot">
            <div class="who">
                <?php echo h($admin['name']); ?><br>
                <small><?php echo h($admin['email']); ?></small>
            </div>
            <form method="post" action="logout.php">
                <?php echo csrf_field(); ?>
                <button type="submit" class="link">Esci</button>
            </form>
        </div>
    </aside>
    <main class="main">
        <header class="topbar"><h1><?php echo h($title); ?></h1></header>
        <div class="content">
            <?php flash_render(); ?>
    <?php
}

function admin_footer()
{
    ?>
        </div>
    </main>
</div>
</body>
</html>
    <?php
}
