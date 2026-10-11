<?php
require_once __DIR__ . '/Auth.php';

/**
 * Server-side authorization for sections / students.
 *
 * A teacher can open a section when they are its homeroom adviser OR are
 * assigned (teacher_assignments) to at least one subject in it. They can
 * EDIT a grade only for subjects they are assigned to in that section.
 * Every page and endpoint calls these helpers; nothing relies on the client.
 *
 * NOTE: PDO here runs with ATTR_EMULATE_PREPARES = false (native prepares),
 * which does not allow the same :named placeholder twice in one statement.
 * Queries in this class use positional "?" placeholders for that reason.
 */
final class Access
{
    /**
     * All sections the teacher may open (any school year), newest year first.
     * Admins see every section system-wide (Priority 2/5: not restricted by
     * normal teacher subject/section permissions).
     */
    public static function sectionsFor(int $userId): array
    {
        $pdo  = get_db();

        if (Auth::role() === 'admin') {
            $stmt = $pdo->prepare(
                "SELECT sec.id, sec.grade_level, sec.section_name, sec.school_year_id, sec.adviser_id,
                        sy.year_label, sy.is_active AS year_active,
                        adv.first_name AS adviser_first, adv.last_name AS adviser_last,
                        (SELECT COUNT(*) FROM enrollments e
                          WHERE e.section_id = sec.id AND e.school_year_id = sec.school_year_id
                            AND e.status = 'active') AS student_count,
                        (SELECT COUNT(*) FROM teacher_assignments ta2
                          WHERE ta2.section_id = sec.id AND ta2.school_year_id = sec.school_year_id
                            AND ta2.teacher_id = ?) AS my_subject_count
                 FROM sections sec
                 JOIN school_years sy ON sy.id = sec.school_year_id
                 LEFT JOIN users adv ON adv.id = sec.adviser_id
                 ORDER BY sy.is_active DESC, sy.year_label DESC, sec.grade_level, sec.section_name"
            );
            $stmt->execute([$userId]);
            return self::mapSections($stmt->fetchAll(), $userId);
        }

        $stmt = $pdo->prepare(
            "SELECT sec.id, sec.grade_level, sec.section_name, sec.school_year_id, sec.adviser_id,
                    sy.year_label, sy.is_active AS year_active,
                    adv.first_name AS adviser_first, adv.last_name AS adviser_last,
                    (SELECT COUNT(*) FROM enrollments e
                      WHERE e.section_id = sec.id AND e.school_year_id = sec.school_year_id
                        AND e.status = 'active') AS student_count,
                    (SELECT COUNT(*) FROM teacher_assignments ta2
                      WHERE ta2.section_id = sec.id AND ta2.school_year_id = sec.school_year_id
                        AND ta2.teacher_id = ?) AS my_subject_count
             FROM sections sec
             JOIN school_years sy ON sy.id = sec.school_year_id
             LEFT JOIN users adv ON adv.id = sec.adviser_id
             WHERE sec.adviser_id = ?
                OR EXISTS (SELECT 1 FROM teacher_assignments ta
                            WHERE ta.section_id = sec.id AND ta.teacher_id = ?)
             ORDER BY sy.is_active DESC, sy.year_label DESC, sec.grade_level, sec.section_name"
        );
        $stmt->execute([$userId, $userId, $userId]);
        $rows = $stmt->fetchAll();

        // Admin-assigned grade level (Grade 4/5/6): only that grade's sections are visible.
        $assigned = Auth::assignedGradeLevel($userId);
        if ($assigned !== null) {
            $rows = array_values(array_filter($rows, static fn(array $r): bool => (int) $r['grade_level'] === $assigned));
        }
        return self::mapSections($rows, $userId);
    }

    private static function mapSections(array $rows, int $userId): array
    {
        return array_map(static function (array $r) use ($userId): array {
            $r['id']               = (int) $r['id'];
            $r['grade_level']      = (int) $r['grade_level'];
            $r['school_year_id']   = (int) $r['school_year_id'];
            $r['year_active']      = (bool) $r['year_active'];
            $r['student_count']    = (int) $r['student_count'];
            $r['my_subject_count'] = (int) $r['my_subject_count'];
            $r['is_adviser']       = (int) $r['adviser_id'] === $userId;
            $r['adviser_name']     = trim(($r['adviser_first'] ?? '') . ' ' . ($r['adviser_last'] ?? ''));
            return $r;
        }, $rows);
    }

    /**
     * Section the logged-in teacher may open, or 403.
     * Adds: adviser_name, is_adviser, year_active, student_count, my_assignments (subject_id => assignment_id).
     */
    public static function requireSection(int $sectionId): array
    {
        Auth::requireLogin();
        $userId = Auth::userId();
        $pdo    = get_db();

        $stmt = $pdo->prepare(
            'SELECT sec.id, sec.grade_level, sec.section_name, sec.school_year_id, sec.adviser_id,
                    sy.year_label, sy.is_active AS year_active,
                    adv.first_name AS adviser_first, adv.last_name AS adviser_last
             FROM sections sec
             JOIN school_years sy ON sy.id = sec.school_year_id
             LEFT JOIN users adv ON adv.id = sec.adviser_id
             WHERE sec.id = ? LIMIT 1'
        );
        $stmt->execute([$sectionId]);
        $sec = $stmt->fetch();

        $mine = $sec ? self::myAssignments($userId, (int) $sec['id'], (int) $sec['school_year_id']) : [];
        $isAdviser = $sec && (int) $sec['adviser_id'] === $userId;
        $isAdmin   = Auth::role() === 'admin';

        if (!$sec || (!$isAdviser && !$mine && !$isAdmin)) {
            // Same answer whether the section is missing or belongs to someone else.
            throw new HttpException(403, 'You do not have access to this section.');
        }

        // Respect the grade level an Administrator assigned to this teacher (admins are never restricted).
        if (!$isAdmin) {
            $assignedGrade = Auth::assignedGradeLevel($userId);
            if ($assignedGrade !== null && (int) $sec['grade_level'] !== $assignedGrade) {
                throw new HttpException(403, 'This section is outside your assigned grade level (Grade ' . $assignedGrade . ').');
            }
        }

        $count = $pdo->prepare(
            "SELECT COUNT(*) FROM enrollments WHERE section_id = ? AND school_year_id = ? AND status = 'active'"
        );
        $count->execute([(int) $sec['id'], (int) $sec['school_year_id']]);

        return [
            'id'             => (int) $sec['id'],
            'grade_level'    => (int) $sec['grade_level'],
            'section_name'   => $sec['section_name'],
            'school_year_id' => (int) $sec['school_year_id'],
            'year_label'     => $sec['year_label'],
            'year_active'    => (bool) $sec['year_active'],
            'adviser_id'     => $sec['adviser_id'] !== null ? (int) $sec['adviser_id'] : null,
            'adviser_name'   => trim(($sec['adviser_first'] ?? '') . ' ' . ($sec['adviser_last'] ?? '')),
            'is_adviser'     => $isAdviser,
            'is_admin'       => $isAdmin,
            'student_count'  => (int) $count->fetchColumn(),
            'my_assignments' => $mine,
        ];
    }

    /** @return array<int,int> subject_id => teacher_assignments.id for this teacher in this section/year */
    public static function myAssignments(int $userId, int $sectionId, int $yearId): array
    {
        $stmt = get_db()->prepare(
            'SELECT id, subject_id FROM teacher_assignments
             WHERE teacher_id = ? AND section_id = ? AND school_year_id = ?'
        );
        $stmt->execute([$userId, $sectionId, $yearId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['subject_id']] = (int) $r['id'];
        }
        return $out;
    }

    /**
     * The set of GRADABLE subject_ids this teacher may enter grades for in
     * this section/year. Admins may edit nothing directly here by default
     * (grading stays with assigned teachers); admins act through the Admin
     * Panel instead.
     *
     * @return array<int,int> subject_id => the teacher_assignment_id that grants it
     */
    public static function editableSubjectIds(int $userId, int $sectionId, int $yearId): array
    {
        return self::myAssignments($userId, $sectionId, $yearId);
    }

    /**
     * ALL gradable subject_ids assigned in this section/year, regardless of
     * which teacher holds them (subject_id => assignment_id). Used only for
     * Admin overrides (Priority 5: "manage grades where appropriate") — a
     * normal teacher must use editableSubjectIds() instead, which is scoped
     * to their own assignments.
     */
    public static function allEditableSubjectIds(int $sectionId, int $yearId): array
    {
        $stmt = get_db()->prepare('SELECT id, subject_id FROM teacher_assignments WHERE section_id = ? AND school_year_id = ?');
        $stmt->execute([$sectionId, $yearId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['subject_id']] = (int) $r['id'];
        }
        return $out;
    }

    /** The student's enrollment row in this section/year (any status), or 404. */
    public static function requireEnrollment(int $sectionId, int $studentId, int $yearId): array
    {
        $stmt = get_db()->prepare(
            'SELECT e.id, e.status, e.roster_no, s.id AS student_id, s.lrn,
                    s.last_name, s.first_name, s.middle_name, s.gender, s.birthdate
             FROM enrollments e JOIN students s ON s.id = e.student_id
             WHERE e.section_id = ? AND e.student_id = ? AND e.school_year_id = ? LIMIT 1'
        );
        $stmt->execute([$sectionId, $studentId, $yearId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new HttpException(404, 'That student is not enrolled in this section.');
        }
        return $row;
    }

    /** @return int[] student ids with an ACTIVE enrollment in the section/year */
    public static function activeRosterIds(int $sectionId, int $yearId): array
    {
        $stmt = get_db()->prepare(
            "SELECT student_id FROM enrollments WHERE section_id = ? AND school_year_id = ? AND status = 'active'"
        );
        $stmt->execute([$sectionId, $yearId]);
        return array_map('intval', array_column($stmt->fetchAll(), 'student_id'));
    }
}
