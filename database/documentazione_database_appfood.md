# Architettura Database AppFood

Questo documento descrive la struttura del database relazione `AppFood` (MySQL/MariaDB), inclusi i tipi di dati, le chiavi primarie, le chiavi esterne e il diagramma ERD (Entity-Relationship Diagram) generato con **Mermaid**.

---

## 📊 Diagramma Entity-Relationship (Mermaid)

```mermaid
erDiagram

    admin_users {
        int id PK
        varchar name
        varchar email UK
        varchar password
        timestamp created_at
    }

    categories {
        varchar idCategory PK
        varchar nameCategory
        varchar icon
        int count
        int sortOrder
    }

    products {
        varchar IdProduct PK
        varchar nameProduct
        text description
        decimal basePrice
        varchar imageUrl
        varchar category
        boolean isPopular
        json allergens
        int sortOrder
    }

    product_categories {
        varchar productId PK, FK
        varchar idCategory PK, FK
    }

    promotions {
        varchar id PK
        varchar title
        text subtitle
        json badges
        varchar ImageUrl
        int sortOrder
    }

    stores {
        varchar id PK
        varchar name
        text address
        decimal distanceKm
        int readyInMinutes
        varchar openingHours
        boolean isPreferred
    }

    pickup_slots {
        int id PK
        varchar immediated_label
        varchar immediated_availableAt
        json scheduled_slots
    }

    payment_methods {
        varchar id PK
        varchar type
        varchar label
        boolean isDefault
        int sortOrder
    }

    cart_rules {
        int id PK
        decimal pickupFee
        decimal managementFee
        varchar discountCode
        int discountPercent
        varchar currency
    }

    product_options {
        int id PK
        varchar productId FK
        json groups_data
    }

    users {
        varchar userId PK
        varchar name
        varchar email UK
        varchar password
        varchar phone
        text address
        varchar favoriteStoreId
        varchar authProvider
        varchar socialId
        timestamp created_at
        timestamp updated_at
    }

    sessions {
        varchar token PK
        varchar userId FK
        datetime expires_at
        timestamp created_at
    }

    orders {
        varchar orderId PK
        varchar userId FK
        varchar storeId FK
        varchar pickupType
        datetime scheduledTime
        varchar discountCode
        decimal subTotal
        decimal discount
        decimal managementFee
        decimal total
        varchar status
        varchar pickupPin
        varchar pickupTime
        varchar qrCodeUrl
        timestamp created_at
        timestamp updated_at
    }

    order_items {
        int id PK
        varchar orderId FK
        varchar productId FK
        varchar productName
        decimal basePrice
        int quantity
        json selectedOptions
        decimal optionPriceModifier
    }

    payment_history {
        varchar paymentId PK
        varchar orderId FK
        decimal amount
        varchar method
        varchar status
        datetime payment_date
    }

    %% Relazioni tra le entità
    products ||--o{ product_categories : "appartiene a"
    categories ||--o{ product_categories : "contiene"
    products ||--o{ product_options : "ha"
    users ||--o{ sessions : "possiede"
    users ||--o{ orders : "effettua"
    stores ||--o{ orders : "gestisce"
    orders ||--o{ order_items : "contiene"
    products ||--o{ order_items : "riferisce"
    orders ||--o{ payment_history : "ha"
```

---

## 📂 Struttura Dettagliata delle Tabelle

### 1. Amministrazione e Utenti
* **`admin_users`**: Account di amministrazione del sistema.
* **`users`**: Profilo dell'utente cliente, con supporto ad autenticazione locale e social.
* **`sessions`**: Gestione dei token di sessione per l'autenticazione degli utenti.

### 2. Catalogo Prodotti e Categorie
* **`categories`**: Categorie principali dei prodotti.
* **`products`**: Prodotti disponibili nell'applicazione.
* **`product_categories`**: Tabella di junction (molti-a-molti) tra prodotti e categorie.
* **`product_options`**: Varianti e personalizzazioni dei prodotti (es. ingredienti extra, formato) salvate in formato `JSON`.

### 3. Negozi e Promozioni
* **`stores`**: Punti vendita con orari di apertura e distanza.
* **`promotions`**: Banner promozionali in home page.
* **`pickup_slots`**: Slot temporali disponibili per il ritiro.

### 4. Ordini e Pagamenti
* **`orders`**: Testata dell'ordine contenente totali, stato, cliente e negozio di riferimento.
* **`order_items`**: Righe di dettaglio dell'ordine (quantità, prezzo unitario e opzioni selezionate).
* **`payment_history`**: Storico dei tentativi e delle transazioni di pagamento.
* **`payment_methods`**: Metodi di pagamento registrati.
* **`cart_rules`**: Regole del carrello (commissioni, codici sconto globali).

---

## 🔗 Regole di Integrità Referenziale (Foreign Keys)
* `product_categories` $\rightarrow$ `products` (`CASCADE` ON DELETE/UPDATE)
* `product_categories` $\rightarrow$ `categories` (`CASCADE` ON DELETE/UPDATE)
* `product_options` $\rightarrow$ `products` (`CASCADE` ON DELETE)
* `sessions` $\rightarrow$ `users` (`CASCADE` ON DELETE)
* `orders` $\rightarrow$ `users` (`SET NULL` ON DELETE)
* `orders` $\rightarrow$ `stores` (`SET NULL` ON DELETE)
* `order_items` $\rightarrow$ `orders` (`CASCADE` ON DELETE)
* `order_items` $\rightarrow$ `products` (`SET NULL` ON DELETE)
* `payment_history` $\rightarrow$ `orders` (`SET NULL` ON DELETE)