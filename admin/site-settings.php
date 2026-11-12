<?php
require __DIR__ . '/_layout.php';
$me = require_login('admin');          // website admins (and a super admin who entered the website)
$sid = (int)site_id(); $site = current_site();
$own = fn(string $k, string $d = '') => (string)site_own_setting($sid, $k, $d);
$plain = ['mail_from_name', 'mail_from_email', 'mail_reply_to', 'smtp_host', 'smtp_port', 'smtp_secure', 'smtp_user', 'email_header_color', 'email_footer_text', 'email_logo_width', 'email_logo_link',
    'captcha_site_key', 'retention_days', 'spam_retention_days', 'status_reset_days'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? 'save';
    if ($act === 'testmail') {
        require_once __DIR__ . '/../app/mailer.php';
        [$ok, $err] = send_mail(['to' => $me['email'], 'from_email' => '', 'from_name' => '', 'subject' => 'Test email from ' . $site['name'], 'body' => wrap_email_html('<p>Your email settings for <b>' . e($site['name']) . '</b> work.</p>')]);
        flash($ok ? 'Test email sent to ' . $me['email'] : 'Test failed: ' . $err, $ok ? 'ok' : 'err');
    } else {
        $err = save_site_details($sid, $_POST);
        $bad = '';
        foreach (['mail_from_email', 'mail_reply_to'] as $k) if (trim($_POST[$k] ?? '') !== '' && !filter_var(trim($_POST[$k]), FILTER_VALIDATE_EMAIL)) $bad = 'The sender / reply-to email is not valid.';
        if (!$err && $bad) $err = $bad;
        if (!$err) {
            foreach ($plain as $k) if (isset($_POST[$k])) set_site_setting($sid, $k, trim((string)$_POST[$k]));
            foreach (['retention_days', 'spam_retention_days', 'status_reset_days'] as $k) { $v = trim((string)($_POST[$k] ?? '')); set_site_setting($sid, $k, $v === '' ? '' : (string)max(0, (int)$v)); }
            if (($c = trim((string)($_POST['email_header_color'] ?? ''))) !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $c)) set_site_setting($sid, 'email_header_color', '');
            if (($w = (int)($_POST['email_logo_width'] ?? 0)) > 0) set_site_setting($sid, 'email_logo_width', (string)max(60, min(400, $w)));
            set_site_setting($sid, 'smtp_enabled', empty($_POST['smtp_enabled']) ? '0' : '1');
            set_site_setting($sid, 'email_branded', empty($_POST['email_branded']) ? '0' : '1');
            if (($_POST['smtp_pass'] ?? '') !== '') set_site_setting($sid, 'smtp_pass', $_POST['smtp_pass']);
            $prov = in_array($_POST['captcha_provider'] ?? '', ['none', 'recaptcha', 'turnstile'], true) ? $_POST['captcha_provider'] : 'inherit';
            set_site_setting($sid, 'captcha_provider', $prov);
            if (($_POST['captcha_secret_key'] ?? '') !== '') set_site_setting($sid, 'captcha_secret_key', trim($_POST['captcha_secret_key']));
            // site logo (shown next to the brand logo in the admin and at the top of emails)
            if (!empty($_POST['rm_logo']) && $site['logo']) { delete_media($site['logo']); q('UPDATE sites SET logo=NULL WHERE id=?', [$sid]); }
            [$file, $lerr] = save_logo_upload($_FILES['site_logo'] ?? null);
            if ($lerr) $err = 'Saved, but the logo was not changed: ' . $lerr;
            elseif ($file) { if ($site['logo']) delete_media($site['logo']); q('UPDATE sites SET logo=? WHERE id=?', [$file, $sid]); }
            log_activity('Updated site settings');
        }
        flash($err ?: 'Site settings saved.', $err ? 'err' : 'ok');
    }
    header('Location: site-settings.php'); exit;
}
$site = site_by_id($sid);
$logoOk = $site['logo'] && is_file(media_dir() . '/' . $site['logo']);
$prov = $own('captcha_provider', 'inherit');
admin_header('Site settings', 'settings', '', 'Details, logo, email and security for ' . $site['name']); ?>
<form method="post" enctype="multipart/form-data"><?= csrf_field() ?>
<div class="grid2">
<div class="card"><h2>Website details</h2>
<label>Website name<input name="name" value="<?= e($site['name']) ?>" required maxlength="150"></label>
<div class="row"><label>Company<input name="company" value="<?= e($site['company']) ?>"></label><label>Phone<input name="phone" value="<?= e($site['phone']) ?>"></label></div>
<div class="row"><label>Website address<input name="website_url" value="<?= e($site['website_url']) ?>" placeholder="https://www.example.com"></label><label>Contact email<input type="email" name="contact_email" value="<?= e($site['contact_email']) ?>"></label></div>
<label>Address<input name="address" value="<?= e($site['address']) ?>"></label>
<label>Allowed domains <small>(one per line. Forms of this website only accept submissions from these domains, subdomains included. Empty = any website)</small><textarea name="allowed_domains" rows="3" placeholder="example.com"><?= e($site['allowed_domains']) ?></textarea></label>
<h3>Site logo</h3><p class="muted" style="margin-top:-6px">Shown next to the main brand logo in this admin and at the top of this website's emails (a form's own logo still wins).</p>
<?php if ($logoOk): ?><div class="logoprev"><img src="../public/media/<?= e($site['logo']) ?>" alt="Site logo"></div><?php endif; ?>
<input type="file" name="site_logo" accept="image/png,image/jpeg,image/webp,image/gif"><?php if ($logoOk): ?><label class="inline" style="margin-top:8px"><input type="checkbox" name="rm_logo" value="1"> Remove logo</label><?php endif; ?></div>

<div><div class="card"><h2>Email look</h2>
<label class="inline"><input type="checkbox" name="email_branded" value="1" <?= site_setting('email_branded', '1', $sid) === '1' ? 'checked' : '' ?>> Use the branded email template</label>
<div class="row"><label>Header colour<input name="email_header_color" value="<?= e($own('email_header_color')) ?>" placeholder="<?= e(setting('email_header_color', '#ffffff')) ?>"></label><label>Logo width (px)<input name="email_logo_width" type="number" min="60" max="400" value="<?= e($own('email_logo_width')) ?>" placeholder="<?= e(setting('email_logo_width', '180')) ?>"></label></div>
<label>Logo links to<input name="email_logo_link" value="<?= e($own('email_logo_link')) ?>" placeholder="https://www.example.com"></label>
<label>Footer text<textarea name="email_footer_text" rows="2" placeholder="Sent by <?= e($site['name']) ?>"><?= e($own('email_footer_text')) ?></textarea></label></div>

<div class="card"><h2>Email sending</h2>
<div class="row"><label>From name<input name="mail_from_name" value="<?= e($own('mail_from_name')) ?>" placeholder="<?= e(setting('mail_from_name')) ?>"></label><label>From email<input type="email" name="mail_from_email" value="<?= e($own('mail_from_email')) ?>" placeholder="<?= e(setting('mail_from_email')) ?>"></label></div>
<label>Reply-To email <small>(used when a notification has none)</small><input type="email" name="mail_reply_to" value="<?= e($own('mail_reply_to')) ?>"></label>
<label class="inline"><input type="checkbox" name="smtp_enabled" value="1" <?= $own('smtp_enabled', '0') === '1' ? 'checked' : '' ?>> Send this website's email through its own SMTP server <small>(otherwise the platform's mail setup is used)</small></label>
<div class="row"><label>SMTP host<input name="smtp_host" value="<?= e($own('smtp_host')) ?>"></label><label>Port<input name="smtp_port" value="<?= e($own('smtp_port', '587')) ?>"></label><label>Security<select name="smtp_secure"><?php foreach (['tls' => 'STARTTLS', 'ssl' => 'SSL', 'none' => 'None'] as $k => $l) echo '<option value="' . $k . '"' . ($own('smtp_secure', 'tls') === $k ? ' selected' : '') . '>' . $l . '</option>'; ?></select></label></div>
<div class="row"><label>Username<input name="smtp_user" value="<?= e($own('smtp_user')) ?>" autocomplete="off"></label><label>Password <small><?= $own('smtp_pass') !== '' ? '(saved, leave blank to keep)' : '' ?></small><input type="password" name="smtp_pass" autocomplete="new-password"></label></div>
<button class="btn sec" name="action" value="testmail" formnovalidate>Send test email to me</button> <small class="muted">Save first, then test.</small></div></div>
</div>

<div class="grid2">
<div class="card"><h2>Captcha</h2><p class="muted" style="margin-top:-6px">Which captcha provider this website's forms use (whether a form has a captcha is set by the Captcha field, or by the platform when it makes captcha mandatory). By default this website uses the platform's choice. You can use your own Google reCAPTCHA / Cloudflare Turnstile keys, or the built-in captcha.</p>
<label>Captcha for this website<select name="captcha_provider"><?php foreach (['inherit' => 'Same as the platform (' . (setting('captcha_provider', 'none') === 'none' ? 'built-in' : setting('captcha_provider')) . ')', 'none' => 'Built-in captcha', 'recaptcha' => 'Google reCAPTCHA (own keys)', 'turnstile' => 'Cloudflare Turnstile (own keys)'] as $k => $l) echo '<option value="' . $k . '"' . ($prov === $k ? ' selected' : '') . '>' . e($l) . '</option>'; ?></select></label>
<div class="row"><label>Site key<input name="captcha_site_key" value="<?= e($own('captcha_site_key')) ?>" autocomplete="off"></label><label>Secret key <small><?= $own('captcha_secret_key') !== '' ? '(saved, leave blank to keep)' : '' ?></small><input type="password" name="captcha_secret_key" autocomplete="new-password"></label></div>
<?php if (in_array($prov, ['recaptcha', 'turnstile'], true) && captcha_cfg()[0] === 'none'): ?><div class="alert err">Enter both keys, otherwise the built-in captcha is used.</div><?php endif; ?></div>
<div class="card"><h2>Data retention</h2><p class="muted" style="margin-top:-6px">Leave empty to use the platform's value (<?= (int)setting('retention_days', 0) ?> / <?= (int)setting('spam_retention_days', 30) ?> / <?= (int)setting('status_reset_days', 30) ?>). 0 = never.</p>
<label>Delete entries after (days)<input type="number" min="0" name="retention_days" value="<?= e($own('retention_days')) ?>"></label>
<label>Delete spam after (days)<input type="number" min="0" name="spam_retention_days" value="<?= e($own('spam_retention_days')) ?>"></label>
<label>Clear the "New" status of read entries after (days)<input type="number" min="0" name="status_reset_days" value="<?= e($own('status_reset_days')) ?>"></label></div>
</div>
<p><button class="btn">Save site settings</button></p></form>
<?php admin_footer();
