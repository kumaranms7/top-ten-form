<?php
require __DIR__ . '/_layout.php';
$me = require_login('editor');
$fid = (int)($_GET['form'] ?? $_POST['form_id'] ?? 0);
$form = site_form($fid);
if (!$form) { header('Location: forms.php'); exit; }
$fields = form_fields($form);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? ''; $hid = (int)($_POST['id'] ?? 0);
    $hook = $hid ? q('SELECT * FROM webhooks WHERE id=? AND form_id=?', [$hid, $fid])->fetch() : null;
    if ($act === 'save') {
        $name = mb_substr(trim($_POST['name'] ?? ''), 0, 190) ?: 'Webhook'; $url = trim($_POST['url'] ?? ''); $secret = trim($_POST['secret'] ?? '');
        if ($err = webhook_url_error($url)) flash($err, 'err');
        else {
            if ($hook) q('UPDATE webhooks SET name=?,url=?,secret=?,active=? WHERE id=?', [$name, $url, $secret, empty($_POST['active']) ? 0 : 1, $hid]);
            else q('INSERT INTO webhooks (form_id,name,url,secret,active) VALUES (?,?,?,?,?)', [$fid, $name, $url, $secret, empty($_POST['active']) ? 0 : 1]);
            log_activity("Saved webhook for form #$fid"); flash('Webhook saved.');
        }
    } elseif ($hook && $act === 'delete') { q('DELETE FROM webhooks WHERE id=?', [$hid]); q('DELETE FROM webhook_logs WHERE webhook_id=?', [$hid]); flash('Webhook deleted.'); }
    elseif ($hook && $act === 'toggle') q('UPDATE webhooks SET active=1-active WHERE id=?', [$hid]);
    elseif ($hook && $act === 'test') {
        $sample = []; foreach ($fields as $f) if (is_input_field($f)) $sample[$f['name']] = $f['type'] === 'checkbox' ? ['Sample option'] : 'Sample ' . $f['label'];
        $payload = webhook_payload($form, $fields, 0, $sample, ['ip' => '203.0.113.9', 'page_url' => 'https://www.example.com/contact', 'user_agent' => 'Webhook test', 'referrer' => '', 'utm' => new stdClass()]);
        $payload['test'] = true;
        [$ok, $err] = webhook_send($hook, $payload, null, 0);
        flash($ok ? 'Test delivered: the endpoint answered with success.' : 'Test failed: ' . $err, $ok ? 'ok' : 'err');
    }
    header("Location: webhooks.php?form=$fid"); exit;
}
$hooks = q('SELECT * FROM webhooks WHERE form_id=? ORDER BY id DESC', [$fid])->fetchAll();
$edit = null;
if (isset($_GET['edit'])) $edit = $_GET['edit'] ? q('SELECT * FROM webhooks WHERE id=? AND form_id=?', [(int)$_GET['edit'], $fid])->fetch() : ['id' => 0, 'name' => '', 'url' => '', 'secret' => bin2hex(random_bytes(16)), 'active' => 1];
admin_header('Webhooks', 'forms', '', 'Send every new entry to another app (Zapier, Make, your CRM) the moment it arrives');
echo form_tabs($fid, 'webhooks'); ?>
<?php if ($edit): ?>
<div class="card"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><input type="hidden" name="form_id" value="<?= $fid ?>">
<div class="row"><label>Name<input name="name" value="<?= e($edit['name']) ?>" placeholder="e.g. Zapier - new leads" required></label>
<label>Endpoint URL<input name="url" value="<?= e($edit['url']) ?>" placeholder="https://hooks.zapier.com/..." required></label></div>
<label>Signing secret <small>(optional. We sign every request with HMAC-SHA256 in the <code>X-TopTen-Signature</code> header, so your server can verify it came from here.)</small><input name="secret" value="<?= e($edit['secret']) ?>"></label>
<label class="inline"><input type="checkbox" name="active" value="1" <?= $edit['active'] ? 'checked' : '' ?>> Active</label>
<p><button class="btn">Save webhook</button> <a class="btn sec" href="webhooks.php?form=<?= $fid ?>">Cancel</a></p></form></div>
<?php else: ?>
<p><a class="btn" href="webhooks.php?form=<?= $fid ?>&edit=0">+ Add webhook</a></p>
<?php foreach ($hooks as $h): $logs = q('SELECT * FROM webhook_logs WHERE webhook_id=? ORDER BY id DESC LIMIT 6', [$h['id']])->fetchAll(); ?>
<div class="card"><div class="actions" style="justify-content:space-between"><div><h2 style="margin:0"><?= e($h['name']) ?> <span class="tag <?= $h['active'] ? 'green' : 'red' ?>"><?= $h['active'] ? 'active' : 'off' ?></span></h2><div class="muted" style="word-break:break-all"><?= e($h['url']) ?></div></div>
<div class="actions"><a class="btn sm" href="webhooks.php?form=<?= $fid ?>&edit=<?= $h['id'] ?>">Edit</a>
<?php foreach (['test' => 'Send test', 'toggle' => $h['active'] ? 'Turn off' : 'Turn on', 'delete' => 'Delete'] as $a => $l): ?>
<form method="post" <?= $a === 'delete' ? 'onsubmit="return confirm(\'Delete this webhook?\')"' : '' ?>><?= csrf_field() ?><input type="hidden" name="id" value="<?= $h['id'] ?>"><input type="hidden" name="form_id" value="<?= $fid ?>"><button class="btn sm <?= $a === 'delete' ? 'danger' : 'sec' ?>" name="action" value="<?= $a ?>"><?= $l ?></button></form><?php endforeach; ?></div></div>
<?php if ($logs): ?><table style="margin-top:14px"><tr><th>When</th><th>Entry</th><th>Result</th><th>HTTP</th><th>Response</th></tr>
<?php foreach ($logs as $l): ?><tr><td><?= e(fmt_datetime(strtotime($l['created_at']))) ?></td><td><?= $l['entry_id'] ? '#' . (int)$l['entry_id'] : 'test' ?></td><td><span class="tag <?= $l['status'] === 'ok' ? 'green' : 'red' ?>"><?= $l['status'] ?></span></td><td><?= e($l['http_code']) ?></td><td><?= e(mb_strimwidth((string)$l['response'], 0, 80, '...')) ?></td></tr><?php endforeach; ?></table>
<?php else: ?><p class="muted" style="margin:12px 0 0">No deliveries yet.</p><?php endif; ?></div>
<?php endforeach; if (!$hooks): ?><div class="card empty-state"><h2>No webhooks yet</h2><p>Add one to push each new entry (as JSON) to Zapier, Make, a CRM or your own server.</p></div><?php endif; ?>
<div class="card"><h2>What gets sent</h2><p class="muted" style="margin-top:-6px">A JSON <code>POST</code> for every new entry that is not spam. Failed deliveries are retried automatically (1 min, 5 min, 15 min, 1 h, 6 h). Uploaded files are not attached; the file name is included.</p>
<pre class="payload"><?= e(json_encode(['event' => 'entry.created', 'form' => ['id' => (int)$fid, 'name' => $form['name']], 'entry' => ['id' => 123, 'created_at' => '2026-11-09T09:30:00-05:00', 'data' => ['name' => 'Ann Lee', 'email' => 'ann@example.com'], 'labels' => ['name' => 'Name', 'email' => 'Email'], 'meta' => ['ip' => '203.0.113.9', 'page_url' => 'https://www.yoursite.com/contact', 'referrer' => 'https://www.google.com/', 'utm' => ['utm_source' => 'google', 'utm_medium' => 'cpc']]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre></div>
<?php endif; admin_footer();
