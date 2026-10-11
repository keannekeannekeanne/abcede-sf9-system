# Multi-select Delete / Force Delete and multi Add

No database changes. No new dependencies.

## New / changed files
- NEW  src/Http/Batch.php — shared helpers (id/row validation, 200 ids / 100 rows per request cap)
- NEW  public/includes/multi_add_students.php — "Add multiple learners" form (shared by Enroll Students and Admin Panel)
- src/Admin/AdminService.php — deleteAccounts, deleteSections, createAccounts, createSections (loop over the existing single-record methods)
- src/Admin/ForceDeleteService.php — previewMany, executeMany (loop over existing preview()/execute(); one transaction + one force_delete_log row per record)
- src/Enrollment/EnrollmentService.php — registerMany, enrollMany (loop over existing register()/enroll())
- public/api/admin.php — actions delete_many / create_many (accounts, sections), register_many / enroll_many (students), force_delete with ids[]; ALSO FIX: the students resource now passes grade_level + year_label to EnrollmentService (enrolling a learner who had an earlier-year enrollment failed with a server error)
- public/api/enrollment.php — register_many, enroll_many (same section-access check as before)
- public/assets/js/app.js, app.css — bulkSelect, bulkDelete, bulkForceDelete, showBatchResults, multiAdd (appended; one 1-line event added in initTable)
- public/admin.php, my_classes.php, section.php, enroll_students.php — checkboxes, select-all, bars, Add-multiple buttons

## Where things are
Delete Selected + Force Delete Selected: Admin Panel > Accounts, > Sections; View Sections cards; Force Delete Selected only: section roster, Admin Panel > Students search (students have no normal Delete).
Add multiple: Admin Panel > Accounts, > Sections, > Students; Enroll Students (Register multiple learners + Enroll Selected on search results).
Everything stays admin-only / section-access-only on the server exactly as before.

## MAPEH fix (migration 005)
Databases left on the older four-component MAPEH layout (MAPEH wrapper + Music/Arts/PE/Health rows) showed no MAPEH teacher and could not edit MAPEH grades,
because the code reads the two subjects "Music and Arts" and "Physical Education and Health".
migrations/005_restore_two_mapeh_subjects.sql converts the layout (back up first; it keeps _bak_legacy_mapeh_* copies and refuses to run twice).
