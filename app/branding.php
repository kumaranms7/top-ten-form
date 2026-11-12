<?php
/** Logos and the branded email template. Logos live in public/media (the only web-readable upload folder; scripts can never run there). */

function media_dir(): string {
    $d = TF_ROOT . '/public/media';
    if (!is_dir($d)) @mkdir($d, 0755, true);
    if (!is_file("$d/.htaccess")) @file_put_contents("$d/.htaccess", "Options -Indexes -ExecCGI\n<FilesMatch \"\\.(php|phtml|phar|pl|py|cgi|sh)$\">\n  Require all denied\n</FilesMatch>\n<IfModule mod_headers.c>\n  Header set X-Content-Type-Options nosniff\n  Header set Cache-Control \"public, max-age=86400\"\n</IfModule>\n");
    return $d;
}

/** Validate and store an uploaded logo image. Returns [filename|null, error]. */
function save_logo_upload(?array $f): array {
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return [null, ''];
    if ($f['error'] !== UPLOAD_ERR_OK) return [null, 'The logo upload failed.'];
    if ($f['size'] > 1048576) return [null, 'The logo must be 1 MB or smaller.'];
    $info = @getimagesize($f['tmp_name']);
    $map = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($map[$info[2]])) return [null, 'Please upload a PNG, JPG, GIF or WebP image.'];
    $name = 'logo_' . bin2hex(random_bytes(8)) . '.' . $map[$info[2]];
    if (!@move_uploaded_file($f['tmp_name'], media_dir() . '/' . $name)) return [null, 'Could not save the logo (check folder permissions).'];
    return [$name, ''];
}
function delete_media(string $file): void {
    if ($file !== '' && preg_match('/^logo_[a-f0-9]{16}\.(png|jpg|gif|webp)$/', $file)) @unlink(media_dir() . '/' . $file);
}
function media_url(string $file): string { return base_url() . '/public/media/' . rawurlencode($file); }

/** Logo filename to use in emails for a form (form's own logo first, then the site-wide one). */
function email_logo_for(?int $formId, ?int $sid = null): string {
    if ($formId) { $f = (string)setting('email_logo_form_' . $formId, ''); if ($f !== '' && is_file(media_dir() . '/' . $f)) return $f; }
    if ($sid && ($sl = (string)q('SELECT logo FROM sites WHERE id=?', [$sid])->fetchColumn()) !== '' && is_file(media_dir() . '/' . $sl)) return $sl;   // website logo
    $g = (string)setting('email_logo', '');
    return ($g !== '' && is_file(media_dir() . '/' . $g)) ? $g : '';
}

/** Wrap an email body in the site's branded template (logo header, footer). Turn off in Settings to send bodies as written. */
function wrap_email_html(string $inner, ?int $formId = null): string {
    $sid = $formId ? ((int)q('SELECT site_id FROM forms WHERE id=?', [$formId])->fetchColumn() ?: null) : site_id();
    if (site_setting('email_branded', '1', $sid) !== '1') return $inner;
    $logo = email_logo_for($formId, $sid);
    $w = max(60, min(400, (int)site_setting('email_logo_width', 180, $sid)));
    $head = e(site_setting('email_header_color', '#ffffff', $sid));
    $footer = trim((string)site_setting('email_footer_text', '', $sid));
    $brand = $sid ? (string)q('SELECT name FROM sites WHERE id=?', [$sid])->fetchColumn() : '';
    if ($footer === '') $footer = 'Sent by ' . ($brand !== '' ? $brand : setting('site_name', 'TopTen Form'));
    $name = $formId ? (string)q('SELECT name FROM forms WHERE id=?', [$formId])->fetchColumn() : '';
    $link = trim((string)site_setting('email_logo_link', '', $sid));
    $img = '';
    if ($logo !== '') {
        $img = '<img src="' . e(media_url($logo)) . '" alt="' . e($brand !== '' ? $brand : setting('site_name', 'Logo')) . '" width="' . $w . '" style="display:block;margin:0 auto;max-width:' . $w . 'px;height:auto;border:0">';
        if (preg_match('#^https?://#i', $link)) $img = '<a href="' . e($link) . '" style="text-decoration:none">' . $img . '</a>';
    }
    $font = 'font-family:Arial,Helvetica,sans-serif';
    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;padding:0;background:#f1f3f9">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f3f9;padding:24px 10px"><tr><td align="center">'
        . '<table role="presentation" width="620" cellpadding="0" cellspacing="0" style="width:100%;max-width:620px;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e3e6f0;' . $font . ';color:#1f2933">'
        . ($img !== '' ? '<tr><td style="padding:24px 28px;background:' . $head . ';text-align:center;border-bottom:1px solid #eceef6">' . $img . '</td></tr>' : '')
        . '<tr><td style="padding:28px;font-size:15px;line-height:1.6;' . $font . '">' . $inner . '</td></tr>'
        . '<tr><td style="padding:16px 28px;background:#f8f9fc;border-top:1px solid #eceef6;font-size:12px;line-height:1.5;color:#6b7285;text-align:center;' . $font . '">' . nl2br(e($footer)) . ($name !== '' ? '<br>Form: ' . e($name) : '') . '</td></tr>'
        . '</table></td></tr></table></body></html>';
}

/** Path of the admin logo relative to the admin/ folder (custom upload, else the bundled default). */
function admin_logo_path(): string {
    $al = (string)setting('admin_logo', '');
    return ($al !== '' && is_file(media_dir() . '/' . $al)) ? '../public/media/' . $al : 'assets/img/logo.webp';
}
