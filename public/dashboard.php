<?php
require_once __DIR__ . '/../src/Http/Http.php';
Page::boot();
require_once __DIR__ . '/../src/Auth/Access.php';
Auth::requireLogin();

$pageTitle  = 'Dashboard';
$activePage = 'dashboard';
$useCharts  = true;

$all      = Access::sectionsFor(Auth::userId());
$sections = array_values(array_filter($all, fn($s) => $s['year_active']));
$students = array_sum(array_column($sections, 'student_count'));
$subjects = array_sum(array_column($sections, 'my_subject_count'));

$chartSections = array_map(fn($s) => ['id' => $s['id'], 'label' => 'Grade ' . $s['grade_level'] . '-' . $s['section_name']], $sections);

require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div>
    <h1>Welcome, <?= htmlspecialchars(Auth::fullName()) ?></h1>
    <div class="sub">Here is where your classes stand this school year.</div>
  </div>
  <a href="my_classes.php" class="btn btn-primary"><i class="bi bi-collection me-1"></i><?= Auth::isAdmin() ? 'View Sections' : 'My Classes' ?></a>
</div>

<div class="row g-3 mb-4">
  <div class="col-12 col-sm-4"><div class="surface stat"><div class="ico"><i class="bi bi-collection"></i></div><div><div class="num"><?= count($sections) ?></div><div class="lbl">Sections</div></div></div></div>
  <div class="col-12 col-sm-4"><div class="surface stat"><div class="ico"><i class="bi bi-people"></i></div><div><div class="num"><?= (int) $students ?></div><div class="lbl">Enrolled students</div></div></div></div>
  <div class="col-12 col-sm-4"><div class="surface stat"><div class="ico"><i class="bi bi-journal-text"></i></div><div><div class="num"><?= (int) $subjects ?></div><div class="lbl">Subject assignments</div></div></div></div>
</div>

<div class="row g-3">
  <div class="col-12 col-xl-7">
    <div class="surface h-100">
      <div class="surface-head"><h2>Class performance</h2>
        <div class="d-flex gap-2">
          <select id="dashSection" class="form-select form-select-sm" aria-label="Section">
            <?php foreach ($chartSections as $c): ?><option value="<?= (int) $c['id'] ?>"><?= htmlspecialchars($c['label']) ?></option><?php endforeach; ?>
          </select>
          <select id="dashTerm" class="form-select form-select-sm" aria-label="Term">
            <option value="1">Term 1</option><option value="2">Term 2</option><option value="3">Term 3</option><option value="final">Final</option>
          </select>
        </div>
      </div>
      <div class="surface-body">
        <?php if (!$sections): ?>
          <div class="empty-state"><i class="bi bi-bar-chart"></i><strong>No active sections yet</strong>Once you are assigned a section this school year, its performance appears here.</div>
        <?php else: ?>
          <div id="dashEmpty" class="empty-state" hidden><i class="bi bi-bar-chart"></i><strong>No grades to chart yet</strong><span id="dashEmptyMsg">Enrol students or record grades for this term.</span></div>
          <div id="dashBody" class="row g-3 align-items-center">
            <div class="col-12 col-md-6"><div class="chart-box"><canvas id="dashDonut" aria-label="Students by descriptor" role="img"></canvas></div></div>
            <div class="col-12 col-md-6"><ul id="dashLegend" class="legend-list"></ul><div id="dashMean" class="text-secondary small mt-3"></div></div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-12 col-xl-5">
    <div class="surface h-100">
      <div class="surface-head"><h2>My sections</h2><a href="my_classes.php" class="small">View all</a></div>
      <div class="list-group list-group-flush">
        <?php if (!$sections): ?>
          <div class="empty-state"><i class="bi bi-inbox"></i><strong>No classes assigned</strong>Contact your administrator.</div>
        <?php endif; ?>
        <?php foreach (array_slice($sections, 0, 6) as $s): ?>
          <a class="list-group-item list-group-item-action d-flex align-items-center gap-3 py-3" href="section.php?section_id=<?= $s['id'] ?>">
            <div class="flex-grow-1">
              <div class="fw-semibold">Grade <?= $s['grade_level'] ?> – <?= htmlspecialchars($s['section_name']) ?></div>
              <div class="small text-secondary"><?= $s['student_count'] ?> students · <?= $s['is_adviser'] ? 'Adviser' : $s['my_subject_count'] . ' subjects' ?></div>
            </div>
            <i class="bi bi-chevron-right text-secondary"></i>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<?php if ($sections): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var sel = document.getElementById('dashSection'), term = document.getElementById('dashTerm');
  var chart = null;

  function render(d) {
    var body = document.getElementById('dashBody'), empty = document.getElementById('dashEmpty');
    body.hidden = d.total_students === 0; empty.hidden = d.total_students > 0;
    if (d.total_students === 0) { document.getElementById('dashEmptyMsg').textContent = 'No students are enrolled in this section yet.'; }
    document.getElementById('dashLegend').innerHTML = d.distribution.map(function (x) {
      return '<li><span class="dot" style="background:' + (BAND_COLORS[x.label] || '#999') + '"></span>' + esc(x.label) + '<span class="n">' + x.count + '</span></li>';
    }).join('');
    document.getElementById('dashMean').textContent = d.mean_general_average !== null
      ? 'Mean general average: ' + d.mean_general_average
      : (d.total_students ? 'No complete general averages for this term yet (all 8 learning areas are needed).' : '');
    if (chart) chart.destroy();
    if (d.total_students === 0) return;
    chart = new Chart(document.getElementById('dashDonut'), {
      type: 'doughnut',
      data: { labels: d.distribution.map(function (x) { return x.label; }),
              datasets: [{ data: d.distribution.map(function (x) { return x.count; }),
                           backgroundColor: d.distribution.map(function (x) { return BAND_COLORS[x.label] || '#999'; }), borderWidth: 2, borderColor: '#fff' }] },
      options: { responsive: true, maintainAspectRatio: false, cutout: '62%', plugins: { legend: { display: false } } }
    });
  }

  function load() {
    apiFetch('api/section_stats.php?section_id=' + encodeURIComponent(sel.value) + '&term=' + encodeURIComponent(term.value))
      .then(function (r) { render(r.data); })
      .catch(function (e) { toast(e.message, 'error'); });
  }
  sel.addEventListener('change', load); term.addEventListener('change', load);
  // Chart.js is loaded with `defer`, which finishes before DOMContentLoaded fires.
  load();
});
</script>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
