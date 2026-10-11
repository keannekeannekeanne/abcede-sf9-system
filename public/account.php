<?php
$pageTitle  = 'Account Settings';
$activePage = '';
require __DIR__ . '/includes/header.php';

try {
    $stmt = get_db()->prepare('SELECT username, email, first_name, last_name, middle_name, role, assigned_grade_level FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([Auth::userId()]);
} catch (PDOException $e) {   // migration 004 not applied yet: no grade-level column
    $stmt = get_db()->prepare('SELECT username, email, first_name, last_name, middle_name, role, NULL AS assigned_grade_level FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([Auth::userId()]);
}
$me = $stmt->fetch();
if (!$me) { throw new HttpException(404, 'Account not found.'); }
$roleLabels = ['teacher' => 'Teacher', 'master_teacher' => 'Master Teacher', 'principal' => 'Principal', 'admin' => 'Administrator'];
?>
<div class="page-head">
  <div><h1>Account Settings</h1><p class="text-secondary mb-0">Manage your own profile and password.</p></div>
</div>

<div class="row g-3">
  <div class="col-12 col-lg-6">
    <div class="surface h-100">
      <div class="surface-head"><h2>Profile</h2></div>
      <div class="surface-body">
        <div id="profErr" class="alert alert-danger" hidden></div>
        <div class="row g-2">
          <div class="col-12 col-md-6"><label class="form-label small" for="pfFirst">First name</label><input class="form-control" id="pfFirst" maxlength="100" value="<?= htmlspecialchars($me['first_name']) ?>"><div class="invalid-feedback"></div></div>
          <div class="col-12 col-md-6"><label class="form-label small" for="pfLast">Last name</label><input class="form-control" id="pfLast" maxlength="100" value="<?= htmlspecialchars($me['last_name']) ?>"><div class="invalid-feedback"></div></div>
          <div class="col-12 col-md-6"><label class="form-label small" for="pfMiddle">Middle name</label><input class="form-control" id="pfMiddle" maxlength="100" value="<?= htmlspecialchars((string) $me['middle_name']) ?>"><div class="invalid-feedback"></div></div>
          <div class="col-12 col-md-6"><label class="form-label small" for="pfEmail">Email</label><input class="form-control" id="pfEmail" type="email" maxlength="150" value="<?= htmlspecialchars((string) $me['email']) ?>"><div class="invalid-feedback"></div></div>
        </div>
        <hr>
        <dl class="row small mb-3">
          <dt class="col-5 col-md-4 text-secondary fw-normal">Username</dt><dd class="col-7 col-md-8 mb-1"><?= htmlspecialchars($me['username']) ?></dd>
          <dt class="col-5 col-md-4 text-secondary fw-normal">Role</dt><dd class="col-7 col-md-8 mb-1"><?= htmlspecialchars($roleLabels[$me['role']] ?? $me['role']) ?></dd>
          <?php if ($me['assigned_grade_level'] !== null): ?>
            <dt class="col-5 col-md-4 text-secondary fw-normal">Assigned grade</dt><dd class="col-7 col-md-8 mb-1">Grade <?= (int) $me['assigned_grade_level'] ?></dd>
          <?php endif; ?>
        </dl>
        <p class="small text-secondary">Your username, role and assigned grade level are managed by an Administrator.</p>
        <button class="btn btn-primary" id="pfSave">Save Profile</button>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="surface h-100">
      <div class="surface-head"><h2>Change Password</h2></div>
      <div class="surface-body">
        <div id="pwErr" class="alert alert-danger" hidden></div>
        <label class="form-label small" for="pwCur">Current password</label>
        <input class="form-control mb-2" id="pwCur" type="password" autocomplete="current-password">
        <label class="form-label small" for="pwNew">New password</label>
        <input class="form-control mb-2" id="pwNew" type="password" autocomplete="new-password" placeholder="At least 8 characters">
        <label class="form-label small" for="pwConf">Confirm new password</label>
        <input class="form-control mb-3" id="pwConf" type="password" autocomplete="new-password">
        <button class="btn btn-primary" id="pwSave">Change Password</button>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  function $(id) { return document.getElementById(id); }
  function showErr(box, msg) { box.textContent = msg; box.hidden = !msg; }
  function clearInvalid(root) { root.querySelectorAll('.is-invalid').forEach(function (e) { e.classList.remove('is-invalid'); }); }
  function fieldErrors(map, errors) {
    Object.keys(errors || {}).forEach(function (k) {
      var el = $(map[k]); if (!el) return;
      el.classList.add('is-invalid'); var fb = el.parentNode.querySelector('.invalid-feedback'); if (fb) fb.textContent = errors[k];
    });
  }

  $('pfSave').addEventListener('click', function () {
    clearInvalid(document); showErr($('profErr'), '');
    var btn = $('pfSave'); btn.disabled = true;
    apiFetch('api/account.php', { method: 'POST', body: { action: 'profile', first_name: $('pfFirst').value, last_name: $('pfLast').value, middle_name: $('pfMiddle').value, email: $('pfEmail').value } })
      .then(function (r) {
        toast(r.message);
        var who = document.querySelector('.side-user .who strong'); if (who) who.textContent = r.data.full_name;
        var av = document.querySelector('.side-user .avatar'); if (av) av.textContent = (r.data.full_name.split(' ').map(function (p) { return p.charAt(0); }).join('').slice(0, 1) + r.data.full_name.split(' ').slice(-1)[0].charAt(0)).toUpperCase();
      })
      .catch(function (e) {
        showErr($('profErr'), e.message);
        fieldErrors({ first_name: 'pfFirst', last_name: 'pfLast', middle_name: 'pfMiddle', email: 'pfEmail' }, e.payload && e.payload.errors);
      })
      .finally(function () { btn.disabled = false; });
  });

  $('pwSave').addEventListener('click', function () {
    clearInvalid(document); showErr($('pwErr'), '');
    var cur = $('pwCur').value, nw = $('pwNew').value, cf = $('pwConf').value;
    if (!cur) { showErr($('pwErr'), 'Enter your current password.'); return; }
    if (nw.length < 8) { showErr($('pwErr'), 'New password must be at least 8 characters.'); return; }
    if (nw !== cf) { showErr($('pwErr'), 'The new passwords do not match.'); return; }
    var btn = $('pwSave'); btn.disabled = true;
    apiFetch('api/account.php', { method: 'POST', body: { action: 'password', current_password: cur, new_password: nw } })
      .then(function (r) { toast(r.message); $('pwCur').value = $('pwNew').value = $('pwConf').value = ''; })
      .catch(function (e) { showErr($('pwErr'), e.message); })
      .finally(function () { btn.disabled = false; });
  });
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
