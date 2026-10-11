<?php
require_once __DIR__ . '/../src/Http/Http.php';
Page::boot();
require_once __DIR__ . '/../src/Auth/Auth.php';
require_once __DIR__ . '/../src/Auth/Access.php';
Auth::requireAdmin();   // server-side: only the Administrator may reach this page at all

$pageTitle  = 'Admin Panel';
$activePage = 'admin';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div>
    <h1>Admin Panel</h1>
    <div class="sub">Accounts, sections, subject teacher assignments and students — full system-wide control.</div>
  </div>
</div>

<ul class="nav nav-pills mb-3" id="adminTabs" role="tablist">
  <li class="nav-item"><button class="nav-link active" data-tab="accounts" type="button"><i class="bi bi-people-fill me-1"></i>Accounts</button></li>
  <li class="nav-item"><button class="nav-link" data-tab="sections" type="button"><i class="bi bi-collection me-1"></i>Sections</button></li>
  <li class="nav-item"><button class="nav-link" data-tab="backup" type="button"><i class="bi bi-file-earmark-excel me-1"></i>Export/Backup</button></li>
  <li class="nav-item"><button class="nav-link" data-tab="grades" type="button"><i class="bi bi-mortarboard me-1"></i>Assign Teacher</button></li>
  <li class="nav-item"><button class="nav-link" data-tab="assignments" type="button"><i class="bi bi-journal-check me-1"></i>Subject Teachers</button></li>
  <li class="nav-item"><button class="nav-link" data-tab="students" type="button"><i class="bi bi-person-plus me-1"></i>Students</button></li>
  <li class="nav-item"><button class="nav-link" data-tab="settings" type="button"><i class="bi bi-gear me-1"></i>Settings</button></li>
</ul>

<!-- ACCOUNTS -->
<div class="admin-pane" data-pane="accounts">
  <div class="surface">
    <div class="surface-head">
      <h2>Teacher &amp; Admin accounts</h2>
      <div class="d-flex flex-wrap gap-2">
        <button class="btn btn-outline-primary btn-sm" id="btnAddManyAccounts"><i class="bi bi-people me-1"></i>Add Multiple</button>
        <button class="btn btn-primary btn-sm" id="btnAddAccount"><i class="bi bi-person-plus me-1"></i>Add Account</button>
      </div>
    </div>
    <div class="table-toolbar"><input type="search" id="acctSearch" class="form-control search" placeholder="Search name or username…"></div>
    <div id="acctBulk"></div>
    <div class="table-responsive">
      <table class="table table-modern align-middle">
        <thead><tr><th class="cell-check"><input type="checkbox" class="form-check-input bulk-all" aria-label="Select all accounts shown"></th><th>Name</th><th>Username</th><th>Role</th><th>Status</th><th>Assigned</th><th class="text-end">Actions</th></tr></thead>
        <tbody id="acctBody"><tr><td colspan="7" class="text-center text-secondary py-4">Loading…</td></tr></tbody>
      </table>
    </div>
  </div>
</div>

<!-- SECTIONS -->
<div class="admin-pane" data-pane="sections" hidden>
  <div class="surface mb-3">
    <div class="surface-head">
      <h2>School Year</h2>
      <button class="btn btn-outline-primary btn-sm" id="btnAddYear"><i class="bi bi-plus-lg me-1"></i>Add School Year</button>
    </div>
    <div class="surface-body">
      <div id="yearList" class="d-flex flex-wrap gap-2"><span class="text-secondary small">Loading…</span></div>
    </div>
  </div>
  <div class="surface">
    <div class="surface-head">
      <h2>View Sections</h2>
      <div class="d-flex flex-wrap gap-2">
        <button class="btn btn-outline-primary btn-sm" id="btnAddManySections"><i class="bi bi-collection me-1"></i>Add Multiple</button>
        <button class="btn btn-primary btn-sm" id="btnAddSection"><i class="bi bi-plus-lg me-1"></i>Create Section</button>
      </div>
    </div>
    <div id="secBulk"></div>
    <div class="surface-body" id="secGroups"><p class="text-secondary text-center py-4 mb-0">Loading…</p></div>
  </div>
</div>

<!-- EXPORT / BACKUP -->
<div class="admin-pane" data-pane="backup" hidden>
  <div class="surface">
    <div class="surface-head">
      <h2>Export/Backup Section Data</h2>
      <button class="btn btn-primary btn-sm" id="bkGo" disabled><i class="bi bi-file-earmark-excel me-1"></i>Export to Excel</button>
    </div>
    <div class="surface-body">
      <p class="small text-secondary"><i class="bi bi-info-circle me-1"></i>Select one or more sections. You get one Excel file with the sections, learners, grades, attendance, teacher remarks and subject teachers. This is a <strong>copy</strong> — nothing is changed or deleted in the system.</p>
      <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
        <button class="btn btn-outline-secondary btn-sm" id="bkAll" type="button">Select all</button>
        <button class="btn btn-outline-secondary btn-sm" id="bkNone" type="button">Clear</button>
        <span class="small text-secondary" id="bkCount">0 sections selected</span>
      </div>
      <div id="bkList" class="row g-2"><div class="col-12 text-secondary small">Loading sections…</div></div>
    </div>
  </div>
</div>

<!-- ASSIGN TEACHER TO GRADE LEVEL -->
<div class="admin-pane" data-pane="grades" hidden>
  <div class="surface">
    <div class="surface-head">
      <h2>Assign Teacher to Grade Level</h2>
    </div>
    <div class="surface-body pb-0">
      <p class="small text-secondary mb-2"><i class="bi bi-info-circle me-1"></i>A teacher assigned to a grade level can only open that grade's sections and students. Teachers with no grade assigned keep their current access. Teachers cannot change this themselves.</p>
    </div>
    <div class="table-toolbar">
      <input type="search" id="glSearch" class="form-control search" placeholder="Search teacher name or username…">
      <select id="glFilter" class="form-select" style="width:auto" aria-label="Filter by grade level">
        <option value="">All teachers</option><option value="4">Grade 4</option><option value="5">Grade 5</option><option value="6">Grade 6</option><option value="none">Not assigned</option>
      </select>
    </div>
    <div class="table-responsive">
      <table class="table table-modern align-middle">
        <thead><tr><th>Teacher</th><th>Username</th><th>Role</th><th>Status</th><th>Assigned Grade</th><th>Current workload</th><th class="text-end">Actions</th></tr></thead>
        <tbody id="glBody"><tr><td colspan="7" class="text-center text-secondary py-4">Loading…</td></tr></tbody>
      </table>
    </div>
  </div>
</div>

<!-- ASSIGNMENTS -->
<div class="admin-pane" data-pane="assignments" hidden>
  <div class="surface">
    <div class="surface-head"><h2>Subject Teacher Assignment</h2></div>
    <div class="surface-body">
      <div class="row g-2 mb-3">
        <div class="col-12 col-sm-6">
          <label class="form-label small">Section</label>
          <select id="asgSection" class="form-select"></select>
        </div>
      </div>
      <div id="asgEmpty" class="empty-state"><i class="bi bi-journal-check"></i><strong>Select a section</strong>Choose a section above to assign or remove subject teachers.</div>
      <div class="table-responsive" id="asgTableWrap" hidden>
        <table class="table table-modern align-middle">
          <thead><tr><th>Subject</th><th>Assigned Teacher</th><th class="text-end">Actions</th></tr></thead>
          <tbody id="asgBody"></tbody>
        </table>
        <p class="small text-secondary mb-0"><i class="bi bi-info-circle me-1"></i>MAPEH is graded as two subjects, each assigned separately: Music and Arts, and Physical Education and Health. The MAPEH grade is calculated automatically as their average.</p>
      </div>
    </div>
  </div>
</div>

<!-- STUDENTS -->
<div class="admin-pane" data-pane="students" hidden>
  <div class="surface mb-3">
    <div class="surface-head">
      <h2>Import Data</h2>
      <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="student_import_template.php"><i class="bi bi-download me-1"></i>Download Template</a>
        <button class="btn btn-primary btn-sm" id="btnImport"><i class="bi bi-file-earmark-arrow-up me-1"></i>Import Data</button>
      </div>
    </div>
    <div class="surface-body">
      <p class="small text-secondary mb-0"><i class="bi bi-info-circle me-1"></i>Add learners from an Excel file (<strong>.xls</strong>, <strong>.xlsx</strong> or .csv) — including the DepEd <strong>SF1 School Register</strong> exported from LIS. You will see a check of the file first; nothing is saved until you confirm. Learners already in the system are skipped, never overwritten.</p>
    </div>
  </div>
  <div class="surface mb-3">
    <div class="surface-head"><h2>View a Student's Grades</h2></div>
    <div class="surface-body">
      <p class="small text-secondary">Find any learner and go straight to their grades — no need to open View Sections first.</p>
      <div class="row g-2 mb-2">
        <div class="col-12 col-sm-8">
          <input type="search" id="sgSearch" class="form-control" placeholder="Search by name or LRN…">
        </div>
        <div class="col-12 col-sm-4">
          <select id="sgGrade" class="form-select"><option value="">All grade levels</option><option value="4">Grade 4</option><option value="5">Grade 5</option><option value="6">Grade 6</option></select>
        </div>
      </div>
      <div id="sgBulk"></div>
      <label class="form-check small mt-2 mb-2" id="sgAllWrap" hidden><input type="checkbox" class="form-check-input bulk-all" id="sgAll"> <span class="form-check-label">Select all shown</span></label>
      <div id="sgResults"></div>
    </div>
  </div>
  <div class="surface">
    <div class="surface-head"><h2>Enroll / Add Student</h2></div>
    <div class="surface-body">
      <div class="row g-2 mb-3">
        <div class="col-12 col-sm-6">
          <label class="form-label small">Section</label>
          <select id="stuSection" class="form-select"></select>
        </div>
      </div>
      <div class="input-group mb-3">
        <input type="search" id="stuSearch" class="form-control" placeholder="Search by name or LRN to enroll an existing learner…">
      </div>
      <div id="stuBulk"></div>
      <label class="form-check small mt-2 mb-2" id="stuAllWrap" hidden><input type="checkbox" class="form-check-input bulk-all" id="stuAll"> <span class="form-check-label">Select all shown</span></label>
      <div id="stuResults"></div>
      <hr>
      <div class="d-flex flex-wrap gap-2">
        <button class="btn btn-outline-primary btn-sm" id="btnNewStudent"><i class="bi bi-person-plus me-1"></i>Add a brand-new student</button>
        <button class="btn btn-outline-primary btn-sm" id="btnManyStudents"><i class="bi bi-people me-1"></i>Add multiple students</button>
      </div>
      <div id="stuNewForm" class="mt-3" hidden></div>
    </div>
  </div>
</div>

<!-- SETTINGS -->
<div class="admin-pane" data-pane="settings" hidden>
  <div class="surface">
    <div class="surface-head"><h2>SF9 Settings</h2></div>
    <div class="surface-body">
      <div id="setErr" class="alert alert-danger" hidden></div>
      <div class="row g-2">
        <div class="col-12 col-sm-8">
          <label class="form-label small">Default School Head (SF9 signature line)</label>
          <input class="form-control" id="setSchoolHead" placeholder="e.g. YVETTE DS. SAN JUAN">
          <div class="small text-secondary mt-1">Used on the SF9 when no active Principal account is on file. Can be changed anytime.</div>
        </div>
      </div>
      <button class="btn btn-primary btn-sm mt-3" id="setSave">Save Settings</button>
    </div>
  </div>
</div>

<!-- Import Data modal -->
<div class="modal fade" id="impModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable"><div class="modal-content">
  <div class="modal-header"><h2 class="modal-title h5"><i class="bi bi-file-earmark-arrow-up me-1"></i>Import Data — Students</h2><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <div id="impErr" class="alert alert-danger" hidden></div>

    <div id="impStep1">
      <label class="form-label" for="impFile">Excel file (.xls, .xlsx or .csv — max 5 MB)</label>
      <input type="file" class="form-control mb-2" id="impFile" accept=".xls,.xlsx,.csv">
      <div class="small text-secondary">Expected columns: <strong>LRN, Last Name, First Name, Middle Name, Sex (M/F), Birth Date</strong>. Or upload the SF1 register as it is. <a href="student_import_template.php">Download the template</a>.</div>
    </div>

    <div id="impStep2" hidden>
      <div id="impContext" class="small text-secondary mb-2"></div>
      <div class="row g-2 mb-3" id="impCounts"></div>
      <div class="row g-2 align-items-end mb-3">
        <div class="col-12 col-md-7">
          <label class="form-label small" for="impSection">Also enroll the new learners in a section <span class="text-secondary fw-normal">(optional)</span></label>
          <select id="impSection" class="form-select form-select-sm"></select>
        </div>
        <div class="col-12 col-md-5" id="impPossibleWrap" hidden>
          <div class="form-check"><input class="form-check-input" type="checkbox" id="impPossible"><label class="form-check-label small" for="impPossible">Also import <span id="impPossibleN"></span> possible duplicate(s)</label></div>
        </div>
      </div>
      <div class="d-flex gap-2 align-items-center mb-2">
        <select id="impFilter" class="form-select form-select-sm" style="width:auto" aria-label="Show rows">
          <option value="">All rows</option><option value="new">Ready to import</option><option value="duplicate">Duplicates</option><option value="possible_duplicate">Possible duplicates</option><option value="invalid">Invalid</option>
        </select>
        <span class="small text-secondary" id="impShown"></span>
      </div>
      <div class="table-responsive" style="max-height:340px;overflow:auto">
        <table class="table table-modern table-sm align-middle mb-0">
          <thead><tr><th>Row</th><th>LRN</th><th>Name</th><th>Sex</th><th>Birth date</th><th>Result</th></tr></thead>
          <tbody id="impRows"></tbody>
        </table>
      </div>
    </div>

    <div id="impStep3" hidden>
      <div class="alert alert-success mb-3" id="impDone"></div>
      <div class="row g-2 mb-3" id="impDoneCounts"></div>
      <div id="impDoneNotes"></div>
    </div>
  </div>
  <div class="modal-footer">
    <button class="btn btn-outline-secondary" data-bs-dismiss="modal" id="impClose">Cancel</button>
    <button class="btn btn-outline-secondary" id="impBack" hidden>Choose another file</button>
    <button class="btn btn-primary" id="impCheck">Check File</button>
    <button class="btn btn-success" id="impGo" hidden>Import</button>
  </div>
</div></div></div>

<!-- Assign Teacher (grade level) modal -->
<div class="modal fade" id="glModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header"><h2 class="modal-title h5">Assign Teacher</h2><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <div id="glErr" class="alert alert-danger" hidden></div>
    <input type="hidden" id="glTeacherId">
    <p class="mb-2">Teacher: <strong id="glTeacherName"></strong></p>
    <label class="form-label small" for="glGrade">Grade level</label>
    <select class="form-select" id="glGrade"><option value="4">Grade 4</option><option value="5">Grade 5</option><option value="6">Grade 6</option></select>
  </div>
  <div class="modal-footer"><button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" id="glSave">Save Assignment</button></div>
</div></div></div>

<!-- Add/Edit Account modal -->
<div class="modal fade" id="acctModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header"><h2 class="modal-title h5" id="acctModalTitle">Add Account</h2><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <div id="acctErr" class="alert alert-danger" hidden></div>
    <input type="hidden" id="acctUserId">
    <div class="row g-2">
      <div class="col-6"><label class="form-label small">First name</label><input class="form-control" id="acctFirst"></div>
      <div class="col-6"><label class="form-label small">Last name</label><input class="form-control" id="acctLast"></div>
      <div class="col-6"><label class="form-label small">Middle name</label><input class="form-control" id="acctMiddle"></div>
      <div class="col-6"><label class="form-label small">Email</label><input class="form-control" id="acctEmail" type="email"></div>
      <div class="col-6" id="acctUsernameWrap"><label class="form-label small">Username</label><input class="form-control" id="acctUsername"></div>
      <div class="col-6"><label class="form-label small">Role</label>
        <select class="form-select" id="acctRole">
          <option value="teacher">Teacher</option>
          <option value="master_teacher">Master Teacher</option>
          <option value="principal">Principal</option>
          <option value="admin">Head / Admin</option>
        </select>
      </div>
      <div class="col-12" id="acctPasswordWrap"><label class="form-label small">Initial password</label><input class="form-control" id="acctPassword" type="text" placeholder="At least 8 characters"></div>
    </div>
  </div>
  <div class="modal-footer"><button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" id="acctSave">Save</button></div>
</div></div></div>

<!-- Reset password modal -->
<div class="modal fade" id="pwModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header"><h2 class="modal-title h5">Reset Password</h2><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <div id="pwErr" class="alert alert-danger" hidden></div>
    <input type="hidden" id="pwUserId">
    <label class="form-label small">New password</label>
    <input class="form-control" id="pwNew" type="text" placeholder="At least 8 characters">
    <div class="small text-secondary mt-2">The account will be required to set a new password at next login.</div>
  </div>
  <div class="modal-footer"><button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" id="pwSave">Reset Password</button></div>
</div></div></div>

<!-- Create Section modal -->
<div class="modal fade" id="secModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header"><h2 class="modal-title h5">Create Section</h2><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <div id="secErr" class="alert alert-danger" hidden></div>
    <div class="row g-2">
      <div class="col-6"><label class="form-label small">Grade Level</label>
        <select class="form-select" id="secGrade"><option value="4">Grade 4</option><option value="5">Grade 5</option><option value="6">Grade 6</option></select>
      </div>
      <div class="col-6"><label class="form-label small">Section Name</label><input class="form-control" id="secName"></div>
      <div class="col-12"><label class="form-label small">Adviser</label><select class="form-select" id="secAdviser"></select></div>
    </div>
  </div>
  <div class="modal-footer"><button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" id="secSave">Create Section</button></div>
</div></div></div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var $ = function (id) { return document.getElementById(id); };
  var state = { accounts: [], sections: [], teachers: [], years: [] };

  // ---- Tabs ----
  document.querySelectorAll('#adminTabs [data-tab]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.querySelectorAll('#adminTabs [data-tab]').forEach(function (b) { b.classList.remove('active'); });
      btn.classList.add('active');
      var tab = btn.dataset.tab;
      document.querySelectorAll('.admin-pane').forEach(function (p) { p.hidden = p.dataset.pane !== tab; });
      if (tab === 'sections') { loadSections(); loadYears(); }
      if (tab === 'backup') loadBackupSections();
      if (tab === 'grades') loadGradeTeachers();
      if (tab === 'assignments') loadAssignmentSectionOptions();
      if (tab === 'students') loadStudentSectionOptions();
      if (tab === 'settings') loadSettings();
    });
  });

  // ---- ACCOUNTS ----
  function roleLabel(r) { return { teacher: 'Teacher', master_teacher: 'Master Teacher', principal: 'Principal', admin: 'Head / Admin' }[r] || r; }

  // Multi-select Delete / Force Delete for accounts
  var acctSel = bulkSelect({
    container: document.querySelector('[data-pane="accounts"]'), master: ['.bulk-all'], bar: '#acctBulk',
    actions: [
      { key: 'del', label: 'Delete Selected', icon: 'bi-trash', cls: 'btn-outline-danger',
        run: function (it) { bulkDelete({ noun: 'account', items: it, resource: 'accounts', idKey: 'user_ids', onDone: loadAccounts }); } },
      { key: 'fd', label: 'Force Delete Selected', icon: 'bi-trash3-fill', cls: 'btn-danger',
        run: function (it) { bulkForceDelete({ entity: 'account', items: it, onDone: loadAccounts }); } }
    ]
  });

  function renderAccounts() {
    var q = ($('acctSearch').value || '').toLowerCase();
    var rows = state.accounts.filter(function (a) {
      return !q || (a.full_name + ' ' + a.username).toLowerCase().indexOf(q) !== -1;
    });
    $('acctBody').innerHTML = rows.map(function (a) {
      return '<tr>' +
        '<td class="cell-check"><input type="checkbox" class="form-check-input bulk-check" value="' + a.id + '" data-label="' + esc(a.full_name + ' (' + a.username + ')') + '" aria-label="Select ' + esc(a.full_name) + '"></td>' +
        '<td>' + esc(a.full_name) + '</td>' +
        '<td>' + esc(a.username) + '</td>' +
        '<td><span class="pill pill-primary">' + esc(roleLabel(a.role)) + '</span></td>' +
        '<td>' + (a.is_active ? '<span class="pill pill-ok">Active</span>' : '<span class="pill pill-bad">Inactive</span>') + '</td>' +
        '<td class="text-secondary small">' + a.assignment_count + ' subject(s)' + (a.adviser_count ? ', ' + a.adviser_count + ' adviser' : '') + '</td>' +
        '<td class="cell-actions"><div class="row-actions">' +
          '<button class="btn btn-sm btn-outline-secondary" data-edit="' + a.id + '">Edit</button>' +
          '<button class="btn btn-sm btn-outline-secondary" data-pw="' + a.id + '">Reset Password</button>' +
          '<button class="btn btn-sm btn-outline-' + (a.is_active ? 'danger' : 'success') + '" data-toggle="' + a.id + '" data-next="' + (a.is_active ? 0 : 1) + '">' + (a.is_active ? 'Deactivate' : 'Activate') + '</button>' +
          '<button class="btn btn-sm btn-outline-danger" data-del="' + a.id + '" data-name="' + esc(a.full_name) + '" title="Delete"><i class="bi bi-trash"></i></button>' +
          '<button class="btn btn-sm btn-danger" data-fdacct="' + a.id + '" title="Force Delete: also removes/re-assigns related data"><i class="bi bi-trash3-fill me-1"></i>Force Delete</button>' +
        '</div></td></tr>';
    }).join('') || '<tr><td colspan="6" class="text-center text-secondary py-4">No accounts match.</td></tr>';
    acctSel.refresh();

    document.querySelectorAll('[data-edit]').forEach(function (b) { b.addEventListener('click', function () { openAccountModal(parseInt(b.dataset.edit, 10)); }); });
    document.querySelectorAll('[data-pw]').forEach(function (b) { b.addEventListener('click', function () { openPwModal(parseInt(b.dataset.pw, 10)); }); });
    document.querySelectorAll('[data-toggle]').forEach(function (b) {
      b.addEventListener('click', function () {
        apiFetch('api/admin.php', { method: 'POST', body: { resource: 'accounts', action: 'set_active', user_id: parseInt(b.dataset.toggle, 10), is_active: b.dataset.next === '1' } })
          .then(function (r) { toast(r.message); loadAccounts(); })
          .catch(function (e) { toast(e.message, 'error'); });
      });
    });
    document.querySelectorAll('[data-fdacct]').forEach(function (b) {
      b.addEventListener('click', function () {
        forceDelete({ entity: 'account', id: parseInt(b.dataset.fdacct, 10), onDone: loadAccounts });
      });
    });
    document.querySelectorAll('[data-del]').forEach(function (b) {
      b.addEventListener('click', function () {
        if (!confirm('Are you sure you want to delete the account for ' + b.dataset.name + '? This cannot be undone.')) return;
        apiFetch('api/admin.php', { method: 'POST', body: { resource: 'accounts', action: 'delete', user_id: parseInt(b.dataset.del, 10) } })
          .then(function (r) { toast(r.message); loadAccounts(); })
          .catch(function (e) { toast(e.message, 'error'); });
      });
    });
  }

  function loadAccounts() {
    apiFetch('api/admin.php?resource=accounts').then(function (r) { state.accounts = r.data.accounts; renderAccounts(); }).catch(function (e) { toast(e.message, 'error'); });
  }
  $('acctSearch').addEventListener('input', renderAccounts);

  function openAccountModal(id) {
    var a = id ? state.accounts.filter(function (x) { return x.id === id; })[0] : null;
    $('acctErr').hidden = true;
    $('acctModalTitle').textContent = a ? 'Edit Account' : 'Add Account';
    $('acctUserId').value = a ? a.id : '';
    $('acctFirst').value = a ? a.first_name : '';
    $('acctLast').value = a ? a.last_name : '';
    $('acctMiddle').value = a ? (a.middle_name || '') : '';
    $('acctEmail').value = a ? (a.email || '') : '';
    $('acctUsername').value = a ? a.username : '';
    $('acctUsername').disabled = !!a;
    $('acctUsernameWrap').style.opacity = a ? .6 : 1;
    $('acctRole').value = a ? a.role : 'teacher';
    $('acctPasswordWrap').hidden = !!a;
    $('acctPassword').value = '';
    bootstrap.Modal.getOrCreateInstance($('acctModal')).show();
  }
  $('btnAddAccount').addEventListener('click', function () { openAccountModal(null); });
  $('btnAddManyAccounts').addEventListener('click', function () {
    multiAdd({
      title: 'Add multiple accounts', noun: 'account', startRows: 3, maxRows: 30, dupField: 'username',
      columns: [
        { key: 'last_name', label: 'Last name', required: true, maxlength: 100, cls: 'col-12 col-sm-6 col-lg-3' },
        { key: 'first_name', label: 'First name', required: true, maxlength: 100, cls: 'col-12 col-sm-6 col-lg-3' },
        { key: 'middle_name', label: 'Middle name', maxlength: 100, cls: 'col-12 col-sm-6 col-lg-3' },
        { key: 'email', label: 'Email', type: 'email', cls: 'col-12 col-sm-6 col-lg-3' },
        { key: 'username', label: 'Username', required: true, maxlength: 50, cls: 'col-12 col-sm-6 col-lg-3' },
        { key: 'password', label: 'Temporary password (min. 8)', required: true, type: 'text', cls: 'col-12 col-sm-6 col-lg-3' },
        { key: 'role', label: 'Role', required: true, type: 'select', cls: 'col-12 col-sm-6 col-lg-3',
          options: [['teacher', 'Teacher'], ['master_teacher', 'Master Teacher'], ['principal', 'Principal'], ['admin', 'Head / Admin']] }
      ],
      validate: function (v) {
        var e = {};
        if (!/^[a-zA-Z0-9._-]{3,50}$/.test(v.username)) e.username = 'Use 3–50 letters, numbers, dot, underscore or dash.';
        if (v.password.length < 8) e.password = 'At least 8 characters.';
        if (!v.first_name) e.first_name = 'First name is required.';
        if (!v.last_name) e.last_name = 'Last name is required.';
        if (v.email && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(v.email)) e.email = 'Enter a valid email.';
        return e;
      },
      dupKey: function (v) { return v.username.toLowerCase(); },
      submit: function (rows) { return apiFetch('api/admin.php', { method: 'POST', body: { resource: 'accounts', action: 'create_many', rows: rows } }); },
      onDone: loadAccounts
    });
  });
  $('acctSave').addEventListener('click', function () {
    var id = $('acctUserId').value;
    var body = {
      resource: 'accounts', action: id ? 'update' : 'create',
      user_id: id ? parseInt(id, 10) : undefined,
      first_name: $('acctFirst').value, last_name: $('acctLast').value, middle_name: $('acctMiddle').value,
      email: $('acctEmail').value, role: $('acctRole').value,
    };
    if (!id) { body.username = $('acctUsername').value; body.password = $('acctPassword').value; }
    apiFetch('api/admin.php', { method: 'POST', body: body })
      .then(function (r) {
        bootstrap.Modal.getInstance($('acctModal')).hide();
        toast(id ? 'Account updated.' : 'Account created. Temporary password: ' + (r.data.temporary_password || ''));
        loadAccounts();
      })
      .catch(function (e) {
        var msg = e.message;
        if (e.payload && e.payload.errors) msg += ' ' + Object.values(e.payload.errors).join(' ');
        $('acctErr').textContent = msg; $('acctErr').hidden = false;
      });
  });

  function openPwModal(id) {
    $('pwErr').hidden = true; $('pwUserId').value = id; $('pwNew').value = '';
    bootstrap.Modal.getOrCreateInstance($('pwModal')).show();
  }
  $('pwSave').addEventListener('click', function () {
    apiFetch('api/admin.php', { method: 'POST', body: { resource: 'accounts', action: 'reset_password', user_id: parseInt($('pwUserId').value, 10), new_password: $('pwNew').value } })
      .then(function (r) { bootstrap.Modal.getInstance($('pwModal')).hide(); toast(r.message); })
      .catch(function (e) {
        var msg = e.message;
        if (e.payload && e.payload.errors) msg += ' ' + Object.values(e.payload.errors).join(' ');
        $('pwErr').textContent = msg; $('pwErr').hidden = false;
      });
  });

  // ---- SECTIONS (grouped by Grade Level, per current school year context) ----
  // Multi-select Delete / Force Delete for sections
  var secSel = bulkSelect({
    container: $('secGroups').parentNode, master: ['.bulk-all'], bar: '#secBulk',
    actions: [
      { key: 'del', label: 'Delete Selected', icon: 'bi-trash', cls: 'btn-outline-danger',
        run: function (it) { bulkDelete({ noun: 'section', items: it, resource: 'sections', idKey: 'section_ids', onDone: loadSections }); } },
      { key: 'fd', label: 'Force Delete Selected', icon: 'bi-trash3-fill', cls: 'btn-danger',
        run: function (it) { bulkForceDelete({ entity: 'section', items: it, onDone: loadSections }); } }
    ]
  });

  function renderSections() {
    var byGrade = {};
    state.sections.forEach(function (s) { (byGrade[s.grade_level] = byGrade[s.grade_level] || []).push(s); });
    var grades = Object.keys(byGrade).sort(function (a, b) { return a - b; });
    if (!grades.length) { $('secGroups').innerHTML = '<p class="text-secondary text-center py-4 mb-0">No sections yet.</p>'; return; }
    $('secGroups').innerHTML = grades.map(function (g) {
      var rows = byGrade[g].map(function (s) {
        return '<tr><td class="cell-check"><input type="checkbox" class="form-check-input bulk-check" value="' + s.id + '" data-label="Grade ' + g + ' – ' + esc(s.section_name) + ' (' + esc(s.year_label) + ')" aria-label="Select Grade ' + g + ' ' + esc(s.section_name) + '"></td><td>' + esc(s.section_name) + '</td>' +
          '<td>' + esc(s.year_label) + (s.year_active ? ' <span class="pill pill-ok">Current</span>' : '') + '</td>' +
          '<td>' + esc(s.adviser_name || 'Not set') + '</td>' +
          '<td>' + s.student_count + '</td>' +
          '<td class="text-end"><div class="row-actions">' +
            '<a class="btn btn-sm btn-outline-primary" href="section.php?section_id=' + s.id + '">Open</a>' +
            '<button class="btn btn-sm btn-outline-danger" data-delsec="' + s.id + '" data-secname="Grade ' + g + ' – ' + esc(s.section_name) + '" title="Delete"><i class="bi bi-trash"></i></button>' +
            '<button class="btn btn-sm btn-danger" data-fdsec="' + s.id + '" title="Force Delete: also removes enrollments, grades and attendance"><i class="bi bi-trash3-fill me-1"></i>Force Delete</button>' +
          '</div></td></tr>';
      }).join('');
      return '<div class="mb-4"><h3 class="h6 mb-2">Grade ' + esc(g) + '</h3>' +
        '<div class="table-responsive"><table class="table table-modern align-middle">' +
        '<thead><tr><th class="cell-check"><input type="checkbox" class="form-check-input bulk-all" data-scope="#secG' + esc(g) + '" aria-label="Select all Grade ' + esc(g) + ' sections shown"></th><th>Section</th><th>School Year</th><th>Adviser</th><th>Students</th><th class="text-end">Actions</th></tr></thead>' +
        '<tbody id="secG' + esc(g) + '">' + rows + '</tbody></table></div></div>';
    }).join('');
    secSel.refresh();
    document.querySelectorAll('[data-fdsec]').forEach(function (b) {
      b.addEventListener('click', function () {
        forceDelete({ entity: 'section', id: parseInt(b.dataset.fdsec, 10), onDone: loadSections });
      });
    });
    document.querySelectorAll('[data-delsec]').forEach(function (b) {
      b.addEventListener('click', function () {
        if (!confirm('Are you sure you want to delete ' + b.dataset.secname + '? This cannot be undone.')) return;
        apiFetch('api/admin.php', { method: 'POST', body: { resource: 'sections', action: 'delete', section_id: parseInt(b.dataset.delsec, 10) } })
          .then(function (r) { toast(r.message); loadSections(); })
          .catch(function (e) { toast(e.message, 'error'); });
      });
    });
  }
  function loadSections() {
    apiFetch('api/admin.php?resource=sections').then(function (r) { state.sections = r.data.sections; renderSections(); populateSectionSelects(); }).catch(function (e) { toast(e.message, 'error'); });
  }

  function loadTeachersIntoAdviserSelect() {
    apiFetch('api/admin.php?resource=assignments&section_id=0&school_year_id=0').then(function (r) {
      state.teachers = r.data.teachers;
      $('secAdviser').innerHTML = state.teachers.map(function (t) { return '<option value="' + t.id + '">' + esc(t.name) + '</option>'; }).join('');
    }).catch(function () {});
  }
  $('btnAddSection').addEventListener('click', function () {
    $('secErr').hidden = true; $('secGrade').value = '4'; $('secName').value = '';
    if (!state.teachers.length) loadTeachersIntoAdviserSelect();
    bootstrap.Modal.getOrCreateInstance($('secModal')).show();
  });
  $('btnAddManySections').addEventListener('click', function () {
    apiFetch('api/admin.php?resource=assignments&section_id=0&school_year_id=0').then(function (r) {
      state.teachers = r.data.teachers;
      if (!state.teachers.length) { toast('No active teacher is available to be adviser.', 'error'); return; }
      multiAdd({
        title: 'Add multiple sections (current school year)', noun: 'section', startRows: 3, maxRows: 30,
        columns: [
          { key: 'grade_level', label: 'Grade level', required: true, type: 'select', cls: 'col-12 col-sm-4', options: [['4', 'Grade 4'], ['5', 'Grade 5'], ['6', 'Grade 6']] },
          { key: 'section_name', label: 'Section name', required: true, maxlength: 50, cls: 'col-12 col-sm-4' },
          { key: 'adviser_id', label: 'Adviser', required: true, type: 'select', cls: 'col-12 col-sm-4',
            options: state.teachers.map(function (t) { return [String(t.id), t.name]; }) }
        ],
        validate: function (v) { return v.section_name ? {} : { section_name: 'Section name is required.' }; },
        dupKey: function (v) { return v.grade_level + '|' + v.section_name.toLowerCase(); }, dupField: 'section_name',
        submit: function (rows) {
          return apiFetch('api/admin.php', { method: 'POST', body: { resource: 'sections', action: 'create_many', rows: rows.map(function (x) { return { grade_level: parseInt(x.grade_level, 10), section_name: x.section_name, adviser_id: x.adviser_id }; }) } });
        },
        onDone: loadSections
      });
    }).catch(function (e) { toast(e.message, 'error'); });
  });
  $('secSave').addEventListener('click', function () {
    apiFetch('api/admin.php', { method: 'POST', body: { resource: 'sections', grade_level: parseInt($('secGrade').value, 10), section_name: $('secName').value, adviser_id: $('secAdviser').value } })
      .then(function () { bootstrap.Modal.getInstance($('secModal')).hide(); toast('Section created.'); loadSections(); })
      .catch(function (e) {
        var msg = e.message;
        if (e.payload && e.payload.errors) msg += ' ' + Object.values(e.payload.errors).join(' ');
        $('secErr').textContent = msg; $('secErr').hidden = false;
      });
  });

  // ---- SCHOOL YEARS ----
  function renderYears() {
    $('yearList').innerHTML = state.years.map(function (y) {
      return '<span class="pill ' + (y.is_active ? 'pill-ok' : '') + '" style="cursor:pointer" data-year="' + y.id + '">' +
        esc(y.year_label) + (y.is_active ? ' (Current)' : '') + '</span>';
    }).join('') || '<span class="text-secondary small">No school years yet.</span>';
    document.querySelectorAll('[data-year]').forEach(function (el) {
      el.addEventListener('click', function () {
        var y = state.years.filter(function (x) { return x.id === parseInt(el.dataset.year, 10); })[0];
        if (!y || y.is_active) return;
        if (!confirm('Set ' + y.year_label + ' as the active school year?')) return;
        apiFetch('api/admin.php', { method: 'POST', body: { resource: 'school_years', action: 'set_active', school_year_id: y.id } })
          .then(function (r) { toast(r.message); loadYears(); loadSections(); })
          .catch(function (e) { toast(e.message, 'error'); });
      });
    });
  }
  function loadYears() {
    apiFetch('api/admin.php?resource=school_years').then(function (r) { state.years = r.data.school_years; renderYears(); }).catch(function (e) { toast(e.message, 'error'); });
  }
  $('btnAddYear').addEventListener('click', function () {
    var label = prompt('New school year (e.g. 2026-2027):');
    if (!label) return;
    var makeActive = confirm('Make ' + label + ' the active school year now?');
    apiFetch('api/admin.php', { method: 'POST', body: { resource: 'school_years', year_label: label, make_active: makeActive } })
      .then(function (r) { toast('School year created.'); loadYears(); })
      .catch(function (e) {
        var msg = e.message;
        if (e.payload && e.payload.errors) msg += ' ' + Object.values(e.payload.errors).join(' ');
        toast(msg, 'error');
      });
  });

  // ---- ASSIGNMENTS ----
  function populateSectionSelects() {
    var opts = state.sections.map(function (s) { return '<option value="' + s.id + '|' + s.school_year_id + '">Grade ' + s.grade_level + ' – ' + esc(s.section_name) + ' (' + esc(s.year_label) + ')</option>'; }).join('');
    $('asgSection').innerHTML = '<option value="">— Select a section —</option>' + opts;
    $('stuSection').innerHTML = '<option value="">— Select a section —</option>' + opts;
  }
  function loadAssignmentSectionOptions() { if (!state.sections.length) loadSections(); }
  $('asgSection').addEventListener('change', function () {
    var v = $('asgSection').value;
    $('asgTableWrap').hidden = !v; $('asgEmpty').hidden = !!v;
    if (!v) return;
    var parts = v.split('|');
    apiFetch('api/admin.php?resource=assignments&section_id=' + parts[0] + '&school_year_id=' + parts[1]).then(function (r) {
      state.teachers = r.data.teachers;
      $('asgBody').innerHTML = r.data.assignments.map(function (a) {
        var opts = '<option value="">— Unassigned —</option>' + state.teachers.map(function (t) { return '<option value="' + t.id + '"' + (a.assignment && a.assignment.teacher_id === t.id ? ' selected' : '') + '>' + esc(t.name) + '</option>'; }).join('');
        return '<tr><td>' + esc(a.subject_name) + '</td>' +
          '<td><select class="form-select form-select-sm" data-subject="' + a.id + '">' + opts + '</select></td>' +
          '<td class="text-end"><button class="btn btn-sm btn-primary" data-assign="' + a.id + '">Save</button></td></tr>';
      }).join('');
      document.querySelectorAll('[data-assign]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var subjectId = parseInt(btn.dataset.assign, 10);
          var sel = document.querySelector('[data-subject="' + subjectId + '"]');
          var teacherId = sel.value;
          var body = { resource: 'assignments', section_id: parseInt(parts[0], 10), school_year_id: parseInt(parts[1], 10), subject_id: subjectId };
          if (teacherId) { body.action = 'assign'; body.teacher_id = parseInt(teacherId, 10); }
          else {
            var row = r.data.assignments.filter(function (x) { return x.id === subjectId; })[0];
            if (!row.assignment) { toast('Already unassigned.'); return; }
            body.action = 'remove'; body.assignment_id = row.assignment.assignment_id;
          }
          apiFetch('api/admin.php', { method: 'POST', body: body })
            .then(function (rr) { toast(rr.message); $('asgSection').dispatchEvent(new Event('change')); })
            .catch(function (e) { toast(e.message, 'error'); });
        });
      });
    }).catch(function (e) { toast(e.message, 'error'); });
  });

  // ---- STUDENTS ----
  function loadStudentSectionOptions() { if (!state.sections.length) loadSections(); }
  var stuSearchTimer = null;
  // Multi-select enroll of existing learners (same eligibility rules as one-by-one Enroll)
  var stuSel = bulkSelect({
    container: $('stuResults').parentNode, master: ['#stuAll'], bar: '#stuBulk',
    actions: [{ key: 'enroll', label: 'Enroll Selected', icon: 'bi-person-check', cls: 'btn-primary',
      run: function (it) {
        var sec = $('stuSection').value;
        if (!sec) { toast('Select a section first.', 'error'); return; }
        if (!confirm('Enroll ' + it.length + ' selected learner' + (it.length === 1 ? '' : 's') + ' in the selected section?')) return;
        apiFetch('api/admin.php', { method: 'POST', body: { resource: 'students', action: 'enroll_many', section_id: parseInt(sec.split('|')[0], 10), student_ids: it.map(function (i) { return i.id; }) } })
          .then(function (r) {
            var ok = r.data.results.filter(function (x) { return x.ok; }), bad = r.data.results.filter(function (x) { return !x.ok; });
            if (bad.length) showBatchResults('Enroll results', ok, bad, function () { $('stuSearch').dispatchEvent(new Event('input')); });
            else { toast(r.message); $('stuSearch').dispatchEvent(new Event('input')); }
          }).catch(function (e) { toast(e.message, 'error'); });
      } }]
  });
  $('btnManyStudents').addEventListener('click', function () {
    var sec = $('stuSection').value;
    if (!sec) { toast('Select a section first.', 'error'); return; }
    openMultiAddStudents({ url: 'api/admin.php', asBody: function (rows) { return { resource: 'students', action: 'register_many', section_id: parseInt(sec.split('|')[0], 10), rows: rows }; },
      onDone: function () { loadSections(); } });
  });
  $('stuSearch').addEventListener('input', function () {
    clearTimeout(stuSearchTimer);
    var q = $('stuSearch').value;
    var sec = $('stuSection').value;
    if (!sec || q.trim().length < 2) { $('stuResults').innerHTML = ''; return; }
    stuSearchTimer = setTimeout(function () {
      apiFetch('api/admin.php?resource=students&section_id=' + sec.split('|')[0] + '&q=' + encodeURIComponent(q)).then(function (r) {
        $('stuResults').innerHTML = r.data.results.map(function (s) {
          return '<div class="surface d-flex justify-content-between align-items-center py-2 px-3 mb-2">' +
            '<div class="d-flex align-items-center gap-2">' + (s.can_enroll ? '<input type="checkbox" class="form-check-input bulk-check" value="' + s.student_id + '" data-label="' + esc(s.name) + '" aria-label="Select ' + esc(s.name) + '">' : '<span style="width:1.1rem"></span>') +
            '<div><strong>' + esc(s.name) + '</strong><div class="small text-secondary">LRN ' + esc(s.lrn) + (s.section ? ' · ' + esc(s.section) : '') + '</div></div></div>' +
            (s.can_enroll ? '<button class="btn btn-sm btn-primary" data-enroll="' + s.student_id + '">Enroll</button>' : '<span class="pill pill-warn">' + esc(s.reason) + '</span>') +
          '</div>';
        }).join('') || '<div class="text-secondary small">No matches.</div>';
        $('stuAllWrap').hidden = !r.data.results.some(function (x) { return x.can_enroll; });
        stuSel.refresh();
        document.querySelectorAll('[data-enroll]').forEach(function (b) {
          b.addEventListener('click', function () {
            apiFetch('api/admin.php', { method: 'POST', body: { resource: 'students', action: 'enroll', section_id: parseInt(sec.split('|')[0], 10), student_id: parseInt(b.dataset.enroll, 10) } })
              .then(function (r2) { toast(r2.message || 'Enrolled.'); $('stuSearch').dispatchEvent(new Event('input')); })
              .catch(function (e) { toast(e.message, 'error'); });
          });
        });
      }).catch(function (e) { toast(e.message, 'error'); });
    }, 300);
  });
  $('stuSection').addEventListener('change', function () { $('stuSearch').value = ''; $('stuResults').innerHTML = ''; });
  $('btnNewStudent').addEventListener('click', function () {
    var f = $('stuNewForm');
    f.hidden = !f.hidden;
    if (f.hidden) return;
    f.innerHTML =
      '<div class="row g-2">' +
      '<div class="col-6"><label class="form-label small">LRN (12 digits)</label><input class="form-control" id="nsLrn"></div>' +
      '<div class="col-6"><label class="form-label small">Gender</label><select class="form-select" id="nsGender"><option value="Male">Male</option><option value="Female">Female</option></select></div>' +
      '<div class="col-6"><label class="form-label small">Last name</label><input class="form-control" id="nsLast"></div>' +
      '<div class="col-6"><label class="form-label small">First name</label><input class="form-control" id="nsFirst"></div>' +
      '<div class="col-6"><label class="form-label small">Middle name</label><input class="form-control" id="nsMiddle"></div>' +
      '<div class="col-6"><label class="form-label small">Birthdate</label><input class="form-control" id="nsBirth" type="date"></div>' +
      '</div><div id="nsErr" class="alert alert-danger mt-2" hidden></div>' +
      '<button class="btn btn-success btn-sm mt-2" id="nsSave">Add &amp; Enroll</button>';
    $('nsSave').addEventListener('click', function () {
      var sec = $('stuSection').value;
      if (!sec) { toast('Select a section first.', 'error'); return; }
      apiFetch('api/admin.php', { method: 'POST', body: {
        resource: 'students', action: 'register', section_id: parseInt(sec.split('|')[0], 10),
        lrn: $('nsLrn').value, gender: $('nsGender').value, last_name: $('nsLast').value,
        first_name: $('nsFirst').value, middle_name: $('nsMiddle').value, birthdate: $('nsBirth').value,
      } })
        .then(function (r) { toast('Student added and enrolled.'); f.hidden = true; })
        .catch(function (e) {
          var msg = e.message;
          if (e.payload && e.payload.errors) msg += ' ' + Object.values(e.payload.errors).join(' ');
          $('nsErr').textContent = msg; $('nsErr').hidden = false;
        });
    });
  });

  // ---- VIEW A STUDENT'S GRADES (direct, no section navigation required) ----
  var sgTimer = null;
  // Multi-select Force Delete of learners (students have no normal Delete — only Force Delete)
  var sgSel = bulkSelect({
    container: $('sgResults').parentNode, master: ['#sgAll'], bar: '#sgBulk',
    actions: [{ key: 'fd', label: 'Force Delete Selected', icon: 'bi-trash3-fill', cls: 'btn-danger',
      run: function (it) { bulkForceDelete({ entity: 'student', items: it, onDone: runStudentGradeSearch }); } }]
  });
  function runStudentGradeSearch() {
    clearTimeout(sgTimer);
    var q = $('sgSearch').value, grade = $('sgGrade').value;
    if (q.trim().length < 2 && !grade) { $('sgResults').innerHTML = ''; return; }
    sgTimer = setTimeout(function () {
      var url = 'api/admin.php?resource=student_directory&q=' + encodeURIComponent(q) + (grade ? '&grade_level=' + grade : '');
      apiFetch(url).then(function (r) {
        $('sgResults').innerHTML = r.data.results.map(function (s) {
          return '<div class="surface d-flex justify-content-between align-items-center py-2 px-3 mb-2">' +
            '<div class="d-flex align-items-center gap-2"><input type="checkbox" class="form-check-input bulk-check" value="' + s.student_id + '" data-label="' + esc(s.name + ' (LRN ' + s.lrn + ')') + '" aria-label="Select ' + esc(s.name) + '">' +
            '<div><strong>' + esc(s.name) + '</strong><div class="small text-secondary">LRN ' + esc(s.lrn) + ' · ' + esc(s.section) + ' · SY ' + esc(s.year_label) + '</div></div></div>' +
            '<div class="d-flex gap-2"><a class="btn btn-sm btn-primary" href="student_grades.php?section_id=' + s.section_id + '&student_id=' + s.student_id + '">View Grades</a>' +
            '<button class="btn btn-sm btn-danger" data-fdstu="' + s.student_id + '" title="Force Delete: removes the learner and all their grades, attendance and remarks"><i class="bi bi-trash3-fill me-1"></i>Force Delete</button></div>' +
          '</div>';
        }).join('') || '<div class="text-secondary small">No matches.</div>';
        $('sgAllWrap').hidden = !r.data.results.length;
        sgSel.refresh();
        document.querySelectorAll('[data-fdstu]').forEach(function (b) {
          b.addEventListener('click', function () {
            forceDelete({ entity: 'student', id: parseInt(b.dataset.fdstu, 10), onDone: runStudentGradeSearch });
          });
        });
      }).catch(function (e) { toast(e.message, 'error'); });
    }, 300);
  }
  $('sgSearch').addEventListener('input', runStudentGradeSearch);
  $('sgGrade').addEventListener('change', runStudentGradeSearch);

  // ---- SETTINGS ----
  function loadSettings() {
    apiFetch('api/admin.php?resource=settings').then(function (r) {
      $('setSchoolHead').value = (r.data.settings && r.data.settings.school_head_name) || '';
    }).catch(function (e) { toast(e.message, 'error'); });
  }
  $('setSave').addEventListener('click', function () {
    $('setErr').hidden = true;
    apiFetch('api/admin.php', { method: 'POST', body: { resource: 'settings', key: 'school_head_name', value: $('setSchoolHead').value } })
      .then(function (r) { toast(r.message); })
      .catch(function (e) { $('setErr').textContent = e.message; $('setErr').hidden = false; });
  });

  // ---- EXPORT / BACKUP ----
  function bkSelected() { return Array.prototype.slice.call(document.querySelectorAll('.bk-check:checked')).map(function (c) { return c.value; }); }
  function bkUpdate() { var n = bkSelected().length; $('bkCount').textContent = n + ' section' + (n === 1 ? '' : 's') + ' selected'; $('bkGo').disabled = n === 0; }
  function loadBackupSections() {
    apiFetch('api/admin.php?resource=backup_sections').then(function (r) {
      var groups = {};
      r.data.sections.forEach(function (s) { (groups[s.year_label] = groups[s.year_label] || []).push(s); });
      $('bkList').innerHTML = Object.keys(groups).map(function (y) {
        return '<div class="col-12"><div class="fw-semibold small mt-2">School Year ' + esc(y) + '</div></div>' + groups[y].map(function (s) {
          return '<div class="col-12 col-md-6 col-xl-4"><label class="surface p-2 d-flex gap-2 align-items-center mb-0" style="cursor:pointer">' +
            '<input type="checkbox" class="form-check-input bk-check mt-0" value="' + s.id + '">' +
            '<span>Grade ' + s.grade_level + ' – ' + esc(s.section_name) + '<span class="d-block small text-secondary">' + s.student_count + ' learner(s)' + (s.year_active ? '' : ' · past year') + '</span></span></label></div>';
        }).join('');
      }).join('') || '<div class="col-12 text-secondary small">No sections yet.</div>';
      document.querySelectorAll('.bk-check').forEach(function (c) { c.addEventListener('change', bkUpdate); });
      bkUpdate();
    }).catch(function (e) { toast(e.message, 'error'); });
  }
  $('bkAll').addEventListener('click', function () { document.querySelectorAll('.bk-check').forEach(function (c) { c.checked = true; }); bkUpdate(); });
  $('bkNone').addEventListener('click', function () { document.querySelectorAll('.bk-check').forEach(function (c) { c.checked = false; }); bkUpdate(); });
  $('bkGo').addEventListener('click', function () {
    var ids = bkSelected(); if (!ids.length) return;
    window.location.href = 'export_sections.php?section_ids=' + ids.join(',');
    toast('Preparing your Excel backup…');
  });

  // ---- IMPORT DATA (students from Excel) ----
  var imp = { token: null, rows: [], summary: null, suggested: null };
  var IMP_LABEL = { new: ['Ready', 'pill-ok'], duplicate: ['Duplicate', 'pill-warn'], possible_duplicate: ['Possible duplicate', 'pill-warn'], invalid: ['Invalid', 'pill-bad'], imported: ['Imported', 'pill-ok'] };
  function impStep(n) {
    $('impStep1').hidden = n !== 1; $('impStep2').hidden = n !== 2; $('impStep3').hidden = n !== 3;
    $('impCheck').hidden = n !== 1; $('impGo').hidden = n !== 2; $('impBack').hidden = n !== 2;
    $('impClose').textContent = n === 3 ? 'Close' : 'Cancel';
  }
  function impError(msg) { $('impErr').textContent = msg; $('impErr').hidden = !msg; }
  function countCard(n, label, cls) {
    return '<div class="col-6 col-md"><div class="surface p-2 text-center"><div class="fs-4 fw-bold ' + (cls || '') + '">' + n + '</div><div class="small text-secondary">' + label + '</div></div></div>';
  }
  function impRender() {
    var f = $('impFilter').value;
    var rows = imp.rows.filter(function (r) { return !f || r.status === f; });
    $('impRows').innerHTML = rows.map(function (r) {
      var l = IMP_LABEL[r.status] || [r.status, ''];
      return '<tr><td>' + r.row + '</td><td>' + esc(r.lrn) + '</td><td>' + esc(r.name) + '</td><td>' + esc(r.sex || '') + '</td><td>' + esc(r.birthdate || '') + '</td>' +
        '<td><span class="pill ' + l[1] + '">' + l[0] + '</span>' + (r.messages.length ? '<div class="small text-secondary mt-1">' + r.messages.map(esc).join('<br>') + '</div>' : '') + '</td></tr>';
    }).join('') || '<tr><td colspan="6" class="text-center text-secondary py-3">No rows.</td></tr>';
    $('impShown').textContent = rows.length + ' of ' + imp.rows.length + ' row(s)';
  }
  function impReset() {
    imp = { token: null, rows: [], summary: null, suggested: null };
    $('impFile').value = ''; impError(''); impStep(1); $('impCheck').disabled = false; $('impGo').disabled = false;
  }
  $('btnImport').addEventListener('click', function () { if (!state.sections.length) loadSections(); impReset(); bootstrap.Modal.getOrCreateInstance($('impModal')).show(); });
  $('impBack').addEventListener('click', impReset);
  $('impFilter').addEventListener('change', impRender);

  $('impCheck').addEventListener('click', function () {
    var file = $('impFile').files[0];
    if (!file) { impError('Choose a file first.'); return; }
    if (!/\.(xls|xlsx|csv)$/i.test(file.name)) { impError('Only .xls, .xlsx and .csv files can be imported.'); return; }
    if (file.size > 5 * 1024 * 1024) { impError('That file is larger than 5 MB.'); return; }
    var fd = new FormData(); fd.append('action', 'preview'); fd.append('file', file);
    impError(''); $('impCheck').disabled = true; $('impCheck').textContent = 'Checking…';
    apiUpload('api/student_import.php', fd).then(function (r) {
      var d = r.data; imp.token = d.token; imp.rows = d.rows; imp.summary = d.summary; imp.suggested = d.suggested_section_id;
      var s = d.summary;
      $('impContext').innerHTML = d.context && d.context.grade ? 'File heading: <strong>' + esc(d.context.grade) + (d.context.section ? ' – ' + esc(d.context.section) : '') + '</strong>' + (d.context.school_year ? ' · SY ' + esc(d.context.school_year) : '') : '';
      $('impCounts').innerHTML = countCard(s.total, 'Learners in file') + countCard(s.new, 'Ready to import', 'text-success') +
        countCard(s.duplicate + s.possible_duplicate, 'Duplicates', 'text-warning') + countCard(s.invalid, 'Invalid', 'text-danger');
      var opts = '<option value="">Do not enroll (add to the student list only)</option>' + state.sections.filter(function (x) { return x.year_active; }).map(function (x) {
        return '<option value="' + x.id + '"' + (x.id === imp.suggested ? ' selected' : '') + '>Grade ' + x.grade_level + ' – ' + esc(x.section_name) + ' (' + esc(x.year_label) + ')</option>';
      }).join('');
      $('impSection').innerHTML = opts;
      $('impPossibleWrap').hidden = s.possible_duplicate === 0; $('impPossible').checked = false; $('impPossibleN').textContent = s.possible_duplicate;
      $('impFilter').value = ''; impRender(); impStep(2);
      $('impGo').textContent = s.new ? 'Import ' + s.new + ' learner' + (s.new === 1 ? '' : 's') : 'Nothing to import';
      $('impGo').disabled = s.new === 0 && s.possible_duplicate === 0;
    }).catch(function (e) {
      impError(e.message + (e.payload && e.payload.errors && e.payload.errors.columns ? '' : ''));
    }).finally(function () { $('impCheck').disabled = false; $('impCheck').textContent = 'Check File'; });
  });

  $('impGo').addEventListener('click', function () {
    var n = imp.summary.new + ($('impPossible').checked ? imp.summary.possible_duplicate : 0);
    var secTxt = $('impSection').value ? ' and enroll them in ' + $('impSection').selectedOptions[0].textContent : '';
    if (!confirm('Import ' + n + ' learner(s)' + secTxt + '? Existing learners will not be changed.')) return;
    $('impGo').disabled = true; impError('');
    apiFetch('api/student_import.php', { method: 'POST', body: { action: 'commit', token: imp.token, section_id: $('impSection').value ? parseInt($('impSection').value, 10) : 0, allow_possible_duplicates: $('impPossible').checked } })
      .then(function (r) {
        var d = r.data;
        $('impDone').innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>Import finished: <strong>' + d.imported + '</strong> learner(s) added' + ($('impSection').value ? ', <strong>' + d.enrolled + '</strong> enrolled in the section' : '') + '.';
        $('impDoneCounts').innerHTML = countCard(d.imported, 'Successfully imported', 'text-success') + countCard(d.skipped, 'Skipped (total)') +
          countCard(d.duplicates, 'Duplicates', 'text-warning') + countCard(d.invalid, 'Invalid records', 'text-danger');
        var notes = '';
        if (d.enroll_failures.length) notes += '<div class="alert alert-warning small"><strong>Imported but not enrolled:</strong><ul class="mb-0">' + d.enroll_failures.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ul></div>';
        var skipped = d.rows.filter(function (x) { return x.result !== 'imported'; });
        if (skipped.length) notes += '<details><summary class="small">Skipped rows (' + skipped.length + ')</summary><ul class="small mt-2">' + skipped.map(function (x) { return '<li>Row ' + x.row + ' — ' + esc(x.name) + ': ' + esc((x.messages || []).join(' ') || x.result) + '</li>'; }).join('') + '</ul></details>';
        $('impDoneNotes').innerHTML = notes;
        impStep(3); toast(r.message);
        loadSections();
      })
      .catch(function (e) { $('impGo').disabled = false; impError(e.message); });
  });

  // ---- ASSIGN TEACHER TO GRADE LEVEL ----
  var glTeachers = [];
  function renderGradeTeachers() {
    var q = ($('glSearch').value || '').toLowerCase(), f = $('glFilter').value;
    var rows = glTeachers.filter(function (t) {
      var okQ = !q || (t.name + ' ' + t.username).toLowerCase().indexOf(q) !== -1;
      var okF = !f || (f === 'none' ? t.assigned_grade_level === null : String(t.assigned_grade_level) === f);
      return okQ && okF;
    });
    $('glBody').innerHTML = rows.map(function (t) {
      var grade = t.assigned_grade_level === null ? '<span class="pill">Not assigned</span>' : '<span class="pill pill-primary">Grade ' + t.assigned_grade_level + '</span>';
      return '<tr><td>' + esc(t.name) + '</td><td>' + esc(t.username) + '</td>' +
        '<td>' + esc(roleLabel(t.role)) + '</td>' +
        '<td>' + (t.is_active ? '<span class="pill pill-ok">Active</span>' : '<span class="pill pill-bad">Inactive</span>') + '</td>' +
        '<td>' + grade + '</td>' +
        '<td class="text-secondary small">' + t.adviser_count + ' adviser · ' + t.assignment_count + ' subject(s)</td>' +
        '<td class="cell-actions"><div class="row-actions">' +
          '<button class="btn btn-sm btn-primary" data-glassign="' + t.id + '">' + (t.assigned_grade_level === null ? 'Assign Teacher' : 'Change Grade') + '</button>' +
          (t.assigned_grade_level === null ? '' : '<button class="btn btn-sm btn-outline-danger" data-glremove="' + t.id + '">Remove</button>') +
        '</div></td></tr>';
    }).join('') || '<tr><td colspan="7" class="text-center text-secondary py-4">No teachers match.</td></tr>';
    document.querySelectorAll('[data-glassign]').forEach(function (b) { b.addEventListener('click', function () { openGradeModal(parseInt(b.dataset.glassign, 10)); }); });
    document.querySelectorAll('[data-glremove]').forEach(function (b) {
      b.addEventListener('click', function () {
        var t = glTeachers.filter(function (x) { return x.id === parseInt(b.dataset.glremove, 10); })[0];
        if (!confirm('Remove ' + t.name + ' from Grade ' + t.assigned_grade_level + '? They will no longer be limited to one grade level.')) return;
        apiFetch('api/admin.php', { method: 'POST', body: { resource: 'grade_assignments', action: 'unassign', teacher_id: t.id } })
          .then(function (r) { toast(r.message); loadGradeTeachers(); })
          .catch(function (e) { toast(e.message, 'error'); });
      });
    });
  }
  function loadGradeTeachers() {
    apiFetch('api/admin.php?resource=grade_assignments').then(function (r) { glTeachers = r.data.teachers; renderGradeTeachers(); }).catch(function (e) { toast(e.message, 'error'); });
  }
  $('glSearch').addEventListener('input', renderGradeTeachers);
  $('glFilter').addEventListener('change', renderGradeTeachers);
  function openGradeModal(id) {
    var t = glTeachers.filter(function (x) { return x.id === id; })[0];
    $('glErr').hidden = true; $('glTeacherId').value = id; $('glTeacherName').textContent = t.name;
    $('glGrade').value = t.assigned_grade_level === null ? '4' : String(t.assigned_grade_level);
    bootstrap.Modal.getOrCreateInstance($('glModal')).show();
  }
  function saveGrade(confirmConflicts) {
    apiFetch('api/admin.php', { method: 'POST', body: { resource: 'grade_assignments', action: 'assign', teacher_id: parseInt($('glTeacherId').value, 10), grade_level: parseInt($('glGrade').value, 10), confirm_conflicts: !!confirmConflicts } })
      .then(function (r) { bootstrap.Modal.getInstance($('glModal')).hide(); toast(r.message); loadGradeTeachers(); })
      .catch(function (e) {
        if (e.payload && e.payload.code === 'grade_conflict') {
          if (confirm(e.message + '\n\nAssign anyway?')) saveGrade(true);
          return;
        }
        $('glErr').textContent = e.message; $('glErr').hidden = false;
      });
  }
  $('glSave').addEventListener('click', function () { saveGrade(false); });

  loadAccounts();
});
</script>
<?php require __DIR__ . '/includes/multi_add_students.php'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
