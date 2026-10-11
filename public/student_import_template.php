<?php
// Download the Excel template for "Import Data" (Admins and section advisers).
require_once __DIR__ . '/../src/Http/Http.php';
Page::boot();
require_once __DIR__ . '/../src/Auth/Auth.php';
require_once __DIR__ . '/../src/Import/StudentImportService.php';
Auth::requireLogin();
if (!Auth::isAdmin()) {
    $st = get_db()->prepare('SELECT 1 FROM sections WHERE adviser_id = ? LIMIT 1');
    $st->execute([Auth::userId()]);
    if (!$st->fetchColumn()) { http_response_code(403); exit('Only Admins and section advisers can download this template.'); }
}
StudentImportService::buildTemplate()->send('student-import-template.xlsx');
