-- ============================================================
-- Migration 005 — convert the OLD four-component MAPEH layout back to the
-- two-subject layout this version of the code uses.
--
-- WHO NEEDS THIS: databases where `subjects` contains a "MAPEH" row with
-- is_group = 1 plus Music / Arts / Physical Education / Health rows
-- (parent_group = 'MAPEH'). Symptom: the grade editor shows no teacher for
-- MAPEH and the MAPEH subjects cannot be edited.
--
-- RESULT (the layout in schema.sql / README):
--   * subject "MAPEH" (wrapper)            -> becomes "Music and Arts"
--   * subject "Physical Education and Health" (was deactivated) -> active again
--   * Music / Arts / Physical Education / Health rows -> merged and removed
--   * every MAPEH teacher assignment      -> kept for Music and Arts AND copied
--                                            to Physical Education and Health
--   * grades:  Music and Arts            = ROUND(AVERAGE(Music, Arts))
--              Physical Education and Health = ROUND(AVERAGE(Physical Education, Health))
--     (NLPA / Transferred-out on either component carries over as that status;
--      a term where only ONE component was entered uses that single value.)
--
-- BEFORE YOU RUN:  take a full backup (mysqldump).  The script also keeps
-- copies of everything it changes in _bak_legacy_mapeh_* tables.
-- Run once:  mysql -u root -p sf9_system < migrations/005_restore_two_mapeh_subjects.sql
-- It stops with an error if run a second time (the backup tables already exist).
--
-- PREVIEW (read-only, run first):
--   SELECT id, subject_name, parent_group, is_group, is_active FROM subjects ORDER BY id;
--   SELECT subject_id, COUNT(*) FROM grades WHERE subject_id IN
--     (SELECT id FROM subjects WHERE subject_name='MAPEH' OR parent_group='MAPEH') GROUP BY subject_id;
-- ============================================================
USE sf9_system;

SET @mapeh  := (SELECT id FROM subjects WHERE subject_name = 'MAPEH' AND is_group = 1 LIMIT 1);
SET @pe     := (SELECT id FROM subjects WHERE subject_name = 'Physical Education and Health' LIMIT 1);
SET @music  := (SELECT id FROM subjects WHERE subject_name = 'Music' AND parent_group = 'MAPEH' LIMIT 1);
SET @arts   := (SELECT id FROM subjects WHERE subject_name = 'Arts' AND parent_group = 'MAPEH' LIMIT 1);
SET @phys   := (SELECT id FROM subjects WHERE subject_name = 'Physical Education' AND parent_group = 'MAPEH' LIMIT 1);
SET @health := (SELECT id FROM subjects WHERE subject_name = 'Health' AND parent_group = 'MAPEH' LIMIT 1);

-- Safety stop: refuse to run unless all six legacy rows are found.
SELECT IF(@mapeh IS NULL OR @pe IS NULL OR @music IS NULL OR @arts IS NULL OR @phys IS NULL OR @health IS NULL,
          'STOP: the legacy MAPEH layout was not found - nothing was changed. Do NOT continue.',
          'Legacy MAPEH layout found - converting.') AS status;
-- (If it says STOP, press Ctrl+C / do not run the rest.)

-- ---- 1. Backups --------------------------------------------------------------
CREATE TABLE _bak_legacy_mapeh_subjects    AS SELECT * FROM subjects
  WHERE id IN (@mapeh, @pe, @music, @arts, @phys, @health);
CREATE TABLE _bak_legacy_mapeh_assignments AS SELECT * FROM teacher_assignments
  WHERE subject_id IN (@mapeh, @pe, @music, @arts, @phys, @health);
CREATE TABLE _bak_legacy_mapeh_grades      AS SELECT * FROM grades
  WHERE subject_id IN (@mapeh, @pe, @music, @arts, @phys, @health);

-- ---- 2. Convert (all-or-nothing) --------------------------------------------
START TRANSACTION;

-- 2a. Merge the four component grades into the two subjects (computed first).
CREATE TEMPORARY TABLE _mapeh_merge AS
SELECT g.student_id, g.section_id, g.school_year_id, g.trimester,
       IF(g.subject_id IN (@music, @arts), @mapeh, @pe)                       AS target_subject,
       ROUND(AVG(CASE WHEN g.status = 'graded' THEN g.grade_value END))        AS v,
       CASE WHEN SUM(g.status = 'nlpa') > 0 THEN 'nlpa'
            WHEN SUM(g.status = 'transferred_out') > 0 THEN 'transferred_out'
            ELSE 'graded' END                                                  AS st,
       MAX(g.is_locked)  AS is_locked,
       MIN(g.created_by) AS created_by,
       MAX(g.updated_by) AS updated_by
FROM grades g
WHERE g.subject_id IN (@music, @arts, @phys, @health)
GROUP BY g.student_id, g.section_id, g.school_year_id, g.trimester, target_subject;

-- 2b. Old stale single-subject assignments on PE&H are replaced by copies of the MAPEH teacher.
DELETE FROM teacher_assignments WHERE subject_id = @pe;
INSERT INTO teacher_assignments (teacher_id, section_id, subject_id, school_year_id, assigned_by)
SELECT teacher_id, section_id, @pe, school_year_id, assigned_by
FROM teacher_assignments WHERE subject_id = @mapeh;

-- 2c. Write the merged grades (replacing any stale duplicate on the two subjects).
INSERT INTO grades (student_id, subject_id, section_id, school_year_id, trimester,
                    teacher_assignment_id, grade_value, status, is_locked, created_by, updated_by)
SELECT m.student_id, m.target_subject, m.section_id, m.school_year_id, m.trimester,
       (SELECT ta.id FROM teacher_assignments ta
         WHERE ta.section_id = m.section_id AND ta.subject_id = m.target_subject AND ta.school_year_id = m.school_year_id LIMIT 1),
       IF(m.st = 'graded', m.v, NULL), m.st, m.is_locked, m.created_by, m.updated_by
FROM _mapeh_merge m
WHERE m.st <> 'graded' OR m.v IS NOT NULL
ON DUPLICATE KEY UPDATE grade_value = VALUES(grade_value), status = VALUES(status),
                        is_locked = VALUES(is_locked), teacher_assignment_id = VALUES(teacher_assignment_id);

-- 2d. Remove the four component rows and anything that pointed at them.
DELETE FROM grades              WHERE subject_id IN (@music, @arts, @phys, @health);
DELETE FROM teacher_assignments WHERE subject_id IN (@music, @arts, @phys, @health);
DELETE FROM subjects            WHERE id         IN (@music, @arts, @phys, @health);

-- 2e. The two subjects, as in schema.sql.
UPDATE subjects SET subject_name = 'Music and Arts', parent_group = NULL, is_group = 0, is_active = 1, sort_order = 7 WHERE id = @mapeh;
UPDATE subjects SET parent_group = NULL, is_group = 0, is_active = 1, sort_order = 8 WHERE id = @pe;

COMMIT;
DROP TEMPORARY TABLE IF EXISTS _mapeh_merge;

-- ---- 3. Check -----------------------------------------------------------------
SELECT id, subject_name, parent_group, is_group, is_active FROM subjects ORDER BY sort_order, id;
SELECT ta.section_id, s.subject_name, u.username
FROM teacher_assignments ta JOIN subjects s ON s.id = ta.subject_id JOIN users u ON u.id = ta.teacher_id
WHERE s.subject_name IN ('Music and Arts', 'Physical Education and Health') ORDER BY ta.section_id, s.sort_order;
