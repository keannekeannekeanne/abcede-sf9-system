<?php
// Grading Summary: the section's three-term grading sheet (1st / 2nd / 3rd Term + Final Summary) as an Excel download.
//   grading_summary.php?section_id=12
// READ-ONLY: nothing is changed in the database. Same access rule as the section page, the SF9 and the temporary
// report cards: an admin, the section's adviser, or a teacher assigned to a subject in it.
require_once __DIR__ . '/../src/Http/Http.php';
Page::boot();
require_once __DIR__ . '/../src/Auth/Auth.php';
require_once __DIR__ . '/../src/Auth/Access.php';
require_once __DIR__ . '/../src/SF9/TempReportCardService.php';
require_once __DIR__ . '/../src/Export/GradingSummaryService.php';

Auth::requireLogin();
$section = Access::requireSection((int) ($_GET['section_id'] ?? 0));   // 403 unless allowed

$data = GradingSummaryService::collect($section, TempReportCardService::principalName());
if (!$data['learners']) {
    throw new HttpException(422, 'This section has no active learners yet, so there is nothing to summarize.');
}
GradingSummaryService::build($data)->send(GradingSummaryService::filename($data));
