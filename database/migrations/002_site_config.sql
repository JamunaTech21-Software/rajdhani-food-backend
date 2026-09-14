-- 002_site_config.sql
-- Site identity and configuration (doc 8.2). site_profile is a singleton.
--
-- Generated from section 8 of Rajdhani_Project_Doc.md v3.0, in foreign-key
-- dependency order. Forward-only: never edit this file once it has been
-- applied to any environment (doc 16.4).

CREATE TABLE site_profile (
  id               TINYINT UNSIGNED NOT NULL DEFAULT 1,   -- singleton; see note below
  name             VARCHAR(255)  NOT NULL,                -- 'Rajdhani Food Products'
  tagline          VARCHAR(255)  NULL,

  logo_light_id    CHAR(26)      NULL,
  logo_dark_id     CHAR(26)      NULL,
  favicon_id       CHAR(26)      NULL,
  og_image_id      CHAR(26)      NULL,

  primary_color    VARCHAR(9)    NOT NULL DEFAULT '#1B5E20',
  secondary_color  VARCHAR(9)    NOT NULL DEFAULT '#C9A227',
  accent_color     VARCHAR(9)    NOT NULL DEFAULT '#FFFFFF',

  address_line     VARCHAR(255)  NULL,
  city             VARCHAR(128)  NULL,
  country          VARCHAR(128)  NULL DEFAULT 'Bangladesh',
  phone_primary    VARCHAR(32)   NULL,
  phone_secondary  VARCHAR(32)   NULL,
  email_primary    VARCHAR(255)  NULL,
  email_secondary  VARCHAR(255)  NULL,
  website_url      VARCHAR(255)  NULL,
  business_hours   VARCHAR(255)  NULL,
  map_latitude     DOUBLE        NULL,
  map_longitude    DOUBLE        NULL,
  map_embed_url    TEXT          NULL,
  footer_about     TEXT          NULL,
  copyright_text   VARCHAR(255)  NULL,

  meta_title       VARCHAR(255)  NULL,
  meta_description TEXT          NULL,

  created_at       DATETIME(3)   NOT NULL,
  updated_at       DATETIME(3)   NOT NULL,

  PRIMARY KEY (id),
  KEY ix_site_profile_logo_light (logo_light_id),
  KEY ix_site_profile_logo_dark  (logo_dark_id),
  KEY ix_site_profile_favicon    (favicon_id),
  KEY ix_site_profile_og_image   (og_image_id),
  CONSTRAINT ck_site_profile_singleton CHECK (id = 1),
  CONSTRAINT fk_site_profile_logo_light FOREIGN KEY (logo_light_id) REFERENCES media_assets (id),
  CONSTRAINT fk_site_profile_logo_dark  FOREIGN KEY (logo_dark_id)  REFERENCES media_assets (id),
  CONSTRAINT fk_site_profile_favicon    FOREIGN KEY (favicon_id)    REFERENCES media_assets (id),
  CONSTRAINT fk_site_profile_og_image   FOREIGN KEY (og_image_id)   REFERENCES media_assets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
  id         CHAR(26)     NOT NULL,
  `key`      VARCHAR(128) NOT NULL,          -- 'enquiry_notify_emails', 'gtm_id'
  value      TEXT         NOT NULL,
  `group`    VARCHAR(64)  NULL,              -- 'notifications' | 'analytics' | 'general'
  updated_at DATETIME(3)  NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_settings_key (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE social_links (
  id         CHAR(26)     NOT NULL,
  platform   VARCHAR(64)  NOT NULL,          -- facebook | instagram | linkedin | youtube | whatsapp
  url        VARCHAR(255) NOT NULL,
  icon_name  VARCHAR(64)  NULL,
  sort_order INT          NOT NULL DEFAULT 0,
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY ix_social_links_active (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE menu_links (
  id              CHAR(26)     NOT NULL,
  location        VARCHAR(64)  NOT NULL,     -- 'header' | 'footer_quick' | 'footer_products' | 'legal'
  label           VARCHAR(128) NOT NULL,
  url             VARCHAR(255) NOT NULL,
  parent_id       CHAR(26)     NULL,
  sort_order      INT          NOT NULL DEFAULT 0,
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  open_in_new_tab TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY ix_menu_links_location (location, is_active, sort_order),
  KEY ix_menu_links_parent (parent_id),
  CONSTRAINT fk_menu_links_parent FOREIGN KEY (parent_id) REFERENCES menu_links (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE seo_meta (
  id               CHAR(26)     NOT NULL,
  page_key         VARCHAR(64)  NOT NULL,    -- 'home' | 'about' | 'products' | 'quality' ...
  meta_title       VARCHAR(255) NULL,
  meta_description TEXT         NULL,
  meta_keywords    VARCHAR(255) NULL,
  og_image_id      CHAR(26)     NULL,
  no_index         TINYINT(1)   NOT NULL DEFAULT 0,
  canonical_url    VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_seo_meta_page (page_key),
  KEY ix_seo_meta_og_image (og_image_id),
  CONSTRAINT fk_seo_meta_og_image FOREIGN KEY (og_image_id) REFERENCES media_assets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reference_counters (
  id         CHAR(26)          NOT NULL,
  type       ENUM('ENQ','DA')  NOT NULL,
  year       SMALLINT UNSIGNED NOT NULL,
  last_seq   INT UNSIGNED      NOT NULL DEFAULT 0,   -- not `last_value`: reserved (window function)
  updated_at DATETIME(3)       NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reference_counters (type, year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
