<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../Auth/Auth.php';
require_once __DIR__ . '/../Http/Batch.php';

/**
 * Everything the Head/Administrator can do that a Teacher cannot:
 *  - create/edit/activate/deactivate teacher & admin accounts, reset passwords
 *  - create sections
 *  - assign / remove a subject teacher (MAPEH's two subjects — Music and
 *    Arts, Physical Education and Health — are assigned independently)
 *  - manage students system-wide (add/edit/remove) and view all grades/SF9
 *
 * Every public method here MUST be called only after Auth::requireAdmin() —
 * callers (public/api/admin.php) enforce that server-side on every request,
 * never relying on the UI hiding a button. This mirrors the pattern already
 * used by Auth::assertOwnsAssignment() for teacher grade permissions.
 */
final class AdminService
{
    public const ROLES = ['teacher', 'master_teacher', 'principal', 'admin'];

    /** Grade levels an Administrator can assign a teacher to (Teacher Assignment by Grade Level). */
    public const GRADE_LEVELS = [4, 5, 6];

    // ---------------------------------------------------------------
    // ACCOUNTS
    // ---------------------------------------------------------------

    public static function listAccounts(): array
    {
        $stmt = get_db()->query(
            'SELECT id, username, email, first_name, last_name, middle_name, role, assigned_grade_level, is_active,
                    must_change_password, created_at,
                    (SELECT COUNT(*) FROM teacher_assignments ta WHERE ta.teacher_id = users.id) AS assignment_count,
                    (SELECT COUNT(*) FROM sections sec WHERE sec.adviser_id = users.id) AS adviser_count
             FROM users ORDER BY role, last_name, first_name'
        );
        return array_map(static function (array $r): array {
            $r['id'] = (int) $r['id'];
            $r['assigned_grade_level'] = $r['assigned_grade_level'] !== null ? (int) $r['assigned_grade_level'] : null;
            $r['is_active'] = (bool) $r['is_active'];
            $r['must_change_password'] = (bool) $r['must_change_password'];
            $r['assignment_count'] = (int) $r['assignment_count'];
            $r['adviser_count'] = (int) $r['adviser_count'];
            $r['full_name'] = trim($r['last_name'] . ', ' . $r['first_name'] . ' ' . ($r['middle_name'] ?? ''));
            return $r;
        }, $stmt->fetchAll());
    }

    /**
     * Create a new account. Only ever reachable via Auth::requireAdmin() —
     * CRITICAL RULE from the spec: only the Administrator can create teacher
     * accounts, and this is enforced here (server-side), not just by hiding
     * the "Add Account" button in the UI.
     */
    public static function createAccount(array $in, int $actingUserId): array
    {
        $data = self::validateAccountInput($in, null);
        $pdo = get_db();

        $dup = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $dup->execute([$data['username']]);
        if ($dup->fetch()) {
            throw new HttpException(409, 'That username is already taken.', ['errors' => ['username' => 'Already taken.']]);
        }

        $hash = password_hash($data['password'], PASSWORD_DEFAULT);
        $ins = $pdo->prepare(
            'INSERT INTO users (username, email, password_hash, first_name, last_name, middle_name, role, is_active, must_change_password, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
        );
        $ins->execute([
            $data['username'], $data['email'], $hash, $data['first_name'], $data['last_name'],
            $data['middle_name'], $data['role'], $data['is_active'] ? 1 : 0, $actingUserId,
        ]);
        $newId = (int) $pdo->lastInsertId();

        self::audit($newId, 'created', $actingUserId, 'role=' . $data['role']);

        return ['user_id' => $newId, 'temporary_password' => $data['password']];
    }

    public static function updateAccount(int $userId, array $in, int $actingUserId): void
    {
        $existing = self::requireUser($userId);
        $data = self::validateAccountInput($in, $userId);
        $pdo = get_db();

        if ($userId === $actingUserId && $data['role'] !== 'admin') {
            throw new HttpException(422, 'You cannot remove your own Administrator role.');
        }

        $up = $pdo->prepare(
            'UPDATE users SET email = ?, first_name = ?, last_name = ?, middle_name = ?, role = ? WHERE id = ?'
        );
        $up->execute([$data['email'], $data['first_name'], $data['last_name'], $data['middle_name'], $data['role'], $userId]);

        // Only teaching roles carry a grade-level assignment; moving to principal/admin clears it.
        if (!in_array($data['role'], ['teacher', 'master_teacher'], true) && $existing['assigned_grade_level'] !== null) {
            get_db()->prepare('UPDATE users SET assigned_grade_level = NULL WHERE id = ?')->execute([$userId]);
            self::audit($userId, 'grade_unassigned', $actingUserId, 'role changed to ' . $data['role']);
        }

        if ($existing['role'] !== $data['role']) {
            self::audit($userId, 'role_changed', $actingUserId, $existing['role'] . ' -> ' . $data['role']);
        } else {
            self::audit($userId, 'edited', $actingUserId, '');
        }
    }

    public static function setActive(int $userId, bool $active, int $actingUserId): void
    {
        self::requireUser($userId);
        if ($userId === $actingUserId && !$active) {
            throw new HttpException(422, 'You cannot deactivate your own account.');
        }
        $stmt = get_db()->prepare('UPDATE users SET is_active = ? WHERE id = ?');
        $stmt->execute([$active ? 1 : 0, $userId]);
        self::audit($userId, $active ? 'activated' : 'deactivated', $actingUserId, '');
    }

    /**
     * Delete a user account (Admin Panel requirement 3.5). Blocked when the
     * account has related records (sections it advises, subject
     * assignments, grades it entered, audit history, accounts it created,
     * etc.) — those foreign keys are RESTRICT by design so deleting never
     * silently orphans or corrupts data. In that case, deactivating the
     * account is the safe alternative.
     */
    public static function deleteAccount(int $userId, int $actingUserId): void
    {
        self::requireUser($userId);
        if ($userId === $actingUserId) {
            throw new HttpException(422, 'You cannot delete your own account.');
        }
        $pdo = get_db();
        try {
            $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
            $stmt->execute([$userId]);
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1451) {
                throw new HttpException(
                    409,
                    'This account cannot be deleted because it has related records ' .
                    '(sections, subject assignments, grades, or audit history). Deactivate the account instead.'
                );
            }
            throw $e;
        }
    }

    /** Admin sets/resets a password directly. Always hashed; the account must change it at next login. */
    public static function resetPassword(int $userId, string $newPassword, int $actingUserId): void
    {
        self::requireUser($userId);
        self::validatePassword($newPassword);
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = get_db()->prepare('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?');
        $stmt->execute([$hash, $userId]);
        self::audit($userId, 'password_reset', $actingUserId, '');
    }

    public static function requireUser(int $userId): array
    {
        $stmt = get_db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $u = $stmt->fetch();
        if (!$u) {
            throw new HttpException(404, 'Account not found.');
        }
        return $u;
    }

    private static function validateAccountInput(array $in, ?int $editingUserId): array
    {
        $username = trim((string) ($in['username'] ?? ''));
        $email    = trim((string) ($in['email'] ?? ''));
        $first    = trim((string) ($in['first_name'] ?? ''));
        $last     = trim((string) ($in['last_name'] ?? ''));
        $middle   = trim((string) ($in['middle_name'] ?? ''));
        $role     = (string) ($in['role'] ?? 'teacher');
        $active   = (bool) ($in['is_active'] ?? true);
        $password = (string) ($in['password'] ?? '');

        $errors = [];
        if ($editingUserId === null) {
            if (!preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $username)) {
                $errors['username'] = 'Username must be 3–50 characters (letters, numbers, dot, underscore, dash).';
            }
            self::validatePassword($password, $errors);
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if ($first === '' || mb_strlen($first) > 100) {
            $errors['first_name'] = 'First name is required (max 100 characters).';
        }
        if ($last === '' || mb_strlen($last) > 100) {
            $errors['last_name'] = 'Last name is required (max 100 characters).';
        }
        if (!in_array($role, self::ROLES, true)) {
            $errors['role'] = 'Select a valid role.';
        }
        if ($errors) {
            throw new HttpException(422, 'Please correct the highlighted fields.', ['errors' => $errors]);
        }

        return [
            'username' => $username, 'email' => $email !== '' ? $email : null,
            'first_name' => $first, 'last_name' => $last, 'middle_name' => $middle !== '' ? $middle : null,
            'role' => $role, 'is_active' => $active, 'password' => $password,
        ];
    }

    public static function validatePassword(string $password, array &$errors = []): void
    {
        if (mb_strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters.';
        }
        if (isset($errors['password'])) {
            throw new HttpException(422, 'Please correct the highlighted fields.', ['errors' => $errors]);
        }
    }

    private static function audit(int $targetUserId, string $action, int $actingUserId, string $details): void
    {
        $stmt = get_db()->prepare(
            'INSERT INTO account_audit_log (target_user_id, action, performed_by, details) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$targetUserId, $action, $actingUserId, $details]);
    }

    // ---------------------------------------------------------------
    // SECTIONS
    // ---------------------------------------------------------------

    public static function listSections(): array
    {
        $stmt = get_db()->query(
            "SELECT sec.id, sec.grade_level, sec.section_name, sec.track_strand, sec.school_year_id,
                    sy.year_label, sy.is_active AS year_active,
                    adv.first_name AS adviser_first, adv.last_name AS adviser_last,
                    (SELECT COUNT(*) FROM enrollments e WHERE e.section_id = sec.id AND e.school_year_id = sec.school_year_id AND e.status = 'active') AS student_count
             FROM sections sec
             JOIN school_years sy ON sy.id = sec.school_year_id
             LEFT JOIN users adv ON adv.id = sec.adviser_id
             ORDER BY sy.is_active DESC, sy.year_label DESC, sec.grade_level, sec.section_name"
        );
        return array_map(static function (array $r): array {
            $r['id'] = (int) $r['id'];
            $r['grade_level'] = (int) $r['grade_level'];
            $r['school_year_id'] = (int) $r['school_year_id'];
            $r['year_active'] = (bool) $r['year_active'];
            $r['student_count'] = (int) $r['student_count'];
            $r['adviser_name'] = trim(($r['adviser_first'] ?? '') . ' ' . ($r['adviser_last'] ?? ''));
            return $r;
        }, $stmt->fetchAll());
    }

    /** Create a section. Callable by an Admin, or by a teacher via My Classes -> Create Section (that teacher becomes adviser). */
    public static function createSection(array $in, int $actingUserId): array
    {
        $grade   = (int) ($in['grade_level'] ?? 0);
        $name    = trim((string) ($in['section_name'] ?? ''));
        $track   = trim((string) ($in['track_strand'] ?? ''));
        $yearId  = (int) ($in['school_year_id'] ?? 0);
        $adviserId = isset($in['adviser_id']) && $in['adviser_id'] !== '' ? (int) $in['adviser_id'] : $actingUserId;

        $errors = [];
        if ($grade < 1 || $grade > 12) $errors['grade_level'] = 'Enter a grade level from 1 to 12.';
        if ($name === '' || mb_strlen($name) > 50) $errors['section_name'] = 'Section name is required (max 50 characters).';
        if ($errors) {
            throw new HttpException(422, 'Please correct the highlighted fields.', ['errors' => $errors]);
        }

        $pdo = get_db();
        if ($yearId === 0) {
            $yearId = (int) $pdo->query('SELECT id FROM school_years WHERE is_active = 1 ORDER BY id DESC LIMIT 1')->fetchColumn();
        }
        if ($yearId === 0) {
            throw new HttpException(422, 'No active school year exists. Ask the Administrator to create one first.');
        }

        // Advisers must be teaching staff — administrators/principals never advise a section.
        $adv = $pdo->prepare("SELECT id FROM users WHERE id = ? AND is_active = 1 AND role IN ('teacher','master_teacher') LIMIT 1");
        $adv->execute([$adviserId]);
        if (!$adv->fetch()) {
            throw new HttpException(422, 'Select an active Teacher or Master Teacher as the adviser.');
        }

        self::assertGradeMatches($adviserId, $grade, $adviserId === $actingUserId ? 'create' : 'advise');

        $dup = $pdo->prepare('SELECT id FROM sections WHERE grade_level = ? AND section_name = ? AND school_year_id = ? LIMIT 1');
        $dup->execute([$grade, $name, $yearId]);
        if ($dup->fetch()) {
            throw new HttpException(409, 'A section with that grade level and name already exists for this school year.');
        }

        $ins = $pdo->prepare(
            'INSERT INTO sections (grade_level, section_name, track_strand, school_year_id, adviser_id, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([$grade, $name, $track !== '' ? $track : null, $yearId, $adviserId, $actingUserId]);
        return ['section_id' => (int) $pdo->lastInsertId()];
    }

    /**
     * Delete a section (Admin Panel requirement 3.3). Blocked when the
     * section has enrolled students or any recorded grades/attendance —
     * deleting those silently would corrupt historical records. Subject
     * teacher assignments (assignment-only rows, no downstream data) are
     * safe to clear automatically.
     */
    public static function deleteSection(int $sectionId): array
    {
        $pdo = get_db();
        $sec = $pdo->prepare('SELECT id, grade_level, section_name FROM sections WHERE id = ? LIMIT 1');
        $sec->execute([$sectionId]);
        $section = $sec->fetch();
        if (!$section) {
            throw new HttpException(404, 'Section not found.');
        }

        $enr = $pdo->prepare('SELECT COUNT(*) FROM enrollments WHERE section_id = ?');
        $enr->execute([$sectionId]);
        $enrollmentCount = (int) $enr->fetchColumn();

        $grd = $pdo->prepare('SELECT COUNT(*) FROM grades WHERE section_id = ?');
        $grd->execute([$sectionId]);
        $gradeCount = (int) $grd->fetchColumn();

        if ($enrollmentCount > 0 || $gradeCount > 0) {
            throw new HttpException(
                409,
                'Grade ' . $section['grade_level'] . ' – ' . $section['section_name'] . ' has ' .
                $enrollmentCount . ' enrollment record(s) and ' . $gradeCount . ' grade record(s), so it cannot be deleted. ' .
                'Remove/transfer the students first if this section truly needs to go away.'
            );
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM teacher_assignments WHERE section_id = ?')->execute([$sectionId]);
            $pdo->prepare('DELETE FROM sections WHERE id = ?')->execute([$sectionId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return ['deleted' => true];
    }

    // ---------------------------------------------------------------
    // MULTIPLE RECORDS (multi-select Delete / multi Add)
    // Each method loops over the single-record method above, so every rule (own-account guard, related-data
    // block, validation, duplicate checks) is exactly the one-by-one rule. A failure never affects the others.
    // ---------------------------------------------------------------

    /** @param int[] $ids @return array{deleted:array<int,array{id:int,label:string}>,failed:array<int,array{id:int,label:string,message:string}>} */
    public static function deleteAccounts(array $ids, int $actingUserId): array
    {
        $deleted = [];
        $failed  = [];
        foreach ($ids as $id) {
            $id    = (int) $id;
            $label = self::accountLabel($id);
            try {
                self::deleteAccount($id, $actingUserId);
                $deleted[] = ['id' => $id, 'label' => $label];
            } catch (Throwable $e) {
                $failed[] = ['id' => $id, 'label' => $label, 'message' => Batch::failure($e, 'This account could not be deleted.')['message']];
            }
        }
        return ['deleted' => $deleted, 'failed' => $failed];
    }

    /** @param int[] $ids @return array{deleted:array<int,array{id:int,label:string}>,failed:array<int,array{id:int,label:string,message:string}>} */
    public static function deleteSections(array $ids): array
    {
        $deleted = [];
        $failed  = [];
        foreach ($ids as $id) {
            $id    = (int) $id;
            $label = self::sectionLabel($id);
            try {
                self::deleteSection($id);
                $deleted[] = ['id' => $id, 'label' => $label];
            } catch (Throwable $e) {
                $failed[] = ['id' => $id, 'label' => $label, 'message' => Batch::failure($e, 'This section could not be deleted.')['message']];
            }
        }
        return ['deleted' => $deleted, 'failed' => $failed];
    }

    /**
     * Add several accounts. @param array<int,array<string,mixed>> $rows
     * @return array{added:int,failed:int,results:array<int,array<string,mixed>>}
     */
    public static function createAccounts(array $rows, int $actingUserId): array
    {
        $results = [];
        $seen    = [];
        foreach (array_values($rows) as $i => $row) {
            $name = trim((string) ($row['username'] ?? ''));
            $key  = strtolower($name);
            if ($key !== '' && isset($seen[$key])) {
                $results[] = ['index' => $i, 'ok' => false, 'label' => $name, 'message' => 'This username appears more than once in this list.', 'errors' => ['username' => 'Duplicate in this list.']];
                continue;
            }
            try {
                $r = self::createAccount($row, $actingUserId);
                $seen[$key] = true;
                $results[] = ['index' => $i, 'ok' => true, 'label' => $name, 'user_id' => $r['user_id'], 'temporary_password' => $r['temporary_password'], 'message' => 'Account created.'];
            } catch (Throwable $e) {
                $f = Batch::failure($e, 'This account could not be created.');
                $results[] = ['index' => $i, 'ok' => false, 'label' => $name, 'message' => $f['message'], 'errors' => $f['errors']];
            }
        }
        return self::tally($results);
    }

    /**
     * Add several sections for the active school year. @param array<int,array<string,mixed>> $rows
     * Rows that repeat an earlier row (same grade + name) fail on the same duplicate check as a single Add.
     * @return array{added:int,failed:int,results:array<int,array<string,mixed>>}
     */
    public static function createSections(array $rows, int $actingUserId): array
    {
        $results = [];
        foreach (array_values($rows) as $i => $row) {
            $label = 'Grade ' . (int) ($row['grade_level'] ?? 0) . ' – ' . trim((string) ($row['section_name'] ?? ''));
            try {
                $r = self::createSection($row, $actingUserId);
                $results[] = ['index' => $i, 'ok' => true, 'label' => $label, 'section_id' => $r['section_id'], 'message' => 'Section created.'];
            } catch (Throwable $e) {
                $f = Batch::failure($e, 'This section could not be created.');
                $results[] = ['index' => $i, 'ok' => false, 'label' => $label, 'message' => $f['message'], 'errors' => $f['errors']];
            }
        }
        return self::tally($results);
    }

    /** @param array<int,array<string,mixed>> $results */
    private static function tally(array $results): array
    {
        $added = count(array_filter($results, static fn(array $r): bool => $r['ok']));
        return ['added' => $added, 'failed' => count($results) - $added, 'results' => $results];
    }

    private static function accountLabel(int $id): string
    {
        $stmt = get_db()->prepare('SELECT first_name, last_name, username FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $u = $stmt->fetch();
        return $u ? trim($u['first_name'] . ' ' . $u['last_name']) . ' (' . $u['username'] . ')' : 'Account #' . $id;
    }

    private static function sectionLabel(int $id): string
    {
        $stmt = get_db()->prepare('SELECT sec.grade_level, sec.section_name, sy.year_label FROM sections sec JOIN school_years sy ON sy.id = sec.school_year_id WHERE sec.id = ? LIMIT 1');
        $stmt->execute([$id]);
        $s = $stmt->fetch();
        return $s ? 'Grade ' . $s['grade_level'] . ' – ' . $s['section_name'] . ' (' . $s['year_label'] . ')' : 'Section #' . $id;
    }

    // ---------------------------------------------------------------
    // SCHOOL YEARS (Admin Panel requirement 3.4)
    // ---------------------------------------------------------------

    public static function listSchoolYears(): array
    {
        $stmt = get_db()->query('SELECT id, year_label, is_active FROM school_years ORDER BY year_label DESC');
        return array_map(static fn(array $r) => [
            'id' => (int) $r['id'], 'year_label' => $r['year_label'], 'is_active' => (bool) $r['is_active'],
        ], $stmt->fetchAll());
    }

    public static function createSchoolYear(string $label, bool $makeActive): array
    {
        $label = trim($label);
        if (!preg_match('/^\d{4}-\d{4}$/', $label)) {
            throw new HttpException(422, 'Enter a school year like 2026-2027.', ['errors' => ['year_label' => 'Use the format YYYY-YYYY.']]);
        }
        $pdo = get_db();
        $dup = $pdo->prepare('SELECT id FROM school_years WHERE year_label = ? LIMIT 1');
        $dup->execute([$label]);
        if ($dup->fetch()) {
            throw new HttpException(409, 'That school year already exists.');
        }
        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare('INSERT INTO school_years (year_label, is_active) VALUES (?, 0)');
            $ins->execute([$label]);
            $id = (int) $pdo->lastInsertId();
            if ($makeActive) {
                $pdo->exec('UPDATE school_years SET is_active = 0');
                $pdo->prepare('UPDATE school_years SET is_active = 1 WHERE id = ?')->execute([$id]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return ['school_year_id' => $id];
    }

    /** Exactly one school year is active at a time — every section/record is scoped to it. */
    public static function setActiveSchoolYear(int $id): void
    {
        $pdo = get_db();
        $chk = $pdo->prepare('SELECT id FROM school_years WHERE id = ? LIMIT 1');
        $chk->execute([$id]);
        if (!$chk->fetch()) {
            throw new HttpException(404, 'School year not found.');
        }
        $pdo->beginTransaction();
        try {
            $pdo->exec('UPDATE school_years SET is_active = 0');
            $pdo->prepare('UPDATE school_years SET is_active = 1 WHERE id = ?')->execute([$id]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // ---------------------------------------------------------------
    // STUDENT DIRECTORY (Admin Panel requirement 4.1 — view grades
    // directly: Admin Panel -> Students -> Select Student -> View Grades,
    // without navigating through Sections first)
    // ---------------------------------------------------------------

    /** @return array<int,array<string,mixed>> students with their current section, for a direct "View Grades" link. */
    public static function studentsDirectory(string $q, ?int $gradeLevel): array
    {
        $pdo   = get_db();
        $esc   = static fn(string $t): string => addcslashes($t, '%_\\');
        $where = ['1=1'];
        $args  = [];

        $q = trim(preg_replace('/\s+/', ' ', $q) ?? '');
        if ($q !== '') {
            if (preg_match('/^\d+$/', $q)) {
                $where[] = 's.lrn LIKE ?';
                $args[]  = $esc($q) . '%';
            } else {
                foreach (array_slice(explode(' ', $q), 0, 4) as $tok) {
                    $where[] = '(s.last_name LIKE ? OR s.first_name LIKE ? OR s.middle_name LIKE ?)';
                    $like = '%' . $esc($tok) . '%';
                    array_push($args, $like, $like, $like);
                }
            }
        }
        if ($gradeLevel !== null) {
            $where[] = 'sec.grade_level = ?';
            $args[]  = $gradeLevel;
        }

        $stmt = $pdo->prepare(
            'SELECT s.id AS student_id, s.lrn, s.last_name, s.first_name, s.middle_name,
                    sec.id AS section_id, sec.grade_level, sec.section_name, sy.year_label, sy.is_active AS year_active
             FROM students s
             JOIN enrollments e ON e.student_id = s.id
             JOIN sections sec ON sec.id = e.section_id
             JOIN school_years sy ON sy.id = e.school_year_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY sy.is_active DESC, s.last_name, s.first_name LIMIT 50'
        );
        $stmt->execute($args);

        // One row per student: prefer their active-school-year enrollment when there are several.
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $sid = (int) $r['student_id'];
            if (isset($out[$sid]) && $out[$sid]['year_active']) {
                continue;
            }
            $out[$sid] = [
                'student_id'  => $sid,
                'name'        => trim($r['last_name'] . ', ' . $r['first_name'] . ' ' . ($r['middle_name'] ?? '')),
                'lrn'         => $r['lrn'],
                'section_id'  => (int) $r['section_id'],
                'grade_level' => (int) $r['grade_level'],
                'section'     => 'Grade ' . $r['grade_level'] . ' – ' . $r['section_name'],
                'year_label'  => $r['year_label'],
                'year_active' => (bool) $r['year_active'],
            ];
        }
        return array_values($out);
    }

    // ---------------------------------------------------------------
    // SUBJECTS / TEACHER ASSIGNMENTS
    // ---------------------------------------------------------------

    /** All gradable subjects, individually assignable (including both MAPEH subjects). */
    public static function assignableSubjects(): array
    {
        $stmt = get_db()->query(
            "SELECT id, subject_name, is_group FROM subjects WHERE is_active = 1 AND parent_group IS NULL ORDER BY sort_order"
        );
        return array_map(static fn(array $r) => ['id' => (int) $r['id'], 'subject_name' => $r['subject_name'], 'is_group' => (bool) $r['is_group']], $stmt->fetchAll());
    }

    /** Subject -> assigned teacher, for one section/year (Priority 9's "Section -> Subject -> Assign/Remove Teacher"). */
    public static function assignmentsForSection(int $sectionId, int $yearId): array
    {
        $subjects = self::assignableSubjects();
        $stmt = get_db()->prepare(
            'SELECT ta.id AS assignment_id, ta.subject_id, ta.teacher_id, u.first_name, u.last_name
             FROM teacher_assignments ta JOIN users u ON u.id = ta.teacher_id
             WHERE ta.section_id = ? AND ta.school_year_id = ?'
        );
        $stmt->execute([$sectionId, $yearId]);
        $bySubject = [];
        foreach ($stmt->fetchAll() as $r) {
            $bySubject[(int) $r['subject_id']] = [
                'assignment_id' => (int) $r['assignment_id'],
                'teacher_id' => (int) $r['teacher_id'],
                'teacher_name' => trim($r['last_name'] . ', ' . $r['first_name']),
            ];
        }
        foreach ($subjects as &$s) {
            $s['assignment'] = $bySubject[$s['id']] ?? null;
        }
        return $subjects;
    }

    /**
     * Assign a teacher to a subject in a section/year. Because
     * teacher_assignments has a UNIQUE (section_id, subject_id, school_year_id)
     * key, this REPLACES any existing teacher for that subject (Priority 9:
     * assign/remove) rather than allowing a second one.
     */
    public static function assignSubjectTeacher(int $sectionId, int $subjectId, int $teacherId, int $yearId, int $actingUserId): array
    {
        $pdo = get_db();

        $sub = $pdo->prepare('SELECT id, subject_name FROM subjects WHERE id = ? AND is_active = 1 LIMIT 1');
        $sub->execute([$subjectId]);
        $subject = $sub->fetch();
        if (!$subject) {
            throw new HttpException(404, 'Subject not found.');
        }

        $teacher = $pdo->prepare("SELECT id FROM users WHERE id = ? AND is_active = 1 AND role IN ('teacher','master_teacher') LIMIT 1");
        $teacher->execute([$teacherId]);
        if (!$teacher->fetch()) {
            throw new HttpException(422, 'That account is not an active teacher, so it cannot be assigned a subject.');
        }

        $secRow = $pdo->prepare('SELECT grade_level FROM sections WHERE id = ? AND school_year_id = ? LIMIT 1');
        $secRow->execute([$sectionId, $yearId]);
        $sectionGrade = $secRow->fetchColumn();
        if ($sectionGrade === false) {
            throw new HttpException(404, 'Section not found.');
        }
        self::assertGradeMatches($teacherId, (int) $sectionGrade, 'teach');

        $pdo->beginTransaction();
        try {
            $del = $pdo->prepare('DELETE FROM teacher_assignments WHERE section_id = ? AND subject_id = ? AND school_year_id = ?');
            $del->execute([$sectionId, $subjectId, $yearId]);

            $ins = $pdo->prepare(
                'INSERT INTO teacher_assignments (teacher_id, section_id, subject_id, school_year_id, assigned_by) VALUES (?, ?, ?, ?, ?)'
            );
            $ins->execute([$teacherId, $sectionId, $subjectId, $yearId, $actingUserId]);
            $id = (int) $pdo->lastInsertId();
            $pdo->commit();
            return ['assignment_id' => $id, 'subject_name' => $subject['subject_name']];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param int|null $onlySectionId when given, the assignment must belong to that section (adviser-scoped removals). */
    public static function removeSubjectTeacher(int $assignmentId, ?int $onlySectionId = null): void
    {
        if ($onlySectionId !== null) {
            $stmt = get_db()->prepare('DELETE FROM teacher_assignments WHERE id = ? AND section_id = ?');
            $stmt->execute([$assignmentId, $onlySectionId]);
        } else {
            $stmt = get_db()->prepare('DELETE FROM teacher_assignments WHERE id = ?');
            $stmt->execute([$assignmentId]);
        }
        if ($stmt->rowCount() === 0) {
            throw new HttpException(404, 'Assignment not found.');
        }
    }

    /** All teacher/master_teacher accounts (administrators/principals are never assignable) eligible to be assigned a subject. */
    public static function assignableTeachers(): array
    {
        $stmt = get_db()->query(
            "SELECT id, first_name, last_name FROM users
             WHERE is_active = 1 AND role IN ('teacher','master_teacher')
             ORDER BY last_name, first_name"
        );
        return array_map(static fn(array $r) => ['id' => (int) $r['id'], 'name' => trim($r['last_name'] . ', ' . $r['first_name'])], $stmt->fetchAll());
    }

    // ---------------------------------------------------------------
    // TEACHER ASSIGNMENT BY GRADE LEVEL (Admin only — callers use Auth::requireAdmin())
    // ---------------------------------------------------------------

    /**
     * Refuse when a teacher who is assigned to one grade level is being put into a section of a
     * different grade (as adviser or subject teacher). Teachers with no assigned grade are unrestricted.
     */
    private static function assertGradeMatches(int $teacherId, int $sectionGrade, string $verb): void
    {
        $assigned = Auth::assignedGradeLevel($teacherId);
        if ($assigned === null || $assigned === $sectionGrade) {
            return;
        }
        $who = $verb === 'create' ? 'You are' : 'That teacher is';
        $msg = [
            'create' => 'so you can only create Grade ' . $assigned . ' sections.',
            'advise' => 'so they cannot advise a Grade ' . $sectionGrade . ' section.',
            'teach'  => 'so they cannot be assigned a subject in a Grade ' . $sectionGrade . ' section.',
        ][$verb] ?? 'so this is not allowed for a Grade ' . $sectionGrade . ' section.';
        throw new HttpException(422, $who . ' assigned to Grade ' . $assigned . ', ' . $msg);
    }

    /** Registered teachers (Teacher / Master Teacher accounts) with their assigned grade level and workload. */
    public static function teachersWithGrades(): array
    {
        $stmt = get_db()->query(
            "SELECT u.id, u.username, u.first_name, u.last_name, u.middle_name, u.role, u.is_active, u.assigned_grade_level,
                    (SELECT COUNT(*) FROM sections sec WHERE sec.adviser_id = u.id) AS adviser_count,
                    (SELECT COUNT(*) FROM teacher_assignments ta WHERE ta.teacher_id = u.id) AS assignment_count
             FROM users u
             WHERE u.role IN ('teacher','master_teacher')
             ORDER BY u.is_active DESC, u.last_name, u.first_name"
        );
        return array_map(static function (array $r): array {
            return [
                'id'                   => (int) $r['id'],
                'username'             => $r['username'],
                'name'                 => trim($r['last_name'] . ', ' . $r['first_name'] . ' ' . ($r['middle_name'] ?? '')),
                'role'                 => $r['role'],
                'is_active'            => (bool) $r['is_active'],
                'assigned_grade_level' => $r['assigned_grade_level'] !== null ? (int) $r['assigned_grade_level'] : null,
                'adviser_count'        => (int) $r['adviser_count'],
                'assignment_count'     => (int) $r['assignment_count'],
            ];
        }, $stmt->fetchAll());
    }

    /**
     * Assign a teacher to Grade 4/5/6, change the assignment, or remove it ($grade = null).
     *
     * A teacher may already advise sections or teach subjects in a different grade. Moving them
     * would silently hide those sections from them, so the change is refused (409, code
     * "grade_conflict") until the Admin confirms it explicitly ($confirmConflicts = true).
     * Nothing about those sections/assignments is deleted — they simply stay under the Admin's control.
     */
    public static function assignGradeLevel(int $teacherId, ?int $grade, int $actingUserId, bool $confirmConflicts = false): array
    {
        $teacher = self::requireUser($teacherId);
        if (!in_array($teacher['role'], ['teacher', 'master_teacher'], true)) {
            throw new HttpException(422, 'Only Teacher or Master Teacher accounts can be assigned to a grade level.');
        }
        if ($grade !== null && !in_array($grade, self::GRADE_LEVELS, true)) {
            throw new HttpException(422, 'Select Grade 4, Grade 5 or Grade 6.', ['errors' => ['grade_level' => 'Select Grade 4, 5 or 6.']]);
        }

        $before = $teacher['assigned_grade_level'] !== null ? (int) $teacher['assigned_grade_level'] : null;
        if ($before === $grade) {
            return ['assigned_grade_level' => $grade, 'changed' => false];
        }

        $pdo = get_db();
        if ($grade !== null) {
            $adv = $pdo->prepare('SELECT COUNT(*) FROM sections WHERE adviser_id = ? AND grade_level <> ?');
            $adv->execute([$teacherId, $grade]);
            $asg = $pdo->prepare(
                'SELECT COUNT(*) FROM teacher_assignments ta JOIN sections sec ON sec.id = ta.section_id
                 WHERE ta.teacher_id = ? AND sec.grade_level <> ?'
            );
            $asg->execute([$teacherId, $grade]);
            $advisedElsewhere  = (int) $adv->fetchColumn();
            $teachingElsewhere = (int) $asg->fetchColumn();

            if (($advisedElsewhere > 0 || $teachingElsewhere > 0) && !$confirmConflicts) {
                $parts = [];
                if ($advisedElsewhere > 0)  $parts[] = 'advises ' . $advisedElsewhere . ' section(s)';
                if ($teachingElsewhere > 0) $parts[] = 'has ' . $teachingElsewhere . ' subject assignment(s)';
                throw new HttpException(409,
                    $teacher['first_name'] . ' ' . $teacher['last_name'] . ' currently ' . implode(' and ', $parts) .
                    ' outside Grade ' . $grade . '. Those sections will no longer be visible to this teacher (nothing is deleted).',
                    ['code' => 'grade_conflict', 'conflicts' => ['sections_advised' => $advisedElsewhere, 'subject_assignments' => $teachingElsewhere]]
                );
            }
        }

        $stmt = $pdo->prepare('UPDATE users SET assigned_grade_level = ? WHERE id = ?');
        $stmt->execute([$grade, $teacherId]);

        $was = $before !== null ? 'Grade ' . $before : 'none';
        if ($grade === null) {
            self::audit($teacherId, 'grade_unassigned', $actingUserId, 'was ' . $was);
        } else {
            self::audit($teacherId, 'grade_assigned', $actingUserId, 'Grade ' . $grade . ' (was ' . $was . ')');
        }
        return ['assigned_grade_level' => $grade, 'changed' => true];
    }
}
