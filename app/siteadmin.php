<?php
/** Helpers for managing websites (used by the super-admin pages and the site settings page). */

/** Remove a form and everything that belongs to it. */
function delete_form_cascade(int $id): void {
    foreach (['entries', 'notifications', 'webhooks', 'drafts'] as $t) q("DELETE FROM $t WHERE form_id=?", [$id]);
    q('DELETE FROM jobs WHERE form_id=?', [$id]); q('DELETE FROM email_logs WHERE form_id=?', [$id]);
    q('DELETE FROM forms WHERE id=?', [$id]);
}

/** Delete a website with its forms, entries, rules, settings, memberships and logo. Accounts that only belonged to it are deleted too (never super admins). */
function delete_site(int $sid): void {
    $site = site_by_id($sid); if (!$site) return;
    foreach (q('SELECT id FROM forms WHERE site_id=?', [$sid])->fetchAll(PDO::FETCH_COLUMN) as $fid) delete_form_cascade((int)$fid);
    $members = q('SELECT user_id FROM user_sites WHERE site_id=?', [$sid])->fetchAll(PDO::FETCH_COLUMN);
    q('DELETE FROM user_sites WHERE site_id=?', [$sid]);
    foreach ($members as $uid) {
        $u = q('SELECT is_super FROM users WHERE id=?', [$uid])->fetch();
        if ($u && !$u['is_super'] && !q('SELECT 1 FROM user_sites WHERE user_id=?', [$uid])->fetchColumn()) {
            foreach (['trusted_devices', 'twofa_codes', 'user_prefs', 'password_resets'] as $t) q("DELETE FROM $t WHERE user_id=?", [$uid]);
            q('DELETE FROM users WHERE id=?', [$uid]);
        }
    }
    q('DELETE FROM spam_rules WHERE site_id=?', [$sid]); q('DELETE FROM site_settings WHERE site_id=?', [$sid]); q('UPDATE activity_log SET site_id=NULL WHERE site_id=?', [$sid]);
    if (!empty($site['logo'])) delete_media($site['logo']);
    q('DELETE FROM sites WHERE id=?', [$sid]);
}

/** A unique, URL-safe slug for a website name. */
function unique_site_slug(string $name, int $ignoreId = 0): string {
    $base = slugify($name); $slug = $base; $i = 2;
    while (q('SELECT 1 FROM sites WHERE slug=? AND id<>?', [$slug, $ignoreId])->fetchColumn()) $slug = $base . '-' . $i++;
    return $slug;
}

/** Clean "one domain per line" input: lowercase hosts without scheme/path. */
function clean_domains(string $raw): string {
    $out = [];
    foreach (preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $d) {
        $d = strtolower(preg_replace('#^https?://#i', '', trim($d))); $d = preg_replace('#[/?\#].*$#', '', $d);
        if (preg_match('/^[a-z0-9.-]+(:\d+)?$/', $d)) $out[$d] = $d;
    }
    return implode("\n", $out);
}

/** Save the descriptive details of a website from a POST. Returns an error message or ''. */
function save_site_details(int $sid, array $p): string {
    $name = trim($p['name'] ?? ''); if ($name === '') return 'Enter the website name.';
    $email = trim($p['contact_email'] ?? ''); if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return 'The contact email is not valid.';
    $url = trim($p['website_url'] ?? ''); if ($url !== '' && !preg_match('#^https?://#i', $url)) return 'The website address must start with http:// or https://';
    q('UPDATE sites SET name=?, company=?, website_url=?, allowed_domains=?, contact_email=?, phone=?, address=? WHERE id=?',
        [mb_substr($name, 0, 150), mb_substr(trim($p['company'] ?? ''), 0, 150), mb_substr($url, 0, 250), clean_domains($p['allowed_domains'] ?? ''), $email, mb_substr(trim($p['phone'] ?? ''), 0, 60), mb_substr(trim($p['address'] ?? ''), 0, 400), $sid]);
    return '';
}
