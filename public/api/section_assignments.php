<?php
// Section -> Subject -> Assign/Remove Teacher (Priority 9). Reachable by the
// section's Adviser (their own section only) or an Admin (any section) —
// enforced here server-side via Access::requireSection(), never by the UI.
require_once __DIR__ . '/../../src/Http/Http.php';
Json::boot();
require_once __DIR__ . '/../../src/Auth/Auth.php';
require_once __DIR__ . '/../../src/Auth/Csrf.php';
require_once __DIR__ . '/../../src/Auth/Access.php';
require_once __DIR__ . '/../../src/Admin/AdminService.php';

Auth::requireLogin();
$method = Json::requireMethod('GET', 'POST');
$body = $method === 'POST' ? Json::body() : [];
if ($method === 'POST') {
    Csrf::validateOrFail(Csrf::fromRequest($body));
}

$sectionId = (int) ($method === 'GET' ? ($_GET['section_id'] ?? 0) : ($body['section_id'] ?? 0));
$section = Access::requireSection($sectionId);   // 403 unless adviser/assigned/admin

if (!$section['is_adviser'] && !$section['is_admin']) {
    throw new HttpException(403, 'Only the section adviser or an Administrator may assign subject teachers.');
}

if ($method === 'GET') {
    Json::ok([
        'assignments' => AdminService::assignmentsForSection($sectionId, (int) $section['school_year_id']),
        'teachers'    => AdminService::assignableTeachers(),
    ], 'Assignments loaded.');
}

$action = (string) ($body['action'] ?? 'assign');
if ($action === 'assign') {
    $r = AdminService::assignSubjectTeacher(
        $sectionId, (int) ($body['subject_id'] ?? 0), (int) ($body['teacher_id'] ?? 0),
        (int) $section['school_year_id'], Auth::userId()
    );
    Json::ok($r, $r['subject_name'] . ' teacher assigned.');
}
if ($action === 'remove') {
    AdminService::removeSubjectTeacher((int) ($body['assignment_id'] ?? 0), $sectionId);   // may only remove rows of THIS section
    Json::ok(null, 'Teacher removed from subject.');
}
throw new HttpException(422, 'Unknown action.');
