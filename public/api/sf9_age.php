<?php
// JSON: POST { student_id, school_year_id, section_id, age }   age = whole number, or null / '' to go back to the calculated age
//
// The age printed on the SF9 is calculated from the learner's birth date (unchanged). A teacher can override it
// FOR THIS SF9 ONLY: the value is stored on the learner's ENROLLMENT for that school year
// (enrollments.sf9_age_override), so it can never change another learner, another school year, or students.birthdate.
require_once __DIR__ . '/../../src/Http/Http.php';
Json::boot();

require_once __DIR__ . '/../../src/Auth/Auth.php';
require_once __DIR__ . '/../../src/Auth/Csrf.php';
require_once __DIR__ . '/../../src/Auth/Access.php';

Auth::requireLogin();
Json::requireMethod('POST');
$body = Json::body();
Csrf::validateOrFail(Csrf::fromRequest($body));

$studentId    = (int) ($body['student_id'] ?? 0);
$schoolYearId = (int) ($body['school_year_id'] ?? 0);
$sectionId    = (int) ($body['section_id'] ?? 0);
if ($studentId === 0 || $schoolYearId === 0 || $sectionId === 0) {
    throw new HttpException(422, 'student_id, school_year_id and section_id are required.');
}

// Same rule as the other SF9 edits (attendance, remarks): adviser, teacher of a subject in the section, or Admin.
$sec = Access::requireSection($sectionId);
if (!$sec['is_adviser'] && !$sec['is_admin'] && empty($sec['my_assignments'])) {
    throw new HttpException(403, 'You do not have access to edit this section.');
}
Access::requireEnrollment($sectionId, $studentId, $schoolYearId);

$raw = $body['age'] ?? null;
$age = null;
if ($raw !== null && trim((string) $raw) !== '') {
    if (!is_numeric($raw) || (string) (int) $raw !== trim((string) $raw)) {
        throw new HttpException(422, 'Age must be a whole number.', ['errors' => ['age' => 'Age must be a whole number.']]);
    }
    $age = (int) $raw;
    if ($age < 3 || $age > 30) {
        throw new HttpException(422, 'Age must be between 3 and 30.', ['errors' => ['age' => 'Age must be between 3 and 30.']]);
    }
}

try {
    $stmt = get_db()->prepare('UPDATE enrollments SET sf9_age_override = ? WHERE student_id = ? AND school_year_id = ? AND section_id = ?');
    $stmt->execute([$age, $studentId, $schoolYearId, $sectionId]);
} catch (PDOException $e) {
    error_log('[sf9] sf9_age_override unavailable: ' . $e->getMessage());
    throw new HttpException(500, 'The custom-age setting is not available yet. Apply migrations/004_grade_assignment_force_delete_remarks_age.sql first.');
}

Json::ok(['age' => $age], $age === null ? 'Age reset — the SF9 will use the age calculated from the birth date.' : 'Age saved for this SF9.');
