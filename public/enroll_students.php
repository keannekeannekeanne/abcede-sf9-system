<?php
require_once __DIR__ . '/../src/Http/Http.php';
Page::boot();
require_once __DIR__ . '/../src/Auth/Access.php';
Auth::requireLogin();

$section = Access::requireSection((int) ($_GET['section_id'] ?? 0));
$name    = 'Grade ' . $section['grade_level'] . ' – ' . $section['section_name'];

$pageTitle  = 'Enroll Students';
$activePage = 'my_classes';
require __DIR__ . '/includes/header.php';
?>
<div class="crumbs"><a href="my_classes.php"><?= Auth::isAdmin() ? 'View Sections' : 'My Classes' ?></a> / <a href="section.php?section_id=<?= $section['id'] ?>"><?= htmlspecialchars($name) ?></a> / Enroll Students</div>
<div class="page-head">
  <div>
    <h1>Enroll Students</h1>
    <div class="sub"><?= htmlspecialchars($name) ?> · <?= htmlspecialchars($section['year_label']) ?> · <span id="enrolledCount"><?= $section['student_count'] ?></span> enrolled</div>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <?php if (($section['is_admin'] || $section['is_adviser']) && $section['year_active']): ?>
      <button type="button" class="btn btn-primary" data-section-import><i class="bi bi-file-earmark-arrow-up me-1"></i>Import from Excel</button>
    <?php endif; ?>
    <a class="btn btn-outline-secondary" href="section.php?section_id=<?= $section['id'] ?>#students"><i class="bi bi-people me-1"></i>View Students</a>
  </div>
</div>

<?php if (!$section['year_active']): ?>
  <div class="alert alert-warning">This section belongs to a past school year. Enrollment is only open for the current school year.</div>
<?php else: ?>

<div class="surface mb-3">
  <div class="surface-body">
    <label class="form-label" for="q">Search student by name or LRN</label>
    <div class="input-group">
      <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
      <input type="search" id="q" class="form-control" placeholder="e.g. “Acebron” or 136700000001" autocomplete="off" autofocus>
    </div>
    <div class="form-text">Existing learner records are reused — no duplicate student is created.</div>
  </div>
  <div id="resultsWrap" hidden>
    <div id="enrollBulk"></div>
    <label class="form-check small m-2 ms-3" id="enrollAllWrap"><input type="checkbox" class="form-check-input bulk-all" id="enrollAll"> <span class="form-check-label">Select all shown</span></label>
    <table class="table table-modern table-stack">
      <thead><tr><th class="cell-check"></th><th>Student Name</th><th>LRN</th><th>Grade Level</th><th>Section</th><th class="text-end">Action</th></tr></thead>
      <tbody id="results"></tbody>
    </table>
  </div>
  <div id="resultsEmpty" class="empty-state" hidden><i class="bi bi-person-x"></i><strong>No learner found</strong>Check the spelling or LRN, or register the learner below.</div>
  <div id="resultsHint" class="empty-state"><i class="bi bi-search"></i>Start typing to find a learner.</div>
</div>

<div class="surface">
  <div class="surface-head">
    <h2>Learner not in the system?</h2>
    <div class="d-flex flex-wrap gap-2">
      <button class="btn btn-sm btn-outline-primary" type="button" id="btnManyLearners"><i class="bi bi-people me-1"></i>Register multiple learners</button>
      <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#newLearner" aria-expanded="false" aria-controls="newLearner">Register new learner</button>
    </div>
  </div>
  <div class="collapse" id="newLearner">
    <form id="newForm" class="surface-body row g-3" novalidate>
      <div class="col-12 col-md-4"><label class="form-label" for="f_lrn">LRN (12 digits)</label><input class="form-control" id="f_lrn" name="lrn" inputmode="numeric" maxlength="12" required><div class="invalid-feedback"></div></div>
      <div class="col-12 col-md-4"><label class="form-label" for="f_last">Last name</label><input class="form-control" id="f_last" name="last_name" maxlength="100" required><div class="invalid-feedback"></div></div>
      <div class="col-12 col-md-4"><label class="form-label" for="f_first">First name</label><input class="form-control" id="f_first" name="first_name" maxlength="100" required><div class="invalid-feedback"></div></div>
      <div class="col-12 col-md-4"><label class="form-label" for="f_mid">Middle name <span class="text-secondary fw-normal">(optional)</span></label><input class="form-control" id="f_mid" name="middle_name" maxlength="100"><div class="invalid-feedback"></div></div>
      <div class="col-6 col-md-4"><label class="form-label" for="f_gender">Sex</label><select class="form-select" id="f_gender" name="gender" required><option value="">Select…</option><option>Male</option><option>Female</option></select><div class="invalid-feedback"></div></div>
      <div class="col-6 col-md-4"><label class="form-label" for="f_birth">Birthdate <span class="text-secondary fw-normal">(optional)</span></label><input type="date" class="form-control" id="f_birth" name="birthdate"><div class="invalid-feedback"></div></div>
      <div class="col-12"><button class="btn btn-primary" type="submit" id="newBtn">Register &amp; enroll in <?= htmlspecialchars($name) ?></button></div>
    </form>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var SECTION = <?= (int) $section['id'] ?>;
  var q = document.getElementById('q'), body = document.getElementById('results');
  var wrap = document.getElementById('resultsWrap'), empty = document.getElementById('resultsEmpty'), hint = document.getElementById('resultsHint');
  var timer = null, seq = 0;

  function show(which) { wrap.hidden = which !== 'rows'; empty.hidden = which !== 'empty'; hint.hidden = which !== 'hint'; }
  function bump(n) { var c = document.getElementById('enrolledCount'); c.textContent = parseInt(c.textContent, 10) + n; }

  function row(r) {
    var tr = document.createElement('tr');
    var action = r.can_enroll
      ? '<button type="button" class="btn btn-sm btn-primary" data-id="' + r.student_id + '">' + (r.code === 'reactivate' ? 'Re-activate' : 'Enroll') + '</button>'
        + (r.reason ? '<div class="small text-secondary mt-1">' + esc(r.reason) + '</div>' : '')
      : '<span class="pill ' + (r.code === 'already' ? 'pill-ok' : 'pill-warn') + '">' + (r.code === 'already' ? 'Enrolled' : 'Not eligible') + '</span><div class="small text-secondary mt-1">' + esc(r.reason) + '</div>';
    tr.innerHTML =
      '<td class="cell-check">' + (r.can_enroll ? '<input type="checkbox" class="form-check-input bulk-check" value="' + r.student_id + '" data-label="' + esc(r.name) + '" aria-label="Select ' + esc(r.name) + '">' : '') + '</td>' +
      '<td data-label="Student"><span class="fw-semibold">' + esc(r.name) + '</span></td>' +
      '<td data-label="LRN">' + esc(r.lrn) + '</td>' +
      '<td data-label="Grade">' + (r.grade_level ? esc(r.grade_level) : '—') + '</td>' +
      '<td data-label="Section">' + (r.section ? esc(r.section) : '—') + '</td>' +
      '<td data-label="Action" class="text-end">' + action + '</td>';
    var btn = tr.querySelector('button');
    if (btn) btn.addEventListener('click', function () { enroll(r, btn, tr); });
    return tr;
  }

  function search() {
    var term = q.value.trim();
    if (term.length < 2) { body.innerHTML = ''; show('hint'); return; }
    var mine = ++seq;
    apiFetch('api/enrollment.php?action=search&section_id=' + SECTION + '&q=' + encodeURIComponent(term))
      .then(function (r) {
        if (mine !== seq) return;   // a newer search is already running
        body.innerHTML = '';
        r.data.results.forEach(function (x) { body.appendChild(row(x)); });
        show(r.data.results.length ? 'rows' : 'empty');
        document.getElementById('enrollAllWrap').hidden = !r.data.results.some(function (x) { return x.can_enroll; });
        sel.refresh();
      })
      .catch(function (e) { if (mine === seq) toast(e.message, 'error'); });
  }
  q.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(search, 300); });

  // Multi-select enroll (same eligibility rules as the single Enroll button — checked again on the server per learner)
  var sel = bulkSelect({
    container: document.getElementById('resultsWrap'), master: ['#enrollAll'], bar: '#enrollBulk',
    actions: [{ key: 'enroll', label: 'Enroll Selected', icon: 'bi-person-check', cls: 'btn-primary',
      run: function (it) {
        if (!confirm('Enroll ' + it.length + ' selected learner' + (it.length === 1 ? '' : 's') + ' in <?= htmlspecialchars($name, ENT_QUOTES) ?>?')) return;
        apiFetch('api/enrollment.php', { method: 'POST', body: { action: 'enroll_many', section_id: SECTION, student_ids: it.map(function (i) { return i.id; }) } })
          .then(function (res) {
            var ok = res.data.results.filter(function (x) { return x.ok; }), bad = res.data.results.filter(function (x) { return !x.ok; });
            bump(ok.length); search();
            if (bad.length) showBatchResults('Enroll results', ok, bad); else toast(res.message);
          }).catch(function (e) { toast(e.message, 'error'); });
      } }]
  });
  document.getElementById('btnManyLearners').addEventListener('click', function () {
    openMultiAddStudents({ url: 'api/enrollment.php', sectionId: SECTION, onAdded: bump, onDone: search });
  });

  function enroll(r, btn, tr) {
    btn.disabled = true;
    apiFetch('api/enrollment.php', { method: 'POST', body: { action: 'enroll', section_id: SECTION, student_id: r.student_id } })
      .then(function (res) {
        toast(r.name + ': ' + res.message);
        bump(1);
        r.can_enroll = false; r.code = 'already'; r.reason = ''; r.section = 'This section';
        tr.replaceWith(row(r));
      })
      .catch(function (e) { btn.disabled = false; toast(e.message, 'error'); if (e.status === 409) search(); });
  }

  // Register a brand-new learner
  var form = document.getElementById('newForm');
  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    form.querySelectorAll('.is-invalid').forEach(function (el) { el.classList.remove('is-invalid'); });
    var data = {}; new FormData(form).forEach(function (v, k) { data[k] = v; });
    var btn = document.getElementById('newBtn'); btn.disabled = true;
    apiFetch('api/enrollment.php', { method: 'POST', body: { action: 'register', section_id: SECTION, student: data } })
      .then(function (res) { toast(res.message); bump(1); form.reset(); })
      .catch(function (e) {
        var errs = (e.payload && e.payload.errors) || {};
        Object.keys(errs).forEach(function (k) {
          var el = form.elements[k]; if (!el) return;
          el.classList.add('is-invalid'); el.parentNode.querySelector('.invalid-feedback').textContent = errs[k];
        });
        toast(e.message, 'error');
      })
      .finally(function () { btn.disabled = false; });
  });
});
</script>
<?php require __DIR__ . '/includes/section_import.php'; ?>
<?php require __DIR__ . '/includes/multi_add_students.php'; ?>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
