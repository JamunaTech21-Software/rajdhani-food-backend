-- 000_migrations_table.sql
-- Bookkeeping for the migration runner itself. Applied before anything else and
-- never rolled back: the runner reads this table to decide what is outstanding.

CREATE TABLE IF NOT EXISTS migrations (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  filename    VARCHAR(255) NOT NULL,
  checksum    CHAR(64)     NOT NULL,          -- sha256 of the file as applied
  applied_at  DATETIME(3)  NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_migrations_filename (filename)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
