<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../Calculations/GradeCalculator.php';
require_once __DIR__ . '/../SF9/SF9DataService.php';
require_once __DIR__ . '/../Auth/Auth.php';
require_once __DIR__ . '/../Auth/Access.php';

/**
 * One student's grades for the "Check Grades" / "Edit Grades" screen, in the
 * SF9's trimester layout. Numbers that are computed (MAPEH, General Average,
 * Final Grade, descriptor, rank) come from SF9DataService / GradeCalculator so
 * this screen can never disagree with the printed SF9.
 */
final class StudentGradeView
{
    /**
     * @param array $section   from Access::requireSection()
     * @param array $enrollment from Access::requireEnrollment()
     */
    public static function build(array $section, array $enrollment): array
    {
        $pdo       = get_db();
        $studentId = (int) $enrollment['student_id'];
        $yearId    = (int) $section['school_year_id'];
        $userId    = Auth::userId();
        $mine      = Auth::isAdmin()
            ? Access::allEditableSubjectIds((int) $section['id'], $yearId)
            : Access::editableSubjectIds($userId, (int) $section['id'], $yearId);

        $subjects = [];
        foreach ($pdo->query('SELECT id, subject_name FROM subjects')->fetchAll() as $s) {
            $subjects[$s['subject_name']] = (int) $s['id'];
        }

        $ta = $pdo->prepare(
            'SELECT ta.subject_id, u.first_name, u.last_name
             FROM teacher_assignments ta JOIN users u ON u.id = ta.teacher_id
             WHERE ta.section_id = ? AND ta.school_year_id = ?'
        );
        $ta->execute([(int) $section['id'], $yearId]);
        $teachers = [];
        foreach ($ta->fetchAll() as $r) {
            $teachers[(int) $r['subject_id']][] = trim($r['first_name'] . ' ' . $r['last_name']);
        }

        $g = $pdo->prepare('SELECT subject_id, trimester, grade_value, status, is_locked FROM grades WHERE student_id = ? AND school_year_id = ?');
        $g->execute([$studentId, $yearId]);
        $cells = [];   // cells[subject_id][tri]
        foreach ($g->fetchAll() as $r) {
            $cells[(int) $r['subject_id']][$r['trimester']] = [
                'value'  => $r['grade_value'] !== null ? (float) $r['grade_value'] : null,
                'status' => $r['status'],
                'locked' => (bool) $r['is_locked'],
            ];
        }

        $sf9 = SF9DataService::buildForStudent($studentId, $yearId);

        $teacherFor = static function (array $names) use ($subjects, $teachers): string {
            $out = [];
            foreach ($names as $n) {
                foreach ($teachers[$subjects[$n] ?? 0] ?? [] as $t) {
                    $out[$t] = true;
                }
            }
            return $out ? implode(', ', array_keys($out)) : '—';
        };
        $subjectRow = static function (string $name, string $kind) use ($subjects, $cells, $mine, $teachers, $sf9): array {
            $id = $subjects[$name] ?? null;
            $terms = [];
            foreach (['1', '2', '3'] as $t) {
                $c = $id ? ($cells[$id][$t] ?? null) : null;
                $terms[$t] = $c ?? ['value' => null, 'status' => null, 'locked' => false];
            }
            $final = $kind === 'component'
                ? ($sf9['final']['component_grades'][$name] ?? null)
                : ($sf9['final']['subject_grades'][$name] ?? null);
            return [
                'key' => $name, 'label' => $name, 'kind' => $kind, 'subject_id' => $id,
                'teacher' => $id ? (implode(', ', $teachers[$id] ?? []) ?: '—') : '—',
                'editable' => $id !== null && isset($mine[$id]),
                'terms' => $terms, 'final' => $final,
            ];
        };

        $rows = [];
        foreach (GradeCalculator::REQUIRED_AREAS as $area) {
            if ($area === 'MAPEH') {
                $terms = [];
                foreach (['1', '2', '3'] as $t) {
                    $terms[$t] = ['value' => $sf9['terms'][$t]['area_grades']['MAPEH'] ?? null, 'status' => null, 'locked' => false];
                }
                $rows[] = [
                    'key' => 'MAPEH', 'label' => 'MAPEH', 'kind' => 'computed', 'subject_id' => null,
                    'teacher' => $teacherFor(GradeCalculator::MAPEH_COMPONENTS),
                    'editable' => false, 'terms' => $terms, 'final' => $sf9['final']['subject_grades']['MAPEH'] ?? null,
                ];
                foreach (GradeCalculator::MAPEH_COMPONENTS as $comp) {
                    $rows[] = $subjectRow($comp, 'component');
                }
                continue;
            }
            $rows[] = $subjectRow($area, 'subject');
        }

        $summary = ['general_average' => [], 'descriptor' => []];
        foreach (['1', '2', '3'] as $t) {
            $summary['general_average'][$t] = $sf9['terms'][$t]['general_average'];
            $summary['descriptor'][$t]      = $sf9['terms'][$t]['descriptor'];
        }
        $summary['general_average']['final'] = $sf9['final']['general_average'];
        $summary['descriptor']['final']      = $sf9['final']['descriptor'];

        $canEdit = false;
        foreach ($rows as $r) {
            $canEdit = $canEdit || $r['editable'];
        }

        return [
            'student' => [
                'id' => $studentId, 'lrn' => $enrollment['lrn'],
                'name' => trim($enrollment['last_name'] . ', ' . $enrollment['first_name'] . ' ' . ($enrollment['middle_name'] ?? '')),
                'gender' => $enrollment['gender'], 'status' => $enrollment['status'],
            ],
            'section' => [
                'id' => (int) $section['id'], 'label' => 'Grade ' . $section['grade_level'] . '-' . $section['section_name'],
                'school_year' => $section['year_label'], 'school_year_id' => $yearId, 'adviser' => $section['adviser_name'],
            ],
            'rows'      => $rows,
            'summary'   => $summary,
            'rank'      => $sf9['final']['rank'],
            'award'     => $sf9['final']['award'],
            'blocked_by_nlpa_term' => $sf9['final']['blocked_by_nlpa_term'],
            'can_edit'  => $canEdit,
        ];
    }
}
