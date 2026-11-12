<?php
require __DIR__ . '/_layout.php';
$me = require_login('editor');
require_once __DIR__ . '/../app/mailer.php';
$fid = (int)($_GET['form'] ?? $_POST['form_id'] ?? 0);
$form = site_form($fid);
if (!$form) { header('Location: forms.php'); exit; }
$fields = form_fields($form);

// Preview a notification exactly as recipients will see it (sample data, branded template)
if (isset($_GET['preview'])) {
    $n = q('SELECT * FROM notifications WHERE id=? AND form_id=?', [(int)$_GET['preview'], $fid])->fetch();
    if (!$n) { http_response_code(404); exit('Not found'); }
    $sample = []; foreach ($fields as $f) if (is_input_field($f)) $sample[$f['name']] = 'Sample ' . $f['label'];
    $meta = ['page_url' => 'https://www.example.com/contact', 'user_agent' => 'Sample browser'];
    echo wrap_email_html(merge_tags($n['body'], $form, $fields, $sample, 123, '203.0.113.9', true, $meta), $fid); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? ''; $nid = (int)($_POST['id'] ?? 0);
    if ($act === 'delete') { q('DELETE FROM notifications WHERE id=? AND form_id=?', [$nid, $fid]); flash('Notification deleted.'); }
    elseif ($act === 'logo') {
        $key = 'email_logo_form_' . $fid;
        if (!empty($_POST['remove'])) { delete_media((string)setting($key, '')); set_setting($key, ''); flash('This form now uses the site-wide email logo.'); }
        else {
            [$file, $lerr] = save_logo_upload($_FILES['logo'] ?? null);
            if ($lerr) flash($lerr, 'err'); elseif ($file) { delete_media((string)setting($key, '')); set_setting($key, $file); flash('Email logo updated for this form.'); } else flash('Choose an image first.', 'err');
        }
    }
    elseif ($act === 'toggle') { q('UPDATE notifications SET active=1-active WHERE id=? AND form_id=?', [$nid, $fid]); }
    elseif ($act === 'test') {
        require __DIR__ . '/../app/mailer.php';
        $n = q('SELECT * FROM notifications WHERE id=? AND form_id=?', [$nid, $fid])->fetch();
        $meta = ['page_url' => 'https://www.example.com/contact', 'user_agent' => 'Test browser']; $sample = []; foreach ($fields as $f) if (is_input_field($f)) $sample[$f['name']] = 'Sample ' . $f['label'];
        [$ok, $err] = send_mail(['to' => $me['email'], 'from_email' => $n['from_email'], 'from_name' => $n['from_name'], 'subject' => '[TEST] ' . merge_tags($n['subject'], $form, $fields, $sample, 0, '127.0.0.1', true, $meta),
            'body' => wrap_email_html(merge_tags($n['body'], $form, $fields, $sample, 0, '127.0.0.1', true, $meta), $fid)]);
        flash($ok ? 'Test email sent to ' . $me['email'] : 'Test failed: ' . $err, $ok ? 'ok' : 'err');
    } elseif ($act === 'save') {
        $d = [trim($_POST['name']) ?: 'Notification', in_array($_POST['type'], ['admin', 'autoresponder']) ? $_POST['type'] : 'admin', trim($_POST['to_email']), trim($_POST['cc']), trim($_POST['bcc']),
            trim($_POST['from_name']), trim($_POST['from_email']), trim($_POST['reply_to']), trim($_POST['subject']) ?: '(no subject)', $_POST['body'], empty($_POST['active']) ? 0 : 1];
        $cond = sanitize_logic(json_decode((string)($_POST['condition'] ?? ''), true), false);
        $d[] = $cond ? json_encode($cond) : null;
        if ($nid) { q('UPDATE notifications SET name=?,type=?,to_email=?,cc=?,bcc=?,from_name=?,from_email=?,reply_to=?,subject=?,body=?,active=?,condition_json=? WHERE id=? AND form_id=?', array_merge($d, [$nid, $fid])); }
        else { q('INSERT INTO notifications (name,type,to_email,cc,bcc,from_name,from_email,reply_to,subject,body,active,condition_json,form_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', array_merge($d, [$fid])); }
        flash('Notification saved.'); log_activity("Saved notification for form #$fid");
    }
    header("Location: notifications.php?form=$fid"); exit;
}
$list = q('SELECT * FROM notifications WHERE form_id=? ORDER BY id DESC', [$fid])->fetchAll();
$edit = null; $editing = isset($_GET['edit']);
if ($editing) {
    $edit = $_GET['edit'] ? q('SELECT * FROM notifications WHERE id=? AND form_id=?', [(int)$_GET['edit'], $fid])->fetch() : null;
    $edit = $edit ?: ['id' => 0, 'name' => '', 'type' => 'admin', 'to_email' => $me['email'], 'cc' => '', 'bcc' => '', 'from_name' => '', 'from_email' => '', 'reply_to' => '', 'subject' => 'New submission: {form_name}', 'body' => '<p>You have a new submission on <strong>{form_name}</strong> (Entry #{entry_id}).</p>{entry_details}', 'active' => 1, 'condition_json' => null];
}
admin_header('Email notifications: ' . $form['name'], 'forms'); ?>
<?= form_tabs($fid, 'notifications') ?>
<?php if ($edit): ?>
<div class="card"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= $edit['id'] ?>"><input type="hidden" name="form_id" value="<?= $fid ?>">
<div class="row"><label>Name<input name="name" value="<?= e($edit['name']) ?>" required></label>
<label>Type<select name="type"><option value="admin" <?= $edit['type'] === 'admin' ? 'selected' : '' ?>>Admin notification (with attachments)</option><option value="autoresponder" <?= $edit['type'] === 'autoresponder' ? 'selected' : '' ?>>Auto-responder to submitter</option></select></label></div>
<label>To <small>(comma separated; for an auto-responder use the email field's tag, e.g. {email})</small><input name="to_email" value="<?= e($edit['to_email']) ?>" required></label>
<div class="row"><label>CC<input name="cc" value="<?= e($edit['cc']) ?>"></label><label>BCC<input name="bcc" value="<?= e($edit['bcc']) ?>"></label></div>
<div class="row"><label>From name <small>(blank = global default)</small><input name="from_name" value="<?= e($edit['from_name']) ?>"></label><label>From email<input name="from_email" value="<?= e($edit['from_email']) ?>"></label><label>Reply-To <small>(e.g. {email})</small><input name="reply_to" value="<?= e($edit['reply_to']) ?>"></label></div>
<label>Subject<input name="subject" value="<?= e($edit['subject']) ?>" required></label>
<label>Message (HTML)<textarea name="body" rows="9" class="code"><?= e($edit['body']) ?></textarea></label>
<p class="muted">Merge tags: <code>{entry_details}</code> <code>{all_fields}</code> <code>{form_name}</code> <code>{page_url}</code> <code>{user_agent}</code> <code>{entry_id}</code> <code>{date}</code> <code>{ip}</code> <?php foreach ($fields as $f) if (is_input_field($f)) echo '<code>{' . e($f['name']) . '}</code> '; ?></p>
<div class="card" style="background:var(--surface-2);box-shadow:none"><h2 style="font-size:.95rem">Routing <small class="muted" style="font-weight:400">(optional)</small></h2>
<p class="muted" style="margin-top:-8px">Send this email only for certain answers, e.g. "Service type is Commercial" goes to your commercial team. Create several notifications with different conditions to route leads.</p>
<div id="condBox"></div><input type="hidden" name="condition" id="condVal" value="<?= e((string)($edit['condition_json'] ?? '')) ?>"></div>
<label class="inline"><input type="checkbox" name="active" value="1" <?= $edit['active'] ? 'checked' : '' ?>> Active</label>
<p><button class="btn">Save notification</button> <a class="btn sec" href="notifications.php?form=<?= $fid ?>">Cancel</a></p></form></div>
<?php else: ?>
<?php $fl = (string)setting('email_logo_form_' . $fid, ''); $hasFormLogo = $fl !== '' && is_file(media_dir() . '/' . $fl); $gl = (string)setting('email_logo', ''); $shown = $hasFormLogo ? $fl : (($gl !== '' && is_file(media_dir() . '/' . $gl)) ? $gl : ''); ?>
<div class="card"><h2>Email logo for this form</h2>
<div class="row" style="align-items:center"><div class="logoprev" style="margin:0;flex:0 0 220px"><?php if ($shown): ?><img src="../public/media/<?= e($shown) ?>" alt="Email logo"><?php else: ?><span class="muted">No logo yet</span><?php endif; ?></div>
<form method="post" enctype="multipart/form-data" style="flex:1"><?= csrf_field() ?><input type="hidden" name="action" value="logo"><input type="hidden" name="form_id" value="<?= $fid ?>">
<p class="muted" style="margin-top:0"><?= $hasFormLogo ? 'This form uses its own logo.' : ($shown ? 'Using the site-wide logo from Settings.' : 'Upload your website logo to show it at the top of every email from this form.') ?> PNG, JPG, WebP or GIF, up to 1 MB.</p>
<input type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/gif" required><div class="actions" style="margin-top:10px"><button class="btn sm">Upload logo</button>
<?php if ($hasFormLogo): ?><button class="btn sm sec" name="remove" value="1" formnovalidate>Use site-wide logo</button><?php endif; ?></div></form></div></div>
<p><a class="btn" href="notifications.php?form=<?= $fid ?>&edit=0">+ Add notification</a></p>
<table><tr><th>Name</th><th>Type</th><th>To</th><th>Subject</th><th>Status</th><th>Actions</th></tr>
<?php foreach ($list as $n): ?><tr><td><?= e($n['name']) ?><?= !empty($n['condition_json']) ? ' <span class="tag violet" title="Only sent for certain answers">routed</span>' : '' ?></td><td><?= e($n['type']) ?></td><td><?= e($n['to_email']) ?></td><td><?= e($n['subject']) ?></td>
<td><span class="tag <?= $n['active'] ? 'green' : 'red' ?>"><?= $n['active'] ? 'active' : 'off' ?></span></td>
<td class="actions"><a class="btn sm" href="notifications.php?form=<?= $fid ?>&edit=<?= $n['id'] ?>">Edit</a><a class="btn sm sec" href="notifications.php?form=<?= $fid ?>&preview=<?= $n['id'] ?>" target="_blank" rel="noopener">Preview</a>
<?php foreach (['test' => 'Send test', 'toggle' => $n['active'] ? 'Turn off' : 'Turn on', 'delete' => 'Delete'] as $a => $l): ?>
<form method="post" <?= $a === 'delete' ? 'onsubmit="return confirm(\'Delete?\')"' : '' ?>><?= csrf_field() ?><input type="hidden" name="id" value="<?= $n['id'] ?>"><input type="hidden" name="form_id" value="<?= $fid ?>"><button class="btn sm <?= $a === 'delete' ? 'danger' : 'sec' ?>" name="action" value="<?= $a ?>"><?= $l ?></button></form><?php endforeach; ?></td></tr>
<?php endforeach; if (!$list) echo '<tr><td colspan="6" class="muted">No notifications. Add one to get emailed on each submission.</td></tr>'; ?></table>
<?php endif;
$extra = '';
if ($edit) {
    $opts = array_map(fn($f) => ['name' => $f['name'], 'label' => $f['label'], 'type' => $f['type'], 'options' => $f['options'] ?? []], array_values(array_filter($fields, fn($f) => is_input_field($f) && $f['type'] !== 'hidden')));
    $extra = '<script src="assets/logic-editor.js?v=1"></script><script>(function(){var v=document.getElementById("condVal"),cur=null;try{cur=v.value?JSON.parse(v.value):null}catch(e){}'
        . 'TFLogic.mount(document.getElementById("condBox"),{logic:cur,fields:' . json_encode($opts) . ',withAction:false,onChange:function(l){v.value=l?JSON.stringify(l):""}});})();</script>';
}
admin_footer($extra);
