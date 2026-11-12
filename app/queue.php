<?php
/** Background jobs (emails, webhooks) with automatic retries, so a slow mail server never delays a visitor. */
const JOB_MAX_ATTEMPTS = 5;
const JOB_BACKOFF = [60, 300, 900, 3600, 21600]; // seconds before attempt 2,3,4,5

function queue_job(string $type, array $payload, ?int $formId = null, ?int $entryId = null, int $delay = 0): int {
    q('INSERT INTO jobs (type,payload,next_attempt,form_id,entry_id) VALUES (?,?,DATE_ADD(NOW(), INTERVAL ? SECOND),?,?)',
        [$type, json_encode($payload, JSON_UNESCAPED_UNICODE), $delay, $formId, $entryId]);
    return (int)db()->lastInsertId();
}

/** Tell the browser the response is complete, then keep working (cron/jobs) in the same process. */
function finish_request(): void {
    ignore_user_abort(true); @set_time_limit(120);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    if (function_exists('fastcgi_finish_request')) { while (ob_get_level() > 0) @ob_end_flush(); fastcgi_finish_request(); return; }
    if (ob_get_level() > 0 && !headers_sent()) { header('Content-Length: ' . ob_get_length()); header('Connection: close'); }
    while (ob_get_level() > 0) @ob_end_flush();
    flush();
}

function process_jobs(int $limit = 20): int {
    $done = 0;
    $rows = q("SELECT id FROM jobs WHERE status='pending' AND next_attempt <= NOW() ORDER BY id LIMIT " . (int)$limit)->fetchAll();
    foreach ($rows as $r) {
        // claim atomically so two workers never run the same job
        $c = q("UPDATE jobs SET status='processing', attempts=attempts+1, next_attempt=NOW() WHERE id=? AND status='pending'", [$r['id']]);
        if ($c->rowCount() !== 1) continue;
        $job = q('SELECT * FROM jobs WHERE id=?', [$r['id']])->fetch();
        $p = json_decode($job['payload'], true) ?: [];
        try {
            [$ok, $err] = run_job($job['type'], $p, $job);
        } catch (Throwable $ex) { [$ok, $err] = [false, $ex->getMessage()]; }
        if ($ok) { q("UPDATE jobs SET status='done', done_at=NOW(), last_error=NULL WHERE id=?", [$job['id']]); $done++; }
        elseif ($job['attempts'] >= JOB_MAX_ATTEMPTS) q("UPDATE jobs SET status='failed', last_error=? WHERE id=?", [$err, $job['id']]);
        else q("UPDATE jobs SET status='pending', last_error=?, next_attempt=DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id=?", [$err, JOB_BACKOFF[min($job['attempts'] - 1, 4)], $job['id']]);
    }
    return $done;
}

function run_job(string $type, array $p, array $job): array {
    use_site($job['form_id'] ? ((int)q('SELECT site_id FROM forms WHERE id=?', [$job['form_id']])->fetchColumn() ?: null) : null);   // mail/webhooks use the website's own SMTP, sender and branding
    if ($type === 'mail') {
        require_once __DIR__ . '/mailer.php';
        if ($job['form_id'] && ($db = split_emails(setting('default_bcc', '')))) {   // platform-wide BCC on every client form email
            $have = array_map('strtolower', array_merge(split_emails($p['to'] ?? ''), split_emails($p['cc'] ?? ''), split_emails($p['bcc'] ?? '')));
            $p['bcc'] = implode(', ', array_merge(split_emails($p['bcc'] ?? ''), array_values(array_filter($db, fn($a) => !in_array(strtolower($a), $have, true)))));
        }
        [$ok, $err] = send_mail($p);
        $final = $ok || $job['attempts'] >= JOB_MAX_ATTEMPTS;
        q('INSERT INTO email_logs (entry_id,form_id,to_email,subject,status,error) VALUES (?,?,?,?,?,?)',
            [$job['entry_id'], $job['form_id'], $p['to'] ?? '', $p['subject'] ?? '', $ok ? 'sent' : 'failed', $ok ? null : ($err . ($final ? '' : ' (will retry)'))]);
        return [$ok, $err];
    }
    if ($type === 'webhook') {
        require_once __DIR__ . '/webhooks.php';
        return deliver_webhook($p, $job);
    }
    return [false, 'Unknown job type'];
}

/** Housekeeping + due jobs. Called by cron.php, or automatically (at most once a minute) by normal traffic. */
function run_cron(): void {
    q("UPDATE jobs SET status='pending' WHERE status='processing' AND next_attempt < (NOW() - INTERVAL 10 MINUTE)"); // recover stuck jobs
    process_jobs(50);
    q('DELETE FROM rate_limits WHERE created_at < (NOW() - INTERVAL 1 HOUR)');
    q('DELETE FROM drafts WHERE expires_at < NOW()');
    q('DELETE FROM spam_tokens WHERE created_at < (NOW() - INTERVAL 2 DAY)');
    q('DELETE FROM captcha_challenges WHERE expires_at < (NOW() - INTERVAL 1 HOUR)');
    q('DELETE FROM trusted_devices WHERE expires_at < NOW()');
    q('DELETE FROM password_resets WHERE expires_at < NOW()');
    q('DELETE FROM twofa_codes WHERE expires_at < (NOW() - INTERVAL 1 DAY)');
    q("DELETE FROM jobs WHERE status='done' AND done_at < (NOW() - INTERVAL 14 DAY)");
    foreach (q('SELECT id FROM sites')->fetchAll(PDO::FETCH_COLUMN) as $sid) {   // retention rules: each website may override the platform values
        $sid = (int)$sid; $fs = '(SELECT id FROM forms WHERE site_id=' . $sid . ')';
        $days = (int)site_setting('retention_days', 0, $sid);
        if ($days > 0) q("DELETE FROM entries WHERE is_spam=0 AND form_id IN $fs AND created_at < (NOW() - INTERVAL $days DAY)");
        $sd = (int)site_setting('spam_retention_days', 30, $sid);
        if ($sd > 0) q("DELETE FROM entries WHERE is_spam=1 AND form_id IN $fs AND created_at < (NOW() - INTERVAL $sd DAY)");
        $days = (int)site_setting('status_reset_days', 30, $sid);   // read entries still marked "New" after a month are cleared to "No status"
        if ($days > 0) q("UPDATE entries SET status='' WHERE status='new' AND is_read=1 AND is_spam=0 AND form_id IN $fs AND created_at < (NOW() - INTERVAL $days DAY)");
    }
    require_once __DIR__ . '/antivirus.php'; av_probe_refresh();
    if (function_exists('run_scheduled_backup')) run_scheduled_backup();
    require_once __DIR__ . '/reports.php'; send_monthly_reports();   // monthly lead report per client (1st of the month onward)
}

/** Pseudo-cron: runs housekeeping at most once per minute when someone uses the app. */
function maybe_run_cron(): void {
    $last = (string)q("SELECT v FROM settings WHERE k='last_cron'")->fetchColumn();
    if ($last === '') { q("INSERT IGNORE INTO settings (k,v) VALUES ('last_cron','0')"); $last = '0'; }
    if (time() - (int)$last < 60) return;
    // atomic claim: only one request wins
    if (q("UPDATE settings SET v=? WHERE k='last_cron' AND v=?", [(string)time(), $last])->rowCount() !== 1) return;
    try { run_cron(); } catch (Throwable $e) { error_log('TopTen Form cron: ' . $e->getMessage()); }
}
