-- 001_core_accounts.sql
-- Media, both account types, tokens and the audit trail (doc 8.3, 8.4).
--
-- Generated from section 8 of Rajdhani_Project_Doc.md v3.0, in foreign-key
-- dependency order. Forward-only: never edit this file once it has been
-- applied to any environment (doc 16.4).

CREATE TABLE media_assets (
  id             CHAR(26)     NOT NULL,
  public_id      VARCHAR(255) NOT NULL,       -- Cloudinary public_id
  secure_url     VARCHAR(512) NOT NULL,
  type           ENUM('IMAGE','VIDEO','DOCUMENT') NOT NULL DEFAULT 'IMAGE',
  format         VARCHAR(16)  NULL,           -- jpg | png | webp | pdf
  width          INT          NULL,
  height         INT          NULL,
  bytes          INT          NULL,
  folder         VARCHAR(255) NULL,           -- 'rajdhani/products'
  alt_text       VARCHAR(255) NULL,
  caption        VARCHAR(512) NULL,
  uploaded_by_id CHAR(26)     NULL,
  created_at     DATETIME(3)  NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_media_assets_public_id (public_id),
  KEY ix_media_assets_folder (folder),
  KEY ix_media_assets_type   (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE admin_users (
  id                CHAR(26)     NOT NULL,
  name              VARCHAR(255) NOT NULL,
  email             VARCHAR(255) NOT NULL,
  password_hash     VARCHAR(255) NULL,        -- NULL until the invite is accepted
  role              ENUM('SUPER_ADMIN','EDITOR','SALES') NOT NULL DEFAULT 'EDITOR',
  avatar_id         CHAR(26)     NULL,
  phone             VARCHAR(32)  NULL,
  is_active         TINYINT(1)   NOT NULL DEFAULT 1,
  last_login_at     DATETIME(3)  NULL,
  invite_token      CHAR(64)     NULL,
  invite_expires_at DATETIME(3)  NULL,
  reset_token       CHAR(64)     NULL,
  reset_expires_at  DATETIME(3)  NULL,
  created_by_id     CHAR(26)     NULL,
  created_at        DATETIME(3)  NOT NULL,
  updated_at        DATETIME(3)  NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_admin_users_email        (email),
  UNIQUE KEY uq_admin_users_invite_token (invite_token),
  UNIQUE KEY uq_admin_users_reset_token  (reset_token),
  KEY ix_admin_users_avatar  (avatar_id),
  KEY ix_admin_users_creator (created_by_id),
  CONSTRAINT fk_admin_users_avatar  FOREIGN KEY (avatar_id)     REFERENCES media_assets (id),
  CONSTRAINT fk_admin_users_creator FOREIGN KEY (created_by_id) REFERENCES admin_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customers (
  id             CHAR(26)     NOT NULL,
  google_id      VARCHAR(64)  NULL,
  email          VARCHAR(255) NOT NULL,
  name           VARCHAR(255) NOT NULL,
  avatar_url     VARCHAR(512) NULL,
  phone          VARCHAR(32)  NULL,
  city           VARCHAR(128) NULL,
  company_name   VARCHAR(255) NULL,
  email_verified TINYINT(1)   NOT NULL DEFAULT 1,   -- Google-verified
  is_blocked     TINYINT(1)   NOT NULL DEFAULT 0,
  last_login_at  DATETIME(3)  NULL,
  created_at     DATETIME(3)  NOT NULL,
  updated_at     DATETIME(3)  NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_customers_google_id (google_id),
  UNIQUE KEY uq_customers_email     (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE refresh_tokens (
  id          CHAR(26)     NOT NULL,
  jti         CHAR(36)     NOT NULL,
  token_hash  VARCHAR(255) NOT NULL,
  audience    ENUM('customer','admin') NOT NULL,
  admin_id    CHAR(26)     NULL,
  customer_id CHAR(26)     NULL,
  family_id   CHAR(36)     NOT NULL,          -- rotation family, for reuse detection
  user_agent  VARCHAR(512) NULL,
  ip_address  VARCHAR(45)  NULL,              -- 45 chars fits IPv6
  expires_at  DATETIME(3)  NOT NULL,
  revoked_at  DATETIME(3)  NULL,
  created_at  DATETIME(3)  NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_refresh_tokens_jti (jti),
  KEY ix_refresh_tokens_admin    (admin_id),
  KEY ix_refresh_tokens_customer (customer_id),
  KEY ix_refresh_tokens_family   (family_id),
  KEY ix_refresh_tokens_expires  (expires_at),
  CONSTRAINT fk_refresh_tokens_admin    FOREIGN KEY (admin_id)    REFERENCES admin_users (id) ON DELETE CASCADE,
  CONSTRAINT fk_refresh_tokens_customer FOREIGN KEY (customer_id) REFERENCES customers (id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
  id         CHAR(26)     NOT NULL,
  email      VARCHAR(255) NOT NULL,
  audience   ENUM('customer','admin') NOT NULL,
  ip_address VARCHAR(45)  NULL,
  successful TINYINT(1)   NOT NULL,
  created_at DATETIME(3)  NOT NULL,
  PRIMARY KEY (id),
  KEY ix_login_attempts_email_time (email, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
  id          CHAR(26)     NOT NULL,
  admin_id    CHAR(26)     NULL,
  action      VARCHAR(128) NOT NULL,          -- 'product.update'
  entity_type VARCHAR(64)  NOT NULL,          -- 'Product'
  entity_id   CHAR(26)     NULL,
  before_json JSON         NULL,
  after_json  JSON         NULL,
  ip_address  VARCHAR(45)  NULL,
  created_at  DATETIME(3)  NOT NULL,
  PRIMARY KEY (id),
  KEY ix_audit_logs_entity  (entity_type, entity_id),
  KEY ix_audit_logs_created (created_at),
  KEY ix_audit_logs_admin   (admin_id),
  CONSTRAINT fk_audit_logs_admin FOREIGN KEY (admin_id) REFERENCES admin_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
