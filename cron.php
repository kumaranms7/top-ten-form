<?php
/**
 * Run background jobs and housekeeping.
 *   Linux cron (every minute):  * * * * *  php "/path/to/TopTen Form/cron.php"
 *   Windows Task Scheduler:     php.exe "C:\path\to\TopTen Form\cron.php"
 *   Or by URL:                  https://yourapp/cron.php?key=YOUR_KEY   (the key is shown in Settings)
 *
 * Monthly lead report (send at 12:00 AM on the 1st):
 *   Linux cron:                 0 0 1 * *  php "/path/to/TopTen Form/cron.php" report
 *   Windows Task Scheduler:     monthly, day 1, 12:00 AM:  php.exe "C:\path	o\TopTen Form\cron.php" report
 *   Or by URL:                  https://yourapp/cron.php?key=YOUR_KEY&task=report
 *
 * Optional: without it the app runs these tasks automatically (at most once a minute) while it is being used.
 */
require __DIR__ . '/app/bootstrap.php';
if (PHP_SAPI !== 'cli' && !hash_equals((string)setting('cron_key'), (string)($_GET['key'] ?? ''))) { http_response_code(403); exit('Forbidden'); }
if ((PHP_SAPI === 'cli' ? ($argv[1] ?? '') : ($_GET['task'] ?? '')) === 'report') {   // monthly lead report only
    require_once __DIR__ . '/app/reports.php';
    $n = send_monthly_reports(); process_jobs(50);
    exit($n ? "Report queued and sent " . date('c') . "
" : "Nothing to send (already sent, disabled, or no recipient) " . date('c') . "
");
}
run_cron();
q("UPDATE settings SET v=? WHERE k='last_cron'", [(string)time()]);
echo "OK " . date('c') . "\n";
