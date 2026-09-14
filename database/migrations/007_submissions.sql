-- 007_submissions.sql
-- Lead capture and reference geography (doc 8.8).
--
-- Generated from section 8 of Rajdhani_Project_Doc.md v3.0, in foreign-key
-- dependency order. Forward-only: never edit this file once it has been
-- applied to any environment (doc 16.4).

CREATE TABLE districts (
  id            CHAR(26)     NOT NULL,
  name          VARCHAR(128) NOT NULL,        -- 'Dhaka'
  name_bn       VARCHAR(128) NULL,            -- requires utf8mb4
  division_name VARCHAR(128) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_districts_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE upazilas (
  id          CHAR(26)     NOT NULL,
  district_id CHAR(26)     NOT NULL,
  name        VARCHAR(128) NOT NULL,
  name_bn     VARCHAR(128) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_upazilas_district_name (district_id, name),
  CONSTRAINT fk_upazilas_district FOREIGN KEY (district_id) REFERENCES districts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_enquiries (
  id              CHAR(26)     NOT NULL,
  reference_no    VARCHAR(32)  NOT NULL,      -- 'RDFP-ENQ-2026-00841'
  product_id      CHAR(26)     NULL,
  customer_id     CHAR(26)     NULL,          -- set if submitted while logged in
  name            VARCHAR(255) NOT NULL,
  company_name    VARCHAR(255) NULL,
  phone           VARCHAR(32)  NOT NULL,
  email           VARCHAR(255) NOT NULL,
  city            VARCHAR(128) NOT NULL,
  pack_size_label VARCHAR(64)  NULL,
  quantity        VARCHAR(64)  NULL,          -- free text: '100 kg'
  message         TEXT         NOT NULL,
  status          ENUM('NEW','IN_PROGRESS','CONTACTED','CLOSED','SPAM') NOT NULL DEFAULT 'NEW',
  assigned_to_id  CHAR(26)     NULL,
  internal_notes  TEXT         NULL,
  source_page     VARCHAR(255) NULL,
  ip_address      VARCHAR(45)  NULL,
  created_at      DATETIME(3)  NOT NULL,
  updated_at      DATETIME(3)  NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_product_enquiries_reference (reference_no),
  KEY ix_product_enquiries_status   (status, created_at),
  KEY ix_product_enquiries_created  (created_at),
  KEY ix_product_enquiries_product  (product_id),
  KEY ix_product_enquiries_customer (customer_id),
  KEY ix_product_enquiries_assignee (assigned_to_id),
  CONSTRAINT fk_enquiries_product  FOREIGN KEY (product_id)     REFERENCES products (id),
  CONSTRAINT fk_enquiries_customer FOREIGN KEY (customer_id)    REFERENCES customers (id),
  CONSTRAINT fk_enquiries_assignee FOREIGN KEY (assigned_to_id) REFERENCES admin_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE dealer_applications (
  id                  CHAR(26)     NOT NULL,
  application_id      VARCHAR(32)  NOT NULL,  -- 'RDFP-DA-2026-05120'
  full_name           VARCHAR(255) NOT NULL,
  company_name        VARCHAR(255) NOT NULL,
  phone               VARCHAR(32)  NOT NULL,
  email               VARCHAR(255) NOT NULL,
  district_id         CHAR(26)     NOT NULL,
  upazila_id          CHAR(26)     NOT NULL,
  address_line        VARCHAR(255) NULL,
  has_trade_license   TINYINT(1)   NOT NULL DEFAULT 0,
  has_tin_certificate TINYINT(1)   NOT NULL DEFAULT 0,
  years_of_experience INT          NULL,
  message             TEXT         NULL,
  status              ENUM('SUBMITTED','UNDER_REVIEW','APPROVED','REJECTED','ON_HOLD') NOT NULL DEFAULT 'SUBMITTED',
  assigned_to_id      CHAR(26)     NULL,
  internal_notes      TEXT         NULL,
  reviewed_at         DATETIME(3)  NULL,
  ip_address          VARCHAR(45)  NULL,
  created_at          DATETIME(3)  NOT NULL,
  updated_at          DATETIME(3)  NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dealer_applications_app_id (application_id),
  KEY ix_dealer_applications_status   (status, created_at),
  KEY ix_dealer_applications_created  (created_at),
  KEY ix_dealer_applications_district (district_id),
  KEY ix_dealer_applications_upazila  (upazila_id),
  KEY ix_dealer_applications_assignee (assigned_to_id),
  CONSTRAINT fk_apps_district FOREIGN KEY (district_id)    REFERENCES districts (id),
  CONSTRAINT fk_apps_upazila  FOREIGN KEY (upazila_id)     REFERENCES upazilas (id),
  CONSTRAINT fk_apps_assignee FOREIGN KEY (assigned_to_id) REFERENCES admin_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE contact_messages (
  id             CHAR(26)     NOT NULL,
  name           VARCHAR(255) NOT NULL,
  email          VARCHAR(255) NOT NULL,
  phone          VARCHAR(32)  NULL,
  subject        VARCHAR(255) NULL,
  message        TEXT         NOT NULL,
  status         ENUM('UNREAD','READ','REPLIED','ARCHIVED') NOT NULL DEFAULT 'UNREAD',
  replied_at     DATETIME(3)  NULL,
  internal_notes TEXT         NULL,
  ip_address     VARCHAR(45)  NULL,
  created_at     DATETIME(3)  NOT NULL,
  PRIMARY KEY (id),
  KEY ix_contact_messages_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE newsletter_subscribers (
  id                CHAR(26)     NOT NULL,
  email             VARCHAR(255) NOT NULL,
  is_subscribed     TINYINT(1)   NOT NULL DEFAULT 1,
  unsubscribe_token CHAR(64)     NOT NULL,
  source            VARCHAR(64)  NULL,        -- 'footer' | 'home_strip'
  subscribed_at     DATETIME(3)  NOT NULL,
  unsubscribed_at   DATETIME(3)  NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_newsletter_token (unsubscribe_token),
  UNIQUE KEY uq_newsletter_email (email),
  KEY ix_newsletter_subscribed (is_subscribed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
