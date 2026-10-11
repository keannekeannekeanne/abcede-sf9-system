<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../Calculations/GradeCalculator.php';

/**
 * Class-level performance for one section, from real saved grades.
 * All formulas come from GradeCalculator (MAPEH, averages, descriptor bands, NLPA rule).
 * Categories are the descriptor_bands rows (Emerging … Advancing) plus
 * "Incomplete" (not all 8 areas graded yet) and "NLPA".
 */
final class SectionAnalytics
{
    /** @param string $term '1' | '2' | '3' | 'final' */
    public static function forSection(array $section, string $term): array
    {
        if (!in_array($term, ['1', '2', '3', 'final'], true)) {
            throw new HttpException(422, 'Invalid term.');
        }
        $pdo = get_db();
        $sectionId = (int) $section['id'];
        $yearId    = (int) $section['school_year_id'];

        $bands = $pdo->query('SELECT min_score, max_score, descriptor FROM descriptor_bands ORDER BY min_score')->fetchAll();

        $st = $pdo->prepare("SELECT student_id FROM enrollments WHERE section_id = ? AND school_year_id = ? AND status = 'active'");
        $st->execute([$sectionId, $yearId]);
        $studentIds = array_map('intval', array_column($st->fetchAll(), 'student_id'));

        $counts = [];
        foreach ($bands as $b) {
            $counts[$b['descriptor']] = 0;
        }
        $counts['Incomplete'] = 0;
        $counts['NLPA'] = 0;

        $areaSums = array_fill_keys(GradeCalculator::REQUIRED_AREAS, ['sum' => 0.0, 'n' => 0]);
        $gaSum = 0.0;
        $gaN   = 0;

        if ($studentIds) {
            $g = $pdo->prepare(
                "SELECT g.student_id, sub.subject_name, g.trimester, g.grade_value, g.status
                 FROM grades g JOIN subjects sub ON sub.id = g.subject_id
                 WHERE g.school_year_id = ?
                   AND g.student_id IN (SELECT e.student_id FROM enrollments e
                                         WHERE e.section_id = ? AND e.school_year_id = ? AND e.status = 'active')"
            );
            $g->execute([$yearId, $sectionId, $yearId]);
            $raw = [];   // raw[student][tri][subject] = [value, status]
            foreach ($g->fetchAll() as $r) {
                $raw[(int) $r['student_id']][$r['trimester']][$r['subject_name']] = [
                    $r['grade_value'] !== null ? (float) $r['grade_value'] : null, $r['status'],
                ];
            }

            foreach ($studentIds as $sid) {
                [$areas, $ga, $nlpa] = self::studentTerm($raw[$sid] ?? [], $term);
                if ($nlpa) {
                    $counts['NLPA']++;
                } elseif ($ga === null || ($label = GradeCalculator::descriptor($ga, $bands)) === null) {
                    $counts['Incomplete']++;
                } else {
                    $counts[$label]++;
                }
                if (!$nlpa) {
                    if ($ga !== null) { $gaSum += $ga; $gaN++; }
                    foreach ($areas as $area => $v) {
                        if ($v !== null) { $areaSums[$area]['sum'] += $v; $areaSums[$area]['n']++; }
                    }
                }
            }
        }

        $distribution = [];
        foreach ($counts as $label => $n) {
            $distribution[] = ['label' => $label, 'count' => $n];
        }
        $subjects = [];
        foreach ($areaSums as $area => $a) {
            $subjects[] = ['area' => $area, 'average' => $a['n'] ? round($a['sum'] / $a['n'], 1) : null, 'graded' => $a['n']];
        }

        return [
            'section_id'      => $sectionId,
            'term'            => $term,
            'total_students'  => count($studentIds),
            'distribution'    => $distribution,
            'subject_averages'=> $subjects,
            'mean_general_average' => $gaN ? round($gaSum / $gaN, 1) : null,
        ];
    }

    /**
     * @param array $rawByTri raw[tri][subject] = [value, status]
     * @return array{0:array<string,?float>,1:?int,2:bool} [area grades, general average, nlpa?]
     */
    private static function studentTerm(array $rawByTri, string $term): array
    {
        $termAreas = [];
        $nlpaAny   = false;
        foreach (['1', '2', '3'] as $tri) {
            $r = $rawByTri[$tri] ?? [];
            $areas = [];
            foreach (GradeCalculator::REQUIRED_AREAS as $area) {
                if ($area === 'MAPEH') {
                    foreach (GradeCalculator::MAPEH_COMPONENTS as $c) {
                        if (($r[$c][1] ?? '') === 'nlpa') { $nlpaAny = $nlpaAny || $term === 'final' || $tri === $term; }
                    }
                    $areas['MAPEH'] = GradeCalculator::mapeh(
                        $r['Music and Arts'][0] ?? null,
                        $r['Physical Education and Health'][0] ?? null
                    );
                } else {
                    if (($r[$area][1] ?? '') === 'nlpa') { $nlpaAny = $nlpaAny || $term === 'final' || $tri === $term; }
                    $areas[$area] = $r[$area][0] ?? null;
                }
            }
            $termAreas[$tri] = $areas;
        }

        if ($term !== 'final') {
            $areas = $termAreas[$term];
            return [$areas, GradeCalculator::generalAverage(GradeCalculator::subjectAverage($areas)), $nlpaAny];
        }

        $finals = [];
        foreach (GradeCalculator::REQUIRED_AREAS as $area) {
            $finals[$area] = GradeCalculator::finalSubjectGrade([
                1 => $termAreas['1'][$area], 2 => $termAreas['2'][$area], 3 => $termAreas['3'][$area],
            ]);
        }
        return [$finals, GradeCalculator::finalGeneralAverage($finals), $nlpaAny];
    }
}
