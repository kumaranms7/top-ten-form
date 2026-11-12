<?php
require __DIR__ . '/../app/bootstrap.php';
start_session();
if (current_user()) { header('Location: index.php'); exit; }
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = trim($_POST['email'] ?? '');
    $u = q('SELECT * FROM users WHERE email=?', [$email])->fetch();
    if ($u && $u['locked_until'] && strtotime($u['locked_until']) > time()) {
        $err = 'Account temporarily locked. Try again in a few minutes.';
    } elseif ($u && $u['active'] && password_verify($_POST['password'] ?? '', $u['password']) && !$u['is_super'] && !user_site_list((int)$u['id'], false)) {
        $err = 'Your account is not linked to an active website yet. Please contact your administrator.';
    } elseif ($u && $u['active'] && password_verify($_POST['password'] ?? '', $u['password'])) {
        session_regenerate_id(true);
        q('UPDATE users SET failed_logins=0, locked_until=NULL WHERE id=?', [$u['id']]);
        if ($u['twofa_enabled'] && !twofa_globally_off() && !twofa_is_trusted((int)$u['id'])) {
            // password is right, but this browser is not trusted: ask for the emailed code before granting access
            $_SESSION['2fa_uid'] = (int)$u['id']; $_SESSION['2fa_started'] = time();
            [$sent, $serr] = twofa_send_code($u, 'login');
            if (!$sent) flash('We could not email your verification code (' . $serr . '). Ask an administrator to reset your two-step verification.', 'err');
            header('Location: verify.php'); exit;
        }
        $_SESSION['uid'] = $u['id']; $_SESSION['last'] = time();
        q('UPDATE users SET last_login=NOW() WHERE id=?', [$u['id']]);
        $dest = post_login_url($u); current_user(true);
        log_activity('Logged in');
        header('Location: ' . $dest); exit;
    } else {
        if ($u) {
            $n = $u['failed_logins'] + 1;
            q('UPDATE users SET failed_logins=?, locked_until=? WHERE id=?', [$n, $n >= 5 ? date('Y-m-d H:i:s', time() + 900) : null, $u['id']]);
            if ($n >= 5) q('UPDATE users SET failed_logins=0 WHERE id=?', [$u['id']]);
        }
        $err = 'Invalid email or password.';
    }
}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Login</title>
<link rel="icon" href="../favicon.ico?v=1" sizes="any"><link rel="shortcut icon" href="../favicon.ico?v=1"><link rel="stylesheet" href="assets/admin.css?v=6"></head><body class="auth-body"><div class="auth-card">
<img class="auth-logo" src="<?= e(admin_logo_path()) ?>" height="56" alt="<?= e(setting('site_name', 'TopTen Form')) ?>">
<h1 class="center">Welcome back</h1>
<?php if ($f = flash()): ?><div class="alert <?= $f[1] ?>"><?= e($f[0]) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>
<form method="post"><?= csrf_field() ?>
<label>Email<input type="email" name="email" required autofocus></label>
<label>Password<input type="password" name="password" required></label>
<button class="btn" type="submit" style="width:100%">Log in</button></form>
<p style="margin-top:14px"><a href="forgot.php">Forgot password?</a></p>
</div></body></html>
