<?php
require __DIR__ . '/../app/bootstrap.php';
start_session();
if (current_user()) { header('Location: index.php'); exit; }
$uid = (int)($_SESSION['2fa_uid'] ?? 0);
if (!$uid || time() - (int)($_SESSION['2fa_started'] ?? 0) > 1800) { unset($_SESSION['2fa_uid'], $_SESSION['2fa_started']); flash('Please sign in again.', 'err'); header('Location: login.php'); exit; }
$u = q('SELECT * FROM users WHERE id=? AND active=1', [$uid])->fetch();
if (!$u) { header('Location: login.php'); exit; }
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'resend') {
        [$ok, $e2] = twofa_send_code($u, 'login');
        flash($ok ? 'A new code is on its way.' : $e2, $ok ? 'ok' : 'err'); header('Location: verify.php'); exit;
    }
    [$ok, $err] = twofa_verify($uid, (string)($_POST['code'] ?? ''), 'login');
    if ($ok) {
        session_regenerate_id(true);
        unset($_SESSION['2fa_uid'], $_SESSION['2fa_started']);
        $_SESSION['uid'] = $uid; $_SESSION['last'] = time();
        q('UPDATE users SET last_login=NOW() WHERE id=?', [$uid]);
        $dest = post_login_url($u); current_user(true);
        if (!empty($_POST['remember'])) twofa_trust_device($uid);
        log_activity('Logged in (two-step verified' . (!empty($_POST['remember']) ? ', device remembered' : '') . ')');
        header('Location: ' . $dest); exit;
    }
}
$masked = preg_replace('/(?<=.).(?=[^@]*@)/', '*', $u['email']);
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Verify your sign-in</title>
<link rel="icon" href="../favicon.ico?v=1" sizes="any"><link rel="shortcut icon" href="../favicon.ico?v=1"><link rel="stylesheet" href="assets/admin.css?v=13"></head><body class="auth-body"><div class="auth-card">
<img class="auth-logo" src="<?= e(admin_logo_path()) ?>" height="56" alt="<?= e(setting('site_name', 'TopTen Form')) ?>">
<h1 class="center">Check your email</h1>
<?php if ($f = flash()): ?><div class="alert <?= $f[1] ?>"><?= e($f[0]) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>
<p class="muted" style="text-align:center;margin-top:-8px">We sent a 6-digit code to <b><?= e($masked) ?></b>.</p>
<form method="post"><?= csrf_field() ?>
<label>Verification code<input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]*" maxlength="9" required autofocus placeholder="123456" style="text-align:center;font-size:1.5rem;letter-spacing:.35em"></label>
<label class="inline"><input type="checkbox" name="remember" value="1"> Remember this device for 30 days</label>
<button class="btn" type="submit" style="width:100%">Verify and sign in</button></form>
<form method="post" style="margin-top:12px"><?= csrf_field() ?><input type="hidden" name="action" value="resend"><button class="btn sec" style="width:100%">Send a new code</button></form>
<p style="margin-top:14px"><a href="login.php">Back to login</a></p>
</div></body></html>
