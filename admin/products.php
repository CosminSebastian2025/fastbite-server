<?php
require __DIR__ . '/inc.php';
require_admin();

$pdo = admin_db();
$action = get_value('action', 'list');
$errors = array();

function admin_product_categories(PDO $pdo)
{
    return $pdo->query('SELECT idCategory, nameCategory FROM categories ORDER BY nameCategory')->fetchAll();
}

function admin_allergens_to_json($raw)
{
    $raw = trim((string)$raw);
    if ($raw === '') {
        return json_encode(array());
    }
    $parts = preg_split('/[,;]+/', $raw);
    $clean = array();
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part !== '') {
            $clean[] = $part;
        }
    }
    return json_encode(array_values(array_unique($clean)));
}

function admin_allergens_to_text($json)
{
    $decoded = json_decode((string)$json, true);
    if (!is_array($decoded)) {
        return '';
    }
    return implode(', ', $decoded);
}

function admin_new_product_id(PDO $pdo)
{
    $stmt = $pdo->prepare('SELECT 1 FROM products WHERE IdProduct = ?');
    do {
        $id = 'prod_' . bin2hex(random_bytes(4));
        $stmt->execute(array($id));
    } while ($stmt->fetchColumn());
    return $id;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $formAction = post_value('form_action');

    if ($formAction === 'delete') {
        $id = post_value('id');
        try {
            $stmt = $pdo->prepare('DELETE FROM products WHERE IdProduct = ?');
            $stmt->execute(array($id));
            recalc_category_counts($pdo);
            flash_set($stmt->rowCount() > 0 ? 'ok' : 'error',
                $stmt->rowCount() > 0 ? 'Prodotto eliminato.' : 'Prodotto non trovato.');
        } catch (Throwable $e) {
            error_log('FastBite admin delete product: ' . $e->getMessage());
            flash_set('error', 'Impossibile eliminare il prodotto.');
        }
        redirect('products.php');
    }

    if ($formAction === 'save') {
        $isEdit = post_value('is_edit') === '1';
        $id = post_value('id');
        $name = post_value('name');
        $description = post_value('description');
        $priceRaw = str_replace(',', '.', post_value('base_price'));
        $imageUrl = post_value('image_url');
        $category = post_value('category');
        $isPopular = post_value('is_popular') === '1' ? 1 : 0;
        $allergens = post_value('allergens');

        if ($name === '') {
            $errors[] = 'Il nome del prodotto è obbligatorio.';
        }
        if ($category === '') {
            $errors[] = 'Seleziona una categoria.';
        }
        if ($priceRaw === '' || !is_numeric($priceRaw) || (float)$priceRaw < 0) {
            $errors[] = 'Il prezzo deve essere un numero maggiore o uguale a zero.';
        }

        if (count($errors) === 0) {
            try {
                if ($isEdit) {
                    if ($id === '') {
                        $errors[] = 'Prodotto non valido.';
                    } else {
                        $stmt = $pdo->prepare(
                            'UPDATE products
                             SET nameProduct = ?, description = ?, basePrice = ?, imageUrl = ?,
                                 category = ?, isPopular = ?, allergens = ?
                             WHERE IdProduct = ?'
                        );
                        $stmt->execute(array(
                            $name,
                            $description !== '' ? $description : null,
                            (float)$priceRaw,
                            $imageUrl !== '' ? $imageUrl : null,
                            $category,
                            $isPopular,
                            admin_allergens_to_json($allergens),
                            $id,
                        ));
                        recalc_category_counts($pdo);
                        flash_set('ok', 'Prodotto aggiornato.');
                        redirect('products.php');
                    }
                } else {
                    $newId = admin_new_product_id($pdo);
                    $stmt = $pdo->prepare(
                        'INSERT INTO products
                         (IdProduct, nameProduct, description, basePrice, imageUrl, category, isPopular, allergens)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $stmt->execute(array(
                        $newId,
                        $name,
                        $description !== '' ? $description : null,
                        (float)$priceRaw,
                        $imageUrl !== '' ? $imageUrl : null,
                        $category,
                        $isPopular,
                        admin_allergens_to_json($allergens),
                    ));
                    recalc_category_counts($pdo);
                    flash_set('ok', 'Prodotto creato: ' . $newId);
                    redirect('products.php');
                }
            } catch (Throwable $e) {
                error_log('FastBite admin save product: ' . $e->getMessage());
                $errors[] = 'Errore nel salvataggio del prodotto.';
            }
        }
    }
}

$repostWithErrors = $_SERVER['REQUEST_METHOD'] === 'POST'
    && post_value('form_action') === 'save'
    && count($errors) > 0;

// Form (nuovo / modifica)
if ($action === 'new' || $action === 'edit' || $repostWithErrors) {
    if ($repostWithErrors) {
        $action = post_value('is_edit') === '1' ? 'edit' : 'new';
    }

    $form = array(
        'IdProduct' => '',
        'nameProduct' => '',
        'description' => '',
        'basePrice' => '',
        'imageUrl' => '',
        'category' => '',
        'isPopular' => 0,
        'allergens' => '[]',
    );

    if ($action === 'edit' && !$repostWithErrors) {
        $stmt = $pdo->prepare('SELECT * FROM products WHERE IdProduct = ?');
        $stmt->execute(array(get_value('id')));
        $found = $stmt->fetch();
        if (!$found) {
            flash_set('error', 'Prodotto non trovato.');
            redirect('products.php');
        }
        $form = $found;
    }

    $categories = admin_product_categories($pdo);

    admin_header($action === 'edit' ? 'Modifica prodotto' : 'Nuovo prodotto', 'products');
    ?>
    <div class="panel">
        <div class="panel-head">
            <h2><?php echo $action === 'edit' ? h(isset($_POST['name']) ? post_value('name') : $form['nameProduct']) : 'Aggiungi un prodotto'; ?></h2>
            <a class="btn ghost" href="products.php">Torna all'elenco</a>
        </div>

        <?php if (count($errors) > 0): ?>
            <div class="alert error">
                <?php foreach ($errors as $err): ?><div><?php echo h($err); ?></div><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post" action="products.php" class="stack">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="form_action" value="save">
            <input type="hidden" name="is_edit" value="<?php echo $action === 'edit' ? '1' : '0'; ?>">
            <input type="hidden" name="id" value="<?php echo h(isset($_POST['id']) ? post_value('id') : $form['IdProduct']); ?>">

            <div class="grid-2">
                <label>Nome
                    <input type="text" name="name" maxlength="200" required
                           value="<?php echo h(isset($_POST['name']) ? post_value('name') : $form['nameProduct']); ?>">
                </label>
                <label>Prezzo (€)
                    <input type="text" name="base_price" inputmode="decimal" required
                           value="<?php echo h(isset($_POST['base_price']) ? post_value('base_price') : $form['basePrice']); ?>">
                </label>
            </div>

            <label>Descrizione
                <textarea name="description" rows="3"><?php echo h(isset($_POST['description']) ? post_value('description') : $form['description']); ?></textarea>
            </label>

            <div class="grid-2">
                <label>Categoria
                    <select name="category" required>
                        <option value="">— seleziona —</option>
                        <?php $currentCategory = isset($_POST['category']) ? post_value('category') : $form['category']; ?>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo h($cat['nameCategory']); ?>"
                                <?php echo $cat['nameCategory'] === $currentCategory ? 'selected' : ''; ?>>
                                <?php echo h($cat['nameCategory']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Immagine (URL)
                    <input type="text" name="image_url" maxlength="500"
                           value="<?php echo h(isset($_POST['image_url']) ? post_value('image_url') : $form['imageUrl']); ?>">
                </label>
            </div>

            <label>Allergeni (separati da virgola)
                <input type="text" name="allergens"
                       value="<?php echo h(isset($_POST['allergens']) ? post_value('allergens') : admin_allergens_to_text($form['allergens'])); ?>">
            </label>

            <?php $popular = isset($_POST['is_popular']) ? post_value('is_popular') === '1' : (bool)$form['isPopular']; ?>
            <label class="check">
                <input type="checkbox" name="is_popular" value="1" <?php echo $popular ? 'checked' : ''; ?>>
                Mostra come popolare
            </label>

            <div class="form-actions">
                <button type="submit" class="btn primary"><?php echo $action === 'edit' ? 'Salva modifiche' : 'Crea prodotto'; ?></button>
                <a class="btn ghost" href="products.php">Annulla</a>
            </div>
        </form>
    </div>
    <?php
    admin_footer();
    exit;
}

// Elenco
$q = get_value('q');
$categoryFilter = get_value('category');
$sql = 'SELECT * FROM products WHERE 1=1';
$params = array();
if ($q !== '') {
    $sql .= ' AND (nameProduct LIKE ? OR description LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
if ($categoryFilter !== '') {
    $sql .= ' AND category = ?';
    $params[] = $categoryFilter;
}
$sql .= ' ORDER BY category ASC, nameProduct ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();
$categories = admin_product_categories($pdo);

admin_header('Prodotti', 'products');
?>
<div class="panel">
    <div class="panel-head">
        <h2>Menu (<?php echo count($products); ?>)</h2>
        <a class="btn primary" href="products.php?action=new">+ Nuovo prodotto</a>
    </div>

    <form method="get" action="products.php" class="filters">
        <input type="text" name="q" placeholder="Cerca per nome…" value="<?php echo h($q); ?>">
        <select name="category">
            <option value="">Tutte le categorie</option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?php echo h($cat['nameCategory']); ?>" <?php echo $cat['nameCategory'] === $categoryFilter ? 'selected' : ''; ?>>
                    <?php echo h($cat['nameCategory']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn ghost">Filtra</button>
        <?php if ($q !== '' || $categoryFilter !== ''): ?>
            <a class="btn ghost" href="products.php">Azzera</a>
        <?php endif; ?>
    </form>

    <?php if (count($products) === 0): ?>
        <p class="muted">Nessun prodotto trovato.</p>
    <?php else: ?>
        <table class="table">
            <thead>
            <tr><th>Prodotto</th><th>Categoria</th><th>Prezzo</th><th class="hide-sm">Popolare</th><th class="hide-sm">Allergeni</th><th class="right">Azioni</th></tr>
            </thead>
            <tbody>
            <?php foreach ($products as $p): ?>
                <tr>
                    <td>
                        <strong><?php echo h($p['nameProduct']); ?></strong><br>
                        <small class="muted"><?php echo h($p['IdProduct']); ?></small>
                    </td>
                    <td><?php echo h($p['category']); ?></td>
                    <td><?php echo h(money($p['basePrice'])); ?></td>
                    <td class="hide-sm"><?php echo $p['isPopular'] ? 'Sì' : '—'; ?></td>
                    <td class="hide-sm"><small><?php echo h(admin_allergens_to_text($p['allergens'])); ?></small></td>
                    <td class="right nowrap">
                        <a class="btn small ghost" href="products.php?action=edit&id=<?php echo h(urlencode($p['IdProduct'])); ?>">Modifica</a>
                        <form method="post" action="products.php" class="inline"
                              onsubmit="return confirm('Eliminare questo prodotto? L\'ordine resterà nello storico.');">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="form_action" value="delete">
                            <input type="hidden" name="id" value="<?php echo h($p['IdProduct']); ?>">
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
