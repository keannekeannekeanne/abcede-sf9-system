-- ============================================================
-- Migration 003 — System Updates (see SYSTEM UPDATES AND REQUIRED CHANGES)
--   - "settings" table (configurable School Head default, etc.)
--   - "sf9_comment_options" table (predefined SF9 comments)
--   - No destructive changes. Safe to run on an existing installation.
-- Run this AFTER schema.sql/002 on an existing database. Fresh installs
-- just use the updated schema.sql directly.
-- ============================================================

CREATE TABLE IF NOT EXISTS settings (
  setting_key    VARCHAR(50) PRIMARY KEY,
  setting_value  VARCHAR(255) NULL,
  updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
  ('school_head_name', 'YVETTE DS. SAN JUAN');

CREATE TABLE IF NOT EXISTS sf9_comment_options (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  comment_text VARCHAR(255) NOT NULL,
  sort_order   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_active    TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

INSERT INTO sf9_comment_options (comment_text, sort_order)
SELECT * FROM (SELECT 'Keep up the good work.' AS t, 1 AS o
  UNION ALL SELECT 'Shows consistent improvement in class performance.', 2
  UNION ALL SELECT 'Needs improvement in reading comprehension.', 3
  UNION ALL SELECT 'Needs to improve study habits and time management.', 4
  UNION ALL SELECT 'Actively participates in class activities.', 5
  UNION ALL SELECT 'Needs more focus and attention during class discussions.', 6
  UNION ALL SELECT 'Demonstrates excellent behavior and cooperation.', 7
  UNION ALL SELECT 'Needs improvement in completing requirements on time.', 8
) seed
WHERE NOT EXISTS (SELECT 1 FROM sf9_comment_options);

-- Track/Strand: the field is removed from the Create Section UI (elementary
-- school — not applicable) but the column is left in place so existing data
-- is never dropped. It is simply no longer read from or shown in any form.
