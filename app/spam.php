<?php
/**
 * Built-in spam protection, on by default for every form. No captcha, nothing for real visitors to solve.
 * Anything flagged goes to the Spam folder (recoverable) and never triggers emails or webhooks.
 *
 * Layers (Standard):
 *   1. hidden honeypot field                      6. HTML / link injection and link limit
 *   2. server-signed, single-use form token       7. disposable-email domains
 *   3. minimum fill time (measured by the server) 8. repeated identical submissions
 *   4. rate limiting per visitor and form         9. your own blocked words / domains / IPs
 *   5. captcha (optional, per form)
 * Levels: "basic" = honeypot + rate limit + your rules, "standard" = all layers, "strict" = tighter limits and a required token.
 */

const SPAM_DISPOSABLE = ['mailinator.com', 'guerrillamail.com', 'guerrillamail.net', 'guerrillamail.org', '10minutemail.com', 'tempmail.com', 'temp-mail.org', 'yopmail.com', 'trashmail.com', 'throwawaymail.com',
    'getnada.com', 'sharklasers.com', 'dispostable.com', 'maildrop.cc', 'fakeinbox.com', 'mintemail.com', 'mohmal.com', 'tempr.email', 'emailondeck.com', 'spamgourmet.com', 'moakt.com', 'tmpmail.org',
    'discard.email', 'mailnesia.com', 'burnermail.io', 'tempinbox.com', 'trbvm.com', 'tempail.com', 'mailcatch.com'];
const SPAM_TOKEN_MAX_AGE = 21600; // 6 hours; an older page simply submits without a fresh token (not treated as spam)

function spam_rules(string $type): array {
    static $cache = [];
    $sid = (int)(site_id() ?? 0);   // platform-wide rules (site 0) plus this website's own rules
    if (!isset($cache[$sid][$type])) $cache[$sid][$type] = array_column(q('SELECT DISTINCT value FROM spam_rules WHERE type=? AND site_id IN (0,?) ORDER BY value', [$type, $sid])->fetchAll(), 'value');
    return $cache[$sid][$type];
}

function ip_matches(string $ip, string $rule): bool {
    $rule = trim($rule);
    if ($rule === $ip) return true;
    if (strpos($rule, '*') !== false) return fnmatch($rule, $ip);
    if (strpos($rule, '/') !== false && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {      // IPv4 CIDR
        [$net, $bits] = explode('/', $rule, 2);
        if (!filter_var($net, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !ctype_digit($bits) || (int)$bits > 32) return false;
        $mask = (int)$bits === 0 ? 0 : (~0 << (32 - (int)$bits)) & 0xFFFFFFFF;
        return (ip2long($ip) & $mask) === (ip2long($net) & $mask);
    }
    return false;
}

/* ---------- server-signed single-use token ---------- */
function spam_secret(): string {
    $s = (string)setting('spam_secret', '');
    if ($s === '') { $s = bin2hex(random_bytes(24)); set_setting('spam_secret', $s); }
    return $s;
}
/** Issued to the form's script when the page loads. Format: time.nonce.signature */
function spam_issue_token(int $fid): string {
    $ts = time(); $nonce = bin2hex(random_bytes(8));
    return $ts . '.' . $nonce . '.' . substr(hash_hmac('sha256', "$fid|$ts|$nonce", spam_secret()), 0, 32);
}
/** @return array ['state' => missing|invalid|expired|replayed|valid, 'age' => seconds since issued]. A valid token is used up (single use). */
function spam_verify_token(string $tok, int $fid, bool $consume = true): array {
    if ($tok === '') return ['state' => 'missing', 'age' => 0];
    if (!preg_match('/^(\d{9,11})\.([a-f0-9]{16})\.([a-f0-9]{32})$/', $tok, $m)) return ['state' => 'invalid', 'age' => 0];
    if (!hash_equals(substr(hash_hmac('sha256', "$fid|{$m[1]}|{$m[2]}", spam_secret()), 0, 32), $m[3])) return ['state' => 'invalid', 'age' => 0];
    $age = time() - (int)$m[1];
    if ($age < 0) return ['state' => 'invalid', 'age' => 0];
    if ($age > SPAM_TOKEN_MAX_AGE) return ['state' => 'expired', 'age' => $age];
    if ($consume && q('INSERT IGNORE INTO spam_tokens (nonce) VALUES (?)', [$m[2]])->rowCount() !== 1) return ['state' => 'replayed', 'age' => $age];
    return ['state' => 'valid', 'age' => $age];
}

/** Text signature of a submission, used to spot the same message being sent again and again. */
function spam_signature(array $flat): string {
    return md5(mb_strtolower(implode('|', array_map(fn($v) => trim((string)$v), $flat))));
}

/**
 * Returns a human-readable reason when the submission looks like spam, or null when it looks fine.
 * $ctx: level (basic|standard|strict), token (from spam_verify_token), form_id.
 */
function spam_check(array $fields, array $vals, string $ip, array $post, array $ctx = []): ?string {
    $level = $ctx['level'] ?? 'standard';
    $tok = $ctx['token'] ?? ['state' => 'missing', 'age' => 0];
    foreach (spam_rules('ip') as $rule) if (ip_matches($ip, $rule)) return "Blocked IP ($rule)";

    $texts = []; $emails = [];
    foreach ($fields as $f) {
        if (!is_input_field($f)) continue;
        $v = $vals[$f['name']] ?? '';
        if (is_array($v)) $v = isset($v['name']) ? $v['name'] : implode(' ', $v);
        $v = (string)$v;
        $texts[] = $v;
        if ($f['type'] === 'email' && $v !== '') $emails[] = strtolower($v);
    }
    $all = mb_strtolower(implode("\n", $texts));

    foreach (spam_rules('word') as $w) if ($w !== '' && mb_strpos($all, mb_strtolower($w)) !== false) return "Blocked word ($w)";
    $domains = [];
    foreach ($emails as $em) $domains[] = substr(strrchr($em, '@') ?: '', 1);
    foreach (spam_rules('email_domain') as $d) {
        $d = strtolower(ltrim($d, '@'));
        foreach ($domains as $dom) if ($dom === $d || substr($dom, -strlen($d) - 1) === '.' . $d) return "Blocked email domain ($d)";
    }
    if ($level === 'basic') return null;

    // ---- HTML / link injection (bots love <a href> and BBCode links)
    if (preg_match('/<\s*(a|script|iframe|img|svg|object|embed)\b|\[url[=\]]|\[link[=\]]/i', implode("\n", $texts))) return 'HTML or link code in the message';
    $maxLinks = (int)setting('spam_max_links', 5); if ($level === 'strict' && $maxLinks > 0) $maxLinks = min($maxLinks, 2);
    if ($maxLinks > 0 && preg_match_all('#https?://|www\.#i', $all) > $maxLinks) return 'Too many links';

    // ---- throw-away mailboxes
    if (setting('spam_block_disposable', '1') === '1') foreach ($domains as $dom) foreach (SPAM_DISPOSABLE as $d) if ($dom === $d || substr($dom, -strlen($d) - 1) === '.' . $d) return "Disposable email address ($d)";

    // ---- form token + timing (the server's clock, not the visitor's)
    if ($tok['state'] === 'invalid') return 'Forged form token';
    if ($tok['state'] === 'replayed') return 'Form token already used (replayed request)';
    $minSec = (float)setting('spam_min_seconds', 2); if ($level === 'strict') $minSec = max($minSec, 4);
    $hasElapsed = isset($post['tf_elapsed']) && is_numeric($post['tf_elapsed']);
    if ($tok['state'] === 'valid') {
        if ($minSec > 0 && $tok['age'] < $minSec) return 'Submitted too fast (' . $tok['age'] . 's)';
    } else { // missing or expired
        if (!$hasElapsed && $tok['state'] === 'missing') return 'No browser verification (direct post)';
        if ($level === 'strict' && $tok['state'] === 'missing') return 'Missing form token';
        if ($hasElapsed && $minSec > 0 && (float)$post['tf_elapsed'] < $minSec * 1000) return 'Submitted too fast (' . round($post['tf_elapsed'] / 1000, 1) . 's)';
    }
    if (setting('spam_require_js', '0') === '1' && !$hasElapsed && $tok['state'] !== 'valid') return 'No browser signal (script did not run)';

    // ---- the same message sent again and again
    $win = (int)setting('spam_dupe_minutes', 10); if ($level === 'strict') $win = max($win, 60);
    if ($win > 0 && !empty($ctx['form_id'])) {
        $sig = spam_signature(array_map(fn($v) => is_array($v) ? flat_value($v) : $v, $vals));
        foreach (q('SELECT data_json FROM entries WHERE form_id=? AND created_at > (NOW() - INTERVAL ' . $win . ' MINUTE) ORDER BY id DESC LIMIT 60', [(int)$ctx['form_id']])->fetchAll() as $r) {
            $old = json_decode($r['data_json'], true) ?: [];
            if (spam_signature(array_map('flat_value', $old)) === $sig) return 'Identical to a recent submission';
        }
    }
    return null;
}

/** Keep only known campaign parameters from the JSON the form script sends. */
function clean_utm($raw): ?string {
    $d = json_decode((string)$raw, true);
    if (!is_array($d)) return null;
    $out = [];
    foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'msclkid', 'fbclid'] as $k)
        if (isset($d[$k]) && is_string($d[$k]) && $d[$k] !== '') $out[$k] = mb_substr($d[$k], 0, 190);
    return $out ? json_encode($out, JSON_UNESCAPED_UNICODE) : null;
}
