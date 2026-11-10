-- ============================================================
-- FastBite / FoodApp - Migrazione: prodotti in più categorie
--                            + ordine delle categorie
-- Database: AppFood (XAMPP / MariaDB)
--
-- Eseguire UNA VOLTA da phpMyAdmin (tab SQL) oppure:
--   mysql -u root AppFood < migrate_multi_category.sql
--
-- Lo script è ri-eseguibile senza perdere dati:
--   - la tabella product_categories viene creata solo se manca;
--   - la colonna sortOrder viene aggiunta solo se manca;
--   - il backfill usa INSERT IGNORE (niente duplicati);
--   - l'ordine iniziale viene assegnato solo alle categorie con sortOrder = 0.
-- ============================================================

USE AppFood;

-- ------------------------------------------------------------
-- 1) Tabella di collegamento prodotto <-> categoria
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS product_categories (
    productId VARCHAR(50) NOT NULL,
    idCategory VARCHAR(50) NOT NULL,
    PRIMARY KEY (productId, idCategory),
    KEY idx_product_categories_category (idCategory),
    CONSTRAINT fk_pc_product FOREIGN KEY (productId)
        REFERENCES products (IdProduct) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_pc_category FOREIGN KEY (idCategory)
        REFERENCES categories (idCategory) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 2) Ordine personalizzato delle categorie
--    (0 = mai ordinata; il pannello admin assegna 1..N)
-- ------------------------------------------------------------
ALTER TABLE categories ADD COLUMN IF NOT EXISTS sortOrder INT NOT NULL DEFAULT 0;

-- Ordine iniziale = quello attuale dell'API (idCategory), solo per le
-- categorie che non hanno ancora un ordine impostato.
SET @pos := 0;
UPDATE categories
SET sortOrder = (@pos := @pos + 1)
WHERE sortOrder = 0
ORDER BY idCategory;

-- ------------------------------------------------------------
-- 3) Backfill: la categoria salvata in products.category diventa
--    la prima categoria del prodotto (nome oppure id, come nel DB).
-- ------------------------------------------------------------
INSERT IGNORE INTO product_categories (productId, idCategory)
SELECT p.IdProduct, c.idCategory
FROM products p
JOIN categories c
  ON c.nameCategory = p.category OR c.idCategory = p.category;

-- ------------------------------------------------------------
-- 4) Conteggi categoria ricalcolati sui prodotti distinti
-- ------------------------------------------------------------
UPDATE categories c
SET c.count = (
    SELECT COUNT(DISTINCT pc.productId)
    FROM product_categories pc
    WHERE pc.idCategory = c.idCategory
);

-- ------------------------------------------------------------
-- 5) Controllo finale
-- ------------------------------------------------------------
SELECT c.idCategory, c.nameCategory, c.sortOrder, c.count
FROM categories c
ORDER BY c.sortOrder, c.nameCategory;

SELECT COUNT(*) AS prodotti_senza_categoria
FROM products p
LEFT JOIN product_categories pc ON pc.productId = p.IdProduct
WHERE pc.productId IS NULL;
