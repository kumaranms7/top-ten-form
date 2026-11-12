<?php
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../app/spam.php';
$me = require_scope('editor');
$ruleSite = (int)(site_id() ?? 0);   // a website manages its own rules; the platform view manages the platform-wide ones

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';
    if ($act === 'add') {
        $type = in_array($_POST['type'] ?? '', ['word', 'ip', 'email_domain'], true) ? $_POST['type'] : 'word';
        $added = 0; $bad = 0;
        foreach (preg_split('/[\r\n,]+/', (string)($_POST['values'] ?? '')) as $v) {
            $v = trim($v); if ($v === '') continue;
            if ($type === 'ip' && !preg_match('#^[0-9a-fA-F:.*/]+$#', $v)) { $bad++; continue; }
            if ($type === 'email_domain') $v = strtolower(ltrim($v, '@'));
            $added += q('INSERT IGNORE INTO spam_rules (type,value,site_id) VALUES (?,?,?)', [$type, mb_substr($v, 0, 190), $ruleSite])->rowCount();
        }
        flash("$added rule(s) added." . ($bad ? " $bad invalid IP(s) skipped." : ''), $added || !$bad ? 'ok' : 'err');
    } elseif ($act === 'delete') { q('DELETE FROM spam_rules WHERE id=? AND site_id=?', [(int)$_POST['id'], $ruleSite]); flash('Rule removed.'); }
    elseif ($act === 'settings' && in_platform()) {
        foreach (['spam_min_seconds', 'spam_max_links', 'spam_retention_days', 'spam_dupe_minutes'] as $k) set_setting($k, (string)max(0, (int)($_POST[$k] ?? 0)));
        set_setting('spam_block_disposable', empty($_POST['spam_block_disposable']) ? '0' : '1');
        set_setting('spam_require_js', empty($_POST['spam_require_js']) ? '0' : '1');
        flash('Spam settings saved.');
    }
    log_activity('Updated spam rules');
    header('Location: spam.php'); exit;
}
$rules = ['word' => [], 'ip' => [], 'email_domain' => []];
foreach (q('SELECT * FROM spam_rules WHERE site_id=? ORDER BY id DESC', [$ruleSite])->fetchAll() as $r) $rules[$r['type']][] = $r;
$globalRules = $ruleSite ? q('SELECT type, value FROM spam_rules WHERE site_id=0 ORDER BY value')->fetchAll() : [];
$titles = ['word' => ['Blocked words & phrases', 'Any submission containing one of these (in any field) goes to Spam. Not case sensitive.', 'viagra, crypto giveaway, seo services'],
    'email_domain' => ['Blocked email domains', 'Submissions using an email from these domains go to Spam (subdomains included).', 'mailinator.com, spam.example'],
    'ip' => ['Blocked IP addresses', 'Exact IPs, wildcards (203.0.113.*) or IPv4 ranges (203.0.113.0/24).', '203.0.113.7, 198.51.100.*']];
$spamCount = (int)q('SELECT COUNT(*) FROM entries WHERE is_spam=1 AND form_id IN ' . site_forms_sql())->fetchColumn();
admin_header('Spam rules', 'entries', '', 'Control what is treated as spam. Flagged entries wait in the Spam folder and never send emails.'); ?>
<p><a href="entries.php?view=spam">&larr; Open the Spam folder (<?= $spamCount ?>)</a></p>
<div class="card shield"><h2>Built-in protection <span class="tag green">on for every form</span></h2>
<p class="muted" style="margin-top:-6px">Visitors answer one simple security check (the built-in captcha); everything else below is invisible to them. Choose the level per form in the builder (Form properties &rarr; Spam protection) and add the <b>Captcha</b> field where you want it. <b>Standard</b> is the default.</p>
<div class="layers">
<div><b>0. Captcha (optional field, or mandatory platform-wide)</b><span>Built-in math / text / question check, or Google / Cloudflare if you set one in Settings.</span></div>
<div><b>1. Honeypot</b><span>A hidden field only bots fill in. Those submissions are dropped silently.</span></div>
<div><b>2. Signed single-use token</b><span>Each page gets a token signed by your server. Forged, reused or missing tokens are caught.</span></div>
<div><b>3. Server-side timing</b><span>The server measures how long the form was open. Instant submissions are bots.</span></div>
<div><b>4. Rate limits</b><span>Per visitor and form, and across all forms.</span></div>
<div><b>5. Content checks</b><span>HTML / link injection, too many links, disposable email addresses.</span></div>
<div><b>6. Repeat detection</b><span>The same message sent again and again.</span></div>
<div><b>7. Your rules</b><span>Blocked words, email domains and IPs (below).</span></div>
<div><b>8. Spam folder</b><span>Flagged entries are kept (not deleted, not emailed) so you can restore a false alarm.</span></div></div></div>
<div class="grid2">
<?php foreach ($titles as $type => [$title, $help, $ph]): ?>
<div class="card"><h2><?= e($title) ?></h2><p class="muted" style="margin-top:-8px"><?= e($help) ?></p>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="add"><input type="hidden" name="type" value="<?= $type ?>">
<label style="margin-bottom:8px"><textarea name="values" rows="2" placeholder="<?= e($ph) ?>" required></textarea></label><button class="btn sm">Add</button> <span class="muted">one per line or comma separated</span></form>
<div class="chips"><?php foreach ($rules[$type] as $r): ?><form method="post" class="chipf"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>"><span><?= e($r['value']) ?></span><button class="x" name="action" value="delete" aria-label="Remove <?= e($r['value']) ?>">&times;</button></form><?php endforeach;
if (!$rules[$type]) echo '<span class="muted">None yet.</span>'; ?></div></div>
<?php endforeach; ?>

<div class="card"><h2>Automatic checks</h2>
<?php if (in_platform()): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="settings">
<label>Minimum time to fill the form (seconds) <small>- faster than this is treated as a bot. 0 = off.</small><input type="number" min="0" name="spam_min_seconds" value="<?= e(setting('spam_min_seconds', 2)) ?>"></label>
<label>Maximum links in a submission <small>- more than this is treated as spam. 0 = off.</small><input type="number" min="0" name="spam_max_links" value="<?= e(setting('spam_max_links', 5)) ?>"></label>
<label>Same message sent again within (minutes) <small>- counts as a repeat. 0 = off.</small><input type="number" min="0" name="spam_dupe_minutes" value="<?= e(setting('spam_dupe_minutes', 10)) ?>"></label>
<label class="inline"><input type="checkbox" name="spam_block_disposable" value="1" <?= setting('spam_block_disposable', '1') === '1' ? 'checked' : '' ?>> <span>Treat disposable email addresses (mailinator, yopmail, ...) as spam</span></label>
<label>Auto-delete spam after (days, 0 = never)<input type="number" min="0" name="spam_retention_days" value="<?= e(setting('spam_retention_days', 30)) ?>"></label>
<label class="inline"><input type="checkbox" name="spam_require_js" value="1" <?= setting('spam_require_js', '0') === '1' ? 'checked' : '' ?>> <span>Treat submissions without a browser signal as spam <small>(stops bots posting directly; leave off if you still use older pasted form code)</small></span></label>
<button class="btn">Save</button></form>
<?php else: ?><p class="muted">These thresholds are set by the platform administrator for all websites.</p><?php endif; ?>
<p class="muted" style="margin-bottom:0">Rate limit: 10 submissions per 10 minutes per visitor and form (3 on Strict).</p></div>
</div>
<?php admin_footer();
