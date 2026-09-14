-- 006_gallery_news.sql
-- Gallery, news and downloadable files (doc 8.7).
--
-- Generated from section 8 of Rajdhani_Project_Doc.md v3.0, in foreign-key
-- dependency order. Forward-only: never edit this file once it has been
-- applied to any environment (doc 16.4).

CREATE TABLE gallery_categories (
  id             CHAR(26)     NOT NULL,
  name           VARCHAR(255) NOT NULL,       -- 'Tea Gardens', 'Manufacturing'
  slug           VARCHAR(191) NOT NULL,
  description    TEXT         NULL,
  icon_name      VARCHAR(64)  NULL,
  cover_image_id CHAR(26)     NULL,
  sort_order     INT          NOT NULL DEFAULT 0,
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_gallery_categories_slug (slug),
  KEY ix_gallery_categories_active (is_active, sort_order),
  KEY ix_gallery_categories_cover (cover_image_id),
  CONSTRAINT fk_gallery_categories_cover FOREIGN KEY (cover_image_id) REFERENCES media_assets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE gallery_images (
  id          CHAR(26)     NOT NULL,
  category_id CHAR(26)     NOT NULL,
  media_id    CHAR(26)     NOT NULL,
  title       VARCHAR(255) NULL,
  description VARCHAR(512) NULL,
  sort_order  INT          NOT NULL DEFAULT 0,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  DATETIME(3)  NOT NULL,
  PRIMARY KEY (id),
  KEY ix_gallery_images_category (category_id, is_active, sort_order),
  KEY ix_gallery_images_media (media_id),
  CONSTRAINT fk_gallery_images_category FOREIGN KEY (category_id) REFERENCES gallery_categories (id) ON DELETE CASCADE,
  CONSTRAINT fk_gallery_images_media    FOREIGN KEY (media_id)    REFERENCES media_assets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE news_posts (
  id               CHAR(26)     NOT NULL,
  title            VARCHAR(255) NOT NULL,
  slug             VARCHAR(191) NOT NULL,
  excerpt          TEXT         NULL,
  content          MEDIUMTEXT   NOT NULL,     -- rich text
  cover_image_id   CHAR(26)     NULL,
  tags             JSON         NULL,         -- string[]
  status           ENUM('DRAFT','PUBLISHED','ARCHIVED') NOT NULL DEFAULT 'DRAFT',
  is_featured      TINYINT(1)   NOT NULL DEFAULT 0,
  published_at     DATETIME(3)  NULL,
  view_count       INT          NOT NULL DEFAULT 0,
  author_id        CHAR(26)     NULL,
  meta_title       VARCHAR(255) NULL,
  meta_description TEXT         NULL,
  created_at       DATETIME(3)  NOT NULL,
  updated_at       DATETIME(3)  NOT NULL,
  deleted_at       DATETIME(3)  NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_news_posts_slug (slug),
  KEY ix_news_posts_status (status, published_at),
  KEY ix_news_posts_author (author_id),
  KEY ix_news_posts_cover (cover_image_id),
  CONSTRAINT fk_news_posts_author FOREIGN KEY (author_id)      REFERENCES admin_users (id),
  CONSTRAINT fk_news_posts_cover  FOREIGN KEY (cover_image_id) REFERENCES media_assets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE downloads (
  id             CHAR(26)     NOT NULL,
  title          VARCHAR(255) NOT NULL,       -- 'Dealer Brochure'
  `key`          VARCHAR(64)  NOT NULL,       -- 'dealer_brochure'
  description    VARCHAR(512) NULL,
  file_id        CHAR(26)     NOT NULL,
  requires_email TINYINT(1)   NOT NULL DEFAULT 0,
  download_count INT          NOT NULL DEFAULT 0,
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_downloads_key (`key`),
  KEY ix_downloads_file (file_id),
  CONSTRAINT fk_downloads_file FOREIGN KEY (file_id) REFERENCES media_assets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
