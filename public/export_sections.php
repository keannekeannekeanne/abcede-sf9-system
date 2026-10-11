<?php
// Export / backup of selected sections to Excel (Admin only). READ-ONLY: nothing is changed or deleted.
//   export_sections.php?section_ids=1,2,3
require_once __DIR__ . '/../src/Http/Http.php';
Page::boot();
require_once __DIR__ . '/../src/Auth/Auth.php';
require_once __DIR__ . '/../src/Export/SectionBackupService.php';

Auth::requireAdmin();                      // server-side role check — a teacher gets a 403 page
$ids = array_filter(array_map('intval', explode(',', (string) ($_GET['section_ids'] ?? ''))));
$who = get_db()->prepare('SELECT first_name, last_name FROM users WHERE id = ?');
$who->execute([Auth::userId()]);
$u = $who->fetch() ?: ['first_name' => 'Administrator', 'last_name' => ''];

$out = SectionBackupService::build($ids, trim($u['first_name'] . ' ' . $u['last_name']));
$out['workbook']->send($out['filename']);
