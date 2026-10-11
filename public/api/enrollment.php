<?php
// JSON: GET ?action=search&section_id=&q=   |   POST {action: enroll|register|enroll_many|register_many, section_id, ...}
require_once __DIR__ . '/../../src/Http/Http.php';
Json::boot();

require_once __DIR__ . '/../../src/Auth/Auth.php';
require_once __DIR__ . '/../../src/Auth/Csrf.php';
require_once __DIR__ . '/../../src/Auth/Access.php';
require_once __DIR__ . '/../../src/Enrollment/EnrollmentService.php';
require_once __DIR__ . '/../../src/Http/Batch.php';

Auth::requireLogin();
$method = Json::requireMethod('GET', 'POST');

if ($method === 'GET') {
    $section = Access::requireSection((int) ($_GET['section_id'] ?? 0));
    $results = EnrollmentService::search($section, (string) ($_GET['q'] ?? ''));
    Json::ok(['results' => $results], count($results) . ' learner(s) found.');
}

$body = Json::body();
Csrf::validateOrFail(Csrf::fromRequest($body));
$section = Access::requireSection((int) ($body['section_id'] ?? 0));
$action  = (string) ($body['action'] ?? '');

if ($action === 'enroll') {
    $r = EnrollmentService::enroll($section, (int) ($body['student_id'] ?? 0));
    Json::ok($r, $r['status'] === 'reactivated' ? 'Learner re-activated in this section.' : 'Learner enrolled successfully.', 201);
}
if ($action === 'register') {
    $r = EnrollmentService::register($section, is_array($body['student'] ?? null) ? $body['student'] : []);
    Json::ok($r, 'New learner registered and enrolled.', 201);
}
if ($action === 'register_many') {
    $r = EnrollmentService::registerMany($section, Batch::rows($body['students'] ?? []));
    Json::ok($r, Batch::plural($r['added'], 'learner') . ' added' . ($r['failed'] ? ', ' . $r['failed'] . ' could not be added.' : '.'), 201);
}
if ($action === 'enroll_many') {
    $r = EnrollmentService::enrollMany($section, Batch::ids($body['student_ids'] ?? []));
    Json::ok($r, Batch::plural($r['added'], 'learner') . ' enrolled' . ($r['failed'] ? ', ' . $r['failed'] . ' could not be enrolled.' : '.'), 201);
}
throw new HttpException(422, 'Unknown action.');
