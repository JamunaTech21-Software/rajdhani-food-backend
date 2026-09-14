-- 004_engagement.sql
-- Customer-generated content (doc 8.6).
--
-- Generated from section 8 of Rajdhani_Project_Doc.md v3.0, in foreign-key
-- dependency order. Forward-only: never edit this file once it has been
-- applied to any environment (doc 16.4).

CREATE TABLE reviews (
  id               CHAR(26)     NOT NULL,
  product_id       CHAR(26)     NOT NULL,
  customer_id      CHAR(26)     NOT NULL,
  rating           TINYINT UNSIGNED NOT NULL,     -- 1..5, enforced in the validator
  title            VARCHAR(255) NULL,
  comment          TEXT         NOT NULL,
  status           ENUM('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING',
  moderated_by_id  CHAR(26)     NULL,
  moderated_at     DATETIME(3)  NULL,
  rejection_reason VARCHAR(512) NULL,
  created_at       DATETIME(3)  NOT NULL,
  updated_at       DATETIME(3)  NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reviews_product_customer (product_id, customer_id),  -- one per customer per product
  KEY ix_reviews_product_status (product_id, status),
  KEY ix_reviews_status_created (status, created_at),
  KEY ix_reviews_customer       (customer_id),
  KEY ix_reviews_moderator      (moderated_by_id),
  CONSTRAINT fk_reviews_product   FOREIGN KEY (product_id)      REFERENCES products (id)    ON DELETE CASCADE,
  CONSTRAINT fk_reviews_customer  FOREIGN KEY (customer_id)     REFERENCES customers (id)   ON DELETE CASCADE,
  CONSTRAINT fk_reviews_moderator FOREIGN KEY (moderated_by_id) REFERENCES admin_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE wishlist_items (
  id          CHAR(26)    NOT NULL,
  customer_id CHAR(26)    NOT NULL,
  product_id  CHAR(26)    NOT NULL,
  created_at  DATETIME(3) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wishlist_customer_product (customer_id, product_id),
  KEY ix_wishlist_product (product_id),
  CONSTRAINT fk_wishlist_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE,
  CONSTRAINT fk_wishlist_product  FOREIGN KEY (product_id)  REFERENCES products (id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
