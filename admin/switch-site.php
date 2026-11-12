<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_user();
$back = $_SERVER['HTTP_REFERER'] ?? '';
if (!hash_equals(csrf_token(), (string)($_GET['t'] ?? ''))) { http_response_code(419); exit('Invalid or expired link. Go back and try again.'); }
$id = (int)($_GET['id'] ?? 0);

if ($id === 0) {                                   // back to the platform view (super admin only)
    if (!$u['is_super'] || !switch_site(null)) { http_response_code(403); exit('Only a super admin can open the platform view.'); }
    header('Location: platform.php'); exit;
}
$site = site_by_id($id);
if (!$site || !switch_site($id)) { http_response_code(403); exit('You do not have access to that website.'); }
if ($u['is_super']) q('INSERT INTO activity_log (user_id,action,ip,site_id) VALUES (?,?,?,?)', [$u['id'], 'Super admin entered the site "' . $site['name'] . '"', $_SERVER['REMOTE_ADDR'] ?? '', $id]);
$to = in_array($_GET['to'] ?? '', ['site-settings.php', 'forms.php', 'entries.php', 'users.php'], true) ? $_GET['to'] : 'index.php';
header('Location: ' . $to); exit;
