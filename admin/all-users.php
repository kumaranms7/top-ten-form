<?php
require __DIR__ . '/_layout.php';
$me = require_super();
$sites = q('SELECT id,name FROM sites ORDER BY name')->fetchAll();
$siteIds = array_map('intval', array_column($sites, 'id'));

/** Replace a user's website memberships with the submitted list (site_id => role). */
function save_memberships(int $uid, array $post, array $siteIds): void {
    q('DELETE FROM user_sites WHERE user_id=?', [$uid]);
    foreach ((array)($post['sites'] ?? []) as $sid => $on) {
        $sid = (int)$sid; $role = $post['site_role'][$sid] ?? 'editor';
        if (in_array($sid, $siteIds, true) && array_key_exists($role, ROLES)) q('INSERT INTO user_sites (user_id,site_id,role) VALUES (?,?,?)', [$uid, $sid, $role]);
    }
}
$superCount = fn() => (int)q('SELECT COUNT(*) FROM users WHERE is_super=1 AND active=1')->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? ''; $uid = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? ''); $email = trim($_POST['email'] ?? ''); $pass = $_POST['password'] ?? '';
    $isSuper = !empty($_POST['is_super']) ? 1 : 0; $active = empty($_POST['active']) ? 0 : 1;
    $target = $uid ? q('SELECT * FROM users WHERE id=?', [$uid])->fetch() : null;
    if ($uid && !$target) { flash('User not found.', 'err'); header('Location: all-users.php'); exit; }

    if ($act === 'save') {
        $dup = q('SELECT id FROM users WHERE email=? AND id<>?', [$email, $uid])->fetch();
        if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL)) flash('Enter a name and a valid email.', 'err');
        elseif ($dup) flash('That email is already used by another account.', 'err');
        elseif (!$uid && strlen($pass) < 8) flash('Password must be at least 8 characters.', 'err');
        elseif ($uid && $pass !== '' && strlen($pass) < 8) flash('Password must be at least 8 characters.', 'err');
        elseif ($uid === (int)$me['id'] && (!$isSuper || !$active)) flash('You cannot remove your own super admin access or disable your own account.', 'err');
        elseif ($uid && $target['is_super'] && (!$isSuper || !$active) && $superCount() < 2) flash('There must always be at least one active super admin.', 'err');
        else {
            if (!$uid) {
                q('INSERT INTO users (name,email,password,role,active,is_super) VALUES (?,?,?,?,?,?)', [$name, $email, password_hash($pass, PASSWORD_DEFAULT), 'admin', $active, $isSuper]);
                $uid = (int)db()->lastInsertId(); log_activity("Created user $email");
            } else {
                q('UPDATE users SET name=?, email=?, active=?, is_super=? WHERE id=?', [$name, $email, $active, $isSuper, $uid]);
                if ($pass !== '') { q('UPDATE users SET password=? WHERE id=?', [password_hash($pass, PASSWORD_DEFAULT), $uid]); twofa_forget_devices($uid); }
                log_activity("Updated user #$uid");
            }
            save_memberships($uid, $_POST, $siteIds);
            flash('User saved.'); header('Location: all-users.php'); exit;
        }
        header('Location: all-users.php?edit=' . $uid); exit;
    } elseif ($act === 'reset2fa' && $target) {
        q('UPDATE users SET twofa_enabled=0 WHERE id=?', [$uid]); twofa_forget_devices($uid); q('DELETE FROM twofa_codes WHERE user_id=?', [$uid]); log_activity("Reset two-step verification for user #$uid"); flash('Two-step verification turned off for that user.');
    } elseif ($act === 'delete' && $target) {
        if ($uid === (int)$me['id']) flash('You cannot delete yourself.', 'err');
        elseif ($target['is_super'] && $superCount() < 2) flash('There must always be at least one active super admin.', 'err');
        else {
            q('DELETE FROM user_sites WHERE user_id=?', [$uid]); q('UPDATE entries SET assigned_to=NULL WHERE assigned_to=?', [$uid]);
            foreach (['trusted_devices', 'twofa_codes', 'user_prefs', 'password_resets'] as $t) q("DELETE FROM $t WHERE user_id=?", [$uid]);
            q('DELETE FROM users WHERE id=?', [$uid]); log_activity("Deleted user #$uid"); flash('User deleted.');
        }
    }
    header('Location: all-users.php'); exit;
}

$edit = null;
if (isset($_GET['edit'])) {
    $edit = (int)$_GET['edit'] ? (q('SELECT * FROM users WHERE id=?', [(int)$_GET['edit']])->fetch() ?: null) : ['id' => 0, 'name' => '', 'email' => '', 'active' => 1, 'is_super' => 0];
    if (!$edit) { header('Location: all-users.php'); exit; }
    $mem = $edit['id'] ? array_column(q('SELECT site_id, role FROM user_sites WHERE user_id=?', [$edit['id']])->fetchAll(), 'role', 'site_id') : [];
}
$fsite = (int)($_GET['site'] ?? 0);
$users = q('SELECT u.* FROM users u' . ($fsite ? ' JOIN user_sites f ON f.user_id=u.id AND f.site_id=' . $fsite : '') . ' ORDER BY u.id DESC')->fetchAll();
$memAll = [];
foreach (q('SELECT us.user_id, us.role, s.name FROM user_sites us JOIN sites s ON s.id=us.site_id ORDER BY s.name')->fetchAll() as $r) $memAll[$r['user_id']][] = $r;
admin_header('Users', 'allusers', '', 'Everyone who can sign in, across all websites');
if ($edit): ?>
<div class="card" style="max-width:720px"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
<div class="row"><label>Name<input name="name" value="<?= e($edit['name']) ?>" required></label><label>Email<input type="email" name="email" value="<?= e($edit['email']) ?>" required></label></div>
<label>Password <small><?= $edit['id'] ? '(leave blank to keep)' : '(min 8 chars)' ?></small><input type="password" name="password" autocomplete="new-password" <?= $edit['id'] ? '' : 'required' ?>></label>
<label class="inline"><input type="checkbox" name="active" value="1" <?= $edit['active'] ? 'checked' : '' ?>> Active (can sign in)</label>
<label class="inline"><input type="checkbox" name="is_super" value="1" <?= !empty($edit['is_super']) ? 'checked' : '' ?>> Super admin <small>(manages every website and the platform settings)</small></label>
<h3>Websites &amp; roles</h3><p class="muted" style="margin-top:-6px">Tick every website this person can work in. A person can have different roles in different websites.</p>
<table><tr><th>Access</th><th>Website</th><th>Role</th></tr>
<?php foreach ($sites as $s): $on = isset($mem[$s['id']]); $r = $mem[$s['id']] ?? 'editor'; ?><tr><td><input type="checkbox" name="sites[<?= $s['id'] ?>]" value="1" <?= $on ? 'checked' : '' ?>></td><td><?= e($s['name']) ?></td>
<td><select name="site_role[<?= $s['id'] ?>]"><?php foreach (['admin' => 'Admin', 'editor' => 'Editor', 'viewer' => 'Viewer'] as $k => $l) echo '<option value="' . $k . '"' . ($r === $k ? ' selected' : '') . '>' . $l . '</option>'; ?></select></td></tr><?php endforeach; ?></table>
<p><button class="btn">Save user</button> <a class="btn sec" href="all-users.php">Cancel</a></p></form></div>
<?php else: ?>
<form class="filters" method="get" style="justify-content:space-between;align-items:center"><a class="btn" href="all-users.php?edit=0">+ Add user</a>
<label style="max-width:300px;margin:0">Website<select name="site" onchange="this.form.submit()"><option value="">All websites</option><?php foreach ($sites as $s) echo '<option value="' . $s['id'] . '"' . ($fsite == $s['id'] ? ' selected' : '') . '>' . e($s['name']) . '</option>'; ?></select></label></form>
<table><tr><th>Name</th><th>Email</th><th>Websites</th><th>Status</th><th>2-step</th><th>Last login</th><th>Actions</th></tr>
<?php foreach ($users as $u): ?><tr><td><?= e($u['name']) ?> <?= $u['is_super'] ? '<span class="tag blue">super admin</span>' : '' ?></td><td><?= e($u['email']) ?></td>
<td><?php foreach ($memAll[$u['id']] ?? [] as $m) echo '<span class="tag violet" title="' . e($m['role']) . '">' . e($m['name']) . ' &middot; ' . e($m['role']) . '</span> '; if (empty($memAll[$u['id']])) echo '<span class="muted">-</span>'; ?></td>
<td><span class="tag <?= $u['active'] ? 'green' : 'red' ?>"><?= $u['active'] ? 'active' : 'disabled' ?></span></td><td><?= $u['twofa_enabled'] ? '<span class="tag green">on</span>' : '<span class="tag">off</span>' ?></td><td><?= e($u['last_login'] ? fmt_datetime(strtotime($u['last_login'])) : 'never') ?></td>
<td class="actions"><a class="btn sm" href="all-users.php?edit=<?= $u['id'] ?>">Edit</a>
<?php if ($u['twofa_enabled']): ?><form method="post" onsubmit="return confirm('Turn off two-step verification for this user?')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $u['id'] ?>"><button class="btn sm sec" name="action" value="reset2fa">Reset 2-step</button></form><?php endif; ?>
<?php if ($u['id'] != $me['id']): ?><form method="post" onsubmit="return confirm('Delete this user from the whole platform?')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $u['id'] ?>"><button class="btn sm danger" name="action" value="delete">Delete</button></form><?php endif; ?></td></tr>
<?php endforeach; ?></table>
<?php endif; admin_footer();
