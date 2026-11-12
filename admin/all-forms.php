<?php
require __DIR__ . '/_layout.php';
$me = require_super();
$sf = (int)($_GET['site'] ?? 0);
$sites = q('SELECT id,name FROM sites ORDER BY name')->fetchAll();
$rows = q('SELECT f.id, f.name, f.status, f.created_at, s.id sid, s.name sname, (SELECT COUNT(*) FROM entries e WHERE e.form_id=f.id AND e.is_spam=0) cnt
    FROM forms f LEFT JOIN sites s ON s.id=f.site_id' . ($sf ? ' WHERE f.site_id=' . $sf : '') . ' ORDER BY f.id DESC')->fetchAll();
$tok = urlencode(csrf_token());
admin_header('All forms', 'allforms', '', 'Forms of every website'); ?>
<form class="filters" method="get"><label style="max-width:300px;margin:0">Website<select name="site" onchange="this.form.submit()"><option value="">All websites</option><?php foreach ($sites as $s) echo '<option value="' . $s['id'] . '"' . ($sf == $s['id'] ? ' selected' : '') . '>' . e($s['name']) . '</option>'; ?></select></label></form>
<table><tr><th>Form</th><th>Website</th><th>Status</th><th>Entries</th><th>Created</th><th></th></tr>
<?php foreach ($rows as $r): ?><tr><td><b><?= e($r['name']) ?></b> <small class="muted">#<?= $r['id'] ?></small></td><td><?= e($r['sname'] ?? '-') ?></td><td><span class="tag <?= $r['status'] === 'active' ? 'green' : '' ?>"><?= e($r['status']) ?></span></td><td><?= (int)$r['cnt'] ?></td><td><?= e(date('M j, Y', strtotime($r['created_at']))) ?></td>
<td class="actions"><?php if ($r['sid']): ?><a class="btn sm" href="switch-site.php?id=<?= $r['sid'] ?>&t=<?= $tok ?>&to=forms.php">Open website</a><?php endif; ?></td></tr><?php endforeach;
if (!$rows) echo '<tr><td colspan="6" class="muted">No forms.</td></tr>'; ?></table>
<?php admin_footer();
