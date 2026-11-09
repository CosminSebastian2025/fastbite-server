<?php
require __DIR__ . '/inc.php';
require_admin();

$dbError = '';
try {
    $pdo = admin_db();

    $stats = array(
        'products' => (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn(),
        'categories' => (int)$pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn(),
        'orders' => (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
        'users' => (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
        'revenue' => (float)$pdo->query(
            "SELECT COALESCE(SUM(total), 0) FROM orders WHERE status NOT LIKE '%nnul%'"
        )->fetchColumn(),
        'today' => (int)$pdo->query(
            'SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()'
        )->fetchColumn(),
    );

    $recent = $pdo->query(
        'SELECT o.orderId, o.total, o.status, o.created_at,
                u.name AS user_name, u.email AS user_email,
                s.name AS store_name
         FROM orders o
         LEFT JOIN users u ON u.userId = o.userId
         LEFT JOIN stores s ON s.id = o.storeId
         ORDER BY o.created_at DESC, o.orderId DESC
         LIMIT 10'
    )->fetchAll();

    $topProducts = $pdo->query(
        'SELECT oi.productName, SUM(oi.quantity) AS qty, SUM(oi.quantity * (oi.basePrice + oi.optionPriceModifier)) AS revenue
         FROM order_items oi
         JOIN orders o ON o.orderId = oi.orderId
         WHERE o.status NOT LIKE \'%nnul%\'
         GROUP BY oi.productName
         ORDER BY qty DESC
         LIMIT 5'
    )->fetchAll();
} catch (Throwable $e) {
    error_log('FastBite admin dashboard: ' . $e->getMessage());
    $stats = array('products' => 0, 'categories' => 0, 'orders' => 0, 'users' => 0, 'revenue' => 0, 'today' => 0);
    $recent = array();
    $topProducts = array();
    $dbError = 'Impossibile leggere i dati dal database. Controlla le credenziali in inc.php.';
}

admin_header('Dashboard', 'dashboard');
?>

<?php if (!empty($dbError)): ?>
    <div class="alert error"><?php echo h($dbError); ?></div>
<?php endif; ?>

<div class="cards">
    <a class="card" href="orders.php">
        <span class="card-label">Ordini totali</span>
        <strong><?php echo (int)$stats['orders']; ?></strong>
        <small><?php echo (int)$stats['today']; ?> oggi</small>
    </a>
    <a class="card" href="products.php">
        <span class="card-label">Prodotti</span>
        <strong><?php echo (int)$stats['products']; ?></strong>
        <small>nel menu</small>
    </a>
    <a class="card" href="categories.php">
        <span class="card-label">Categorie</span>
        <strong><?php echo (int)$stats['categories']; ?></strong>
        <small>attive</small>
    </a>
    <div class="card">
        <span class="card-label">Incasso (esclusi annullati)</span>
        <strong><?php echo h(money($stats['revenue'])); ?></strong>
        <small><?php echo (int)$stats['users']; ?> utenti registrati</small>
    </div>
</div>

<div class="grid-2">
    <section class="panel">
        <div class="panel-head">
            <h2>Ultimi ordini</h2>
            <a class="btn ghost" href="orders.php">Vedi tutti</a>
        </div>
        <?php if (count($recent) === 0): ?>
            <p class="muted">Nessun ordine registrato.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                <tr><th>Ordine</th><th>Cliente</th><th>Totale</th><th>Stato</th><th class="hide-sm">Data</th></tr>
                </thead>
                <tbody>
                <?php foreach ($recent as $row): ?>
                    <tr>
                        <td><a href="orders.php?view=<?php echo h(urlencode($row['orderId'])); ?>"><?php echo h($row['orderId']); ?></a></td>
                        <td>
                            <?php echo h($row['user_name'] !== null ? $row['user_name'] : 'Ospite'); ?><br>
                            <small class="muted"><?php echo h($row['user_email']); ?></small>
                        </td>
                        <td><?php echo h(money($row['total'])); ?></td>
                        <td><span class="<?php echo h(status_class($row['status'])); ?>"><?php echo h($row['status']); ?></span></td>
                        <td><small class="hide-sm"><?php echo h(format_date($row['created_at'])); ?></small></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Prodotti più venduti</h2></div>
        <?php if (count($topProducts) === 0): ?>
            <p class="muted">Nessuna vendita registrata.</p>
        <?php else: ?>
            <table class="table">
                <thead><tr><th>Prodotto</th><th>Pezzi</th><th>Incasso</th></tr></thead>
                <tbody>
                <?php foreach ($topProducts as $p): ?>
                    <tr>
                        <td><?php echo h($p['productName']); ?></td>
                        <td><?php echo (int)$p['qty']; ?></td>
                        <td><?php echo h(money($p['revenue'])); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</div>

<?php admin_footer(); ?>
