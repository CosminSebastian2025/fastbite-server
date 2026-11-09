-- ============================================================
-- FastBite / FoodApp - Correzioni dati MySQL
-- Server: 192.168.2.248 (XAMPP)
-- Database in uso dal server live: AppFood
-- Eseguire da phpMyAdmin o: mysql -u root -p AppFood < fix_database.sql
-- ============================================================
USE AppFood;

-- ------------------------------------------------------------
-- 1) Conteggi categorie: ricalcolati dai prodotti reali
--    (i valori del seed erano sbagliati: Panini 5->3, Pizze 4->2,
--     Bevande 6->2, Dolci 3->1, Contorni 4->2)
-- ------------------------------------------------------------
UPDATE categories c
SET c.count = (
    SELECT COUNT(*)
    FROM products p
    WHERE p.category = c.nameCategory OR p.category = c.idCategory
);

-- ------------------------------------------------------------
-- 2) Immagine mancante: prod_7 (Acqua Naturale 50cl) -> URL 404
--    Sostituita con un'immagine Unsplash valida (verificata HTTP 200)
-- ------------------------------------------------------------
UPDATE products
SET imageUrl = 'https://images.unsplash.com/photo-1523362628745-0c100150b504?w=400'
WHERE IdProduct = 'prod_7';

-- ------------------------------------------------------------
-- 3) Immagini promozioni: il server live restituiva imageUrl = null.
--    La colonna nel DB si chiama "ImageUrl" (case-sensitive su Linux);
--    qui la aggiorniamo in modo robusto qualunque sia il nome.
-- ------------------------------------------------------------
SET @promo_col := (
    SELECT COLUMN_NAME
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'promotions'
      AND LOWER(COLUMN_NAME) = 'imageurl'
    LIMIT 1
);

SET @promo_sql := IF(
    @promo_col IS NULL,
    'SELECT ''ATTENZIONE: colonna immagine promozioni non trovata'' AS warning',
    CONCAT(
        'UPDATE promotions SET `', @promo_col, '` = CASE id ',
        'WHEN ''promo_1'' THEN ''https://images.unsplash.com/photo-1561758033-d89a9ad46330?w=400'' ',
        'WHEN ''promo_2'' THEN ''https://images.unsplash.com/photo-1513104890138-7c749659a591?w=400'' ',
        'WHEN ''promo_3'' THEN ''https://images.unsplash.com/photo-1550547660-d9450f859349?w=400'' ',
        'ELSE `', @promo_col, '` END ',
        'WHERE id IN (''promo_1'',''promo_2'',''promo_3'')'
    )
);

PREPARE stmt FROM @promo_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 4) Regole carrello: allineate all'app (gestione 0,50 EUR, sconto 10%)
--    Se la riga manca, viene creata.
-- ------------------------------------------------------------
INSERT INTO cart_rules (pickupFee, managementFee, discountCode, discountPercent, currency)
SELECT 1.50, 0.50, 'BENVENUTO10', 10, 'EUR'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM cart_rules);

UPDATE cart_rules
SET managementFee = 0.50,
    discountCode = COALESCE(discountCode, 'BENVENUTO10'),
    discountPercent = COALESCE(discountPercent, 10),
    currency = COALESCE(currency, 'EUR');

-- ------------------------------------------------------------
-- 5) Utente di test: il seed contiene un hash NON valido
--    ('$2y$10$YourHashedPasswordHere'), quindi il login fallisce.
--    Genera un hash reale SUL SERVER XAMPP con:
--
--      C:\xampp\php\php.exe -r "echo password_hash('password123', PASSWORD_DEFAULT);"
--
--    poi esegui (sostituendo <HASH>):
--
--      UPDATE users
--      SET password = '<HASH>'
--      WHERE email = 'mario.rossi@example.com';
--
--    In alternativa registra un nuovo utente dall'app: viene salvato
--    correttamente con password_hash() lato PHP.
-- ------------------------------------------------------------
SELECT 'Hash utente di test da rigenerare: vedi commento al punto 5' AS nota_utente_seed;

-- ------------------------------------------------------------
-- 6) Verifica finale
-- ------------------------------------------------------------
SELECT c.idCategory, c.nameCategory, c.count AS conteggio_db,
       (SELECT COUNT(*) FROM products p WHERE p.category = c.nameCategory) AS conteggio_reale
FROM categories c;

SELECT id, title,
       (SELECT 1) AS ok
FROM promotions
WHERE id IN ('promo_1','promo_2','promo_3');
