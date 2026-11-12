<?php
/**
 * FastBite Admin - configurazione, connessione DB e helper condivisi.
 * PHP 7.1+ / MySQL-MariaDB
 *
 * Le credenziali arrivano da config/database.php: per cambiarle crea
 * config/database.local.php oppure imposta le variabili FASTBITE_DB_*.
 */

// Configurazione MySQL condivisa con l'API (override in config/database.local.php).
$dbConfig = require dirname(__DIR__) . '/config/database.php';
define('ADMIN_DB_HOST', $dbConfig['host']);
define('ADMIN_DB_PORT', $dbConfig['port']);
define('ADMIN_DB_NAME', $dbConfig['name']);
define('ADMIN_DB_USER', $dbConfig['user']);
define('ADMIN_DB_PASS', $dbConfig['pass']);

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

/** La migrazione multi-categoria è stata eseguita? (cache per richiesta) */
function admin_has_multi_category(PDO $pdo)
{
    static $has = null;
    if ($has === null) {
        try {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = ?'
            );
            $stmt->execute(array('product_categories'));
            $has = (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            $has = false;
        }
    }
    return $has;
}

/** La colonna categories.sortOrder esiste? (cache per richiesta) */
function admin_has_category_order(PDO $pdo)
{
    static $has = null;
    if ($has === null) {
        try {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
            );
            $stmt->execute(array('categories', 'sortOrder'));
            $has = (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            $has = false;
        }
    }
    return $has;
}

/** Categorie ordinate come le vede l'app. */
function admin_categories_ordered(PDO $pdo)
{
    $order = admin_has_category_order($pdo) ? 'sortOrder, nameCategory' : 'nameCategory';
    return $pdo->query('SELECT * FROM categories ORDER BY ' . $order)->fetchAll();
}

/** Id delle categorie collegate a un prodotto. */
function admin_product_category_ids(PDO $pdo, $productId)
{
    $ids = array();
    if (admin_has_multi_category($pdo)) {
        $stmt = $pdo->prepare('SELECT idCategory FROM product_categories WHERE productId = ?');
        $stmt->execute(array($productId));
        foreach ($stmt->fetchAll() as $row) {
            $ids[] = $row['idCategory'];
        }
    }
    return $ids;
}

/** Mappa productId => elenco nomi categoria, per le tabelle del pannello. */
function admin_product_category_names(PDO $pdo, array $productIds)
{
    $map = array();
    if (!admin_has_multi_category($pdo) || count($productIds) === 0) {
        return $map;
    }
    $ids = array_values($productIds);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $order = admin_has_category_order($pdo) ? 'c.sortOrder, c.nameCategory' : 'c.nameCategory';
    $stmt = $pdo->prepare(
        'SELECT pc.productId, c.nameCategory
         FROM product_categories pc
         JOIN categories c ON c.idCategory = pc.idCategory
         WHERE pc.productId IN (' . $placeholders . ')
         ORDER BY ' . $order
    );
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $row) {
        $map[$row['productId']][] = $row['nameCategory'];
    }
    return $map;
}

/** Riallinea il conteggio prodotti per categoria (usato dall'app). */
function recalc_category_counts(PDO $pdo)
{
    if (admin_has_multi_category($pdo)) {
        $pdo->exec('UPDATE categories c SET c.count = (
            SELECT COUNT(DISTINCT pc.productId) FROM product_categories pc
            WHERE pc.idCategory = c.idCategory
        )');
        return;
    }

    $pdo->exec('UPDATE categories c SET c.count = (
        SELECT COUNT(*) FROM products p
        WHERE p.category = c.nameCategory OR p.category = c.idCategory
    )');
}

/** Avviso mostrato quando la migrazione del database non è stata eseguita. */
function admin_migration_notice(PDO $pdo)
{
    if (admin_has_multi_category($pdo) && admin_has_category_order($pdo)) {
        return;
    }
    echo '<div class="alert error">Schema database non aggiornato: mancano '
        . '<code>product_categories</code> e/o <code>categories.sortOrder</code>. '
        . 'Applica <code>database/init_database.sql</code> su un database nuovo.</div>';
}

/** Intestazione comune delle pagine del pannello. */
function admin_header($title, $active, $bodyClass = '')
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
<body class="page-<?php echo h($active); ?><?php echo $bodyClass !== '' ? ' ' . h($bodyClass) : ''; ?>">
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
