# Change 007 — Grading Summary (Excel)

**What's new**
* **Grading Summary** button on the section page (`section.php`). One click downloads an Excel workbook of the whole
  section, laid out like the school's three-term grading sheet:
  `Input` · `1st Term` · `2nd Term` · `3rd Term` · `Final Summary` · `Helper` (hidden).
* **One NAME column** ("LAST, FIRST MIDDLE") instead of separate Last / First / Middle name columns, on every sheet.
* Each term sheet has: grades per learning area, MAPEH, Subject Average, General Average, Rank, Descriptor,
  Academic Excellence Award, a CLASS AVERAGE row, the "learners with failing grades per subject" table (Male / Female /
  Total, below 75) and the Submitted / Checked / Monitored by block.
* `Final Summary` shows 1st / 2nd / 3rd Term and Final Grade for the 8 learning areas, plus General Average, Rank,
  Descriptor and Award.
* MAPEH, averages, ranks, descriptors, awards, final grades and the failing table are **live Excel formulas** — edit a grade
  in the file and everything recalculates. Landscape A4, fit to one page wide, header row repeats on each printed page.

**Who can use it:** same rule as the SF9 and Temporary Report Cards — an admin, the section's adviser, or a teacher assigned
to a subject in that section (respecting the admin-assigned grade level).

**Files**
* NEW `public/grading_summary.php` (download endpoint, read-only)
* NEW `src/Export/GradingSummaryService.php` (reads grades, calculates, builds the sheets)
* NEW `src/Spreadsheet/XlsxBook.php` (formatted .xlsx writer: formulas, merged cells, colours)
* EDITED `public/section.php` (one button added)
* Unchanged: `XlsxWriter.php`, Export/Backup, SF9, grading rules (`GradeCalculator.php`), database.

**Database / dependencies / setup:** none (needs PHP `zip`, already required by Export/Backup). Copy the files over and refresh.

**Rules used (same as the SF9):** MAPEH = rounded average of Music and Arts + PE and Health; Subject Average needs all 8 areas;
General Average = rounded Subject Average; Rank = 1 + learners with a higher General Average (ties share a rank);
Award = General Average ≥ 90 and every one of the 8 areas ≥ 80; NLPA blocks the term (shown as NLPA, no average).
Descriptor bands come from the `descriptor_bands` table. Only ACTIVE learners are listed (boys first, then girls, A–Z).

**Differences from the sample Excel (its formulas had mistakes that are NOT copied):**
* Rank counted the CLASS AVERAGE row as if it were a learner (ranks were 1 too low for many learners).
* 2nd Term award tested `General Average = 90` (not ≥ 90); 3rd Term award tested the Music & Arts grade instead of the General Average;
  the 1st Term award ignored GMRC. All now use the SF9 rule above.
* CLASS AVERAGE divided by a fixed 41; it now averages the grades actually present.

**How to test:** log in → open any section with grades → *Grading Summary* → open the file in Excel. Compare a learner's
General Average / Rank with their SF9 (they match). Change a grade in a term sheet and watch the averages update.

**Addendum — section page student list:** learners are now listed Male first, then Female, each group A–Z by last name
(then first name). A **Gender** column was added and a thicker line marks where the female group starts. Search, status filter,
paging, Print SF9 and Force Delete work as before. File changed: `public/section.php`.
