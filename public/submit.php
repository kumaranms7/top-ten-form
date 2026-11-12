<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/render.php';
require __DIR__ . '/../app/mailer.php';
require __DIR__ . '/../app/antivirus.php';
require __DIR__ . '/../app/spam.php';
require __DIR__ . '/../app/validate.php';
require __DIR__ . '/../app/entries_lib.php';

ob_start(); // lets us answer the visitor first, then send emails/webhooks in the background
$isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

function respond(bool $ok, string $msg, array $extra = [], array $errors = [], bool $ajax = true, string $redirect = ''): void {
    if ($ajax) {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode(['success' => $ok, 'message' => $msg, 'errors' => (object)$errors] + $extra);
    } else {
        $to = $redirect ?: ($_SERVER['HTTP_REFERER'] ?? '');
        if ($to === '') { echo e($msg); }
        else { if (!$redirect) $to .= (strpos($to, '?') === false ? '?' : '&') . 'tf_status=' . ($ok ? 'ok' : 'error') . '&tf_msg=' . rawurlencode($msg); header('Location: ' . $to); }
    }
    if ($ok) { // visitor has their answer; now deliver queued emails/webhooks and housekeeping
        finish_request();
        try { process_jobs(20); maybe_run_cron(); } catch (Throwable $e) { error_log('TopTen Form jobs: ' . $e->getMessage()); }
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // Preflight: reflect origin; the real check happens on the actual request once the form is known.
    if ($origin) { header('Access-Control-Allow-Origin: ' . $origin); header('Access-Control-Allow-Methods: GET, POST, OPTIONS'); header('Access-Control-Allow-Headers: X-Requested-With, Content-Type'); header('Vary: Origin'); }
    exit;
}
if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'GET'], true)) { http_response_code(405); exit('Method not allowed'); }

$fid = (int)($_POST['tf_form_id'] ?? $_GET['tf_form_id'] ?? 0);
$form = q('SELECT * FROM forms WHERE id=?', [$fid])->fetch();
if ($form) use_site((int)$form['site_id']);
if ($form && (($st = site_by_id((int)$form['site_id'])) === null || $st['status'] !== 'active')) $form = null;   // suspended website: its forms stop accepting entries
if (!$form || $form['status'] !== 'active') respond(false, 'This form is not available.', [], [], true);
$s = form_settings($form);
$fields = form_fields($form);
$ip = client_ip();

// ---- Origin / CORS check ----
$allowed = array_filter(array_map(fn($o) => rtrim(strtolower(trim($o)), '/'), preg_split('/[\s,]+/', $s['allowed_origins'])));
$siteDomains = site_domains(current_site() ?? []);
if ($origin) {
    if (!$allowed && $siteDomains && !origin_in_domains($origin, $siteDomains)) { http_response_code(403); respond(false, 'Submissions from this website are not allowed.', [], [], $isAjax); }
    if ($allowed && !in_array(rtrim(strtolower($origin), '/'), $allowed, true)) { http_response_code(403); respond(false, 'Submissions from this website are not allowed.', [], [], $isAjax); }
    header('Access-Control-Allow-Origin: ' . $origin); header('Vary: Origin');
}

// ---- Save & resume: fetch a saved draft ----
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['tf_captcha'])) {   // a new built-in captcha challenge (never contains the answer)
    header('Content-Type: application/json'); header('Cache-Control: no-store');
    if (!captcha_builtin_active()) { echo json_encode(['builtin' => false]); exit; }
    if (q('SELECT COUNT(*) FROM rate_limits WHERE ip=? AND form_id=? AND created_at > (NOW() - INTERVAL 10 MINUTE)', [$ip, -(1000000 + $fid)])->fetchColumn() >= 60) { http_response_code(429); echo json_encode(['builtin' => true, 'error' => 'Too many requests. Please wait a moment.']); exit; }
    q('INSERT INTO rate_limits (ip, form_id) VALUES (?,?)', [$ip, -(1000000 + $fid)]);
    echo json_encode(captcha_issue($fid, !empty($_GET['alt']) ? 'question' : $s['captcha_type'], $s['captcha_questions']) + ['builtin' => true]); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['tf_token'])) {   // a fresh signed token for the spam check (the form script asks for one on load)
    header('Content-Type: application/json'); header('Cache-Control: no-store');
    echo json_encode(['token' => spam_issue_token($fid)]); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $tok = (string)($_GET['tf_draft'] ?? '');
    if (!preg_match('/^[a-f0-9]{40}$/', $tok)) respond(false, 'Invalid link.', [], [], true);
    $d = q('SELECT data_json FROM drafts WHERE token=? AND form_id=? AND expires_at > NOW()', [$tok, $fid])->fetchColumn();
    if (!$d) respond(false, 'This saved link has expired or is no longer valid.', [], [], true);
    header('Content-Type: application/json'); header('Cache-Control: no-store');
    echo json_encode(['success' => true, 'data' => json_decode($d, true) ?: new stdClass()]); exit;
}

// ---- Save & resume: store a draft and email the visitor a link ----
if (($_POST['tf_action'] ?? '') === 'save_draft') {
    if (empty($s['save_resume'])) respond(false, 'Saving is not enabled for this form.', [], [], true);
    $email = trim((string)($_POST['tf_email'] ?? ''));
    $page = (string)($_POST['tf_page'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) respond(false, 'Enter a valid email address.', [], [], true);
    if (!preg_match('#^https?://[^\s]+$#i', $page)) respond(false, 'Could not create a link from this page.', [], [], true);
    if (q('SELECT COUNT(*) FROM rate_limits WHERE ip=? AND form_id=? AND created_at > (NOW() - INTERVAL 10 MINUTE)', [$ip, -$fid])->fetchColumn() >= 5)
        respond(false, 'Too many requests. Please try again later.', [], [], true);
    q('INSERT INTO rate_limits (ip, form_id) VALUES (?,?)', [$ip, -$fid]);
    $keys = [];
    foreach ($fields as $f) {
        if (!is_input_field($f) || in_array($f['type'], ['file', 'signature', 'calculated'], true)) continue;
        if ($f['type'] === 'name') foreach (['first', 'middle', 'last', 'full'] as $k) $keys[] = $f['name'] . '_' . $k;
        elseif ($f['type'] === 'address') foreach (['street', 'line2', 'city', 'state', 'zip', 'country', 'full'] as $k) $keys[] = $f['name'] . '_' . $k;
        else $keys[] = $f['name'];
    }
    $data = [];
    foreach ($keys as $k) if (isset($_POST[$k])) $data[$k] = is_array($_POST[$k]) ? array_values(array_map('strval', $_POST[$k])) : (string)$_POST[$k];
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    if (strlen($json) > 120000) respond(false, 'Your answers are too long to save.', [], [], true);
    $token = bin2hex(random_bytes(20));
    q('INSERT INTO drafts (form_id,token,data_json,email,expires_at) VALUES (?,?,?,?,DATE_ADD(NOW(), INTERVAL 30 DAY))', [$fid, $token, $json, $email]);
    $base = preg_replace('/[#].*$/', '', $page);
    $base = preg_replace('/([?&])tf_resume=[a-f0-9]+(&|$)/', '$1', $base); $base = rtrim($base, '?&');
    $link = $base . (strpos($base, '?') === false ? '?' : '&') . 'tf_resume=' . $token;
    $body = wrap_email_html('<p>Here is your link to continue the form <strong>' . e($form['name']) . '</strong>:</p><p><a href="' . e($link) . '">' . e($link) . '</a></p><p>It works for 30 days. Files and signatures are not saved, so you will need to add them again.</p>', $fid);
    queue_job('mail', ['to' => $email, 'from_email' => '', 'from_name' => '', 'subject' => 'Continue your form: ' . $form['name'], 'body' => $body], $fid, null);
    respond(true, 'We have emailed you a link. Use it to continue where you left off.', [], [], true);
}

// ---- Spam: honeypot + rate limit ----
if (!empty($_POST['tf_website'])) respond(true, $s['success_message'], [], [], $isAjax); // silently drop bots
q('DELETE FROM rate_limits WHERE created_at < (NOW() - INTERVAL 1 HOUR)');
$level = $s['spam_protection'] ?? 'standard';
$limit = $level === 'strict' ? 3 : 10;
if (q('SELECT COUNT(*) FROM rate_limits WHERE ip=? AND form_id=? AND created_at > (NOW() - INTERVAL 10 MINUTE)', [$ip, $fid])->fetchColumn() >= $limit
    || q('SELECT COUNT(*) FROM rate_limits WHERE ip=? AND form_id>0 AND created_at > (NOW() - INTERVAL 10 MINUTE)', [$ip])->fetchColumn() >= 40)
    respond(false, 'Too many submissions. Please try again later.', [], [], $isAjax);

// ---- Captcha (mandatory on every form): the provider chosen in Settings, otherwise the built-in captcha ----
if (captcha_external_active()) {
    $tok = $_POST['cf-turnstile-response'] ?? ($_POST['g-recaptcha-response'] ?? '');
    $url = captcha_provider() === 'turnstile' ? 'https://challenges.cloudflare.com/turnstile/v0/siteverify' : 'https://www.google.com/recaptcha/api/siteverify';
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => 'Content-type: application/x-www-form-urlencoded', 'content' => http_build_query(['secret' => captcha_cfg()[2], 'response' => $tok, 'remoteip' => $ip]), 'timeout' => 8]]);
    $res = json_decode(@file_get_contents($url, false, $ctx), true);
    if (empty($res['success'])) respond(false, 'Please complete the captcha verification.', [], [], $isAjax);
} elseif (captcha_builtin_active()) {
    $failKey = -(2000000 + $fid);
    if (q('SELECT COUNT(*) FROM rate_limits WHERE ip=? AND form_id=? AND created_at > (NOW() - INTERVAL 10 MINUTE)', [$ip, $failKey])->fetchColumn() >= 30)
        respond(false, 'Too many wrong answers. Please wait a few minutes and try again.', [], [], $isAjax);
    $capId = (string)($_POST['tf_captcha_id'] ?? '');
    $cv = captcha_verify($capId, (string)($_POST['tf_captcha_answer'] ?? ''), $fid);
    if (!$cv['ok']) {
        if ($capId !== '' && trim((string)($_POST['tf_captcha_answer'] ?? '')) !== '') q('INSERT INTO rate_limits (ip, form_id) VALUES (?,?)', [$ip, $failKey]);
        $fresh = $cv['new'] ? captcha_issue($fid, $s['captcha_type'], $s['captcha_questions']) : null;
        respond(false, 'Please check the security question.', ['captcha' => $fresh], ['tf_captcha' => $cv['msg']], $isAjax);
    }
}

// ---- Validate (every field type, conditional logic, calculated fields) ----
[$errors, $vals, $uploads] = validate_submission($fields, $_POST, $_FILES);
if ($errors) respond(false, 'Please fix the highlighted fields.', [], $errors, $isAjax);

// ---- Save ----
q('INSERT INTO rate_limits (ip, form_id) VALUES (?,?)', [$ip, $fid]);
$pageUrl = (string)($_POST['tf_page'] ?? ($_SERVER['HTTP_REFERER'] ?? ''));
if (!preg_match('#^https?://#i', $pageUrl)) $pageUrl = '';
$ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
$meta = ['page_url' => $pageUrl, 'user_agent' => $ua];
$flatPre = array_map('flat_value', $vals);
if (captcha_builtin_active() && !empty($capId)) captcha_consume($capId);   // solved challenge is single-use
$tokenInfo = spam_verify_token((string)($_POST['tf_token'] ?? ''), $fid, true);   // single use: consumed now that the submission is valid
$spamReason = spam_check($fields, $flatPre, $ip, $_POST, ['level' => $level, 'token' => $tokenInfo, 'form_id' => $fid]);   // null = looks fine
$utmJson = clean_utm($_POST['tf_utm'] ?? '');
$referrer = substr((string)($_POST['tf_ref'] ?? ''), 0, 500); if (!preg_match('#^https?://#i', $referrer)) $referrer = '';
$sourcePage = substr(trim((string)($_POST['tf_srcpage'] ?? '')), 0, 500); if (!preg_match('#^https?://[^\s]+$#i', $sourcePage)) $sourcePage = '';
$attachments = [];
$entryId = 0;
if ($s['save_entries']) {
    q('INSERT INTO entries (form_id,data_json,ip,user_agent,page_url,is_spam,spam_reason,utm_json,referrer,source_page) VALUES (?,?,?,?,?,?,?,?,?,?)',
        [$fid, '{}', $ip, $ua, substr($pageUrl, 0, 500), $spamReason ? 1 : 0, $spamReason, $utmJson, $referrer ?: null, $sourcePage ?: null]);
    $entryId = (int)db()->lastInsertId();
}
foreach ($uploads as $n => $fi) {
    $dir = upload_path((string)$fid); if (!is_dir($dir)) mkdir($dir, 0755, true);
    $stored = ($entryId ?: time()) . '_' . bin2hex(random_bytes(6)) . '.' . $fi['ext'];
    $ok = isset($fi['content']) ? file_put_contents("$dir/$stored", $fi['content']) !== false : move_uploaded_file($fi['tmp'], "$dir/$stored");
    if ($ok) { $vals[$n] = ['file' => $fid . '/' . $stored, 'name' => $fi['name']]; $attachments["$dir/$stored"] = $fi['name']; }
}
if ($entryId) q('UPDATE entries SET data_json=? WHERE id=?', [json_encode($vals, JSON_UNESCAPED_UNICODE), $entryId]);
if (!empty($_POST['tf_resume_token']) && preg_match('/^[a-f0-9]{40}$/', $_POST['tf_resume_token'])) q('DELETE FROM drafts WHERE token=? AND form_id=?', [$_POST['tf_resume_token'], $fid]);

// ---- Notifications (never for spam). A notification can be limited to certain answers (routing). ----
$flat = array_map('flat_value', $vals);
foreach ($spamReason ? [] : q('SELECT * FROM notifications WHERE form_id=? AND active=1', [$fid])->fetchAll() as $n) {
    if (!condition_matches($n['condition_json'] ?? null, $vals)) continue;
    $to = merge_tags($n['to_email'], $form, $fields, $flat, $entryId, $ip, false, $meta);
    if (!split_emails(strip_tags($to))) continue;
    $subject = html_entity_decode(merge_tags($n['subject'], $form, $fields, $flat, $entryId, $ip, true, $meta), ENT_QUOTES);
    $body = wrap_email_html(merge_tags($n['body'], $form, $fields, $flat, $entryId, $ip, true, $meta), $fid);
    $replyTo = html_entity_decode(merge_tags((string)$n['reply_to'], $form, $fields, $flat, $entryId, $ip), ENT_QUOTES);
    queue_job('mail', [
        'to' => html_entity_decode($to), 'cc' => $n['cc'], 'bcc' => $n['bcc'], 'from_name' => $n['from_name'], 'from_email' => $n['from_email'],
        'reply_to' => $replyTo, 'subject' => $subject, 'body' => $body,
        'attachments' => $n['type'] === 'admin' ? $attachments : [],
    ], $fid, $entryId ?: null);
}
if (!$spamReason && $entryId && function_exists('queue_webhooks')) queue_webhooks($form, $fields, $entryId, $vals, ['ip' => $ip, 'page_url' => $pageUrl, 'user_agent' => $ua, 'referrer' => $referrer, 'source_page' => $sourcePage, 'utm' => $utmJson ? json_decode($utmJson, true) : new stdClass()]);

// ---- Respond ----
if ($s['success_mode'] === 'redirect' && $s['redirect_url'] !== '') {
    $url = $s['redirect_url'];
    if (!empty($s['pass_values'])) $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query(array_filter($flat, 'is_string'));
    respond(true, $s['success_message'], ['redirect' => $url], [], $isAjax, $url);
}
respond(true, $s['success_message'], [], [], $isAjax);
