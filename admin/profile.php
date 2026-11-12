<?php
require __DIR__ . '/_layout.php';
$me = require_user();
$self = q('SELECT * FROM users WHERE id=?', [$me['id']])->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? 'profile';
    if ($act === 'profile') {
        $name = trim($_POST['name'] ?? ''); $email = trim($_POST['email'] ?? '');
        $dup = q('SELECT id FROM users WHERE email=? AND id<>?', [$email, $me['id']])->fetch();
        if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL)) flash('Enter a name and a valid email.', 'err');
        elseif ($dup) flash('That email is already used.', 'err');
        else {
            q('UPDATE users SET name=?, email=? WHERE id=?', [$name, $email, $me['id']]);
            if ($_POST['new_password'] !== '') {
                if (!password_verify($_POST['current_password'] ?? '', $self['password'])) { flash('Current password is incorrect.', 'err'); header('Location: profile.php'); exit; }
                if (strlen($_POST['new_password']) < 8) { flash('New password must be at least 8 characters.', 'err'); header('Location: profile.php'); exit; }
                q('UPDATE users SET password=? WHERE id=?', [password_hash($_POST['new_password'], PASSWORD_DEFAULT), $me['id']]);
                twofa_forget_devices((int)$me['id']); // a new password signs out every remembered device
                log_activity('Changed own password');
            }
            flash('Profile updated.');
        }
    } elseif ($act === '2fa_start') {
        if (twofa_globally_off()) { flash('Two-step verification is switched off in config/config.php.', 'err'); }
        else {
            [$ok, $err] = twofa_send_code($self, 'enable');
            if ($ok) { $_SESSION['2fa_enabling'] = time(); flash('We emailed a code to ' . $self['email'] . '. Enter it below to finish turning on two-step verification.'); }
            else flash('Could not send the code: ' . $err . ' (Set up working email in Settings first.)', 'err');
        }
    } elseif ($act === '2fa_confirm') {
        [$ok, $err] = twofa_verify((int)$me['id'], (string)($_POST['code'] ?? ''), 'enable');
        if ($ok) { q('UPDATE users SET twofa_enabled=1 WHERE id=?', [$me['id']]); unset($_SESSION['2fa_enabling']); twofa_trust_device((int)$me['id']); log_activity('Turned on two-step verification'); flash('Two-step verification is ON. This device is remembered for 30 days.'); }
        else flash($err, 'err');
    } elseif ($act === '2fa_off') {
        if (!password_verify($_POST['password'] ?? '', $self['password'])) flash('Password is incorrect.', 'err');
        else { q('UPDATE users SET twofa_enabled=0 WHERE id=?', [$me['id']]); twofa_forget_devices((int)$me['id']); q('DELETE FROM twofa_codes WHERE user_id=?', [$me['id']]); log_activity('Turned off two-step verification'); flash('Two-step verification is now off.'); }
    } elseif ($act === '2fa_forget') {
        twofa_forget_devices((int)$me['id']); flash('All remembered devices were removed. You will be asked for a code on your next sign-in.');
    }
    header('Location: profile.php'); exit;
}
$u = $self;
$devices = (int)q('SELECT COUNT(*) FROM trusted_devices WHERE user_id=? AND expires_at > NOW()', [$me['id']])->fetchColumn();
$pending = !empty($_SESSION['2fa_enabling']) && time() - $_SESSION['2fa_enabling'] < TWOFA_TTL;
admin_header('My profile', '', '', 'Your details, password and sign-in security'); ?>
<div class="grid2">
<div class="card"><h2>Account</h2><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="profile">
<label>Name<input name="name" value="<?= e($u['name']) ?>" required></label>
<label>Email<input type="email" name="email" value="<?= e($u['email']) ?>" required></label>
<h3>Change password</h3>
<label>Current password<input type="password" name="current_password" autocomplete="current-password"></label>
<label>New password <small>(leave blank to keep)</small><input type="password" name="new_password" autocomplete="new-password"></label>
<button class="btn">Save</button></form></div>

<div class="card"><h2>Two-step verification <span class="tag <?= $u['twofa_enabled'] ? 'green' : '' ?>"><?= $u['twofa_enabled'] ? 'On' : 'Off' ?></span></h2>
<p class="muted" style="margin-top:-6px">Adds a second step to signing in: after your password, we email a 6-digit code to <b><?= e($u['email']) ?></b>. You can choose to remember a device for 30 days so you are not asked every time.</p>
<?php if (twofa_globally_off()): ?><div class="alert err">Two-step verification is switched off for the whole app in <code>config/config.php</code>.</div>
<?php elseif (!$u['twofa_enabled']): ?>
  <?php if ($pending): ?>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="2fa_confirm"><label>Enter the code we emailed you<input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="9" required autofocus placeholder="123456"></label>
  <div class="actions"><button class="btn">Turn on</button></div></form>
  <form method="post" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="action" value="2fa_start"><button class="btn sec sm">Send a new code</button></form>
  <?php else: ?>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="2fa_start"><button class="btn">Turn on two-step verification</button></form>
  <p class="muted" style="margin-bottom:0">We'll email a code first to make sure your email works, so you can't lock yourself out.</p>
  <?php endif; ?>
<?php else: ?>
  <p>Trusted devices right now: <b><?= $devices ?></b></p>
  <div class="actions"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="2fa_forget"><button class="btn sec">Forget all trusted devices</button></form></div>
  <h3>Turn off</h3>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="2fa_off"><label>Confirm with your password<input type="password" name="password" required autocomplete="current-password"></label><button class="btn danger">Turn off two-step verification</button></form>
<?php endif; ?></div>
</div>
<?php admin_footer();
