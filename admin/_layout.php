<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/render.php';

/** Inline Lucide-style icons (no external dependency). */
function icon(string $name): string {
    static $p = [
        'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'forms' => '<path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/>',
        'entries' => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
        'logs' => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'settings' => '<path d="M21 4h-7M10 4H3M21 12h-9M8 12H3M21 20h-5M12 20H3M14 2v4M8 10v4M16 18v4"/>',
        'profile' => '<circle cx="12" cy="8" r="4"/><path d="M4 21v-1a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v1"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'edit' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
        'mail' => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>',
        'code' => '<path d="m16 18 6-6-6-6M8 6l-6 6 6 6"/>',
        'copy' => '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
        'power' => '<path d="M18.36 6.64a9 9 0 1 1-12.73 0M12 2v10"/>',
        'trash' => '<path d="M3 6h18M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6M10 11v6M14 11v6M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>',
        'chev' => '<path d="m6 9 6 6 6-6"/>',
        'unread' => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>',
        'week' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'eye' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'monitor' => '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>',
        'tablet' => '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M12 18h.01"/>',
        'phone' => '<rect x="7" y="2" width="10" height="20" rx="2"/><path d="M12 18h.01"/>',
        'upload' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>',
        'undo' => '<path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 5.5 5.5v0a5.5 5.5 0 0 1-5.5 5.5H11"/>',
        'redo' => '<path d="m15 14 5-5-5-5"/><path d="M20 9H9.5A5.5 5.5 0 0 0 4 14.5v0A5.5 5.5 0 0 0 9.5 20H13"/>',
        'tag' => '<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"/><circle cx="7.5" cy="7.5" r="1.2"/>',
        'sites' => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/>',
        'alert' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3zM12 9v4M12 17h.01"/>',
    ];
    return '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$name] ?? '') . '</svg>';
}

/** Breadcrumb + pill tabs shared by all per-form pages. */
function form_tabs(int $id, string $active): string {
    $f = q('SELECT name FROM forms WHERE id=?', [$id])->fetch();
    $t = ['fields' => ["builder.php?id=$id", 'Fields'], 'notifications' => ["notifications.php?form=$id", 'Email notifications'], 'webhooks' => ["webhooks.php?form=$id", 'Webhooks'], 'preview' => ["preview.php?id=$id", 'Preview'], 'code' => ["export.php?id=$id", 'Get code'], 'entries' => ["entries.php?form=$id", 'Entries']];
    $h = '<div class="crumbs"><a href="forms.php">Forms</a> / <span>' . e($f['name'] ?? '') . '</span></div><div class="tabs">';
    foreach ($t as $k => [$href, $label]) $h .= '<a href="' . $href . '" class="' . ($k === $active ? 'active' : '') . '">' . $label . '</a>';
    return $h . '</div>';
}

function admin_header(string $title, string $active = '', string $extraHead = '', string $sub = ''): void {
    ob_start(); // page is sent in one piece, then background tasks run (see admin_footer)
    $u = current_user(); $site = current_site(); $platform = in_platform();
    if ($platform) {   // super admin outside any website
        $nav = ['platform' => ['platform.php', 'Platform', 'viewer', 'dashboard', 'indigo'], 'sites' => ['sites.php', 'Websites', 'viewer', 'sites', 'violet'], 'allforms' => ['all-forms.php', 'Forms', 'viewer', 'forms', 'sky'],
            'allusers' => ['all-users.php', 'Users', 'viewer', 'users', 'pink'], 'logs' => ['logs.php', 'Logs', 'viewer', 'logs', 'orange'], 'settings' => ['settings.php', 'Platform settings', 'viewer', 'settings', 'teal']];
    } else {
        $unread = (int)q('SELECT COUNT(*) FROM entries WHERE is_read=0 AND is_spam=0 AND form_id IN ' . site_forms_sql())->fetchColumn();
        $nav = [
            'dashboard' => ['index.php', 'Dashboard', 'viewer', 'dashboard', 'indigo'], 'forms' => ['forms.php', 'Forms', 'viewer', 'forms', 'violet'],
            'entries' => ['entries.php', 'Entries' . ($unread ? " <span class=\"badge\">$unread</span>" : ''), 'viewer', 'entries', 'sky'],
            'logs' => ['logs.php', 'Logs', 'admin', 'logs', 'orange'], 'users' => ['users.php', 'Users', 'admin', 'users', 'pink'], 'settings' => ['site-settings.php', 'Site settings', 'admin', 'settings', 'teal'],
        ];
        if ($u['is_super']) $nav = ['platform' => ['platform.php', 'Platform', 'viewer', 'sites', 'slate']] + $nav;
    }
    $heroColor = ($nav[$active][4] ?? 'indigo'); $heroIcon = ($nav[$active][3] ?? 'dashboard');
    $siteLogo = ($site && !empty($site['logo']) && is_file(media_dir() . '/' . $site['logo'])) ? '../public/media/' . $site['logo'] : '';
    $mySites = ($u['is_super'] || true) ? user_site_list((int)$u['id'], (bool)$u['is_super']) : [];
    $tok = csrf_token();
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title) ?> - <?= e(setting('site_name', 'TopTen Form')) ?></title>
<link rel="preload" href="assets/fonts/inter-latin-var.woff2" as="font" type="font/woff2" crossorigin>
<link rel="icon" href="../favicon.ico?v=1" sizes="any"><link rel="shortcut icon" href="../favicon.ico?v=1"><link rel="stylesheet" href="assets/admin.css?v=23"><?= $extraHead ?></head><body>
<header class="topbar"><a class="brand" href="<?= $platform ? 'platform.php' : 'index.php' ?>" title="<?= e(setting('site_name', 'TopTen Form')) ?>"><img src="<?= e(admin_logo_path()) ?>" height="42" alt="<?= e(setting('site_name', 'TopTen Form')) ?>"></a>
<?php if ($siteLogo): ?><span class="brand-sep" aria-hidden="true"></span><a class="brand site-brand" href="index.php" title="<?= e($site['name']) ?>"><img class="site-logo" src="<?= e($siteLogo) ?>" alt="<?= e($site['name']) ?>"></a><?php endif; ?>
<nav class="nav"><?php foreach ($nav as $k => [$href, $label, $min, $ic, $col]) if (can($min)): ?>
<a href="<?= $href ?>" class="c-<?= $col ?> <?= $active === $k ? 'active' : '' ?>"><span class="ni"><?= icon($ic) ?></span><span><?= $label ?></span></a><?php endif; ?></nav>
<?php if (count($mySites) > 1 || $u['is_super']): ?><details class="sitemenu"><summary><?= icon('sites') ?><span class="sm-name"><?= $platform ? 'Platform view' : e($site['name'] ?? '') ?></span><?= icon('chev') ?></summary>
<div class="menu sm-list"><?php if ($u['is_super']): ?><a href="switch-site.php?id=0&amp;t=<?= e($tok) ?>"><?= icon('dashboard') ?>Platform view</a><?php endif; ?>
<?php foreach (array_slice($mySites, 0, 25) as $ms): ?><a href="switch-site.php?id=<?= (int)$ms['id'] ?>&amp;t=<?= e($tok) ?>" class="<?= !$platform && (int)$ms['id'] === (int)$site['id'] ? 'cur' : '' ?>"><?= icon('sites') ?><?= e($ms['name']) ?><?= $ms['status'] === 'suspended' ? ' <small>(suspended)</small>' : '' ?></a><?php endforeach; ?>
<?php if ($u['is_super'] && count($mySites) > 25): ?><a href="sites.php">All websites...</a><?php endif; ?></div></details><?php endif; ?>
<details class="usermenu"><summary><span class="avatar"><?= e(strtoupper(mb_substr($u['name'], 0, 1))) ?></span><span class="uname"><?= e($u['name']) ?><small><?= $u['is_super'] ? 'Super admin' : e(ucfirst($u['role'])) ?></small></span><?= icon('chev') ?></summary>
<div class="menu"><a href="profile.php"><?= icon('profile') ?>My profile</a><a href="logout.php"><?= icon('logout') ?>Log out</a></div></details></header>
<?php if ($u['is_super'] && !$platform && $site): ?><div class="ctx-banner"><?= icon('sites') ?> You are managing <b><?= e($site['name']) ?></b> as super admin. <a href="switch-site.php?id=0&amp;t=<?= e($tok) ?>">Back to the platform</a></div><?php endif; ?>
<main class="content"><div class="hero c-<?= $heroColor ?>"><div><h1><?= e($title) ?></h1><?php if ($sub): ?><div class="sub"><?= e($sub) ?></div><?php endif; ?></div><div class="hero-ic"><?= icon($heroIcon) ?></div></div>
<?php if ($f = flash()): ?><div class="alert <?= $f[1] ?>"><?= e($f[0]) ?></div><?php endif;
}
function admin_footer(string $extraScript = ''): void {
    echo '</main>' . $extraScript . '</body></html>';
    finish_request();
    try { maybe_run_cron(); } catch (Throwable $e) { error_log('TopTen Form cron: ' . $e->getMessage()); }
}
