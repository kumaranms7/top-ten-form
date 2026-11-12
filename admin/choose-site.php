<?php
require __DIR__ . '/_layout.php';
$u = require_user();
if ($u['is_super']) { header('Location: platform.php'); exit; }
$sites = user_site_list((int)$u['id'], false);
if (count($sites) === 1) { switch_site((int)$sites[0]['id']); header('Location: index.php'); exit; }
admin_header('Choose a website', '', '', 'You have access to more than one website. Pick the one you want to work on.'); ?>
<?php if (!$sites): ?><div class="card empty-state"><h2>No website yet</h2><p>Your account is not linked to an active website. Please contact your administrator.</p></div>
<?php else: ?><div class="site-cards"><?php foreach ($sites as $s): $logo = (!empty($s['logo']) && is_file(media_dir() . '/' . $s['logo'])) ? '../public/media/' . $s['logo'] : ''; ?>
<a class="site-card" href="switch-site.php?id=<?= (int)$s['id'] ?>&amp;t=<?= e(csrf_token()) ?>">
<span class="sc-logo"><?php if ($logo): ?><img src="<?= e($logo) ?>" alt=""><?php else: ?><span><?= e(strtoupper(mb_substr($s['name'], 0, 1))) ?></span><?php endif; ?></span>
<b><?= e($s['name']) ?></b><small><?= e($s['website_url'] ?: ucfirst($s['role'])) ?></small><span class="tag"><?= e(ucfirst($s['role'])) ?></span></a><?php endforeach; ?></div><?php endif; ?>
<?php admin_footer();
