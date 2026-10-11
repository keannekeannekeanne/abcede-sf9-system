<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../Settings/Settings.php';
require_once __DIR__ . '/SF9DataService.php';

/**
 * Data for the "Temporary Report Card" sheet (public/temp_report_cards.php).
 *
 * A temporary report card carries the learner's identity, the school header and
 * the Learner's Progress and Achievement table. The grades come from
 * SF9DataService (the same numbers, MAPEH rule, Final Grade and NLPA handling as
 * the SF9), so the two documents can never disagree. It returns one row per
 * learner of a section; the page lays them out four DIFFERENT learners per A4 sheet.
 *
 * Authorization is NOT done here: the page calls Access::requireSection() first.
 */
final class TempReportCardService
{
    /** Cards per A4 sheet (2 x 2). */
    public const PER_SHEET = 4;

    /**
     * Learners of the section for the school year, in printing order (surname, then first name).
     * Only ACTIVE enrollments are included - transferred-out / dropped learners get no card.
     * When $onlyIds is given, only those learners (still limited to the active roster) are returned.
     *
     * @param  int[]|null $onlyIds
     * @return array<int,array<string,mixed>> id, lrn, full_name, sex, age, grades (null or ['terms'=>..,'final'=>..])
     */
    public static function learners(int $sectionId, int $schoolYearId, ?array $onlyIds = null, bool $withGrades = true): array
    {
        $pdo = get_db();

        $sql = "SELECT s.id, s.lrn, s.last_name, s.first_name, s.middle_name, s.gender, s.birthdate, e.id AS enrollment_id
                FROM enrollments e JOIN students s ON s.id = e.student_id
                WHERE e.section_id = ? AND e.school_year_id = ? AND e.status = 'active'
                ORDER BY s.last_name, s.first_name, s.middle_name, s.id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sectionId, $schoolYearId]);
        $rows = $stmt->fetchAll();

        // SF9-only age typed by a teacher (enrollments.sf9_age_override, migration 004). Tolerate databases without it.
        $override = [];
        try {
            $q = $pdo->prepare('SELECT student_id, sf9_age_override FROM enrollments WHERE section_id = ? AND school_year_id = ? AND sf9_age_override IS NOT NULL');
            $q->execute([$sectionId, $schoolYearId]);
            foreach ($q->fetchAll() as $r) {
                $override[(int) $r['student_id']] = (int) $r['sf9_age_override'];
            }
        } catch (PDOException $e) { /* column not there yet */ }

        $want = $onlyIds === null ? null : array_flip(array_map('intval', $onlyIds));
        $out  = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            if ($want !== null && !isset($want[$id])) {
                continue;
            }
            $name = $r['last_name'] . ', ' . $r['first_name'] . (($r['middle_name'] ?? '') !== '' ? ' ' . $r['middle_name'] : '');
            $grades = null;
            if ($withGrades) {
                try {
                    // Rank is not printed on this card, so skip the (slow) per-section rank calculation.
                    $d = SF9DataService::buildForStudent($id, $schoolYearId, false);
                    $grades = ['terms' => $d['terms'], 'final' => $d['final']];
                } catch (RuntimeException $e) { /* no grades readable: the card just prints empty cells */ }
            }
            $out[] = [
                'grades'    => $grades,
                'id'        => $id,
                'lrn'       => (string) $r['lrn'],
                'full_name' => mb_strtoupper($name),
                'sex'       => mb_strtoupper((string) $r['gender']),
                'age'       => isset($override[$id]) ? (string) $override[$id] : self::ageFrom($r['birthdate']),
            ];
        }
        return $out;
    }

    /** Whole years between the birth date and today (same rule the SF9 uses); '' when there is no birth date. */
    public static function ageFrom(?string $birthdate): string
    {
        if (!$birthdate) {
            return '';
        }
        return (string) (new DateTime($birthdate))->diff(new DateTime())->y;
    }

    /** Name printed on the Principal line (same source and fallback as the SF9). */
    public static function principalName(): string
    {
        $pdo = get_db();
        $row = $pdo->query("SELECT first_name, last_name FROM users WHERE role = 'principal' AND is_active = 1 ORDER BY id LIMIT 1")->fetch();
        $name = $row ? trim($row['first_name'] . ' ' . $row['last_name']) : '';
        if ($name === '') {
            try {
                $name = (string) Settings::get('school_head_name', 'YVETTE DS. SAN JUAN');
            } catch (PDOException $e) {
                $name = 'YVETTE DS. SAN JUAN';   // `settings` table not created yet (migration 003)
            }
        }
        return mb_strtoupper($name);
    }

    /** Split the cards into sheets of four: [[a,b,c,d],[e,f,g,h],...]. The last sheet may hold fewer than four. */
    public static function sheets(array $learners): array
    {
        return array_chunk($learners, self::PER_SHEET);
    }
}
