-- ============================================================
-- Grade Sheet & SF9 Generation System — Database Schema
-- School: Dr. Domingo G. Abcede Elementary School
-- Grading system: 3 Trimesters (never quarters)
-- ============================================================

CREATE DATABASE IF NOT EXISTS sf9_system
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE sf9_system;

-- ------------------------------------------------------------
-- 1. School years
-- ------------------------------------------------------------
CREATE TABLE school_years (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  year_label    VARCHAR(9) NOT NULL UNIQUE,   -- e.g. '2026-2027'
  is_active     TINYINT(1) NOT NULL DEFAULT 0,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 2. Users / Teacher accounts (auth)
-- ------------------------------------------------------------
CREATE TABLE users (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username              VARCHAR(50)  NOT NULL UNIQUE,
  email                 VARCHAR(150) NULL,
  password_hash         VARCHAR(255) NOT NULL,       -- password_hash() / PASSWORD_DEFAULT — never plain text
  first_name            VARCHAR(100) NOT NULL,
  last_name             VARCHAR(100) NOT NULL,
  middle_name           VARCHAR(100) NULL,
  role                  ENUM('teacher','master_teacher','principal','admin') NOT NULL DEFAULT 'teacher',
  assigned_grade_level  TINYINT UNSIGNED NULL,           -- Admin-assigned grade (4, 5 or 6); NULL = not restricted to one grade
  is_active             TINYINT(1) NOT NULL DEFAULT 1,
  must_change_password  TINYINT(1) NOT NULL DEFAULT 0,   -- forces a password change at next login (set on admin reset/create)
  created_by            INT UNSIGNED NULL,                -- admin who created this account (NULL for the first bootstrap admin)
  created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_created_by FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT chk_users_assigned_grade CHECK (assigned_grade_level IS NULL OR assigned_grade_level IN (4,5,6))
) ENGINE=InnoDB;

-- Audit trail for account management actions (Admin Panel requirement: every
-- create/role-change/activate/deactivate/password-reset is recorded, who did it and when).
CREATE TABLE account_audit_log (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  target_user_id INT UNSIGNED NOT NULL,
  action       ENUM('created','role_changed','activated','deactivated','password_reset','edited',
                     'grade_assigned','grade_unassigned','self_edited','password_changed') NOT NULL,
  performed_by INT UNSIGNED NOT NULL,
  details      VARCHAR(255) NULL,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_aal_target FOREIGN KEY (target_user_id) REFERENCES users(id),
  CONSTRAINT fk_aal_actor  FOREIGN KEY (performed_by)   REFERENCES users(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 3. Sections (Grade & Section, e.g. "4-MARS")
-- ------------------------------------------------------------
CREATE TABLE sections (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  grade_level    TINYINT UNSIGNED NOT NULL,        -- 4, 5, or 6 (or any grade level the school uses)
  section_name   VARCHAR(50) NOT NULL,              -- 'MARS'
  track_strand   VARCHAR(100) NULL,                 -- e.g. 'TVL', 'STEM' — only used where applicable (SHS)
  school_year_id INT UNSIGNED NOT NULL,
  adviser_id     INT UNSIGNED NULL,                 -- FK to users (homeroom adviser)
  master_teacher_id INT UNSIGNED NULL,              -- FK to users
  created_by     INT UNSIGNED NULL,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_section (grade_level, section_name, school_year_id),
  CONSTRAINT fk_section_year    FOREIGN KEY (school_year_id) REFERENCES school_years(id),
  CONSTRAINT fk_section_adviser FOREIGN KEY (adviser_id)     REFERENCES users(id),
  CONSTRAINT fk_section_master  FOREIGN KEY (master_teacher_id) REFERENCES users(id),
  CONSTRAINT fk_section_created_by FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 4. Students
-- ------------------------------------------------------------
CREATE TABLE students (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lrn           VARCHAR(12) NOT NULL UNIQUE,        -- Learner Reference Number (SF9 field)
  last_name     VARCHAR(100) NOT NULL,
  first_name    VARCHAR(100) NOT NULL,
  middle_name   VARCHAR(100) NULL,
  gender        ENUM('Male','Female') NOT NULL,
  birthdate     DATE NULL,                          -- used to compute Age on SF9
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Enrollment ties a student to a specific section for a specific school year.
-- Also carries the roster "NO." ordering used on the grade sheet.
CREATE TABLE enrollments (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id     INT UNSIGNED NOT NULL,
  section_id     INT UNSIGNED NOT NULL,
  school_year_id INT UNSIGNED NOT NULL,
  roster_no      SMALLINT UNSIGNED NULL,            -- the "NO." column on the sheet
  sf9_age_override TINYINT UNSIGNED NULL,           -- SF9-only age typed by a teacher/admin; NULL = computed from birthdate
  status         ENUM('active','transferred_out','dropped') NOT NULL DEFAULT 'active',
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  -- One enrollment per student per school year: a learner can never be in two sections at once.
  UNIQUE KEY uq_enrollment_student_year (student_id, school_year_id),
  CONSTRAINT fk_enr_student FOREIGN KEY (student_id)     REFERENCES students(id),
  CONSTRAINT fk_enr_section FOREIGN KEY (section_id)     REFERENCES sections(id),
  CONSTRAINT fk_enr_year    FOREIGN KEY (school_year_id) REFERENCES school_years(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 5. Learning areas (subjects)
--
-- MAPEH is never entered directly and never stored in `grades`. It is
-- always computed on the fly as the average of two separately-graded,
-- separately-assignable subjects: "Music and Arts" and
-- "Physical Education and Health" (see GradeCalculator::mapeh()).
-- (GradeCalculator::mapeh()) so it can never drift from its inputs and can
-- never be hand-edited.
-- ------------------------------------------------------------
CREATE TABLE subjects (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subject_name  VARCHAR(100) NOT NULL UNIQUE,
  parent_group  VARCHAR(50) NULL,      -- 'MAPEH' for the four component rows, else NULL
  is_group      TINYINT(1) NOT NULL DEFAULT 0,   -- 1 = assignment-only wrapper (MAPEH); never gradable directly
  is_active     TINYINT(1) NOT NULL DEFAULT 1,   -- soft-deprecate a subject without breaking historical FKs
  sort_order    TINYINT UNSIGNED NOT NULL
) ENGINE=InnoDB;

INSERT INTO subjects (subject_name, parent_group, is_group, sort_order) VALUES
  ('Filipino',                          NULL, 0, 1),
  ('English',                           NULL, 0, 2),
  ('Mathematics',                       NULL, 0, 3),
  ('Science',                           NULL, 0, 4),
  ('Araling Panlipunan',                NULL, 0, 5),
  ('EPP/TLE',                           NULL, 0, 6),
  ('Music and Arts',                    NULL, 0, 7),
  ('Physical Education and Health',     NULL, 0, 8),
  ('GMRC',                              NULL, 0, 9);

-- ------------------------------------------------------------
-- 6. Teacher assignments — WHO may enter grades for WHICH
--    section + subject + school year. This is the access-control table.
--    A subject can have at most ONE assigned teacher per section/year —
--    this is what guarantees exactly one MAPEH teacher per section, and
--    generally matches "Subject -> Assigned Teacher" from the spec.
-- ------------------------------------------------------------
CREATE TABLE teacher_assignments (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  teacher_id     INT UNSIGNED NOT NULL,
  section_id     INT UNSIGNED NOT NULL,
  subject_id     INT UNSIGNED NOT NULL,
  school_year_id INT UNSIGNED NOT NULL,
  assigned_by    INT UNSIGNED NULL,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_assignment (teacher_id, section_id, subject_id, school_year_id),
  UNIQUE KEY uq_one_teacher_per_subject (section_id, subject_id, school_year_id),
  CONSTRAINT fk_ta_teacher FOREIGN KEY (teacher_id)     REFERENCES users(id),
  CONSTRAINT fk_ta_section FOREIGN KEY (section_id)     REFERENCES sections(id),
  CONSTRAINT fk_ta_subject FOREIGN KEY (subject_id)     REFERENCES subjects(id),
  CONSTRAINT fk_ta_year    FOREIGN KEY (school_year_id) REFERENCES school_years(id),
  CONSTRAINT fk_ta_assigned_by FOREIGN KEY (assigned_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 7. Grades — one row per student, per subject, per trimester,
--    per school year. Duplicate-proof via the UNIQUE key.
-- ------------------------------------------------------------
CREATE TABLE grades (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id            INT UNSIGNED NOT NULL,
  subject_id            INT UNSIGNED NOT NULL,
  section_id            INT UNSIGNED NOT NULL,       -- denormalized for fast scoping/locking
  school_year_id        INT UNSIGNED NOT NULL,
  trimester             ENUM('1','2','3') NOT NULL,
  teacher_assignment_id INT UNSIGNED NULL,            -- which assignment recorded this grade (NULL once that assignment is removed)
  grade_value           DECIMAL(5,2) NULL,             -- NULL when status below is not 'graded'
  status                ENUM('graded','nlpa','transferred_out')
                          NOT NULL DEFAULT 'graded',
                          -- 'nlpa' = No Longer Participating in Learning Activities.
                          -- A student with 'nlpa' on ANY required subject for a trimester
                          -- cannot have that trimester's Subject/General Average computed
                          -- (see Calculations/GradeCalculator.php).
  is_locked             TINYINT(1) NOT NULL DEFAULT 0, -- lock after SF9 generation, if desired
  created_by            INT UNSIGNED NOT NULL,
  updated_by            INT UNSIGNED NULL,
  created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_grade (student_id, subject_id, school_year_id, trimester),
  KEY idx_section_term (section_id, school_year_id, trimester),
  CONSTRAINT fk_g_student    FOREIGN KEY (student_id)  REFERENCES students(id),
  CONSTRAINT fk_g_subject    FOREIGN KEY (subject_id)  REFERENCES subjects(id),
  CONSTRAINT fk_g_section    FOREIGN KEY (section_id)  REFERENCES sections(id),
  CONSTRAINT fk_g_year       FOREIGN KEY (school_year_id) REFERENCES school_years(id),
  CONSTRAINT fk_g_assignment FOREIGN KEY (teacher_assignment_id) REFERENCES teacher_assignments(id) ON DELETE SET NULL,
  CONSTRAINT fk_g_created_by FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_g_updated_by FOREIGN KEY (updated_by) REFERENCES users(id),
  CONSTRAINT chk_grade_range CHECK (grade_value IS NULL OR (grade_value >= 0 AND grade_value <= 100))
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 8. Grade change audit log — every save/correction is recorded
-- ------------------------------------------------------------
CREATE TABLE grade_audit_log (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  grade_id     BIGINT UNSIGNED NOT NULL,
  changed_by   INT UNSIGNED NOT NULL,
  old_value    DECIMAL(5,2) NULL,
  new_value    DECIMAL(5,2) NULL,
  old_status   VARCHAR(30) NULL,
  new_status   VARCHAR(30) NULL,
  changed_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_gal_grade FOREIGN KEY (grade_id)   REFERENCES grades(id),
  CONSTRAINT fk_gal_user  FOREIGN KEY (changed_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 9. Attendance — monthly, per student per school year (SF9 Attendance Record)
-- ------------------------------------------------------------
CREATE TABLE attendance_monthly (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id     INT UNSIGNED NOT NULL,
  section_id     INT UNSIGNED NOT NULL,
  school_year_id INT UNSIGNED NOT NULL,
  month          ENUM('Jun','Jul','Aug','Sep','Oct','Nov','Dec','Jan','Feb','Mar','Apr') NOT NULL,
  days_of_class  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  days_present   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  days_absent    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_attendance (student_id, school_year_id, month),
  CONSTRAINT fk_att_student FOREIGN KEY (student_id) REFERENCES students(id),
  CONSTRAINT fk_att_section FOREIGN KEY (section_id) REFERENCES sections(id),
  CONSTRAINT fk_att_year    FOREIGN KEY (school_year_id) REFERENCES school_years(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 10a. SF9 Teacher's Remarks "Sections" A-E (Outstanding, Very Satisfactory, Satisfactory,
--      Fairly Satisfactory / Needs Improvement, Character and Values). Rows are inserted below.
-- ------------------------------------------------------------
CREATE TABLE sf9_remark_sections (
  code        CHAR(1) PRIMARY KEY,               -- 'A'..'E'
  title       VARCHAR(80) NOT NULL,
  sort_order  TINYINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 10. Teacher's per-term remarks (SF9 "Teacher's Comments/Remarks")
-- ------------------------------------------------------------
CREATE TABLE teacher_remarks (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id     INT UNSIGNED NOT NULL,
  school_year_id INT UNSIGNED NOT NULL,
  trimester      ENUM('1','2','3') NOT NULL,
  remarks        TEXT NULL,
  remark_section CHAR(1) NULL,                      -- Section (A-E) the remark was chosen under; NULL for free text
  written_by     INT UNSIGNED NOT NULL,
  updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_remark (student_id, school_year_id, trimester),
  CONSTRAINT fk_rem_student FOREIGN KEY (student_id) REFERENCES students(id),
  CONSTRAINT fk_rem_year    FOREIGN KEY (school_year_id) REFERENCES school_years(id),
  CONSTRAINT fk_rem_writer  FOREIGN KEY (written_by) REFERENCES users(id),
  CONSTRAINT fk_rem_section FOREIGN KEY (remark_section) REFERENCES sf9_remark_sections(code) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 11. Descriptor bands (from the Helper sheet — configurable, not hardcoded)
-- ------------------------------------------------------------
CREATE TABLE descriptor_bands (
  id          TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  min_score   DECIMAL(5,2) NOT NULL,
  max_score   DECIMAL(5,2) NOT NULL,
  descriptor  VARCHAR(30) NOT NULL
) ENGINE=InnoDB;

INSERT INTO descriptor_bands (min_score, max_score, descriptor) VALUES
  (0,  64,  'Emerging'),
  (65, 74,  'Developing'),
  (75, 79,  'Connecting'),
  (80, 89,  'Benchmarking'),
  (90, 100, 'Advancing');

-- ------------------------------------------------------------
-- 12. System settings — small configurable values that must NOT be
--     hard-coded (e.g. the default School Head shown on the SF9).
--     Admin-editable via the Admin Panel -> Settings tab.
-- ------------------------------------------------------------
CREATE TABLE settings (
  setting_key    VARCHAR(50) PRIMARY KEY,
  setting_value  VARCHAR(255) NULL,
  updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO settings (setting_key, setting_value) VALUES
  ('school_head_name', 'YVETTE DS. SAN JUAN');

-- ------------------------------------------------------------
-- 13. Predefined SF9 comment choices (Teacher's Comments/Remarks).
--     A teacher may pick one of these OR type a custom comment —
--     see public/sf9_preview.php.
-- ------------------------------------------------------------
CREATE TABLE sf9_comment_options (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  comment_text VARCHAR(255) NOT NULL,
  section_code CHAR(1) NULL,                        -- 'A'..'E' (NULL = general / older predefined comment)
  code         VARCHAR(4) NULL,                     -- e.g. 'A1'
  sort_order   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  KEY idx_sco_section (section_code, sort_order)
) ENGINE=InnoDB;

INSERT INTO sf9_comment_options (comment_text, sort_order) VALUES
  ('Keep up the good work.', 1),
  ('Shows consistent improvement in class performance.', 2),
  ('Needs improvement in reading comprehension.', 3),
  ('Needs to improve study habits and time management.', 4),
  ('Actively participates in class activities.', 5),
  ('Needs more focus and attention during class discussions.', 6),
  ('Demonstrates excellent behavior and cooperation.', 7),
  ('Needs improvement in completing requirements on time.', 8);

-- ------------------------------------------------------------
-- 14. Teacher's Remarks by Section A-E — the 100 preset remarks from the school's
--     "SF9 Teacher's Remarks" list (20 per Section). Editable/extendable by SQL; the SF9
--     page lets a teacher pick one per term or type a custom remark.
-- ------------------------------------------------------------
INSERT INTO sf9_remark_sections (code, title, sort_order) VALUES
  ('A', 'Outstanding', 1),
  ('B', 'Very Satisfactory', 2),
  ('C', 'Satisfactory', 3),
  ('D', 'Fairly Satisfactory / Needs Improvement', 4),
  ('E', 'Character and Values', 5);

INSERT INTO sf9_comment_options (section_code, code, comment_text, sort_order) VALUES
  ('A', 'A1', 'Patuloy na nagpapakita ng natatanging husay sa pag-aaral at mabuting asal.', 101),
  ('A', 'A2', 'Isang huwarang mag-aaral na may mataas na antas ng disiplina at pananagutan.', 102),
  ('A', 'A3', 'Ipagpatuloy ang kahusayan at pagiging inspirasyon sa iba.', 103),
  ('A', 'A4', 'Lubos na kahanga-hanga ang iyong dedikasyon sa pag-aaral.', 104),
  ('A', 'A5', 'Patuloy na linangin ang iyong mga kakayahan upang higit pang magtagumpay.', 105),
  ('A', 'A6', 'Mahusay ang pakikilahok at napakataas ng kalidad ng mga gawain.', 106),
  ('A', 'A7', 'Nagpapakita ng pamumuno at positibong impluwensya sa mga kamag-aral.', 107),
  ('A', 'A8', 'Napananatili ang mataas na antas ng pagganap sa lahat ng asignatura.', 108),
  ('A', 'A9', 'Karapat-dapat na ipagmalaki ang iyong pagsusumikap at tagumpay.', 109),
  ('A', 'A10', 'Ipagpatuloy ang iyong sipag, tiyaga, at mabuting pag-uugali.', 110),
  ('A', 'A11', 'Ang iyong determinasyon ay naging susi sa iyong tagumpay.', 111),
  ('A', 'A12', 'Nagpapakita ng kahusayan sa akademiko at magandang pagpapahalaga.', 112),
  ('A', 'A13', 'Napakahusay sa pagsunod sa mga alituntunin ng paaralan.', 113),
  ('A', 'A14', 'Patuloy na maging ehemplo ng kasipagan at disiplina.', 114),
  ('A', 'A15', 'Binabati kita sa iyong natatanging pagganap.', 115),
  ('A', 'A16', 'Ang iyong pagsisikap ay tunay na kapuri-puri.', 116),
  ('A', 'A17', 'Ipagpatuloy ang pagiging responsable at masigasig.', 117),
  ('A', 'A18', 'Nananatiling mahusay sa akademiko at pakikisalamuha.', 118),
  ('A', 'A19', 'Mahusay ang pagharap sa mga hamon sa pag-aaral.', 119),
  ('A', 'A20', 'Nawa''y ipagpatuloy mo ang iyong kahusayan.', 120),
  ('B', 'B1', 'Patuloy na nagpapakita ng mahusay na pagganap sa klase.', 201),
  ('B', 'B2', 'Mahusay ang pakikilahok sa mga gawaing pampagkatuto.', 202),
  ('B', 'B3', 'Ipagpatuloy ang kasipagan at positibong saloobin.', 203),
  ('B', 'B4', 'Nagpapakita ng malaking potensyal sa pag-aaral.', 204),
  ('B', 'B5', 'Patuloy na umuunlad sa iba''t ibang larangan.', 205),
  ('B', 'B6', 'Mahusay sa pagtupad ng mga gawain.', 206),
  ('B', 'B7', 'Nagpapakita ng responsibilidad sa pag-aaral.', 207),
  ('B', 'B8', 'Ipagpatuloy ang magandang pag-uugali.', 208),
  ('B', 'B9', 'Kapansin-pansin ang iyong pag-unlad.', 209),
  ('B', 'B10', 'Nawa''y mapanatili ang sigla sa pag-aaral.', 210),
  ('B', 'B11', 'Mahusay ang pakikipag-ugnayan sa guro at kamag-aral.', 211),
  ('B', 'B12', 'Patuloy na pagyamanin ang iyong kakayahan.', 212),
  ('B', 'B13', 'Nakakamit ang mga layunin sa pagkatuto.', 213),
  ('B', 'B14', 'Ang iyong pagsisikap ay nagbibigay ng magandang resulta.', 214),
  ('B', 'B15', 'Ipagpatuloy ang aktibong pakikilahok sa klase.', 215),
  ('B', 'B16', 'Magandang halimbawa ng responsableng mag-aaral.', 216),
  ('B', 'B17', 'Patuloy na magsikap upang higit pang umunlad.', 217),
  ('B', 'B18', 'Ang iyong tiyaga ay kapuri-puri.', 218),
  ('B', 'B19', 'Magpatuloy sa paglinang ng iyong talento.', 219),
  ('B', 'B20', 'Binabati kita sa iyong magandang pagganap.', 220),
  ('C', 'C1', 'Nakamit ang inaasahang pamantayan sa pagkatuto.', 301),
  ('C', 'C2', 'Patuloy na magsikap upang higit pang mapabuti ang pagganap.', 302),
  ('C', 'C3', 'Nagpapakita ng unti-unting pag-unlad.', 303),
  ('C', 'C4', 'Mas pagbutihin pa ang pag-aaral at paghahanda.', 304),
  ('C', 'C5', 'Kailangang maging mas aktibo sa talakayan.', 305),
  ('C', 'C6', 'Ipagpatuloy ang pagsisikap sa lahat ng asignatura.', 306),
  ('C', 'C7', 'May kakayahang umunlad sa pamamagitan ng patuloy na pag-aaral.', 307),
  ('C', 'C8', 'Gampanan ang mga gawain nang may higit na kasipagan.', 308),
  ('C', 'C9', 'Maglaan ng sapat na oras sa pag-aaral.', 309),
  ('C', 'C10', 'Maging mas responsable sa pagtapos ng mga gawain.', 310),
  ('C', 'C11', 'Patuloy na linangin ang tiwala sa sarili.', 311),
  ('C', 'C12', 'Pag-ibayuhin pa ang pagsisikap.', 312),
  ('C', 'C13', 'Maging mas masinop sa pagsunod sa mga panuto.', 313),
  ('C', 'C14', 'Magpatuloy sa positibong pag-uugali.', 314),
  ('C', 'C15', 'Ang iyong pag-unlad ay nakasalalay sa iyong patuloy na pagsisikap.', 315),
  ('C', 'C16', 'Maging regular sa pagrepaso ng mga aralin.', 316),
  ('C', 'C17', 'Huwag mawalan ng sigla sa pag-aaral.', 317),
  ('C', 'C18', 'Mas pagbutihin pa ang pakikilahok sa klase.', 318),
  ('C', 'C19', 'Patuloy na magsumikap upang maabot ang iyong mga layunin.', 319),
  ('C', 'C20', 'Naniniwala kaming kaya mong higit pang umunlad.', 320),
  ('D', 'D1', 'Kailangang pag-ibayuhin pa ang pagsisikap sa pag-aaral.', 401),
  ('D', 'D2', 'Maging mas responsable sa pagtapos ng mga gawain.', 402),
  ('D', 'D3', 'Ugaliing mag-aral araw-araw.', 403),
  ('D', 'D4', 'Hinihikayat kang maging mas aktibo sa klase.', 404),
  ('D', 'D5', 'Kailangang pagtuunan ng pansin ang mga asignaturang nangangailangan ng pagpapabuti.', 405),
  ('D', 'D6', 'Magsumikap upang mapataas ang antas ng pagkatuto.', 406),
  ('D', 'D7', 'Ang regular na pagpasok at pag-aaral ay makatutulong sa iyong pag-unlad.', 407),
  ('D', 'D8', 'Maging mas maingat sa pagsunod sa mga panuto.', 408),
  ('D', 'D9', 'Kailangang maging mas disiplinado sa oras.', 409),
  ('D', 'D10', 'Magkaroon ng positibong pananaw sa pag-aaral.', 410),
  ('D', 'D11', 'Huwag matakot magtanong kung may hindi nauunawaan.', 411),
  ('D', 'D12', 'Ang patuloy na pagsasanay ay magpapabuti sa iyong pagganap.', 412),
  ('D', 'D13', 'Kailangan ng higit na pokus sa klase.', 413),
  ('D', 'D14', 'Magsikap na tapusin ang lahat ng gawain sa tamang oras.', 414),
  ('D', 'D15', 'Magpatuloy sa pagsusumikap dahil kaya mong umunlad.', 415),
  ('D', 'D16', 'Makipagtulungan sa guro at magulang upang mapabuti ang pagganap.', 416),
  ('D', 'D17', 'Ang iyong pagsisikap ngayon ang magiging susi sa iyong tagumpay.', 417),
  ('D', 'D18', 'Magpakita ng higit na determinasyon sa pag-aaral.', 418),
  ('D', 'D19', 'Sikaping mapabuti ang pagdalo at pakikilahok.', 419),
  ('D', 'D20', 'Naniniwala kaming kaya mong mapabuti ang iyong pagganap.', 420),
  ('E', 'E1', 'Magalang at may paggalang sa kapwa.', 501),
  ('E', 'E2', 'Responsable sa kanyang mga tungkulin.', 502),
  ('E', 'E3', 'Nagpapakita ng katapatan at integridad.', 503),
  ('E', 'E4', 'Mahusay makisama sa mga kamag-aral.', 504),
  ('E', 'E5', 'Marunong tumanggap ng puna at payo.', 505),
  ('E', 'E6', 'Nagpapakita ng malasakit sa kapwa.', 506),
  ('E', 'E7', 'Sumusunod sa mga alituntunin ng paaralan.', 507),
  ('E', 'E8', 'May disiplina sa pag-aaral.', 508),
  ('E', 'E9', 'Pinahahalagahan ang oras at mga gawain.', 509),
  ('E', 'E10', 'Maayos ang pakikitungo sa lahat.', 510),
  ('E', 'E11', 'Ipagpatuloy ang pagiging mabuting halimbawa.', 511),
  ('E', 'E12', 'Patuloy na isabuhay ang mga mabubuting pagpapahalaga.', 512),
  ('E', 'E13', 'Nawa''y manatili ang iyong pagiging responsable.', 513),
  ('E', 'E14', 'Magpatuloy sa pagpapakita ng paggalang at kabutihang-asal.', 514),
  ('E', 'E15', 'Patuloy na maging tapat at masipag.', 515),
  ('E', 'E16', 'Pairalin ang disiplina sa lahat ng pagkakataon.', 516),
  ('E', 'E17', 'Maging bukas sa mga bagong kaalaman.', 517),
  ('E', 'E18', 'Ipagpatuloy ang pagiging mabuting mamamayan ng paaralan.', 518),
  ('E', 'E19', 'Nawa''y manatili ang iyong positibong pag-uugali.', 519),
  ('E', 'E20', 'Hangad namin ang iyong patuloy na pag-unlad at tagumpay.', 520);

ALTER TABLE sf9_comment_options
  ADD CONSTRAINT fk_sco_section FOREIGN KEY (section_code) REFERENCES sf9_remark_sections(code);

-- ------------------------------------------------------------
-- 15. Force Delete audit trail (Admin-only Force Delete of a section / student / account).
-- ------------------------------------------------------------
CREATE TABLE force_delete_log (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entity_type       ENUM('section','student','account') NOT NULL,
  entity_id         INT UNSIGNED NOT NULL,
  entity_label      VARCHAR(255) NOT NULL,
  related_summary   VARCHAR(1000) NULL,
  performed_by      INT UNSIGNED NULL,
  performed_by_name VARCHAR(255) NOT NULL,
  created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_fdl_actor FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
