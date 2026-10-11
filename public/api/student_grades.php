<?php
// JSON: GET ?section_id=&student_id=  -> one student's trimester grades
//       POST {section_id, student_id, changes:[{subject_id, trimester, grade_value, status}]}
require_once __DIR__ . '/../../src/Http/Http.php';
Json::boot();

require_once __DIR__ . '/../../src/Auth/Auth.php';
require_once __DIR__ . '/../../src/Auth/Csrf.php';
require_once __DIR__ . '/../../src/Auth/Access.php';
require_once __DIR__ . '/../../src/Grades/GradeService.php';
require_once __DIR__ . '/../../src/Grades/StudentGradeView.php';

Auth::requireLogin();
$method = Json::requireMethod('GET', 'POST');

$body = $method === 'POST' ? Json::body() : [];
if ($method === 'POST') {
    Csrf::validateOrFail(Csrf::fromRequest($body));
}
$src       = $method === 'POST' ? $body : $_GET;
$section   = Access::requireSection((int) ($src['section_id'] ?? 0));            // 403 unless adviser/assigned
$studentId = (int) ($src['student_id'] ?? 0);
$yearId    = (int) $section['school_year_id'];
$enrolment = Access::requireEnrollment((int) $section['id'], $studentId, $yearId); // 404 unless in this section

if ($method === 'GET') {
    Json::ok(StudentGradeView::build($section, $enrolment), 'Grades loaded.');
}

// POST: save edits
if ($enrolment['status'] !== 'active') {
    throw new HttpException(409, 'Grades can only be edited for actively enrolled students.');
}
$changes = $body['changes'] ?? [];
if (!is_array($changes) || !$changes) {
    throw new HttpException(422, 'No changes to save.');
}
if (count($changes) > 60) {
    throw new HttpException(422, 'Too many changes in one request.');
}

$mine   = Auth::isAdmin()
    ? Access::allEditableSubjectIds((int) $section['id'], $yearId)
    : Access::editableSubjectIds(Auth::userId(), (int) $section['id'], $yearId);
$items  = [];
$errors = [];
foreach ($changes as $c) {
    if (!is_array($c)) {
        throw new HttpException(422, 'Malformed change.');
    }
    $subjectId = (int) ($c['subject_id'] ?? 0);
    $tri       = (string) ($c['trimester'] ?? '');
    if (!isset($mine[$subjectId])) {
        throw new HttpException(403, 'You are not assigned to one of the subjects you tried to edit.');
    }
    try {
        [$value, $status] = GradeService::normalize($tri, $c['grade_value'] ?? null, (string) ($c['status'] ?? 'graded'));
    } catch (InvalidArgumentException $e) {
        $errors[] = ['subject_id' => $subjectId, 'trimester' => $tri, 'message' => $e->getMessage()];
        continue;
    }
    $items[] = [
        'student_id' => $studentId, 'subject_id' => $subjectId, 'section_id' => (int) $section['id'],
        'school_year_id' => $yearId, 'trimester' => $tri, 'teacher_assignment_id' => $mine[$subjectId],
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

// Re-read so the response already contains the recomputed MAPEH / averages.
$fresh = StudentGradeView::build($section, $enrolment);
$fresh['saved'] = count($items);
Json::ok($fresh, count($items) . ' grade(s) saved.');
