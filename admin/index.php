<?php
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../app/charts.php';
require_once __DIR__ . '/../app/entries_lib.php';
$me = require_login();

// ---------- filters ----------
$range = (int)($_GET['range'] ?? 30); if (!in_array($range, [7, 30, 90], true)) $range = 30;
$fid = (int)($_GET['form'] ?? 0);
if ($fid && !q('SELECT 1 FROM forms WHERE id=? AND id IN ' . site_forms_sql(), [$fid])->fetchColumn()) $fid = 0;
$since = date('Y-m-d 00:00:00', strtotime('-' . ($range - 1) . ' days'));
$prevSince = date('Y-m-d 00:00:00', strtotime('-' . (2 * $range - 1) . ' days'));
$fw = ' AND form_id IN ' . site_forms_sql() . ($fid ? ' AND form_id=' . $fid : '');

// ---------- numbers ----------
$cur = (int)q("SELECT COUNT(*) FROM entries WHERE is_spam=0 AND created_at >= ?$fw", [$since])->fetchColumn();
$prev = (int)q("SELECT COUNT(*) FROM entries WHERE is_spam=0 AND created_at >= ? AND created_at < ?$fw", [$prevSince, $since])->fetchColumn();
$delta = $prev > 0 ? round(100 * ($cur - $prev) / $prev) : null;
$stats = [
    'Forms' => ['forms', (int)q('SELECT COUNT(*) FROM forms WHERE id IN ' . site_forms_sql())->fetchColumn(), 'violet', ''],
    'Entries (last ' . $range . ' days)' => ['entries', $cur, 'sky', $delta === null ? '' : ($delta >= 0 ? '&#9650; ' : '&#9660; ') . abs($delta) . '% vs previous ' . $range . ' days'],
    'Unread entries' => ['unread', (int)q("SELECT COUNT(*) FROM entries WHERE is_read=0 AND is_spam=0$fw")->fetchColumn(), 'pink', ''],
    'Spam caught (' . $range . ' days)' => ['alert', (int)q("SELECT COUNT(*) FROM entries WHERE is_spam=1 AND created_at >= ?$fw", [$since])->fetchColumn(), 'orange', ''],
    'Failed emails/webhooks' => ['logs', (int)q("SELECT COUNT(*) FROM jobs WHERE status='failed' AND form_id IN " . site_forms_sql())->fetchColumn(), 'green', ''],
];

// ---------- chart data ----------
$perDay = array_column(q("SELECT DATE(created_at) d, COUNT(*) c FROM entries WHERE is_spam=0 AND created_at >= ?$fw GROUP BY d", [$since])->fetchAll(), 'c', 'd');
$points = [];
for ($i = $range - 1; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-$i days")); $points[] = [date('M j', strtotime($d)), (int)($perDay[$d] ?? 0)]; }

$byForm = [];
foreach (q("SELECT f.id, f.name, COUNT(*) c FROM entries e JOIN forms f ON f.id=e.form_id WHERE e.is_spam=0 AND e.created_at >= ? AND e.form_id IN " . site_forms_sql() . " GROUP BY f.id, f.name ORDER BY c DESC LIMIT 8", [$since])->fetchAll() as $r)
    $byForm[] = [$r['name'], (int)$r['c'], 'entries.php?form=' . $r['id']];

$srcCount = []; $campCount = [];
foreach (q("SELECT utm_json, referrer FROM entries WHERE is_spam=0 AND created_at >= ?$fw ORDER BY id DESC LIMIT 20000", [$since])->fetchAll() as $r) {
    $s = entry_sources($r); $srcCount[$s['label']] = ($srcCount[$s['label']] ?? 0) + 1;
    if (!empty($s['utm']['utm_campaign'])) $campCount[$s['utm']['utm_campaign']] = ($campCount[$s['utm']['utm_campaign']] ?? 0) + 1;
}
arsort($srcCount); arsort($campCount);
$sources = []; foreach (array_slice($srcCount, 0, 6, true) as $k => $v) $sources[] = [$k, $v];
if (count($srcCount) > 6) $sources[] = ['Other', array_sum(array_slice($srcCount, 6))];
$campaigns = []; foreach (array_slice($campCount, 0, 6, true) as $k => $v) $campaigns[] = [$k, $v];

$st = array_column(q("SELECT status, COUNT(*) c FROM entries WHERE is_spam=0 AND created_at >= ?$fw GROUP BY status", [$since])->fetchAll(), 'c', 'status');
$statusItems = [['New', (int)($st['new'] ?? 0), '#6366f1'], ['Contacted', (int)($st['contacted'] ?? 0), '#f59e0b'], ['Won', (int)($st['won'] ?? 0), '#10b981'], ['Lost', (int)($st['lost'] ?? 0), '#f43f5e'], ['No status', (int)($st[''] ?? 0), '#94a3b8']];

$recent = q("SELECT e.*, f.name fname FROM entries e JOIN forms f ON f.id=e.form_id WHERE e.is_spam=0$fw ORDER BY e.id DESC LIMIT 8")->fetchAll();
$forms = q('SELECT id,name,tags FROM forms WHERE id IN ' . site_forms_sql() . ' ORDER BY name')->fetchAll();
admin_header('Dashboard', 'dashboard', '', 'Overview of your forms and submissions'); ?>

<form class="filters" method="get" style="justify-content:space-between;align-items:center">
  <div class="actions"><span class="muted">Period</span><div class="devices"><?php foreach ([7 => '7 days', 30 => '30 days', 90 => '90 days'] as $d => $l): ?><a class="<?= $range === $d ? 'active' : '' ?>" href="?range=<?= $d ?><?= $fid ? '&form=' . $fid : '' ?>"><?= $l ?></a><?php endforeach; ?></div></div>
  <label style="max-width:280px;margin:0">Form<select name="form" onchange="this.form.submit()"><option value="">All forms</option><?php foreach ($forms as $f) echo '<option value="' . $f['id'] . '"' . ($fid == $f['id'] ? ' selected' : '') . '>' . e($f['name'] . (form_tags($f) ? '  [' . implode(', ', form_tags($f)) . ']' : '')) . '</option>'; ?></select></label>
  <input type="hidden" name="range" value="<?= $range ?>">
</form>

<div class="grid"><?php foreach ($stats as $k => [$ic, $v, $col, $note]): ?><div class="stat c-<?= $col ?>"><span class="ico"><?= icon($ic) ?></span><div><b><?= (int)$v ?></b><?= e($k) ?><?php if ($note): ?><small class="delta <?= $delta !== null && $delta < 0 ? 'down' : 'up' ?>"><?= $note ?></small><?php endif; ?></div></div><?php endforeach; ?></div>

<div class="card"><h2>Entries over time</h2><?= svg_area_chart($points) ?></div>

<div class="grid2">
  <div class="card"><h2>Entries by form</h2><?= html_bars($byForm) ?></div>
  <div class="card"><h2>Where entries come from</h2><?= svg_donut($sources) ?><p class="muted" style="margin:10px 0 0">Campaign (utm_source) or referring site; "Direct" when neither was recorded.</p></div>
  <div class="card"><h2>Lead status</h2><?= html_stacked($statusItems) ?>
  <?php if ($campaigns): ?><h3 style="margin-top:22px">Top campaigns</h3><?= html_bars($campaigns) ?><?php endif; ?></div>
</div>

<h2 style="margin-top:26px">Recent entries</h2>
<table><tr><th>#</th><th>Form</th><th>Preview</th><th>Source</th><th>Date</th></tr>
<?php foreach ($recent as $r): $d = json_decode($r['data_json'], true) ?: []; $pv = [];
  foreach ($d as $v) { $t = flat_value($v); if ($t !== '') $pv[] = $t; if (count($pv) >= 2) break; } ?>
<tr class="<?= $r['is_read'] ? '' : 'unread' ?>"><td><a href="entry.php?id=<?= $r['id'] ?>"><?= $r['id'] ?></a></td><td><?= e($r['fname']) ?></td><td><?= e(mb_strimwidth(implode(' | ', $pv), 0, 70, '...')) ?></td><td><?= e(entry_sources($r)['label']) ?></td><td><?= e(date('M j, g:i a', strtotime($r['created_at']))) ?></td></tr>
<?php endforeach; if (!$recent) echo '<tr><td colspan="5" class="muted">No entries yet.</td></tr>'; ?></table>
<?php admin_footer();
