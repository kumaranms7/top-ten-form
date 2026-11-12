<?php
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../app/antivirus.php';
$me = require_super();
$keys = ['site_name', 'timezone', 'mail_driver', 'mail_from_email', 'mail_from_name', 'default_bcc', 'report_recipients', 'smtp_host', 'smtp_port', 'smtp_secure', 'smtp_user', 'session_timeout', 'max_upload_mb', 'allowed_ext', 'retention_days',
    'captcha_provider', 'captcha_site_key', 'captcha_secret_key', 'av_mode', 'av_clamd', 'av_clamscan', 'spam_retention_days',
    'email_header_color', 'email_footer_text', 'email_logo_width', 'email_logo_link', 'status_reset_days'];

/** Move the private storage folder (uploads + backups) to a new location. Returns an error message or ''. */
function move_storage(string $new): string {
    $new = rtrim(str_replace('\\', '/', $new), '/');
    $old = storage_dir();
    if ($new === '' || $new === $old) return '';
    if (!is_dir($new) && !@mkdir($new, 0755, true)) return "Could not create $new";
    if (!is_writable($new)) return "$new is not writable";
    foreach (['uploads', 'backups', 'tmp'] as $sub) {
        @mkdir("$new/$sub", 0755, true);
        if (!is_dir("$old/$sub")) continue;
        foreach (new DirectoryIterator("$old/$sub") as $f) {
            if ($f->isDot()) continue;
            if (!@rename($f->getPathname(), "$new/$sub/" . $f->getFilename())) return 'Could not move ' . $f->getFilename() . ' (check permissions)';
        }
    }
    if (is_file("$old/mail.log")) @rename("$old/mail.log", "$new/mail.log");
    @file_put_contents("$new/.htaccess", "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    return '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'testmail') {
        require __DIR__ . '/../app/mailer.php';
        [$ok, $err] = send_mail(['to' => $me['email'], 'from_email' => '', 'from_name' => '', 'subject' => 'TopTen Form test email', 'body' => wrap_email_html('<p>Your email settings work.</p>')]);
        flash($ok ? 'Test email sent to ' . $me['email'] : 'Test failed: ' . $err, $ok ? 'ok' : 'err');
    } elseif (($_POST['action'] ?? '') === 'sendreport') {   // send last month's report to every client now (testing / re-send)
        require_once __DIR__ . '/../app/reports.php';
        $to = report_recipients(); $ym = date('Y-m', strtotime('first day of last month')); $n = 0;
        if (!$to) flash('Add at least one valid report recipient and save first.', 'err');
        else { $n = queue_monthly_report($ym, $to); try { process_jobs(50); } catch (Throwable $e) {} flash('Queued one report email for ' . date('F Y', strtotime($ym . '-01')) . ' (' . $n . ' leads across all websites) to ' . implode(', ', $to) . '. Check Logs → Email log for delivery.'); }
    } elseif (($_POST['action'] ?? '') === 'newcronkey') {
        set_setting('cron_key', bin2hex(random_bytes(16))); flash('New cron key generated. Update your scheduled task if you use the URL.');
    } else {
        require_once __DIR__ . '/../app/mailer.php';
        if (isset($_POST['report_recipients'])) $_POST['report_recipients'] = implode(', ', split_emails($_POST['report_recipients']));
        set_setting('report_enabled', empty($_POST['report_enabled']) ? '0' : '1');
        if (isset($_POST['default_bcc'])) $_POST['default_bcc'] = implode(', ', split_emails($_POST['default_bcc']));   // keep valid addresses only
        foreach ($keys as $k) if (isset($_POST[$k])) set_setting($k, trim($_POST[$k]));
        if (($_POST['smtp_pass'] ?? '') !== '') set_setting('smtp_pass', $_POST['smtp_pass']);
        set_setting('email_branded', empty($_POST['email_branded']) ? '0' : '1');
        set_setting('captcha_required', empty($_POST['captcha_required']) ? '0' : '1');
        if (!sanitize_color((string)setting('email_header_color', '#ffffff'))) set_setting('email_header_color', '#ffffff');
        $logoErr = '';
        foreach (['admin_logo' => 'admin_logo', 'email_logo' => 'email_logo'] as $field => $key) {   // logo uploads / removal
            if (!empty($_POST['rm_' . $field])) { delete_media((string)setting($key, '')); set_setting($key, ''); }
            [$file, $lerr] = save_logo_upload($_FILES[$field] ?? null);
            if ($lerr) { $logoErr = $lerr; }
            elseif ($file) { delete_media((string)setting($key, '')); set_setting($key, $file); }
        }
        $newDir = trim($_POST['storage_dir'] ?? '');
        if ($newDir !== '' && rtrim(str_replace('\\', '/', $newDir), '/') !== storage_dir()) {
            $err = move_storage($newDir);
            if ($err) { flash('Settings saved, but the storage folder was not changed: ' . $err, 'err'); header('Location: settings.php'); exit; }
            set_setting('storage_dir', rtrim(str_replace('\\', '/', $newDir), '/'));
        }
        log_activity('Updated settings'); if ($logoErr) flash('Settings saved, but the logo was not changed: ' . $logoErr, 'err'); else flash('Settings saved.');
    }
    header('Location: settings.php'); exit;
}
$s = fn($k, $d = '') => e(setting($k, $d));
$av = av_status();
$sel = fn($k, $v, $d = '') => setting($k, $d) === $v ? 'selected' : '';
$lastCron = (int)setting('last_cron', 0);
$cronUrl = base_url() . '/cron.php?key=' . setting('cron_key');
$files = 0; foreach (@new RecursiveIteratorIterator(new RecursiveDirectoryIterator(upload_path(), FilesystemIterator::SKIP_DOTS)) as $f) if ($f->isFile()) $files++;
admin_header('Settings', 'settings', '', 'Site, email, uploads, spam and background tasks'); ?>
<div class="tabs"><a class="active" href="settings.php">Settings</a><a href="backup.php">Backups</a><a href="update.php">Updates</a></div>
<form method="post" enctype="multipart/form-data"><?= csrf_field() ?>
<div class="card"><h2>General</h2><div class="row"><label>Site name<input name="site_name" value="<?= $s('site_name') ?>"></label>
<label>Timezone <small>(default: New York / US Eastern)</small><select name="timezone"><?php $cur = setting('timezone', 'America/New_York'); foreach (timezone_identifiers_list() as $tzn) echo '<option' . ($tzn === $cur ? ' selected' : '') . '>' . e($tzn) . '</option>'; ?></select></label>
<label>Session timeout (minutes)<input type="number" name="session_timeout" value="<?= $s('session_timeout', 120) ?>"></label></div>
<p class="muted" style="margin:0">Version <?= e(TF_VERSION) ?> &middot; database v<?= (int)setting('db_version', 0) ?></p></div>

<div class="card"><h2>Email</h2>
<p class="muted">Emails are sent in the background and retried automatically (up to 5 times) if your mail server is busy or down. On localhost, PHP <code>mail()</code> usually doesn't work. Use <b>SMTP</b> (e.g. Gmail: host <code>smtp.gmail.com</code>, port 587, TLS, your address + an <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener">App Password</a>) or <b>Log to file</b> (writes to <code>mail.log</code> in the storage folder) while testing. Save settings before sending a test.</p>
<div class="row"><label>Send using<select name="mail_driver"><option value="phpmail" <?= $sel('mail_driver', 'phpmail') ?>>PHP mail()</option><option value="smtp" <?= $sel('mail_driver', 'smtp') ?>>SMTP (recommended)</option><option value="log" <?= $sel('mail_driver', 'log') ?>>Log to file (local testing only)</option></select></label>
<label>Default from email<input name="mail_from_email" value="<?= $s('mail_from_email') ?>"></label><label>Default from name<input name="mail_from_name" value="<?= $s('mail_from_name') ?>"></label></div>
<label>Default BCC <small>(copied silently on every email sent by every client form; separate several with commas)</small><input name="default_bcc" value="<?= $s('default_bcc') ?>" placeholder="archive@yourcompany.com"></label>
<div class="row"><label>SMTP host<input name="smtp_host" value="<?= $s('smtp_host') ?>"></label><label>Port<input name="smtp_port" value="<?= $s('smtp_port', 587) ?>"></label>
<label>Encryption<select name="smtp_secure"><?php foreach (['tls' => 'TLS (587)', 'ssl' => 'SSL (465)', 'none' => 'None'] as $k => $l) echo '<option value="' . $k . '"' . (setting('smtp_secure', 'tls') === $k ? ' selected' : '') . ">$l</option>"; ?></select></label></div>
<div class="row"><label>SMTP username<input name="smtp_user" value="<?= $s('smtp_user') ?>" autocomplete="off"></label><label>SMTP password <small>(leave blank to keep)</small><input type="password" name="smtp_pass" autocomplete="new-password"></label></div></div>

<div class="card"><h2>Branding</h2>
<div class="row"><div><b>Admin panel logo</b><p class="muted" style="margin:4px 0 8px">Shown top-left in this admin. Leave empty to use the default.</p>
<?php $al = (string)setting('admin_logo', ''); ?><div class="logoprev"><img src="<?= $al && is_file(media_dir() . '/' . $al) ? '../public/media/' . e($al) : 'assets/img/logo.webp' ?>" alt="Current admin logo"></div>
<input type="file" name="admin_logo" accept="image/png,image/jpeg,image/webp,image/gif"><?php if ($al): ?><label class="inline" style="margin-top:8px"><input type="checkbox" name="rm_admin_logo" value="1"> Remove (use default)</label><?php endif; ?></div>
<div><b>Email logo <small>(all forms; each form can override it on its Email notifications tab)</small></b><p class="muted" style="margin:4px 0 8px">Shown at the top of every email.</p>
<?php $el = (string)setting('email_logo', ''); if ($el && is_file(media_dir() . '/' . $el)): ?><div class="logoprev"><img src="<?= '../public/media/' . e($el) ?>" alt="Current email logo"></div><?php endif; ?>
<input type="file" name="email_logo" accept="image/png,image/jpeg,image/webp,image/gif"><?php if ($el): ?><label class="inline" style="margin-top:8px"><input type="checkbox" name="rm_email_logo" value="1"> Remove</label><?php endif; ?></div></div>
<h3>Email template</h3>
<label class="inline"><input type="checkbox" name="email_branded" value="1" <?= setting('email_branded', '1') === '1' ? 'checked' : '' ?>> Use the branded email template (logo header + footer) for all emails</label>
<div class="row"><label>Logo width (px)<input type="number" min="60" max="400" name="email_logo_width" value="<?= $s('email_logo_width', 180) ?>"></label>
<label>Header background<input type="color" name="email_header_color" value="<?= $s('email_header_color', '#ffffff') ?>"></label>
<label>Logo links to <small>(optional URL)</small><input name="email_logo_link" value="<?= $s('email_logo_link') ?>" placeholder="https://www.yoursite.com"></label></div>
<label>Email footer text<textarea name="email_footer_text" rows="2" placeholder="Your company name, address, phone..."><?= $s('email_footer_text') ?></textarea></label>
<p class="muted" style="margin:0">Email clients only show logos from a publicly reachable address, so the logo appears for outside recipients once this app runs on your live HTTPS domain (not on localhost).</p></div>

<div class="card"><h2>Uploads &amp; private storage</h2>
<div class="row"><label>Max upload size (MB)<input type="number" name="max_upload_mb" value="<?= $s('max_upload_mb', 5) ?>"></label>
<label>Allowed extensions (comma separated)<input name="allowed_ext" value="<?= $s('allowed_ext') ?>"></label></div>
<label>Storage folder <small>(uploads, backups and logs. Changing it moves the existing files.)</small><input name="storage_dir" value="<?= e(storage_dir()) ?>"></label>
<p class="muted" style="margin-top:-6px"><?= storage_is_private() ? '<span class="tag green">Outside the web folder</span> Files cannot be requested by URL.' : '<span class="tag red">Inside the web folder</span> Move it above your web root for best safety.' ?> &middot; <?= (int)$files ?> uploaded file(s) stored.</p>
<h3>Virus scanning</h3>
<p class="muted" style="margin-top:0"><?= $av['available'] ? '<span class="tag green">ClamAV active</span> ' : '<span class="tag amber">ClamAV not found</span> ' ?><?= e($av['detail']) ?> Every upload also gets file-type and content checks, and dangerous types (scripts, executables) are always blocked.</p>
<div class="row"><label>Scanning mode<select name="av_mode"><option value="auto" <?= $sel('av_mode', 'auto', 'auto') ?>>Automatic: scan when ClamAV is available</option><option value="required" <?= $sel('av_mode', 'required', 'auto') ?>>Required: reject uploads if scanning is unavailable</option><option value="off" <?= $sel('av_mode', 'off', 'auto') ?>>Off (content checks only)</option></select></label>
<label>ClamAV daemon <small>(clamd address)</small><input name="av_clamd" value="<?= $s('av_clamd', 'tcp://127.0.0.1:3310') ?>"></label>
<label>clamscan path <small>(optional)</small><input name="av_clamscan" value="<?= $s('av_clamscan') ?>" placeholder="auto-detect"></label></div></div>

<div class="card"><h2>Captcha &amp; spam protection</h2>
<p class="muted" style="margin-top:-6px">Tick the option below to make a captcha <b>mandatory on every form</b>; leave it unticked and each form adds a <b>Captcha</b> field only when it needs one. If you choose Google reCAPTCHA or Cloudflare Turnstile, forms with a captcha use it (its script loads only when a visitor starts the form, so it does not slow the page). Otherwise the <b>built-in captcha</b> is used (self-hosted: no account, no keys, nothing sent to third parties); pick Math, Text image or Question and answer on the Captcha field in the builder. Invisible checks (honeypot, signed token, timing, rate limits, content checks) always run as well. Suspected spam goes to the <a href="entries.php?view=spam">Spam folder</a>. <a href="spam.php">Manage spam rules</a>.</p>
<div class="row"><label>Captcha provider<select name="captcha_provider"><?php foreach (['none' => 'Built-in captcha (recommended)', 'recaptcha' => 'Google reCAPTCHA v2', 'turnstile' => 'Cloudflare Turnstile'] as $k => $l) echo '<option value="' . $k . '"' . (setting('captcha_provider', 'none') === $k ? ' selected' : '') . ">$l</option>"; ?></select></label>
<label>Site key <small>(Google / Cloudflare only)</small><input name="captcha_site_key" value="<?= $s('captcha_site_key') ?>"></label><label>Secret key <small>(Google / Cloudflare only)</small><input name="captcha_secret_key" value="<?= $s('captcha_secret_key') ?>"></label></div>
<?php if (setting('captcha_provider', 'none') !== 'none' && captcha_provider() === 'none'): ?><div class="alert err">A provider is selected but its keys are missing, so the built-in captcha is being used.</div><?php endif; ?>
<label class="inline"><input type="checkbox" name="captcha_required" value="1" <?= captcha_required() ? 'checked' : '' ?>> <span>Captcha is mandatory for all website forms <small>When ticked, every form gets a captcha automatically (it is added at the end unless the form has a Captcha field). When unticked, captcha is optional: websites add the <b>Captcha</b> field to the forms that need it.</small></span></label></div>

<div class="card"><h2>Data retention</h2><div class="row"><label>Auto-delete entries older than (days, 0 = never)<input type="number" name="retention_days" value="<?= $s('retention_days', 0) ?>"></label>
<label>Auto-delete spam older than (days, 0 = never)<input type="number" name="spam_retention_days" value="<?= $s('spam_retention_days', 30) ?>"></label>
<label>Clear the "New" status of read entries older than (days, 0 = never)<input type="number" min="0" name="status_reset_days" value="<?= $s('status_reset_days', 30) ?>"></label></div>
<p class="muted" style="margin:0">Entries that have been opened but never followed up and are still "New" after this many days are switched to "No status" automatically, so your "New" list stays meaningful.</p></div>

<div class="card"><h2>Monthly lead reports</h2>
<p class="muted">On the 1st of every month, one professional email is sent covering every client website for the previous calendar month: the total leads across all websites, then each website with its leads per form and a \"View full report\" button that opens that website's full report page (every lead in a table per form, with source and campaign).</p>
<label class="inline"><input type="checkbox" name="report_enabled" value="1" <?= setting('report_enabled', '0') === '1' ? 'checked' : '' ?>> Send monthly reports automatically</label>
<label>Send reports to <small>(one or more addresses, separated by commas)</small><input name="report_recipients" value="<?= $s('report_recipients') ?>" placeholder="name@example.com"></label>
<label>Schedule: 12:00 AM on the 1st of every month <small>(add this to your server&rsquo;s cron / Task Scheduler)</small><input readonly onclick="this.select()" value='0 0 1 * *  php "<?= e(TF_ROOT) ?>/cron.php" report'></label>
<label>Or by URL <small>(keep this secret)</small><input readonly onclick="this.select()" value="<?= e($cronUrl) ?>&amp;task=report"></label>
<p class="muted" style="margin-top:0">The report is sent once per month, so running the task more often is harmless. Set the server clock to <?= e(date_default_timezone_get()) ?> (the app timezone) so &ldquo;12 AM&rdquo; matches; otherwise it goes out on the first run after midnight in that timezone. Without a scheduled task, the app sends it automatically on the first visit after the month ends.</p>
<a class="btn sec sm" href="report-preview.php" target="_blank">Preview a report</a> <button class="btn sec sm" name="action" value="sendreport" formnovalidate onclick="return confirm('Email the previous month report for every active website to the address(es) above now?')">Send last month&rsquo;s reports now</button>
</div>

<div class="card"><h2>Background tasks</h2>
<p class="muted">Emails, retries, cleanup and scheduled backups run automatically while the app is being used (at most once a minute). Last run: <b><?= $lastCron ? e(fmt_datetime($lastCron, true)) : 'never' ?></b>. For reliable timing on a busy or quiet site, also schedule <code>cron.php</code> every minute:</p>
<label>Command (Linux cron / Windows Task Scheduler)<input readonly onclick="this.select()" value='php "<?= e(TF_ROOT) ?>/cron.php"'></label>
<label>Or by URL <small>(keep this secret)</small><input readonly onclick="this.select()" value="<?= e($cronUrl) ?>"></label>
<button class="btn sec sm" name="action" value="newcronkey" formnovalidate onclick="return confirm('Generate a new cron key? Existing scheduled URLs will stop working.')">Generate new key</button></div>

<button class="btn">Save settings</button> <button class="btn sec" name="action" value="testmail" formnovalidate>Send test email to me</button>
</form>
<?php admin_footer();
