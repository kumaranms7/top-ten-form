<?php
/** Monthly lead report page. Opened from the link in the report email (unguessable token, no login needed). */
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/report_page.php';

$sid = (int)($_GET['s'] ?? 0); $t = (string)($_GET['t'] ?? '');
$ym = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['m'] ?? '')) ? $_GET['m'] : date('Y-m', strtotime('first day of last month'));   // default: last month
$ym = min($ym, date('Y-m'));
$site = site_by_id($sid);
if (!$site || $site['status'] !== 'active' || !hash_equals(report_token($sid), $t)) { http_response_code(404); exit('Report not found.'); }
use_site($sid);
header('Content-Type: text/html; charset=utf-8'); header('X-Robots-Tag: noindex, nofollow'); header('Cache-Control: private, no-store');
echo render_report_page(report_data($site, $ym));
