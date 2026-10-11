# SF9 System — Development Report
Priority tasks: MAPEH restructuring, Admin Panel, Account Management, Section/Enrollment, Subject-Teacher Assignment, Grade Permissions, Auto-Save

---

## 1. COMPLETED FEATURES

### MAPEH
- MAPEH is now one subject (`subjects.is_group = 1`) with exactly one teacher assignment per section/year.
- The grade sheet shows four separate rows: **Music, Arts, Physical Education, Health**, each its own `subjects` row (`parent_group = 'MAPEH'`).
- `MAPEH = ROUND((Music + Arts + Physical Education + Health) / 4, 0)` — computed on read, **never stored**, so it can never drift from its inputs or be hand-edited.
- MAPEH teacher assignment automatically grants edit rights on all four components (no separate assignment rows for Music/Arts/PE/Health).
- Verified live: saved 88/92/85/90 → system returned 89, correctly reflected on the grade sheet, the per-student view, and the SF9 preview.

### Admin Panel
- New `/admin.php` page (tabs: Accounts, Sections, Subject Teachers, Students), reachable only via `Auth::requireAdmin()`.
- Backed by `public/api/admin.php`, every action re-checked server-side.

### Account Management
- Add Account (role, initial password, active flag), Edit Account, Activate/Deactivate, Reset Password.
- Only Admins can create accounts — enforced in `AdminService`/`api/admin.php`, not just hidden UI (verified: a teacher session hitting `api/admin.php` gets a 403).
- Passwords are always `password_hash()`-hashed; a reset sets `must_change_password`, which forces the account through `change_password.php` before it can reach any other page (verified live).
- Every create/edit/activate/deactivate/reset is written to `account_audit_log`.

### Section Management
- **My Classes → Create Section** for any teacher (they become adviser).
- Admin can also create a section for any teacher via the Admin Panel.
- Duplicate (grade + name + year) sections are rejected (verified).

### Teacher Assignment
- `Section → Subject → Assign/Remove Teacher`, from either the section's Adviser (their own section only) or an Admin (any section).
- `teacher_assignments` now has `UNIQUE (section_id, subject_id, school_year_id)`: re-assigning a subject **replaces** the previous teacher rather than creating a duplicate — verified live (assignment id changed from 39→40→41 across three re-assignments of the same MAPEH slot, never two rows at once).

### Grade Management
- A teacher can only save grades for subjects mapped to their own `teacher_assignments` rows, expanded for MAPEH into the four components (`Access::editableSubjectIds()`).
- Verified server-side rejections: another teacher's subject, a non-MAPEH subject smuggled under a MAPEH assignment, and a direct write to the MAPEH group id itself.
- Admin has a separate, broader `allEditableSubjectIds()` path used only for admin overrides on the per-student grade page.

### Auto-Save
- `grade_sheet.php` rewritten: **no Save button**. Each cell debounces ~600ms after the last keystroke, then POSTs; status dropdown changes save immediately.
- Per-cell "Saving… / Saved / Save failed" indicator.
- MAPEH average recalculates on the page immediately after any component save succeeds.
- Verified live via direct API calls (the debounce/UI timing itself could not be verified in a headless CLI test — see §6).

---

## 2. DATABASE CHANGES

**Tables added:** `account_audit_log`.

**Columns added:**
- `users`: `email`, `must_change_password`, `created_by`
- `sections`: `track_strand`, `created_by`, `created_at`
- `subjects`: `is_group`, `is_active`
- `teacher_assignments`: `assigned_by`, `created_at`

**Relationships / constraints changed:**
- `subjects`: "Music and Arts" renamed in place to `MAPEH` (`is_group=1`); "Physical Education and Health" deprecated (`is_active=0`, kept for historical FK integrity); four new rows added — Music, Arts, Physical Education, Health (`parent_group='MAPEH'`).
- `teacher_assignments`: new `UNIQUE (section_id, subject_id, school_year_id)` — one teacher per subject per section/year, which is what makes "one MAPEH teacher" actually enforced by the database, not just application logic.
- New FKs: `users.created_by → users.id`, `sections.created_by → users.id`, `teacher_assignments.assigned_by → users.id`, plus `account_audit_log`'s two FKs to `users`.

**Migration:** `migrations/002_mapeh_components_and_admin.sql` handles all of the above for an existing installation, including:
- A **documented, non-destructive** strategy for legacy MAPEH data: a single old "Music and Arts" grade is copied into *both* new Music and Arts rows (and likewise PE/Health) as a starting point, since a single number cannot be losslessly split into two. Teachers should review and correct these individually going forward — this is called out explicitly in the migration file, not silently done.
- A conflict-detection step (`_migration_002_conflicts` table) for the edge case where an old install had two *different* teachers on the old two-component MAPEH design — that can't be auto-merged safely, so it's surfaced for an admin to resolve by hand rather than silently dropped.

**A fresh install** (the state I tested against) simply uses the updated `schema.sql` + `seed.sql` directly; the migration is for existing production data.

---

## 3. PERMISSION CHANGES

| Role | Can do |
|---|---|
| **Head/Admin** | Everything: all accounts (create/edit/reset password/activate/deactivate), all sections, all subject-teacher assignments, all students, can override grades via the per-student page. Cannot deactivate their own account (verified — blocked) or remove their own admin role while editing themselves. |
| **Adviser** | Full access to their own section(s): student enrollment, subject-teacher assignment for that section only (`Access::requireSection()` checks `is_adviser` server-side). |
| **Subject Teacher** | Grade entry only for subjects in their own `teacher_assignments`, only in sections they're assigned to. Verified: 403 on another teacher's assignment id. |
| **MAPEH Teacher** | Same as Subject Teacher, but their single MAPEH assignment expands to Music/Arts/PE/Health. Cannot write the computed MAPEH value directly (verified: rejected). |
| **Students** | No accounts, no login, no permissions — unchanged from the original system; nothing in this work added any student-facing auth. |

All of the above is enforced in `Access.php` / `AdminService.php` / the `public/api/*.php` endpoints — never only in the UI.

---

## 4. FILES CHANGED

| File | What changed | Why |
|---|---|---|
| `schema.sql` | `subjects` split into MAPEH group + 4 components; new `account_audit_log` table; new columns on `users`/`sections`/`teacher_assignments`; new unique constraint | Phase 1 + Admin Panel foundation |
| `migrations/002_mapeh_components_and_admin.sql` | *(new)* Upgrade path for existing installs, with documented data-continuity strategy | Don't break existing deployments |
| `seed.sql` | Demo data updated: MAPEH assignment now targets the single group subject only (not each component); added a `headadmin` account and an extra teacher | Keep demo data valid under the new schema |
| `src/Calculations/GradeCalculator.php` | `mapeh()` now takes 4 args; added `MAPEH_COMPONENTS` constant | Phase 1 |
| `src/Auth/Access.php` | Admin bypass in `sectionsFor`/`requireSection`; new `editableSubjectIds()`, `allEditableSubjectIds()`, `mapehGroupSubjectId()` | MAPEH permission expansion + admin access |
| `src/Auth/Auth.php` | `isAdmin()`, `requireAdmin()`, `mustChangePassword()`; session now carries `must_change_password` | Admin gate, forced password change |
| `src/Admin/AdminService.php` | *(new)* Accounts, sections, subject-teacher assignment, student lookups — all the actual Admin Panel logic | Priorities 2–9 |
| `src/Grades/GradeService.php` | New `getMapehSheet()` | 4-column MAPEH grade sheet with computed average |
| `src/Grades/StudentGradeView.php` | Fixed to use `editableSubjectIds()`/`allEditableSubjectIds()` instead of the raw (unexpanded) assignment map — a MAPEH teacher couldn't otherwise edit components on this page | Bug found during testing |
| `src/SF9/SF9DataService.php`, `src/Analytics/SectionAnalytics.php` | Updated to 4-component MAPEH formula everywhere the old 2-component one was used | Keep SF9 output and analytics correct |
| `public/api/grades.php` | Rewritten: branches MAPEH (per-component rows) vs. regular subjects; validates every subject_id against the teacher's actual editable set | Priority 10/11 security + MAPEH |
| `public/api/student_grades.php` | Uses the new editable-subject helpers instead of the stale assignment map | Same MAPEH-editing bug as StudentGradeView |
| `public/api/admin.php` | *(new)* Admin Panel JSON API | Priorities 2–9 |
| `public/api/section_create.php` | *(new)* Teacher-initiated section creation | Priority 7 |
| `public/api/section_assignments.php` | *(new)* Adviser/Admin subject-teacher assignment for one section | Priority 9 |
| `public/api/change_password.php` | *(new)* Self-service password change, used by the forced-change flow | Priority 4 |
| `public/admin.php` | *(new)* Admin Panel UI | Priority 2 |
| `public/change_password.php` | *(new)* Forced-change page | Priority 4 |
| `public/grade_sheet.php` | Rewritten: true auto-save (no Save button), 4-column MAPEH layout with computed average column | Priority 1 + 11 |
| `public/my_classes.php` | Added "Create Section" button/modal | Priority 7 |
| `public/section.php` | Added Subject Teacher Assignment panel for advisers/admins | Priority 9 |
| `public/includes/header.php` | Admin nav link; forced-password-change redirect gate | Priorities 2, 4 |
| `public/assets/css/app.css` | Auto-save badges, MAPEH sheet styling, `.auth-narrow` | Supports the above UI |

---

## 5. SECURITY CHECK

| Item | Verified |
|---|---|
| Admin-only teacher account creation | **PASS** — teacher session gets 403 on `api/admin.php` |
| Secure password hashing | **PASS** — `password_hash()`/`password_verify()` throughout, no plaintext anywhere |
| Admin-only password management | **PASS** — same 403 boundary as above |
| Backend role validation | **PASS** — `Auth::requireAdmin()` on every admin endpoint, independent of UI |
| Subject-based grade permissions | **PASS** — verified 3 different smuggling/cross-subject attempts, all rejected |
| Section-based permissions | **PASS** — `Access::requireSection()` 403s a teacher outside their own sections |
| Unauthorized endpoint protection | **PASS** — tested directly against the API, not just through the UI |
| No student authentication | **PASS** — nothing added creates student accounts; `students` table has no password/login concept |
| MAPEH average cannot be manually overridden | **PASS** — direct write to the group subject id rejected; average is never a writable column |

---

## 6. TEST RESULTS

All of the following were run as real HTTP-equivalent requests (`php-cgi`) against a live MariaDB instance seeded from `schema.sql`/`seed.sql` — not just read from code.

| Test | Result |
|---|---|
| Admin creates teacher account | **PASS** |
| Admin assigns role | **PASS** (set at creation; edit path also exists, not separately re-tested) |
| Admin resets password | **PASS**, including forced `must_change_password` gate |
| Admin changes password (self-service after reset) | **PASS** |
| Teacher login | **PASS** |
| Create section (teacher, via My Classes) | **NOT TESTED** — API (`section_create.php`) reuses the same `AdminService::createSection()` verified under admin, but the endpoint itself wasn't hit directly in this pass |
| Create section (admin) | **PASS**, duplicate name correctly rejected |
| Enroll student | **PASS** (via `register` — creates + enrolls in one step) |
| Assign subject teacher | **PASS** |
| Remove/reassign subject teacher | **PASS** — confirmed single-teacher replacement, not duplication |
| Edit assigned subject grade | **PASS** (regular subject, Filipino) |
| Attempt unauthorized grade editing | **PASS** — 3 separate attack attempts, all rejected with correct error messages |
| MAPEH teacher assignment | **PASS** |
| Music/Arts/PE/Health grade editing | **PASS** — all 4 components saved and read back correctly |
| Automatic MAPEH calculation | **PASS** — 88/92/85/90 → 89, confirmed in both the grade API and the SF9 preview |
| MAPEH cannot be manually edited | **PASS** |
| Auto-save (network behavior) | **PARTIALLY TESTED** — the underlying save API is fully verified; the browser-side debounce/keystroke timing in `grade_sheet.php` was written and code-reviewed but not exercised in an actual browser (no browser automation available in this environment) |
| SF9 workflow | **PASS** — preview renders, shows correct MAPEH figure for the graded term; full 3-term/8-area final-average path **NOT TESTED** (only trimester 1 / MAPEH was graded in this test pass) |
| Mobile/responsive layout | **NOT TESTED** — no visual/browser testing available in this environment |

---

## 7. REMAINING ISSUES

- **Auto-save UI behavior (debounce, spinner timing, keystroke handling)** — NOT TESTED in an actual browser. Logic was written carefully and the underlying API is verified, but I'd want to click through it once in a real browser before calling it fully done.
- **`enroll_students.php`** (the original enrollment page) was left as-is and not integrated with or re-tested against the new admin flow; the new admin Student tab uses `EnrollmentService` directly and was tested independently.
- **Mobile/responsive layout** — not visually verified.
- **Migration 002's conflict path** (two different teachers on the legacy 2-component MAPEH design) is written but NOT TESTED, since the seed data never produces that state. Recommend a dry run against a real backup before applying to any live pre-existing database.
- **Missing PHP extension discovered during this work**: `php-mbstring` is required and was not installed on the environment I was given — worth double-checking on whatever server this eventually deploys to.
- **Role "principal"** still has no special permissions beyond a base teacher (unchanged from the original system) — the spec's "Head/Administrator" is mapped to the existing `admin` role; if "principal" was meant to carry elevated access too, that wasn't in scope of what was asked and wasn't added.
- Account **edit** (role change on an existing user) and account **listing/search** were built and used internally during testing but not independently stress-tested (e.g., changing a user's role while they have an active session).
