-- 008_rate_limits.sql
-- Request counters for the §14.2 rate limits (RTPP-15).
--
-- **Not generated from section 8**, unlike 001–007: §8 defines 36 tables and
-- none of them stores a rate-limit counter, while RTPP-15 requires the storage
-- to be a database table because there is no Redis on shared hosting (§5.4).
-- The gap is recorded as a deviation in §19 and §8 has been updated to match.
--
-- Why a table and not APCu or a file: PHP-FPM gives every request its own
-- process, so an in-memory counter is per-process and counts a fraction of the
-- traffic — the failure mode is a limit that silently never triggers (§19,
-- deviation 2). The database is the only state every request shares.
--
-- Fixed window rather than a sliding log: one row per bucket instead of one row
-- per request. A sliding window is more precise at the boundary and costs a row
-- for every request the site ever serves, which on a shared account is the more
-- expensive kind of wrong.

CREATE TABLE rate_limits (
  id           CHAR(26)     NOT NULL,

  -- 'global:203.0.113.7' | 'form:enquiry:203.0.113.7'. The scope is part of the
  -- key so one table serves every limit without a discriminator column.
  bucket_key   VARCHAR(191) NOT NULL,

  window_start DATETIME(3)  NOT NULL,
  hits         INT UNSIGNED NOT NULL DEFAULT 0,
  expires_at   DATETIME(3)  NOT NULL,

  PRIMARY KEY (id),

  -- The lock target. Every increment is an UPDATE against this unique key, so
  -- concurrent requests from one IP serialise on one row rather than racing.
  UNIQUE KEY uq_rate_limits_bucket (bucket_key),

  -- For the nightly prune (§16.5). Without it the table grows by one row per
  -- distinct IP for ever.
  KEY ix_rate_limits_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
