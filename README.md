# Grade Sheet & SF9 Generation System

Built from scratch for **Dr. Domingo G. Abcede Elementary School** (Grades 4–6),
using the 3-Trimester grading system. PHP + MySQL backend, React (via CDN, no
build step) for the interactive Grade Sheet, Bootstrap 5 for styling.

## Tech stack

- PHP 8+ (PDO, prepared statements only — no ORM, no Composer dependencies)
- MySQL 8 / MariaDB (via XAMPP)
- React 18 + Babel Standalone, loaded from CDN directly in `grade_sheet.php`
  (no npm build step, keeping the project runnable straight from XAMPP)
- Bootstrap 5.3 + Bootstrap Icons (CDN); Chart.js 4 (CDN, dashboard / section / student pages)

## Setup (XAMPP)

1. Copy the `sf9-system/` folder into `htdocs/` (or symlink it).
2. Start Apache + MySQL in the XAMPP control panel.
3. In phpMyAdmin (or `mysql` CLI), run in order:
   ```sql
   SOURCE schema.sql;
   SOURCE seed.sql;   -- optional demo data, safe to skip in production
   ```
4. Edit `config/database.php` if your MySQL user/password differ from the
   XAMPP defaults (`root` / no password).
5. Visit `http://localhost/sf9-system/` (the login page, `index.php`, is in the project root; the other pages are in `public/`).
6. Demo login (if you ran `seed.sql`): **username** `mbanaga`, **password**
   `password123`. Change or remove this before any real deployment.

## How grades flow through the system

```
Teacher Login → Dashboard → My Classes → Section → Enroll Students → Students
    → Check Grades → Edit Grades (save) → Print SF9
(class-wide entry per subject is still available: Section → Check Grades ▾ → Grade Sheet)
```

The Grade Sheet is the only place grades are entered. The SF9 preview never
asks for grades again — it reads and computes from what was saved.

## Where the calculation rules live

All grade math is centralized in `src/Calculations/GradeCalculator.php` as
pure, unit-testable functions, with the confirmed rules documented at the
top of that file:

- MAPEH = `ROUND(AVERAGE(Music and Arts, PE and Health), 0)` — never entered directly.
- Subject Average requires **all 8** learning areas present for the trimester
  (English included — confirmed required). Missing any one leaves the
  trimester's averages/rank/descriptor blank rather than assuming a zero.
- General Average = `ROUND(Subject Average, 0)`.
- Rank = competition-style (`1 + count of students with a strictly higher average`).
- Descriptor bands and the Academic Excellence Award threshold
  (`General Average >= 90 AND MIN(all 8 areas, GMRC included) >= 80`) are
  confirmed against the school's grade sheet, applied consistently at both
  the trimester and final-SF9 level.
- **NLPA** ("No Longer Participating in Learning Activities") on any subject
  blocks that trimester (and therefore the Final Grade) from being computed
  — the SF9 preview shows a clear warning instead of a number.

If any of these rules ever change, update `GradeCalculator.php` only — every
page and the SF9 template read through it, so there is a single source of truth.

## Security notes

- All grade reads/writes go through `Auth::assertOwnsAssignment()`, which
  checks the `teacher_assignments` table server-side — a teacher cannot view
  or edit a class they aren't assigned to, no matter what the client sends.
- All SQL uses PDO prepared statements (`PDO::ATTR_EMULATE_PREPARES => false`).
- CSRF tokens (`src/Auth/Csrf.php`) protect the login form and the grade-save API.
- Grade saves run inside a DB transaction with `SELECT ... FOR UPDATE`, and
  every change is written to `grade_audit_log` (who changed what, from what,
  to what, when).
- Duplicate grades are structurally impossible: `grades` has a `UNIQUE` key
  on `(student_id, subject_id, school_year_id, trimester)`; saves use
  `INSERT ... ON DUPLICATE KEY UPDATE`, so a re-save corrects the same row
  instead of creating a new one.

## Deliberate simplifications (documented, not hidden)

- **PDF generation** uses the browser's native print-to-PDF via `window.print()`
  on a print-styled HTML page (`sf9_preview.php`), rather than a PHP PDF
  library. This avoids a Composer/Packagist dependency the sandbox that
  built this project cannot reach, and keeps the project dependency-free.
  Swapping in `dompdf` or `mPDF` later is a drop-in change scoped entirely to
  `sf9_preview.php` — the data layer (`SF9DataService`) is unaffected.
- The seed data only includes the first 10 students from the sample workbook
  and a couple of sample grades/attendance rows, enough to exercise every
  screen. Add the remaining roster the same way, or write a small importer
  against `src/Grades/GradeService.php` if you want to bulk-load the full
  Excel roster later.
- "Age" on the SF9 is computed from `students.birthdate` as of *today's
  date* at print time. If the school has a specific as-of date convention
  (e.g., age as of a fixed date in the school year), tell me and I'll pin it.

## Still open (carried over from the original grade sheet inspection)

- The `1st Term` sample data has MAPEH sub-scores (Music & Arts, PE & Health)
  completely blank for every student, and English is blank across all three
  terms for every student in the sample file — with English now confirmed
  required, any real term sheet missing it will correctly show blank
  averages until it's filled in. That's expected behavior, not a bug.
- There's an unexplained per-column "class average" row beneath the roster
  in the original `1st Term` sheet that wasn't carried into this system —
  let me know if you want a section-level statistics view added.

## Temporary Report Cards (feature update 005)

Section page -> **Temporary Report Cards** opens `public/temp_report_cards.php?section_id=N`.

- Each card holds the school header, the learner's Name / Age / Sex / LRN / Grade / Section, the
  "Dear Parents" note, Principal and Teacher names and the Learner's Progress and Achievement table,
  filled with the learner's saved grades (Terms 1-3, Final Grade, Remarks, General Average) from the same
  calculation as the SF9. Untick **Show grades** to print blank cells for writing by hand.
- One A4 sheet = 2 x 2 cards, each card a **different** learner (alphabetical, active learners only).
  The last sheet carries whatever learners are left over; unused slots stay blank, never repeated.
- **Choose learners** prints only the ticked learners. **Cut guides** toggles the dashed cutting lines.
- Age follows the SF9 rule (birth date, or the SF9 age override when one was saved). Principal / Teacher
  names are editable on screen and shared with the SF9 page.
- Same access rule as the SF9: admin, the section adviser, or a teacher assigned to the section.
- No database changes, no new dependencies. Print on A4 at 100% ("Actual size"), margins "None".

## Folder structure

```
sf9-system/
├── schema.sql              # run first
├── seed.sql                 # optional demo data
├── config/
│   └── database.php          # PDO connection settings
├── src/
│   ├── Auth/
│   │   ├── Auth.php           # session login/logout, access-control checks
│   │   └── Csrf.php
│   ├── Grades/
│   │   └── GradeService.php   # save/validate/duplicate-prevent/audit-log
│   ├── Calculations/
│   │   └── GradeCalculator.php # all grading formulas — single source of truth
│   └── SF9/
│       └── SF9DataService.php  # assembles one student's full SF9 dataset
├── index.php                # login / entry point (project root)
├── .htaccess                # blocks .sql/.md downloads, disables directory listing
└── public/                  # pages, API endpoints and assets
    ├── index.php              # stub: redirects to ../index.php
    ├── logout.php
    ├── dashboard.php
    ├── my_classes.php
    ├── grade_sheet.php         # React grade-entry grid
    ├── academic_record.php     # section roster → per-student record
    ├── sf9_preview.php         # printable SF9, matches the official template
    ├── api/
    │   └── grades.php          # JSON: GET grade sheet, POST save grades
    ├── includes/
    │   ├── header.php
    │   └── footer.php
    └── assets/css/app.css
```


---

# Multi-section update

## Upgrade steps (existing database)
1. Back up the database.
2. Run `migrations/001_one_enrollment_per_student_per_year.sql` (step 1 must return 0 rows, then step 2 adds the unique key).
3. Copy the new files over the project. Fresh installs just run `schema.sql` (+ optional `seed.sql`).
4. PHP needs the `mbstring` and `pdo_mysql` extensions (both on in XAMPP).

## The "Unexpected token '<'" bug — root cause
`GradeService::getGradeSheet()` used `:school_year_id` twice and `saveGrade()` used `:uid` twice. The project
connects with `PDO::ATTR_EMULATE_PREPARES => false`, and native prepared statements do not allow a repeated
named placeholder (`SQLSTATE[HY093]`). The PDOException was uncaught, XAMPP printed it as `<br /><b>Fatal error</b>…`
(display_errors is on), and `fetch().then(r => r.json())` choked on the `<`. Fixed at the source (positional
placeholders) **and** hardened so no future warning can do this:
- `src/Http/Http.php` — `Json::boot()` buffers output, logs (never prints) warnings/exceptions/fatals, and always answers
  `{"success": bool, "message": "...", "data": {...}}` with the right status (401/403/404/405/409/419/422/500/503).
- Endpoints no longer redirect to the HTML login page (they return 401 JSON), and 403/419 use the same envelope.
- `assets/js/app.js` `apiFetch()` never surfaces a raw parse error; non-JSON replies become a readable message and the
  raw body is logged to the browser console. A 401 sends the user back to the login page.
- Set `SF9_DEBUG=1` in the server environment to include the exception text in 500 responses while developing.

## Files
New: `src/Http/Http.php`, `src/Auth/Access.php`, `src/Enrollment/EnrollmentService.php`, `src/Analytics/SectionAnalytics.php`,
`src/Grades/StudentGradeView.php`, `public/section.php`, `public/enroll_students.php`, `public/student_grades.php`,
`public/api/{enrollment,student_grades,section_stats}.php`, `public/assets/js/app.js`, `migrations/001_…sql`.
Changed: `config/database.php` (throws instead of `die()`), `src/Auth/{Auth,Csrf}.php`, `src/Grades/GradeService.php`
(bug fix, atomic batch save, numeric validation), `public/api/grades.php`, `public/{index,dashboard,my_classes,grade_sheet,sf9_preview}.php`,
`public/includes/{header,footer}.php`, `public/assets/css/app.css`, `schema.sql`, `seed.sql`.
`academic_record.php` now redirects to `section.php`. Untouched: `GradeCalculator.php`, `SF9DataService.php`, the grading rules.

## Permission model
- Open a section: you are its adviser **or** assigned to a subject in it. Anyone else gets 403.
- Edit a grade: only subjects assigned to you in that section (checked on the server from `teacher_assignments`; the client never sends an assignment id). Advisers with no subject assignment can view and print but not edit.
- Print SF9: adviser or assigned teacher of the student's section.
- `principal` / `admin` roles have no extra access in the existing design, and still don't.

## Enrollment rules
One enrollment per student per school year (DB unique key + checked in code); current school year only; LRN must be
12 digits; a learner already in another section is never moved silently; learners with an earlier-year record may only
go to the same grade or the next one (new entrants: any grade).

## Suggested manual test pass (seed data)
Log in as `mbanaga` / `password123`: two sections appear (4-MARS, 5-JUPITER; plus a "Past" 2025-2026 one).
Enroll: search `Dela Cruz` (allowed in Grade 4/5 only), `Santos` (new entrant), `Reyes` (blocked: already in 6-SATURN),
`Test` (blocked: invalid LRN). Open `section.php?section_id=<6-SATURN id>` → 403. Edit a grade to 101 or `abc` → rejected;
save a valid one → averages refresh without reload. Print SF9 → browser print preview (A4).

---

# SF9 print layout (matches the school's form)

`public/sf9_preview.php` was rebuilt to reproduce the school's printed **Learner's Performance Report** (front) and
attendance / remarks / certificate of transfer sheet (back), traced against the scanned sample on an A5 (148 × 210 mm)
sheet. It is self-contained (no Bootstrap/CDN) so the printout never depends on the internet.

- **Front:** DepEd seal + school seal (`public/assets/img/*.png`, cropped from the scan — drop in higher-resolution
  files with the same names for a sharper print), learner data, Term 1–3 / Final Grade / Remarks table (subject order and
  wording as on the form, MAPEH sub-areas indented), General Average, Performance Descriptors.
- **Back:** Attendance Record (Jun–Apr + Total), Teacher's Comments / Remarks (Term 1–3), Parent's/Guardian's Signature,
  Certificate of Transfer, Cancellation of Eligibility to Transfer.
- **Fillable before printing:** School Head / Principal and Adviser / Teacher names (typing in one updates every place it
  appears; the school head is remembered in the browser), the Admitted-to-Grade / Eligible / Admitted-in / Date lines, and the
  teacher's remarks boxes. The adviser is pre-filled from the section; the principal from the first active `principal` account.
- **Paper:** A5 by default; the toolbar's "A4 (form centered on the sheet)" prints the same A5 layout centered on A4.
  Print double-sided: page 1 = front, page 2 = back. Blank cells stay blank (no dashes) like the form.
- **Remarks column:** "Passed" when the Final Grade is 75 or higher, otherwise "Failed" (per the descriptor table).
  Trimester general averages, rank and award are not on the form, so they are shown only in an on-screen strip above the sheet.
- The Performance Descriptors table is printed exactly as on the sample (`$SCALE` at the top of `sf9_preview.php`).


---

# Feature update 004 — grade assignment, Force Delete, Excel import/backup, account settings, SF9 improvements

## Upgrade steps (existing database)

1. **Back up your database first** (phpMyAdmin → Export, or `mysqldump sf9_system > backup.sql`).
2. Copy the new project files over the old ones (nothing else to install — no Composer, no extra packages).
3. Run **`migrations/004_grade_assignment_force_delete_remarks_age.sql`** once (phpMyAdmin → *Import*, or
   `mysql -u root sf9_system < migrations/004_grade_assignment_force_delete_remarks_age.sql`).
   It only **adds** columns/tables and loads the 100 preset remarks; no existing row is changed or deleted.
   Fresh installs get everything from `schema.sql`.
4. PHP needs the **zip** extension for `.xlsx` files (`extension=zip`, already enabled in XAMPP). Reading old `.xls` files needs nothing extra.

Until step 3 is done the teacher screens keep working, but the Admin Panel accounts list and the new Admin features need the migration.

## What was added

| Feature | Where to find it | Main files |
|---|---|---|
| **Assign Teacher** to Grade 4 / 5 / 6 | Admin Panel → *Assign Teacher* | `AdminService::assignGradeLevel`, `api/admin.php` (`grade_assignments`), `Auth::assignedGradeLevel`, `Access.php` |
| **Force Delete** (section, learner, account) | red *Force Delete* button next to Delete (Admin Panel, My Classes, section page) | `src/Admin/ForceDeleteService.php`, `api/admin.php` (`force_delete`), `forceDelete()` in `app.js` |
| **Import Data** (.xls / .xlsx / .csv, incl. DepEd SF1) | Admin Panel → *Students* → *Import Data* (+ *Download Template*) | `src/Import/StudentImportService.php`, `src/Spreadsheet/*`, `api/student_import.php`, `student_import_template.php` |
| **Export/Backup** sections to Excel | Admin Panel → *Export/Backup*, and *Export/Backup* on a section page | `src/Export/SectionBackupService.php`, `export_sections.php` |
| **Account Settings** | gear icon beside *Log out* | `account.php`, `api/account.php` |
| **Teacher's Remarks by Section A–E** | SF9 Preview → back page, per term | `sf9_preview.php`, `api/sf9_remarks.php`, tables `sf9_remark_sections`, `sf9_comment_options` |
| **Text auto-fit** | SF9 Preview (automatic) | `sf9_preview.php` |
| **Custom Age** | SF9 Preview → *Age* field + *Save Age* / *Reset Age* | `api/sf9_age.php`, `enrollments.sf9_age_override` |

## Database changes (migration 004)

* `users.assigned_grade_level` (4, 5, 6 or NULL) — NULL = not restricted (every existing teacher starts as NULL).
* `grades.teacher_assignment_id` is now nullable with `ON DELETE SET NULL`, so removing a subject assignment/teacher never destroys grades.
* `force_delete_log` — permanent record of every Force Delete (who, what, how much).
* `account_audit_log.action` gains `grade_assigned`, `grade_unassigned`, `self_edited`, `password_changed`.
* `sf9_remark_sections` (A–E), `sf9_comment_options.section_code/code` (the 100 preset remarks; the 8 older comments stay as "General"), `teacher_remarks.remark_section`.
* `enrollments.sf9_age_override`.

## Decisions worth knowing

* **Grade-level assignment.** A teacher with an assigned grade only sees/opens that grade's sections, students, grade sheets, SF9s and APIs (checked server-side in `Access` and `Auth::assertOwnsAssignment`). Teachers with *no* grade assigned behave exactly as before. The assignment can only be changed by an Admin. Changing a teacher to a grade where they still advise/teach other-grade classes asks the Admin to confirm (nothing is deleted); an assigned teacher can no longer be put on another grade's section.
* **Force Delete** removes, in one transaction: *section* → its enrollments, grades (+ grade audit), attendance, SF9 remarks and subject assignments (learners are kept); *learner* → everything belonging to that learner; *account* → the account only — grades/remarks it authored are kept and re-attributed to the Admin, advised sections become adviser-less, its subject assignments are removed. The last active Admin and your own account cannot be deleted. Normal Delete is unchanged.
* **Import.** Columns are found by heading text, so the school's template and the SF1 register both work. Existing LRNs are never overwritten; same-name + same-birth-date learners with a different LRN are flagged *possible duplicate* and skipped unless the Admin ticks the box. A preview (nothing saved) comes first; the import runs only after confirmation. Age columns in the file are ignored (age is always calculated from the birth date). Dates are read as mm/dd/yyyy (the SF1 way); Excel date cells work as well.
* **Backup** is read-only (SELECTs only). It stores grades *as recorded*; MAPEH, averages, rank and descriptors are calculated and therefore not stored. There is no "restore" — it is a copy for safekeeping/reporting.
* **Account Settings** lets every user change their own name/email and password (current password required, 5 wrong tries / 15 min lock). Username, role and grade level are Admin-managed. `api/change_password.php` now works only for the forced "set a new password" screen, so it cannot bypass the current-password check.
* **Remarks "Sections A–E"** are the five remark groups of *SF9 Teacher's Remarks* (Outstanding, Very Satisfactory, Satisfactory, Fairly Satisfactory / Needs Improvement, Character and Values) — not the classroom sections (MARS, JUPITER…). Pick a Section, then one of its 20 remarks or type your own; the chosen Section is stored per term and restored on reopening. The server refuses a ready-made remark saved under the wrong Section.
* **Age** is still calculated from the birth date. A value typed on the SF9 is saved as an **SF9-only override on that learner's enrollment for that school year**, so it never changes another learner, another school year, or the birth date. *Reset Age* removes it.
* **Auto-fit.** Names/values shrink to a readable minimum, then wrap on two small lines; remark boxes shrink until the text fits and show a red "Too long for this box" note if even the smallest size cannot hold it.


# Feature update 006 — Import from Excel inside a section

See `CHANGES-006.md`. Admin -> open a section -> **Import from Excel** (also on *Enroll Students*). Same preview/confirm import as *Admin Panel -> Import Data*, but it always enrolls the new learners in the section you are viewing.
