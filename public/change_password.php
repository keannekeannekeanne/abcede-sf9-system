<?php
$pageTitle  = 'Change Password';
$activePage = '';
require __DIR__ . '/includes/header.php';
?>
<div class="auth-narrow">
  <div class="surface">
    <div class="surface-head"><h2>Set a new password</h2></div>
    <div class="surface-body">
      <p class="text-secondary small">Your password was reset by an Administrator. Choose a new password before continuing.</p>
      <div id="cpErr" class="alert alert-danger" hidden></div>
      <label class="form-label small">New password</label>
      <input class="form-control mb-3" id="cpNew" type="password" placeholder="At least 8 characters">
      <label class="form-label small">Confirm password</label>
      <input class="form-control mb-3" id="cpConfirm" type="password">
      <button class="btn btn-primary" id="cpSave">Save Password</button>
    </div>
  </div>
</div>
<script>
document.getElementById('cpSave').addEventListener('click', function () {
  var a = document.getElementById('cpNew').value, b = document.getElementById('cpConfirm').value;
  var err = document.getElementById('cpErr');
  if (a.length < 8) { err.textContent = 'Password must be at least 8 characters.'; err.hidden = false; return; }
  if (a !== b) { err.textContent = 'Passwords do not match.'; err.hidden = false; return; }
  apiFetch('api/change_password.php', { method: 'POST', body: { new_password: a } })
    .then(function () { window.location.href = 'dashboard.php'; })
    .catch(function (e) { err.textContent = e.message; err.hidden = false; });
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
