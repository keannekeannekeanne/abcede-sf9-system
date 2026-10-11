<?php
// My Classes -> Create Section. Any logged-in teacher may create a section
// and becomes its adviser automatically (Priority 7). Admins should use the
// Admin Panel, which can also set a different adviser.
require_once __DIR__ . '/../../src/Http/Http.php';
Json::boot();
require_once __DIR__ . '/../../src/Auth/Auth.php';
require_once __DIR__ . '/../../src/Auth/Csrf.php';
require_once __DIR__ . '/../../src/Admin/AdminService.php';

Auth::requireLogin();
Json::requireMethod('POST');
$body = Json::body();
Csrf::validateOrFail(Csrf::fromRequest($body));

// A teacher can only ever make THEMSELVES the adviser here — never someone else.
$body['adviser_id'] = Auth::userId();

Json::ok(AdminService::createSection($body, Auth::userId()), 'Section created. You are the adviser.');
