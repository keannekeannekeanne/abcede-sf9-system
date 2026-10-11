<?php
// Login page / application entry point. Lives in the project root; every other page is in public/.
define('SF9_ROOT_PAGE', true);   // tells the shared error page to build links with the public/ prefix
require_once __DIR__ . '/src/Http/Http.php';
require_once __DIR__ . '/src/Auth/Auth.php';
require_once __DIR__ . '/src/Auth/Csrf.php';
Page::boot();

Auth::start();
if (Auth::check()) {
    // Role-based landing: every role currently shares the teacher dashboard.
    header('Location: public/dashboard.php');
    exit;
}

$error    = null;
$notice   = isset($_GET['expired']) ? 'Your session expired. Please log in again.' : null;
$username = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    try {
        Csrf::validateOrFail($_POST['csrf_token'] ?? null);
        if ($username === '' || $password === '') {
            $error = 'Please enter both your username and password.';
        } elseif (Auth::attempt($username, $password)) {
            header('Location: public/dashboard.php');
            exit;
        } else {
            $error = 'Invalid username or password.';
        }
    } catch (HttpException $e) {
        $error = $e->getMessage();   // e.g. expired form token: show it inline on the form
    }
}

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Log in · SF9 System</title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="public/assets/img/abcede-logo.jpg" rel="icon">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="public/assets/css/app.css" rel="stylesheet">
</head>
<body>
<div class="auth-wrap">
  <aside class="auth-aside">
    <div class="brand text-white">
      <span class="brand-mark brand-mark-img" style="background:#fff"><img src="public/assets/img/school-seal.png" alt="" width="34" height="34"></span>
      <span>SF9 System<small class="text-white-50">Dr. Domingo G. Abcede Elementary School</small></span>
    </div>
    <div>
      <h2>Grades and report cards, in one place.</h2>
      <p>Manage your sections, record term grades and print each learner's SF9 without retyping a thing.</p>
    </div>
    <small class="text-white-50">Teachers sign in with the account issued by the school.</small>
  </aside>

  <main class="auth-main">
    <div class="surface auth-card">
      <a class="brand d-lg-none" href="index.php"><span class="brand-mark brand-mark-img"><img src="public/assets/img/school-seal.png" alt="" width="34" height="34"></span><span>SF9 System</span></a>
      <h1 class="h4 mb-1">Welcome back</h1>
      <p class="text-secondary mb-4">Log in to open your classes.</p>

      <?php if ($notice): ?><div class="alert alert-info py-2" role="status"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
      <?php if ($error): ?><div class="alert alert-danger py-2" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
      <div id="clientError" class="alert alert-danger py-2" role="alert" hidden></div>

      <form method="post" id="loginForm" novalidate>
        <?= Csrf::field() ?>
        <div class="mb-3">
          <label class="form-label" for="username">Username</label>
          <input type="text" id="username" name="username" class="form-control" autocomplete="username" autocapitalize="none" spellcheck="false" value="<?= htmlspecialchars($username, ENT_QUOTES) ?>" required autofocus>
        </div>
        <div class="mb-2">
          <label class="form-label" for="password">Password</label>
          <div class="input-icon">
            <input type="password" id="password" name="password" class="form-control" autocomplete="current-password" required>
            <button type="button" id="togglePw" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye"></i></button>
          </div>
        </div>
        <p class="small text-secondary mb-4">Forgot your password? Ask the school administrator to reset it.</p>
        <button type="submit" class="btn btn-primary w-100 py-2" id="loginBtn">
          <span class="spinner-border spinner-border-sm me-2" id="loginSpin" hidden aria-hidden="true"></span>
          <span id="loginLabel">Log in</span>
        </button>
      </form>
    </div>
  </main>
</div>

<script>
(function () {
  var form = document.getElementById('loginForm');
  var pw = document.getElementById('password');
  var toggle = document.getElementById('togglePw');
  var err = document.getElementById('clientError');

  toggle.addEventListener('click', function () {
    var show = pw.type === 'password';
    pw.type = show ? 'text' : 'password';
    toggle.setAttribute('aria-pressed', String(show));
    toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    toggle.firstElementChild.className = 'bi ' + (show ? 'bi-eye-slash' : 'bi-eye');
  });

  form.addEventListener('submit', function (e) {
    var u = document.getElementById('username').value.trim();
    if (!u || !pw.value) {
      e.preventDefault();
      err.textContent = 'Please enter both your username and password.';
      err.hidden = false;
      return;
    }
    err.hidden = true;
    document.getElementById('loginBtn').disabled = true;   // loading state; the page reloads on response
    document.getElementById('loginSpin').hidden = false;
    document.getElementById('loginLabel').textContent = 'Logging in…';
  });

  // Coming back via the browser's Back button must not leave the button stuck.
  window.addEventListener('pageshow', function (ev) {
    if (ev.persisted) {
      document.getElementById('loginBtn').disabled = false;
      document.getElementById('loginSpin').hidden = true;
      document.getElementById('loginLabel').textContent = 'Log in';
    }
  });
})();
</script>
</body>
</html>
