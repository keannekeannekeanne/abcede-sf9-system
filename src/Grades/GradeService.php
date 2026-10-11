<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../Calculations/GradeCalculator.php';

/** Thrown when an edit touches a locked grade (distinct from DB/runtime errors). */
class GradeLockedException extends RuntimeException {}

class GradeService
{
    public const STATUSES = ['graded', 'nlpa', 'transferred_out'];

    /**
     * Fetch the grade grid for one class+subject+trimester, joined against
     * the roster so students with no saved grade yet still appear.
     *
     * (Bug fix: this query used :school_year_id twice. With native prepared
     * statements PDO rejects a repeated named placeholder (SQLSTATE HY093),
     * the uncaught PDOException was printed as HTML, and the grade sheet
     * died with "Unexpected token '<'". Positional placeholders fix it.)
     */
    public static function getGradeSheet(int $sectionId, int $subjectId, int $schoolYearId, string $trimester): array
    {
        $stmt = get_db()->prepare(
            "SELECT e.roster_no, s.id AS student_id, s.last_name, s.first_name, s.middle_name, s.gender,
                    g.id AS grade_id, g.grade_value, g.status, g.is_locked
             FROM enrollments e
             JOIN students s ON s.id = e.student_id
             LEFT JOIN grades g
                    ON g.student_id = s.id AND g.subject_id = ?
                   AND g.school_year_id = ? AND g.trimester = ?
             WHERE e.section_id = ? AND e.school_year_id = ? AND e.status = 'active'
             ORDER BY s.last_name, s.first_name"
        );
        $stmt->execute([$subjectId, $schoolYearId, $trimester, $sectionId, $schoolYearId]);
        return $stmt->fetchAll();
    }

    /**
     * Validate one grade input. Returns [normalizedValue|null, normalizedStatus]
     * or throws InvalidArgumentException with a teacher-readable message.
     *
     * @param mixed $rawValue
     * @return array{0:?float,1:string}
     */
    public static function normalize(string $trimester, $rawValue, string $status): array
    {
        if (!in_array($trimester, ['1', '2', '3'], true)) {
            throw new InvalidArgumentException('Invalid term.');
        }
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Invalid status.');
        }
        if ($status !== 'graded') {
            // NLPA / transferred_out never carry a numeric value — never treat "missing" as zero.
            return [null, $status];
        }
        if ($rawValue === null || $rawValue === '') {
            throw new InvalidArgumentException('A numeric grade is required when status is "Graded".');
        }
        if (!is_numeric($rawValue)) {
            throw new InvalidArgumentException('Grade must be a number.');
        }
        $value = (float) $rawValue;
        if (!is_finite($value) || $value < 0 || $value > 100) {
            throw new InvalidArgumentException('Grade must be between 0 and 100.');
        }
        return [round($value, 2), 'graded'];
    }

    /** Single-grade save (kept for callers that save one cell). */
    public static function saveGrade(
        int $studentId, int $subjectId, int $sectionId, int $schoolYearId, string $trimester,
        int $teacherAssignmentId, ?float $gradeValue, string $status, int $actingUserId
    ): void {
        self::saveBatch([[
            'student_id' => $studentId, 'subject_id' => $subjectId, 'section_id' => $sectionId,
            'school_year_id' => $schoolYearId, 'trimester' => $trimester,
            'teacher_assignment_id' => $teacherAssignmentId, 'grade_value' => $gradeValue, 'status' => $status,
        ]], $actingUserId);
    }

    /**
     * Save many grades ALL-OR-NOTHING in one transaction. Each item is already
     * authorized + normalized by the caller (see api/*.php). Re-saving updates the
     * existing row (UNIQUE key) and every change is written to grade_audit_log.
     *
     * @param array<int,array<string,mixed>> $items
     * @throws GradeLockedException when a grade is locked (nothing is saved)
     */
    public static function saveBatch(array $items, int $actingUserId): void
    {
        $pdo = get_db();
        $pdo->beginTransaction();
        try {
            $select = $pdo->prepare(
                'SELECT id, grade_value, status, is_locked FROM grades
                 WHERE student_id = ? AND subject_id = ? AND school_year_id = ? AND trimester = ?
                 FOR UPDATE'
            );
            // Two distinct placeholders for the acting user (a repeated :uid used to break saves).
            $upsert = $pdo->prepare(
                'INSERT INTO grades
                    (student_id, subject_id, section_id, school_year_id, trimester,
                     teacher_assignment_id, grade_value, status, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    grade_value = VALUES(grade_value),
                    status = VALUES(status),
                    teacher_assignment_id = VALUES(teacher_assignment_id),
                    updated_by = VALUES(updated_by),
                    updated_at = CURRENT_TIMESTAMP'
            );
            $log = $pdo->prepare(
                'INSERT INTO grade_audit_log (grade_id, changed_by, old_value, new_value, old_status, new_status)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $idOf = $pdo->prepare(
                'SELECT id FROM grades WHERE student_id = ? AND subject_id = ? AND school_year_id = ? AND trimester = ?'
            );

            foreach ($items as $it) {
                [$value, $status] = self::normalize((string) $it['trimester'], $it['grade_value'], (string) $it['status']);
                $key = [(int) $it['student_id'], (int) $it['subject_id'], (int) $it['school_year_id'], (string) $it['trimester']];

                $select->execute($key);
                $old = $select->fetch();
                if ($old && (int) $old['is_locked'] === 1) {
                    throw new GradeLockedException('A grade in this change is locked and can no longer be edited.');
                }

                $upsert->execute([
                    (int) $it['student_id'], (int) $it['subject_id'], (int) $it['section_id'],
                    (int) $it['school_year_id'], (string) $it['trimester'], (int) $it['teacher_assignment_id'],
                    $value, $status, $actingUserId, $actingUserId,
                ]);

                if ($old) {
                    $gradeId = (int) $old['id'];
                } else {
                    $idOf->execute($key);
                    $gradeId = (int) $idOf->fetchColumn();
                }

                $log->execute([
                    $gradeId, $actingUserId,
                    $old['grade_value'] ?? null, $value,
                    $old['status'] ?? null, $status,
                ]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
