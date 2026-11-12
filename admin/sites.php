<?php
require __DIR__ . '/_layout.php';
$me = require_super();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? ''; $sid = (int)($_POST['id'] ?? 0);
    if ($act === 'create') {
        $name = trim($_POST['name'] ?? ''); $aEmail = trim($_POST['admin_email'] ?? ''); $aName = trim($_POST['admin_name'] ?? ''); $aPass = $_POST['admin_password'] ?? '';
        $existing = $aEmail !== '' ? q('SELECT id FROM users WHERE email=?', [$aEmail])->fetch() : null;
        if ($name === '') flash('Enter the website name.', 'err');
        elseif ($aEmail !== '' && !filter_var($aEmail, FILTER_VALIDATE_EMAIL)) flash('The administrator email is not valid.', 'err');
        elseif ($aEmail !== '' && !$existing && ($aName === '' || strlen($aPass) < 8)) flash('For a new administrator enter a name and a password of at least 8 characters.', 'err');
        else {
            q('INSERT INTO sites (name,slug,status,company,website_url,contact_email) VALUES (?,?,?,?,?,?)', [mb_substr($name, 0, 150), unique_site_slug($name), 'active', mb_substr(trim($_POST['company'] ?? ''), 0, 150), '', '']);
            $nid = (int)db()->lastInsertId();
            $err = save_site_details($nid, $_POST + ['name' => $name]);
            if ($aEmail !== '') {
                $uid = $existing ? (int)$existing['id'] : 0;
                if (!$uid) { q('INSERT INTO users (name,email,password,role,active) VALUES (?,?,?,?,1)', [$aName, $aEmail, password_hash($aPass, PASSWORD_DEFAULT), 'admin']); $uid = (int)db()->lastInsertId(); }
                q('INSERT IGNORE INTO user_sites (user_id,site_id,role) VALUES (?,?,?)', [$uid, $nid, 'admin']);
            }
            log_activity("Created website #$nid ($name)");
            flash($err ? 'Website created, but: ' . $err : 'Website created. Add its logo and details under Site settings.'); header('Location: switch-site.php?id=' . $nid . '&t=' . urlencode(csrf_token()) . '&to=site-settings.php'); exit;
        }
    } elseif ($act === 'save' && ($site = site_by_id($sid))) {
        $slug = slugify(trim($_POST['slug'] ?? '') ?: $site['name']);
        if (q('SELECT 1 FROM sites WHERE slug=? AND id<>?', [$slug, $sid])->fetchColumn()) flash('That short name (slug) is already used by another website.', 'err');
        else {
            q('UPDATE sites SET slug=?, status=?, notes=? WHERE id=?', [$slug, ($_POST['status'] ?? '') === 'suspended' ? 'suspended' : 'active', trim($_POST['notes'] ?? ''), $sid]);
            $err = save_site_details($sid, $_POST);
            log_activity("Updated website #$sid"); flash($err ?: 'Website saved.', $err ? 'err' : 'ok');
        }
    } elseif ($act === 'toggle' && ($site = site_by_id($sid))) {
        $new = $site['status'] === 'active' ? 'suspended' : 'active';
        q('UPDATE sites SET status=? WHERE id=?', [$new, $sid]); log_activity(($new === 'suspended' ? 'Suspended' : 'Reactivated') . " website #$sid"); flash('Website ' . ($new === 'suspended' ? 'suspended: its forms stop accepting entries and its users cannot sign in.' : 'is active again.'));
    } elseif ($act === 'delete' && ($site = site_by_id($sid))) {
        if (trim($_POST['confirm'] ?? '') !== $site['name']) flash('Type the exact website name to confirm the deletion.', 'err');
        elseif ((int)q('SELECT COUNT(*) FROM sites')->fetchColumn() < 2) flash('You cannot delete the last website.', 'err');
        else { delete_site($sid); if ((int)($_SESSION['site_id'] ?? 0) === $sid) unset($_SESSION['site_id']); log_activity('Deleted website ' . $site['name']); flash('Website deleted with all its forms and entries.'); }
    }
    header('Location: sites.php' . (isset($_GET['edit']) ? '?edit=' . (int)$_GET['edit'] : '')); exit;
}

$edit = null; $new = isset($_GET['new']);
if (isset($_GET['edit'])) { $edit = site_by_id((int)$_GET['edit']); if (!$edit) { header('Location: sites.php'); exit; } }
$rows = q("SELECT s.*, (SELECT COUNT(*) FROM forms f WHERE f.site_id=s.id) nforms,
    (SELECT COUNT(*) FROM entries e JOIN forms f ON f.id=e.form_id WHERE f.site_id=s.id AND e.is_spam=0) nentries,
    (SELECT COUNT(*) FROM user_sites us WHERE us.site_id=s.id) nusers,
    (SELECT MAX(e.created_at) FROM entries e JOIN forms f ON f.id=e.form_id WHERE f.site_id=s.id) last_entry FROM sites s ORDER BY s.id DESC")->fetchAll();
admin_header('Websites', 'sites', '', 'Every client website on this platform');
$tok = urlencode(csrf_token());
if ($new): ?>
<div class="card" style="max-width:720px"><h2>Add a website</h2><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="create">
<div class="row"><label>Website name<input name="name" required maxlength="150" placeholder="Acme Dental"></label><label>Company <small>(optional)</small><input name="company" maxlength="150"></label></div>
<div class="row"><label>Website address<input name="website_url" placeholder="https://www.example.com"></label><label>Contact email<input type="email" name="contact_email"></label></div>
<label>Allowed domains <small>(one per line; forms only accept submissions from these. Leave empty to allow any website)</small><textarea name="allowed_domains" rows="3" placeholder="example.com"></textarea></label>
<h3>First administrator <small>(optional)</small></h3>
<p class="muted" style="margin-top:-6px">Use an email that already has an account to link that person to this website, or enter a new name and password to create one.</p>
<div class="row"><label>Name<input name="admin_name"></label><label>Email<input type="email" name="admin_email"></label><label>Password <small>(new accounts)</small><input type="password" name="admin_password" autocomplete="new-password"></label></div>
<p><button class="btn">Create website</button> <a class="btn sec" href="sites.php">Cancel</a></p></form></div>
<?php elseif ($edit): $ud = q('SELECT u.id,u.name,u.email,us.role FROM users u JOIN user_sites us ON us.user_id=u.id WHERE us.site_id=? ORDER BY u.id DESC', [$edit['id']])->fetchAll(); ?>
<div class="grid2"><div class="card"><h2>Edit website</h2><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= $edit['id'] ?>">
<div class="row"><label>Website name<input name="name" value="<?= e($edit['name']) ?>" required maxlength="150"></label><label>Short name (slug)<input name="slug" value="<?= e($edit['slug']) ?>"></label></div>
<div class="row"><label>Company<input name="company" value="<?= e($edit['company']) ?>"></label><label>Status<select name="status"><option value="active">Active</option><option value="suspended" <?= $edit['status'] === 'suspended' ? 'selected' : '' ?>>Suspended</option></select></label></div>
<div class="row"><label>Website address<input name="website_url" value="<?= e($edit['website_url']) ?>"></label><label>Contact email<input type="email" name="contact_email" value="<?= e($edit['contact_email']) ?>"></label></div>
<div class="row"><label>Phone<input name="phone" value="<?= e($edit['phone']) ?>"></label><label>Address<input name="address" value="<?= e($edit['address']) ?>"></label></div>
<label>Allowed domains <small>(one per line)</small><textarea name="allowed_domains" rows="3"><?= e($edit['allowed_domains']) ?></textarea></label>
<label>Internal notes <small>(only you can see this)</small><textarea name="notes" rows="3"><?= e($edit['notes']) ?></textarea></label>
<p><button class="btn">Save</button> <a class="btn sec" href="sites.php">Back</a> <a class="btn sec" href="switch-site.php?id=<?= $edit['id'] ?>&t=<?= $tok ?>&to=site-settings.php">Logo &amp; email settings</a></p></form></div>
<div><div class="card"><h2>People</h2><table><tr><th>Name</th><th>Role</th></tr><?php foreach ($ud as $u) echo '<tr><td>' . e($u['name']) . '<br><small class="muted">' . e($u['email']) . '</small></td><td><span class="tag violet">' . e($u['role']) . '</span></td></tr>';
if (!$ud) echo '<tr><td colspan="2" class="muted">No one yet. Add people under Users.</td></tr>'; ?></table><p><a class="btn sm sec" href="all-users.php">Manage users</a></p></div>
<div class="card"><h2>Delete website</h2><p class="muted">Removes the website with <b>all</b> its forms, entries, settings and users who belong only to it. This cannot be undone.</p>
<form method="post" onsubmit="return confirm('Delete this website and everything in it?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $edit['id'] ?>"><label>Type <b><?= e($edit['name']) ?></b> to confirm<input name="confirm" autocomplete="off"></label><button class="btn danger">Delete website</button></form></div></div></div>
<?php else: ?>
<p><a class="btn" href="sites.php?new=1">+ Add website</a></p>
<table><tr><th>Website</th><th>Status</th><th>Forms</th><th>Entries</th><th>Users</th><th>Last entry</th><th>Actions</th></tr>
<?php foreach ($rows as $s): ?><tr><td><?php if ($s['logo'] && is_file(media_dir() . '/' . $s['logo'])): ?><img src="../public/media/<?= e($s['logo']) ?>" alt="" style="height:22px;vertical-align:middle;margin-right:8px;max-width:80px;object-fit:contain"><?php endif; ?><b><?= e($s['name']) ?></b><?= $s['website_url'] ? '<br><small class="muted">' . e($s['website_url']) . '</small>' : '' ?></td>
<td><span class="tag <?= $s['status'] === 'active' ? 'green' : 'red' ?>"><?= e($s['status']) ?></span></td><td><?= (int)$s['nforms'] ?></td><td><?= (int)$s['nentries'] ?></td><td><?= (int)$s['nusers'] ?></td>
<td><?= e($s['last_entry'] ? date('M j, Y', strtotime($s['last_entry'])) : '-') ?></td>
<td class="actions"><a class="btn sm" href="switch-site.php?id=<?= $s['id'] ?>&t=<?= $tok ?>">Enter</a> <a class="btn sm sec" href="sites.php?edit=<?= $s['id'] ?>">Edit</a>
<form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $s['id'] ?>"><button class="btn sm sec" name="action" value="toggle"><?= $s['status'] === 'active' ? 'Suspend' : 'Reactivate' ?></button></form></td></tr>
<?php endforeach; ?></table>
<?php endif; admin_footer();
