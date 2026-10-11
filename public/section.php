<?php
require_once __DIR__ . '/../src/Http/Http.php';
Page::boot();
require_once __DIR__ . '/../src/Auth/Access.php';
Auth::requireLogin();

$section = Access::requireSection((int) ($_GET['section_id'] ?? 0));   // 403 unless adviser/assigned
$pdo    = get_db();

$stmt = $pdo->prepare(
    'SELECT s.id, s.lrn, s.last_name, s.first_name, s.middle_name, s.gender, e.roster_no, e.status
     FROM enrollments e JOIN students s ON s.id = e.student_id
     WHERE e.section_id = ? AND e.school_year_id = ?
     ORDER BY FIELD(s.gender, \'Male\', \'Female\'), s.last_name, s.first_name, s.middle_name, s.id'
);
$stmt->execute([$section['id'], $section['school_year_id']]);
$students = $stmt->fetchAll();

// Subjects this teacher can open a class-wide grade sheet for.
$sheetStmt = $pdo->prepare(
    'SELECT ta.id, sub.subject_name FROM teacher_assignments ta JOIN subjects sub ON sub.id = ta.subject_id
     WHERE ta.teacher_id = ? AND ta.section_id = ? AND ta.school_year_id = ? ORDER BY sub.sort_order'
);
$sheetStmt->execute([Auth::userId(), $section['id'], $section['school_year_id']]);
$sheets = $sheetStmt->fetchAll();

$canEditAny = !empty($section['my_assignments']);
$name       = 'Grade ' . $section['grade_level'] . ' – ' . $section['section_name'];
$statusPill = ['active' => 'pill-ok', 'transferred_out' => 'pill-warn', 'dropped' => 'pill-bad'];

$pageTitle  = $name;
$activePage = 'my_classes';
$useCharts  = true;
require __DIR__ . '/includes/header.php';
?>
<div class="crumbs"><a href="my_classes.php"><?= Auth::isAdmin() ? 'View Sections' : 'My Classes' ?></a> / <?= htmlspecialchars($name) ?></div>
<div class="page-head">
  <div>
    <h1><?= htmlspecialchars($name) ?></h1>
    <div class="sub">School year <?= htmlspecialchars($section['year_label']) ?>
      <?= $section['year_active'] ? '<span class="pill pill-ok ms-1">Current</span>' : '<span class="pill ms-1">Past year</span>' ?></div>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <?php if ($section['year_active']): ?>
      <a class="btn btn-primary" href="enroll_students.php?section_id=<?= $section['id'] ?>"><i class="bi bi-person-plus me-1"></i>Enroll Students</a>
    <?php endif; ?>
    <?php if (($section['is_admin'] || $section['is_adviser']) && $section['year_active']): ?>
      <button type="button" class="btn btn-outline-primary" data-section-import title="Import learners from an Excel file (.xls, .xlsx, .csv or the DepEd SF1) into this section"><i class="bi bi-file-earmark-arrow-up me-1"></i>Import from Excel</button>
    <?php endif; ?>
    <a class="btn btn-outline-secondary" href="#students"><i class="bi bi-people me-1"></i>View Students</a>
    <?php if ($students): ?>
      <a class="btn btn-outline-secondary" href="temp_report_cards.php?section_id=<?= (int) $section['id'] ?>" target="_blank" rel="noopener" title="Print temporary report cards, 4 learners per A4 sheet"><i class="bi bi-card-text me-1"></i>Temporary Report Cards</a>
      <a class="btn btn-outline-secondary" href="grading_summary.php?section_id=<?= (int) $section['id'] ?>" title="Download the 1st / 2nd / 3rd Term and Final grading summary of this section as an Excel file"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Grading Summary</a>
    <?php endif; ?>
    <?php if ($section['is_admin']): ?>
      <a class="btn btn-outline-secondary" href="export_sections.php?section_ids=<?= (int) $section['id'] ?>"><i class="bi bi-file-earmark-excel me-1"></i>Export/Backup</a>
    <?php endif; ?>
    <div class="dropdown">
      <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" <?= $sheets ? '' : 'disabled' ?>><i class="bi bi-journal-check me-1"></i>Check Grades</button>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><h6 class="dropdown-header">Class grade sheet by subject</h6></li>
        <?php foreach ($sheets as $sh): ?>
          <li><a class="dropdown-item" href="grade_sheet.php?assignment_id=<?= (int) $sh['id'] ?>"><?= htmlspecialchars($sh['subject_name']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><div class="surface stat"><div class="ico"><i class="bi bi-mortarboard"></i></div><div><div class="num"><?= $section['grade_level'] ?></div><div class="lbl">Grade level</div></div></div></div>
  <div class="col-6 col-lg-3"><div class="surface stat"><div class="ico"><i class="bi bi-people"></i></div><div><div class="num"><?= $section['student_count'] ?></div><div class="lbl">Enrolled students</div></div></div></div>
  <div class="col-12 col-lg-6"><div class="surface stat"><div class="ico"><i class="bi bi-person-badge"></i></div><div><div class="fw-bold"><?= htmlspecialchars($section['adviser_name'] ?: 'Not set') ?></div><div class="lbl">Adviser / teacher · <?= htmlspecialchars($section['year_label']) ?></div></div></div></div>
</div>

<div class="surface mb-3">
  <div class="surface-head">
    <h2>Class performance</h2>
    <select id="secTerm" class="form-select form-select-sm" style="width:auto" aria-label="Term">
      <option value="1">Term 1</option><option value="2">Term 2</option><option value="3">Term 3</option><option value="final">Final</option>
    </select>
  </div>
  <div class="surface-body">
    <div id="secEmpty" class="empty-state" <?= $students ? 'hidden' : '' ?>><i class="bi bi-bar-chart"></i><strong>Nothing to chart yet</strong>Enroll students and record grades to see class performance.</div>
    <div id="secCharts" class="row g-4" <?= $students ? '' : 'hidden' ?>>
      <div class="col-12 col-md-5">
        <div class="chart-box"><canvas id="secDonut" role="img" aria-label="Students by descriptor"></canvas></div>
        <ul id="secLegend" class="legend-list mt-3"></ul>
      </div>
      <div class="col-12 col-md-7">
        <div class="small fw-semibold mb-2">Average per learning area</div>
        <div class="chart-box"><canvas id="secBars" role="img" aria-label="Average grade per learning area"></canvas></div>
      </div>
    </div>
  </div>
</div>

<?php if ($section['is_adviser'] || $section['is_admin']): ?>
<div class="surface mb-3" id="assignPanel">
  <div class="surface-head"><h2>Subject Teacher Assignment</h2></div>
  <div class="surface-body">
    <div class="table-responsive">
      <table class="table table-modern align-middle">
        <thead><tr><th>Subject</th><th>Assigned Teacher</th><th class="text-end">Actions</th></tr></thead>
        <tbody id="asgBody"><tr><td colspan="3" class="text-center text-secondary py-3">Loading…</td></tr></tbody>
      </table>
      <p class="small text-secondary mb-0"><i class="bi bi-info-circle me-1"></i>MAPEH is graded as two subjects, each assigned separately: Music and Arts, and Physical Education and Health. The MAPEH grade is calculated automatically as their average.</p>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="surface" id="students">
  <div class="surface-head"><h2>Students</h2>
    <?php if ($section['year_active']): ?><a class="btn btn-sm btn-outline-primary" href="enroll_students.php?section_id=<?= $section['id'] ?>"><i class="bi bi-person-plus me-1"></i>Enroll</a><?php endif; ?>
  </div>
  <?php if (!$students): ?>
    <div class="empty-state"><i class="bi bi-person-plus"></i><strong>No students enrolled in this section</strong>
      <?php if ($section['year_active']): ?>Use <a href="enroll_students.php?section_id=<?= $section['id'] ?>">Enroll Students</a> to add learners<?= $section['is_admin'] ? ', or <strong>Import from Excel</strong> to add a whole class at once' : '' ?>.<?php endif; ?></div>
  <?php else: ?>
    <div class="table-toolbar">
      <input type="search" id="stSearch" class="form-control search" placeholder="Search name or LRN…" aria-label="Search students">
      <select id="stStatus" class="form-select" style="width:auto" aria-label="Filter by status">
        <option value="active">Active</option><option value="">All statuses</option><option value="transferred_out">Transferred out</option><option value="dropped">Dropped</option>
      </select>
    </div>
    <?php if ($section['is_admin']): ?><div id="stBulk"></div><?php endif; ?>
    <table class="table table-modern table-stack">
      <thead><tr><?php if ($section['is_admin']): ?><th class="cell-check"><input type="checkbox" class="form-check-input bulk-all" id="stAll" aria-label="Select all learners shown"></th><?php endif; ?><th style="width:56px">No.</th><th>Student Name</th><th>Gender</th><th>LRN</th><th>Grade Level</th><th>Section</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
      <tbody id="stBody">
        <?php $prevGender = null; foreach ($students as $s):
          $genderBreak = $prevGender !== null && $prevGender !== $s['gender']; $prevGender = $s['gender'];   // first learner of the next gender group
          $full = $s['last_name'] . ', ' . $s['first_name'] . ' ' . ($s['middle_name'] ?? '');
          $q = 'section_id=' . $section['id'] . '&student_id=' . $s['id'];
        ?>
          <tr data-row<?= $genderBreak ? ' class="gender-break" style="border-top:3px solid var(--bs-border-color, #adb5bd)"' : '' ?> data-status="<?= htmlspecialchars($s['status']) ?>" data-search="<?= htmlspecialchars(strtolower($full . ' ' . $s['lrn'])) ?>">
            <?php if ($section['is_admin']): ?><td class="cell-check" data-label="Select"><input type="checkbox" class="form-check-input bulk-check" value="<?= (int) $s['id'] ?>" data-label="<?= htmlspecialchars(trim($full) . ' (LRN ' . $s['lrn'] . ')', ENT_QUOTES) ?>" aria-label="Select <?= htmlspecialchars(trim($full), ENT_QUOTES) ?>"></td><?php endif; ?>
            <td data-label="No."><?= htmlspecialchars((string) $s['roster_no']) ?></td>
            <td data-label="Student"><span class="fw-semibold"><?= htmlspecialchars(trim($full)) ?></span></td>
            <td data-label="Gender"><?= htmlspecialchars((string) $s['gender']) ?></td>
            <td data-label="LRN"><?= htmlspecialchars($s['lrn']) ?></td>
            <td data-label="Grade"><?= $section['grade_level'] ?></td>
            <td data-label="Section"><?= htmlspecialchars($section['section_name']) ?></td>
            <td data-label="Status"><span class="pill <?= $statusPill[$s['status']] ?? '' ?>"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $s['status']))) ?></span></td>
            <td class="cell-actions"><div class="row-actions">
              <a class="btn btn-sm btn-outline-secondary" href="student_grades.php?<?= $q ?>">View Grades</a>
              <?php if ($canEditAny && $s['status'] === 'active'): ?><a class="btn btn-sm btn-outline-secondary" href="student_grades.php?<?= $q ?>&mode=edit">Edit Grades</a><?php endif; ?>
              <a class="btn btn-sm btn-outline-primary" href="sf9_preview.php?student_id=<?= $s['id'] ?>&school_year_id=<?= $section['school_year_id'] ?>&section_id=<?= $section['id'] ?>" target="_blank" rel="noopener"><i class="bi bi-printer me-1"></i>Print SF9</a>
              <?php if ($section['is_admin']): ?><button type="button" class="btn btn-sm btn-danger" data-fdstu="<?= (int) $s['id'] ?>" title="Force Delete this learner and all their records"><i class="bi bi-trash3-fill me-1"></i>Force Delete</button><?php endif; ?>
            </div></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div id="stEmpty" class="empty-state" hidden><i class="bi bi-search"></i><strong>No students match</strong>Try a different search or status filter.</div>
    <div class="table-foot"><span id="stInfo"></span><div class="pager" id="stPager"></div></div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/section_import.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-fdstu]').forEach(function (b) {
    b.addEventListener('click', function () {
      forceDelete({ entity: 'student', id: parseInt(b.dataset.fdstu, 10), onDone: function () { window.location.reload(); } });
    });
  });
  <?php if ($students): ?>
  initTable({ body: '#stBody', search: '#stSearch', status: '#stStatus', pager: '#stPager', info: '#stInfo', empty: '#stEmpty', pageSize: 10 });
  <?php if ($section['is_admin']): ?>
  // Multi-select Force Delete of learners (students have no normal Delete). "Select all" covers the rows shown on this page.
  var stSel = bulkSelect({
    container: document.getElementById('students'), master: ['#stAll'], bar: '#stBulk',
    actions: [{ key: 'fd', label: 'Force Delete Selected', icon: 'bi-trash3-fill', cls: 'btn-danger',
      run: function (it) { bulkForceDelete({ entity: 'student', items: it, onDone: function () { window.location.reload(); } }); } }]
  });
  document.addEventListener('table:render', function () { stSel.refresh(); });
  <?php endif; ?>

  var term = document.getElementById('secTerm'), donut = null, bars = null;
  function draw(d) {
    if (donut) donut.destroy(); if (bars) bars.destroy();
    document.getElementById('secLegend').innerHTML = d.distribution.map(function (x) {
      return '<li><span class="dot" style="background:' + (BAND_COLORS[x.label] || '#999') + '"></span>' + esc(x.label) + '<span class="n">' + x.count + '</span></li>';
    }).join('');
    donut = new Chart(document.getElementById('secDonut'), {
      type: 'doughnut',
      data: { labels: d.distribution.map(function (x) { return x.label; }),
              datasets: [{ data: d.distribution.map(function (x) { return x.count; }),
                           backgroundColor: d.distribution.map(function (x) { return BAND_COLORS[x.label] || '#999'; }), borderWidth: 2, borderColor: '#fff' }] },
      options: { responsive: true, maintainAspectRatio: false, cutout: '62%', plugins: { legend: { display: false } } }
    });
    var subj = d.subject_averages;
    bars = new Chart(document.getElementById('secBars'), {
      type: 'bar',
      data: { labels: subj.map(function (x) { return x.area; }),
              datasets: [{ label: 'Average', data: subj.map(function (x) { return x.average; }), backgroundColor: '#2456c9', borderRadius: 6, maxBarThickness: 34 }] },
      options: { responsive: true, maintainAspectRatio: false, indexAxis: 'y',
                 scales: { x: { min: 0, max: 100, grid: { color: '#eef1f6' } }, y: { grid: { display: false } } },
                 plugins: { legend: { display: false },
                            tooltip: { callbacks: { label: function (c) { return c.raw === null ? 'No grades yet' : c.raw + ' avg (' + subj[c.dataIndex].graded + ' graded)'; } } } } }
    });
  }
  function load() {
    apiFetch('api/section_stats.php?section_id=<?= (int) $section['id'] ?>&term=' + encodeURIComponent(term.value))
      .then(function (r) { draw(r.data); })
      .catch(function (e) { toast(e.message, 'error'); });
  }
  term.addEventListener('change', load);
  load();
  <?php endif; ?>

  <?php if ($section['is_adviser'] || $section['is_admin']): ?>
  (function () {
    var teachers = [];
    function render(assignments) {
      document.getElementById('asgBody').innerHTML = assignments.map(function (a) {
        var opts = '<option value="">— Unassigned —</option>' + teachers.map(function (t) {
          return '<option value="' + t.id + '"' + (a.assignment && a.assignment.teacher_id === t.id ? ' selected' : '') + '>' + esc(t.name) + '</option>';
        }).join('');
        return '<tr><td>' + esc(a.subject_name) + '</td>' +
          '<td><select class="form-select form-select-sm" data-subject="' + a.id + '">' + opts + '</select></td>' +
          '<td class="text-end"><button class="btn btn-sm btn-primary" data-save="' + a.id + '">Save</button></td></tr>';
      }).join('');
      document.querySelectorAll('#asgBody [data-save]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var subjectId = parseInt(btn.dataset.save, 10);
          var teacherId = document.querySelector('#asgBody [data-subject="' + subjectId + '"]').value;
          var body = { section_id: <?= (int) $section['id'] ?>, subject_id: subjectId };
          if (teacherId) { body.action = 'assign'; body.teacher_id = parseInt(teacherId, 10); }
          else {
            var row = assignments.filter(function (x) { return x.id === subjectId; })[0];
            if (!row.assignment) { toast('Already unassigned.'); return; }
            body.action = 'remove'; body.assignment_id = row.assignment.assignment_id;
          }
          apiFetch('api/section_assignments.php', { method: 'POST', body: body })
            .then(function (r) { toast(r.message); load(); })
            .catch(function (e) { toast(e.message, 'error'); });
        });
      });
    }
    function load() {
      apiFetch('api/section_assignments.php?section_id=<?= (int) $section['id'] ?>')
        .then(function (r) { teachers = r.data.teachers; render(r.data.assignments); })
        .catch(function (e) { toast(e.message, 'error'); });
    }
    load();
  })();
  <?php endif; ?>
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
