<?php
// JSON API for the Admin Panel. Every action here is re-checked server-side
// with Auth::requireAdmin() — the admin-only nav link/buttons in the UI are
// a convenience, never the actual security boundary.
require_once __DIR__ . '/../../src/Http/Http.php';
Json::boot();

require_once __DIR__ . '/../../src/Auth/Auth.php';
require_once __DIR__ . '/../../src/Auth/Csrf.php';
require_once __DIR__ . '/../../src/Auth/Access.php';
require_once __DIR__ . '/../../src/Admin/AdminService.php';
require_once __DIR__ . '/../../src/Admin/ForceDeleteService.php';
require_once __DIR__ . '/../../src/Export/SectionBackupService.php';
require_once __DIR__ . '/../../src/Enrollment/EnrollmentService.php';
require_once __DIR__ . '/../../src/Settings/Settings.php';
require_once __DIR__ . '/../../src/Http/Batch.php';

Auth::requireAdmin();   // <-- the actual server-side enforcement (never trust the client)
$method = Json::requireMethod('GET', 'POST');
$resource = (string) ($_GET['resource'] ?? '');
$body = [];

if ($method === 'POST') {
    $body = Json::body();
    Csrf::validateOrFail(Csrf::fromRequest($body));
    $resource = (string) ($body['resource'] ?? $resource);
}

$actingId = Auth::userId();

/** "3 accounts deleted, 1 could not be deleted." — summary line for a multi-select delete. */
function bulk_message(array $r, string $noun, string $doneVerb, string $failVerb): string
{
    $ok   = count($r['deleted']);
    $bad  = count($r['failed']);
    $text = Batch::plural($ok, $noun) . ' ' . $doneVerb;
    return $bad > 0 ? $text . ', ' . $bad . ' ' . $failVerb . '.' : $text . '.';
}

switch ($resource) {
    case 'accounts':
        if ($method === 'GET') {
            Json::ok(['accounts' => AdminService::listAccounts()], 'Accounts loaded.');
        }
        $action = (string) ($body['action'] ?? 'create');
        if ($action === 'create') {
            Json::ok(AdminService::createAccount($body, $actingId), 'Account created.');
        }
        if ($action === 'update') {
            AdminService::updateAccount((int) ($body['user_id'] ?? 0), $body, $actingId);
            Json::ok(null, 'Account updated.');
        }
        if ($action === 'set_active') {
            AdminService::setActive((int) ($body['user_id'] ?? 0), (bool) ($body['is_active'] ?? true), $actingId);
            Json::ok(null, 'Account status updated.');
        }
        if ($action === 'reset_password') {
            AdminService::resetPassword((int) ($body['user_id'] ?? 0), (string) ($body['new_password'] ?? ''), $actingId);
            Json::ok(null, 'Password reset. The account must set a new password at next login.');
        }
        if ($action === 'delete') {
            AdminService::deleteAccount((int) ($body['user_id'] ?? 0), $actingId);
            Json::ok(null, 'Account deleted.');
        }
        // --- multiple records (multi-select Delete / multi Add): each record is handled by the single-record code above ---
        if ($action === 'delete_many') {
            $r = AdminService::deleteAccounts(Batch::ids($body['user_ids'] ?? []), $actingId);
            Json::ok($r, bulk_message($r, 'account', 'deleted', 'could not be deleted'));
        }
        if ($action === 'create_many') {
            $r = AdminService::createAccounts(Batch::rows($body['rows'] ?? []), $actingId);
            Json::ok($r, Batch::plural($r['added'], 'account') . ' created' . ($r['failed'] ? ', ' . $r['failed'] . ' could not be created.' : '.'));
        }
        throw new HttpException(422, 'Unknown account action.');

    case 'sections':
        if ($method === 'GET') {
            Json::ok(['sections' => AdminService::listSections()], 'Sections loaded.');
        }
        $action = (string) ($body['action'] ?? 'create');
        if ($action === 'delete') {
            AdminService::deleteSection((int) ($body['section_id'] ?? 0));
            Json::ok(null, 'Section deleted.');
        }
        if ($action === 'delete_many') {
            $r = AdminService::deleteSections(Batch::ids($body['section_ids'] ?? []));
            Json::ok($r, bulk_message($r, 'section', 'deleted', 'could not be deleted'));
        }
        if ($action === 'create_many') {
            $r = AdminService::createSections(Batch::rows($body['rows'] ?? []), $actingId);
            Json::ok($r, Batch::plural($r['added'], 'section') . ' created' . ($r['failed'] ? ', ' . $r['failed'] . ' could not be created.' : '.'));
        }
        Json::ok(AdminService::createSection($body, $actingId), 'Section created.');

    case 'school_years':
        if ($method === 'GET') {
            Json::ok(['school_years' => AdminService::listSchoolYears()], 'School years loaded.');
        }
        $action = (string) ($body['action'] ?? 'create');
        if ($action === 'set_active') {
            AdminService::setActiveSchoolYear((int) ($body['school_year_id'] ?? 0));
            Json::ok(null, 'Active school year updated.');
        }
        Json::ok(AdminService::createSchoolYear((string) ($body['year_label'] ?? ''), (bool) ($body['make_active'] ?? false)), 'School year created.');

    case 'settings':
        if ($method === 'GET') {
            Json::ok(['settings' => Settings::all()], 'Settings loaded.');
        }
        $key = (string) ($body['key'] ?? '');
        $value = (string) ($body['value'] ?? '');
        if ($key === '') {
            throw new HttpException(422, 'A setting key is required.');
        }
        Settings::set($key, $value);
        Json::ok(null, 'Setting saved.');

    case 'assignments':
        if ($method === 'GET') {
            $sectionId = (int) ($_GET['section_id'] ?? 0);
            $yearId    = (int) ($_GET['school_year_id'] ?? 0);
            Json::ok([
                'assignments' => AdminService::assignmentsForSection($sectionId, $yearId),
                'teachers'    => AdminService::assignableTeachers(),
            ], 'Assignments loaded.');
        }
        $action = (string) ($body['action'] ?? 'assign');
        if ($action === 'assign') {
            $r = AdminService::assignSubjectTeacher(
                (int) ($body['section_id'] ?? 0), (int) ($body['subject_id'] ?? 0),
                (int) ($body['teacher_id'] ?? 0), (int) ($body['school_year_id'] ?? 0), $actingId
            );
            Json::ok($r, $r['subject_name'] . ' teacher assigned.');
        }
        if ($action === 'remove') {
            AdminService::removeSubjectTeacher((int) ($body['assignment_id'] ?? 0));
            Json::ok(null, 'Teacher removed from subject.');
        }
        throw new HttpException(422, 'Unknown assignment action.');

    case 'grade_assignments':
        // Teacher Assignment by Grade Level (Grade 4 / 5 / 6). Admin-only via Auth::requireAdmin() above.
        if ($method === 'GET') {
            Json::ok(['teachers' => AdminService::teachersWithGrades(), 'grade_levels' => AdminService::GRADE_LEVELS], 'Teachers loaded.');
        }
        $action    = (string) ($body['action'] ?? 'assign');
        $teacherId = (int) ($body['teacher_id'] ?? 0);
        if ($action === 'assign') {
            $grade = (int) ($body['grade_level'] ?? 0);
            if ($grade === 0) {
                // Never let a missing grade fall through as "unassign" — removing is its own explicit action.
                throw new HttpException(422, 'Select Grade 4, Grade 5 or Grade 6.', ['errors' => ['grade_level' => 'Select Grade 4, 5 or 6.']]);
            }
            $r = AdminService::assignGradeLevel($teacherId, $grade, $actingId, (bool) ($body['confirm_conflicts'] ?? false));
            Json::ok($r, $r['changed'] ? 'Teacher assigned to Grade ' . $r['assigned_grade_level'] . '.' : 'No change — the teacher already has that assignment.');
        }
        if ($action === 'unassign') {
            $r = AdminService::assignGradeLevel($teacherId, null, $actingId);
            Json::ok($r, $r['changed'] ? 'Teacher removed from their grade level.' : 'The teacher had no grade level assigned.');
        }
        throw new HttpException(422, 'Unknown grade-assignment action.');

    case 'backup_sections':
        Json::ok(['sections' => SectionBackupService::listSections()], 'Sections loaded.');

    case 'force_delete':
        // Force Delete (section / student / account). Separate from the normal delete actions above,
        // which are unchanged. Admin-only (Auth::requireAdmin() at the top of this file).
        if ($method === 'GET' && isset($_GET['ids'])) {
            // Multi-select: combined preview for the selected records.
            Json::ok(ForceDeleteService::previewMany((string) ($_GET['entity'] ?? ''), Batch::ids((string) $_GET['ids'])), 'Preview loaded.');
        }
        if ($method === 'GET') {
            // Preview: what will be removed / kept — drives the warning dialog.
            Json::ok(ForceDeleteService::preview((string) ($_GET['entity'] ?? ''), (int) ($_GET['id'] ?? 0)), 'Preview loaded.');
        }
        if (($body['confirmed'] ?? false) !== true) {
            throw new HttpException(422, 'Force Delete needs explicit confirmation. Nothing was deleted.');
        }
        if (isset($body['ids'])) {
            // Multi-select Force Delete: every record is force deleted by the same execute() as a single one.
            $r = ForceDeleteService::executeMany((string) ($body['entity'] ?? ''), Batch::ids($body['ids']), $actingId);
            Json::ok($r, bulk_message($r, 'record', 'force deleted', 'could not be force deleted'));
        }
        $r = ForceDeleteService::execute((string) ($body['entity'] ?? ''), (int) ($body['id'] ?? 0), $actingId);
        Json::ok($r, $r['label'] . ' was force deleted.');

    case 'student_directory':
        // Admin Panel -> Students -> Select Student -> View Grades, directly,
        // without navigating through View Sections first (requirement 4.1).
        Json::ok([
            'results' => AdminService::studentsDirectory(
                (string) ($_GET['q'] ?? ''),
                isset($_GET['grade_level']) && $_GET['grade_level'] !== '' ? (int) $_GET['grade_level'] : null
            ),
        ], 'Students loaded.');

    case 'students':
        // Reuses EnrollmentService so student-creation rules (LRN format, grade
        // continuity, duplicate LRN) stay identical for teachers and admins.
        $sectionId = (int) ($body['section_id'] ?? $_GET['section_id'] ?? 0);
        if ($sectionId === 0) {
            throw new HttpException(422, 'section_id is required.');
        }
        $secStmt = get_db()->prepare(
            'SELECT sec.id, sec.grade_level, sec.school_year_id, sy.year_label, sy.is_active AS year_active
             FROM sections sec JOIN school_years sy ON sy.id = sec.school_year_id WHERE sec.id = ? LIMIT 1'
        );
        $secStmt->execute([$sectionId]);
        $sec = $secStmt->fetch();
        if (!$sec) {
            throw new HttpException(404, 'Section not found.');
        }
        // grade_level + year_label are needed by EnrollmentService::evaluate() (grade continuity vs earlier school years);
        // without them, enrolling a learner who has an earlier-year enrollment failed with a server error.
        $section = ['id' => (int) $sec['id'], 'grade_level' => (int) $sec['grade_level'], 'school_year_id' => (int) $sec['school_year_id'],
                    'year_label' => (string) $sec['year_label'], 'year_active' => (bool) $sec['year_active']];

        if ($method === 'GET') {
            $q = (string) ($_GET['q'] ?? '');
            Json::ok(['results' => $q !== '' ? EnrollmentService::search($section, $q) : []], 'Search complete.');
        }
        $action = (string) ($body['action'] ?? '');
        if ($action === 'enroll') {
            Json::ok(EnrollmentService::enroll($section, (int) ($body['student_id'] ?? 0)), 'Student enrolled.');
        }
        if ($action === 'register') {
            Json::ok(EnrollmentService::register($section, $body), 'Student registered and enrolled.');
        }
        if ($action === 'register_many') {
            $r = EnrollmentService::registerMany($section, Batch::rows($body['rows'] ?? []));
            Json::ok($r, Batch::plural($r['added'], 'learner') . ' added' . ($r['failed'] ? ', ' . $r['failed'] . ' could not be added.' : '.'));
        }
        if ($action === 'enroll_many') {
            $r = EnrollmentService::enrollMany($section, Batch::ids($body['student_ids'] ?? []));
            Json::ok($r, Batch::plural($r['added'], 'learner') . ' enrolled' . ($r['failed'] ? ', ' . $r['failed'] . ' could not be enrolled.' : '.'));
        }
        throw new HttpException(422, 'Unknown student action.');

    case 'subjects':
        Json::ok(['subjects' => AdminService::assignableSubjects()], 'Subjects loaded.');

    default:
        throw new HttpException(404, 'Unknown admin resource.');
}
