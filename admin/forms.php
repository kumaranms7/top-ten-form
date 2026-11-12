<?php
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../app/templates.php';
$me = require_login();

/** Create a form (with its default admin notification) from already-sanitized pieces. Returns the new form id. */
function create_form(string $name, array $fields, array $settings, ?array $notifications, int $userId, string $status = 'active'): int {
    q('INSERT INTO forms (name,slug,fields_json,settings_json,created_by,status,site_id) VALUES (?,?,?,?,?,?,?)',
        [$name, slugify($name), json_encode($fields, JSON_UNESCAPED_UNICODE), json_encode($settings, JSON_UNESCAPED_UNICODE), $userId, $status, site_id()]);
    $id = (int)db()->lastInsertId();
    foreach ($notifications as $n) {
        q('INSERT INTO notifications (form_id,name,type,to_email,cc,bcc,from_name,from_email,reply_to,subject,body,active,condition_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$id, $n['name'], $n['type'], $n['to_email'], $n['cc'] ?? '', $n['bcc'] ?? '', $n['from_name'] ?? '', $n['from_email'] ?? '', $n['reply_to'] ?? '', $n['subject'], $n['body'], $n['active'] ?? 1, $n['condition_json'] ?? null]);
    }
    return $id;
}

// ---------- export one form as JSON ----------
if (isset($_GET['export'])) {
    require_login('editor');
    $f = q('SELECT * FROM forms WHERE id=? AND id IN ' . site_forms_sql(), [(int)$_GET['export']])->fetch();
    if (!$f) { flash('Form not found.', 'err'); header('Location: forms.php'); exit; }
    $notifs = q('SELECT name,type,to_email,cc,bcc,from_name,from_email,reply_to,subject,body,active,condition_json FROM notifications WHERE form_id=? ORDER BY id', [$f['id']])->fetchAll();
    $pack = ['topten_form' => 1, 'app_version' => TF_VERSION, 'exported_at' => date('c'), 'name' => $f['name'], 'fields' => form_fields($f), 'settings' => form_settings($f), 'notifications' => $notifs];
    log_activity('Exported form #' . $f['id']);
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="form-' . slugify($f['name']) . '.json"');
    echo json_encode($pack, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_login('editor'); csrf_check();
    $act = $_POST['action'] ?? ''; $id = (int)($_POST['id'] ?? 0);
    $default = fn(string $to) => [['name' => 'Admin notification', 'type' => 'admin', 'to_email' => $to, 'subject' => 'New submission: {form_name}', 'body' => default_notification_body()]];
    if ($act === 'create') {
        $name = mb_substr(trim($_POST['name'] ?? ''), 0, 190) ?: 'Untitled form';
        $tpl = form_templates()[$_POST['template'] ?? 'blank'] ?? form_templates()['blank'];
        $settings = sanitize_settings(array_merge(form_defaults(), $tpl['settings']));
        $nid = create_form($name, sanitize_fields($tpl['fields']), $settings, $default($me['email']), $me['id']);
        log_activity("Created form #$nid from template " . ($_POST['template'] ?? 'blank')); header("Location: builder.php?id=$nid"); exit;
    }
    if ($act === 'import') {
        $up = $_FILES['file'] ?? null;
        $pack = ($up && $up['error'] === UPLOAD_ERR_OK && $up['size'] < 2097152) ? json_decode((string)file_get_contents($up['tmp_name']), true) : null;
        if (!is_array($pack) || empty($pack['topten_form']) || !isset($pack['fields'])) { flash('That is not a valid TopTen Form export file.', 'err'); header('Location: forms.php'); exit; }
        try { $settings = sanitize_settings((array)($pack['settings'] ?? [])); } catch (InvalidArgumentException $e) { $settings = sanitize_settings(array_merge((array)$pack['settings'], ['success_mode' => 'message'])); }
        $notifs = [];
        foreach ((array)($pack['notifications'] ?? []) as $n) if (is_array($n) && !empty($n['subject'])) {
            $cond = isset($n['condition_json']) ? json_decode((string)$n['condition_json'], true) : null;
            $cond = sanitize_logic($cond, false);
            $notifs[] = ['name' => mb_substr((string)($n['name'] ?? 'Notification'), 0, 190), 'type' => ($n['type'] ?? '') === 'autoresponder' ? 'autoresponder' : 'admin', 'to_email' => mb_substr((string)($n['to_email'] ?? ''), 0, 1000),
                'cc' => mb_substr((string)($n['cc'] ?? ''), 0, 1000), 'bcc' => mb_substr((string)($n['bcc'] ?? ''), 0, 1000), 'from_name' => mb_substr((string)($n['from_name'] ?? ''), 0, 190), 'from_email' => mb_substr((string)($n['from_email'] ?? ''), 0, 190),
                'reply_to' => mb_substr((string)($n['reply_to'] ?? ''), 0, 190), 'subject' => mb_substr((string)$n['subject'], 0, 255), 'body' => (string)($n['body'] ?? ''), 'active' => empty($n['active']) ? 0 : 1, 'condition_json' => $cond ? json_encode($cond) : null];
        }
        if (!$notifs) $notifs = $default($me['email']);
        $nid = create_form(mb_substr(trim((string)($pack['name'] ?? 'Imported form')), 0, 170) . ' (imported)', sanitize_fields((array)$pack['fields']), $settings, $notifs, $me['id']);
        log_activity("Imported form #$nid"); flash('Form imported. Check the email recipients before going live.'); header("Location: builder.php?id=$nid"); exit;
    }
    if ($act === 'rename') {
        $name = mb_substr(trim($_POST['name'] ?? ''), 0, 190);
        if ($name !== '') { q('UPDATE forms SET name=?, slug=? WHERE id=? AND id IN ' . site_forms_sql(), [$name, slugify($name), $id]); log_activity("Renamed form #$id"); }
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') { header('Content-Type: application/json'); echo json_encode(['ok' => $name !== '', 'name' => $name]); exit; }
    }
    if (in_array($act, ['tags_add', 'tag_remove'], true) && ($tf = q('SELECT tags FROM forms WHERE id=? AND id IN ' . site_forms_sql(), [$id])->fetch())) {
        $cur = form_tags($tf);
        if ($act === 'tags_add') { $cur = clean_tags(array_merge($cur, clean_tags($_POST['tags'] ?? ''))); flash(count($cur) >= 10 ? 'Tags saved (a form can have up to 10).' : 'Tags saved.'); }
        else { $rm = mb_strtolower(trim($_POST['tag'] ?? '')); $cur = array_values(array_filter($cur, fn($t) => mb_strtolower($t) !== $rm)); }
        q('UPDATE forms SET tags=? WHERE id=?', [implode(', ', $cur), $id]); log_activity("Updated tags of form #$id");
        header('Location: forms.php' . (!empty($_POST['ftag']) ? '?tag=' . urlencode($_POST['ftag']) : '')); exit;
    }
    if ($act === 'bulk') {
        $ids = array_map('intval', $_POST['ids'] ?? []);
        if ($ids && in_array($_POST['value'] ?? '', ['enable', 'disable'], true)) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            q("UPDATE forms SET status=? WHERE id IN ($in) AND id IN " . site_forms_sql(), array_merge([$_POST['value'] === 'enable' ? 'active' : 'disabled'], $ids));
            log_activity('Bulk ' . $_POST['value'] . ' forms: ' . implode(',', $ids)); flash(count($ids) . ' form(s) ' . ($_POST['value'] === 'enable' ? 'enabled' : 'disabled') . '.');
        } else flash('Select at least one form.', 'err');
    }
    if ($act === 'duplicate' && ($f = q('SELECT * FROM forms WHERE id=? AND id IN ' . site_forms_sql(), [$id])->fetch())) {
        $notifs = q('SELECT name,type,to_email,cc,bcc,from_name,from_email,reply_to,subject,body,active,condition_json FROM notifications WHERE form_id=?', [$id])->fetchAll();
        $nid = create_form($f['name'] . ' (copy)', form_fields($f), form_settings($f), $notifs, $me['id'], 'disabled');
        log_activity("Duplicated form #$id"); flash('Form duplicated (disabled until you enable it).');
    }
    if ($act === 'toggle') { q("UPDATE forms SET status=IF(status='active','disabled','active') WHERE id=? AND id IN " . site_forms_sql(), [$id]); log_activity("Toggled form #$id"); }
    if ($act === 'delete' && q('SELECT 1 FROM forms WHERE id=? AND id IN ' . site_forms_sql(), [$id])->fetchColumn()) {
        require_login('admin');
        q('DELETE FROM entries WHERE form_id=?', [$id]); q('DELETE FROM notifications WHERE form_id=?', [$id]); q('DELETE FROM webhooks WHERE form_id=?', [$id]); q('DELETE FROM drafts WHERE form_id=?', [$id]); q('DELETE FROM forms WHERE id=?', [$id]);
        log_activity("Deleted form #$id"); flash('Form deleted.');
    }
    header('Location: forms.php'); exit;
}
$forms = q('SELECT f.*, (SELECT COUNT(*) FROM entries e WHERE e.form_id=f.id AND e.is_spam=0) cnt FROM forms f WHERE f.id IN ' . site_forms_sql() . ' ORDER BY f.id DESC')->fetchAll();
$allTags = [];
foreach ($forms as $f) foreach (form_tags($f) as $t) $allTags[mb_strtolower($t)] = $t;
natcasesort($allTags);
$ftag = mb_strtolower(trim($_GET['tag'] ?? ''));
if ($ftag !== '') $forms = array_values(array_filter($forms, fn($f) => in_array($ftag, array_map('mb_strtolower', form_tags($f)), true)));
$templates = form_templates();
admin_header('Forms', 'forms', '', 'Create, edit and manage your forms'); ?>
<div class="row" style="align-items:center;margin-bottom:20px;justify-content:space-between">
  <div class="searchbox" style="flex:0 1 340px"><?= icon('search') ?><input id="find" placeholder="Find form..." autocomplete="off"></div>
  <?php if (can('editor')): ?><div class="actions" style="flex:0 0 auto"><button class="btn sec" type="button" onclick="document.getElementById('importForm').showModal()">Import</button><button class="btn" type="button" onclick="document.getElementById('newForm').showModal()"><?= icon('plus') ?>Create new form</button></div><?php endif; ?>
</div>
<?php if ($allTags): ?><div class="tagbar"><span class="muted">Tags</span><a class="tagpill <?= $ftag === '' ? 'on' : '' ?>" href="forms.php">All</a><?php foreach ($allTags as $k => $t): ?><a class="tagpill <?= $ftag === $k ? 'on' : '' ?>" href="forms.php?tag=<?= urlencode($t) ?>"><?= e($t) ?></a><?php endforeach; ?></div><?php endif; ?>
<?php if (can('editor') && $forms): ?>
<form method="post" id="bulkForm" class="actions bulkbar"><?= csrf_field() ?><input type="hidden" name="action" value="bulk"><label class="inline" style="margin:0"><input type="checkbox" id="selAll"> Select all</label>
<button class="btn sec sm" name="value" value="enable">Enable selected</button><button class="btn sec sm" name="value" value="disable">Disable selected</button></form><?php endif; ?>
<?php $pal = ['indigo', 'violet', 'pink', 'orange', 'green', 'teal', 'sky', 'blue']; foreach ($forms as $f): $col = $pal[$f['id'] % count($pal)]; ?>
<div class="form-card c-<?= $col ?>" data-name="<?= e(strtolower($f['name'] . ' ' . implode(' ', form_tags($f)))) ?>">
  <?php if (can('editor')): ?><input type="checkbox" class="fsel" name="ids[]" value="<?= $f['id'] ?>" form="bulkForm" aria-label="Select <?= e($f['name']) ?>"><?php endif; ?>
  <div class="fc-av"><?= e(strtoupper(mb_substr($f['name'], 0, 1))) ?></div>
  <div class="fc-main"><div class="fc-title"><a class="fname" data-id="<?= $f['id'] ?>" href="<?= can('editor') ? 'builder.php?id=' . $f['id'] : 'entries.php?form=' . $f['id'] ?>"><?= e($f['name']) ?></a>
  <?php if (can('editor')): ?><button type="button" class="rename" title="Rename" data-id="<?= $f['id'] ?>"><?= icon('edit') ?></button><?php endif; ?>
  <span class="tag <?= $f['status'] === 'active' ? 'green' : 'red' ?>"><?= $f['status'] ?></span>
  <?php foreach (form_tags($f) as $t): ?><span class="ftag"><?= e($t) ?><?php if (can('editor')): ?><form method="post" class="ftag-x"><?= csrf_field() ?><input type="hidden" name="action" value="tag_remove"><input type="hidden" name="id" value="<?= $f['id'] ?>"><input type="hidden" name="tag" value="<?= e($t) ?>"><input type="hidden" name="ftag" value="<?= e($ftag) ?>"><button title="Remove tag" aria-label="Remove tag <?= e($t) ?>">&times;</button></form><?php endif; ?></span><?php endforeach; ?></div>
  <div class="fc-meta">ID <?= $f['id'] ?> &middot; <?= $f['cnt'] ?> entr<?= $f['cnt'] == 1 ? 'y' : 'ies' ?> &middot; Updated <?= e(fmt_date(strtotime($f['updated_at']))) ?></div></div>
  <div class="fc-actions">
    <a class="chip ic-only c-sky" data-tip="Entries" aria-label="Entries" href="entries.php?form=<?= $f['id'] ?>"><?= icon('entries') ?></a>
    <a class="chip ic-only c-pink" data-tip="Preview" aria-label="Preview" href="preview.php?id=<?= $f['id'] ?>" target="_blank" rel="noopener"><?= icon('eye') ?></a>
    <?php if (can('editor')): ?>
    <a class="chip ic-only c-violet" data-tip="Edit" aria-label="Edit" href="builder.php?id=<?= $f['id'] ?>"><?= icon('edit') ?></a>
    <a class="chip ic-only c-orange" data-tip="Notifications" aria-label="Notifications" href="notifications.php?form=<?= $f['id'] ?>"><?= icon('mail') ?></a>
    <button type="button" class="chip ic-only c-amber" data-tip="Tags" aria-label="Tags" data-tagform="<?= $f['id'] ?>" data-formname="<?= e($f['name']) ?>"><?= icon('tag') ?></button>
    <a class="chip ic-only c-green" data-tip="Get code" aria-label="Get code" href="export.php?id=<?= $f['id'] ?>"><?= icon('code') ?></a>
    <a class="chip ic-only c-teal" data-tip="Export (JSON)" aria-label="Export as JSON" href="forms.php?export=<?= $f['id'] ?>"><?= icon('download') ?></a>
    <?php foreach (['toggle' => [icon('power'), $f['status'] === 'active' ? 'Disable' : 'Enable'], 'duplicate' => [icon('copy'), 'Duplicate']] as $a => [$ic, $l]): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $f['id'] ?>"><input type="hidden" name="action" value="<?= $a ?>"><button class="chip ic-only <?= $a === 'toggle' ? 'c-slate' : 'c-blue' ?>" data-tip="<?= $l ?>" aria-label="<?= $l ?>"><?= $ic ?></button></form><?php endforeach; ?>
    <?php if (can('admin')): ?><form method="post" onsubmit="return confirm('Delete this form and ALL its entries?')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $f['id'] ?>"><input type="hidden" name="action" value="delete"><button class="chip ic-only c-rose" data-tip="Delete" aria-label="Delete"><?= icon('trash') ?></button></form><?php endif; endif; ?>
  </div>
</div>
<?php endforeach; ?>
<?php if (!$forms): ?><div class="card empty-state"><h2>No forms yet</h2><p>Create your first form to get started.</p></div><?php endif; ?>
<p id="nomatch" class="muted" hidden>No forms match your search.</p>

<?php if (can('editor')): ?>
<dialog id="tagDlg"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="tags_add"><input type="hidden" name="id" id="tagId" value=""><input type="hidden" name="ftag" value="<?= e($ftag) ?>">
<h2>Add tags</h2><p class="muted" style="margin-top:-6px">Tags are optional. Use them to tell apart forms with the same title, or forms used for different purposes on the website (e.g. <i>home page</i>, <i>landing page</i>, <i>footer</i>).</p>
<label id="tagFor" style="font-weight:600"></label>
<label>Tags <small>(separate with commas, up to 10 per form)</small><input name="tags" id="tagInput" list="tagList" placeholder="tag1, tag2, tag3" maxlength="300" autocomplete="off"></label>
<datalist id="tagList"><?php foreach ($allTags as $t) echo '<option value="' . e($t) . '">'; ?></datalist>
<div class="actions" style="justify-content:flex-end;margin-top:14px"><button type="button" class="btn sec" onclick="this.closest('dialog').close()">Cancel</button><button class="btn">Add tags</button></div></form></dialog>
<dialog id="newForm" class="wide"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="create">
<h2>Create new form</h2><label>Form name<input name="name" placeholder="e.g. Contact us" required autofocus></label>
<p class="muted" style="margin:0 0 8px"><b>Start from a template</b></p>
<div class="tpl-grid"><?php foreach ($templates as $k => $t): ?>
<label class="tpl c-<?= $t['color'] ?>"><input type="radio" name="template" value="<?= $k ?>" <?= $k === 'blank' ? 'checked' : '' ?>><span class="tpl-in"><b><?= e($t['name']) ?></b><small><?= e($t['desc']) ?></small></span></label><?php endforeach; ?></div>
<div class="actions" style="justify-content:flex-end;margin-top:14px"><button type="button" class="btn sec" onclick="this.closest('dialog').close()">Cancel</button><button class="btn">Create &amp; open builder</button></div></form></dialog>
<dialog id="importForm"><form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="import">
<h2>Import a form</h2><p class="muted" style="margin-top:-6px">Choose a JSON file exported from TopTen Form (use <b>Export</b> on any form). It is added as a new form.</p>
<label>JSON file<input type="file" name="file" accept="application/json,.json" required></label>
<div class="actions" style="justify-content:flex-end"><button type="button" class="btn sec" onclick="this.closest('dialog').close()">Cancel</button><button class="btn">Import</button></div></form></dialog>
<form method="post" id="renameForm" hidden><?= csrf_field() ?><input type="hidden" name="action" value="rename"><input type="hidden" name="id" id="renId"><input type="hidden" name="name" id="renName"></form>
<?php endif; ?>
<script>
document.querySelectorAll('[data-tagform]').forEach(function(b){b.addEventListener('click',function(){document.getElementById('tagId').value=b.dataset.tagform;document.getElementById('tagFor').textContent=b.dataset.formname;var i=document.getElementById('tagInput');i.value='';document.getElementById('tagDlg').showModal();i.focus();});});
document.getElementById('find').addEventListener('input',function(){var q=this.value.toLowerCase(),n=0;document.querySelectorAll('.form-card').forEach(function(c){var m=c.dataset.name.indexOf(q)>-1;c.hidden=!m;if(m)n++});document.getElementById('nomatch').hidden=n>0});
var sa=document.getElementById('selAll');if(sa)sa.addEventListener('change',function(){document.querySelectorAll('.fsel').forEach(function(c){if(!c.closest('.form-card').hidden)c.checked=sa.checked})});
document.querySelectorAll('.rename').forEach(function(b){b.addEventListener('click',function(){
  var a=document.querySelector('.fname[data-id="'+b.dataset.id+'"]'),old=a.textContent,inp=document.createElement('input');
  inp.value=old;inp.className='renin';a.replaceWith(inp);inp.focus();inp.select();b.hidden=true;var done=false;
  function save(){if(done)return;done=true;var v=inp.value.trim();if(!v||v===old){location.reload();return}
    document.getElementById('renId').value=b.dataset.id;document.getElementById('renName').value=v;document.getElementById('renameForm').submit();}
  inp.addEventListener('blur',save);inp.addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();save()}if(e.key==='Escape'){done=true;location.reload()}});});});
</script>
<?php admin_footer();
