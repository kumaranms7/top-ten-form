<?php
require __DIR__ . '/_layout.php';
$me = require_super();
if (site_id() !== null) { switch_site(null); current_user(true); }   // this page is the platform view

$one = fn(string $sql) => (int)q($sql)->fetchColumn();
$stats = [
    'Websites' => ['sites', $one("SELECT COUNT(*) FROM sites"), 'violet', $one("SELECT COUNT(*) FROM sites WHERE status='suspended'") . ' suspended'],
    'Forms' => ['forms', $one('SELECT COUNT(*) FROM forms'), 'sky', ''],
    'Entries (30 days)' => ['entries', $one('SELECT COUNT(*) FROM entries WHERE is_spam=0 AND created_at >= (NOW() - INTERVAL 30 DAY)'), 'green', ''],
    'Users' => ['users', $one('SELECT COUNT(*) FROM users'), 'pink', ''],
];
$failed = $one("SELECT COUNT(*) FROM jobs WHERE status='failed'");
$sites = q("SELECT s.id, s.name, s.status, s.logo,
    (SELECT COUNT(*) FROM forms f WHERE f.site_id=s.id) nforms,
    (SELECT COUNT(*) FROM entries e JOIN forms f ON f.id=e.form_id WHERE f.site_id=s.id AND e.is_spam=0 AND e.created_at >= (NOW() - INTERVAL 30 DAY)) n30,
    (SELECT COUNT(*) FROM entries e JOIN forms f ON f.id=e.form_id WHERE f.site_id=s.id AND e.is_read=0 AND e.is_spam=0) unread
    FROM sites s ORDER BY s.id DESC")->fetchAll();
$act = q('SELECT a.*, u.name, s.name site_name FROM activity_log a LEFT JOIN users u ON u.id=a.user_id LEFT JOIN sites s ON s.id=a.site_id ORDER BY a.id DESC LIMIT 12')->fetchAll();
$tok = urlencode(csrf_token());
admin_header('Platform', 'platform', '', 'All websites at a glance'); ?>
<div class="grid"><?php foreach ($stats as $k => [$ic, $v, $col, $note]): ?><div class="stat c-<?= $col ?>"><span class="ico"><?= icon($ic) ?></span><div><b><?= (int)$v ?></b><?= e($k) ?><?= $note ? '<small class="muted"> ' . e($note) . '</small>' : '' ?></div></div><?php endforeach; ?></div>
<?php if ($failed): ?><div class="alert err">There are <b><?= $failed ?></b> failed background job(s). <a href="logs.php?tab=queue">Open the queue</a></div><?php endif; ?>
<div class="card"><h2>Websites</h2><table><tr><th>Website</th><th>Status</th><th>Forms</th><th>Entries (30 days)</th><th>Unread</th><th></th></tr>
<?php foreach ($sites as $s): ?><tr><td><b><?= e($s['name']) ?></b></td><td><span class="tag <?= $s['status'] === 'active' ? 'green' : 'red' ?>"><?= e($s['status']) ?></span></td><td><?= (int)$s['nforms'] ?></td><td><?= (int)$s['n30'] ?></td><td><?= (int)$s['unread'] ?></td>
<td class="actions"><a class="btn sm" href="switch-site.php?id=<?= $s['id'] ?>&t=<?= $tok ?>">Enter</a> <a class="btn sm sec" href="sites.php?edit=<?= $s['id'] ?>">Edit</a></td></tr><?php endforeach; ?></table>
<p><a class="btn sm" href="sites.php?new=1">+ Add website</a></p></div>
<div class="card"><h2>Recent activity</h2><table><tr><th>Date</th><th>Website</th><th>User</th><th>Action</th></tr>
<?php foreach ($act as $r): ?><tr><td><?= e($r['created_at']) ?></td><td><?= e($r['site_name'] ?? '-') ?></td><td><?= e($r['name'] ?? '-') ?></td><td><?= e($r['action']) ?></td></tr><?php endforeach; ?></table></div>
<?php admin_footer();
