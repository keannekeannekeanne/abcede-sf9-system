<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../Auth/Auth.php';
require_once __DIR__ . '/../Http/Batch.php';

/**
 * Force Delete — Admin only.
 *
 * The normal Delete actions (AdminService::deleteSection / deleteAccount) are UNCHANGED: they refuse
 * to delete a record that still has related data. Force Delete is a separate, deliberate action that
 * removes the record together with the data that only exists because of it, in ONE transaction:
 *
 *   section  -> its enrollments, the grades + grade audit entries recorded in it, attendance rows,
 *               the SF9 teacher remarks of the learners enrolled in it that year, and its subject
 *               teacher assignments. The learners themselves (students rows) are KEPT: they may have
 *               records in other school years.
 *   student  -> the learner and everything that belongs to that learner (enrollments, grades + audit
 *               entries, attendance, SF9 remarks). Other students / sections are not touched.
 *   account  -> the account only. Data the account merely authored is PRESERVED and re-attributed to
 *               the Admin doing the delete (grades, grade audit entries, remarks); sections it advised
 *               lose their adviser (set to none); its subject assignments are removed (the grades stay).
 *
 * Every call must come from Auth::requireAdmin() (public/api/admin.php enforces this on every request)
 * and must carry an explicit confirmation flag. Each Force Delete is written to force_delete_log.
 *
 * PDO runs with native prepares: positional "?" placeholders only, never a repeated :name.
 */
final class ForceDeleteService
{
    public const ENTITIES = ['section', 'student', 'account'];

    // ---------------------------------------------------------------
    // PREVIEW — what would be removed / kept (drives the warning dialog)
    // ---------------------------------------------------------------

    /**
     * @return array{entity:string,id:int,label:string,counts:array<int,array{label:string,count:int}>,
     *               keeps:string[],has_related:bool,blocked:?string}
     */
    public static function preview(string $entity, int $id): array
    {
        self::assertEntity($entity);
        switch ($entity) {
            case 'section': return self::previewSection($id);
            case 'student': return self::previewStudent($id);
            default:        return self::previewAccount($id);
        }
    }

    private static function previewSection(int $id): array
    {
        $sec   = self::requireSection($id);
        $label = self::sectionLabel($sec);
        $c = [
            ['Enrollment records',                  self::count('SELECT COUNT(*) FROM enrollments WHERE section_id = ?', [$id])],
            ['Grade records',                       count(self::gradeIdsForSection($id))],
            ['Attendance records',                  self::count('SELECT COUNT(*) FROM attendance_monthly WHERE section_id = ?', [$id])],
            ['SF9 teacher remarks',                 self::count(
                'SELECT COUNT(*) FROM teacher_remarks tr JOIN enrollments e ON e.student_id = tr.student_id AND e.school_year_id = tr.school_year_id WHERE e.section_id = ?', [$id])],
            ['Subject teacher assignments',         self::count('SELECT COUNT(*) FROM teacher_assignments WHERE section_id = ?', [$id])],
        ];
        return self::shape('section', $id, $label, $c,
            ['The student records themselves are kept (learners may have other school years on file).'], null);
    }

    private static function previewStudent(int $id): array
    {
        $st    = self::requireStudent($id);
        $label = trim($st['last_name'] . ', ' . $st['first_name'] . ' ' . ($st['middle_name'] ?? '')) . ' (LRN ' . $st['lrn'] . ')';
        $c = [
            ['Enrollment records', self::count('SELECT COUNT(*) FROM enrollments WHERE student_id = ?', [$id])],
            ['Grade records',      self::count('SELECT COUNT(*) FROM grades WHERE student_id = ?', [$id])],
            ['Attendance records', self::count('SELECT COUNT(*) FROM attendance_monthly WHERE student_id = ?', [$id])],
            ['SF9 teacher remarks', self::count('SELECT COUNT(*) FROM teacher_remarks WHERE student_id = ?', [$id])],
        ];
        return self::shape('student', $id, $label, $c, ['Sections, teachers and other students are not affected.'], null);
    }

    private static function previewAccount(int $id): array
    {
        $u     = self::requireUser($id);
        $label = trim($u['first_name'] . ' ' . $u['last_name']) . ' (' . $u['username'] . ')';
        $blocked = self::accountBlock($u);
        $c = [
            ['Sections this account advises (will have no adviser)', self::count('SELECT COUNT(*) FROM sections WHERE adviser_id = ? OR master_teacher_id = ?', [$id, $id])],
            ['Subject teacher assignments (will be removed)',        self::count('SELECT COUNT(*) FROM teacher_assignments WHERE teacher_id = ?', [$id])],
            ['Grades this account entered (kept, re-assigned to you)', self::count('SELECT COUNT(*) FROM grades WHERE created_by = ? OR updated_by = ?', [$id, $id])],
            ['SF9 remarks this account wrote (kept, re-assigned to you)', self::count('SELECT COUNT(*) FROM teacher_remarks WHERE written_by = ?', [$id])],
            ['Account history entries (removed)',                    self::count('SELECT COUNT(*) FROM account_audit_log WHERE target_user_id = ?', [$id])],
        ];
        return self::shape('account', $id, $label, $c,
            ['Student grades and remarks are never deleted by removing an account.'], $blocked);
    }

    // ---------------------------------------------------------------
    // EXECUTE
    // ---------------------------------------------------------------

    /** @return array{entity:string,id:int,label:string,removed:array<int,array{label:string,count:int}>} */
    public static function execute(string $entity, int $id, int $actingUserId): array
    {
        self::assertEntity($entity);
        $pdo = get_db();

        // Take the preview inside the same request so the log records what was really there.
        $pv = self::preview($entity, $id);
        if ($pv['blocked'] !== null) {
            throw new HttpException(409, $pv['blocked']);
        }
        if ($entity === 'account' && $id === $actingUserId) {
            throw new HttpException(422, 'You cannot delete your own account.');
        }

        $actor = self::requireUser($actingUserId);
        $actorName = trim($actor['first_name'] . ' ' . $actor['last_name']);

        $pdo->beginTransaction();
        try {
            switch ($entity) {
                case 'section': self::deleteSection($id); break;
                case 'student': self::deleteStudent($id); break;
                default:        self::deleteAccount($id, $actingUserId); break;
            }

            $summary = implode('; ', array_map(static fn(array $c): string => $c['label'] . ': ' . $c['count'], $pv['counts']));
            $log = $pdo->prepare(
                'INSERT INTO force_delete_log (entity_type, entity_id, entity_label, related_summary, performed_by, performed_by_name)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $log->execute([$entity, $id, mb_substr($pv['label'], 0, 255), mb_substr($summary, 0, 1000), $actingUserId, mb_substr($actorName, 0, 255)]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof PDOException) {
                error_log('[sf9] force delete ' . $entity . ' #' . $id . ' failed: ' . $e->getMessage());
                throw new HttpException(409, 'The record could not be force deleted because the database still links other data to it. ' .
                    'If you have not yet applied migrations/004_grade_assignment_force_delete_remarks_age.sql, apply it first. Nothing was changed.');
            }
            throw $e;
        }

        return ['entity' => $entity, 'id' => $id, 'label' => $pv['label'], 'removed' => $pv['counts']];
    }

    // ---------------------------------------------------------------
    // MULTIPLE RECORDS (multi-select Force Delete)
    // Every record still goes through preview() / execute() above — same rules, same transaction per
    // record, same force_delete_log entry. One failing record never stops or undoes the others.
    // ---------------------------------------------------------------

    /**
     * Combined preview for the multi-select warning dialog.
     * @param int[] $ids
     * @return array{entity:string,count:int,deletable_count:int,blocked_count:int,has_related:bool,
     *               items:array<int,array{id:int,label:string,blocked:?string,has_related:bool}>,
     *               totals:array<int,array{label:string,count:int}>,keeps:string[]}
     */
    public static function previewMany(string $entity, array $ids): array
    {
        self::assertEntity($entity);
        $items  = [];
        $totals = [];
        $keeps  = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            try {
                $pv = self::preview($entity, $id);
            } catch (HttpException $e) {
                $items[] = ['id' => $id, 'label' => ucfirst($entity) . ' #' . $id, 'blocked' => $e->getMessage(), 'has_related' => false];
                continue;
            }
            $items[] = ['id' => $id, 'label' => $pv['label'], 'blocked' => $pv['blocked'], 'has_related' => $pv['has_related']];
            if ($pv['blocked'] === null) {
                foreach ($pv['counts'] as $c) {
                    $totals[$c['label']] = ($totals[$c['label']] ?? 0) + $c['count'];
                }
                foreach ($pv['keeps'] as $k) {
                    $keeps[$k] = $k;
                }
            }
        }
        $blocked = count(array_filter($items, static fn(array $i): bool => $i['blocked'] !== null));
        $rows = [];
        foreach ($totals as $label => $n) {
            $rows[] = ['label' => $label, 'count' => (int) $n];
        }
        return [
            'entity'          => $entity,
            'count'           => count($items),
            'deletable_count' => count($items) - $blocked,
            'blocked_count'   => $blocked,
            'has_related'     => array_sum(array_column($rows, 'count')) > 0,
            'items'           => $items,
            'totals'          => $rows,
            'keeps'           => array_values($keeps),
        ];
    }

    /**
     * Force delete several records. Each is its own transaction; failures are collected per record.
     * @param int[] $ids
     * @return array{entity:string,deleted:array<int,array{id:int,label:string,removed:array}>,failed:array<int,array{id:int,label:string,message:string}>}
     */
    public static function executeMany(string $entity, array $ids, int $actingUserId): array
    {
        self::assertEntity($entity);
        $deleted = [];
        $failed  = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            try {
                $r = self::execute($entity, $id, $actingUserId);
                $deleted[] = ['id' => $id, 'label' => $r['label'], 'removed' => $r['removed']];
            } catch (Throwable $e) {
                $f = Batch::failure($e, 'This record could not be force deleted. Nothing was changed for it.');
                $failed[] = ['id' => $id, 'label' => self::labelOrFallback($entity, $id), 'message' => $f['message']];
            }
        }
        return ['entity' => $entity, 'deleted' => $deleted, 'failed' => $failed];
    }

    private static function labelOrFallback(string $entity, int $id): string
    {
        try {
            return self::preview($entity, $id)['label'];
        } catch (Throwable $e) {
            return ucfirst($entity) . ' #' . $id;
        }
    }

    private static function deleteSection(int $id): void
    {
        $pdo = get_db();
        self::requireSection($id);

        self::deleteGrades(self::gradeIdsForSection($id));
        $pdo->prepare('DELETE FROM attendance_monthly WHERE section_id = ?')->execute([$id]);
        // Remarks are keyed by learner + school year; the learner's single enrollment for that year is this section's.
        $pdo->prepare(
            'DELETE tr FROM teacher_remarks tr
             JOIN enrollments e ON e.student_id = tr.student_id AND e.school_year_id = tr.school_year_id
             WHERE e.section_id = ?'
        )->execute([$id]);
        $pdo->prepare('DELETE FROM enrollments WHERE section_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM teacher_assignments WHERE section_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM sections WHERE id = ?')->execute([$id]);
    }

    private static function deleteStudent(int $id): void
    {
        $pdo = get_db();
        self::requireStudent($id);

        $ids = $pdo->prepare('SELECT id FROM grades WHERE student_id = ?');
        $ids->execute([$id]);
        self::deleteGrades(array_map('intval', array_column($ids->fetchAll(), 'id')));
        $pdo->prepare('DELETE FROM attendance_monthly WHERE student_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM teacher_remarks WHERE student_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM enrollments WHERE student_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM students WHERE id = ?')->execute([$id]);
    }

    private static function deleteAccount(int $id, int $actingUserId): void
    {
        $pdo = get_db();
        $u = self::requireUser($id);
        if (($blocked = self::accountBlock($u)) !== null) {
            throw new HttpException(409, $blocked);
        }

        // Nothing that other people's data depends on is destroyed: re-attribute it to the acting Admin.
        $pdo->prepare('UPDATE sections SET adviser_id = NULL WHERE adviser_id = ?')->execute([$id]);
        $pdo->prepare('UPDATE sections SET master_teacher_id = NULL WHERE master_teacher_id = ?')->execute([$id]);
        $pdo->prepare('UPDATE sections SET created_by = NULL WHERE created_by = ?')->execute([$id]);
        $pdo->prepare('UPDATE users SET created_by = NULL WHERE created_by = ?')->execute([$id]);
        // Removing the assignment rows leaves the grades in place (grades.teacher_assignment_id is ON DELETE SET NULL).
        $pdo->prepare('DELETE FROM teacher_assignments WHERE teacher_id = ?')->execute([$id]);
        $pdo->prepare('UPDATE teacher_assignments SET assigned_by = NULL WHERE assigned_by = ?')->execute([$id]);
        $pdo->prepare('UPDATE grades SET created_by = ? WHERE created_by = ?')->execute([$actingUserId, $id]);
        $pdo->prepare('UPDATE grades SET updated_by = ? WHERE updated_by = ?')->execute([$actingUserId, $id]);
        $pdo->prepare('UPDATE grade_audit_log SET changed_by = ? WHERE changed_by = ?')->execute([$actingUserId, $id]);
        $pdo->prepare('UPDATE teacher_remarks SET written_by = ? WHERE written_by = ?')->execute([$actingUserId, $id]);
        $pdo->prepare('UPDATE account_audit_log SET performed_by = ? WHERE performed_by = ?')->execute([$actingUserId, $id]);
        $pdo->prepare('DELETE FROM account_audit_log WHERE target_user_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    }

    // ---------------------------------------------------------------
    // helpers
    // ---------------------------------------------------------------

    /** @return int[] every grade recorded in the section (by section, or through one of its assignments) */
    private static function gradeIdsForSection(int $sectionId): array
    {
        $stmt = get_db()->prepare(
            'SELECT id FROM grades
             WHERE section_id = ? OR teacher_assignment_id IN (SELECT id FROM teacher_assignments WHERE section_id = ?)'
        );
        $stmt->execute([$sectionId, $sectionId]);
        return array_map('intval', array_column($stmt->fetchAll(), 'id'));
    }

    /** @param int[] $gradeIds delete their audit entries first (FK), then the grades, in safe chunks */
    private static function deleteGrades(array $gradeIds): void
    {
        $pdo = get_db();
        foreach (array_chunk($gradeIds, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $pdo->prepare("DELETE FROM grade_audit_log WHERE grade_id IN ($in)")->execute($chunk);
            $pdo->prepare("DELETE FROM grades WHERE id IN ($in)")->execute($chunk);
        }
    }

    /** Why an account can never be force deleted, or null. */
    private static function accountBlock(array $u): ?string
    {
        if ((int) $u['id'] === Auth::userId()) {
            return 'You cannot delete your own account.';
        }
        if ($u['role'] === 'admin' && $u['is_active']) {
            $others = self::count("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND id <> ?", [(int) $u['id']]);
            if ($others === 0) {
                return 'This is the only active Administrator account, so it cannot be deleted.';
            }
        }
        return null;
    }

    private static function shape(string $entity, int $id, string $label, array $counts, array $keeps, ?string $blocked): array
    {
        $rows = array_map(static fn(array $c): array => ['label' => $c[0], 'count' => (int) $c[1]], $counts);
        return [
            'entity'      => $entity,
            'id'          => $id,
            'label'       => $label,
            'counts'      => $rows,
            'keeps'       => $keeps,
            'has_related' => array_sum(array_column($rows, 'count')) > 0,
            'blocked'     => $blocked,
        ];
    }

    private static function count(string $sql, array $args): int
    {
        $stmt = get_db()->prepare($sql);
        $stmt->execute($args);
        return (int) $stmt->fetchColumn();
    }

    private static function assertEntity(string $entity): void
    {
        if (!in_array($entity, self::ENTITIES, true)) {
            throw new HttpException(422, 'Force Delete is not available for that kind of record.');
        }
    }

    private static function requireSection(int $id): array
    {
        $stmt = get_db()->prepare(
            'SELECT sec.id, sec.grade_level, sec.section_name, sy.year_label
             FROM sections sec JOIN school_years sy ON sy.id = sec.school_year_id WHERE sec.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new HttpException(404, 'Section not found (it may already have been deleted).');
        }
        return $row;
    }

    private static function sectionLabel(array $sec): string
    {
        return 'Grade ' . $sec['grade_level'] . ' – ' . $sec['section_name'] . ' (' . $sec['year_label'] . ')';
    }

    private static function requireStudent(int $id): array
    {
        $stmt = get_db()->prepare('SELECT id, lrn, last_name, first_name, middle_name FROM students WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new HttpException(404, 'Student not found (they may already have been deleted).');
        }
        return $row;
    }

    private static function requireUser(int $id): array
    {
        $stmt = get_db()->prepare('SELECT id, username, first_name, last_name, role, is_active FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new HttpException(404, 'Account not found (it may already have been deleted).');
        }
        return $row;
    }
}
