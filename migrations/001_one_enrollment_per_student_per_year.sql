-- ============================================================
-- Migration 001 — one enrollment per student per school year
-- Run ONCE on an existing database (fresh installs get this from schema.sql).
-- ============================================================
USE sf9_system;

-- STEP 1 — look for students enrolled in more than one section in the same school year.
-- This must return ZERO rows before step 2 will succeed. If it returns rows, decide
-- which enrollment is correct and delete the other row.
SELECT e.student_id, s.lrn, s.last_name, s.first_name, e.school_year_id, COUNT(*) AS enrollments
FROM enrollments e JOIN students s ON s.id = e.student_id
GROUP BY e.student_id, e.school_year_id
HAVING COUNT(*) > 1;

-- STEP 2 — enforce it in the database (the app also checks, this closes race conditions).
ALTER TABLE enrollments ADD UNIQUE KEY uq_enrollment_student_year (student_id, school_year_id);

-- The old key (student, section, year) is now redundant. Optional clean-up:
-- ALTER TABLE enrollments DROP INDEX uq_enrollment;
