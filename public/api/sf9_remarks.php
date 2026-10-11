<?php
// JSON: POST { student_id, school_year_id, section_id, terms: [{trimester, remarks, section}, ...] }
// `section` is the remark Section (A-E: Outstanding, Very Satisfactory, Satisfactory, Fairly Satisfactory /
// Needs Improvement, Character and Values) the remark was written under; '' for a free-form remark.
// Saves the SF9 Teacher's Comments/Remarks for one student/year. The value
// may be a predefined comment or free text (requirement 5.2) — both arrive
// here the same way, as plain text.
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
$terms        = is_array($body['terms'] ?? null) ? $body['terms'] : [];

if ($studentId === 0 || $schoolYearId === 0 || $sectionId === 0) {
    throw new HttpException(422, 'student_id, school_year_id and section_id are required.');
}

$sec = Access::requireSection($sectionId);
if (!$sec['is_adviser'] && !$sec['is_admin'] && empty($sec['my_assignments'])) {
    throw new HttpException(403, 'You do not have access to edit this section.');
}
Access::requireEnrollment($sectionId, $studentId, $schoolYearId);

$actingUserId = Auth::userId();
$pdo = get_db();

// Remark Sections A-E (migration 004). A database that has not been migrated yet still saves the text as before.
$validSections = [];      // code => true
$presetSection = [];      // preset text => the Section it belongs to
try {
    foreach ($pdo->query('SELECT code FROM sf9_remark_sections')->fetchAll() as $r) { $validSections[$r['code']] = true; }
    foreach ($pdo->query('SELECT comment_text, section_code FROM sf9_comment_options WHERE section_code IS NOT NULL')->fetchAll() as $r) { $presetSection[$r['comment_text']] = $r['section_code']; }
} catch (PDOException $e) {
    $validSections = [];
}

$rows = [];
foreach ($terms as $t) {
    $trimester = (string) ($t['trimester'] ?? '');
    if (!in_array($trimester, ['1', '2', '3'], true)) {
        continue;
    }
    $remarks = trim((string) ($t['remarks'] ?? ''));
    if (mb_strlen($remarks) > 2000) {
        throw new HttpException(422, 'A comment is too long (2000 characters max).');
    }
    $sectionCode = strtoupper(trim((string) ($t['section'] ?? '')));
    if ($remarks === '' || $sectionCode === '') {
        $sectionCode = null;                                   // nothing written, or a free-form remark
    } elseif ($validSections && !isset($validSections[$sectionCode])) {
        throw new HttpException(422, 'Select Section A, B, C, D or E for the remark.');
    }
    // A ready-made remark must be saved under its own Section — never under another one.
    if ($sectionCode !== null && isset($presetSection[$remarks]) && $presetSection[$remarks] !== $sectionCode) {
        throw new HttpException(422, 'Term ' . $trimester . ': that remark belongs to Section ' . $presetSection[$remarks] . ', not Section ' . $sectionCode . '. Choose a remark from Section ' . $sectionCode . ' or switch the Section.');
    }
    $rows[] = [$trimester, $remarks !== '' ? $remarks : null, $validSections ? $sectionCode : null];
}

$pdo->beginTransaction();
try {
    if ($validSections) {
        $upsert = $pdo->prepare(
            'INSERT INTO teacher_remarks (student_id, school_year_id, trimester, remarks, remark_section, written_by)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE remarks = VALUES(remarks), remark_section = VALUES(remark_section), written_by = VALUES(written_by)'
        );
        foreach ($rows as [$trimester, $remarks, $sectionCode]) {
            $upsert->execute([$studentId, $schoolYearId, $trimester, $remarks, $sectionCode, $actingUserId]);
        }
    } else {
        $upsert = $pdo->prepare(
            'INSERT INTO teacher_remarks (student_id, school_year_id, trimester, remarks, written_by)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE remarks = VALUES(remarks), written_by = VALUES(written_by)'
        );
        foreach ($rows as [$trimester, $remarks]) {
            $upsert->execute([$studentId, $schoolYearId, $trimester, $remarks, $actingUserId]);
        }
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

Json::ok(null, 'Comments saved.');
