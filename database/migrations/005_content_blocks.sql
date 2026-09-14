-- 005_content_blocks.sql
-- Editor-managed marketing content (doc 8.7).
--
-- Generated from section 8 of Rajdhani_Project_Doc.md v3.0, in foreign-key
-- dependency order. Forward-only: never edit this file once it has been
-- applied to any environment (doc 16.4).

CREATE TABLE banners (
  id                  CHAR(26)     NOT NULL,
  placement           ENUM('HOME_HERO','ABOUT_HERO','PRODUCTS_HERO','PRODUCT_DETAIL_HERO',
                           'QUALITY_HERO','DEALER_HERO','GALLERY_HERO','NEWS_HERO','CONTACT_HERO',
                           'HOME_PROMO','HOME_VIDEO_CARD','MID_PAGE_CTA','DEALER_CTA','SIDEBAR_AD') NOT NULL,
  title               VARCHAR(255) NULL,
  title_highlight     VARCHAR(255) NULL,      -- coloured portion of the headline
  subtitle            TEXT         NULL,
  eyebrow_text        VARCHAR(255) NULL,      -- 'PREMIUM QUALITY TEA'
  desktop_image_id    CHAR(26)     NULL,
  mobile_image_id     CHAR(26)     NULL,
  video_url           VARCHAR(512) NULL,
  primary_cta_label   VARCHAR(128) NULL,
  primary_cta_url     VARCHAR(255) NULL,
  secondary_cta_label VARCHAR(128) NULL,
  secondary_cta_url   VARCHAR(255) NULL,
  overlay_opacity     INT          NULL DEFAULT 0,
  sort_order          INT          NOT NULL DEFAULT 0,
  status              ENUM('DRAFT','PUBLISHED','ARCHIVED') NOT NULL DEFAULT 'PUBLISHED',
  starts_at           DATETIME(3)  NULL,      -- scheduling window
  ends_at             DATETIME(3)  NULL,
  created_at          DATETIME(3)  NOT NULL,
  updated_at          DATETIME(3)  NOT NULL,
  deleted_at          DATETIME(3)  NULL,
  PRIMARY KEY (id),
  KEY ix_banners_placement (placement, status, sort_order),
  KEY ix_banners_schedule  (starts_at, ends_at),
  KEY ix_banners_desktop_image (desktop_image_id),
  KEY ix_banners_mobile_image  (mobile_image_id),
  CONSTRAINT fk_banners_desktop FOREIGN KEY (desktop_image_id) REFERENCES media_assets (id),
  CONSTRAINT fk_banners_mobile  FOREIGN KEY (mobile_image_id)  REFERENCES media_assets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE feature_items (
  id            CHAR(26)     NOT NULL,
  section       ENUM('HOME_USP','HOME_WHY_US','ABOUT_VALUES','ABOUT_STRENGTH',
                     'QUALITY_COMMITMENT','DEALER_BENEFITS','CONTACT_ASSURANCE',
                     'PRODUCT_HIGHLIGHTS') NOT NULL,
  title         VARCHAR(255) NOT NULL,        -- 'Made with Real Milk'
  description   TEXT         NULL,
  icon_name     VARCHAR(64)  NULL,
  icon_image_id CHAR(26)     NULL,
  icon_bg_color VARCHAR(9)   NULL,
  sort_order    INT          NOT NULL DEFAULT 0,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY ix_feature_items_section (section, is_active, sort_order),
  KEY ix_feature_items_icon (icon_image_id),
  CONSTRAINT fk_feature_items_icon FOREIGN KEY (icon_image_id) REFERENCES media_assets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE process_steps (
  id          CHAR(26)     NOT NULL,
  `group`     ENUM('FROM_GARDEN_TO_CUP','HOW_WE_MAKE_TEA','QUALITY_PROCESS',
                   'MANUFACTURING_PROCESS','BECOME_DEALER') NOT NULL,
  step_number INT          NOT NULL,          -- 1..5
  title       VARCHAR(255) NOT NULL,          -- 'Carefully Sourced'
  description TEXT         NULL,
  icon_name   VARCHAR(64)  NULL,
  image_id    CHAR(26)     NULL,
  sort_order  INT          NOT NULL DEFAULT 0,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_process_steps_group_number (`group`, step_number),
  KEY ix_process_steps_group (`group`, is_active, sort_order),
  KEY ix_process_steps_image (image_id),
  CONSTRAINT fk_process_steps_image FOREIGN KEY (image_id) REFERENCES media_assets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stat_counters (
  id         CHAR(26)     NOT NULL,
  `group`    ENUM('HOME','ABOUT','GALLERY','TEA_GARDEN','DEALER_NETWORK') NOT NULL,
  value      VARCHAR(32)  NOT NULL,           -- '25+', '1000+', '100%'
  label      VARCHAR(255) NOT NULL,           -- 'Years of Experience'
  icon_name  VARCHAR(64)  NULL,
  sort_order INT          NOT NULL DEFAULT 0,
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY ix_stat_counters_group (`group`, is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE certifications (
  id                  CHAR(26)     NOT NULL,
  name                VARCHAR(255) NOT NULL,  -- 'ISO 22000:2018'
  subtitle            VARCHAR(255) NULL,      -- 'Food Safety Management'
  logo_id             CHAR(26)     NULL,
  certificate_file_id CHAR(26)     NULL,      -- downloadable PDF
  sort_order          INT          NOT NULL DEFAULT 0,
  is_active           TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY ix_certifications_active (is_active, sort_order),
  KEY ix_certifications_logo (logo_id),
  KEY ix_certifications_file (certificate_file_id),
  CONSTRAINT fk_certifications_logo FOREIGN KEY (logo_id)             REFERENCES media_assets (id),
  CONSTRAINT fk_certifications_file FOREIGN KEY (certificate_file_id) REFERENCES media_assets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE testimonials (
  id          CHAR(26)     NOT NULL,
  author_name VARCHAR(255) NOT NULL,          -- 'Ahmed Hossain'
  author_role VARCHAR(255) NULL,              -- 'Distributor, Chattogram'
  avatar_id   CHAR(26)     NULL,
  quote       TEXT         NOT NULL,
  rating      TINYINT UNSIGNED NULL,
  status      ENUM('DRAFT','PUBLISHED','ARCHIVED') NOT NULL DEFAULT 'PUBLISHED',
  sort_order  INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY ix_testimonials_status (status, sort_order),
  KEY ix_testimonials_avatar (avatar_id),
  CONSTRAINT fk_testimonials_avatar FOREIGN KEY (avatar_id) REFERENCES media_assets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE page_blocks (
  id            CHAR(26)     NOT NULL,
  page_key      VARCHAR(64)  NOT NULL,        -- 'about' | 'quality' | 'dealer'
  block_key     VARCHAR(64)  NOT NULL,        -- 'our_story' | 'mission' | 'vision'
  eyebrow       VARCHAR(255) NULL,
  heading       VARCHAR(255) NULL,
  subheading    VARCHAR(255) NULL,
  body          MEDIUMTEXT   NULL,            -- rich text
  bullet_points JSON         NULL,            -- string[]
  image_id      CHAR(26)     NULL,
  cta_label     VARCHAR(128) NULL,
  cta_url       VARCHAR(255) NULL,
  sort_order    INT          NOT NULL DEFAULT 0,
  status        ENUM('DRAFT','PUBLISHED','ARCHIVED') NOT NULL DEFAULT 'PUBLISHED',
  PRIMARY KEY (id),
  UNIQUE KEY uq_page_blocks (page_key, block_key),
  KEY ix_page_blocks_page (page_key, status, sort_order),
  KEY ix_page_blocks_image (image_id),
  CONSTRAINT fk_page_blocks_image FOREIGN KEY (image_id) REFERENCES media_assets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
