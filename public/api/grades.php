<?php
// JSON: GET grade sheet for one assignment+trimester, POST save grades (all-or-nothing).
// Every assignment, including "Music and Arts" and "Physical Education and
// Health", is a regular single subject: one grade_value per student.
// MAPEH itself is never entered or saved — it's computed on the fly as the
// average of those two subjects (see GradeCalculator::mapeh()).
require_once __DIR__ . '/../../src/Http/Http.php';
Json::boot();   // must come first: from here on only JSON can leave this script

require_once __DIR__ . '/../../src/Auth/Auth.php';
require_once __DIR__ . '/../../src/Auth/Csrf.php';
require_once __DIR__ . '/../../src/Auth/Access.php';
require_once __DIR__ . '/../../src/Grades/GradeService.php';

Auth::requireLogin();
$method = Json::requireMethod('GET', 'POST');

if ($method === 'GET') {
    $assignmentId = (int) ($_GET['assignment_id'] ?? 0);
    $trimester    = (string) ($_GET['trimester'] ?? '1');
    if (!in_array($trimester, ['1', '2', '3'], true)) {
        throw new HttpException(422, 'Invalid term.');
    }

    $assignment = Auth::assertOwnsAssignment($assignmentId);

    $rows = GradeService::getGradeSheet(
        (int) $assignment['section_id'], (int) $assignment['subject_id'],
        (int) $assignment['school_year_id'], $trimester
    );
    Json::ok(['assignment' => $assignment, 'trimester' => $trimester, 'students' => $rows], 'Grade sheet loaded.');
}

// POST
$body = Json::body();
Csrf::validateOrFail(Csrf::fromRequest($body));

$assignmentId = (int) ($body['assignment_id'] ?? 0);
$assignment   = Auth::assertOwnsAssignment($assignmentId);   // teacher must own section+subject
$trimester    = (string) ($body['trimester'] ?? '');
$rows         = $body['grades'] ?? [];

if (!is_array($rows) || !$rows) {
    throw new HttpException(422, 'No grades submitted.');
}

// Only students actually enrolled in this section can be graded here.
$roster = array_flip(Access::activeRosterIds((int) $assignment['section_id'], (int) $assignment['school_year_id']));

$errors = [];
$items  = [];
foreach ($rows as $row) {
    if (!is_array($row)) {
        throw new HttpException(422, 'Malformed grade row.');
    }
    $studentId = (int) ($row['student_id'] ?? 0);
    $status    = (string) ($row['status'] ?? 'graded');
    $subjectId = (int) $assignment['subject_id'];

    if (!isset($roster[$studentId])) {
        $errors[] = ['student_id' => $studentId, 'subject_id' => $subjectId, 'message' => 'Student is not enrolled in this section.'];
        continue;
    }
    try {
        [$value, $status] = GradeService::normalize($trimester, $row['grade_value'] ?? null, $status);
    } catch (InvalidArgumentException $e) {
        $errors[] = ['student_id' => $studentId, 'subject_id' => $subjectId, 'message' => $e->getMessage()];
        continue;
    }
    $items[] = [
        'student_id' => $studentId, 'subject_id' => $subjectId,
        'section_id' => (int) $assignment['section_id'], 'school_year_id' => (int) $assignment['school_year_id'],
        'trimester' => $trimester, 'teacher_assignment_id' => $assignmentId,
        'grade_value' => $value, 'status' => $status,
    ];
}

if ($errors) {
    Json::fail('Some grades are invalid. Nothing was saved.', 422, ['errors' => $errors]);
}

try {
    GradeService::saveBatch($items, Auth::userId());
} catch (GradeLockedException $e) {
    throw new HttpException(409, $e->getMessage());
}

Json::ok(['saved' => count($items)], count($items) . ' grade(s) saved.');
