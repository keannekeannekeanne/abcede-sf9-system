<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../Calculations/GradeCalculator.php';

class SF9DataService
{
    private static function loadDescriptorBands(PDO $pdo): array
    {
        return $pdo->query('SELECT min_score, max_score, descriptor FROM descriptor_bands ORDER BY min_score')
                    ->fetchAll();
    }

    /**
     * Build the full SF9 payload for one student in one school year:
     * per-trimester per-subject grades, MAPEH computation, subject
     * averages/general averages/rank/descriptor/award per trimester,
     * the 3-term Final Grade per subject, the Final General Average,
     * attendance, and teacher remarks. Returns a structured array ready
     * for the SF9 template (see public/sf9_preview.php).
     */
    public static function buildForStudent(int $studentId, int $schoolYearId, bool $withRank = true): array
    {
        $pdo = get_db();
        $bands = self::loadDescriptorBands($pdo);

        $studentStmt = $pdo->prepare(
            'SELECT s.id, s.lrn, s.last_name, s.first_name, s.middle_name, s.gender, s.birthdate,
                    e.section_id, e.roster_no, sec.grade_level, sec.section_name, sy.year_label,
                    adviser.first_name AS adviser_first, adviser.last_name AS adviser_last
             FROM students s
             JOIN enrollments e ON e.student_id = s.id AND e.school_year_id = :sy
             JOIN sections sec ON sec.id = e.section_id
             JOIN school_years sy ON sy.id = e.school_year_id
             LEFT JOIN users adviser ON adviser.id = sec.adviser_id
             WHERE s.id = :sid LIMIT 1'
        );
        $studentStmt->execute(['sid' => $studentId, 'sy' => $schoolYearId]);
        $student = $studentStmt->fetch();
        if (!$student) {
            throw new RuntimeException('Student not enrolled for the given school year.');
        }

        $gradesStmt = $pdo->prepare(
            'SELECT sub.subject_name, sub.parent_group, g.trimester, g.grade_value, g.status
             FROM grades g
             JOIN subjects sub ON sub.id = g.subject_id
             WHERE g.student_id = :sid AND g.school_year_id = :sy'
        );
        $gradesStmt->execute(['sid' => $studentId, 'sy' => $schoolYearId]);
        $rows = $gradesStmt->fetchAll();

        // raw[trimester][subject_name] = ['value' => float|null, 'status' => string]
        $raw = ['1' => [], '2' => [], '3' => []];
        foreach ($rows as $r) {
            $raw[$r['trimester']][$r['subject_name']] = [
                'value'  => $r['grade_value'] !== null ? (float) $r['grade_value'] : null,
                'status' => $r['status'],
            ];
        }

        $termResults = [];
        $anyNlpaTerm = null;

        foreach (['1', '2', '3'] as $tri) {
            $hasNlpa = false;
            $areaGrades = [];
            // Raw MAPEH components, kept separately for SF9 display —
            // NOT used in any average directly (the average uses the computed 'MAPEH' entry).
            $musicAndArts = $raw[$tri]['Music and Arts']['value'] ?? null;
            $peAndHealth  = $raw[$tri]['Physical Education and Health']['value'] ?? null;
            $components = ['Music and Arts' => $musicAndArts, 'Physical Education and Health' => $peAndHealth];

            foreach (GradeCalculator::REQUIRED_AREAS as $area) {
                if ($area === 'MAPEH') {
                    foreach (GradeCalculator::MAPEH_COMPONENTS as $c) {
                        if (($raw[$tri][$c]['status'] ?? '') === 'nlpa') {
                            $hasNlpa = true;
                        }
                    }
                    $areaGrades['MAPEH'] = GradeCalculator::mapeh($musicAndArts, $peAndHealth);
                } else {
                    $areaGrades[$area] = $raw[$tri][$area]['value'] ?? null;
                    if (($raw[$tri][$area]['status'] ?? '') === 'nlpa') {
                        $hasNlpa = true;
                    }
                }
            }

            if ($hasNlpa) {
                $anyNlpaTerm = $tri;
                $termResults[$tri] = [
                    'area_grades' => $areaGrades,
                    'components' => $components,
                    'subject_average' => null,
                    'general_average' => null,
                    'descriptor' => 'NLPA',
                    'award' => false,
                ];
                continue;
            }

            $subjAvg = GradeCalculator::subjectAverage($areaGrades);
            $genAvg  = GradeCalculator::generalAverage($subjAvg);
            $termResults[$tri] = [
                'area_grades' => $areaGrades,
                'components' => $components,
                'subject_average' => $subjAvg,
                'general_average' => $genAvg,
                'descriptor' => GradeCalculator::descriptor($genAvg, $bands),
                'award' => GradeCalculator::academicExcellenceAward($genAvg, $areaGrades),
            ];
        }

        // Final Grade per subject across the 3 terms (blocked if any term is NLPA).
        $finalSubjectGrades = [];
        $blocked = $anyNlpaTerm !== null;
        if (!$blocked) {
            foreach (GradeCalculator::REQUIRED_AREAS as $area) {
                $finalSubjectGrades[$area] = GradeCalculator::finalSubjectGrade([
                    1 => $termResults['1']['area_grades'][$area] ?? null,
                    2 => $termResults['2']['area_grades'][$area] ?? null,
                    3 => $termResults['3']['area_grades'][$area] ?? null,
                ]);
            }
        }
        // Final Grade for the two MAPEH components too — display-only, mirrors the
        // official template's separate Music and Arts / Physical Education and Health rows.
        $finalComponents = [];
        if (!$blocked) {
            foreach (GradeCalculator::MAPEH_COMPONENTS as $comp) {
                $finalComponents[$comp] = GradeCalculator::finalSubjectGrade([
                    1 => $termResults['1']['components'][$comp] ?? null,
                    2 => $termResults['2']['components'][$comp] ?? null,
                    3 => $termResults['3']['components'][$comp] ?? null,
                ]);
            }
        }

        $finalGeneralAverage = $blocked ? null : GradeCalculator::finalGeneralAverage($finalSubjectGrades);
        $finalDescriptor = $blocked ? 'NLPA' : GradeCalculator::descriptor($finalGeneralAverage, $bands);
        $finalAward = $blocked ? false : GradeCalculator::academicExcellenceAward($finalGeneralAverage, $finalSubjectGrades);

        // Rank among the section's other students for the same school year.
        // Skipped ($withRank = false) by callers that print many learners at once and do not show the rank.
        $finalRank = null;
        if ($withRank) {
            $rankStmt = $pdo->prepare(
                "SELECT DISTINCT s2.id AS student_id FROM students s2
                 JOIN enrollments e2 ON e2.student_id = s2.id
                 WHERE e2.section_id = :sec AND e2.school_year_id = :sy AND e2.status = 'active'"
            );
            $rankStmt->execute(['sec' => $student['section_id'], 'sy' => $schoolYearId]);
            $sectionStudentIds = array_column($rankStmt->fetchAll(), 'student_id');

            $sectionFinalAverages = [];
            foreach ($sectionStudentIds as $sid) {
                if ((int) $sid === $studentId) {
                    continue;
                }
                // Recursion-free: recompute cheaply just for the final average.
                $ga = self::finalGeneralAverageOnly($pdo, (int) $sid, $schoolYearId, $bands);
                if ($ga !== null) {
                    $sectionFinalAverages[] = $ga;
                }
            }
            $finalRank = GradeCalculator::rank($finalGeneralAverage, $sectionFinalAverages);

        }

        $attendanceStmt = $pdo->prepare(
            'SELECT month, days_of_class, days_present, days_absent
             FROM attendance_monthly WHERE student_id = :sid AND school_year_id = :sy'
        );
        $attendanceStmt->execute(['sid' => $studentId, 'sy' => $schoolYearId]);
        $attendance = $attendanceStmt->fetchAll();

        $remarksStmt = $pdo->prepare(
            'SELECT trimester, remarks FROM teacher_remarks WHERE student_id = :sid AND school_year_id = :sy'
        );
        $remarksStmt->execute(['sid' => $studentId, 'sy' => $schoolYearId]);
        $remarksRows = $remarksStmt->fetchAll();
        $remarks = ['1' => '', '2' => '', '3' => ''];
        foreach ($remarksRows as $r) {
            $remarks[$r['trimester']] = $r['remarks'];
        }

        return [
            'student' => $student,
            'terms' => $termResults,
            'final' => [
                'subject_grades' => $finalSubjectGrades,
                'component_grades' => $finalComponents,
                'general_average' => $finalGeneralAverage,
                'rank' => $finalRank,
                'descriptor' => $finalDescriptor,
                'award' => $finalAward,
                'blocked_by_nlpa_term' => $anyNlpaTerm,
            ],
            'attendance' => $attendance,
            'remarks' => $remarks,
        ];
    }

    private static function finalGeneralAverageOnly(PDO $pdo, int $studentId, int $schoolYearId, array $bands): ?int
    {
        $gradesStmt = $pdo->prepare(
            'SELECT sub.subject_name, g.trimester, g.grade_value, g.status
             FROM grades g JOIN subjects sub ON sub.id = g.subject_id
             WHERE g.student_id = :sid AND g.school_year_id = :sy'
        );
        $gradesStmt->execute(['sid' => $studentId, 'sy' => $schoolYearId]);
        $rows = $gradesStmt->fetchAll();

        $raw = ['1' => [], '2' => [], '3' => []];
        foreach ($rows as $r) {
            $raw[$r['trimester']][$r['subject_name']] = [
                'value' => $r['grade_value'] !== null ? (float) $r['grade_value'] : null,
                'status' => $r['status'],
            ];
        }

        $finalSubjectGrades = [];
        foreach (GradeCalculator::REQUIRED_AREAS as $area) {
            $termVals = [];
            foreach (['1', '2', '3'] as $tri) {
                if ($area === 'MAPEH') {
                    $musicAndArts = $raw[$tri]['Music and Arts']['value'] ?? null;
                    $peAndHealth  = $raw[$tri]['Physical Education and Health']['value'] ?? null;
                    foreach (GradeCalculator::MAPEH_COMPONENTS as $c) {
                        if (($raw[$tri][$c]['status'] ?? '') === 'nlpa') {
                            return null;
                        }
                    }
                    $termVals[$tri] = GradeCalculator::mapeh($musicAndArts, $peAndHealth);
                } else {
                    if (($raw[$tri][$area]['status'] ?? '') === 'nlpa') {
                        return null;
                    }
                    $termVals[$tri] = $raw[$tri][$area]['value'] ?? null;
                }
            }
            $finalSubjectGrades[$area] = GradeCalculator::finalSubjectGrade($termVals);
        }

        return GradeCalculator::finalGeneralAverage($finalSubjectGrades);
    }
}
