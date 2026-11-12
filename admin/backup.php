<?php
require __DIR__ . '/_layout.php';
$me = require_super();

/** Re-check the admin's password and the typed word before anything destructive. */
function confirm_destructive(array $me): ?string {
    $pw = q('SELECT password FROM users WHERE id=?', [$me['id']])->fetchColumn();
    if (!password_verify($_POST['password'] ?? '', (string)$pw)) return 'Your password is not correct.';
    if (trim($_POST['confirm'] ?? '') !== 'RESTORE') return 'Type RESTORE (in capitals) to confirm.';
    return null;
}

// ---- download ----
if (isset($_GET['dl'])) {
    $p = backup_path((string)$_GET['dl']);
    if (!$p) { http_response_code(404); exit('Not found'); }
    log_activity('Downloaded backup ' . basename($p));
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="' . basename($p) . '"'); header('Content-Length: ' . filesize($p)); header('X-Content-Type-Options: nosniff');
    readfile($p); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); @set_time_limit(300);
    $act = $_POST['action'] ?? '';
    if ($act === 'create') {
        [$name, $err] = backup_create('manual');
        flash($name ? "Backup created: $name" : "Backup failed: $err", $name ? 'ok' : 'err'); log_activity('Created backup');
    } elseif ($act === 'delete') {
        $p = backup_path((string)($_POST['name'] ?? '')); if ($p) { @unlink($p); flash('Backup deleted.'); log_activity('Deleted backup ' . basename($p)); }
    } elseif ($act === 'schedule') {
        set_setting('backup_schedule', in_array($_POST['schedule'] ?? '', ['off', 'daily', 'weekly'], true) ? $_POST['schedule'] : 'off');
        set_setting('backup_keep', (string)max(1, min(60, (int)($_POST['keep'] ?? 7)))); flash('Backup schedule saved.');
    } elseif ($act === 'restore') {
        if ($e = confirm_destructive($me)) flash($e, 'err');
        else {
            $path = null; $tmpUp = null;
            if (!empty($_POST['name'])) $path = backup_path((string)$_POST['name']);
            elseif (!empty($_FILES['file']['tmp_name']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) { $tmpUp = storage_dir() . '/tmp/upload_' . bin2hex(random_bytes(4)) . '.zip'; move_uploaded_file($_FILES['file']['tmp_name'], $tmpUp); $path = $tmpUp; }
            if (!$path) flash('Choose a backup to restore.', 'err');
            else {
                [$ok, $msg] = backup_restore($path); if ($tmpUp) @unlink($tmpUp);
                if ($ok) log_activity('Restored a backup');
                flash($msg, $ok ? 'ok' : 'err');
            }
        }
    }
    header('Location: backup.php'); exit;
}
$list = backup_list();
$mode = setting('backup_schedule', 'off'); $last = (int)setting('last_backup', 0);
$fmt = fn($b) => $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : max(1, round($b / 1024)) . ' KB';
$tagc = ['manual' => '', 'scheduled' => 'green', 'pre-restore' => 'amber', 'pre-update' => 'amber'];
admin_header('Backups', 'settings', '', 'Download a full copy of your forms, entries and files, or restore one'); ?>
<div class="tabs"><a href="settings.php">Settings</a><a class="active" href="backup.php">Backups</a><a href="update.php">Updates</a></div>

<div class="grid2">
<div class="card"><h2>Create a backup</h2><p class="muted" style="margin-top:-6px">One ZIP with the database (forms, entries, users, settings), uploaded files and logos. Stored in your private storage folder (<code><?= e(storage_dir()) ?>/backups</code>).</p>
<form method="post"><?= csrf_field() ?><button class="btn" name="action" value="create"><?= icon('copy') ?>Back up now</button></form></div>
<div class="card"><h2>Automatic backups</h2>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="schedule"><div class="row">
<label>How often<select name="schedule"><?php foreach (['off' => 'Off', 'daily' => 'Every day', 'weekly' => 'Every week'] as $k => $l) echo '<option value="' . $k . '"' . ($mode === $k ? ' selected' : '') . ">$l</option>"; ?></select></label>
<label>Keep the newest<input type="number" min="1" max="60" name="keep" value="<?= (int)setting('backup_keep', 7) ?>"></label></div>
<button class="btn sm">Save schedule</button> <span class="muted">Last automatic run: <?= $last ? e(fmt_datetime($last)) : 'never' ?><?= setting('last_backup_status') ? ' (' . e(setting('last_backup_status')) . ')' : '' ?></span></form>
<p class="muted" style="margin-bottom:0">Runs through the background tasks, so it works without any cron setup while the app is in use.</p></div>
</div>

<h2 style="margin-top:22px">Your backups</h2>
<table><tr><th>File</th><th>Type</th><th>Size</th><th>Created</th><th></th></tr>
<?php foreach ($list as $b): ?><tr><td><?= e($b['name']) ?></td><td><span class="tag <?= $tagc[$b['reason']] ?? '' ?>"><?= e($b['reason']) ?></span></td><td><?= $fmt($b['size']) ?></td><td><?= e(fmt_datetime($b['time'])) ?></td>
<td class="actions"><a class="btn sm sec" href="backup.php?dl=<?= urlencode($b['name']) ?>">Download</a>
<button class="btn sm sec" type="button" onclick="restoreDlg('<?= e($b['name']) ?>')">Restore</button>
<form method="post" onsubmit="return confirm('Delete this backup file?')"><?= csrf_field() ?><input type="hidden" name="name" value="<?= e($b['name']) ?>"><button class="btn sm danger" name="action" value="delete">Delete</button></form></td></tr><?php endforeach;
if (!$list) echo '<tr><td colspan="5" class="muted">No backups yet.</td></tr>'; ?></table>

<div class="card"><h2>Restore from a file</h2><p class="muted" style="margin-top:-6px">Have a backup ZIP from this or another install? Upload it here.</p>
<button class="btn sec" type="button" onclick="restoreDlg('')">Choose a file to restore...</button></div>

<dialog id="restoreDlg" class="wide"><form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="restore"><input type="hidden" name="name" id="rName">
<h2>Restore a backup</h2>
<div class="alert err"><b>This replaces your current forms, entries, users and settings</b> with the contents of the backup. A safety backup of the current state is taken first, so you can undo this. You will probably need to sign in again.</div>
<p id="rWhich" class="muted" style="margin-top:0"></p>
<label id="rFileWrap" hidden>Backup ZIP file<input type="file" name="file" accept=".zip,application/zip"></label>
<div class="row"><label>Your password<input type="password" name="password" required autocomplete="current-password"></label><label>Type <b>RESTORE</b> to confirm<input name="confirm" required autocomplete="off" placeholder="RESTORE"></label></div>
<div class="actions" style="justify-content:flex-end"><button type="button" class="btn sec" onclick="this.closest('dialog').close()">Cancel</button><button class="btn danger">Restore now</button></div></form></dialog>
<script>
function restoreDlg(name){document.getElementById('rName').value=name;document.getElementById('rFileWrap').hidden=!!name;
document.getElementById('rWhich').textContent=name?('Restoring: '+name):'Upload the backup ZIP below.';
document.querySelector('#restoreDlg input[type=file]').required=!name;document.getElementById('restoreDlg').showModal();}
</script>
<?php admin_footer();
