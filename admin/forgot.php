<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/mailer.php';
start_session();
$sent = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $u = q('SELECT * FROM users WHERE email=? AND active=1', [trim($_POST['email'] ?? '')])->fetch();
    if ($u) {
        $token = bin2hex(random_bytes(32));
        q('DELETE FROM password_resets WHERE user_id=?', [$u['id']]);
        q('INSERT INTO password_resets (user_id,token_hash,expires_at) VALUES (?,?,?)', [$u['id'], hash('sha256', $token), date('Y-m-d H:i:s', time() + 3600)]);
        $link = base_url() . '/admin/reset.php?token=' . $token;
        send_mail(['to' => $u['email'], 'from_email' => '', 'from_name' => '', 'subject' => 'Reset your password',
            'body' => wrap_email_html('<p>Hi ' . e($u['name']) . ',</p><p>Click the link below to reset your password (valid for 1 hour):</p><p><a href="' . e($link) . '">' . e($link) . '</a></p><p>If you did not request this, ignore this email.</p>')]);
    }
    $sent = true; // same response whether or not the account exists
}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Forgot password</title>
<link rel="icon" href="../favicon.ico?v=1" sizes="any"><link rel="shortcut icon" href="../favicon.ico?v=1"><link rel="stylesheet" href="assets/admin.css?v=6"></head><body class="auth-body"><div class="auth-card">
<img class="auth-logo" src="<?= e(admin_logo_path()) ?>" height="56" alt="<?= e(setting('site_name', 'TopTen Form')) ?>">
<h1 class="center">Forgot password</h1>
<?php if ($sent): ?><div class="alert ok">If that email exists, a reset link has been sent.</div><?php else: ?>
<form method="post"><?= csrf_field() ?><label>Email<input type="email" name="email" required autofocus></label>
<button class="btn" style="width:100%">Send reset link</button></form><?php endif; ?>
<p style="margin-top:14px"><a href="login.php">Back to login</a></p></div></body></html>
