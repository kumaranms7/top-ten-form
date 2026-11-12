<?php
// Core bootstrap: config, DB, session, helpers, auth, CSRF.
define('TF_ROOT', dirname(__DIR__));
date_default_timezone_set('America/New_York'); // default: US Eastern (EST/EDT)
$cfgFile = TF_ROOT . '/config/config.php';
$tfInstalled = file_exists($cfgFile);
if ($tfInstalled) require $cfgFile;
require_once __DIR__ . '/version.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/migrations.php';
require_once __DIR__ . '/queue.php';
require_once __DIR__ . '/logic.php';
require_once __DIR__ . '/formmodel.php';
require_once __DIR__ . '/branding.php';
require_once __DIR__ . '/webhooks.php';
require_once __DIR__ . '/twofa.php';
require_once __DIR__ . '/captcha.php';
require_once __DIR__ . '/tenancy.php';
require_once __DIR__ . '/siteadmin.php';
require_once __DIR__ . '/backup.php';
require_once __DIR__ . '/updater.php';

function db(): PDO {
    static $pdo;
    if (!$pdo) {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        sync_db_timezone($pdo);
    }
    return $pdo;
}
/** Make MySQL's session time zone match PHP's (handles EST/EDT), so stored and displayed times agree. */
function sync_db_timezone(PDO $pdo): void {
    $off = (new DateTime('now', new DateTimeZone(date_default_timezone_get())))->format('P');
    $pdo->exec("SET time_zone = '" . $off . "'");
}
function q(string $sql, array $p = []): PDOStatement { $s = db()->prepare($sql); $s->execute($p); return $s; }
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function start_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_name('tfsess');
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
        session_start();
    }
}

/** Base URL of the app root (no trailing slash), e.g. http://localhost/Backups/.../TopTen%20Form */
function base_url(): string {
    if (empty($_SERVER['HTTP_HOST'])) return rtrim((string)setting('app_url', ''), '/'); // CLI/cron: use the URL remembered from the last web request
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME']);
    $root = str_replace('\\', '/', TF_ROOT);
    $docroot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/');
    $path = (stripos($root, $docroot) === 0) ? substr($root, strlen($docroot)) : dirname(dirname($script));
    $url = $scheme . '://' . $_SERVER['HTTP_HOST'] . implode('/', array_map('rawurlencode', explode('/', $path)));
    if (setting('app_url', '') !== $url && PHP_SAPI !== 'cli' && !defined('TF_INSTALLING')) { try { set_setting('app_url', $url); } catch (Throwable $e) {} }
    return $url;
}

// ---- settings ----
function setting(string $k, $default = '') {
    global $tf_settings;
    if ($tf_settings === null) {
        $tf_settings = [];
        try { foreach (q('SELECT k,v FROM settings')->fetchAll() as $r) $tf_settings[$r['k']] = $r['v']; } catch (Throwable $e) {}
    }
    return $tf_settings[$k] ?? $default;
}
function set_setting(string $k, string $v): void {
    global $tf_settings;
    q('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)', [$k, $v]);
    if ($tf_settings !== null) $tf_settings[$k] = $v;
}

// ---- CSRF ----
function csrf_token(): string {
    start_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">'; }
function csrf_check(): void {
    start_session();
    $t = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!$t || !hash_equals($_SESSION['csrf'] ?? '', $t)) { http_response_code(419); exit('Invalid or expired security token. Go back and refresh the page.'); }
}

// ---- auth ----
const ROLES = ['admin' => 3, 'editor' => 2, 'viewer' => 1];
function current_user(bool $refresh = false): ?array {
    start_session();
    static $u = false;
    if ($refresh) $u = false;
    if ($u === false) {
        $u = null;
        if (!empty($_SESSION['uid'])) {
            $u = q('SELECT id,name,email,active,is_super FROM users WHERE id=?', [$_SESSION['uid']])->fetch() ?: null;
            if ($u && !$u['active']) $u = null;
        }
        $timeout = (int)setting('session_timeout', 120) * 60;
        if ($u && !empty($_SESSION['last']) && time() - $_SESSION['last'] > $timeout) { $u = null; $_SESSION = []; }
        if ($u) {
            $u['is_super'] = (int)$u['is_super']; $u['site_id'] = null; $u['role'] = $u['is_super'] ? 'admin' : 'none';
            $sid = (int)($_SESSION['site_id'] ?? 0);
            if ($sid) {   // the site the user is working in must exist, and (unless super admin) they must belong to it and it must be active
                $site = q('SELECT id,status FROM sites WHERE id=?', [$sid])->fetch();
                if ($site && $u['is_super']) $u['site_id'] = (int)$sid;
                elseif ($site && $site['status'] === 'active' && ($m = q('SELECT role FROM user_sites WHERE user_id=? AND site_id=?', [$u['id'], $sid])->fetchColumn())) { $u['site_id'] = (int)$sid; $u['role'] = $m; }
                if ($u['site_id'] === null) $_SESSION['site_id'] = null;
            }
            $_SESSION['last'] = time();
        }
    }
    return $u;
}
/** Pages that work INSIDE a website (forms, entries, ...). Needs the user's role in the current site. */
function require_login(string $minRole = 'viewer'): array {
    $u = current_user();
    if (!$u) { header('Location: login.php'); exit; }
    if ($u['site_id'] === null) { header('Location: ' . ($u['is_super'] ? 'platform.php' : 'choose-site.php')); exit; }
    if ((ROLES[$u['role']] ?? 0) < ROLES[$minRole]) { http_response_code(403); exit('You do not have permission to access this page.'); }
    return $u;
}
/** Pages that need only a signed-in user (profile, choose a site). */
function require_user(): array { $u = current_user(); if (!$u) { header('Location: login.php'); exit; } return $u; }
/** Platform pages: super admins only. */
function require_super(): array {
    $u = require_user();
    if (!$u['is_super']) { http_response_code(403); exit('Only a super admin can open this page.'); }
    return $u;
}
/** Pages that make sense both inside a site and on the platform view (logs, ...). */
function require_scope(string $minRole = 'viewer'): array {
    $u = require_user();
    if ($u['site_id'] === null && !$u['is_super']) { header('Location: choose-site.php'); exit; }
    if ((ROLES[$u['role']] ?? 0) < ROLES[$minRole]) { http_response_code(403); exit('You do not have permission to access this page.'); }
    return $u;
}
function can(string $minRole): bool { $u = current_user(); return $u && (ROLES[$u['role']] ?? 0) >= ROLES[$minRole]; }
function log_activity(string $action): void {
    $u = current_user();
    q('INSERT INTO activity_log (user_id,action,ip,site_id) VALUES (?,?,?,?)', [$u['id'] ?? null, $action, $_SERVER['REMOTE_ADDR'] ?? '', $u['site_id'] ?? (array_key_exists('tf_ctx_site', $GLOBALS) ? $GLOBALS['tf_ctx_site'] : null)]);
}
function client_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? ''; }

function flash(?string $msg = null, string $type = 'ok') {
    start_session();
    if ($msg !== null) { $_SESSION['flash'] = [$msg, $type]; return; }
    $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f;
}
function slugify(string $s): string { $s = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $s), '-')); return $s ?: 'form'; }

// ---- display formats used everywhere (admin, emails, reports): date 12-31-2026, time 3:05 PM (12-hour, no leading zero) ----
function fmt_ts($t): int { return is_int($t) || is_float($t) ? (int)$t : (int)(strtotime((string)$t) ?: 0); }
function fmt_date($t): string { return date('m-d-Y', fmt_ts($t)); }
function fmt_time($t, bool $seconds = false): string { return date($seconds ? 'g:i:s A' : 'g:i A', fmt_ts($t)); }
function fmt_datetime($t, bool $seconds = false): string { return fmt_date($t) . ' ' . fmt_time($t, $seconds); }

// ---- field model helpers ----
function field_types(): array {
    return [
        'text' => 'Text', 'email' => 'Email', 'tel' => 'Phone', 'number' => 'Number', 'textarea' => 'Textarea',
        'select' => 'Dropdown', 'radio' => 'Radio buttons', 'checkbox' => 'Checkboxes', 'date' => 'Date',
        'file' => 'File upload', 'hidden' => 'Hidden', 'consent' => 'Consent checkbox',
        'name' => 'Name', 'address' => 'Address', 'rating' => 'Rating', 'time' => 'Time', 'price' => 'Price', 'calculated' => 'Calculated', 'signature' => 'Signature',
        'captcha' => 'Captcha', 'heading' => 'Heading', 'paragraph' => 'Paragraph', 'pagebreak' => 'Page break',
    ];
}
function is_input_field(array $f): bool { return !in_array($f['type'], ['heading', 'paragraph', 'pagebreak', 'captcha'], true); }

if (!$tfInstalled && !defined('TF_INSTALLING')) {
    header('Location: ' . base_url() . '/install.php');
    exit('Not installed. Open install.php');
}

if ($tfInstalled) { $tz = setting('timezone', 'America/New_York'); if (in_array($tz, timezone_identifiers_list(), true) && $tz !== date_default_timezone_get()) { date_default_timezone_set($tz); sync_db_timezone(db()); } }

if ($tfInstalled && !defined('TF_INSTALLING')) { try { run_migrations(); } catch (Throwable $e) { error_log('TopTen Form migration error: ' . $e->getMessage()); } }
