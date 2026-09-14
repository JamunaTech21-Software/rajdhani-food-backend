-- 003_catalogue.sql
-- Products and their child collections (doc 8.5).
--
-- Generated from section 8 of Rajdhani_Project_Doc.md v3.0, in foreign-key
-- dependency order. Forward-only: never edit this file once it has been
-- applied to any environment (doc 16.4).

CREATE TABLE categories (
  id               CHAR(26)     NOT NULL,
  name             VARCHAR(255) NOT NULL,      -- 'Premium Tea', 'Green Tea'
  slug             VARCHAR(191) NOT NULL,
  description      TEXT         NULL,
  icon_name        VARCHAR(64)  NULL,          -- icon key for the filter bar
  image_id         CHAR(26)     NULL,
  sort_order       INT          NOT NULL DEFAULT 0,
  is_active        TINYINT(1)   NOT NULL DEFAULT 1,
  meta_title       VARCHAR(255) NULL,
  meta_description TEXT         NULL,
  created_at       DATETIME(3)  NOT NULL,
  updated_at       DATETIME(3)  NOT NULL,
  deleted_at       DATETIME(3)  NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categories_slug (slug),
  KEY ix_categories_active (is_active, sort_order),
  KEY ix_categories_image (image_id),
  CONSTRAINT fk_categories_image FOREIGN KEY (image_id) REFERENCES media_assets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE products (
  id                CHAR(26)     NOT NULL,
  category_id       CHAR(26)     NOT NULL,
  name              VARCHAR(255) NOT NULL,
  slug              VARCHAR(191) NOT NULL,
  short_description TEXT         NULL,         -- card blurb
  tagline           VARCHAR(255) NULL,
  description       MEDIUMTEXT   NULL,         -- rich text, Description tab
  ingredients       MEDIUMTEXT   NULL,
  nutrition_info    MEDIUMTEXT   NULL,
  brewing_guide     MEDIUMTEXT   NULL,
  packaging_info    MEDIUMTEXT   NULL,
  key_features      JSON         NULL,         -- string[]
  badge_text        VARCHAR(64)  NULL,         -- 'BEST SELLER'
  badge_color       VARCHAR(9)   NULL,
  status            ENUM('DRAFT','PUBLISHED','ARCHIVED') NOT NULL DEFAULT 'DRAFT',
  is_featured       TINYINT(1)   NOT NULL DEFAULT 0,
  sort_order        INT          NOT NULL DEFAULT 0,
  view_count        INT          NOT NULL DEFAULT 0,
  rating_average    DECIMAL(2,1) NOT NULL DEFAULT 0.0,
  rating_count      INT          NOT NULL DEFAULT 0,
  meta_title        VARCHAR(255) NULL,
  meta_description  TEXT         NULL,
  created_at        DATETIME(3)  NOT NULL,
  updated_at        DATETIME(3)  NOT NULL,
  deleted_at        DATETIME(3)  NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_products_slug (slug),
  KEY ix_products_status          (status, sort_order),
  KEY ix_products_category_status (category_id, status, sort_order),
  KEY ix_products_featured        (is_featured, status, sort_order),
  CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_images (
  id         CHAR(26)   NOT NULL,
  product_id CHAR(26)   NOT NULL,
  media_id   CHAR(26)   NOT NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT        NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY ix_product_images_product (product_id, sort_order),
  KEY ix_product_images_media   (media_id),
  CONSTRAINT fk_product_images_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
  CONSTRAINT fk_product_images_media   FOREIGN KEY (media_id)   REFERENCES media_assets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_pack_sizes (
  id                 CHAR(26)      NOT NULL,
  product_id         CHAR(26)      NOT NULL,
  label              VARCHAR(64)   NOT NULL,  -- '250g', '500g', '1kg'
  sku                VARCHAR(64)   NOT NULL,  -- 'RPT-500'
  price              DECIMAL(10,2) NOT NULL,
  compare_price      DECIMAL(10,2) NULL,      -- struck-through original
  discount_percent   INT           NULL,
  price_includes_vat TINYINT(1)    NOT NULL DEFAULT 1,
  is_default         TINYINT(1)    NOT NULL DEFAULT 0,
  is_available       TINYINT(1)    NOT NULL DEFAULT 1,
  sort_order         INT           NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pack_sizes_product_label (product_id, label),
  UNIQUE KEY uq_pack_sizes_sku (sku),
  KEY ix_pack_sizes_product (product_id, sort_order),
  CONSTRAINT fk_pack_sizes_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_highlights (
  id         CHAR(26)     NOT NULL,
  product_id CHAR(26)     NOT NULL,
  title      VARCHAR(255) NOT NULL,           -- '100% Natural'
  subtitle   VARCHAR(255) NULL,
  icon_name  VARCHAR(64)  NULL,
  sort_order INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY ix_product_highlights_product (product_id, sort_order),
  CONSTRAINT fk_product_highlights_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
