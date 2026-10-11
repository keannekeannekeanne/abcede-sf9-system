<?php
require_once __DIR__ . '/../../src/Http/Http.php';
Json::boot();
require_once __DIR__ . '/../../src/Auth/Auth.php';
require_once __DIR__ . '/../../src/Auth/Csrf.php';
require_once __DIR__ . '/../../src/Admin/AdminService.php';

Auth::requireLogin();
Json::requireMethod('POST');
$body = Json::body();
Csrf::validateOrFail(Csrf::fromRequest($body));

// This endpoint exists for the forced "set a new password" screen after an Admin reset. Everyone else changes
// their password from Account Settings (api/account.php), which asks for the current password first.
if (!Auth::mustChangePassword()) {
    throw new HttpException(403, 'Use Account Settings to change your password.');
}

$new = (string) ($body['new_password'] ?? '');
AdminService::validatePassword($new);

$hash = password_hash($new, PASSWORD_DEFAULT);
$stmt = get_db()->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?');
$stmt->execute([$hash, Auth::userId()]);
$_SESSION['must_change_password'] = false;   // keep the session in sync — DB alone isn't enough, the gate in header.php reads this

Json::ok(null, 'Password updated.');
