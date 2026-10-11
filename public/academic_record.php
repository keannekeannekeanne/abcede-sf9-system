<?php
// Superseded by section.php (student list with View Grades / Edit Grades / Print SF9).
// Kept so existing bookmarks and links keep working.
$id = (int) ($_GET['section_id'] ?? 0);
header('Location: section.php?section_id=' . $id . '#students');
exit;
