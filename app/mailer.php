<?php
require_once __DIR__ . '/../vendor/phpmailer/Exception.php';
require_once __DIR__ . '/../vendor/phpmailer/PHPMailer.php';
require_once __DIR__ . '/../vendor/phpmailer/SMTP.php';
use PHPMailer\PHPMailer\PHPMailer;

/** Send an HTML email using global settings. Returns [bool ok, string error]. */
function send_mail(array $o): array {
    if (setting('mail_driver') === 'log') { // local testing: write the email to a file instead of sending
        $entry = '[' . date('Y-m-d H:i:s') . "] To: {$o['to']} | Subject: {$o['subject']}\n" . trim(strip_tags(preg_replace('/<br\s*\/?>|<\/tr>|<\/p>/i', "\n", $o['body']))) . "\n" . str_repeat('-', 60) . "\n";
        return @file_put_contents(storage_dir() . '/mail.log', $entry, FILE_APPEND) !== false ? [true, ''] : [false, 'Could not write mail.log in the storage folder'];
    }
    $m = new PHPMailer(true);
    try {
        $m->CharSet = 'UTF-8';
        $m->Timeout = 20; // never let a dead mail server hold a worker for minutes
        $sid = site_id();
        $own = $sid && site_own_setting($sid, 'smtp_enabled', '0') === '1' && site_own_setting($sid, 'smtp_host') !== '';   // the website's own mail server, when set up
        $cfg = fn($k, $d = '') => $own ? site_own_setting($sid, $k, $d) : setting($k, $d);
        if ($own || setting('mail_driver') === 'smtp') {
            $m->isSMTP();
            $m->Host = $cfg('smtp_host');
            $m->Port = (int)$cfg('smtp_port', 587);
            $enc = $cfg('smtp_secure', 'tls');
            if ($enc === 'ssl') $m->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            elseif ($enc === 'tls') $m->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            else { $m->SMTPSecure = ''; $m->SMTPAutoTLS = false; }
            if ($cfg('smtp_user') !== '') { $m->SMTPAuth = true; $m->Username = $cfg('smtp_user'); $m->Password = $cfg('smtp_pass'); }
        }
        $fromEmail = $o['from_email'] ?: site_setting('mail_from_email');
        $fromName = $o['from_name'] ?: site_setting('mail_from_name');
        if (empty($o['reply_to']) && $sid && ($rt = site_own_setting($sid, 'mail_reply_to')) !== '') $o['reply_to'] = $rt;
        $m->setFrom($fromEmail, $fromName);
        foreach (split_emails($o['to']) as $a) $m->addAddress($a);
        foreach (split_emails($o['cc'] ?? '') as $a) $m->addCC($a);
        foreach (split_emails($o['bcc'] ?? '') as $a) $m->addBCC($a);
        if (!empty($o['reply_to']) && filter_var($o['reply_to'], FILTER_VALIDATE_EMAIL)) $m->addReplyTo($o['reply_to']);
        $m->isHTML(true);
        $m->Subject = preg_replace('/[\r\n]+/', ' ', $o['subject']);
        $m->Body = $o['body'];
        $m->AltBody = trim(strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $o['body'])));
        foreach ($o['attachments'] ?? [] as $path => $name) if (is_file($path)) $m->addAttachment($path, $name);
        $m->send();
        return [true, ''];
    } catch (Throwable $ex) {
        $msg = $ex->getMessage();
        if (stripos($msg, 'instantiate mail function') !== false)
            $msg .= ' - PHP mail() is not configured on this server (common on XAMPP/localhost). Go to Settings and choose SMTP, or "Log to file" for local testing.';
        return [false, $msg];
    }
}

function split_emails(?string $s): array {
    $out = [];
    foreach (preg_split('/[,;\s]+/', (string)$s, -1, PREG_SPLIT_NO_EMPTY) as $a) if (filter_var($a, FILTER_VALIDATE_EMAIL)) $out[] = $a;
    return $out;
}

/** Replace {field_name}, {all_fields}, {entry_details}, {form_name}, {entry_id}, {date}, {ip}, {page_url}, {user_agent}.
 *  $vals = [name => value]; $meta = ['page_url'=>..., 'user_agent'=>...] */
function merge_tags(string $tpl, array $form, array $fields, array $vals, int $entryId, string $ip, bool $html = true, array $meta = []): string {
    $cell = 'padding:8px 14px;border:1px solid #e3e6ee;';
    $rows = '';
    $map = ['{form_name}' => e($form['name']), '{entry_id}' => (string)$entryId, '{date}' => fmt_datetime(time()), '{ip}' => e($ip),
        '{page_url}' => e($meta['page_url'] ?? ''), '{user_agent}' => e($meta['user_agent'] ?? '')];
    foreach ($fields as $f) {
        if (!is_input_field($f)) continue;
        $val = $vals[$f['name']] ?? '';
        if (is_array($val)) $val = implode(', ', $val);
        $map['{' . $f['name'] . '}'] = e($val);
        $rows .= '<tr><td style="' . $cell . 'background:#f6f7fb;width:200px"><strong>' . e(strip_tags($f['label'])) . '</strong></td><td style="' . $cell . '">' . nl2br(e($val)) . '</td></tr>';
    }
    $tbl = fn($r) => '<table style="border-collapse:collapse;font-family:Arial,sans-serif;font-size:14px;width:100%;max-width:640px">' . $r . '</table>';
    $info = '';
    foreach (['Form' => $form['name'], 'Submitted' => fmt_datetime(time()), 'IP address' => $ip, 'Page URL' => $meta['page_url'] ?? ''] as $k => $v)
        $info .= '<tr><td style="' . $cell . 'background:#f6f7fb;width:200px"><strong>' . $k . '</strong></td><td style="' . $cell . '">' . e($v) . '</td></tr>';
    $map['{all_fields}'] = $tbl($rows);
    $map['{entry_details}'] = $tbl($rows . $info); // same content as the Entry page in the admin
    return strtr($tpl, $map);
}
