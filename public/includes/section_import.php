<?php
/**
 * "Import from Excel" for ONE section (Admin or that section's adviser; active school year only).
 *
 * Include this file inside a page that already has $section (from Access::requireSection()) and the
 * shared app.js helpers (apiFetch, apiUpload, esc, toast). It prints the button's modal + its script.
 * The button itself is any element with  data-section-import  (see section.php / enroll_students.php).
 *
 * It uses the existing endpoint api/student_import.php (preview -> confirm -> commit), so every rule of
 * "Import Data" still applies: LRN must be 12 digits, existing LRNs are never overwritten, nothing is
 * written until the user confirms, and enrollment goes through EnrollmentService.
 */
if ((empty($section['is_admin']) && empty($section['is_adviser'])) || empty($section['year_active'])) { return; }
$siName = 'Grade ' . $section['grade_level'] . ' – ' . $section['section_name'];
?>
<div class="modal fade" id="siModal" tabindex="-1" aria-labelledby="siTitle"><div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable"><div class="modal-content">
  <div class="modal-header">
    <h2 class="modal-title h5" id="siTitle"><i class="bi bi-file-earmark-arrow-up me-1"></i>Import students into <?= htmlspecialchars($siName) ?></h2>
    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
  </div>
  <div class="modal-body">
    <div id="siErr" class="alert alert-danger" hidden></div>

    <div id="siStep1">
      <label class="form-label" for="siFile">Excel file (.xls, .xlsx or .csv — max 5 MB)</label>
      <input type="file" class="form-control mb-2" id="siFile" accept=".xls,.xlsx,.csv">
      <div class="small text-secondary">Every new learner in the file is added to the student list <strong>and enrolled in <?= htmlspecialchars($siName) ?></strong> (<?= htmlspecialchars($section['year_label']) ?>).
        Use the DepEd <strong>SF1 register</strong> as it is, or the template: <strong>LRN, Last Name, First Name, Middle Name, Sex (M/F), Birth Date</strong>.
        <a href="student_import_template.php">Download the template</a>.</div>
    </div>

    <div id="siStep2" hidden>
      <div id="siContext" class="small text-secondary mb-2"></div>
      <div id="siMismatch" class="alert alert-warning small" hidden></div>
      <div class="row g-2 mb-3" id="siCounts"></div>
      <div class="d-flex flex-wrap gap-3 align-items-center mb-2">
        <select id="siFilter" class="form-select form-select-sm" style="width:auto" aria-label="Show rows">
          <option value="">All rows</option><option value="new">Ready to import</option><option value="duplicate">Duplicates</option><option value="possible_duplicate">Possible duplicates</option><option value="invalid">Invalid</option>
        </select>
        <span class="small text-secondary" id="siShown"></span>
        <div class="form-check ms-auto" id="siPossibleWrap" hidden>
          <input class="form-check-input" type="checkbox" id="siPossible"><label class="form-check-label small" for="siPossible">Also import <span id="siPossibleN"></span> possible duplicate(s)</label>
        </div>
      </div>
      <div class="table-responsive" style="max-height:340px;overflow:auto">
        <table class="table table-modern table-sm align-middle mb-0">
          <thead><tr><th>Row</th><th>LRN</th><th>Name</th><th>Sex</th><th>Birth date</th><th>Result</th></tr></thead>
          <tbody id="siRows"></tbody>
        </table>
      </div>
    </div>

    <div id="siStep3" hidden>
      <div class="alert alert-success mb-3" id="siDone"></div>
      <div class="row g-2 mb-3" id="siDoneCounts"></div>
      <div id="siDoneNotes"></div>
    </div>
  </div>
  <div class="modal-footer">
    <button class="btn btn-outline-secondary" data-bs-dismiss="modal" id="siClose">Cancel</button>
    <button class="btn btn-outline-secondary" id="siBack" hidden>Choose another file</button>
    <button class="btn btn-primary" id="siCheck">Check File</button>
    <button class="btn btn-success" id="siGo" hidden>Import</button>
  </div>
</div></div></div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var SECTION = { id: <?= (int) $section['id'] ?>, grade: <?= (int) $section['grade_level'] ?>, name: <?= json_encode((string) $section['section_name']) ?>, label: <?= json_encode($siName) ?> };
  var $ = function (id) { return document.getElementById(id); };
  var st = { token: null, rows: [], summary: null, imported: false };
  var LABEL = { new: ['Ready', 'pill-ok'], duplicate: ['Duplicate', 'pill-warn'], possible_duplicate: ['Possible duplicate', 'pill-warn'], invalid: ['Invalid', 'pill-bad'] };

  function step(n) {
    $('siStep1').hidden = n !== 1; $('siStep2').hidden = n !== 2; $('siStep3').hidden = n !== 3;
    $('siCheck').hidden = n !== 1; $('siGo').hidden = n !== 2; $('siBack').hidden = n !== 2;
    $('siClose').textContent = n === 3 ? 'Close' : 'Cancel';
  }
  function err(msg) { $('siErr').textContent = msg; $('siErr').hidden = !msg; }
  function card(n, label, cls) {
    return '<div class="col-6 col-md"><div class="surface p-2 text-center"><div class="fs-4 fw-bold ' + (cls || '') + '">' + n + '</div><div class="small text-secondary">' + label + '</div></div></div>';
  }
  function render() {
    var f = $('siFilter').value;
    var rows = st.rows.filter(function (r) { return !f || r.status === f; });
    $('siRows').innerHTML = rows.map(function (r) {
      var l = LABEL[r.status] || [r.status, ''];
      return '<tr><td>' + r.row + '</td><td>' + esc(r.lrn) + '</td><td>' + esc(r.name) + '</td><td>' + esc(r.sex || '') + '</td><td>' + esc(r.birthdate || '') + '</td>' +
        '<td><span class="pill ' + l[1] + '">' + l[0] + '</span>' + (r.messages.length ? '<div class="small text-secondary mt-1">' + r.messages.map(esc).join('<br>') + '</div>' : '') + '</td></tr>';
    }).join('') || '<tr><td colspan="6" class="text-center text-secondary py-3">No rows.</td></tr>';
    $('siShown').textContent = rows.length + ' of ' + st.rows.length + ' row(s)';
  }
  function reset() {
    st = { token: null, rows: [], summary: null, imported: false };
    $('siFile').value = ''; err(''); step(1); $('siCheck').disabled = false; $('siGo').disabled = false;
  }

  // Warn (never block) when the SF1 heading names a different grade / section than the one being imported into.
  function mismatch(ctx) {
    var msgs = [];
    if (ctx && ctx.grade_level && ctx.grade_level !== SECTION.grade) { msgs.push('the file is for <strong>' + esc(ctx.grade) + '</strong>'); }
    if (ctx && ctx.section && ctx.section.trim().toUpperCase() !== SECTION.name.trim().toUpperCase()) { msgs.push('the file\'s section is <strong>' + esc(ctx.section) + '</strong>'); }
    return msgs.length ? '<i class="bi bi-exclamation-triangle me-1"></i>Check the file: ' + msgs.join(' and ') + ', but you are importing into <strong>' + esc(SECTION.label) + '</strong>.' : '';
  }

  document.querySelectorAll('[data-section-import]').forEach(function (b) {
    b.addEventListener('click', function () { reset(); bootstrap.Modal.getOrCreateInstance($('siModal')).show(); });
  });
  $('siModal').addEventListener('hidden.bs.modal', function () { if (st.imported) window.location.reload(); });   // show the new roster
  $('siBack').addEventListener('click', reset);
  $('siFilter').addEventListener('change', render);

  $('siCheck').addEventListener('click', function () {
    var file = $('siFile').files[0];
    if (!file) { err('Choose a file first.'); return; }
    if (!/\.(xls|xlsx|csv)$/i.test(file.name)) { err('Only .xls, .xlsx and .csv files can be imported.'); return; }
    if (file.size > 5 * 1024 * 1024) { err('That file is larger than 5 MB.'); return; }
    var fd = new FormData(); fd.append('action', 'preview'); fd.append('section_id', SECTION.id); fd.append('file', file);
    err(''); $('siCheck').disabled = true; $('siCheck').textContent = 'Checking…';
    apiUpload('api/student_import.php', fd).then(function (r) {
      var d = r.data, s = d.summary; st.token = d.token; st.rows = d.rows; st.summary = s;
      $('siContext').innerHTML = d.context && d.context.grade ? 'File heading: <strong>' + esc(d.context.grade) + (d.context.section ? ' – ' + esc(d.context.section) : '') + '</strong>' + (d.context.school_year ? ' · SY ' + esc(d.context.school_year) : '') : '';
      var m = mismatch(d.context); $('siMismatch').innerHTML = m; $('siMismatch').hidden = !m;
      $('siCounts').innerHTML = card(s.total, 'Learners in file') + card(s.new, 'Ready to import', 'text-success') +
        card(s.duplicate + s.possible_duplicate, 'Duplicates', 'text-warning') + card(s.invalid, 'Invalid', 'text-danger');
      $('siPossibleWrap').hidden = s.possible_duplicate === 0; $('siPossible').checked = false; $('siPossibleN').textContent = s.possible_duplicate;
      $('siFilter').value = ''; render(); step(2);
      $('siGo').textContent = s.new ? 'Import ' + s.new + ' learner' + (s.new === 1 ? '' : 's') : 'Nothing to import';
      $('siGo').disabled = s.new === 0 && s.possible_duplicate === 0;
    }).catch(function (e) { err(e.message); })
      .finally(function () { $('siCheck').disabled = false; $('siCheck').textContent = 'Check File'; });
  });

  $('siGo').addEventListener('click', function () {
    var n = st.summary.new + ($('siPossible').checked ? st.summary.possible_duplicate : 0);
    var warn = $('siMismatch').hidden ? '' : '\n\nWarning: the file heading does not match this section.';
    if (!confirm('Import ' + n + ' learner(s) and enroll them in ' + SECTION.label + '? Existing learners will not be changed.' + warn)) return;
    $('siGo').disabled = true; err('');
    apiFetch('api/student_import.php', { method: 'POST', body: { action: 'commit', token: st.token, section_id: SECTION.id, allow_possible_duplicates: $('siPossible').checked } })
      .then(function (r) {
        var d = r.data; st.imported = d.imported > 0;
        $('siDone').innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>Import finished: <strong>' + d.imported + '</strong> learner(s) added, <strong>' + d.enrolled + '</strong> enrolled in ' + esc(SECTION.label) + '.';
        $('siDoneCounts').innerHTML = card(d.imported, 'Successfully imported', 'text-success') + card(d.enrolled, 'Enrolled in section', 'text-success') +
          card(d.duplicates, 'Duplicates skipped', 'text-warning') + card(d.invalid, 'Invalid skipped', 'text-danger');
        var notes = '';
        if (d.enroll_failures.length) notes += '<div class="alert alert-warning small"><strong>Imported but not enrolled:</strong><ul class="mb-0">' + d.enroll_failures.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ul></div>';
        var skipped = d.rows.filter(function (x) { return x.result !== 'imported'; });
        if (skipped.length) notes += '<details><summary class="small">Skipped rows (' + skipped.length + ')</summary><ul class="small mt-2">' + skipped.map(function (x) { return '<li>Row ' + x.row + ' — ' + esc(x.name) + ': ' + esc((x.messages || []).join(' ') || x.result) + '</li>'; }).join('') + '</ul></details>';
        $('siDoneNotes').innerHTML = notes;
        step(3); toast(r.message);
      })
      .catch(function (e) { $('siGo').disabled = false; err(e.message); });
  });
});
</script>
