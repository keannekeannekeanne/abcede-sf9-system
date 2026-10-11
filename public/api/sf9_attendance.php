<?php
// JSON: POST { student_id, school_year_id, section_id, rows: [{month, days_of_class, days_present, days_absent}, ...] }
// Saves the SF9 Attendance Record for one student/year. Requirement 5.1:
// the attendance values must be configurable/editable, never hard-coded.
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
$rows         = is_array($body['rows'] ?? null) ? $body['rows'] : [];

if ($studentId === 0 || $schoolYearId === 0 || $sectionId === 0) {
    throw new HttpException(422, 'student_id, school_year_id and section_id are required.');
}

// Same access rule as viewing the SF9 itself: adviser, an assigned subject
// teacher of the section, or an Admin. Never trust the client.
$sec = Access::requireSection($sectionId);
if (!$sec['is_adviser'] && !$sec['is_admin'] && empty($sec['my_assignments'])) {
    throw new HttpException(403, 'You do not have access to edit this section.');
}
Access::requireEnrollment($sectionId, $studentId, $schoolYearId);

$validMonths = ['Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec', 'Jan', 'Feb', 'Mar', 'Apr'];
$validFields = ['days_of_class', 'days_present', 'days_absent'];

$normalized = [];
foreach ($rows as $row) {
    $month = (string) ($row['month'] ?? '');
    if (!in_array($month, $validMonths, true)) {
        continue;
    }
    $values = [];
    $anyValue = false;
    foreach ($validFields as $field) {
        $raw = $row[$field] ?? null;
        if ($raw === null || $raw === '') {
            $values[$field] = 0;
            continue;
        }
        if (!is_numeric($raw) || (int) $raw < 0 || (int) $raw > 31) {
            throw new HttpException(422, 'Each attendance value must be a number from 0 to 31.', [
                'errors' => ['month' => $month, 'field' => $field, 'message' => 'Enter a number from 0 to 31.'],
            ]);
        }
        $values[$field] = (int) $raw;
        $anyValue = true;
    }
    // A month left entirely blank stays blank (no row written) — never
    // silently recorded as a zero. Once any of its three fields has a
    // real value, the row is written (the other two default to 0).
    if ($anyValue) {
        $normalized[$month] = $values;
    }
}

$pdo = get_db();
$pdo->beginTransaction();
try {
    $upsert = $pdo->prepare(
        'INSERT INTO attendance_monthly (student_id, section_id, school_year_id, month, days_of_class, days_present, days_absent)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            section_id = VALUES(section_id),
            days_of_class = VALUES(days_of_class),
            days_present = VALUES(days_present),
            days_absent = VALUES(days_absent)'
    );
    foreach ($normalized as $month => $v) {
        $upsert->execute([$studentId, $sectionId, $schoolYearId, $month, $v['days_of_class'], $v['days_present'], $v['days_absent']]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

Json::ok(null, 'Attendance saved.');
