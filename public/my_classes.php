<?php
require_once __DIR__ . '/../src/Http/Http.php';
Page::boot();
require_once __DIR__ . '/../src/Auth/Access.php';
Auth::requireLogin();

// Admins manage/view every section system-wide, so this page becomes
// "View Sections" grouped by grade level for them (requirements 3.1/3.2);
// teachers keep the familiar "My Classes" grouped by school year.
$isAdmin    = Auth::isAdmin();
$pageTitle  = $isAdmin ? 'View Sections' : 'My Classes';
$activePage = 'my_classes';
$sections   = Access::sectionsFor(Auth::userId());

if ($isAdmin) {
    // Group current-year sections by Grade 4 / 5 / 6 (spec's exact layout);
    // past-year sections stay grouped by year underneath, also by grade.
    $current = array_values(array_filter($sections, fn($s) => $s['year_active']));
    $past    = array_values(array_filter($sections, fn($s) => !$s['year_active']));
    $byGrade = [];
    foreach ($current as $s) {
        $byGrade[$s['grade_level']][] = $s;
    }
    ksort($byGrade);
    $pastByYear = [];
    foreach ($past as $s) {
        $pastByYear[$s['year_label']][] = $s;
    }
} else {
    $byYear = [];
    foreach ($sections as $s) {
        $byYear[$s['year_label']][] = $s;
    }
}
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div>
    <h1><?= $isAdmin ? 'View Sections' : 'My Classes' ?></h1>
    <div class="sub"><?= $isAdmin ? 'Every section, grouped by grade level.' : 'Open a section to enroll students, check or edit grades and print SF9.' ?></div>
  </div>
  <div class="d-flex gap-2">
    <?php if ($sections): ?>
      <input type="search" id="classFilter" class="form-control" style="max-width:280px" placeholder="Search sections…" aria-label="Search sections">
    <?php endif; ?>
    <?php if ($isAdmin): ?>
      <a class="btn btn-primary" href="admin.php"><i class="bi bi-plus-lg me-1"></i>Create Section (Admin Panel)</a>
    <?php else: ?>
      <button class="btn btn-primary" id="btnCreateSection"><i class="bi bi-plus-lg me-1"></i>Create Section</button>
    <?php endif; ?>
  </div>
</div>

<?php if (!$sections): ?>
  <div class="surface"><div class="empty-state"><i class="bi bi-inbox"></i><strong>No classes assigned yet</strong>Contact your administrator to be assigned a section.</div></div>
<?php endif; ?>

<?php
// Renders one card. $showDelete adds an admin-only Delete Section action
// (requirement 3.3), always with a confirmation dialog.
function render_section_card(array $s, string $year, bool $showDelete): void {
    $name = 'Grade ' . $s['grade_level'] . ' – ' . $s['section_name'];
?>
      <article class="surface section-card" data-sec-id="<?= (int) $s['id'] ?>" data-search="<?= htmlspecialchars(strtolower($name . ' ' . $year . ' ' . $s['adviser_name'])) ?>">
        <div class="d-flex justify-content-between align-items-start gap-2">
          <?php if ($showDelete): ?><input type="checkbox" class="form-check-input bulk-check mt-1" value="<?= (int) $s['id'] ?>" data-label="<?= htmlspecialchars($name . ' (' . $year . ')', ENT_QUOTES) ?>" aria-label="Select <?= htmlspecialchars($name, ENT_QUOTES) ?>"><?php endif; ?>
          <h3 class="flex-grow-1"><?= htmlspecialchars($name) ?></h3>
          <?php if ($s['is_adviser']): ?><span class="pill pill-primary">Adviser</span><?php endif; ?>
        </div>
        <div class="meta">
          <span><i class="bi bi-people"></i><?= $s['student_count'] ?> enrolled student<?= $s['student_count'] === 1 ? '' : 's' ?></span>
          <span><i class="bi bi-person-badge"></i>Adviser: <?= htmlspecialchars($s['adviser_name'] ?: 'Not set') ?></span>
          <?php if (!$showDelete): ?><span><i class="bi bi-journal-text"></i><?= $s['my_subject_count'] ?> subject<?= $s['my_subject_count'] === 1 ? '' : 's' ?> assigned to you</span><?php endif; ?>
        </div>
        <div class="d-flex gap-2 mt-auto">
          <a class="btn btn-outline-primary flex-grow-1" href="section.php?section_id=<?= $s['id'] ?>">View Section</a>
          <?php if ($showDelete): ?>
            <button type="button" class="btn btn-outline-danger" data-delsec="<?= $s['id'] ?>" data-secname="<?= htmlspecialchars($name, ENT_QUOTES) ?>" title="Delete section"><i class="bi bi-trash"></i></button>
            <button type="button" class="btn btn-danger" data-fdsec="<?= $s['id'] ?>" title="Force Delete: also removes enrollments, grades and attendance"><i class="bi bi-trash3-fill me-1"></i>Force Delete</button>
          <?php endif; ?>
        </div>
      </article>
<?php
}
?>

<?php if ($isAdmin): ?>
  <div class="surface mb-3" id="cardBulkWrap">
    <div id="cardBulk"></div>
    <label class="form-check small m-2 ms-4"><input type="checkbox" class="form-check-input bulk-all" id="cardAll"> <span class="form-check-label">Select all sections shown</span></label>
  </div>
  <?php if ($current): ?>
    <h2 class="year-heading">School year <?= htmlspecialchars($current[0]['year_label']) ?> <span class="pill pill-ok ms-1">Current</span></h2>
    <?php foreach ($byGrade as $grade => $list): ?>
      <h3 class="h6 mt-3 mb-2">Grade <?= (int) $grade ?></h3>
      <div class="section-grid">
        <?php foreach ($list as $s) render_section_card($s, $s['year_label'], true); ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
  <?php foreach ($pastByYear as $year => $list): ?>
    <h2 class="year-heading mt-4">School year <?= htmlspecialchars($year) ?> <span class="pill ms-1">Past</span></h2>
    <?php
      $g = [];
      foreach ($list as $s) { $g[$s['grade_level']][] = $s; }
      ksort($g);
    ?>
    <?php foreach ($g as $grade => $glist): ?>
      <h3 class="h6 mt-3 mb-2">Grade <?= (int) $grade ?></h3>
      <div class="section-grid">
        <?php foreach ($glist as $s) render_section_card($s, $year, true); ?>
      </div>
    <?php endforeach; ?>
  <?php endforeach; ?>
<?php else: ?>
  <?php foreach ($byYear as $year => $list): ?>
    <h2 class="year-heading">School year <?= htmlspecialchars($year) ?> <?= $list[0]['year_active'] ? '<span class="pill pill-ok ms-1">Current</span>' : '<span class="pill ms-1">Past</span>' ?></h2>
    <div class="section-grid">
      <?php foreach ($list as $s) render_section_card($s, $year, false); ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<div id="noMatch" class="surface mt-3" hidden><div class="empty-state"><i class="bi bi-search"></i><strong>No sections match your search</strong></div></div>

<!-- Create Section modal -->
<div class="modal fade" id="csModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header"><h2 class="modal-title h5">Create Section</h2><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <div id="csErr" class="alert alert-danger" hidden></div>
    <div class="row g-2">
      <div class="col-6">
        <label class="form-label small">Grade Level</label>
        <select class="form-select" id="csGrade"><option value="4">Grade 4</option><option value="5">Grade 5</option><option value="6">Grade 6</option></select>
      </div>
      <div class="col-6"><label class="form-label small">Section Name</label><input class="form-control" id="csName"></div>
    </div>
    <p class="small text-secondary mt-2 mb-0">You will be set as the adviser of this section.</p>
  </div>
  <div class="modal-footer"><button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" id="csSave">Create Section</button></div>
</div></div></div>

<script>
var _cs = document.getElementById('btnCreateSection');
if (_cs) _cs.addEventListener('click', function () {
  document.getElementById('csErr').hidden = true;
  document.getElementById('csGrade').value = '4'; document.getElementById('csName').value = '';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('csModal')).show();
});
document.getElementById('csSave').addEventListener('click', function () {
  apiFetch('api/section_create.php', { method: 'POST', body: {
    grade_level: parseInt(document.getElementById('csGrade').value, 10),
    section_name: document.getElementById('csName').value,
  } })
    .then(function (r) { window.location.href = 'section.php?section_id=' + r.data.section_id; })
    .catch(function (e) {
      var msg = e.message;
      if (e.payload && e.payload.errors) msg += ' ' + Object.values(e.payload.errors).join(' ');
      var err = document.getElementById('csErr'); err.textContent = msg; err.hidden = false;
    });
});
document.querySelectorAll('[data-fdsec]').forEach(function (b) {
  b.addEventListener('click', function () {
    forceDelete({ entity: 'section', id: parseInt(b.dataset.fdsec, 10), onDone: function () { b.closest('.section-card').remove(); } });
  });
});
document.querySelectorAll('[data-delsec]').forEach(function (b) {
  b.addEventListener('click', function () {
    if (!confirm('Are you sure you want to delete ' + b.dataset.secname + '? This cannot be undone.')) return;
    apiFetch('api/admin.php', { method: 'POST', body: { resource: 'sections', action: 'delete', section_id: parseInt(b.dataset.delsec, 10) } })
      .then(function (r) { toast(r.message); b.closest('.section-card').remove(); })
      .catch(function (e) { toast(e.message, 'error'); });
  });
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var f = document.getElementById('classFilter');
  if (!f) return;
  f.addEventListener('input', function () {
    var q = f.value.trim().toLowerCase(), shown = 0;
    document.querySelectorAll('.section-card').forEach(function (c) {
      var hit = c.dataset.search.indexOf(q) !== -1; c.hidden = !hit; if (hit) shown++;
    });
    document.getElementById('noMatch').hidden = shown > 0;
  });
});
</script>
<?php if ($isAdmin): ?>
<script>
// Multi-select Delete / Force Delete for sections (admin only; the server enforces it again)
document.addEventListener('DOMContentLoaded', function () {
  var sel = bulkSelect({
    container: document.querySelector('main') || document.body, master: ['#cardAll'], bar: '#cardBulk',
    actions: [
      { key: 'del', label: 'Delete Selected', icon: 'bi-trash', cls: 'btn-outline-danger',
        run: function (it) { bulkDelete({ noun: 'section', items: it, resource: 'sections', idKey: 'section_ids', onDone: removeCards }); } },
      { key: 'fd', label: 'Force Delete Selected', icon: 'bi-trash3-fill', cls: 'btn-danger',
        run: function (it) { bulkForceDelete({ entity: 'section', items: it, onDone: removeCards }); } }
    ]
  });
  function removeCards(deleted) {
    (deleted || []).forEach(function (d) { var c = document.querySelector('.section-card[data-sec-id="' + d.id + '"]'); if (c) c.remove(); });
    sel.refresh();
  }
  document.addEventListener('change', function (e) {
    if (e.target.classList && e.target.classList.contains('bulk-check')) e.target.closest('.section-card').classList.toggle('selected', e.target.checked);
  });
  var f = document.getElementById('classFilter');
  if (f) f.addEventListener('input', function () { sel.refresh(); });
});
</script>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
