<?php
require __DIR__ . '/../app/bootstrap.php';
start_session();
$token = $_GET['token'] ?? ($_POST['token'] ?? '');
$row = q('SELECT * FROM password_resets WHERE token_hash=? AND expires_at > NOW()', [hash('sha256', $token)])->fetch();
$err = '';
if ($row && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (strlen($_POST['password'] ?? '') < 8) $err = 'Password must be at least 8 characters.';
    elseif ($_POST['password'] !== ($_POST['password2'] ?? '')) $err = 'Passwords do not match.';
    else {
        q('UPDATE users SET password=?, failed_logins=0, locked_until=NULL WHERE id=?', [password_hash($_POST['password'], PASSWORD_DEFAULT), $row['user_id']]);
        q('DELETE FROM password_resets WHERE user_id=?', [$row['user_id']]); q('DELETE FROM trusted_devices WHERE user_id=?', [$row['user_id']]); // new password = forget remembered devices
        flash('Password updated. You can log in now.'); header('Location: login.php'); exit;
    }
}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Reset password</title>
<link rel="icon" href="../favicon.ico?v=1" sizes="any"><link rel="shortcut icon" href="../favicon.ico?v=1"><link rel="stylesheet" href="assets/admin.css?v=6"></head><body class="auth-body"><div class="auth-card">
<img class="auth-logo" src="<?= e(admin_logo_path()) ?>" height="56" alt="<?= e(setting('site_name', 'TopTen Form')) ?>">
<h1 class="center">Reset password</h1>
<?php if (!$row): ?><div class="alert err">This link is invalid or has expired.</div><a href="forgot.php">Request a new link</a>
<?php else: ?>
<?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
<label>New password<input type="password" name="password" required minlength="8"></label>
<label>Confirm password<input type="password" name="password2" required></label>
<button class="btn" style="width:100%">Update password</button></form><?php endif; ?>
</div></body></html>
