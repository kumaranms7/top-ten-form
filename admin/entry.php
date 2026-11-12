<?php
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../app/entries_lib.php';
$me = require_login();
$id = (int)($_GET['id'] ?? 0);
$en = q('SELECT e.*, f.name fname, f.fields_json, f.settings_json FROM entries e JOIN forms f ON f.id=e.form_id WHERE e.id=? AND e.form_id IN ' . site_forms_sql(), [$id])->fetch();
if (!$en) { flash('Entry not found.', 'err'); header('Location: entries.php'); exit; }
$data = json_decode($en['data_json'], true) ?: [];
$formLike = ['id' => $en['form_id'], 'name' => $en['fname'], 'fields_json' => $en['fields_json']];

// ---------- actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_login('editor'); csrf_check();
    $act = $_POST['action'] ?? '';
    if ($act === 'status' && status_to_db((string)($_POST['value'] ?? '')) !== null) { q('UPDATE entries SET status=? WHERE id=?', [status_to_db($_POST['value']), $id]); q('INSERT INTO entry_notes (entry_id,user_id,note) VALUES (?,?,?)', [$id, $me['id'], 'Status changed to ' . ENTRY_STATUSES[$_POST['value']]]); flash('Status updated.'); }
    elseif ($act === 'assign') {
        $uid = ($_POST['value'] ?? '') === '' ? null : (int)$_POST['value'];
        if ($uid && !q('SELECT 1 FROM user_sites WHERE user_id=? AND site_id=?', [$uid, site_id()])->fetchColumn()) $uid = null; // only people of this website
        q('UPDATE entries SET assigned_to=? WHERE id=?', [$uid, $id]);
        $nm = $uid ? q('SELECT name FROM users WHERE id=?', [$uid])->fetchColumn() : null;
        q('INSERT INTO entry_notes (entry_id,user_id,note) VALUES (?,?,?)', [$id, $me['id'], $nm ? "Assigned to $nm" : 'Unassigned']); flash('Assignment updated.');
    }
    elseif ($act === 'note') { $t = trim($_POST['note'] ?? ''); if ($t !== '') { q('INSERT INTO entry_notes (entry_id,user_id,note) VALUES (?,?,?)', [$id, $me['id'], mb_substr($t, 0, 5000)]); flash('Note added.'); } }
    elseif ($act === 'spam') { q("UPDATE entries SET is_spam=1, spam_reason='Marked as spam by a user' WHERE id=?", [$id]); flash('Moved to Spam.'); }
    elseif ($act === 'notspam') { q('UPDATE entries SET is_spam=0, spam_reason=NULL WHERE id=?', [$id]); flash('Restored from Spam.'); }
    elseif ($act === 'delete') {
        foreach ($data as $v) if (is_array($v) && isset($v['file'])) @unlink(upload_path($v['file']));
        q('DELETE FROM entries WHERE id=?', [$id]); q('DELETE FROM entry_notes WHERE entry_id=?', [$id]); log_activity("Deleted entry #$id");
        flash('Entry deleted.'); header('Location: entries.php?form=' . $en['form_id']); exit;
    }
    elseif ($act === 'reply') {
        require_once __DIR__ . '/../app/mailer.php';
        $to = trim($_POST['to'] ?? ''); $subj = trim($_POST['subject'] ?? ''); $msg = trim($_POST['message'] ?? '');
        if (!split_emails($to) || $subj === '' || $msg === '') flash('Enter a valid recipient, subject and message.', 'err');
        else {
            $html = wrap_email_html('<p>' . nl2br(e($msg)) . '</p>', $en['form_id']);
            queue_job('mail', ['to' => $to, 'from_email' => '', 'from_name' => '', 'reply_to' => $me['email'], 'subject' => $subj, 'body' => $html], (int)$en['form_id'], $id);
            q('INSERT INTO entry_notes (entry_id,user_id,note) VALUES (?,?,?)', [$id, $me['id'], "Replied to $to: $subj"]);
            if (in_array($en['status'], ['new', ''], true)) { q("UPDATE entries SET status='contacted' WHERE id=?", [$id]); q('INSERT INTO entry_notes (entry_id,user_id,note) VALUES (?,?,?)', [$id, $me['id'], 'Status changed to Contacted (automatic, after your reply)']); }
            log_activity("Replied to entry #$id"); flash('Reply queued. It is being sent now.');
        }
    }
    header("Location: entry.php?id=$id");
    if ($act === 'reply') { finish_request(); try { process_jobs(5); } catch (Throwable $e) { error_log($e->getMessage()); } } // send the reply right away
    exit;
}

if (!$en['is_read']) q('UPDATE entries SET is_read=1 WHERE id=?', [$id]);
$labels = []; $types = [];
foreach (json_decode($en['fields_json'], true) ?: [] as $f) { $labels[$f['name']] = strip_tags($f['label']); $types[$f['name']] = $f['type']; }
$notes = q('SELECT n.*, u.name uname FROM entry_notes n LEFT JOIN users u ON u.id=n.user_id WHERE n.entry_id=? ORDER BY n.id DESC', [$id])->fetchAll();
$mails = q('SELECT * FROM email_logs WHERE entry_id=? ORDER BY id', [$id])->fetchAll();
$users = q('SELECT u.id,u.name FROM users u JOIN user_sites us ON us.user_id=u.id WHERE u.active=1 AND us.site_id=? ORDER BY u.name', [site_id()])->fetchAll();
$src = entry_sources($en);
$replyTo = entry_reply_email($formLike, $data);
$canEdit = can('editor');
$prev = q('SELECT id FROM entries WHERE id<? AND form_id=? AND is_spam=? ORDER BY id DESC LIMIT 1', [$id, $en['form_id'], $en['is_spam']])->fetchColumn();
$next = q('SELECT id FROM entries WHERE id>? AND form_id=? AND is_spam=? ORDER BY id ASC LIMIT 1', [$id, $en['form_id'], $en['is_spam']])->fetchColumn();
admin_header('Entry #' . $id, 'entries', '', $en['fname'] . ' · ' . date('M j, Y g:i a', strtotime($en['created_at'])));
?>
<div class="actions" style="justify-content:space-between;margin-bottom:16px">
  <a href="entries.php?form=<?= $en['form_id'] ?><?= $en['is_spam'] ? '&view=spam' : '' ?>">&larr; Back to entries</a>
  <div class="actions"><?php if ($prev): ?><a class="btn sec sm" href="entry.php?id=<?= $prev ?>">&larr; Older</a><?php endif; ?><?php if ($next): ?><a class="btn sec sm" href="entry.php?id=<?= $next ?>">Newer &rarr;</a><?php endif; ?></div>
</div>
<?php if ($en['is_spam']): ?><div class="alert err"><b>This entry is in the Spam folder.</b> Reason: <?= e($en['spam_reason']) ?>. No emails or webhooks were sent for it.
<?php if ($canEdit): ?><form method="post" style="display:inline"><?= csrf_field() ?><button class="btn sm sec" name="action" value="notspam">Not spam</button></form><?php endif; ?></div><?php endif; ?>

<div class="entry-grid">
<div>
  <div class="card"><h2>Submission</h2><table class="plain">
  <?php foreach ($data as $k => $v): ?><tr><th style="width:210px"><?= e($labels[$k] ?? $k) ?></th><td>
  <?php if (is_array($v) && isset($v['file'])):
      $isImg = preg_match('/\.(png|jpe?g|gif|webp)$/i', $v['file']); ?>
      <a href="download.php?entry=<?= $id ?>&field=<?= urlencode($k) ?>"><?= e($v['name']) ?></a>
      <?php if ($isImg): ?><br><img class="entry-img" alt="<?= e($labels[$k] ?? $k) ?>" src="download.php?entry=<?= $id ?>&field=<?= urlencode($k) ?>&inline=1"><?php endif; ?>
  <?php else: echo nl2br(e(flat_value($v))); endif; ?></td></tr><?php endforeach; ?></table></div>

  <div class="card"><h2>Notes &amp; activity</h2>
  <?php if ($canEdit): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="note"><textarea name="note" rows="2" placeholder="Add an internal note (only your team sees this)..." required></textarea><p><button class="btn sm">Add note</button></p></form><?php endif; ?>
  <ul class="timeline"><?php foreach ($notes as $n): ?><li><b><?= e($n['uname'] ?: 'System') ?></b> <span class="muted"><?= e(date('M j, g:i a', strtotime($n['created_at']))) ?></span><div><?= nl2br(e($n['note'])) ?></div></li><?php endforeach;
  if (!$notes) echo '<li class="muted">No notes yet.</li>'; ?></ul></div>

  <div class="card"><h2>Emails sent for this entry</h2><table><tr><th>To</th><th>Subject</th><th>Status</th><th>Error</th></tr>
  <?php foreach ($mails as $m): ?><tr><td><?= e($m['to_email']) ?></td><td><?= e($m['subject']) ?></td><td><span class="tag <?= $m['status'] === 'sent' ? 'green' : 'red' ?>"><?= $m['status'] ?></span></td><td><?= e($m['error']) ?></td></tr><?php endforeach;
  if (!$mails) echo '<tr><td colspan="4" class="muted">None</td></tr>'; ?></table></div>
</div>

<div>
  <div class="card"><h2>Manage</h2>
  <?php if ($canEdit): ?>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="status"><label>Status<select name="value" onchange="this.form.submit()"><?php foreach (ENTRY_STATUSES as $k => $l) echo '<option value="' . $k . '"' . (status_token($en['status']) === $k ? ' selected' : '') . ">$l</option>"; ?></select></label></form>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="assign"><label>Assigned to<select name="value" onchange="this.form.submit()"><option value="">Unassigned</option><?php foreach ($users as $u) echo '<option value="' . $u['id'] . '"' . ($en['assigned_to'] == $u['id'] ? ' selected' : '') . '>' . e($u['name']) . '</option>'; ?></select></label></form>
  <div class="actions"><button class="btn" type="button" onclick="document.getElementById('replyDlg').showModal()"<?= $replyTo === '' ? ' disabled title="No email field on this entry"' : '' ?>><?= icon('mail') ?>Reply</button>
  <?php if (!$en['is_spam']): ?><form method="post"><?= csrf_field() ?><button class="btn sec" name="action" value="spam">Mark as spam</button></form><?php endif; ?>
  <form method="post" onsubmit="return confirm('Delete this entry permanently?')"><?= csrf_field() ?><button class="btn danger" name="action" value="delete">Delete</button></form></div>
  <?php else: ?><p>Status: <span class="tag <?= ENTRY_STATUS_COLOR[status_token($en['status'])] ?>"><?= e(ENTRY_STATUSES[status_token($en['status'])]) ?></span></p><?php endif; ?></div>

  <div class="card"><h2>Details</h2><table class="plain">
  <tr><th>Form</th><td><?= e($en['fname']) ?></td></tr><tr><th>Submitted</th><td><?= e(date('M j, Y g:i:s a', strtotime($en['created_at']))) ?></td></tr>
  <tr><th>Source</th><td><?= e($src['label']) ?></td></tr>
  <tr><th>Source page</th><td style="word-break:break-all"><?php if ($src['page']): ?><a href="<?= e($src['page']) ?>" target="_blank" rel="noopener noreferrer"><?= e($src['page']) ?></a><?php else: ?><span class="muted">Not recorded (visitor typed the address or came directly)</span><?php endif; ?></td></tr>
  <?php foreach ($src['utm'] as $k => $v): ?><tr><th><?= e($k) ?></th><td><?= e($v) ?></td></tr><?php endforeach; ?>
  <tr><th>Page URL</th><td style="word-break:break-all"><?= e($en['page_url']) ?></td></tr><tr><th>IP address</th><td><?= e($en['ip']) ?></td></tr><tr><th>Browser</th><td style="word-break:break-word"><?= e($en['user_agent']) ?></td></tr></table></div>
</div></div>

<?php if ($canEdit && $replyTo !== ''): ?>
<dialog id="replyDlg"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="reply"><h2>Reply to this entry</h2>
<label>To<input name="to" value="<?= e($replyTo) ?>" required></label>
<label>Subject<input name="subject" value="Re: <?= e($en['fname']) ?>" required></label>
<label>Message<textarea name="message" rows="7" required placeholder="Write your reply..."></textarea></label>
<p class="muted" style="margin-top:-6px">Sent from your site's address; replies go to <?= e($me['email']) ?>. The entry is marked "Contacted".</p>
<div class="actions" style="justify-content:flex-end"><button type="button" class="btn sec" onclick="this.closest('dialog').close()">Cancel</button><button class="btn">Send reply</button></div></form></dialog><?php endif; ?>
<?php admin_footer();
