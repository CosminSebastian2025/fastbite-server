<?php
/**
 * FastBite API - versione corretta
 * PHP 7.1+ / MySQL
 *
 * Prima di caricare questo file sul server:
 * 1) crea lo schema con database/init_database.sql (database "AppFood");
 * 2) se il tuo MySQL usa credenziali diverse, compila config/database.local.php;
 * 3) fai un backup del vecchio /api/index.php e poi sostituiscilo con questo.
 */

// ==========================================
// CONFIGURAZIONE DATABASE
// Credenziali lette da config/database.php (override in config/database.local.php
// oppure con le variabili d'ambiente FASTBITE_DB_*).
// ==========================================
$dbConfig = require dirname(__DIR__) . '/config/database.php';
define('DB_HOST', $dbConfig['host']);
define('DB_PORT', $dbConfig['port']);
define('DB_NAME', $dbConfig['name']);
define('DB_USER', $dbConfig['user']);
define('DB_PASS', $dbConfig['pass']);
define('DB_CHARSET', $dbConfig['charset']);

// ==========================================
// CORS
// ==========================================
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ==========================================
// HELPER FUNCTIONS
// ==========================================
function jsonResponse($data, $statusCode = 200)
{
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function jsonError($message, $statusCode = 400, $extra = array())
{
    http_response_code($statusCode);
    echo json_encode(array_merge(array('error' => $message), $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function formatProduct($row)
{
    // "categories" contiene tutti i nomi delle categorie del prodotto; "category"
    // resta la categoria principale per compatibilità con le vecchie versioni.
    $categories = isset($row['_categories']) && is_array($row['_categories'])
        ? $row['_categories']
        : (isset($row['category']) && $row['category'] !== '' ? array($row['category']) : array());

    return array(
        'IdProduct' => $row['IdProduct'],
        'nameProduct' => $row['nameProduct'],
        'description' => $row['description'],
        'basePrice' => (float)$row['basePrice'],
        'imageUrl' => $row['imageUrl'],
        'category' => $row['category'],
        'categories' => array_values($categories),
        'isPopular' => (bool)$row['isPopular'],
        'allergens' => json_decode(isset($row['allergens']) ? $row['allergens'] : '[]', true)
    );
}

/**
 * Aggiunge a ogni prodotto l'elenco delle categorie (nomi) dalla tabella
 * product_categories. Se la migrazione non è ancora stata eseguita, ogni
 * prodotto usa come elenco la sola categoria principale.
 */
function productsWithCategories(PDO $pdo, array $rows)
{
    $map = array();
    if (count($rows) > 0) {
        try {
            $ids = array();
            foreach ($rows as $row) {
                $ids[] = $row['IdProduct'];
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare(
                'SELECT pc.productId, c.nameCategory
                 FROM product_categories pc
                 JOIN categories c ON c.idCategory = pc.idCategory
                 WHERE pc.productId IN (' . $placeholders . ')
                 ORDER BY c.sortOrder, c.nameCategory'
            );
            $stmt->execute($ids);
            foreach ($stmt->fetchAll() as $link) {
                $map[$link['productId']][] = $link['nameCategory'];
            }
        } catch (Throwable $e) {
            error_log('FastBite product_categories non disponibile: ' . $e->getMessage());
        }
    }

    $result = array();
    foreach ($rows as $row) {
        if (isset($map[$row['IdProduct']])) {
            $row['_categories'] = $map[$row['IdProduct']];
        }
        $result[] = formatProduct($row);
    }
    return $result;
}

function paymentLabel($method)
{
    switch (strtoupper((string)$method)) {
        case 'CARD':
        case 'CARTA_DI_CREDITO':
            return '💳 Carta di Credito';
        case 'CASH':
        case 'CONTANTI':
            return '💵 Contanti al Ritiro';
        case 'PAYPAL':
            return '💸 PayPal';
        default:
            $clean = trim((string)$method);
            return $clean !== '' ? $clean : 'Non specificato';
    }
}

// ==========================================
// CONNESSIONE DATABASE
// ==========================================
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        DB_USER,
        DB_PASS,
        array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        )
    );
} catch (PDOException $e) {
    error_log('FastBite DB connection: ' . $e->getMessage());
    jsonError('Database non disponibile. Riprova più tardi.', 500);
}

$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '';
$endpoint = isset($_GET['endpoint']) ? $_GET['endpoint'] : '';

// ==========================================
// GET
// ==========================================
if ($method === 'GET') {

    if ($endpoint === 'products') {
        $stmt = $pdo->query('SELECT * FROM products');
        jsonResponse(productsWithCategories($pdo, $stmt->fetchAll()));
    } elseif ($endpoint === 'populars') {
        $stmt = $pdo->query('SELECT * FROM products WHERE isPopular = TRUE');
        jsonResponse(productsWithCategories($pdo, $stmt->fetchAll()));
    } elseif ($endpoint === 'categories') {
        // Conteggio calcolato sui prodotti distinti; ordine deciso dal pannello admin.
        try {
            $sql = 'SELECT c.idCategory, c.nameCategory, c.icon, c.sortOrder,
                           (SELECT COUNT(DISTINCT pc.productId) FROM product_categories pc
                            WHERE pc.idCategory = c.idCategory) AS count
                    FROM categories c
                    ORDER BY c.sortOrder, c.idCategory';
            $rows = $pdo->query($sql)->fetchAll();
        } catch (Throwable $e) {
            // Migrazione non ancora eseguita: si torna al comportamento precedente.
            error_log('FastBite categories senza product_categories: ' . $e->getMessage());
            $sql = 'SELECT c.idCategory, c.nameCategory, c.icon,
                           0 AS sortOrder,
                           (SELECT COUNT(*) FROM products p
                            WHERE p.category = c.nameCategory OR p.category = c.idCategory) AS count
                    FROM categories c
                    ORDER BY c.idCategory';
            $rows = $pdo->query($sql)->fetchAll();
        }

        $result = array();
        foreach ($rows as $row) {
            $row['count'] = (int)$row['count'];
            $row['sortOrder'] = (int)$row['sortOrder'];
            $result[] = $row;
        }
        jsonResponse($result);
    } elseif ($endpoint === 'promotions') {
        // Immagini di fallback: alcuni DB hanno la colonna "ImageUrl" vuota o assente.
        $fallback = array(
            'promo_1' => 'https://images.unsplash.com/photo-1561758033-d89a9ad46330?w=400',
            'promo_2' => 'https://images.unsplash.com/photo-1513104890138-7c749659a591?w=400',
            'promo_3' => 'https://images.unsplash.com/photo-1550547660-d9450f859349?w=400'
        );
        $stmt = $pdo->query('SELECT * FROM promotions');
        $result = array();
        foreach ($stmt->fetchAll() as $row) {
            $image = null;
            if (isset($row['ImageUrl']) && $row['ImageUrl'] !== '') {
                $image = $row['ImageUrl'];
            } elseif (isset($row['imageUrl']) && $row['imageUrl'] !== '') {
                $image = $row['imageUrl'];
            } elseif (isset($fallback[$row['id']])) {
                $image = $fallback[$row['id']];
            }
            $result[] = array(
                'id' => $row['id'],
                'title' => $row['title'],
                'subtitle' => $row['subtitle'],
                'badges' => json_decode(isset($row['badges']) ? $row['badges'] : '[]', true),
                'imageUrl' => $image
            );
        }
        jsonResponse($result);
    } elseif ($endpoint === 'stores') {
        $stmt = $pdo->query('SELECT * FROM stores');
        $result = array();
        foreach ($stmt->fetchAll() as $row) {
            $result[] = array(
                'id' => (string)$row['id'],
                'name' => $row['name'],
                'address' => $row['address'],
                'distanceKm' => (float)$row['distanceKm'],
                'readyInMinutes' => (int)$row['readyInMinutes'],
                'openingHours' => $row['openingHours'],
                'isPreferred' => (bool)$row['isPreferred']
            );
        }
        jsonResponse($result);
    } elseif ($endpoint === 'pickup_slots') {
        $stmt = $pdo->query('SELECT * FROM pickup_slots LIMIT 1');
        $row = $stmt->fetch();
        if ($row) {
            jsonResponse(array(
                'immediated' => array(
                    'label' => $row['immediated_label'],
                    'availableAt' => $row['immediated_availableAt']
                ),
                'scheduled' => json_decode($row['scheduled_slots'], true)
            ));
        } else {
            jsonResponse(array('immediated' => null, 'scheduled' => array()));
        }
    } elseif ($endpoint === 'payment_methods') {
        $stmt = $pdo->query('SELECT * FROM payment_methods');
        $result = array();
        foreach ($stmt->fetchAll() as $row) {
            $result[] = array(
                'id' => (string)$row['id'],
                'type' => $row['type'],
                'label' => $row['label'],
                'isDefault' => (bool)$row['isDefault']
            );
        }
        jsonResponse($result);
    } elseif ($endpoint === 'cart_rules') {
        $stmt = $pdo->query('SELECT * FROM cart_rules LIMIT 1');
        $row = $stmt->fetch();
        if ($row) {
            jsonResponse(array(
                'pickupFee' => (float)$row['pickupFee'],
                'managementFee' => (float)$row['managementFee'],
                'discountCode' => $row['discountCode'],
                'discountPercent' => (int)$row['discountPercent'],
                'currency' => $row['currency']
            ));
        } else {
            jsonError('Nessuna regola carrello configurata', 404);
        }
    } elseif ($endpoint === 'options') {
        $stmt = $pdo->query('SELECT * FROM product_options');
        $result = array();
        foreach ($stmt->fetchAll() as $row) {
            $result[] = array(
                'productId' => $row['productId'],
                'groups' => json_decode($row['groups_data'], true)
            );
        }
        jsonResponse($result);
    } elseif ($endpoint === 'profile') {
        $userId = isset($_GET['user_id']) ? $_GET['user_id'] : (isset($_GET['userId']) ? $_GET['userId'] : '');
        if ($userId === '') {
            jsonError('userId mancante', 400);
        }
        $stmt = $pdo->prepare('SELECT userId, name, email, phone, address FROM users WHERE userId = ?');
        $stmt->execute(array($userId));
        $user = $stmt->fetch();
        if ($user) {
            jsonResponse($user);
        }
        jsonError('Utente non trovato', 404);
    } elseif ($endpoint === 'order_history') {
        $userId = isset($_GET['user_id']) ? $_GET['user_id'] : (isset($_GET['userId']) ? $_GET['userId'] : '');
        if ($userId === '') {
            jsonError('userId mancante', 400);
        }

        $sql = 'SELECT o.*, s.name AS storeName, s.address AS storeAddress,
                       (SELECT ph.method FROM payment_history ph
                        WHERE ph.orderId = o.orderId
                        ORDER BY ph.payment_date DESC LIMIT 1) AS paymentMethod
                FROM orders o
                LEFT JOIN stores s ON o.storeId = s.id
                WHERE o.userId = ?
                ORDER BY o.created_at DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array($userId));
        $rows = $stmt->fetchAll();

        $stmtItems = $pdo->prepare('SELECT productName, quantity FROM order_items WHERE orderId = ?');
        $result = array();
        foreach ($rows as $row) {
            $stmtItems->execute(array($row['orderId']));
            $items = $stmtItems->fetchAll();
            $parts = array();
            foreach ($items as $item) {
                $parts[] = $item['quantity'] . 'x ' . $item['productName'];
            }
            $itemsSummary = implode(', ', $parts);

            $pickupType = isset($row['pickupType']) ? $row['pickupType'] : 'immediate';
            $orderType = ($pickupType === 'scheduled')
                ? 'Asporto - Ritiro Programmato' . (!empty($row['pickupTime']) ? ' (' . $row['pickupTime'] . ')' : '')
                : 'Asporto - Ritiro in Cassa';

            $result[] = array(
                'id' => $row['orderId'],
                'date' => $row['created_at'],
                'store_name' => !empty($row['storeName']) ? $row['storeName'] : 'FastBite Store',
                'items_summary' => $itemsSummary !== '' ? $itemsSummary : 'Ordine FastBite',
                'total' => (float)$row['total'],
                'store_address' => !empty($row['storeAddress']) ? $row['storeAddress'] : '',
                'order_type' => $orderType,
                'payment_method' => paymentLabel($row['paymentMethod']),
                'status' => !empty($row['status']) ? $row['status'] : 'In Preparazione',
                'pin' => !empty($row['pickupPin']) ? $row['pickupPin'] : '-'
            );
        }
        jsonResponse($result);
    } elseif ($endpoint === 'payment_history') {
        $userId = isset($_GET['user_id']) ? $_GET['user_id'] : (isset($_GET['userId']) ? $_GET['userId'] : '');
        if ($userId === '') {
            jsonError('userId mancante', 400);
        }

        $sql = 'SELECT ph.*
                FROM payment_history ph
                JOIN orders o ON ph.orderId = o.orderId
                WHERE o.userId = ?
                ORDER BY ph.payment_date DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array($userId));

        $result = array();
        foreach ($stmt->fetchAll() as $row) {
            $result[] = array(
                'id' => $row['paymentId'],
                'date' => $row['payment_date'],
                'amount' => (float)$row['amount'],
                'payment_method' => paymentLabel($row['method']),
                'status' => !empty($row['status']) ? $row['status'] : 'Pagato'
            );
        }
        jsonResponse($result);
    } elseif ($endpoint === 'search') {
        $query = isset($_GET['query']) ? trim($_GET['query']) : '';
        if ($query === '') {
            jsonResponse(array());
        }
        $searchTerm = '%' . $query . '%';
        try {
            $stmt = $pdo->prepare(
                'SELECT p.* FROM products p
                 WHERE p.nameProduct LIKE ? OR p.description LIKE ? OR p.category LIKE ?
                    OR EXISTS (
                        SELECT 1
                        FROM product_categories pc
                        JOIN categories c ON c.idCategory = pc.idCategory
                        WHERE pc.productId = p.IdProduct AND c.nameCategory LIKE ?
                    )'
            );
            $stmt->execute(array($searchTerm, $searchTerm, $searchTerm, $searchTerm));
            jsonResponse(productsWithCategories($pdo, $stmt->fetchAll()));
        } catch (Throwable $e) {
            error_log('FastBite search senza product_categories: ' . $e->getMessage());
            $stmt = $pdo->prepare('SELECT * FROM products
                                   WHERE nameProduct LIKE ? OR category LIKE ? OR description LIKE ?');
            $stmt->execute(array($searchTerm, $searchTerm, $searchTerm));
            jsonResponse(array_map('formatProduct', $stmt->fetchAll()));
        }
    } else {
        jsonError('Endpoint GET non valido', 400);
    }
}

// ==========================================
// POST
// ==========================================
elseif ($method === 'POST') {
    $rawData = file_get_contents('php://input');
    $data = json_decode($rawData, true);

    if (!is_array($data)) {
        jsonError('JSON non valido o mancante', 400);
    }

    if ($endpoint === 'register') {
        $email = isset($data['email']) ? trim($data['email']) : '';
        $password = isset($data['password']) ? (string)$data['password'] : '';
        $name = isset($data['name']) ? trim($data['name']) : '';

        if ($email === '' || $password === '' || $name === '') {
            jsonError('Campi obbligatori mancanti', 400);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            jsonError('Email non valida', 400);
        }
        if (strlen($password) < 6) {
            jsonError('La password deve contenere almeno 6 caratteri', 400);
        }

        $stmt = $pdo->prepare('SELECT userId FROM users WHERE email = ?');
        $stmt->execute(array($email));
        if ($stmt->fetch()) {
            jsonError('Email già registrata', 409);
        }

        try {
            $userId = 'user_' . bin2hex(random_bytes(5));
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare('INSERT INTO users (userId, name, email, password) VALUES (?, ?, ?, ?)');
            $stmt->execute(array($userId, $name, $email, $hashedPassword));

            jsonResponse(array(
                'success' => true,
                'message' => 'Registrazione avvenuta con successo',
                'userId' => $userId
            ), 201);
        } catch (Exception $e) {
            error_log('FastBite register: ' . $e->getMessage());
            jsonError('Impossibile completare la registrazione. Riprova più tardi.', 500);
        }
    } elseif ($endpoint === 'login') {
        $email = isset($data['email']) ? trim($data['email']) : '';
        $password = isset($data['password']) ? (string)$data['password'] : '';

        if ($email === '' || $password === '') {
            jsonError('Email e password obbligatori', 400);
        }

        $stmt = $pdo->prepare('SELECT userId, name, email, password FROM users WHERE email = ?');
        $stmt->execute(array($email));
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            jsonResponse(array(
                'success' => true,
                'message' => 'Login effettuato',
                'userId' => $user['userId'],
                'name' => $user['name'],
                'email' => $user['email']
            ));
        }
        jsonError('Credenziali non valide', 401);
    } elseif ($endpoint === 'create_order') {
        $userId = isset($data['userId']) ? trim((string)$data['userId']) : '';
        $storeId = isset($data['storeId']) ? trim((string)$data['storeId']) : '';
        $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : array();

        if ($userId === '' || $storeId === '' || count($items) === 0) {
            jsonError('Dati ordine incompleti', 400);
        }

        try {
            $stmtUser = $pdo->prepare('SELECT userId FROM users WHERE userId = ?');
            $stmtUser->execute(array($userId));
            if (!$stmtUser->fetch()) {
                jsonError('Utente non trovato: effettua di nuovo il login', 401);
            }

            $stmtStore = $pdo->prepare('SELECT id, name FROM stores WHERE id = ?');
            $stmtStore->execute(array($storeId));
            $store = $stmtStore->fetch();
            if (!$store) {
                jsonError('Punto vendita non trovato', 400);
            }

            // Orario di ritiro: l'app invia "HH:mm", il DB usa DATETIME.
            $pickupType = (isset($data['pickupType']) && $data['pickupType'] === 'scheduled')
                ? 'scheduled' : 'immediate';
            $scheduledTime = null;
            $pickupTimeLabel = '15-20 min';

            if ($pickupType === 'scheduled') {
                $rawTime = isset($data['scheduledTime']) ? trim((string)$data['scheduledTime']) : '';
                if ($rawTime === '') {
                    jsonError('Orario di ritiro mancante', 400);
                }
                if (preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $rawTime)) {
                    $scheduledTime = date('Y-m-d') . ' ' . $rawTime . ':00';
                    $pickupTimeLabel = $rawTime;
                } else {
                    $timestamp = strtotime($rawTime);
                    if ($timestamp === false) {
                        jsonError('Orario di ritiro non valido', 400);
                    }
                    $scheduledTime = date('Y-m-d H:i:s', $timestamp);
                    $pickupTimeLabel = date('H:i', $timestamp);
                }
            }

            // Prezzi ricalcolati lato server: non ci si fida dei totali del client.
            $stmtProduct = $pdo->prepare('SELECT IdProduct, nameProduct, basePrice FROM products WHERE IdProduct = ?');
            $subTotal = 0.0;
            $preparedItems = array();

            foreach ($items as $item) {
                if (!is_array($item)) {
                    jsonError('Articolo ordine non valido', 400);
                }
                $productId = isset($item['productId']) ? trim((string)$item['productId']) : '';
                $quantity = isset($item['quantity']) ? (int)$item['quantity'] : 0;
                if ($productId === '' || $quantity < 1 || $quantity > 99) {
                    jsonError('Articolo ordine non valido', 400);
                }

                $stmtProduct->execute(array($productId));
                $product = $stmtProduct->fetch();
                if (!$product) {
                    jsonError('Prodotto non trovato: ' . $productId, 400);
                }

                $basePrice = (float)$product['basePrice'];
                $modifier = isset($item['optionPriceModifier']) ? (float)$item['optionPriceModifier'] : 0.0;
                if ($modifier < 0) {
                    $modifier = 0.0;
                }
                $selectedOptions = isset($item['selectedOptions']) && is_array($item['selectedOptions'])
                    ? $item['selectedOptions'] : array();

                $subTotal += ($basePrice + $modifier) * $quantity;
                $preparedItems[] = array(
                    'productId' => $productId,
                    'productName' => $product['nameProduct'],
                    'basePrice' => $basePrice,
                    'quantity' => $quantity,
                    'selectedOptions' => $selectedOptions,
                    'optionPriceModifier' => $modifier
                );
            }

            // Sconto e costi di gestione presi dalle regole del server.
            $stmtRules = $pdo->query('SELECT * FROM cart_rules LIMIT 1');
            $rules = $stmtRules->fetch();
            $managementFee = $rules ? (float)$rules['managementFee'] : 0.50;
            $discount = 0.0;
            $discountCode = isset($data['discountCode']) ? trim((string)$data['discountCode']) : '';

            if ($discountCode !== '' && $rules && !empty($rules['discountCode'])
                && strcasecmp($discountCode, $rules['discountCode']) === 0) {
                $discount = $subTotal * ((int)$rules['discountPercent'] / 100);
            }

            $total = max(0, $subTotal - $discount + $managementFee);

            $paymentMethod = isset($data['paymentMethod'])
                ? strtoupper(trim((string)$data['paymentMethod'])) : 'CARD';
            if (!in_array($paymentMethod, array('CARD', 'CASH', 'PAYPAL'), true)) {
                $paymentMethod = 'CARD';
            }
            $paymentMethodDb = ($paymentMethod === 'CARD') ? 'CARTA_DI_CREDITO' : $paymentMethod;

            $orderId = 'ORD-' . strtoupper(bin2hex(random_bytes(3)));
            $pickupPin = (string)random_int(1000, 9999);
            $qrCodeUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . urlencode($orderId);

            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'INSERT INTO orders
                 (orderId, userId, storeId, pickupType, scheduledTime, discountCode,
                  subTotal, discount, managementFee, total, status, pickupPin, pickupTime, qrCodeUrl)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute(array(
                $orderId,
                $userId,
                $storeId,
                $pickupType,
                $scheduledTime,
                $discountCode !== '' ? $discountCode : null,
                $subTotal,
                $discount,
                $managementFee,
                $total,
                'In Preparazione',
                $pickupPin,
                $pickupTimeLabel,
                $qrCodeUrl
            ));

            $stmtItem = $pdo->prepare(
                'INSERT INTO order_items
                 (orderId, productId, productName, basePrice, quantity, selectedOptions, optionPriceModifier)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($preparedItems as $item) {
                $stmtItem->execute(array(
                    $orderId,
                    $item['productId'],
                    $item['productName'],
                    $item['basePrice'],
                    $item['quantity'],
                    json_encode($item['selectedOptions']),
                    $item['optionPriceModifier']
                ));
            }

            $paymentId = 'pay_' . bin2hex(random_bytes(6));
            $stmtPay = $pdo->prepare(
                'INSERT INTO payment_history (paymentId, orderId, amount, method, status)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmtPay->execute(array($paymentId, $orderId, $total, $paymentMethodDb, 'Pagato'));

            $pdo->commit();

            jsonResponse(array(
                'orderId' => $orderId,
                'pickupPin' => $pickupPin,
                'pickupTime' => $pickupTimeLabel,
                'storeName' => $store['name'],
                'qrCodeUrl' => $qrCodeUrl,
                'total' => (float)$total
            ), 201);

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('FastBite create_order: ' . $e->getMessage());
            jsonError('Il server non è riuscito a creare l\'ordine. Riprova più tardi.', 500);
        }
    } else {
        jsonError('Endpoint POST non valido', 400);
    }
} else {
    jsonError('Metodo HTTP non supportato', 405);
}
