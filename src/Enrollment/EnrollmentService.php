<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../Http/Batch.php';

/**
 * Enrollment rules (reuses the existing students / enrollments tables — no new tables):
 *  - a student has at most ONE enrollment per school year (DB unique key + checked here);
 *  - enrolling into a section of the ACTIVE school year only;
 *  - LRN must be 12 digits;
 *  - grade level: if the learner was enrolled in an earlier school year at Grade G, the
 *    target section must be Grade G (retained) or G+1 (promoted). Learners with no
 *    earlier record (new entrants) may be placed in any grade of the school.
 *  - a learner already in another section this year is NOT moved silently.
 *
 * PDO runs with native prepares: no repeated :named placeholders, no bound LIMITs.
 */
final class EnrollmentService
{
    public static function validLrn(string $lrn): bool
    {
        return (bool) preg_match('/^\d{12}$/', $lrn);
    }

    /** Students matching a name or LRN fragment, each with an eligibility verdict for $section. */
    public static function search(array $section, string $query): array
    {
        $query = trim(preg_replace('/\s+/', ' ', $query) ?? '');
        if (mb_strlen($query) < 2) {
            throw new HttpException(422, 'Type at least 2 characters of a name or LRN.');
        }
        $esc   = static fn(string $t): string => addcslashes($t, '%_\\');
        $where = [];
        $args  = [];

        if (preg_match('/^\d+$/', $query)) {
            $where[] = 's.lrn LIKE ?';
            $args[]  = $esc($query) . '%';
        } else {
            foreach (array_slice(explode(' ', $query), 0, 4) as $tok) {
                $where[] = '(s.last_name LIKE ? OR s.first_name LIKE ? OR s.middle_name LIKE ?)';
                $like = '%' . $esc($tok) . '%';
                array_push($args, $like, $like, $like);
            }
        }

        $stmt = get_db()->prepare(
            'SELECT s.id, s.lrn, s.last_name, s.first_name, s.middle_name
             FROM students s WHERE ' . implode(' AND ', $where) . '
             ORDER BY s.last_name, s.first_name LIMIT 25'
        );
        $stmt->execute($args);
        $students = $stmt->fetchAll();
        if (!$students) {
            return [];
        }

        $byStudent = self::enrollmentsFor(array_map('intval', array_column($students, 'id')));
        $out = [];
        foreach ($students as $s) {
            $verdict = self::evaluate($s, $byStudent[(int) $s['id']] ?? [], $section);
            $out[] = [
                'student_id'  => (int) $s['id'],
                'name'        => trim($s['last_name'] . ', ' . $s['first_name'] . ' ' . ($s['middle_name'] ?? '')),
                'lrn'         => $s['lrn'],
                'grade_level' => $verdict['place']['grade_level'] ?? null,
                'section'     => $verdict['place']['label'] ?? null,
                'can_enroll'  => $verdict['can'],
                'code'        => $verdict['code'],
                'reason'      => $verdict['reason'],
            ];
        }
        return $out;
    }

    /** @return array<int,array<int,array<string,mixed>>> student_id => enrollments (newest year first) */
    private static function enrollmentsFor(array $studentIds, bool $lock = false): array
    {
        if (!$studentIds) {
            return [];
        }
        $in   = implode(',', array_fill(0, count($studentIds), '?'));
        $stmt = get_db()->prepare(
            "SELECT e.id, e.student_id, e.status, sec.id AS section_id, sec.grade_level, sec.section_name,
                    sy.id AS year_id, sy.year_label
             FROM enrollments e
             JOIN sections sec ON sec.id = e.section_id
             JOIN school_years sy ON sy.id = e.school_year_id
             WHERE e.student_id IN ($in)
             ORDER BY sy.year_label DESC" . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute($studentIds);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['student_id']][] = $r;
        }
        return $out;
    }

    /**
     * @param array $student     row with lrn
     * @param array $enrollments that student's enrollments (any year)
     * @return array{can:bool,code:string,reason:string,place:?array,reactivate_id:?int}
     */
    public static function evaluate(array $student, array $enrollments, array $section): array
    {
        $label = static fn(array $e): string => 'Grade ' . $e['grade_level'] . '-' . $e['section_name'];

        $current = null;
        $last    = null;
        foreach ($enrollments as $e) {
            if ((int) $e['year_id'] === (int) $section['school_year_id']) {
                $current = $e;
            } elseif (strcmp((string) $e['year_label'], (string) $section['year_label']) < 0 && $last === null) {
                $last = $e;   // list is newest-first, so the first earlier one is the latest
            }
        }
        $place = $current ?: $last;
        $placeInfo = $place ? ['grade_level' => (int) $place['grade_level'], 'label' => $label($place) . ($current ? '' : ' (' . $place['year_label'] . ')')] : null;
        $res = static fn(bool $can, string $code, string $reason, ?int $rid = null): array =>
            ['can' => $can, 'code' => $code, 'reason' => $reason, 'place' => $placeInfo, 'reactivate_id' => $rid];

        if (!self::validLrn((string) ($student['lrn'] ?? ''))) {
            return $res(false, 'bad_lrn', 'This learner has a missing or invalid LRN (must be 12 digits). Correct the record first.');
        }
        if ($current) {
            if ((int) $current['section_id'] === (int) $section['id']) {
                return $current['status'] === 'active'
                    ? $res(false, 'already', 'Already enrolled in this section.')
                    : $res(true, 'reactivate', 'Previously ' . str_replace('_', ' ', $current['status']) . ' — will be re-activated.', (int) $current['id']);
            }
            return $res(false, 'other_section', 'Already enrolled this school year in ' . $label($current) . ' (' . str_replace('_', ' ', $current['status']) . ').');
        }
        if ($last) {
            $g = (int) $last['grade_level'];
            $t = (int) $section['grade_level'];
            if ($t !== $g && $t !== $g + 1) {
                return $res(false, 'wrong_grade', 'Last enrolled in Grade ' . $g . ' (' . $last['year_label'] . '); cannot be placed in Grade ' . $t . '.');
            }
        }
        return $res(true, 'ok', '');
    }

    /** Enroll an existing student. Returns ['status' => 'enrolled'|'reactivated']. */
    public static function enroll(array $section, int $studentId): array
    {
        self::assertOpen($section);
        $pdo = get_db();
        $pdo->beginTransaction();
        try {
            // Serialize enrollments per section so roster numbers stay unique.
            $lock = $pdo->prepare('SELECT id FROM sections WHERE id = ? FOR UPDATE');
            $lock->execute([(int) $section['id']]);

            $st = $pdo->prepare('SELECT id, lrn FROM students WHERE id = ? LIMIT 1');
            $st->execute([$studentId]);
            $student = $st->fetch();
            if (!$student) {
                throw new HttpException(404, 'Student not found.');
            }

            $verdict = self::evaluate($student, self::enrollmentsFor([$studentId], true)[$studentId] ?? [], $section);
            if (!$verdict['can']) {
                throw new HttpException(409, $verdict['reason'], ['code' => $verdict['code']]);
            }

            if ($verdict['reactivate_id']) {
                $up = $pdo->prepare("UPDATE enrollments SET status = 'active' WHERE id = ?");
                $up->execute([$verdict['reactivate_id']]);
                $result = 'reactivated';
            } else {
                self::insertEnrollment($pdo, $studentId, $section);
                $result = 'enrolled';
            }
            $pdo->commit();
            return ['status' => $result];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw self::friendly($e);
        }
    }

    /** Create a brand-new learner (unique LRN) and enroll them in one transaction. */
    public static function register(array $section, array $in): array
    {
        self::assertOpen($section);

        $lrn    = trim((string) ($in['lrn'] ?? ''));
        $last   = trim((string) ($in['last_name'] ?? ''));
        $first  = trim((string) ($in['first_name'] ?? ''));
        $middle = trim((string) ($in['middle_name'] ?? ''));
        $gender = (string) ($in['gender'] ?? '');
        $birth  = trim((string) ($in['birthdate'] ?? ''));

        $errors = [];
        if (!self::validLrn($lrn))                          $errors['lrn'] = 'LRN must be exactly 12 digits.';
        if ($last === '' || mb_strlen($last) > 100)         $errors['last_name'] = 'Last name is required (max 100 characters).';
        if ($first === '' || mb_strlen($first) > 100)       $errors['first_name'] = 'First name is required (max 100 characters).';
        if (mb_strlen($middle) > 100)                       $errors['middle_name'] = 'Middle name is too long.';
        if (!in_array($gender, ['Male', 'Female'], true))   $errors['gender'] = 'Select Male or Female.';
        if ($birth !== '') {
            $d = DateTime::createFromFormat('Y-m-d', $birth);
            if (!$d || $d->format('Y-m-d') !== $birth || $d > new DateTime('today')) {
                $errors['birthdate'] = 'Enter a valid birthdate.';
            }
        }
        if ($errors) {
            throw new HttpException(422, 'Please correct the highlighted fields.', ['errors' => $errors]);
        }

        $pdo = get_db();
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT id FROM sections WHERE id = ? FOR UPDATE');
            $lock->execute([(int) $section['id']]);

            $dup = $pdo->prepare('SELECT last_name, first_name FROM students WHERE lrn = ? LIMIT 1');
            $dup->execute([$lrn]);
            if ($row = $dup->fetch()) {
                throw new HttpException(409, 'LRN ' . $lrn . ' already belongs to ' . $row['last_name'] . ', ' . $row['first_name'] . '. Search for that learner and enroll them instead.', ['errors' => ['lrn' => 'LRN already exists.']]);
            }

            $ins = $pdo->prepare('INSERT INTO students (lrn, last_name, first_name, middle_name, gender, birthdate) VALUES (?, ?, ?, ?, ?, ?)');
            $ins->execute([$lrn, $last, $first, $middle !== '' ? $middle : null, $gender, $birth !== '' ? $birth : null]);
            $studentId = (int) $pdo->lastInsertId();

            self::insertEnrollment($pdo, $studentId, $section);
            $pdo->commit();
            return ['student_id' => $studentId];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw self::friendly($e);
        }
    }

    /**
     * Register several brand-new learners and enroll each in $section. Every row goes through register()
     * (same validation, same unique-LRN rule, same transaction); a bad row never blocks the good ones.
     * @param array<int,array<string,mixed>> $rows
     * @return array{added:int,failed:int,results:array<int,array<string,mixed>>}
     */
    public static function registerMany(array $section, array $rows): array
    {
        self::assertOpen($section);
        $results = [];
        $seen    = [];
        foreach (array_values($rows) as $i => $row) {
            $lrn   = trim((string) ($row['lrn'] ?? ''));
            $label = trim(trim((string) ($row['last_name'] ?? '')) . ', ' . trim((string) ($row['first_name'] ?? '')), ', ');
            if ($lrn !== '') {
                $label .= ($label !== '' ? ' ' : '') . '(LRN ' . $lrn . ')';
            }
            if ($label === '') {
                $label = 'Row ' . ($i + 1);
            }
            if ($lrn !== '' && isset($seen[$lrn])) {
                $results[] = ['index' => $i, 'ok' => false, 'label' => $label, 'message' => 'LRN ' . $lrn . ' appears more than once in this list.', 'errors' => ['lrn' => 'Duplicate in this list.']];
                continue;
            }
            try {
                $r = self::register($section, $row);
                $seen[$lrn] = true;
                $results[] = ['index' => $i, 'ok' => true, 'label' => $label, 'student_id' => $r['student_id'], 'message' => 'Registered and enrolled.'];
            } catch (Throwable $e) {
                $f = Batch::failure($e, 'This learner could not be added.');
                $results[] = ['index' => $i, 'ok' => false, 'label' => $label, 'message' => $f['message'], 'errors' => $f['errors']];
            }
        }
        return self::tally($results);
    }

    /**
     * Enroll several EXISTING learners in $section. Every learner goes through enroll() (same eligibility
     * verdict: one enrollment per school year, grade continuity, valid LRN, no silent moves).
     * @param int[] $studentIds
     * @return array{added:int,failed:int,results:array<int,array<string,mixed>>}
     */
    public static function enrollMany(array $section, array $studentIds): array
    {
        self::assertOpen($section);
        $results = [];
        foreach (array_values($studentIds) as $i => $id) {
            $id = (int) $id;
            $st = get_db()->prepare('SELECT lrn, last_name, first_name, middle_name FROM students WHERE id = ? LIMIT 1');
            $st->execute([$id]);
            $row   = $st->fetch();
            $label = $row ? trim($row['last_name'] . ', ' . $row['first_name'] . ' ' . ($row['middle_name'] ?? '')) : 'Student #' . $id;
            try {
                $r = self::enroll($section, $id);
                $results[] = ['index' => $i, 'ok' => true, 'student_id' => $id, 'label' => $label,
                              'message' => $r['status'] === 'reactivated' ? 'Re-activated in this section.' : 'Enrolled.'];
            } catch (Throwable $e) {
                $f = Batch::failure($e, 'This learner could not be enrolled.');
                $results[] = ['index' => $i, 'ok' => false, 'student_id' => $id, 'label' => $label, 'message' => $f['message'], 'errors' => $f['errors']];
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

    private static function assertOpen(array $section): void
    {
        if (empty($section['year_active'])) {
            throw new HttpException(409, 'Enrollment is only open for sections of the active school year.');
        }
    }

    private static function insertEnrollment(PDO $pdo, int $studentId, array $section): void
    {
        $next = $pdo->prepare('SELECT COALESCE(MAX(roster_no), 0) + 1 FROM enrollments WHERE section_id = ? AND school_year_id = ?');
        $next->execute([(int) $section['id'], (int) $section['school_year_id']]);
        $rosterNo = (int) $next->fetchColumn();

        $ins = $pdo->prepare("INSERT INTO enrollments (student_id, section_id, school_year_id, roster_no, status) VALUES (?, ?, ?, ?, 'active')");
        $ins->execute([$studentId, (int) $section['id'], (int) $section['school_year_id'], $rosterNo]);
    }

    /** Map a duplicate-key race to a clear message; pass everything else through. */
    private static function friendly(Throwable $e): Throwable
    {
        if ($e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1062) {
            return new HttpException(409, 'This learner is already enrolled for this school year.', ['code' => 'already']);
        }
        return $e;
    }
}
