<?php
require_once __DIR__ . '/../src/Http/Http.php';
Page::boot();
require_once __DIR__ . '/../src/Auth/Access.php';
Auth::requireLogin();

$section   = Access::requireSection((int) ($_GET['section_id'] ?? 0));
$studentId = (int) ($_GET['student_id'] ?? 0);
Access::requireEnrollment($section['id'], $studentId, $section['school_year_id']);   // 404 unless enrolled here

$name       = 'Grade ' . $section['grade_level'] . ' – ' . $section['section_name'];
$startEdit  = ($_GET['mode'] ?? '') === 'edit';
$pageTitle  = 'Student Grades';
$activePage = 'my_classes';
$useCharts  = true;
require __DIR__ . '/includes/header.php';
?>
<div class="crumbs"><a href="my_classes.php"><?= Auth::isAdmin() ? 'View Sections' : 'My Classes' ?></a> / <a href="section.php?section_id=<?= $section['id'] ?>"><?= htmlspecialchars($name) ?></a> / Grades</div>
<div class="page-head">
  <div>
    <h1 id="stName">Student grades</h1>
    <div class="sub" id="stMeta"><?= htmlspecialchars($name) ?> · <?= htmlspecialchars($section['year_label']) ?></div>
  </div>
  <div class="d-flex flex-wrap gap-2" id="toolbar">
    <a class="btn btn-outline-secondary" href="section.php?section_id=<?= $section['id'] ?>#students"><i class="bi bi-arrow-left me-1"></i>Students</a>
    <button class="btn btn-outline-primary" id="btnEdit" type="button" hidden><i class="bi bi-pencil-square me-1"></i>Edit Grades</button>
    <button class="btn btn-outline-secondary" id="btnCancel" type="button" hidden>Cancel</button>
    <button class="btn btn-success" id="btnSave" type="button" hidden disabled>Save changes</button>
    <a class="btn btn-primary" target="_blank" rel="noopener"
       href="sf9_preview.php?student_id=<?= $studentId ?>&school_year_id=<?= $section['school_year_id'] ?>&section_id=<?= $section['id'] ?>"><i class="bi bi-printer me-1"></i>Print SF9</a>
  </div>
</div>

<div id="banner" class="alert alert-danger" role="alert" hidden></div>
<div id="nlpaNote" class="alert alert-warning" hidden>One or more terms are marked <strong>NLPA</strong> (No Longer Participating in Learning Activities), so the Final Grade and General Average are not computed.</div>

<div class="surface mb-3">
  <div class="surface-head"><h2>Term grades</h2><span class="small text-secondary" id="editHint" hidden>Only subjects assigned to you can be edited.</span></div>
  <div class="table-responsive">
    <table class="table table-modern grade-table">
      <thead><tr><th>Subject</th><th>Teacher</th><th class="g">Term 1</th><th class="g">Term 2</th><th class="g">Term 3</th><th class="g">Final Grade</th></tr></thead>
      <tbody id="gBody"><tr><td colspan="6" class="text-center text-secondary py-4">Loading grades…</td></tr></tbody>
      <tfoot id="gFoot"></tfoot>
    </table>
  </div>
</div>

<div class="row g-3" id="chartRow" hidden>
  <div class="col-12 col-lg-5"><div class="surface h-100"><div class="surface-head"><h2>General average by term</h2></div><div class="surface-body"><div class="chart-box"><canvas id="cLine" role="img" aria-label="General average by term"></canvas></div></div></div></div>
  <div class="col-12 col-lg-7"><div class="surface h-100"><div class="surface-head"><h2>Subject performance</h2></div><div class="surface-body"><div class="chart-box"><canvas id="cBars" role="img" aria-label="Grades per subject and term"></canvas></div></div></div></div>
</div>
<div class="surface" id="chartEmpty" hidden><div class="empty-state"><i class="bi bi-graph-up"></i><strong>No grades recorded yet</strong>Charts appear once grades are saved for this student.</div></div>

<div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><h2 class="modal-title h5" id="confirmTitle">Save these changes?</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body"><p class="mb-2" id="confirmText"></p><ul class="small mb-0" id="confirmList"></ul></div>
    <div class="modal-footer"><button class="btn btn-outline-secondary" data-bs-dismiss="modal" type="button">Keep editing</button><button class="btn btn-success" id="confirmYes" type="button">Save grades</button></div>
  </div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var URL_API = 'api/student_grades.php';
  var SECTION = <?= (int) $section['id'] ?>, STUDENT = <?= (int) $studentId ?>;
  var TRIS = ['1', '2', '3'];
  var state = { data: null, edit: false, pending: {} };
  var lineChart = null, barChart = null;
  var $ = function (id) { return document.getElementById(id); };
  var STATUS = { graded: 'Graded', nlpa: 'NLPA', transferred_out: 'T/O' };

  function key(row, t) { return row.subject_id + '|' + t; }
  function showBanner(msg) { var b = $('banner'); b.textContent = msg || ''; b.hidden = !msg; }
  function num(v) { return v === null || v === undefined ? '—' : String(v); }

  function cellView(c) {
    if (c.status === 'nlpa') return '<span class="pill pill-bad">NLPA</span>';
    if (c.status === 'transferred_out') return '<span class="pill pill-warn">T/O</span>';
    return esc(num(c.value));
  }
  function cellEdit(row, t) {
    var c = row.terms[t];
    if (c.locked) return cellView(c) + ' <i class="bi bi-lock-fill text-secondary" title="Locked"></i>';
    var p = state.pending[key(row, t)];
    var val = p ? p.value : (c.value === null ? '' : c.value);
    var st = p ? p.status : (c.status || 'graded');
    var sopts = Object.keys(STATUS).map(function (s) { return '<option value="' + s + '"' + (s === st ? ' selected' : '') + '>' + STATUS[s] + '</option>'; }).join('');
    return '<input type="number" min="0" max="100" step="0.01" inputmode="decimal" class="form-control form-control-sm grade-input' + (p ? ' changed' : '') + '" data-k="' + key(row, t) + '" data-sid="' + row.subject_id + '" data-t="' + t + '" value="' + esc(val) + '"' + (st !== 'graded' ? ' disabled' : '') + ' aria-label="' + esc(row.label) + ' term ' + t + '">' +
           '<select class="form-select form-select-sm grade-cell-status' + (p ? ' changed' : '') + '" data-sid="' + row.subject_id + '" data-t="' + t + '" aria-label="Status">' + sopts + '</select>';
  }

  function render() {
    var d = state.data;
    $('stName').textContent = d.student.name;
    $('stMeta').textContent = 'LRN ' + d.student.lrn + ' · ' + d.section.label + ' · SY ' + d.section.school_year + (d.student.status !== 'active' ? ' · ' + d.student.status.replace('_', ' ') : '');

    $('gBody').innerHTML = d.rows.map(function (row) {
      var editable = state.edit && row.editable && d.student.status === 'active';
      var tds = TRIS.map(function (t) { return '<td class="g">' + (editable ? cellEdit(row, t) : cellView(row.terms[t])) + '</td>'; }).join('');
      return '<tr class="kind-' + row.kind + '"><td>' + esc(row.label) + (row.kind === 'computed' ? ' <span class="pill">computed</span>' : '') + '</td><td class="text-secondary">' + esc(row.teacher) + '</td>' + tds + '<td class="g fw-semibold">' + esc(num(row.final)) + '</td></tr>';
    }).join('');

    var s = d.summary;
    $('gFoot').innerHTML =
      '<tr><td colspan="2">General Average</td>' + TRIS.map(function (t) { return '<td class="g">' + esc(num(s.general_average[t])) + '</td>'; }).join('') + '<td class="g">' + esc(num(s.general_average.final)) + '</td></tr>' +
      '<tr><td colspan="2" class="fw-normal text-secondary">Descriptor</td>' + TRIS.map(function (t) { return '<td class="g fw-normal">' + esc(s.descriptor[t] || '—') + '</td>'; }).join('') + '<td class="g fw-normal">' + esc(s.descriptor.final || '—') + '</td></tr>' +
      '<tr><td colspan="5" class="fw-normal text-secondary">Rank in section (final)</td><td class="g fw-normal">' + esc(num(d.rank)) + '</td></tr>';

    $('nlpaNote').hidden = !d.blocked_by_nlpa_term;
    $('btnEdit').hidden = state.edit || !d.can_edit || d.student.status !== 'active';
    $('btnCancel').hidden = $('btnSave').hidden = !state.edit;
    $('editHint').hidden = !state.edit;
    var n = Object.keys(state.pending).length;
    $('btnSave').disabled = n === 0;
    $('btnSave').textContent = n ? 'Save changes (' + n + ')' : 'Save changes';
    bindInputs();
    drawCharts();
  }

  function bindInputs() {
    document.querySelectorAll('#gBody .grade-input').forEach(function (el) {
      el.addEventListener('input', function () { track(el.dataset.sid, el.dataset.t, el.value, null); });
    });
    document.querySelectorAll('#gBody .grade-cell-status').forEach(function (el) {
      el.addEventListener('change', function () { track(el.dataset.sid, el.dataset.t, null, el.value); });
    });
  }

  // Record a cell as pending only when it differs from what is saved.
  function track(sid, t, value, status) {
    var row = state.data.rows.filter(function (r) { return String(r.subject_id) === String(sid); })[0];
    var orig = row.terms[t], k = key(row, t);
    var cur = state.pending[k] || { value: orig.value === null ? '' : String(orig.value), status: orig.status || 'graded' };
    if (value !== null) cur.value = value;
    if (status !== null) { cur.status = status; if (status !== 'graded') cur.value = ''; }
    var same = cur.status === (orig.status || 'graded') && (cur.status !== 'graded' || String(cur.value) === (orig.value === null ? '' : String(orig.value)));
    if (same) delete state.pending[k]; else state.pending[k] = cur;
    // Update in place (no re-render, so focus stays in the field).
    var input = document.querySelector('.grade-input[data-k="' + k + '"]');
    var sel = document.querySelector('.grade-cell-status[data-sid="' + sid + '"][data-t="' + t + '"]');
    [input, sel].forEach(function (el) { if (el) el.classList.toggle('changed', !same); });
    if (input) { input.disabled = cur.status !== 'graded'; if (status !== null && cur.status !== 'graded') input.value = ''; }
    var n = Object.keys(state.pending).length;
    $('btnSave').disabled = n === 0;
    $('btnSave').textContent = n ? 'Save changes (' + n + ')' : 'Save changes';
  }

  function changesList() {
    return Object.keys(state.pending).map(function (k) {
      var parts = k.split('|'), p = state.pending[k];
      return { subject_id: parseInt(parts[0], 10), trimester: parts[1], grade_value: p.status === 'graded' ? p.value : null, status: p.status };
    });
  }
  function validate(list) {
    var bad = list.filter(function (c) {
      if (c.status !== 'graded') return false;
      var v = parseFloat(c.grade_value);
      return c.grade_value === '' || isNaN(v) || v < 0 || v > 100;
    });
    return bad;
  }
  function labelFor(sid) { return state.data.rows.filter(function (r) { return r.subject_id === sid; })[0].label; }

  $('btnEdit').addEventListener('click', function () { state.edit = true; showBanner(''); render(); });
  $('btnCancel').addEventListener('click', function () {
    if (Object.keys(state.pending).length && !confirm('Discard your unsaved changes?')) return;
    state.edit = false; state.pending = {}; showBanner(''); render();
  });
  $('btnSave').addEventListener('click', function () {
    var list = changesList(), bad = validate(list);
    if (bad.length) {
      showBanner('Enter a grade from 0 to 100 (or choose NLPA / T/O) for: ' + bad.map(function (c) { return labelFor(c.subject_id) + ' T' + c.trimester; }).join(', ') + '.');
      return;
    }
    showBanner('');
    $('confirmText').textContent = 'You are about to update ' + list.length + ' grade' + (list.length === 1 ? '' : 's') + ' for ' + state.data.student.name + ':';
    $('confirmList').innerHTML = list.map(function (c) {
      return '<li>' + esc(labelFor(c.subject_id)) + ', Term ' + c.trimester + ' → <strong>' + (c.status === 'graded' ? esc(c.grade_value) : STATUS[c.status]) + '</strong></li>';
    }).join('');
    bootstrap.Modal.getOrCreateInstance($('confirmModal')).show();
  });
  $('confirmYes').addEventListener('click', function () {
    var btn = $('confirmYes'); btn.disabled = true;
    apiFetch(URL_API, { method: 'POST', body: { section_id: SECTION, student_id: STUDENT, changes: changesList() } })
      .then(function (r) {
        bootstrap.Modal.getInstance($('confirmModal')).hide();
        state.data = r.data; state.pending = {}; state.edit = false;
        toast(r.message);
        render();   // recomputed MAPEH / averages arrive in the same response
      })
      .catch(function (e) {
        bootstrap.Modal.getInstance($('confirmModal')).hide();
        var detail = e.payload && e.payload.errors ? ' ' + e.payload.errors.map(function (x) { return labelFor(x.subject_id) + ' T' + x.trimester + ': ' + x.message; }).join(' ') : '';
        showBanner(e.message + detail);
      })
      .finally(function () { btn.disabled = false; });
  });

  window.addEventListener('beforeunload', function (ev) {
    if (Object.keys(state.pending).length) { ev.preventDefault(); ev.returnValue = ''; }
  });

  function drawCharts() {
    var d = state.data, areas = d.rows.filter(function (r) { return r.kind !== 'component'; });
    var hasAny = areas.some(function (r) { return TRIS.some(function (t) { return r.terms[t].value !== null; }); });
    $('chartRow').hidden = !hasAny; $('chartEmpty').hidden = hasAny;
    if (lineChart) lineChart.destroy(); if (barChart) barChart.destroy();
    if (!hasAny) return;
    var ga = d.summary.general_average;
    lineChart = new Chart($('cLine'), {
      type: 'line',
      data: { labels: ['Term 1', 'Term 2', 'Term 3', 'Final'],
              datasets: [{ label: 'General average', data: [ga['1'], ga['2'], ga['3'], ga.final], borderColor: '#2456c9', backgroundColor: '#2456c9', tension: .25, pointRadius: 5, spanGaps: false }] },
      options: { responsive: true, maintainAspectRatio: false, scales: { y: { suggestedMin: 60, max: 100, grid: { color: '#eef1f6' } }, x: { grid: { display: false } } }, plugins: { legend: { display: false } } }
    });
    var colors = ['#9db8ec', '#5b86df', '#1b449f'];
    barChart = new Chart($('cBars'), {
      type: 'bar',
      data: { labels: areas.map(function (r) { return r.label; }),
              datasets: TRIS.map(function (t, i) { return { label: 'Term ' + t, data: areas.map(function (r) { return r.terms[t].value; }), backgroundColor: colors[i], borderRadius: 4, maxBarThickness: 18 }; }) },
      options: { responsive: true, maintainAspectRatio: false, scales: { y: { min: 0, max: 100, grid: { color: '#eef1f6' } }, x: { grid: { display: false }, ticks: { maxRotation: 45, minRotation: 0 } } }, plugins: { legend: { position: 'bottom' } } }
    });
  }

  apiFetch(URL_API + '?section_id=' + SECTION + '&student_id=' + STUDENT)
    .then(function (r) {
      state.data = r.data;
      if (<?= $startEdit ? 'true' : 'false' ?>) {
        if (r.data.can_edit && r.data.student.status === 'active') state.edit = true;
        else toast('You are not assigned to any subject for this student, so grades are read-only.', 'error');
      }
      render();
    })
    .catch(function (e) {
      $('gBody').innerHTML = '<tr><td colspan="6" class="text-center text-danger py-4">' + esc(e.message) + '</td></tr>';
    });
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
