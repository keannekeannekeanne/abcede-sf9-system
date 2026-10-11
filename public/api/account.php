<?php
// Account Settings — a signed-in user managing THEIR OWN account only.
//
//   POST {action:'profile',  first_name, last_name, middle_name, email}
//   POST {action:'password', current_password, new_password}
//
// The account being changed is ALWAYS the one in the session (Auth::userId()). Any user_id / username /
// role / assigned_grade_level / is_active sent by the browser is ignored, so a teacher cannot edit
// another account, promote themselves to Admin, or change their own grade-level assignment.
require_once __DIR__ . '/../../src/Http/Http.php';
Json::boot();
require_once __DIR__ . '/../../src/Auth/Auth.php';
require_once __DIR__ . '/../../src/Auth/Csrf.php';
require_once __DIR__ . '/../../src/Admin/AdminService.php';

Auth::requireLogin();
Json::requireMethod('POST');
$body = Json::body();
Csrf::validateOrFail(Csrf::fromRequest($body));

$me = Auth::userId();
$pdo = get_db();
$action = (string) ($body['action'] ?? '');

function account_audit(int $userId, string $action, string $details): void
{
    try {
        get_db()->prepare('INSERT INTO account_audit_log (target_user_id, action, performed_by, details) VALUES (?, ?, ?, ?)')
            ->execute([$userId, $action, $userId, $details]);
    } catch (PDOException $e) {
        error_log('[sf9] account audit skipped (apply migration 004?): ' . $e->getMessage());   // never block the user's change
    }
}

if ($action === 'profile') {
    $first  = trim((string) ($body['first_name'] ?? ''));
    $last   = trim((string) ($body['last_name'] ?? ''));
    $middle = trim((string) ($body['middle_name'] ?? ''));
    $email  = trim((string) ($body['email'] ?? ''));

    $errors = [];
    if ($first === '' || mb_strlen($first) > 100)  { $errors['first_name'] = 'First name is required (max 100 characters).'; }
    if ($last === '' || mb_strlen($last) > 100)    { $errors['last_name'] = 'Last name is required (max 100 characters).'; }
    if (mb_strlen($middle) > 100)                  { $errors['middle_name'] = 'Middle name is too long (max 100 characters).'; }
    if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150)) { $errors['email'] = 'Enter a valid email address.'; }
    if ($errors) {
        throw new HttpException(422, 'Please correct the highlighted fields.', ['errors' => $errors]);
    }

    // Only these four columns can change here. role, username, is_active and assigned_grade_level are never touched.
    $pdo->prepare('UPDATE users SET first_name = ?, last_name = ?, middle_name = ?, email = ? WHERE id = ?')
        ->execute([$first, $last, $middle !== '' ? $middle : null, $email !== '' ? $email : null, $me]);
    $_SESSION['full_name'] = trim($first . ' ' . $last);          // sidebar name updates immediately
    account_audit($me, 'self_edited', 'profile');

    Json::ok(['full_name' => $_SESSION['full_name']], 'Your profile was updated.');
}

if ($action === 'password') {
    // Throttle guessing of the current password from a hijacked session: 5 misses / 15 minutes.
    $tries = array_filter($_SESSION['pw_fail'] ?? [], static fn($t) => $t > time() - 900);
    if (count($tries) >= 5) {
        throw new HttpException(429, 'Too many incorrect attempts. Please wait 15 minutes and try again.');
    }

    $current = (string) ($body['current_password'] ?? '');
    $new     = (string) ($body['new_password'] ?? '');

    $row = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
    $row->execute([$me]);
    $hash = (string) $row->fetchColumn();
    if ($current === '' || !password_verify($current, $hash)) {
        $tries[] = time();
        $_SESSION['pw_fail'] = array_values($tries);
        throw new HttpException(422, 'Your current password is incorrect.', ['errors' => ['current_password' => 'Your current password is incorrect.']]);
    }
    AdminService::validatePassword($new);                            // same rule as everywhere else (min 8)
    if (hash_equals($current, $new)) {
        throw new HttpException(422, 'Choose a new password that is different from your current one.', ['errors' => ['new_password' => 'Must be different from your current password.']]);
    }

    $pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?')
        ->execute([password_hash($new, PASSWORD_DEFAULT), $me]);
    $_SESSION['must_change_password'] = false;
    unset($_SESSION['pw_fail']);
    session_regenerate_id(true);                                     // new session id after a credential change
    account_audit($me, 'password_changed', '');

    Json::ok(null, 'Your password was changed.');
}

throw new HttpException(422, 'Unknown account action.');
