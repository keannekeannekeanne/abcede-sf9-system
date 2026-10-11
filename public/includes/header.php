<?php
require_once __DIR__ . '/../../src/Http/Http.php';
require_once __DIR__ . '/../../src/Auth/Auth.php';
require_once __DIR__ . '/../../src/Auth/Csrf.php';
Page::boot();
Auth::requireLogin();
if (Auth::mustChangePassword() && basename($_SERVER['SCRIPT_NAME']) !== 'change_password.php') {
    header('Location: change_password.php');
    exit;
}
$activePage = $activePage ?? '';
$useCharts  = $useCharts ?? false;
$initials   = strtoupper(mb_substr(trim(Auth::fullName()), 0, 1)) ?: '?';
$roleLabel  = ucwords(str_replace('_', ' ', Auth::role()));
$navItems   = [
    'dashboard'  => ['dashboard.php', 'bi-grid-1x2', 'Dashboard'],
    // Admins manage/view every section system-wide rather than a personal
    // teaching load, so the label reflects that (requirement 3.1).
    'my_classes' => ['my_classes.php', 'bi-collection', Auth::isAdmin() ? 'View Sections' : 'My Classes'],
];
if (Auth::isAdmin()) {
    $navItems['admin'] = ['admin.php', 'bi-shield-lock', 'Admin Panel'];
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
  <title><?= htmlspecialchars($pageTitle ?? 'SF9 System') ?> · SF9 System</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="assets/img/abcede-logo.jpg" rel="icon">
  <link href="assets/css/app.css" rel="stylesheet">
  <script src="assets/js/app.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" defer></script>
<?php if ($useCharts): ?>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js" defer></script>
<?php endif; ?>
</head>
<body>
<div class="app-shell">
  <aside class="offcanvas-lg offcanvas-start app-sidebar" tabindex="-1" id="appSidebar" aria-label="Main navigation">
    <div class="offcanvas-header d-lg-none">
      <span class="fw-bold">Menu</span>
      <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#appSidebar" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body p-0">
      <div class="sidebar-inner">
        <a class="brand" href="dashboard.php">
          <span class="brand-mark brand-mark-img"><img src="assets/img/school-seal.png" alt="" width="32" height="32"></span>
          <span>SF9 System<small>Grades &amp; report cards</small></span>
        </a>
        <nav class="side-nav">
          <?php foreach ($navItems as $key => [$href, $icon, $label]): ?>
            <a class="side-link <?= $activePage === $key ? 'active' : '' ?>" href="<?= $href ?>"<?= $activePage === $key ? ' aria-current="page"' : '' ?>>
              <i class="bi <?= $icon ?>"></i><?= $label ?>
            </a>
          <?php endforeach; ?>
        </nav>
        <div class="side-user">
          <div class="avatar"><?= htmlspecialchars($initials) ?></div>
          <div class="who"><strong><?= htmlspecialchars(Auth::fullName()) ?></strong><span><?= htmlspecialchars($roleLabel) ?></span></div>
          <a class="btn btn-sm btn-outline-secondary ms-auto" href="account.php" title="Account Settings" aria-label="Account Settings"><i class="bi bi-gear"></i></a>
          <a class="btn btn-sm btn-outline-secondary" href="logout.php" title="Log out" aria-label="Log out"><i class="bi bi-box-arrow-right"></i></a>
        </div>
      </div>
    </div>
  </aside>

  <div class="app-content">
    <header class="app-topbar d-lg-none">
      <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="offcanvas" data-bs-target="#appSidebar" aria-controls="appSidebar" aria-label="Open menu"><i class="bi bi-list fs-5"></i></button>
      <strong><?= htmlspecialchars($pageTitle ?? 'SF9 System') ?></strong>
    </header>
    <main class="app-main">
