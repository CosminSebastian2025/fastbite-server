<?php
require __DIR__ . '/inc.php';
require_admin();

$pdo = admin_db();
$action = get_value('action', 'list');
$errors = array();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $formAction = post_value('form_action');

    if ($formAction === 'delete') {
        $id = post_value('id');
        try {
            $stmt = $pdo->prepare('SELECT idCategory, nameCategory FROM categories WHERE idCategory = ?');
            $stmt->execute(array($id));
            $cat = $stmt->fetch();
            if (!$cat) {
                flash_set('error', 'Categoria non trovata.');
            } else {
                $check = $pdo->prepare('SELECT COUNT(*) FROM products WHERE category = ? OR category = ?');
                $check->execute(array($cat['nameCategory'], $cat['idCategory']));
                $used = (int)$check->fetchColumn();

                if (admin_has_multi_category($pdo)) {
                    $checkLinks = $pdo->prepare(
                        'SELECT COUNT(DISTINCT productId) FROM product_categories WHERE idCategory = ?'
                    );
                    $checkLinks->execute(array($id));
                    $used = max($used, (int)$checkLinks->fetchColumn());
                }

                if ($used > 0) {
                    flash_set('error', 'Impossibile eliminare "' . $cat['nameCategory']
                        . '": ci sono ' . $used . ' prodotti collegati. Spostali in un\'altra categoria.');
                } else {
                    $del = $pdo->prepare('DELETE FROM categories WHERE idCategory = ?');
                    $del->execute(array($id));
                    flash_set('ok', 'Categoria eliminata.');
                }
            }
        } catch (Throwable $e) {
            error_log('FastBite admin delete category: ' . $e->getMessage());
            flash_set('error', 'Impossibile eliminare la categoria.');
        }
        redirect('categories.php');
    }

    if ($formAction === 'move') {
        $id = post_value('id');
        $direction = post_value('direction');

        if (!admin_has_category_order($pdo)) {
            flash_set('error', 'Schema non aggiornato: manca categories.sortOrder. Applica database/init_database.sql su un database nuovo.');
            redirect('categories.php');
        }
        if (!in_array($direction, array('up', 'down'), true)) {
            flash_set('error', 'Direzione non valida.');
            redirect('categories.php');
        }

        try {
            $rows = admin_categories_ordered($pdo);
            $index = -1;
            foreach ($rows as $i => $row) {
                if ($row['idCategory'] === $id) {
                    $index = $i;
                    break;
                }
            }
            $target = $direction === 'up' ? $index - 1 : $index + 1;
            if ($index < 0 || $target < 0 || $target >= count($rows)) {
                flash_set('error', 'La categoria è già in cima o in fondo.');
                redirect('categories.php');
            }

            $pdo->beginTransaction();
            $update = $pdo->prepare('UPDATE categories SET sortOrder = ? WHERE idCategory = ?');
            // Rinumera tutto 1..N scambiando le due posizioni.
            foreach ($rows as $i => $row) {
                $position = $i;
                if ($i === $index) {
                    $position = $target;
                } elseif ($i === $target) {
                    $position = $index;
                }
                $update->execute(array($position + 1, $row['idCategory']));
            }
            $pdo->commit();
            flash_set('ok', 'Ordine delle categorie aggiornato.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('FastBite admin move category: ' . $e->getMessage());
            flash_set('error', 'Impossibile cambiare l\'ordine delle categorie.');
        }
        redirect('categories.php');
    }

    if ($formAction === 'save') {
        $isEdit = post_value('is_edit') === '1';
        $id = post_value('id');
        $name = post_value('name');
        $icon = post_value('icon');

        if ($name === '') {
            $errors[] = 'Il nome della categoria è obbligatorio.';
        }
        if ((function_exists('mb_strlen') ? mb_strlen($name) : strlen($name)) > 100) {
            $errors[] = 'Il nome può contenere al massimo 100 caratteri.';
        }

        if (count($errors) === 0) {
            try {
                $dup = $pdo->prepare('SELECT idCategory FROM categories WHERE LOWER(nameCategory) = LOWER(?) AND idCategory <> ?');
                $dup->execute(array($name, $isEdit ? $id : ''));
                if ($dup->fetch()) {
                    $errors[] = 'Esiste già una categoria con questo nome.';
                }
            } catch (Throwable $e) {
                error_log('FastBite admin category duplicate check: ' . $e->getMessage());
                $errors[] = 'Errore nella verifica della categoria.';
            }
        }

        if (count($errors) === 0) {
            try {
                if ($isEdit) {
                    $stmt = $pdo->prepare('SELECT nameCategory FROM categories WHERE idCategory = ?');
                    $stmt->execute(array($id));
                    $old = $stmt->fetch();
                    if (!$old) {
                        $errors[] = 'Categoria non trovata.';
                    } else {
                        $pdo->beginTransaction();
                        $update = $pdo->prepare('UPDATE categories SET nameCategory = ?, icon = ? WHERE idCategory = ?');
                        $update->execute(array($name, $icon !== '' ? $icon : null, $id));
                        if ($old['nameCategory'] !== $name) {
                            $prop = $pdo->prepare('UPDATE products SET category = ? WHERE category = ? OR category = ?');
                            $prop->execute(array($name, $old['nameCategory'], $id));
                        }
                        $pdo->commit();
                        recalc_category_counts($pdo);
                        flash_set('ok', 'Categoria aggiornata.');
                        redirect('categories.php');
                    }
                } else {
                    $newId = 'cat_' . bin2hex(random_bytes(4));
                    if (admin_has_category_order($pdo)) {
                        $nextOrder = (int)$pdo->query(
                            'SELECT COALESCE(MAX(sortOrder), 0) + 1 FROM categories'
                        )->fetchColumn();
                        $stmt = $pdo->prepare(
                            'INSERT INTO categories (idCategory, nameCategory, icon, count, sortOrder)
                             VALUES (?, ?, ?, 0, ?)'
                        );
                        $stmt->execute(array($newId, $name, $icon !== '' ? $icon : null, $nextOrder));
                    } else {
                        $stmt = $pdo->prepare(
                            'INSERT INTO categories (idCategory, nameCategory, icon, count) VALUES (?, ?, ?, 0)'
                        );
                        $stmt->execute(array($newId, $name, $icon !== '' ? $icon : null));
                    }
                    recalc_category_counts($pdo);
                    flash_set('ok', 'Categoria creata: ' . $name);
                    redirect('categories.php');
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('FastBite admin save category: ' . $e->getMessage());
                $errors[] = 'Errore nel salvataggio della categoria.';
            }
        }
    }
}

$repostWithErrors = $_SERVER['REQUEST_METHOD'] === 'POST'
    && post_value('form_action') === 'save'
    && count($errors) > 0;

if ($action === 'new' || $action === 'edit' || $repostWithErrors) {
    if ($repostWithErrors) {
        $action = post_value('is_edit') === '1' ? 'edit' : 'new';
    }

    $form = array('idCategory' => '', 'nameCategory' => '', 'icon' => '');

    if ($action === 'edit' && !$repostWithErrors) {
        $stmt = $pdo->prepare('SELECT * FROM categories WHERE idCategory = ?');
        $stmt->execute(array(get_value('id')));
        $found = $stmt->fetch();
        if (!$found) {
            flash_set('error', 'Categoria non trovata.');
            redirect('categories.php');
        }
        $form = $found;
    }

    admin_header($action === 'edit' ? 'Modifica categoria' : 'Nuova categoria', 'categories');
    ?>
    <div class="panel">
        <div class="panel-head">
            <h2><?php echo $action === 'edit' ? h(isset($_POST['name']) ? post_value('name') : $form['nameCategory']) : 'Aggiungi una categoria'; ?></h2>
            <a class="btn ghost" href="categories.php">Torna all'elenco</a>
        </div>

        <?php admin_migration_notice($pdo); ?>

        <?php if (count($errors) > 0): ?>
            <div class="alert error">
                <?php foreach ($errors as $err): ?><div><?php echo h($err); ?></div><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post" action="categories.php" class="stack">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="form_action" value="save">
            <input type="hidden" name="is_edit" value="<?php echo $action === 'edit' ? '1' : '0'; ?>">
            <input type="hidden" name="id" value="<?php echo h(isset($_POST['id']) ? post_value('id') : $form['idCategory']); ?>">

            <div class="grid-2">
                <label>Nome
                    <input type="text" name="name" maxlength="100" required
                           value="<?php echo h(isset($_POST['name']) ? post_value('name') : $form['nameCategory']); ?>">
                </label>
                <label>Icona (emoji, opzionale)
                    <input type="text" name="icon" maxlength="50" placeholder="🍕"
                           value="<?php echo h(isset($_POST['icon']) ? post_value('icon') : $form['icon']); ?>">
                </label>
            </div>

            <?php if ($action === 'edit'): ?>
                <p class="muted">Rinominando la categoria, i prodotti collegati vengono aggiornati automaticamente.</p>
            <?php endif; ?>

            <div class="form-actions">
                <button type="submit" class="btn primary"><?php echo $action === 'edit' ? 'Salva modifiche' : 'Crea categoria'; ?></button>
                <a class="btn ghost" href="categories.php">Annulla</a>
            </div>
        </form>
    </div>
    <?php
    admin_footer();
    exit;
}

$orderSql = admin_has_category_order($pdo) ? 'c.sortOrder, c.nameCategory' : 'c.nameCategory';
if (admin_has_multi_category($pdo)) {
    $categories = $pdo->query(
        'SELECT c.*,
                (SELECT COUNT(DISTINCT pc.productId) FROM product_categories pc
                  WHERE pc.idCategory = c.idCategory) AS live_count
         FROM categories c
         ORDER BY ' . $orderSql
    )->fetchAll();
} else {
    $categories = $pdo->query(
        'SELECT c.*,
                (SELECT COUNT(*) FROM products p
                  WHERE p.category = c.nameCategory OR p.category = c.idCategory) AS live_count
         FROM categories c
         ORDER BY ' . $orderSql
    )->fetchAll();
}

admin_header('Categorie', 'categories', 'page-list');
?>
<div class="panel list-panel">
    <div class="panel-head">
        <h2>Categorie (<?php echo count($categories); ?>)</h2>
        <a class="btn primary" href="categories.php?action=new">+ Nuova categoria</a>
    </div>

    <?php admin_migration_notice($pdo); ?>

    <p class="muted"><small>L'ordine di questa lista è quello mostrato nell'app. Usa ↑ e ↓ per spostare le categorie.</small></p>

    <?php if (count($categories) === 0): ?>
        <p class="muted">Nessuna categoria. Creane una per iniziare.</p>
    <?php else: ?>
        <div class="table-scroll">
        <table class="table">
            <thead>
            <tr><th>Ordine</th><th>Icona</th><th>Nome</th><th>ID</th><th>Prodotti</th><th class="right">Azioni</th></tr>
            </thead>
            <tbody>
            <?php $lastIndex = count($categories) - 1; ?>
            <?php foreach ($categories as $index => $c): ?>
                <tr>
                    <td class="order-cell">
                        <form method="post" action="categories.php" class="inline">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="form_action" value="move">
                            <input type="hidden" name="direction" value="up">
                            <input type="hidden" name="id" value="<?php echo h($c['idCategory']); ?>">
                            <button type="submit" class="btn small ghost move" title="Sposta su"
                                <?php echo $index === 0 ? 'disabled' : ''; ?>>↑</button>
                        </form>
                        <form method="post" action="categories.php" class="inline">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="form_action" value="move">
                            <input type="hidden" name="direction" value="down">
                            <input type="hidden" name="id" value="<?php echo h($c['idCategory']); ?>">
                            <button type="submit" class="btn small ghost move" title="Sposta giù"
                                <?php echo $index === $lastIndex ? 'disabled' : ''; ?>>↓</button>
                        </form>
                        <span class="muted"><?php echo (int)$index + 1; ?></span>
                    </td>
                    <td class="emoji"><?php echo h($c['icon']); ?></td>
                    <td><strong><?php echo h($c['nameCategory']); ?></strong></td>
                    <td><small class="muted"><?php echo h($c['idCategory']); ?></small></td>
                    <td><a href="products.php?category=<?php echo h(urlencode($c['idCategory'])); ?>"><?php echo (int)$c['live_count']; ?></a></td>
                    <td class="right nowrap">
                        <a class="btn small ghost" href="categories.php?action=edit&id=<?php echo h(urlencode($c['idCategory'])); ?>">Modifica</a>
                        <form method="post" action="categories.php" class="inline"
                              onsubmit="return confirm('Eliminare questa categoria?');">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="form_action" value="delete">
                            <input type="hidden" name="id" value="<?php echo h($c['idCategory']); ?>">
                            <button type="submit" class="btn small danger">Elimina</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>
<?php admin_footer(); ?>
