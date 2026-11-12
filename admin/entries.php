<?php
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../app/entries_lib.php';
$me = require_login();
$fid = (int)($_GET['form'] ?? 0);
$formRow = $fid ? (q('SELECT * FROM forms WHERE id=? AND id IN ' . site_forms_sql(), [$fid])->fetch() ?: null) : null;
if ($fid && !$formRow) { flash('Form not found.', 'err'); header('Location: entries.php'); exit; }

// ---------- POST: bulk actions + column picker ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';
    if ($act === 'cols' && $formRow) {
        $chosen = array_filter(explode(',', (string)($_POST['cols'] ?? '')), 'strlen');
        save_entry_columns($me['id'], $fid, $chosen); flash('Columns updated.');
    } else {
        require_login('editor');
        $ids = array_map('intval', $_POST['ids'] ?? []);
        if ($ids) {
            $mine = array_column(q('SELECT id FROM entries WHERE form_id IN ' . site_forms_sql() . ' AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids)->fetchAll(), 'id');
            $ids = array_map('intval', $mine);   // anything that is not in this website is silently ignored
            if (!$ids) { header('Location: entries.php'); exit; }
            $in = implode(',', array_fill(0, count($ids), '?'));
            $val = $_POST['value'] ?? '';
            if ($act === 'delete') {
                foreach (q("SELECT data_json FROM entries WHERE id IN ($in)", $ids)->fetchAll() as $r)
                    foreach (json_decode($r['data_json'], true) ?: [] as $v) if (is_array($v) && isset($v['file'])) @unlink(upload_path($v['file']));
                q("DELETE FROM entries WHERE id IN ($in)", $ids); q("DELETE FROM entry_notes WHERE entry_id IN ($in)", $ids);
                flash(count($ids) . ' entries deleted.'); log_activity('Deleted entries: ' . implode(',', $ids));
            } elseif ($act === 'read') q("UPDATE entries SET is_read=1 WHERE id IN ($in)", $ids);
            elseif ($act === 'unread') q("UPDATE entries SET is_read=0 WHERE id IN ($in)", $ids);
            elseif ($act === 'spam') { q("UPDATE entries SET is_spam=1, spam_reason='Marked as spam by a user' WHERE id IN ($in)", $ids); flash(count($ids) . ' moved to Spam.'); }
            elseif ($act === 'notspam') { q("UPDATE entries SET is_spam=0, spam_reason=NULL WHERE id IN ($in)", $ids); flash(count($ids) . ' restored from Spam.'); }
            elseif ($act === 'status' && status_to_db($val) !== null) { q("UPDATE entries SET status=? WHERE id IN ($in)", array_merge([status_to_db($val)], $ids)); flash('Status updated.'); }
            elseif ($act === 'assign') {
                $uid = $val === '' ? null : (int)$val;
                if ($uid && !q('SELECT 1 FROM user_sites WHERE user_id=? AND site_id=?', [$uid, site_id()])->fetchColumn()) $uid = null;
                q("UPDATE entries SET assigned_to=? WHERE id IN ($in)", array_merge([$uid], $ids)); flash('Assignment updated.');
            }
        }
    }
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'entries.php')); exit;
}

// ---------- filters ----------
$view = in_array($_GET['view'] ?? '', ['unread', 'spam'], true) ? $_GET['view'] : 'inbox';
$search = trim($_GET['s'] ?? ''); $from = $_GET['from'] ?? ''; $to = $_GET['to'] ?? '';
$statusF = (($_GET['status'] ?? '') !== '' && isset(ENTRY_STATUSES[$_GET['status']])) ? $_GET['status'] : '';
$assignF = $_GET['assigned'] ?? '';
$baseW = ['e.form_id IN ' . site_forms_sql()]; $baseP = [];
if ($fid) { $baseW[] = 'e.form_id=?'; $baseP[] = $fid; }
$counts = [];
foreach (['inbox' => 'e.is_spam=0', 'unread' => 'e.is_spam=0 AND e.is_read=0', 'spam' => 'e.is_spam=1'] as $k => $cond)
    $counts[$k] = (int)q('SELECT COUNT(*) FROM entries e WHERE ' . implode(' AND ', $baseW) . " AND $cond", $baseP)->fetchColumn();
$w = $baseW; $p = $baseP;
$w[] = $view === 'spam' ? 'e.is_spam=1' : 'e.is_spam=0'; if ($view === 'unread') $w[] = 'e.is_read=0';
if ($search !== '') { $w[] = 'e.data_json LIKE ?'; $p[] = '%' . addcslashes($search, '%_') . '%'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $w[] = 'e.created_at >= ?'; $p[] = "$from 00:00:00"; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $w[] = 'e.created_at <= ?'; $p[] = "$to 23:59:59"; }
if ($statusF !== '') { $w[] = 'e.status=?'; $p[] = status_to_db($statusF); }
if ($assignF === 'none') $w[] = 'e.assigned_to IS NULL';
elseif ($assignF === 'me') { $w[] = 'e.assigned_to=?'; $p[] = $me['id']; }
elseif (ctype_digit((string)$assignF) && $assignF !== '') { $w[] = 'e.assigned_to=?'; $p[] = (int)$assignF; }
$where = implode(' AND ', $w);

// ---------- columns + sorting ----------
$inputFields = $formRow ? form_input_fields($formRow) : [];
$fieldByName = []; foreach ($inputFields as $f) $fieldByName[$f['name']] = $f;
$cols = $formRow ? entry_columns($formRow, $me['id']) : [];
$sort = $_GET['sort'] ?? 'created_at';
$dir = strtolower($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';   // newest first by default
$orderSql = 'e.created_at DESC, e.id DESC'; $orderP = [];
if ($sort === 'created_at') $orderSql = "e.created_at $dir, e.id $dir";
elseif ($sort === 'status') $orderSql = "e.status $dir, e.id DESC";
elseif ($sort === 'id') $orderSql = "e.id $dir";
elseif (strpos($sort, 'f:') === 0 && isset($fieldByName[substr($sort, 2)])) {
    $fn = substr($sort, 2); $num = in_array($fieldByName[$fn]['type'], ['number', 'price', 'calculated', 'rating'], true);
    $expr = "JSON_UNQUOTE(JSON_EXTRACT(e.data_json, CONCAT('$.', ?)))";
    $orderSql = ($num ? "CAST($expr AS DECIMAL(20,4))" : $expr) . " $dir, e.id DESC"; $orderP = [$fn];
} else { $sort = 'created_at'; $orderSql = "e.created_at $dir, e.id $dir"; }
$per = (int)($_GET['per'] ?? 25); if (!in_array($per, [10, 25, 50, 100, 200], true)) $per = 25;   // entries per page
$qsBase = array_filter(['form' => $fid ?: null, 'view' => $view === 'inbox' ? null : $view, 's' => $search ?: null, 'from' => $from ?: null, 'to' => $to ?: null, 'status' => $statusF !== '' ? $statusF : null, 'assigned' => $assignF ?: null, 'per' => $per === 25 ? null : $per]);
$sortLink = function (string $key, string $label) use ($qsBase, $sort, $dir) {
    $next = ($sort === $key && $dir === 'ASC') ? 'desc' : 'asc';
    $arrow = $sort === $key ? ($dir === 'ASC' ? ' &#9650;' : ' &#9660;') : '';
    return '<a class="th-sort" href="?' . e(http_build_query($qsBase + ['sort' => $key, 'dir' => $next])) . '">' . e($label) . $arrow . '</a>';
};

// ---------- exports ----------
$export = $_GET['export'] ?? (isset($_GET['csv']) ? 'csv' : '');
if (in_array($export, ['csv', 'xlsx'], true)) {
    $rows = q("SELECT e.*, f.name fname, f.fields_json, u.name aname FROM entries e JOIN forms f ON f.id=e.form_id LEFT JOIN users u ON u.id=e.assigned_to WHERE $where ORDER BY $orderSql LIMIT 50000", array_merge($p, $orderP))->fetchAll();
    $head = ['ID', 'Form', 'Date', 'Status', 'Assigned to'];
    $labels = [];
    // CSV / Excel contain EVERY field (a complete, re-importable copy); the PDF follows the columns you chose because a page can't fit them all
    $exportCols = $export === 'pdf' ? $cols : array_keys($fieldByName);
    if ($formRow) { foreach ($exportCols as $c) { $head[] = $fieldByName[$c]['label']; $labels[] = $c; } }
    else { // all forms: every field name that appears
        foreach ($rows as $r) foreach (array_keys(json_decode($r['data_json'], true) ?: []) as $k) if (!in_array($k, $labels, true)) $labels[] = $k;
        foreach ($labels as $k) $head[] = $k;
    }
    if ($export !== 'pdf') array_push($head, 'IP', 'Page URL', 'Source', 'Source page', 'Campaign', 'Medium');
    $data = [];
    foreach ($rows as $r) {
        $d = json_decode($r['data_json'], true) ?: []; $src = entry_sources($r);
        $line = [(int)$r['id'], $r['fname'], $r['created_at'], ENTRY_STATUSES[status_token($r['status'])], (string)$r['aname']];
        foreach ($labels as $k) $line[] = flat_value($d[$k] ?? '');
        if ($export !== 'pdf') array_push($line, $r['ip'], $r['page_url'], $src['label'], $src['page'], $src['utm']['utm_campaign'] ?? '', $src['utm']['utm_medium'] ?? '');
        $data[] = $line;
    }
    $name = 'entries-' . ($formRow ? slugify($formRow['name']) . '-' : '') . date('Ymd-His');
    log_activity("Exported $export (" . count($data) . ' entries)');
    while (ob_get_level() > 0) ob_end_clean();
    if ($export === 'csv') {
        header('Content-Type: text/csv; charset=utf-8'); header("Content-Disposition: attachment; filename=\"$name.csv\"");
        $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF"); fputcsv($o, $head);
        foreach ($data as $line) fputcsv($o, array_map(fn($v) => (is_string($v) && preg_match('/^[=+\-@\t\r]/', $v)) ? "'" . $v : $v, $line)); // CSV-injection guard
    } elseif ($export === 'xlsx') {
        require_once __DIR__ . '/../app/xlsx.php';
        foreach ($data as &$line) foreach ($line as $i => $v) if (is_string($v) && preg_match('/^[=+\-@]/', $v)) $line[$i] = "'" . $v;
        unset($line);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header("Content-Disposition: attachment; filename=\"$name.xlsx\"");
        echo xlsx_build(array_merge([$head], $data), $formRow['name'] ?? 'Entries');
    } else {
        require_once __DIR__ . '/../app/pdf.php';
        $pdf = new SimplePdf();
        $pdf->table(($formRow['name'] ?? 'All forms') . ' - Entries', 'Exported ' . date('M j, Y g:i a') . ' | ' . count($data) . ' entr' . (count($data) === 1 ? 'y' : 'ies') . ($view === 'spam' ? ' | Spam folder' : ''), $head, $data);
        header('Content-Type: application/pdf'); header("Content-Disposition: attachment; filename=\"$name.pdf\"");
        echo $pdf->output();
    }
    exit;
}

// ---------- list ----------
$page = max(1, (int)($_GET['page'] ?? 1));
$total = (int)q("SELECT COUNT(*) FROM entries e WHERE $where", $p)->fetchColumn();
$rows = q("SELECT e.*, f.name fname, u.name aname FROM entries e JOIN forms f ON f.id=e.form_id LEFT JOIN users u ON u.id=e.assigned_to WHERE $where ORDER BY $orderSql LIMIT $per OFFSET " . (($page - 1) * $per), array_merge($p, $orderP))->fetchAll();
$forms = q('SELECT id,name,tags FROM forms WHERE id IN ' . site_forms_sql() . ' ORDER BY name')->fetchAll();
$users = q('SELECT u.id,u.name FROM users u JOIN user_sites us ON us.user_id=u.id WHERE u.active=1 AND us.site_id=? ORDER BY u.name', [site_id()])->fetchAll();
$canEdit = can('editor');

admin_header('Entries', 'entries', '', $formRow ? 'Submissions for "' . $formRow['name'] . '"' : 'View and manage submissions from all forms');
if ($fid && $canEdit) echo form_tabs($fid, 'entries'); ?>

<div class="tabs"><?php foreach (['inbox' => 'Inbox', 'unread' => 'Unread', 'spam' => 'Spam'] as $k => $l): ?>
<a href="?<?= e(http_build_query(array_filter(['form' => $fid ?: null, 'view' => $k === 'inbox' ? null : $k]))) ?>" class="<?= $view === $k ? 'active' : '' ?>"><?= $l ?> <span class="count"><?= $counts[$k] ?></span></a><?php endforeach; ?></div>

<form class="filters" method="get"><input type="hidden" name="view" value="<?= e($view === 'inbox' ? '' : $view) ?>">
<label>Form<select name="form" onchange="this.form.submit()" title="Pick a form to import entries into it"><option value="">All forms</option><?php foreach ($forms as $f) echo '<option value="' . $f['id'] . '"' . ($fid == $f['id'] ? ' selected' : '') . '>' . e($f['name'] . (form_tags($f) ? '  [' . implode(', ', form_tags($f)) . ']' : '')) . '</option>'; ?></select></label>
<label>Search<input name="s" value="<?= e($search) ?>" placeholder="Any field..."></label>
<label>Status<select name="status"><option value="">Any</option><?php foreach (ENTRY_STATUSES as $k => $l) echo '<option value="' . $k . '"' . ($statusF === $k ? ' selected' : '') . ">$l</option>"; ?></select></label>
<label>Assigned<select name="assigned"><option value="">Anyone</option><option value="me" <?= $assignF === 'me' ? 'selected' : '' ?>>Me</option><option value="none" <?= $assignF === 'none' ? 'selected' : '' ?>>Unassigned</option><?php foreach ($users as $u) echo '<option value="' . $u['id'] . '"' . ((string)$assignF === (string)$u['id'] ? ' selected' : '') . '>' . e($u['name']) . '</option>'; ?></select></label>
<label>From<input type="date" name="from" value="<?= e($from) ?>"></label><label>To<input type="date" name="to" value="<?= e($to) ?>"></label>
<button class="btn">Filter</button></form>

<div class="actions" style="margin-bottom:14px;justify-content:space-between">
  <div class="actions"><?php if ($formRow): ?><button type="button" class="btn sec sm" onclick="document.getElementById('colDlg').showModal()"><?= icon('settings') ?>Select fields</button><?php endif; ?>
  <?php if ($view === 'spam' && can('editor')): ?><a class="btn sec sm" href="spam.php">Spam rules</a><?php endif; ?></div>
  <div class="actions"><?php if ($formRow && $canEdit): ?><a class="btn sec sm" href="import.php?form=<?= $fid ?>"><?= icon('upload') ?>Import</a><?php endif; ?><span class="muted">Export:</span>
    <?php foreach (['csv' => 'CSV', 'xlsx' => 'Excel'] as $k => $l): ?><a class="btn sec sm" href="?<?= e(http_build_query($qsBase + ['sort' => $sort, 'dir' => strtolower($dir), 'export' => $k])) ?>"><?= $l ?></a><?php endforeach; ?></div>
</div>

<form method="post"><?= csrf_field() ?>
<table><tr><?php if ($canEdit): ?><th style="width:34px"><input type="checkbox" onclick="document.querySelectorAll('.cb').forEach(c=>c.checked=this.checked)"></th><?php endif; ?>
<th><?= $sortLink('id', '#') ?></th>
<?php if ($formRow): foreach ($cols as $c): ?><th><?= $sortLink('f:' . $c, $fieldByName[$c]['label']) ?></th><?php endforeach; else: ?><th>Form</th><th>Preview</th><?php endif; ?>
<th><?= $sortLink('status', 'Status') ?></th><th>Assigned</th><th><?= $sortLink('created_at', 'Date') ?></th><th style="width:1%"></th></tr>
<?php foreach ($rows as $r): $d = json_decode($r['data_json'], true) ?: []; ?>
<tr class="<?= $r['is_read'] ? '' : 'unread' ?>"><?php if ($canEdit): ?><td><input class="cb" type="checkbox" name="ids[]" value="<?= $r['id'] ?>"></td><?php endif; ?>
<td><a href="entry.php?id=<?= $r['id'] ?>"><?= $r['id'] ?></a></td>
<?php if ($formRow): foreach ($cols as $c): ?><td><?= e(mb_strimwidth(flat_value($d[$c] ?? ''), 0, 60, '...')) ?></td><?php endforeach;
else: $pv = []; foreach ($d as $v) { $t = flat_value($v); if ($t !== '') $pv[] = $t; if (count($pv) >= 3) break; } ?>
<td><?= e($r['fname']) ?></td><td><?= e(mb_strimwidth(implode(' | ', $pv), 0, 70, '...')) ?></td><?php endif; ?>
<td><span class="tag <?= ENTRY_STATUS_COLOR[status_token($r['status'])] ?>"><?= e(ENTRY_STATUSES[status_token($r['status'])]) ?></span><?= $r['is_spam'] ? ' <span class="tag red" title="' . e($r['spam_reason']) . '">spam</span>' : '' ?></td>
<td><?= e($r['aname'] ?: '-') ?></td><td><?= e(date('M j, Y g:i a', strtotime($r['created_at']))) ?></td>
<td><a class="btn sm sec" href="entry.php?id=<?= $r['id'] ?>"><?= icon('eye') ?>View</a></td></tr>
<?php endforeach; if (!$rows) echo '<tr><td colspan="12" class="muted">No entries found.</td></tr>'; ?></table>
<?php if ($canEdit && $rows): ?><div class="actions bulk">
  <select name="action" id="bulkAct" onchange="var v=document.getElementById('bulkVal');v.hidden=!['status','assign'].includes(this.value);v.innerHTML=this.value==='status'?document.getElementById('optStatus').innerHTML:document.getElementById('optAssign').innerHTML" style="width:auto;margin:0">
    <option value="">With selected...</option><option value="read">Mark as read</option><option value="unread">Mark as unread</option>
    <option value="status">Set status...</option><option value="assign">Assign to...</option>
    <?= $view === 'spam' ? '<option value="notspam">Not spam (restore)</option>' : '<option value="spam">Mark as spam</option>' ?>
    <option value="delete">Delete</option></select>
  <select name="value" id="bulkVal" hidden style="width:auto;margin:0"></select>
  <button class="btn sm" onclick="var a=document.getElementById('bulkAct').value;if(!a)return false;if(a==='delete')return confirm('Delete the selected entries permanently?')">Apply</button>
</div>
<template id="optStatus"><?php foreach (ENTRY_STATUSES as $k => $l) echo "<option value=\"$k\">$l</option>"; ?></template>
<template id="optAssign"><option value="">Unassigned</option><?php foreach ($users as $u) echo '<option value="' . $u['id'] . '">' . e($u['name']) . '</option>'; ?></template><?php endif; ?></form>

<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px"><div class="pager"><?php for ($i = 1; $i <= max(1, ceil($total / $per)); $i++) echo $i == $page ? "<span>$i</span>" : '<a href="?' . e(http_build_query($qsBase + ['sort' => $sort, 'dir' => strtolower($dir), 'page' => $i])) . "\">$i</a>"; ?></div><label style="margin:0;display:flex;align-items:center;gap:8px"><span class="muted">Show</span><select style="width:auto;margin:0" onchange="var u=new URL(location.href);u.searchParams.set('per',this.value);u.searchParams.delete('page');location.href=u.href"><?php foreach ([10, 25, 50, 100, 200] as $n) echo '<option' . ($n === $per ? ' selected' : '') . '>' . $n . '</option>'; ?></select><span class="muted">entries per page</span></label></div>

<?php if ($formRow): $picked = $cols; $rest = array_diff(array_keys($fieldByName), $picked); ?>
<dialog id="colDlg"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="cols"><input type="hidden" name="cols" id="colsVal">
<h2>Select fields to show</h2><p class="muted" style="margin-top:-6px">Tick the fields you want as columns and use the arrows to reorder. Saved for you, for this form.</p>
<ul id="colList" class="collist"><?php foreach (array_merge($picked, $rest) as $n): ?>
<li data-n="<?= e($n) ?>"><label class="inline" style="margin:0"><input type="checkbox" <?= in_array($n, $picked, true) ? 'checked' : '' ?>><?= e($fieldByName[$n]['label']) ?></label><span><button type="button" class="btn ghost sm" data-mv="-1" aria-label="Move up">&uarr;</button><button type="button" class="btn ghost sm" data-mv="1" aria-label="Move down">&darr;</button></span></li><?php endforeach; ?></ul>
<div class="actions" style="justify-content:flex-end"><button type="button" class="btn sec" onclick="this.closest('dialog').close()">Cancel</button><button class="btn">Save columns</button></div></form></dialog>
<script>
(function(){var l=document.getElementById('colList');if(!l)return;
l.addEventListener('click',function(e){var b=e.target.closest('[data-mv]');if(!b)return;var li=b.closest('li'),d=+b.dataset.mv;if(d<0&&li.previousElementSibling)l.insertBefore(li,li.previousElementSibling);if(d>0&&li.nextElementSibling)l.insertBefore(li.nextElementSibling,li);});
l.closest('form').addEventListener('submit',function(){var v=[];l.querySelectorAll('li').forEach(function(li){if(li.querySelector('input').checked)v.push(li.dataset.n)});document.getElementById('colsVal').value=v.join(',');});})();
</script><?php endif; ?>
<?php admin_footer();
