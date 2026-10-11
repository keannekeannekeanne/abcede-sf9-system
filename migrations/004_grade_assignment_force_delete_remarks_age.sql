-- ============================================================
-- Migration 004 — Feature update
--   1. Teacher grade-level assignment            (users.assigned_grade_level)
--   2. Force Delete support + audit trail        (force_delete_log, grades.teacher_assignment_id -> nullable)
--   3. Account self-service audit actions        (account_audit_log.action extended)
--   4. SF9 Teacher's Remarks by Section A-E      (sf9_remark_sections, sf9_comment_options.section_code/code,
--                                                 teacher_remarks.remark_section) + the 100 preset remarks
--   5. SF9 customizable age                      (enrollments.sf9_age_override)
--
-- Run ONCE, AFTER migrations 001-003 (fresh installs already get all of this from schema.sql).
-- Back up the database first. Nothing here deletes or rewrites existing rows:
-- every new column is NULLable, so existing records keep working unchanged.
-- ============================================================
USE sf9_system;

-- ------------------------------------------------------------
-- 1. Grade-level assignment. NULL = "not restricted to one grade" (the behaviour every
--    existing teacher has today), so nothing changes until an Admin assigns a grade.
-- ------------------------------------------------------------
ALTER TABLE users
  ADD COLUMN assigned_grade_level TINYINT UNSIGNED NULL AFTER role,
  ADD CONSTRAINT chk_users_assigned_grade CHECK (assigned_grade_level IS NULL OR assigned_grade_level IN (4,5,6));

-- ------------------------------------------------------------
-- 2/3. Audit: new account actions.
-- ------------------------------------------------------------
ALTER TABLE account_audit_log
  MODIFY action ENUM('created','role_changed','activated','deactivated','password_reset','edited',
                     'grade_assigned','grade_unassigned','self_edited','password_changed') NOT NULL;

-- ------------------------------------------------------------
-- 2. Force Delete.
--    a) A grade must be able to outlive the subject-assignment row that recorded it, otherwise
--       force-deleting a teacher account would have to destroy that teacher's students' grades.
--       teacher_assignment_id becomes NULLable with ON DELETE SET NULL (nothing reads it back).
--    b) force_delete_log keeps a permanent record of every Force Delete (who, what, how much).
-- ------------------------------------------------------------
ALTER TABLE grades DROP FOREIGN KEY fk_g_assignment;
ALTER TABLE grades MODIFY teacher_assignment_id INT UNSIGNED NULL;
ALTER TABLE grades
  ADD CONSTRAINT fk_g_assignment FOREIGN KEY (teacher_assignment_id) REFERENCES teacher_assignments(id) ON DELETE SET NULL;

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

-- ------------------------------------------------------------
-- 4. SF9 Teacher's Remarks: Sections A-E (from "SF9 Teacher's Remarks" list).
--    The 8 older predefined comments keep section_code = NULL and stay available ("General").
-- ------------------------------------------------------------
CREATE TABLE sf9_remark_sections (
  code        CHAR(1) PRIMARY KEY,               -- 'A'..'E'
  title       VARCHAR(80) NOT NULL,
  sort_order  TINYINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;

ALTER TABLE sf9_comment_options
  ADD COLUMN section_code CHAR(1) NULL AFTER comment_text,
  ADD COLUMN code VARCHAR(4) NULL AFTER section_code,
  ADD KEY idx_sco_section (section_code, sort_order);

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

-- Which Section (A-E) a saved term remark was written under; NULL for free text / older remarks.
ALTER TABLE teacher_remarks
  ADD COLUMN remark_section CHAR(1) NULL AFTER remarks,
  ADD CONSTRAINT fk_rem_section FOREIGN KEY (remark_section) REFERENCES sf9_remark_sections(code) ON DELETE SET NULL;

-- ------------------------------------------------------------
-- 5. SF9-specific age override, stored per ENROLLMENT (= per student per school year) so it can
--    never leak to another student or another year, and never touches students.birthdate.
--    NULL = use the age computed from the birthdate (existing behaviour).
-- ------------------------------------------------------------
ALTER TABLE enrollments
  ADD COLUMN sf9_age_override TINYINT UNSIGNED NULL AFTER roster_no;

-- Done.
