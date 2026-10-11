<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../Spreadsheet/XlsxWriter.php';

/**
 * Export / backup of one or more sections to an Excel workbook.
 *
 * READ-ONLY: this class only ever runs SELECT statements. It is a copy of the data, never a move,
 * and it changes nothing in the database. Restoring is not part of this feature.
 *
 * Sheets: Backup Info | Students | Grades | Attendance | Teacher Remarks | Subject Teachers
 * Grades are the values as STORED (Term 1-3 per learning area, NLPA / T/O shown as text);
 * MAPEH, averages, rank and descriptors are calculated by the system and can be regenerated,
 * so they are not stored in the backup.
 */
final class SectionBackupService
{
    public const MAX_SECTIONS = 100;
    private const MONTH_ORDER = ['Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec', 'Jan', 'Feb', 'Mar', 'Apr'];

    /** Sections available for backup, newest school year first. */
    public static function listSections(): array
    {
        $stmt = get_db()->query(
            "SELECT sec.id, sec.grade_level, sec.section_name, sy.year_label, sy.is_active AS year_active,
                    (SELECT COUNT(*) FROM enrollments e WHERE e.section_id = sec.id AND e.school_year_id = sec.school_year_id) AS student_count
             FROM sections sec JOIN school_years sy ON sy.id = sec.school_year_id
             ORDER BY sy.is_active DESC, sy.year_label DESC, sec.grade_level, sec.section_name"
        );
        return array_map(static fn(array $r): array => [
            'id' => (int) $r['id'], 'grade_level' => (int) $r['grade_level'], 'section_name' => $r['section_name'],
            'year_label' => $r['year_label'], 'year_active' => (bool) $r['year_active'], 'student_count' => (int) $r['student_count'],
        ], $stmt->fetchAll());
    }

    /**
     * @param int[] $sectionIds
     * @return array{workbook:XlsxWriter,filename:string,counts:array<string,int>}
     */
    public static function build(array $sectionIds, string $exportedBy): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $sectionIds), static fn($i) => $i > 0)));
        if (!$ids) {
            throw new HttpException(422, 'Select at least one section to back up.');
        }
        if (count($ids) > self::MAX_SECTIONS) {
            throw new HttpException(422, 'Select at most ' . self::MAX_SECTIONS . ' sections at a time.');
        }
        $pdo = get_db();
        $in  = implode(',', array_fill(0, count($ids), '?'));

        $secStmt = $pdo->prepare(
            "SELECT sec.id, sec.grade_level, sec.section_name, sec.school_year_id, sy.year_label,
                    adv.first_name AS adv_first, adv.last_name AS adv_last, mt.first_name AS mt_first, mt.last_name AS mt_last
             FROM sections sec
             JOIN school_years sy ON sy.id = sec.school_year_id
             LEFT JOIN users adv ON adv.id = sec.adviser_id
             LEFT JOIN users mt  ON mt.id  = sec.master_teacher_id
             WHERE sec.id IN ($in)
             ORDER BY sy.year_label DESC, sec.grade_level, sec.section_name"
        );
        $secStmt->execute($ids);
        $sections = $secStmt->fetchAll();
        if (count($sections) !== count($ids)) {
            throw new HttpException(404, 'One of the selected sections no longer exists. Reload the page and try again.');
        }
        $order = array_flip(array_column($sections, 'id'));
        $secById = array_column($sections, null, 'id');
        $secIds = array_map('intval', array_column($sections, 'id'));

        // ---- students (every enrollment status) -------------------------------------------------
        $studentSql = "SELECT e.section_id, e.student_id, e.school_year_id, e.roster_no, e.status, %s AS sf9_age_override,
                    s.lrn, s.last_name, s.first_name, s.middle_name, s.gender, s.birthdate
             FROM enrollments e JOIN students s ON s.id = e.student_id
             WHERE e.section_id IN ($in)
             ORDER BY e.section_id, e.roster_no IS NULL, e.roster_no, s.last_name, s.first_name";
        try {
            $stuStmt = $pdo->prepare(sprintf($studentSql, 'e.sf9_age_override'));
            $stuStmt->execute($secIds);
        } catch (PDOException $e) {
            // Database without migration 004 yet: export everything except the SF9 age override.
            $stuStmt = $pdo->prepare(sprintf($studentSql, 'NULL'));
            $stuStmt->execute($secIds);
        }
        $students = $stuStmt->fetchAll();
        usort($students, static fn($a, $b) => ($order[(int) $a['section_id']] <=> $order[(int) $b['section_id']]));

        $stuKey = [];           // "studentId|yearId" => student row (for grades/attendance/remarks scoping)
        foreach ($students as $s) { $stuKey[(int) $s['student_id'] . '|' . (int) $s['school_year_id']] = $s; }

        // ---- workbook ----------------------------------------------------------------------------
        $now = new DateTimeImmutable('now');
        $stamp = $now->format('Y-m-d H:i:s T');
        $x = new XlsxWriter();

        // Backup Info
        $info = $x->addSheet('Backup Info', [['Grade', 9], ['Section', 18], ['School Year', 14], ['Adviser', 28], ['Master Teacher', 28], ['Learners enrolled', 16]]);
        $info->title('Section Data Backup — Dr. Domingo G. Abcede Elementary School')
             ->label('Exported on:', $stamp)
             ->label('Exported by:', $exportedBy)
             ->label('Sections included:', (string) count($sections))
             ->label('Learners included:', (string) count($students))
             ->note('This file is a copy of the data. Nothing was changed or deleted in the system.')
             ->gap();
        $perSection = [];
        foreach ($students as $s) { $perSection[(int) $s['section_id']] = ($perSection[(int) $s['section_id']] ?? 0) + 1; }
        foreach ($sections as $sec) {
            $info->row([
                XlsxWriter::int($sec['grade_level']), $sec['section_name'], $sec['year_label'],
                self::person($sec['adv_first'], $sec['adv_last']), self::person($sec['mt_first'], $sec['mt_last']),
                XlsxWriter::int($perSection[(int) $sec['id']] ?? 0),
            ]);
        }

        // Students
        $st = $x->addSheet('Students', [
            ['Grade', 8], ['Section', 16], ['School Year', 12], ['No.', 6], ['LRN', 16], ['Last Name', 22], ['First Name', 24],
            ['Middle Name', 18], ['Sex', 9], ['Birth Date', 13], ['Age (on export date)', 12], ['SF9 Age (custom)', 12], ['Enrollment Status', 16],
        ]);
        foreach ($students as $s) {
            $sec = $secById[(int) $s['section_id']];
            $st->row([
                XlsxWriter::int($sec['grade_level']), $sec['section_name'], $sec['year_label'], XlsxWriter::int($s['roster_no']),
                (string) $s['lrn'], (string) $s['last_name'], (string) $s['first_name'], (string) ($s['middle_name'] ?? ''), (string) $s['gender'],
                XlsxWriter::date($s['birthdate']), self::age($s['birthdate'], $now), XlsxWriter::int($s['sf9_age_override']),
                ucwords(str_replace('_', ' ', (string) $s['status'])),
            ]);
        }

        // Grades (raw values as stored)
        $subjects = $pdo->query('SELECT id, subject_name FROM subjects WHERE is_active = 1 AND is_group = 0 ORDER BY sort_order')->fetchAll();
        $gCols = [['Grade', 8], ['Section', 16], ['LRN', 16], ['Learner', 34], ['Term', 7]];
        foreach ($subjects as $sub) { $gCols[] = [$sub['subject_name'], 15]; }
        $gs = $x->addSheet('Grades', $gCols);
        $gs->note('Grades as recorded per term. NLPA = No Longer Participating in Learning Activities; T/O = transferred out. MAPEH and averages are calculated by the system and are not part of the backup.');
        $gradeRows = 0;
        if ($students) {
            $q = $pdo->prepare("SELECT g.student_id, g.school_year_id, g.subject_id, g.trimester, g.grade_value, g.status FROM grades g WHERE g.section_id IN ($in)");
            $q->execute($secIds);
            $byStu = [];
            foreach ($q->fetchAll() as $g) {
                $byStu[(int) $g['student_id'] . '|' . (int) $g['school_year_id']][(string) $g['trimester']][(int) $g['subject_id']] = $g;
            }
            foreach ($students as $s) {
                $k = (int) $s['student_id'] . '|' . (int) $s['school_year_id'];
                foreach (['1', '2', '3'] as $term) {
                    if (empty($byStu[$k][$term])) { continue; }
                    $sec = $secById[(int) $s['section_id']];
                    $row = [XlsxWriter::int($sec['grade_level']), $sec['section_name'], (string) $s['lrn'], self::fullName($s), XlsxWriter::int($term)];
                    foreach ($subjects as $sub) {
                        $g = $byStu[$k][$term][(int) $sub['id']] ?? null;
                        if ($g === null) { $row[] = ''; }
                        elseif ($g['status'] === 'nlpa') { $row[] = 'NLPA'; }
                        elseif ($g['status'] === 'transferred_out') { $row[] = 'T/O'; }
                        else { $row[] = $g['grade_value'] === null ? '' : XlsxWriter::num($g['grade_value']); }
                    }
                    $gs->row($row);
                    $gradeRows++;
                }
            }
        }

        // Attendance
        $as = $x->addSheet('Attendance', [['Grade', 8], ['Section', 16], ['LRN', 16], ['Learner', 34], ['Month', 8], ['Days of Class', 13], ['Days Present', 13], ['Days Absent', 13]]);
        $attRows = 0;
        $aq = $pdo->prepare("SELECT student_id, school_year_id, section_id, month, days_of_class, days_present, days_absent FROM attendance_monthly WHERE section_id IN ($in)");
        $aq->execute($secIds);
        $att = $aq->fetchAll();
        $mo = array_flip(self::MONTH_ORDER);
        usort($att, static function ($a, $b) use ($order, $mo, $stuKey) {
            return [$order[(int) $a['section_id']] ?? 0, (string) ($stuKey[(int) $a['student_id'] . '|' . (int) $a['school_year_id']]['last_name'] ?? ''), $mo[$a['month']] ?? 99]
               <=> [$order[(int) $b['section_id']] ?? 0, (string) ($stuKey[(int) $b['student_id'] . '|' . (int) $b['school_year_id']]['last_name'] ?? ''), $mo[$b['month']] ?? 99];
        });
        foreach ($att as $a) {
            $s = $stuKey[(int) $a['student_id'] . '|' . (int) $a['school_year_id']] ?? null;
            if ($s === null) { continue; }
            $sec = $secById[(int) $a['section_id']];
            $as->row([XlsxWriter::int($sec['grade_level']), $sec['section_name'], (string) $s['lrn'], self::fullName($s), $a['month'],
                XlsxWriter::int($a['days_of_class']), XlsxWriter::int($a['days_present']), XlsxWriter::int($a['days_absent'])]);
            $attRows++;
        }

        // Teacher remarks
        $rs = $x->addSheet("Teacher Remarks", [['Grade', 8], ['Section', 16], ['LRN', 16], ['Learner', 34], ['Term', 7], ['Remark Set', 26], ['Remarks', 70], ['Written By', 24], ['Last Updated', 19]]);
        $rs->wrap(6);
        $remRows = 0;
        $sectionsBy = self::remarkSectionTitles($pdo);
        if ($students) {
            $yearIds = array_values(array_unique(array_map('intval', array_column($sections, 'school_year_id'))));
            $yin = implode(',', array_fill(0, count($yearIds), '?'));
            $withSection = self::hasColumn($pdo, 'teacher_remarks', 'remark_section');
            $rq = $pdo->prepare(
                'SELECT tr.student_id, tr.school_year_id, tr.trimester, tr.remarks, ' . ($withSection ? 'tr.remark_section' : 'NULL AS remark_section') . ', tr.updated_at, u.first_name, u.last_name
                 FROM teacher_remarks tr LEFT JOIN users u ON u.id = tr.written_by
                 WHERE tr.school_year_id IN (' . $yin . ') AND tr.remarks IS NOT NULL AND tr.remarks <> \'\'
                 ORDER BY tr.student_id, tr.trimester'
            );
            $rq->execute($yearIds);
            $rem = [];
            foreach ($rq->fetchAll() as $r) { $rem[(int) $r['student_id'] . '|' . (int) $r['school_year_id']][] = $r; }
            foreach ($students as $s) {
                foreach ($rem[(int) $s['student_id'] . '|' . (int) $s['school_year_id']] ?? [] as $r) {
                    $sec = $secById[(int) $s['section_id']];
                    $set = $r['remark_section'] ? ($r['remark_section'] . '. ' . ($sectionsBy[$r['remark_section']] ?? '')) : '';
                    $rs->row([XlsxWriter::int($sec['grade_level']), $sec['section_name'], (string) $s['lrn'], self::fullName($s), XlsxWriter::int($r['trimester']),
                        $set, (string) $r['remarks'], self::person($r['first_name'], $r['last_name']), (string) $r['updated_at']]);
                    $remRows++;
                }
            }
        }

        // Subject teachers
        $ts = $x->addSheet('Subject Teachers', [['Grade', 8], ['Section', 16], ['School Year', 12], ['Subject', 32], ['Teacher', 30]]);
        $tq = $pdo->prepare(
            "SELECT ta.section_id, sub.subject_name, u.first_name, u.last_name
             FROM teacher_assignments ta JOIN subjects sub ON sub.id = ta.subject_id JOIN users u ON u.id = ta.teacher_id
             WHERE ta.section_id IN ($in) ORDER BY ta.section_id, sub.sort_order"
        );
        $tq->execute($secIds);
        $taRows = $tq->fetchAll();
        usort($taRows, static fn($a, $b) => ($order[(int) $a['section_id']] ?? 0) <=> ($order[(int) $b['section_id']] ?? 0));
        foreach ($taRows as $t) {
            $sec = $secById[(int) $t['section_id']];
            $ts->row([XlsxWriter::int($sec['grade_level']), $sec['section_name'], $sec['year_label'], $t['subject_name'], self::person($t['first_name'], $t['last_name'])]);
        }

        // ---- filename ------------------------------------------------------------------------------
        if (count($sections) === 1) {
            $label = 'Grade' . $sections[0]['grade_level'] . '-' . $sections[0]['section_name'] . '_' . $sections[0]['year_label'];
        } else {
            $years = array_values(array_unique(array_column($sections, 'year_label')));
            $label = count($sections) . '-sections' . (count($years) === 1 ? '_' . $years[0] : '');
        }
        $filename = 'SF9-backup_' . $label . '_' . $now->format('Ymd-His') . '.xlsx';

        return [
            'workbook' => $x, 'filename' => $filename,
            'counts' => ['sections' => count($sections), 'students' => count($students), 'grade_rows' => $gradeRows, 'attendance_rows' => $attRows, 'remark_rows' => $remRows, 'subject_teachers' => count($taRows)],
        ];
    }

    private static function fullName(array $s): string
    {
        return trim($s['last_name'] . ', ' . $s['first_name'] . ' ' . ($s['middle_name'] ?? ''));
    }

    private static function person(?string $first, ?string $last): string
    {
        return trim(($first ?? '') . ' ' . ($last ?? ''));
    }

    private static function age(?string $birthdate, DateTimeImmutable $on): array
    {
        if (!$birthdate) { return ['t' => 'e']; }
        try {
            return XlsxWriter::int((new DateTimeImmutable($birthdate))->diff($on)->y);
        } catch (Throwable $e) {
            return ['t' => 'e'];
        }
    }

    /** @return array<string,string> code => title (empty before migration 004) */
    private static function remarkSectionTitles(PDO $pdo): array
    {
        try {
            $out = [];
            foreach ($pdo->query('SELECT code, title FROM sf9_remark_sections')->fetchAll() as $r) { $out[$r['code']] = $r['title']; }
            return $out;
        } catch (PDOException $e) {
            return [];
        }
    }

    private static function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        $st->execute([$table, $column]);
        return (int) $st->fetchColumn() > 0;
    }
}
