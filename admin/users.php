<?php
require __DIR__ . '/_layout.php';
$me = require_login('admin');
$sid = (int)site_id();

/** Is this user a member of the current website? (Everything below goes through this, so nobody can touch users of other websites.) */
function member(int $uid, int $sid): ?array {
    return q('SELECT u.*, us.role AS site_role FROM users u JOIN user_sites us ON us.user_id=u.id WHERE u.id=? AND us.site_id=?', [$uid, $sid])->fetch() ?: null;
}
/** Does the account belong to this website only? Only then may a website admin change the account itself (email, password, 2-step). */
function exclusive(int $uid, int $sid): bool {
    $u = q('SELECT is_super FROM users WHERE id=?', [$uid])->fetch();
    return $u && !$u['is_super'] && !q('SELECT 1 FROM user_sites WHERE user_id=? AND site_id<>?', [$uid, $sid])->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? ''; $uid = (int)($_POST['id'] ?? 0);
    $role = array_key_exists($_POST['role'] ?? '', ROLES) ? $_POST['role'] : 'editor';
    $name = trim($_POST['name'] ?? ''); $email = trim($_POST['email'] ?? ''); $pass = $_POST['password'] ?? '';
    $target = $uid ? member($uid, $sid) : null;
    if ($uid && !$target) { flash('That user is not part of this website.', 'err'); header('Location: users.php'); exit; }

    if ($act === 'save') {
        $other = q('SELECT id FROM users WHERE email=? AND id<>?', [$email, $uid])->fetch();
        if (!$uid) { // new account for this website
            if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL)) flash('Enter a name and a valid email.', 'err');
            elseif ($other) flash('That email already has an account. Ask the platform administrator to link it to this website.', 'err');
            elseif (strlen($pass) < 8) flash('Password must be at least 8 characters.', 'err');
            else {
                q('INSERT INTO users (name,email,password,role,active) VALUES (?,?,?,?,?)', [$name, $email, password_hash($pass, PASSWORD_DEFAULT), $role, empty($_POST['active']) ? 0 : 1]);
                $nid = (int)db()->lastInsertId(); q('INSERT INTO user_sites (user_id,site_id,role) VALUES (?,?,?)', [$nid, $sid, $role]);
                log_activity("Created user $email"); flash('User created.');
            }
        } elseif ($uid === (int)$me['id'] && !$me['is_super'] && $role !== 'admin') flash('You cannot remove your own admin role.', 'err');
        else {
            q('UPDATE user_sites SET role=? WHERE user_id=? AND site_id=?', [$role, $uid, $sid]);   // the role in THIS website
            if (exclusive($uid, $sid)) {                                                         // account details only for people who belong to this website alone
                if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL)) flash('Enter a name and a valid email.', 'err');
                elseif ($other) flash('That email is already in use.', 'err');
                elseif ($pass !== '' && strlen($pass) < 8) flash('Password must be at least 8 characters.', 'err');
                else {
                    q('UPDATE users SET name=?, email=?, active=? WHERE id=?', [$name, $email, $uid === (int)$me['id'] ? 1 : (empty($_POST['active']) ? 0 : 1), $uid]);
                    if ($pass !== '') { q('UPDATE users SET password=? WHERE id=?', [password_hash($pass, PASSWORD_DEFAULT), $uid]); twofa_forget_devices($uid); }
                    log_activity("Updated user #$uid"); flash('User saved.');
                }
            } else { log_activity("Changed role of user #$uid"); flash('Role updated. (This person also has access to other websites, so only the platform administrator can change their account details.)'); }
        }
    } elseif ($act === 'reset2fa') {
        if (exclusive($uid, $sid)) { q('UPDATE users SET twofa_enabled=0 WHERE id=?', [$uid]); twofa_forget_devices($uid); q('DELETE FROM twofa_codes WHERE user_id=?', [$uid]); log_activity("Reset two-step verification for user #$uid"); flash('Two-step verification was turned off for that user.'); }
        else flash('Only the platform administrator can reset two-step verification for people who have several websites.', 'err');
    } elseif ($act === 'remove') {
        if ($uid === (int)$me['id']) flash('You cannot remove yourself.', 'err');
        else {
            $ex = exclusive($uid, $sid);
            q('DELETE FROM user_sites WHERE user_id=? AND site_id=?', [$uid, $sid]);
            q('UPDATE entries SET assigned_to=NULL WHERE assigned_to=? AND form_id IN ' . site_forms_sql(), [$uid]);
            if ($ex) { foreach (['trusted_devices', 'twofa_codes', 'user_prefs', 'password_resets'] as $t) q("DELETE FROM $t WHERE user_id=?", [$uid]); q('DELETE FROM users WHERE id=?', [$uid]); }
            log_activity("Removed user #$uid from the website"); flash($ex ? 'User deleted.' : 'User removed from this website (their other websites are untouched).');
        }
    }
    header('Location: users.php'); exit;
}
$users = q('SELECT u.*, us.role AS site_role, (SELECT COUNT(*) FROM user_sites x WHERE x.user_id=u.id) AS nsites FROM users u JOIN user_sites us ON us.user_id=u.id WHERE us.site_id=? ORDER BY u.id DESC', [$sid])->fetchAll();
$edit = null; $editEx = true;
if (isset($_GET['edit'])) {
    $edit = (int)$_GET['edit'] ? member((int)$_GET['edit'], $sid) : ['id' => 0, 'name' => '', 'email' => '', 'site_role' => 'editor', 'active' => 1];
    if (!$edit) { flash('That user is not part of this website.', 'err'); header('Location: users.php'); exit; }
    $editEx = $edit['id'] ? exclusive((int)$edit['id'], $sid) : true;
}
admin_header('Users', 'users', '', 'People who can sign in to ' . ($GLOBALS['tf_site_name'] ?? (current_site()['name'] ?? 'this website'))); ?>
<?php if ($edit): ?>
<div class="card" style="max-width:560px"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= $edit['id'] ?>">
<?php if (!$editEx): ?><div class="alert ok">This person also works on other websites, so you can change their <b>role here</b> only. Their name, email, password and two-step settings are managed by the platform administrator.</div><?php endif; ?>
<label>Name<input name="name" value="<?= e($edit['name']) ?>" required <?= $editEx ? '' : 'readonly' ?>></label>
<label>Email<input type="email" name="email" value="<?= e($edit['email']) ?>" required <?= $editEx ? '' : 'readonly' ?>></label>
<label>Role in this website<select name="role"><option value="admin" <?= $edit['site_role'] === 'admin' ? 'selected' : '' ?>>Admin - everything in this website</option><option value="editor" <?= $edit['site_role'] === 'editor' ? 'selected' : '' ?>>Editor - build forms, manage entries</option><option value="viewer" <?= $edit['site_role'] === 'viewer' ? 'selected' : '' ?>>Viewer - read-only entries</option></select></label>
<?php if ($editEx): ?><label>Password <small><?= $edit['id'] ? '(leave blank to keep)' : '(min 8 chars)' ?></small><input type="password" name="password" autocomplete="new-password" <?= $edit['id'] ? '' : 'required' ?>></label>
<label class="inline"><input type="checkbox" name="active" value="1" <?= $edit['active'] ? 'checked' : '' ?>> Active (can sign in)</label><?php endif; ?>
<p><button class="btn">Save user</button> <a class="btn sec" href="users.php">Cancel</a></p></form></div>
<?php else: ?>
<p><a class="btn" href="users.php?edit=0">+ Add user</a></p>
<table><tr><th>Name</th><th>Email</th><th>Role here</th><th>Websites</th><th>Status</th><th>2-step</th><th>Last login</th><th>Actions</th></tr>
<?php foreach ($users as $u): $ex = exclusive((int)$u['id'], $sid); ?><tr><td><?= e($u['name']) ?></td><td><?= e($u['email']) ?></td><td><span class="tag violet"><?= e($u['site_role']) ?></span></td>
<td><?= $u['is_super'] ? '<span class="tag blue">super admin</span>' : (int)$u['nsites'] ?></td>
<td><span class="tag <?= $u['active'] ? 'green' : 'red' ?>"><?= $u['active'] ? 'active' : 'disabled' ?></span></td><td><?= $u['twofa_enabled'] ? '<span class="tag green">on</span>' : '<span class="tag">off</span>' ?></td><td><?= e($u['last_login'] ? date('M j, Y g:i a', strtotime($u['last_login'])) : 'never') ?></td>
<td class="actions"><a class="btn sm" href="users.php?edit=<?= $u['id'] ?>">Edit</a>
<?php if ($u['twofa_enabled'] && $ex): ?><form method="post" onsubmit="return confirm('Turn off two-step verification for this user?')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $u['id'] ?>"><button class="btn sm sec" name="action" value="reset2fa">Reset 2-step</button></form><?php endif; ?>
<?php if ($u['id'] != $me['id']): ?><form method="post" onsubmit="return confirm('<?= $ex ? 'Delete this user?' : 'Remove this user from this website?' ?>')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $u['id'] ?>"><button class="btn sm danger" name="action" value="remove"><?= $ex ? 'Delete' : 'Remove' ?></button></form><?php endif; ?></td></tr>
<?php endforeach; ?></table>
<?php endif; admin_footer();
