-- ============================================================
-- Migration 002 — Admin Panel / account management support
-- (MAPEH stays as two separately-graded subjects — "Music and Arts" and
-- "Physical Education and Health" — averaged on the fly; no subject split.)
-- Run AFTER 001_one_enrollment_per_student_per_year.sql.
-- Back up the database before running this.
-- ============================================================
USE sf9_system;

-- ------------------------------------------------------------
-- STEP 1 — users: account-management columns
-- ------------------------------------------------------------
ALTER TABLE users
  ADD COLUMN email VARCHAR(150) NULL AFTER username,
  ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active,
  ADD COLUMN created_by INT UNSIGNED NULL AFTER must_change_password,
  ADD CONSTRAINT fk_users_created_by FOREIGN KEY (created_by) REFERENCES users(id);

CREATE TABLE IF NOT EXISTS account_audit_log (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  target_user_id INT UNSIGNED NOT NULL,
  action       ENUM('created','role_changed','activated','deactivated','password_reset','edited') NOT NULL,
  performed_by INT UNSIGNED NOT NULL,
  details      VARCHAR(255) NULL,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_aal_target FOREIGN KEY (target_user_id) REFERENCES users(id),
  CONSTRAINT fk_aal_actor  FOREIGN KEY (performed_by)   REFERENCES users(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- STEP 2 — sections: section-creation metadata
-- ------------------------------------------------------------
ALTER TABLE sections
  ADD COLUMN track_strand VARCHAR(100) NULL AFTER section_name,
  ADD COLUMN created_by INT UNSIGNED NULL AFTER master_teacher_id,
  ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER created_by,
  ADD CONSTRAINT fk_section_created_by FOREIGN KEY (created_by) REFERENCES users(id);

-- ------------------------------------------------------------
-- STEP 3 — subjects: bookkeeping columns only. MAPEH is NOT split.
-- "Music and Arts" and "Physical Education and Health" remain the two
-- directly-gradable, directly-assignable subjects they always were;
-- MAPEH is computed as their average, never stored in `grades`.
-- ------------------------------------------------------------
ALTER TABLE subjects
  ADD COLUMN is_group  TINYINT(1) NOT NULL DEFAULT 0 AFTER parent_group,
  ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER is_group;

-- ------------------------------------------------------------
-- STEP 4 — teacher_assignments: bookkeeping columns + one-teacher-per-
-- subject constraint (each of the two MAPEH subjects is assigned
-- independently, just like any other subject).
-- ------------------------------------------------------------
ALTER TABLE teacher_assignments
  ADD COLUMN assigned_by INT UNSIGNED NULL AFTER school_year_id,
  ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER assigned_by,
  ADD CONSTRAINT fk_ta_assigned_by FOREIGN KEY (assigned_by) REFERENCES users(id),
  ADD UNIQUE KEY uq_one_teacher_per_subject (section_id, subject_id, school_year_id);

-- ------------------------------------------------------------
-- Done.
-- ============================================================
