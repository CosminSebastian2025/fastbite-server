<?php
require __DIR__ . '/inc.php';
require_admin();

$pdo = admin_db();

$statusOptions = array('In Preparazione', 'Pronto', 'Completato', 'Annullato');

function admin_order_items(PDO $pdo, $orderId)
{
    $stmt = $pdo->prepare(
        'SELECT * FROM order_items WHERE orderId = ? ORDER BY id ASC'
    );
    $stmt->execute(array($orderId));
    return $stmt->fetchAll();
}

function admin_order_payments(PDO $pdo, $orderId)
{
    $stmt = $pdo->prepare(
        'SELECT * FROM payment_history WHERE orderId = ? ORDER BY payment_date ASC'
    );
    $stmt->execute(array($orderId));
    return $stmt->fetchAll();
}

function admin_options_text($json)
{
    $decoded = json_decode((string)$json, true);
    if (!is_array($decoded)) {
        return '';
    }
    $labels = array();
    foreach ($decoded as $option) {
        if (is_array($option)) {
            $label = '';
            if (isset($option['name'])) {
                $label = (string)$option['name'];
            } elseif (isset($option['label'])) {
                $label = (string)$option['label'];
            } elseif (isset($option['optionName'])) {
                $label = (string)$option['optionName'];
            }
            if ($label === '') {
                $label = implode(' / ', array_map('strval', $option));
            }
            $labels[] = $label;
        } elseif (is_scalar($option)) {
            $labels[] = (string)$option;
        }
    }
    return implode(', ', $labels);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $formAction = post_value('form_action');

    if ($formAction === 'update_status') {
        $orderId = post_value('order_id');
        $newStatus = post_value('status');
        if (!in_array($newStatus, $statusOptions, true)) {
            flash_set('error', 'Stato non valido.');
        } else {
            try {
                $stmt = $pdo->prepare('UPDATE orders SET status = ? WHERE orderId = ?');
                $stmt->execute(array($newStatus, $orderId));
                flash_set($stmt->rowCount() > 0 ? 'ok' : 'error',
                    $stmt->rowCount() > 0 ? 'Stato aggiornato a "' . $newStatus . '".' : 'Ordine non trovato.');
            } catch (Throwable $e) {
                error_log('FastBite admin update order status: ' . $e->getMessage());
                flash_set('error', 'Impossibile aggiornare lo stato dell\'ordine.');
            }
        }
        redirect('orders.php?view=' . urlencode($orderId));
    }

    redirect('orders.php');
}

// Dettaglio ordine
$viewId = get_value('view');
if ($viewId !== '') {
    try {
        $stmt = $pdo->prepare(
            'SELECT o.*, u.name AS user_name, u.email AS user_email, u.phone AS user_phone,
                    s.name AS store_name, s.address AS store_address
             FROM orders o
             LEFT JOIN users u ON u.userId = o.userId
             LEFT JOIN stores s ON s.id = o.storeId
             WHERE o.orderId = ?'
        );
        $stmt->execute(array($viewId));
        $order = $stmt->fetch();

        if (!$order) {
            flash_set('error', 'Ordine non trovato.');
            redirect('orders.php');
        }

        $items = admin_order_items($pdo, $order['orderId']);
        $payments = admin_order_payments($pdo, $order['orderId']);
    } catch (Throwable $e) {
        error_log('FastBite admin order detail: ' . $e->getMessage());
        flash_set('error', 'Impossibile leggere i dettagli dell\'ordine.');
        redirect('orders.php');
    }

    $currentStatus = (string)$order['status'];
    $chooseOptions = $statusOptions;
    if ($currentStatus !== '' && !in_array($currentStatus, $chooseOptions, true)) {
        $chooseOptions[] = $currentStatus;
    }
    $statusForForm = $currentStatus !== '' ? $currentStatus : $statusOptions[0];

    admin_header('Ordine ' . $order['orderId'], 'orders');
    ?>
    <div class="panel">
        <div class="panel-head">
            <h2>
                <?php echo h($order['orderId']); ?>
                <span class="<?php echo h(status_class($order['status'])); ?>"><?php echo h($order['status']); ?></span>
            </h2>
            <a class="btn ghost" href="orders.php">Torna agli ordini</a>
        </div>

        <div class="grid-2">
            <div>
                <h3>Cliente</h3>
                <p>
                    <strong><?php echo h($order['user_name'] !== null ? $order['user_name'] : 'Ospite'); ?></strong><br>
                    <?php echo h($order['user_email']); ?><br>
                    <?php echo $order['user_phone'] ? h($order['user_phone']) : '<span class="muted">Telefono non indicato</span>'; ?>
                </p>

                <h3>Ritiro</h3>
                <p>
                    Punto vendita: <strong><?php echo h($order['store_name'] !== null ? $order['store_name'] : ($order['storeId'] !== null ? $order['storeId'] : '—')); ?></strong><br>
                    <?php if (!empty($order['store_address'])): ?>
                        <?php echo h($order['store_address']); ?><br>
                    <?php endif; ?>
                    Tipo: <?php echo $order['pickupType'] === 'scheduled' ? 'Programmato' : 'Immediato'; ?><br>
                    <?php if ($order['pickupType'] === 'scheduled' && !empty($order['scheduledTime'])): ?>
                        Orario: <?php echo h(format_date($order['scheduledTime'])); ?><br>
                    <?php endif; ?>
                    <?php if (!empty($order['pickupTime'])): ?>
                        Stima: <?php echo h($order['pickupTime']); ?><br>
                    <?php endif; ?>
                    PIN: <strong><?php echo h($order['pickupPin']); ?></strong>
                </p>

                <h3>Pagamento</h3>
                <?php if (count($payments) === 0): ?>
                    <p class="muted">Nessun pagamento registrato.</p>
                <?php else: ?>
                    <table class="table compact">
                        <thead><tr><th>Metodo</th><th>Importo</th><th>Stato</th><th>Data</th></tr></thead>
                        <tbody>
                        <?php foreach ($payments as $pay): ?>
                            <tr>
                                <td><?php echo h(payment_label($pay['method'])); ?></td>
                                <td><?php echo h(money($pay['amount'])); ?></td>
                                <td><?php echo h($pay['status']); ?></td>
                                <td><small><?php echo h(format_date($pay['payment_date'])); ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div>
                <h3>Riepilogo</h3>
                <table class="table compact totals">
                    <tbody>
                    <tr><td>Subtotale</td><td class="right"><?php echo h(money($order['subTotal'])); ?></td></tr>
                    <tr>
                        <td>Sconto<?php echo !empty($order['discountCode']) ? ' (' . h($order['discountCode']) . ')' : ''; ?></td>
                        <td class="right">-<?php echo h(money($order['discount'])); ?></td>
                    </tr>
                    <tr><td>Costi di gestione</td><td class="right"><?php echo h(money($order['managementFee'])); ?></td></tr>
                    <tr class="strong"><td>Totale</td><td class="right"><?php echo h(money($order['total'])); ?></td></tr>
                    </tbody>
                </table>
                <p class="muted"><small>Creato: <?php echo h(format_date($order['created_at'])); ?><br>
                        Ultimo aggiornamento: <?php echo h(format_date($order['updated_at'])); ?></small></p>

                <h3>Cambia stato</h3>
                <form method="post" action="orders.php" class="stack">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="form_action" value="update_status">
                    <input type="hidden" name="order_id" value="<?php echo h($order['orderId']); ?>">
                    <select name="status">
                        <?php foreach ($chooseOptions as $opt): ?>
                            <option value="<?php echo h($opt); ?>" <?php echo $opt === $statusForForm ? 'selected' : ''; ?>>
                                <?php echo h($opt); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn primary">Aggiorna stato</button>
                </form>
            </div>
        </div>

        <h3>Articoli (<?php echo count($items); ?>)</h3>
        <?php if (count($items) === 0): ?>
            <p class="muted">Nessun articolo associato a questo ordine.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                <tr><th>Prodotto</th><th>Opzioni</th><th>Prezzo base</th><th>Extra</th><th>Qtà</th><th class="right">Riga</th></tr>
                </thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <?php $lineTotal = ((float)$item['basePrice'] + (float)$item['optionPriceModifier']) * (int)$item['quantity']; ?>
                    <tr>
                        <td>
                            <?php echo h($item['productName']); ?><br>
                            <small class="muted"><?php echo h($item['productId']); ?></small>
                        </td>
                        <td><small><?php echo h(admin_options_text($item['selectedOptions'])); ?></small></td>
                        <td><?php echo h(money($item['basePrice'])); ?></td>
                        <td><?php echo h(money($item['optionPriceModifier'])); ?></td>
                        <td><?php echo (int)$item['quantity']; ?></td>
                        <td class="right"><?php echo h(money($lineTotal)); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
    admin_footer();
    exit;
}

// Elenco con filtri e paginazione
$statusFilter = get_value('status');
$q = get_value('q');
$page = max(1, (int)get_value('page', '1'));
$perPage = 25;

$where = ' WHERE 1=1';
$params = array();
if ($statusFilter !== '' && in_array($statusFilter, $statusOptions, true)) {
    $where .= ' AND o.status = ?';
    $params[] = $statusFilter;
}
if ($q !== '') {
    $where .= ' AND (o.orderId LIKE ? OR u.email LIKE ? OR u.name LIKE ?)';
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$dbError = '';
try {
    $countStmt = $pdo->prepare(        'SELECT COUNT(*) FROM orders o LEFT JOIN users u ON u.userId = o.userId' . $where
    );
    $countStmt->execute($params);
    $totalOrders = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalOrders / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }

    $listStmt = $pdo->prepare(
        'SELECT o.orderId, o.total, o.status, o.created_at, o.pickupType, o.pickupTime,
                u.name AS user_name, u.email AS user_email,
                s.name AS store_name
         FROM orders o
         LEFT JOIN users u ON u.userId = o.userId
         LEFT JOIN stores s ON s.id = o.storeId'
        . $where .
        ' ORDER BY o.created_at DESC, o.orderId DESC
          LIMIT ' . (int)$perPage . ' OFFSET ' . (int)(($page - 1) * $perPage)
    );
    $listStmt->execute($params);
    $orders = $listStmt->fetchAll();
} catch (Throwable $e) {
    error_log('FastBite admin orders list: ' . $e->getMessage());
    $orders = array();
    $totalOrders = 0;
    $totalPages = 1;
    $dbError = 'Impossibile leggere gli ordini dal database.';
}

$queryBase = 'orders.php?';
$queryParts = array();
if ($statusFilter !== '') {
    $queryParts[] = 'status=' . urlencode($statusFilter);
}
if ($q !== '') {
    $queryParts[] = 'q=' . urlencode($q);
}
$queryBase .= implode('&', $queryParts);

admin_header('Ordini', 'orders');
?>
<div class="panel">
    <div class="panel-head">
        <h2>Ordini dei clienti (<?php echo (int)$totalOrders; ?>)</h2>
    </div>

    <?php if (!empty($dbError)): ?>
        <div class="alert error"><?php echo h($dbError); ?></div>
    <?php endif; ?>

    <form method="get" action="orders.php" class="filters">
        <input type="text" name="q" placeholder="Cerca ordine, email o nome…" value="<?php echo h($q); ?>">
        <select name="status">
            <option value="">Tutti gli stati</option>
            <?php foreach ($statusOptions as $opt): ?>
                <option value="<?php echo h($opt); ?>" <?php echo $opt === $statusFilter ? 'selected' : ''; ?>>
                    <?php echo h($opt); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn ghost">Filtra</button>
        <?php if ($q !== '' || $statusFilter !== ''): ?>
            <a class="btn ghost" href="orders.php">Azzera</a>
        <?php endif; ?>
    </form>

    <?php if (count($orders) === 0): ?>
        <p class="muted">Nessun ordine trovato.</p>
    <?php else: ?>
        <table class="table">
            <thead>
            <tr><th>Ordine</th><th>Cliente</th><th class="hide-sm">Punto vendita</th><th class="hide-sm">Ritiro</th><th>Totale</th><th>Stato</th><th class="hide-sm">Data</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($orders as $o): ?>
                <tr>
                    <td><a href="orders.php?view=<?php echo h(urlencode($o['orderId'])); ?>"><?php echo h($o['orderId']); ?></a></td>
                    <td>
                        <?php echo h($o['user_name'] !== null ? $o['user_name'] : 'Ospite'); ?><br>
                        <small class="muted"><?php echo h($o['user_email']); ?></small>
                    </td>
                    <td class="hide-sm"><?php echo h($o['store_name']); ?></td>
                    <td class="hide-sm">
                        <?php echo $o['pickupType'] === 'scheduled' ? 'Programmato' : 'Immediato'; ?>
                        <?php if (!empty($o['pickupTime'])): ?><br><small class="muted"><?php echo h($o['pickupTime']); ?></small><?php endif; ?>
                    </td>
                    <td><?php echo h(money($o['total'])); ?></td>
                    <td><span class="<?php echo h(status_class($o['status'])); ?>"><?php echo h($o['status']); ?></span></td>
                    <td class="hide-sm"><small><?php echo h(format_date($o['created_at'])); ?></small></td>
                    <td class="right nowrap">
                        <a class="btn small ghost" href="orders.php?view=<?php echo h(urlencode($o['orderId'])); ?>">Dettagli</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($totalPages > 1): ?>
            <div class="pager">
                <?php if ($page > 1): ?>
                    <a class="btn ghost" href="<?php echo h($queryBase . ($queryBase === 'orders.php?' ? '' : '&') . 'page=' . ($page - 1)); ?>">‹ Precedente</a>
                <?php endif; ?>
                <span class="muted">Pagina <?php echo (int)$page; ?> di <?php echo (int)$totalPages; ?></span>
                <?php if ($page < $totalPages): ?>
                    <a class="btn ghost" href="<?php echo h($queryBase . ($queryBase === 'orders.php?' ? '' : '&') . 'page=' . ($page + 1)); ?>">Successiva ›</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php admin_footer(); ?>
