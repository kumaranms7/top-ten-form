<?php
require __DIR__ . '/_layout.php';
require_scope('admin');
$plat = in_platform();
$jobScope = $plat ? '1=1' : 'form_id IN ' . site_forms_sql();   // jobs / email log rows of this website only (platform view: everything)
$tab = in_array($_GET['tab'] ?? 'email', ['email', 'queue', 'activity'], true) ? ($_GET['tab'] ?? 'email') : 'email';

// ---- filters ----
// Super admin (platform view): Website + Form (email) / Website + User + Action (activity).
// User with several websites (admin in 2+): Website + Form (email) / Website + User (activity), limited to the websites they administer.
// Everyone else: Form (email) / User (activity), for their own website.
$me = current_user();
if ($plat) $allowed = array_map('intval', q('SELECT id FROM sites')->fetchAll(PDO::FETCH_COLUMN));
else {
    $allowed = array_map('intval', q("SELECT s.id FROM sites s JOIN user_sites us ON us.site_id=s.id WHERE us.user_id=? AND us.role='admin' AND s.status='active'", [$me['id']])->fetchAll(PDO::FETCH_COLUMN));
    if ($me['is_super'] || !in_array((int)site_id(), $allowed, true)) $allowed = [(int)site_id()];   // a super admin inside one website sees just that website
}
$multi = $plat || count($allowed) > 1;                   // show the Website filter
$fSite = (int)($_GET['site'] ?? 0); if (!in_array($fSite, $allowed, true)) $fSite = 0;
$scopeSites = $fSite ? [$fSite] : $allowed;               // websites whose logs are listed
$sitesIn = $scopeSites ? implode(',', $scopeSites) : '0';
$fForm = (int)($_GET['form'] ?? 0);
$fUser = (int)($_GET['user'] ?? 0);
$fAct = mb_substr(trim((string)($_GET['act'] ?? '')), 0, 100);
$allIn = $allowed ? implode(',', $allowed) : '0';
$formOpts = q("SELECT id,name,site_id FROM forms WHERE site_id IN ($sitesIn) ORDER BY name")->fetchAll();
$siteOpts = $multi ? q("SELECT id,name FROM sites WHERE id IN ($allIn) ORDER BY name")->fetchAll() : [];
$userOpts = q("SELECT DISTINCT u.id,u.name FROM users u JOIN user_sites us ON us.user_id=u.id WHERE us.site_id IN ($sitesIn)" . ($plat ? ' OR u.is_super=1' : '') . ' ORDER BY u.name')->fetchAll();
function log_filters(string $tab, bool $plat, bool $multi, array $siteOpts, array $formOpts, array $userOpts, int $fSite, int $fForm, int $fUser, string $fAct): void {
    echo '<form method="get" class="filters"><input type="hidden" name="tab" value="' . e($tab) . '">';
    if ($multi) { echo '<label>Website<select name="site" onchange="this.form.submit()"><option value="">All websites</option>'; foreach ($siteOpts as $o) echo '<option value="' . $o['id'] . '"' . ($fSite == $o['id'] ? ' selected' : '') . '>' . e($o['name']) . '</option>'; echo '</select></label>'; }
    if ($tab === 'email') {
        echo '<label>Form<select name="form" onchange="this.form.submit()"><option value="">All forms</option>';
        foreach ($formOpts as $o) echo '<option value="' . $o['id'] . '"' . ($fForm == $o['id'] ? ' selected' : '') . '>' . e($o['name']) . '</option>';
        echo '</select></label>';
    } else {
        echo '<label>User<select name="user" onchange="this.form.submit()"><option value="">All users</option>'; foreach ($userOpts as $o) echo '<option value="' . $o['id'] . '"' . ($fUser == $o['id'] ? ' selected' : '') . '>' . e($o['name']) . '</option>'; echo '</select></label>';
        if ($plat) echo '<label>Action contains<input name="act" value="' . e($fAct) . '" placeholder="e.g. Deleted"></label><button class="btn sm">Filter</button>';
    }
    if ($fSite || $fForm || $fUser || $fAct !== '') echo ' <a class="btn sm sec" href="?tab=' . e($tab) . '">Clear</a>';
    echo '</form>';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? ''; $jid = (int)($_POST['id'] ?? 0);
    if ($act === 'retry') { q("UPDATE jobs SET status='pending', attempts=0, next_attempt=NOW() WHERE id=? AND status IN ('failed','pending') AND $jobScope", [$jid]); flash('Job queued for retry.'); }
    elseif ($act === 'delete') { q("DELETE FROM jobs WHERE id=? AND $jobScope", [$jid]); flash('Job removed.'); }
    elseif ($act === 'run' && $plat) { $n = process_jobs(50); flash("Processed $n job(s)."); }
    elseif ($act === 'clear_failed') { q("DELETE FROM jobs WHERE status='failed' AND $jobScope"); flash('Failed jobs cleared.'); }
    header('Location: logs.php?tab=queue'); exit;
}
$pending = (int)q("SELECT COUNT(*) FROM jobs WHERE status IN ('pending','processing') AND $jobScope")->fetchColumn();
$failed = (int)q("SELECT COUNT(*) FROM jobs WHERE status='failed' AND $jobScope")->fetchColumn();
admin_header('Logs', 'logs', '', 'Emails, background queue and admin activity'); ?>
<div class="tabs"><a class="<?= $tab === 'email' ? 'active' : '' ?>" href="?tab=email">Email log</a>
<a class="<?= $tab === 'queue' ? 'active' : '' ?>" href="?tab=queue">Queue<?= $pending + $failed ? ' <span class="badge">' . ($pending + $failed) . '</span>' : '' ?></a>
<a class="<?= $tab === 'activity' ? 'active' : '' ?>" href="?tab=activity">Activity log</a></div>
<?php if ($tab === 'email'): log_filters('email', $plat, $multi, $siteOpts, $formOpts, $userOpts, $fSite, $fForm, $fUser, $fAct);
$ew = ["l.form_id IN (SELECT id FROM forms WHERE site_id IN ($sitesIn))"]; $ep = [];
if ($fForm) { $ew[] = 'l.form_id=?'; $ep[] = $fForm; }
$rows = q('SELECT l.*, f.name fname FROM email_logs l LEFT JOIN forms f ON f.id=l.form_id WHERE ' . implode(' AND ', $ew) . ' ORDER BY l.id DESC LIMIT 200', $ep)->fetchAll(); ?>
<table><tr><th>Date</th><th>Form</th><th>To</th><th>Subject</th><th>Status</th><th>Error</th></tr>
<?php foreach ($rows as $r): ?><tr><td><?= e($r['created_at']) ?></td><td><?= e($r['fname']) ?></td><td><?= e($r['to_email']) ?></td><td><?= e($r['subject']) ?></td><td><span class="tag <?= $r['status'] === 'sent' ? 'green' : 'red' ?>"><?= $r['status'] ?></span></td><td><?= e($r['error']) ?></td></tr><?php endforeach; ?></table>
<?php elseif ($tab === 'queue'): $rows = q('SELECT j.*, f.name fname FROM jobs j LEFT JOIN forms f ON f.id=j.form_id WHERE ' . ($plat ? '1=1' : 'j.form_id IN ' . site_forms_sql()) . ' ORDER BY j.id DESC LIMIT 100')->fetchAll(); ?>
<p class="muted">Emails and webhooks wait here until sent. Failures are retried automatically (1 min, 5 min, 15 min, 1 h, 6 h), then marked failed.</p>
<form method="post" class="actions" style="margin-bottom:14px"><?= csrf_field() ?><button class="btn sm" name="action" value="run">Process queue now</button>
<?php if ($failed): ?><button class="btn sm sec" name="action" value="clear_failed" onclick="return confirm('Remove all failed jobs?')">Clear failed (<?= $failed ?>)</button><?php endif; ?></form>
<table><tr><th>#</th><th>Type</th><th>Form</th><th>Status</th><th>Attempts</th><th>Next try</th><th>Last error</th><th></th></tr>
<?php foreach ($rows as $r): $cls = ['done' => 'green', 'failed' => 'red', 'pending' => 'amber', 'processing' => 'amber'][$r['status']]; ?>
<tr><td><?= $r['id'] ?></td><td><?= e($r['type']) ?></td><td><?= e($r['fname']) ?></td><td><span class="tag <?= $cls ?>"><?= $r['status'] ?></span></td><td><?= $r['attempts'] ?></td>
<td><?= $r['status'] === 'pending' ? e($r['next_attempt']) : e($r['done_at'] ?: '') ?></td><td><?= e(mb_strimwidth((string)$r['last_error'], 0, 90, '...')) ?></td>
<td class="actions"><?php if (in_array($r['status'], ['failed', 'pending'])): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn sm sec" name="action" value="retry">Retry now</button></form><?php endif; ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn sm sec" name="action" value="delete">Remove</button></form></td></tr><?php endforeach;
if (!$rows) echo '<tr><td colspan="8" class="muted">The queue is empty.</td></tr>'; ?></table>
<?php else: log_filters('activity', $plat, $multi, $siteOpts, $formOpts, $userOpts, $fSite, $fForm, $fUser, $fAct);
$aw = [($plat && !$fSite) ? '1=1' : "a.site_id IN ($sitesIn)"]; $ap = [];
if ($fUser) { $aw[] = 'a.user_id=?'; $ap[] = $fUser; }
if ($plat && $fAct !== '') { $aw[] = 'a.action LIKE ?'; $ap[] = '%' . addcslashes($fAct, '%_' . chr(92)) . '%'; }
$rows = q('SELECT a.*, u.name, s.name site_name FROM activity_log a LEFT JOIN users u ON u.id=a.user_id LEFT JOIN sites s ON s.id=a.site_id WHERE ' . implode(' AND ', $aw) . ' ORDER BY a.id DESC LIMIT 200', $ap)->fetchAll(); ?>
<table><tr><th>Date</th><?= $multi ? '<th>Website</th>' : '' ?><th>User</th><th>Action</th><th>IP</th></tr>
<?php foreach ($rows as $r): ?><tr><td><?= e($r['created_at']) ?></td><?= $multi ? '<td>' . e($r['site_name'] ?? '-') . '</td>' : '' ?><td><?= e($r['name'] ?? '-') ?></td><td><?= e($r['action']) ?></td><td><?= e($r['ip']) ?></td></tr><?php endforeach; ?></table>
<?php endif; admin_footer();
