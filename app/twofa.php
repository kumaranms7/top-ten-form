<?php
/** Optional two-factor login by email: a 6-digit code (10 minutes, 5 tries) and "remember this device for 30 days". */
const TWOFA_TTL = 600;
const TWOFA_MAX_TRIES = 5;
const TWOFA_RESEND_WAIT = 30;
const TWOFA_LOCKOUT_WAIT = 300; // after too many wrong guesses, no new code for 5 minutes
const TWOFA_TRUST_DAYS = 30;

function twofa_globally_off(): bool { return defined('TF_DISABLE_2FA') && TF_DISABLE_2FA; } // emergency switch in config/config.php

function twofa_hash(string $code, int $uid): string { return hash_hmac('sha256', $code, 'tf2fa|' . $uid . '|' . TF_ROOT); }

/** Generate and email a fresh code. Returns [ok, error]. */
function twofa_send_code(array $user, string $purpose = 'login'): array {
    require_once __DIR__ . '/mailer.php';
    $row = q('SELECT sent_at, attempts FROM twofa_codes WHERE user_id=?', [$user['id']])->fetch();
    if ($row) {
        $locked = $row['attempts'] >= TWOFA_MAX_TRIES; $wait = $locked ? TWOFA_LOCKOUT_WAIT : TWOFA_RESEND_WAIT; $left = $wait - (time() - strtotime($row['sent_at']));
        if ($left > 0) return [false, $locked ? 'Too many wrong attempts. Please wait ' . (int)ceil($left / 60) . ' minute(s) before requesting a new code.' : "Please wait $left seconds before requesting another code."];
    }
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    q('REPLACE INTO twofa_codes (user_id, code_hash, expires_at, attempts, sent_at, purpose) VALUES (?,?,DATE_ADD(NOW(), INTERVAL ? SECOND),0,NOW(),?)', [$user['id'], twofa_hash($code, (int)$user['id']), TWOFA_TTL, $purpose]);
    $what = $purpose === 'enable' ? 'turn on two-step verification' : 'sign in';
    $body = wrap_email_html('<p>Hi ' . e($user['name']) . ',</p><p>Use this code to ' . $what . ':</p><p style="font-size:32px;letter-spacing:8px;font-weight:700;margin:18px 0;color:#4338ca">' . $code . '</p><p>It expires in 10 minutes. If you did not try to sign in, ignore this email and consider changing your password.</p>');
    [$ok, $err] = send_mail(['to' => $user['email'], 'from_email' => '', 'from_name' => '', 'subject' => 'Your ' . setting('site_name', 'TopTen Form') . ' verification code', 'body' => $body]);
    if (!$ok) q('DELETE FROM twofa_codes WHERE user_id=?', [$user['id']]);
    return [$ok, $ok ? '' : $err];
}

/** Check a code. Returns [ok, message]. Wrong guesses are counted; after 5 the code is void. */
function twofa_verify(int $uid, string $code, string $purpose = 'login'): array {
    $row = q('SELECT * FROM twofa_codes WHERE user_id=? AND purpose=?', [$uid, $purpose])->fetch();
    if (!$row || strtotime($row['expires_at']) < time()) return [false, 'That code has expired. Request a new one.'];
    if ($row['attempts'] >= TWOFA_MAX_TRIES) return [false, 'Too many wrong attempts. Please wait a few minutes, then request a new code.']; // row is kept so the lockout (and resend wait) stays in force
    $code = preg_replace('/\D/', '', $code);
    if (!hash_equals($row['code_hash'], twofa_hash($code, $uid))) {
        q('UPDATE twofa_codes SET attempts=attempts+1 WHERE user_id=?', [$uid]);
        $left = TWOFA_MAX_TRIES - $row['attempts'] - 1;
        return [false, $left > 0 ? "That code is not correct. $left attempt(s) left." : 'Too many wrong attempts. Please wait a few minutes, then request a new code.'];
    }
    q('DELETE FROM twofa_codes WHERE user_id=?', [$uid]);
    return [true, ''];
}

function twofa_cookie_opts(int $expires): array {
    return ['expires' => $expires, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax'];
}
function twofa_trust_device(int $uid): void {
    $token = bin2hex(random_bytes(32));
    q('INSERT INTO trusted_devices (user_id, token_hash, user_agent, expires_at) VALUES (?,?,?,DATE_ADD(NOW(), INTERVAL ? DAY))', [$uid, hash('sha256', $token), substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255), TWOFA_TRUST_DAYS]);
    setcookie('tf_trust', $uid . '.' . $token, twofa_cookie_opts(time() + TWOFA_TRUST_DAYS * 86400));
}
function twofa_is_trusted(int $uid): bool {
    $c = (string)($_COOKIE['tf_trust'] ?? '');
    if (!preg_match('/^(\d+)\.([a-f0-9]{64})$/', $c, $m) || (int)$m[1] !== $uid) return false;
    return (bool)q('SELECT 1 FROM trusted_devices WHERE user_id=? AND token_hash=? AND expires_at > NOW()', [$uid, hash('sha256', $m[2])])->fetchColumn();
}
/** Forget every trusted device of a user (password change, 2FA turned off, admin reset). */
function twofa_forget_devices(int $uid): void {
    q('DELETE FROM trusted_devices WHERE user_id=?', [$uid]);
    if (isset($_COOKIE['tf_trust'])) setcookie('tf_trust', '', twofa_cookie_opts(time() - 3600));
}
