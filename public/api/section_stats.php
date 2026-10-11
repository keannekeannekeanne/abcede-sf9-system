<?php
// JSON: GET ?section_id=&term=1|2|3|final  -> class performance for Chart.js
require_once __DIR__ . '/../../src/Http/Http.php';
Json::boot();

require_once __DIR__ . '/../../src/Auth/Auth.php';
require_once __DIR__ . '/../../src/Auth/Access.php';
require_once __DIR__ . '/../../src/Analytics/SectionAnalytics.php';

Auth::requireLogin();
Json::requireMethod('GET');

$section = Access::requireSection((int) ($_GET['section_id'] ?? 0));
$stats   = SectionAnalytics::forSection($section, (string) ($_GET['term'] ?? '1'));
Json::ok($stats, 'Section statistics loaded.');
