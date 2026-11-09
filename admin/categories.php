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
                    $stmt = $pdo->prepare('INSERT INTO categories (idCategory, nameCategory, icon, count) VALUES (?, ?, ?, 0)');
                    $stmt->execute(array($newId, $name, $icon !== '' ? $icon : null));
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

$categories = $pdo->query(
    'SELECT c.*,
            (SELECT COUNT(*) FROM products p
              WHERE p.category = c.nameCategory OR p.category = c.idCategory) AS live_count
     FROM categories c
     ORDER BY c.nameCategory'
)->fetchAll();

admin_header('Categorie', 'categories');
?>
<div class="panel">
    <div class="panel-head">
        <h2>Categorie (<?php echo count($categories); ?>)</h2>
        <a class="btn primary" href="categories.php?action=new">+ Nuova categoria</a>
    </div>

    <?php if (count($categories) === 0): ?>
        <p class="muted">Nessuna categoria. Creane una per iniziare.</p>
    <?php else: ?>
        <table class="table">
            <thead>
            <tr><th>Icona</th><th>Nome</th><th>ID</th><th>Prodotti</th><th class="right">Azioni</th></tr>
            </thead>
            <tbody>
            <?php foreach ($categories as $c): ?>
                <tr>
                    <td class="emoji"><?php echo h($c['icon']); ?></td>
                    <td><strong><?php echo h($c['nameCategory']); ?></strong></td>
                    <td><small class="muted"><?php echo h($c['idCategory']); ?></small></td>
                    <td><a href="products.php?category=<?php echo h(urlencode($c['nameCategory'])); ?>"><?php echo (int)$c['live_count']; ?></a></td>
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
    <?php endif; ?>
</div>
<?php admin_footer(); ?>
