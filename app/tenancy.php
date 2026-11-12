<?php
/**
 * Multi-site (multi-tenant) layer.
 *   Super admin  : users.is_super = 1. Manages every site, user and form, plus platform settings.
 *   Site         : one website/client with its own forms, entries, notifications, webhooks, users, spam rules, logo and settings.
 *   Membership   : user_sites (user, site, role). A user can belong to several sites and switches between them.
 * The "current site" is the one the user is working in (kept in the session) or, for public requests and background jobs, the site of the form.
 */

/** Public requests / jobs set this (use_site) so site-aware helpers know which site they work for. */
function use_site(?int $siteId): void { $GLOBALS['tf_ctx_site'] = $siteId; }

function site_id(): ?int {
    if (array_key_exists('tf_ctx_site', $GLOBALS)) return $GLOBALS['tf_ctx_site'];
    if (PHP_SAPI === 'cli') return null;
    $u = current_user();
    return $u ? ($u['site_id'] ?? null) : null;
}
function current_site(): ?array {
    static $cache = [];
    $id = site_id(); if (!$id) return null;
    if (!isset($cache[$id])) $cache[$id] = q('SELECT * FROM sites WHERE id=?', [$id])->fetch() ?: null;
    return $cache[$id];
}
function site_by_id(int $id): ?array { return q('SELECT * FROM sites WHERE id=?', [$id])->fetch() ?: null; }
function is_super(): bool { $u = current_user(); return $u && !empty($u['is_super']); }
/** A super admin who has not entered any site: sees the platform pages. */
function in_platform(): bool { return is_super() && site_id() === null; }

/** SQL fragment: ids of the forms in the current site (all forms when in the platform view). Safe to embed (integers only). */
function site_forms_sql(): string {
    $sid = site_id();
    return $sid ? '(SELECT id FROM forms WHERE site_id=' . (int)$sid . ')' : '(SELECT id FROM forms)';
}

/** Sites a user may work in: all active sites for a super admin, else their memberships (with role). */
function user_site_list(int $uid, bool $super): array {
    if ($super) return q("SELECT s.*, 'admin' AS role FROM sites s ORDER BY s.name")->fetchAll();
    return q("SELECT s.*, us.role FROM sites s JOIN user_sites us ON us.site_id=s.id WHERE us.user_id=? AND s.status='active' ORDER BY s.name", [$uid])->fetchAll();
}

/** Put a user into a site context (validates membership). Returns true on success. */
function switch_site(?int $siteId): bool {
    $u = current_user(); if (!$u) return false;
    if ($siteId === null) { if (!$u['is_super']) return false; $_SESSION['site_id'] = null; return true; }
    $site = site_by_id($siteId); if (!$site) return false;
    if (!$u['is_super']) { if ($site['status'] !== 'active' || !q('SELECT 1 FROM user_sites WHERE user_id=? AND site_id=?', [$u['id'], $siteId])->fetchColumn()) return false; }
    $_SESSION['site_id'] = $siteId; $_SESSION['last_site_' . $u['id']] = $siteId;
    return true;
}

/** Where to send a user right after signing in. Also picks the site automatically when there is only one. */
function post_login_url(array $u): string {
    $_SESSION['site_id'] = null;
    if (!empty($u['is_super'])) {
        $last = (int)($_SESSION['last_site_' . $u['id']] ?? 0);
        return 'platform.php';
    }
    $sites = user_site_list((int)$u['id'], false);
    if (count($sites) === 1) { $_SESSION['site_id'] = (int)$sites[0]['id']; return 'index.php'; }
    return $sites ? 'choose-site.php' : 'choose-site.php';
}

/** ---------- per-site settings (override the platform setting when set) ---------- */
function site_setting(string $k, $default = '', ?int $siteId = null) {
    $sid = $siteId ?? site_id();
    if ($sid) {
        $c = &$GLOBALS['tf_site_settings'][$sid];
        if ($c === null) { $c = []; foreach (q('SELECT k,v FROM site_settings WHERE site_id=?', [$sid])->fetchAll() as $r) $c[$r['k']] = $r['v']; }
        if (isset($c[$k]) && $c[$k] !== '') return $c[$k];
    }
    return setting($k, $default);
}
/** The site's own value only (no platform fallback). */
function site_own_setting(int $sid, string $k, $default = '') {
    $v = q('SELECT v FROM site_settings WHERE site_id=? AND k=?', [$sid, $k])->fetchColumn();
    return $v === false ? $default : $v;
}
function set_site_setting(int $sid, string $k, string $v): void {
    q('INSERT INTO site_settings (site_id,k,v) VALUES (?,?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)', [$sid, $k, $v]);
    unset($GLOBALS['tf_site_settings'][$sid]);
}

/** Load a form of the CURRENT site, or stop (404). Use this everywhere a form id comes from the URL or a POST. */
function site_form(int $id, bool $ajax = false): array {
    $f = q('SELECT * FROM forms WHERE id=? AND id IN ' . site_forms_sql(), [$id])->fetch();
    if (!$f) {
        if ($ajax) { http_response_code(404); header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => 'Not found']); exit; }
        flash('That form was not found.', 'err'); header('Location: forms.php'); exit;
    }
    return $f;
}

/** Hosts a site accepts form submissions from (its allowed domains, one per line / comma separated). */
function site_domains(array $site): array {
    return array_values(array_filter(array_map(fn($d) => strtolower(preg_replace('#^https?://#i', '', rtrim(trim($d), '/'))), preg_split('/[\s,]+/', (string)($site['allowed_domains'] ?? '')))));
}
/** Does this request origin belong to one of the site's domains (subdomains included)? */
function origin_in_domains(string $origin, array $domains): bool {
    $host = strtolower((string)parse_url($origin, PHP_URL_HOST)); if ($host === '') return false;
    foreach ($domains as $d) { $d = preg_replace('/:\d+$/', '', $d); if ($host === $d || substr($host, -strlen($d) - 1) === '.' . $d || $host === 'www.' . $d) return true; }
    return false;
}
