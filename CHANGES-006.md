# Change 006 — Import students from Excel straight into a section

**What's new**
* **Import from Excel** button on the section page (`section.php`) and on *Enroll Students* (`enroll_students.php`).
  Admin only, current school year only. Upload an `.xls`, `.xlsx`, `.csv` or the DepEd **SF1** as-is -> review the preview ->
  confirm -> every new learner is saved and **enrolled in that section** (roster numbers assigned by the existing enrollment rules).
* The preview warns (does not block) when the SF1 heading names a different grade or section than the one you are importing into.
* After a successful import the roster refreshes automatically when the popup is closed.

**Files**
* NEW `public/includes/section_import.php` (popup + script)
* EDITED `public/section.php`, `public/enroll_students.php` (button + include)
* Reused unchanged: `api/student_import.php`, `src/Import/StudentImportService.php`, `src/Spreadsheet/*`, `student_import_template.php`

**Database / dependencies / setup:** none. Copy the files over and refresh the browser.

**Rules that still apply:** LRN = 12 digits; an LRN already in the system is skipped, never overwritten; same name + birth date with a
different LRN is a "possible duplicate" (skipped unless ticked); nothing is written until you confirm.

**How to test:** log in as Admin -> *View Sections* -> open Grade 4 - MARS -> *Import from Excel* -> choose `SF1_2026_Grade-4-MARS.xls`
-> *Check File* (41 learners ready) -> *Import* -> close the popup and see the 41 learners in the list.
