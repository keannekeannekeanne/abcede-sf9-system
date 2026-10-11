# Change log — feature update 004

Run `migrations/004_grade_assignment_force_delete_remarks_age.sql` once (back up first). See README → "Feature update 004".

## New files
- migrations/004_grade_assignment_force_delete_remarks_age.sql
- src/Admin/ForceDeleteService.php
- src/Import/StudentImportService.php
- src/Export/SectionBackupService.php
- src/Spreadsheet/SpreadsheetReader.php  (.xls BIFF8 / .xlsx / .csv reader, no Composer)
- src/Spreadsheet/XlsxWriter.php         (.xlsx writer, no Composer)
- public/account.php, public/api/account.php
- public/api/student_import.php, public/student_import_template.php
- public/export_sections.php
- public/api/sf9_age.php

## Modified files
- schema.sql (new columns/tables/presets, for fresh installs)
- src/Auth/Auth.php, src/Auth/Access.php          (grade-level enforcement)
- src/Admin/AdminService.php                       (grade assignment, grade checks, scoped subject-teacher removal)
- public/api/admin.php                             (grade_assignments, force_delete, backup_sections)
- public/api/section_assignments.php               (an adviser can only remove assignments of their own section)
- public/api/change_password.php                   (forced-change screen only)
- public/api/sf9_remarks.php                       (remark Section A-E)
- public/admin.php, public/my_classes.php, public/section.php, public/includes/header.php, public/assets/js/app.js
- public/sf9_preview.php                           (remarks by Section, auto-fit, custom age)
- README.md

## Packages / dependencies
None. PHP `zip` extension (on by default in XAMPP) is used for .xlsx files.
