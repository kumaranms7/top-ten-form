<?php
/** Super admin: preview a monthly lead report in the browser exactly as it is emailed. */
require __DIR__ . '/_layout.php';
$me = require_super();
require_once __DIR__ . '/../app/reports.php';
$sites = q('SELECT id,name FROM sites ORDER BY name')->fetchAll();
$sid = (int)($_GET['site'] ?? ($sites[0]['id'] ?? 0));
$site = q('SELECT * FROM sites WHERE id=?', [$sid])->fetch();
$ym = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m', strtotime('first day of last month'));
if (!$site) { header('Location: settings.php'); exit; }
use_site($sid);
require_once __DIR__ . '/../app/report_page.php';
if (isset($_GET['raw'])) { echo ($_GET['view'] ?? '') === 'email' ? build_combined_report_email($ym)[0] : render_report_page(report_data($site, $ym)); exit; }
admin_header('Report preview', 'settings', '', 'The full report page, and the email that links to it'); ?>
<form method="get" class="filters">
  <label>Website<select name="site" onchange="this.form.submit()"><?php foreach ($sites as $s) echo '<option value="' . $s['id'] . '"' . ($s['id'] == $sid ? ' selected' : '') . '>' . e($s['name']) . '</option>'; ?></select></label>
  <label>Month<input type="month" name="month" value="<?= e($ym) ?>" onchange="this.form.submit()"></label>
  <label>Show<select name="view" onchange="this.form.submit()"><option value="page">Full report page</option><option value="email"<?= ($_GET['view'] ?? '') === 'email' ? ' selected' : '' ?>>Email</option></select></label>
  <a class="btn sec sm" href="settings.php">&larr; Back to settings</a>
</form>
<iframe src="report-preview.php?raw=1&site=<?= $sid ?>&month=<?= e($ym) ?>&view=<?= e($_GET['view'] ?? 'page') ?>" style="width:100%;height:80vh;border:1px solid var(--border);border-radius:12px;background:#f1f3f9"></iframe>
<?php admin_footer(); ?>
