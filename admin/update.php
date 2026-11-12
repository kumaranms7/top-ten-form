<?php
require __DIR__ . '/_layout.php';
$me = require_super();
$pkg = $_SESSION['update_pkg'] ?? null; // ['path' => ..., 'name' => ...] of an uploaded package waiting for confirmation

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); @set_time_limit(300);
    $act = $_POST['action'] ?? '';
    if ($act === 'upload') {
        $up = $_FILES['pkg'] ?? null;
        if (!$up || $up['error'] !== UPLOAD_ERR_OK) flash('Choose the update ZIP first (or it is larger than the PHP upload limit).', 'err');
        else {
            $tmp = storage_dir() . '/tmp/update_' . bin2hex(random_bytes(6)) . '.zip';
            if (!move_uploaded_file($up['tmp_name'], $tmp)) flash('Could not store the uploaded file.', 'err');
            else {
                [$info, $err] = update_inspect($tmp);
                if (!$info) { @unlink($tmp); flash($err, 'err'); }
                else { if ($pkg) @unlink($pkg['path']); $_SESSION['update_pkg'] = ['path' => $tmp, 'name' => basename($up['name'])]; }
            }
        }
    } elseif ($act === 'cancel') { if ($pkg) @unlink($pkg['path']); unset($_SESSION['update_pkg']); }
    elseif ($act === 'apply' && $pkg && is_file($pkg['path'])) {
        $pw = q('SELECT password FROM users WHERE id=?', [$me['id']])->fetchColumn();
        if (!password_verify($_POST['password'] ?? '', (string)$pw)) flash('Your password is not correct.', 'err');
        else {
            [$ok, $msg, $rid] = update_apply($pkg['path']);
            @unlink($pkg['path']); unset($_SESSION['update_pkg']);
            log_activity($ok ? "Installed update: $msg" : "Update failed: $msg");
            flash($msg . ($ok ? ' The database is upgraded automatically on the next page load.' : ''), $ok ? 'ok' : 'err');
            header('Location: update.php?done=' . ($ok ? '1' : '0')); exit;
        }
    } elseif ($act === 'rollback') {
        [$ok, $msg] = update_rollback((string)($_POST['id'] ?? '')); log_activity("Rollback: $msg"); flash($msg, $ok ? 'ok' : 'err');
        header('Location: update.php'); exit;
    }
    header('Location: update.php'); exit;
}
$info = null;
if ($pkg && is_file($pkg['path'])) [$info] = update_inspect($pkg['path']);
$rbs = update_rollbacks();
admin_header('Updates', 'settings', '', 'Install a new version from a ZIP file, with automatic backup and one-click rollback'); ?>
<div class="tabs"><a href="settings.php">Settings</a><a href="backup.php">Backups</a><a class="active" href="update.php">Updates</a></div>

<div class="grid">
  <div class="stat c-indigo"><span class="ico"><?= icon('settings') ?></span><div><b><?= e(TF_VERSION) ?></b>Installed version</div></div>
  <div class="stat c-teal"><span class="ico"><?= icon('logs') ?></span><div><b>v<?= (int)setting('db_version', 0) ?></b>Database version</div></div>
</div>

<?php if ($info): $cmp = $info['cmp']; ?>
<div class="card"><h2>Ready to install: version <?= e($info['version']) ?></h2>
<p><?= $cmp > 0 ? '<span class="tag green">newer</span>' : ($cmp === 0 ? '<span class="tag amber">same version</span> This reinstalls the current files.' : '<span class="tag red">older</span> This would downgrade the app.') ?>
 &middot; <?= (int)$info['count'] ?> file(s), <?= round($info['bytes'] / 1024) ?> KB &middot; from <code><?= e($pkg['name']) ?></code></p>
<?php if ($info['changelog'] !== ''): ?><h3>What's new</h3><pre class="payload" style="white-space:pre-wrap"><?= e($info['changelog']) ?></pre><?php endif; ?>
<ul class="muted"><li>An automatic <b>backup</b> is taken first.</li><li>Your settings, forms, entries, uploads, logos and <code>config/config.php</code> are kept.</li><li>Every replaced file is saved, so you can <b>roll back</b> afterwards.</li></ul>
<form method="post" class="row" style="align-items:flex-end"><?= csrf_field() ?>
<label>Confirm with your password<input type="password" name="password" required autocomplete="current-password"></label>
<div class="actions" style="flex:0 0 auto;margin-bottom:14px"><button class="btn" name="action" value="apply">Install update</button><button class="btn sec" name="action" value="cancel" formnovalidate>Cancel</button></div></form>
<p class="muted" style="margin:0">Only install packages from a source you trust: an update contains program code that will run on your server.</p></div>
<?php else: ?>
<div class="card"><h2>Install an update</h2>
<p class="muted" style="margin-top:-6px">Upload the new version's ZIP (for example <code>topten-form-2.1.0.zip</code>). You'll see what it contains before anything changes.</p>
<form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="upload"><label>Update package (.zip)<input type="file" name="pkg" accept=".zip,application/zip" required></label><button class="btn">Check package</button></form></div>
<?php endif; ?>

<h2 style="margin-top:22px">Rollback</h2>
<table><tr><th>Installed</th><th>From</th><th>To</th><th>Files</th><th></th></tr>
<?php foreach ($rbs as $r): ?><tr><td><?= e(fmt_datetime(strtotime($r['created_at']))) ?></td><td><?= e($r['from']) ?></td><td><?= e($r['to']) ?></td><td><?= count($r['replaced']) + count($r['added']) ?></td>
<td><form method="post" onsubmit="return confirm('Restore the files from before this update?')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($r['id']) ?>"><button class="btn sm sec" name="action" value="rollback">Roll back</button></form></td></tr><?php endforeach;
if (!$rbs) echo '<tr><td colspan="5" class="muted">No updates installed yet. After you install one, you can undo it here.</td></tr>'; ?></table>
<p class="muted">Rolling back restores the program files. If the update also upgraded the database, restore the backup that was taken automatically (see <a href="backup.php">Backups</a>).</p>
<?php admin_footer();
