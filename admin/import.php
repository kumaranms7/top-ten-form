<?php
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../app/entries_lib.php';
require_once __DIR__ . '/../app/import.php';
$me = require_login('editor');
$fid = (int)($_GET['form'] ?? $_POST['form_id'] ?? 0);
$form = site_form($fid);
if (!$form) { flash('Choose a form first.', 'err'); header('Location: entries.php'); exit; }
$fields = form_input_fields($form);
$pending = $_SESSION['import'] ?? null;
if ($pending && ($pending['form'] !== $fid || !is_file($pending['path']))) { $pending = null; unset($_SESSION['import']); }

if (isset($_GET['template'])) {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="import-template-' . slugify($form['name']) . '.csv"');
    echo import_template_csv($form); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); @set_time_limit(300);
    $act = $_POST['action'] ?? '';
    if ($act === 'upload') {
        $up = $_FILES['file'] ?? null;
        $ext = $up ? strtolower(pathinfo($up['name'], PATHINFO_EXTENSION)) : '';
        if (!$up || $up['error'] !== UPLOAD_ERR_OK) flash('Choose a CSV or Excel file first.', 'err');
        elseif (!in_array($ext, ['csv', 'txt', 'xlsx'], true)) flash('Only .csv and .xlsx files can be imported.', 'err');
        else {
            $tmp = storage_dir() . '/tmp/import_' . bin2hex(random_bytes(6)) . '.' . ($ext === 'xlsx' ? 'xlsx' : 'csv');
            move_uploaded_file($up['tmp_name'], $tmp);
            [$rows, $err] = import_read($tmp, $ext === 'xlsx' ? 'xlsx' : 'csv');
            if (!$rows) { @unlink($tmp); flash($err, 'err'); }
            else { if ($pending) @unlink($pending['path']); $_SESSION['import'] = ['form' => $fid, 'path' => $tmp, 'name' => basename($up['name']), 'ext' => $ext === 'xlsx' ? 'xlsx' : 'csv']; }
        }
    } elseif ($act === 'cancel') { if ($pending) @unlink($pending['path']); unset($_SESSION['import']); }
    elseif ($act === 'apply' && $pending) {
        [$rows, $err] = import_read($pending['path'], $pending['ext']);
        if (!$rows) flash($err, 'err');
        else {
            $map = []; $valid = array_merge(array_map(fn($f) => 'f:' . $f['name'], $fields), ['meta:created_at', 'meta:status', 'meta:ip', 'meta:page_url', 'meta:source_page', 'meta:utm_source', 'meta:utm_campaign', 'meta:utm_medium']);
            foreach ((array)($_POST['map'] ?? []) as $i => $t) if (in_array($t, $valid, true)) $map[(int)$i] = $t;
            if (!$map) flash('Match at least one column to a field.', 'err');
            else {
                $res = import_rows($form, $rows, $map, ['default_status' => $_POST['default_status'] ?? 'none', 'mark_read' => !empty($_POST['mark_read']), 'skip_dupes' => !empty($_POST['skip_dupes'])]);
                @unlink($pending['path']); unset($_SESSION['import']);
                log_activity("Imported {$res['imported']} entries into form #$fid");
                flash("Imported {$res['imported']} entr" . ($res['imported'] === 1 ? 'y' : 'ies') . ($res['dupes'] ? ", skipped {$res['dupes']} already there" : '') . ($res['empty'] ? ", skipped {$res['empty']} empty row(s)" : '') . '.');
                header('Location: entries.php?form=' . $fid); exit;
            }
        }
    }
    header('Location: import.php?form=' . $fid); exit;
}

$rows = null; $map = [];
if ($pending) { [$rows] = import_read($pending['path'], $pending['ext']); if ($rows) $map = import_automap($rows[0], $fields); }
$targets = ['' => '- Do not import -'];
foreach ($fields as $f) if (!in_array($f['type'], ['file', 'signature'], true)) $targets['f:' . $f['name']] = $f['label'];
$targets += ['meta:created_at' => 'Date submitted', 'meta:status' => 'Status', 'meta:ip' => 'IP address', 'meta:page_url' => 'Page URL', 'meta:source_page' => 'Source page URL', 'meta:utm_source' => 'Source', 'meta:utm_campaign' => 'Campaign', 'meta:utm_medium' => 'Medium'];
admin_header('Import entries', 'entries', '', 'Bring entries from a CSV or Excel file into "' . $form['name'] . '"');
echo form_tabs($fid, 'entries'); ?>

<?php if (!$rows): ?>
<div class="card"><h2>1. Choose a file</h2>
<p class="muted" style="margin-top:-6px">A <b>.csv</b> or <b>.xlsx</b> file whose first row is the column headings, up to <?= number_format(IMPORT_MAX_ROWS) ?> rows. Files exported from this app import back in without any changes. Imported entries are marked as read and <b>never send emails or webhooks</b>.</p>
<form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="upload"><input type="hidden" name="form_id" value="<?= $fid ?>">
<label>File<input type="file" name="file" accept=".csv,.txt,.xlsx" required></label>
<div class="actions"><button class="btn">Upload and preview</button><a class="btn sec" href="import.php?form=<?= $fid ?>&template=1">Download a CSV template</a><a class="btn sec" href="entries.php?form=<?= $fid ?>">Cancel</a></div></form></div>
<?php else: ?>
<div class="card"><h2>2. Match the columns</h2>
<p class="muted" style="margin-top:-6px"><b><?= e($pending['name']) ?></b>: <?= count($rows) - 1 ?> row(s). We matched the columns automatically; change anything that's wrong.</p>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="apply"><input type="hidden" name="form_id" value="<?= $fid ?>">
<table><tr><th>Column in your file</th><th>Example</th><th>Import it as</th></tr>
<?php foreach ($rows[0] as $i => $h): $ex = ''; for ($r = 1; $r < min(count($rows), 6) && $ex === ''; $r++) $ex = trim((string)($rows[$r][$i] ?? '')); ?>
<tr><td><b><?= e($h !== '' ? $h : '(column ' . ($i + 1) . ')') ?></b></td><td class="muted"><?= e(mb_strimwidth($ex, 0, 50, '...')) ?></td>
<td><select name="map[<?= $i ?>]" style="margin:0"><?php foreach ($targets as $k => $l) echo '<option value="' . e($k) . '"' . (($map[$i] ?? '') === $k ? ' selected' : '') . '>' . e($l) . '</option>'; ?></select></td></tr><?php endforeach; ?></table>
<h3>Options</h3>
<div class="row"><label>Status for rows without one<select name="default_status"><?php foreach (ENTRY_STATUSES as $k => $l) echo '<option value="' . $k . '"' . ($k === 'none' ? ' selected' : '') . ">$l</option>"; ?></select></label></div>
<label class="inline"><input type="checkbox" name="mark_read" value="1" checked> Mark imported entries as read</label>
<label class="inline"><input type="checkbox" name="skip_dupes" value="1" checked> Skip rows that are already in this form (same date and answers)</label>
<div class="actions" style="margin-top:12px"><button class="btn">Import <?= count($rows) - 1 ?> row(s)</button><button class="btn sec" name="action" value="cancel" formnovalidate>Cancel</button></div></form></div>
<?php endif; ?>
<?php admin_footer();
