-- ============================================================
-- Seed data for local testing. Run AFTER schema.sql.
-- Demo login:  username = mbanaga   password = password123
-- (Change this password immediately in any non-local environment.)
-- ============================================================
USE sf9_system;

INSERT INTO school_years (year_label, is_active) VALUES ('2026-2027', 1);
SET @sy := LAST_INSERT_ID();

INSERT INTO users (username, password_hash, first_name, last_name, role) VALUES
  ('mbanaga', '$2b$12$5mS9uK1ENzYY42hkAUjai.TYJ.HpRSLg1yBdUOrNTP8P8CZNhbxiO', 'Maricel', 'Banaga', 'teacher'),
  ('mtolete', '$2b$12$5mS9uK1ENzYY42hkAUjai.TYJ.HpRSLg1yBdUOrNTP8P8CZNhbxiO', 'Mildred', 'Tolete', 'master_teacher'),
  ('mdeleon',  '$2b$12$5mS9uK1ENzYY42hkAUjai.TYJ.HpRSLg1yBdUOrNTP8P8CZNhbxiO', 'Maria Rosario', 'De Leon', 'principal'),
  ('jcruz',    '$2b$12$5mS9uK1ENzYY42hkAUjai.TYJ.HpRSLg1yBdUOrNTP8P8CZNhbxiO', 'Jonas', 'Cruz', 'teacher'),
  ('headadmin','$2b$12$5mS9uK1ENzYY42hkAUjai.TYJ.HpRSLg1yBdUOrNTP8P8CZNhbxiO', 'System', 'Administrator', 'admin');
SET @adviser := (SELECT id FROM users WHERE username = 'mbanaga');
SET @master  := (SELECT id FROM users WHERE username = 'mtolete');

INSERT INTO sections (grade_level, section_name, school_year_id, adviser_id, master_teacher_id)
VALUES (4, 'MARS', @sy, @adviser, @master);
SET @section := LAST_INSERT_ID();

-- The adviser teaches every subject in this demo (typical for a self-contained elementary class).
-- Assignable subjects: all subjects, including "Music and Arts" and
-- "Physical Education and Health" separately (MAPEH itself is computed).
INSERT INTO teacher_assignments (teacher_id, section_id, subject_id, school_year_id)
SELECT @adviser, @section, id, @sy FROM subjects WHERE parent_group IS NULL AND is_active = 1;

-- Roster from 4-MARS_GS.xlsx (first 10 students, for a manageable demo set).
INSERT INTO students (lrn, last_name, first_name, middle_name, gender, birthdate) VALUES
  ('136700000001', 'ACEBRON',    'LAMARCUS',        'B.',     'Male', '2016-03-14'),
  ('136700000002', 'AGAGAD',     'JAMES KEVIN',      'M',      'Male', '2016-05-02'),
  ('136700000003', 'BIEN',       'THIRDY KIBZ',      NULL,     'Male', '2016-07-19'),
  ('136700000004', 'BOLIMA',     'DANIEL JASPER',    'A.',     'Male', '2016-02-11'),
  ('136700000005', 'BONDOCOY',   'JHAEDANN JAMES',   'A.',     'Male', '2016-09-25'),
  ('136700000006', 'BUMALAY',    'CHRISTIAN',        'B.',     'Male', '2016-01-30'),
  ('136700000007', 'CALAYAO',    'FRANCIS ANTHONY',  'J',      'Male', '2016-04-08'),
  ('136700000008', 'CANACO',     'JAMES ANGELO',     'P',      'Male', '2016-06-17'),
  ('136700000009', 'CARJEL',     'RYAN JOHN',        'P',      'Male', '2016-08-03'),
  ('136700000010', 'CUADRANTE',  'RENCE KENDALL',    'B.',     'Male', '2016-03-29');

INSERT INTO enrollments (student_id, section_id, school_year_id, roster_no, status)
SELECT id, @section, @sy, ROW_NUMBER() OVER (ORDER BY id), 'active' FROM students;

-- A few sample 1st Term grades for ACEBRON (student 1), matching the source workbook.
SET @student1 := (SELECT id FROM students WHERE lrn = '136700000001');
SET @ta_filipino := (SELECT ta.id FROM teacher_assignments ta JOIN subjects s ON s.id = ta.subject_id WHERE ta.section_id = @section AND s.subject_name = 'Filipino');
SET @ta_math     := (SELECT ta.id FROM teacher_assignments ta JOIN subjects s ON s.id = ta.subject_id WHERE ta.section_id = @section AND s.subject_name = 'Mathematics');

INSERT INTO grades (student_id, subject_id, section_id, school_year_id, trimester, teacher_assignment_id, grade_value, status, created_by, updated_by)
VALUES
  (@student1, (SELECT subject_id FROM teacher_assignments WHERE id = @ta_filipino), @section, @sy, '1', @ta_filipino, 85.00, 'graded', @adviser, @adviser),
  (@student1, (SELECT subject_id FROM teacher_assignments WHERE id = @ta_math),     @section, @sy, '1', @ta_math,     88.00, 'graded', @adviser, @adviser);

-- Sample attendance for the same student (June only, for demo purposes).
INSERT INTO attendance_monthly (student_id, section_id, school_year_id, month, days_of_class, days_present, days_absent)
VALUES (@student1, @section, @sy, 'Jun', 20, 19, 1);

-- ============================================================
-- Extra demo data for the multi-section workflow (safe to skip).
--   * mbanaga now has TWO sections: 4-MARS and 5-JUPITER
--   * 6-SATURN belongs to mtolete  -> mbanaga must get 403 on it
--   * unenrolled learners to search for on the Enroll Students screen
-- ============================================================
INSERT INTO school_years (year_label, is_active) VALUES ('2025-2026', 0);
SET @sy_prev := LAST_INSERT_ID();

INSERT INTO sections (grade_level, section_name, school_year_id, adviser_id, master_teacher_id) VALUES
  (5, 'JUPITER', @sy, @adviser, @master);
SET @sec_jupiter := LAST_INSERT_ID();
INSERT INTO sections (grade_level, section_name, school_year_id, adviser_id, master_teacher_id) VALUES
  (6, 'SATURN', @sy, @master, NULL);
SET @sec_saturn := LAST_INSERT_ID();
INSERT INTO sections (grade_level, section_name, school_year_id, adviser_id, master_teacher_id) VALUES
  (4, 'MARS', @sy_prev, @adviser, @master);
SET @sec_prev := LAST_INSERT_ID();

INSERT INTO teacher_assignments (teacher_id, section_id, subject_id, school_year_id)
SELECT @adviser, @sec_jupiter, id, @sy FROM subjects WHERE parent_group IS NULL AND is_active = 1;
INSERT INTO teacher_assignments (teacher_id, section_id, subject_id, school_year_id)
SELECT @master, @sec_saturn, id, @sy FROM subjects WHERE parent_group IS NULL AND is_active = 1;

INSERT INTO students (lrn, last_name, first_name, middle_name, gender, birthdate) VALUES
  ('136700000011', 'DELA CRUZ', 'JUAN',   'P.',  'Male',   '2015-02-10'),  -- was Grade 4 last year: may go to Grade 4 or 5 only
  ('136700000012', 'SANTOS',    'MARIA',  NULL,  'Female', '2015-08-21'),  -- new entrant, no history
  ('136700000013', 'REYES',     'ANA',    'L.',  'Female', '2014-11-05'),  -- already enrolled in 6-SATURN
  ('NOLRN',        'TEST',      'INVALIDLRN', NULL, 'Male', NULL);         -- invalid LRN: enrollment must be refused

INSERT INTO enrollments (student_id, section_id, school_year_id, roster_no, status)
SELECT id, @sec_prev, @sy_prev, 1, 'active' FROM students WHERE lrn = '136700000011';
INSERT INTO enrollments (student_id, section_id, school_year_id, roster_no, status)
SELECT id, @sec_saturn, @sy, 1, 'active' FROM students WHERE lrn = '136700000013';
