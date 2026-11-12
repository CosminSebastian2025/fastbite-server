-- ============================================================
-- FastBite / FoodApp - Inizializzazione database MySQL
-- Database: AppFood (XAMPP / MariaDB)
--
-- ATTENZIONE: lo script elimina e ricrea tutte le tabelle.
-- Usarlo su un database nuovo o quando si vuole ripartire da zero.
--
-- Esecuzione da phpMyAdmin (tab SQL) oppure da terminale:
--   mysql -u root AppFood < database/init_database.sql
--
-- Struttura allineata a database/documentazione_database_appfood.md
-- ============================================================

CREATE DATABASE IF NOT EXISTS AppFood
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE AppFood;
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- Pulizia: le tabelle figlie vengono eliminate per prime
-- ------------------------------------------------------------
DROP TABLE IF EXISTS payment_history;
DROP TABLE IF EXISTS order_items;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS sessions;
DROP TABLE IF EXISTS product_options;
DROP TABLE IF EXISTS product_categories;
DROP TABLE IF EXISTS cart_rules;
DROP TABLE IF EXISTS payment_methods;
DROP TABLE IF EXISTS pickup_slots;
DROP TABLE IF EXISTS promotions;
DROP TABLE IF EXISTS stores;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS admin_users;

SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------
-- Amministrazione e utenti
-- ------------------------------------------------------------
CREATE TABLE admin_users (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(120) NOT NULL,
    email      VARCHAR(190) NOT NULL UNIQUE,
    password   VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
    userId          VARCHAR(50) PRIMARY KEY,
    name            VARCHAR(120) NOT NULL,
    email           VARCHAR(190) NOT NULL UNIQUE,
    password        VARCHAR(255) NOT NULL,
    phone           VARCHAR(30) NULL,
    address         TEXT NULL,
    favoriteStoreId VARCHAR(50) NULL,
    authProvider    VARCHAR(30) NULL,
    socialId        VARCHAR(190) NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sessions (
    token      VARCHAR(255) PRIMARY KEY,
    userId     VARCHAR(50) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sessions_user (userId),
    CONSTRAINT fk_sessions_user FOREIGN KEY (userId)
        REFERENCES users (userId) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Catalogo: categorie, prodotti, collegamento molti-a-molti
-- ------------------------------------------------------------
CREATE TABLE categories (
    idCategory   VARCHAR(50) PRIMARY KEY,
    nameCategory VARCHAR(80) NOT NULL,
    icon         VARCHAR(16) NOT NULL DEFAULT '',
    count        INT NOT NULL DEFAULT 0,
    sortOrder    INT NOT NULL DEFAULT 0,
    KEY idx_categories_order (sortOrder)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE products (
    IdProduct   VARCHAR(50) PRIMARY KEY,
    nameProduct VARCHAR(120) NOT NULL,
    description TEXT NULL,
    basePrice   DECIMAL(8, 2) NOT NULL DEFAULT 0.00,
    imageUrl    VARCHAR(255) NULL,
    category    VARCHAR(80) NOT NULL,
    isPopular   TINYINT(1) NOT NULL DEFAULT 0,
    allergens   JSON NULL,
    sortOrder   INT NOT NULL DEFAULT 0,
    KEY idx_products_category (category),
    KEY idx_products_popular (isPopular),
    KEY idx_products_order (sortOrder)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_categories (
    productId  VARCHAR(50) NOT NULL,
    idCategory VARCHAR(50) NOT NULL,
    PRIMARY KEY (productId, idCategory),
    KEY idx_product_categories_category (idCategory),
    CONSTRAINT fk_pc_product FOREIGN KEY (productId)
        REFERENCES products (IdProduct) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_pc_category FOREIGN KEY (idCategory)
        REFERENCES categories (idCategory) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_options (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    productId   VARCHAR(50) NOT NULL,
    groups_data JSON NOT NULL,
    KEY idx_product_options_product (productId),
    CONSTRAINT fk_product_options_product FOREIGN KEY (productId)
        REFERENCES products (IdProduct) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Negozi, promozioni, ritiro e pagamenti
-- ------------------------------------------------------------
CREATE TABLE stores (
    id             VARCHAR(50) PRIMARY KEY,
    name           VARCHAR(120) NOT NULL,
    address        TEXT NULL,
    distanceKm     DECIMAL(6, 2) NOT NULL DEFAULT 0.00,
    readyInMinutes INT NOT NULL DEFAULT 15,
    openingHours   VARCHAR(80) NOT NULL DEFAULT '10:00 - 23:00',
    isPreferred    TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE promotions (
    id        VARCHAR(50) PRIMARY KEY,
    title     VARCHAR(120) NOT NULL,
    subtitle  TEXT NULL,
    badges    JSON NULL,
    ImageUrl  VARCHAR(255) NULL,
    sortOrder INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pickup_slots (
    id                    INT AUTO_INCREMENT PRIMARY KEY,
    immediated_label      VARCHAR(80) NOT NULL,
    immediated_availableAt VARCHAR(80) NOT NULL,
    scheduled_slots       JSON NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payment_methods (
    id        VARCHAR(50) PRIMARY KEY,
    type      VARCHAR(30) NOT NULL,
    label     VARCHAR(120) NOT NULL,
    isDefault TINYINT(1) NOT NULL DEFAULT 0,
    sortOrder INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cart_rules (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    pickupFee       DECIMAL(8, 2) NOT NULL DEFAULT 0.00,
    managementFee   DECIMAL(8, 2) NOT NULL DEFAULT 0.00,
    discountCode    VARCHAR(50) NULL,
    discountPercent INT NOT NULL DEFAULT 0,
    currency        CHAR(3) NOT NULL DEFAULT 'EUR'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Ordini e pagamenti
-- ------------------------------------------------------------
CREATE TABLE orders (
    orderId       VARCHAR(50) PRIMARY KEY,
    userId        VARCHAR(50) NULL,
    storeId       VARCHAR(50) NULL,
    pickupType    VARCHAR(20) NOT NULL DEFAULT 'immediate',
    scheduledTime DATETIME NULL,
    discountCode  VARCHAR(50) NULL,
    subTotal      DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    discount      DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    managementFee DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    total         DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    status        VARCHAR(40) NOT NULL DEFAULT 'In Preparazione',
    pickupPin     VARCHAR(20) NOT NULL,
    pickupTime    VARCHAR(30) NOT NULL DEFAULT 'ASAP',
    qrCodeUrl     VARCHAR(255) NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_orders_user (userId),
    KEY idx_orders_store (storeId),
    KEY idx_orders_created (created_at),
    CONSTRAINT fk_orders_user FOREIGN KEY (userId)
        REFERENCES users (userId) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_orders_store FOREIGN KEY (storeId)
        REFERENCES stores (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_items (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    orderId             VARCHAR(50) NOT NULL,
    productId           VARCHAR(50) NULL,
    productName         VARCHAR(120) NOT NULL,
    basePrice           DECIMAL(8, 2) NOT NULL DEFAULT 0.00,
    quantity            INT NOT NULL DEFAULT 1,
    selectedOptions     JSON NULL,
    optionPriceModifier DECIMAL(8, 2) NOT NULL DEFAULT 0.00,
    KEY idx_order_items_order (orderId),
    KEY idx_order_items_product (productId),
    CONSTRAINT fk_order_items_order FOREIGN KEY (orderId)
        REFERENCES orders (orderId) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_order_items_product FOREIGN KEY (productId)
        REFERENCES products (IdProduct) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payment_history (
    paymentId    VARCHAR(50) PRIMARY KEY,
    orderId      VARCHAR(50) NULL,
    amount       DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    method       VARCHAR(30) NOT NULL,
    status       VARCHAR(40) NOT NULL,
    payment_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_payment_history_order (orderId),
    KEY idx_payment_history_date (payment_date),
    CONSTRAINT fk_payment_history_order FOREIGN KEY (orderId)
        REFERENCES orders (orderId) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- DATI INIZIALI
-- ============================================================

-- Categorie mostrate nell'app (ordine = sortOrder)
INSERT INTO categories (idCategory, nameCategory, icon, count, sortOrder) VALUES
('cat_1', 'Panini',   '🍔', 3, 1),
('cat_2', 'Pizze',    '🍕', 2, 2),
('cat_3', 'Bevande',  '🥤', 2, 3),
('cat_4', 'Dolci',    '🍰', 1, 4),
('cat_5', 'Contorni', '🍟', 2, 5);

-- Prodotti del catalogo (category = nome categoria principale)
INSERT INTO products
    (IdProduct, nameProduct, description, basePrice, imageUrl, category, isPopular, allergens, sortOrder)
VALUES
('prod_1', 'Hamburger Classico', 'Pane artigianale, manzo 100%, pomodoro, lattuga e salsa speciale',
 8.50, 'https://images.unsplash.com/photo-1568901346375-23c9450c58cd?w=400', 'Panini', 1, '["Glutine","Lattosio"]', 1),
('prod_2', 'Cheeseburger Deluxe', 'Doppio cheddar, bacon croccante e cipolle caramellate',
 10.00, 'https://images.unsplash.com/photo-1553979459-d2229ba7433b?w=400', 'Panini', 1, '["Glutine","Lattosio"]', 2),
('prod_3', 'Chicken Burger', 'Petto di pollo grigliato, maionese e insalata fresca',
 9.00, 'https://images.unsplash.com/photo-1606755962773-d324e0a13086?w=400', 'Panini', 0, '["Glutine"]', 3),
('prod_4', 'Pizza Margherita', 'Pomodoro San Marzano, mozzarella fior di latte e basilico',
 7.00, 'https://images.unsplash.com/photo-1604068549290-dea0e4a305ca?w=400', 'Pizze', 1, '["Glutine","Lattosio"]', 4),
('prod_5', 'Pizza Pepperoni', 'Pomodoro, mozzarella e pepperoni piccante',
 8.50, 'https://images.unsplash.com/photo-1628840042765-356cda07504e?w=400', 'Pizze', 1, '["Glutine","Lattosio"]', 5),
('prod_6', 'Coca-Cola 33cl', 'Lattina di Coca-Cola servita fredda',
 2.50, 'https://images.unsplash.com/photo-1554866585-cd94860890b7?w=400', 'Bevande', 0, '[]', 6),
('prod_7', 'Acqua Naturale 50cl', 'Bottiglia di acqua naturale',
 1.00, 'https://images.unsplash.com/photo-1523362628745-0c100150b504?w=400', 'Bevande', 0, '[]', 7),
('prod_8', 'Patatine Fritte', 'Patate croccanti con sale marino',
 3.50, 'https://images.unsplash.com/photo-1573080496219-bb080dd4f877?w=400', 'Contorni', 1, '[]', 8),
('prod_9', 'Anelli di Cipolla', 'Anelli di cipolla dorati e croccanti',
 4.00, 'https://images.unsplash.com/photo-1573080496219-bb080dd4f877?w=400', 'Contorni', 0, '["Glutine"]', 9),
('prod_10', 'Tiramisù', 'Dolce italiano classico con mascarpone e caffè',
 4.50, 'https://images.unsplash.com/photo-1571877227200-a0d98ea607e9?w=400', 'Dolci', 0, '["Lattosio","Glutine","Uova"]', 10);

-- Collegamento prodotto <-> categoria (un prodotto può stare in più categorie)
INSERT INTO product_categories (productId, idCategory) VALUES
('prod_1', 'cat_1'),
('prod_2', 'cat_1'),
('prod_3', 'cat_1'),
('prod_4', 'cat_2'),
('prod_5', 'cat_2'),
('prod_6', 'cat_3'),
('prod_7', 'cat_3'),
('prod_8', 'cat_5'),
('prod_9', 'cat_5'),
('prod_10', 'cat_4');

-- Opzioni di personalizzazione (formato atteso dall'app: groups -> choises)
INSERT INTO product_options (productId, groups_data) VALUES
('prod_1', '[{"groupId":"grp_1_salse","groupName":"Salse","isRequired":false,"selectionType":"single","choises":[{"id":"ketchup","name":"Ketchup","priceModifier":0},{"id":"maionese","name":"Maionese","priceModifier":0},{"id":"bbq","name":"Salsa BBQ","priceModifier":0.5}]},{"groupId":"grp_1_extra","groupName":"Ingredienti extra","isRequired":false,"selectionType":"multi","choises":[{"id":"cheddar","name":"Extra Cheddar","priceModifier":1},{"id":"bacon","name":"Bacon Croccante","priceModifier":1.5},{"id":"cipolla","name":"Cipolla caramellata","priceModifier":0.5}]}]'),
('prod_2', '[{"groupId":"grp_2_cottura","groupName":"Cottura","isRequired":true,"selectionType":"single","choises":[{"id":"media","name":"Media","priceModifier":0},{"id":"ben_cotta","name":"Ben cotta","priceModifier":0}]}]'),
('prod_4', '[{"groupId":"grp_4_impasto","groupName":"Impasto","isRequired":false,"selectionType":"single","choises":[{"id":"classico","name":"Classico","priceModifier":0},{"id":"integrale","name":"Integrale","priceModifier":1}]}]');

-- Promozioni della home page
INSERT INTO promotions (id, title, subtitle, badges, ImageUrl, sortOrder) VALUES
('promo_1', 'Sconto di Benvenuto',
 'Usa il codice BENVENUTO10 e risparmia il 10% sul primo ordine.',
 '["-10%","Nuovi utenti"]',
 'https://images.unsplash.com/photo-1561758033-d89a9ad46330?w=400', 1),
('promo_2', 'Menu Pranzo',
 'Burger + patatine + bibita a 12,90 €.',
 '["Pranzo","12:00-15:00"]',
 'https://images.unsplash.com/photo-1513104890138-7c749659a591?w=400', 2),
('promo_3', 'Doppio Punti',
 'Accumula punti doppi su tutti gli ordini del weekend.',
 '["Weekend","Punti x2"]',
 'https://images.unsplash.com/photo-1550547660-d9450f859349?w=400', 3);

-- Punti vendita
INSERT INTO stores (id, name, address, distanceKm, readyInMinutes, openingHours, isPreferred) VALUES
('store_1', 'FoodApp Centro', 'Via Roma 1, 20100 Milano', 1.20, 15, '09:00 - 23:00', 1),
('store_2', 'FoodApp Nord', 'Corso Sempione 45, 20100 Milano', 3.50, 25, '11:00 - 00:00', 0),
('store_3', 'FoodApp Sud', 'Via Torino 12, 20100 Milano', 5.00, 30, '10:00 - 22:00', 0);

-- Slot di ritiro: immediato + orari programmati
INSERT INTO pickup_slots (id, immediated_label, immediated_availableAt, scheduled_slots) VALUES
(1, 'Prima possibile', '10-15 min',
 '["12:00","12:30","13:00","13:30","19:00","19:30","20:00","20:30"]');

-- Metodi di pagamento
INSERT INTO payment_methods (id, type, label, isDefault, sortOrder) VALUES
('card',   'card',   '💳 Carta di Credito / Google Pay', 1, 1),
('paypal', 'paypal', '💸 PayPal', 0, 2),
('cash',   'cash',   '💵 Contanti al ritiro', 0, 3);

-- Regole del carrello (una sola riga)
INSERT INTO cart_rules (id, pickupFee, managementFee, discountCode, discountPercent, currency) VALUES
(1, 1.50, 0.50, 'BENVENUTO10', 10, 'EUR');

-- ------------------------------------------------------------
-- Amministratore di esempio
--   Email:    admin@fastbite.local
--   Password: admin
--
-- ATTENZIONE: credenziale volutamente semplice per i primi test.
-- Cambiarla subito dal pannello o con:
--   php -r "echo password_hash('NuovaPassword', PASSWORD_DEFAULT), PHP_EOL;"
--   UPDATE admin_users SET password = '<HASH>' WHERE email = 'admin@fastbite.local';
-- ------------------------------------------------------------
INSERT INTO admin_users (name, email, password) VALUES
('Admin', 'admin@fastbite.local',
 '$2a$10$XF6My615WV7wkxlNvYH4xueaxOPxnAKhGtNp.4jj0Dl56KtMahZpG');

-- ------------------------------------------------------------
-- Conteggi categorie calcolati dai prodotti collegati
-- ------------------------------------------------------------
UPDATE categories c
SET c.count = (
    SELECT COUNT(DISTINCT pc.productId)
    FROM product_categories pc
    WHERE pc.idCategory = c.idCategory
);

-- ============================================================
-- VERIFICA FINALE
-- ============================================================
SELECT 'Tabelle create' AS esito, COUNT(*) AS totale
FROM information_schema.tables
WHERE table_schema = DATABASE();

SELECT c.idCategory, c.nameCategory, c.icon, c.count, c.sortOrder
FROM categories c
ORDER BY c.sortOrder;

SELECT COUNT(*) AS prodotti FROM products;
SELECT COUNT(*) AS amministratori FROM admin_users;

SELECT email, name FROM admin_users WHERE email = 'admin@fastbite.local';
