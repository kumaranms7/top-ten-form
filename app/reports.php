<?php
/** Automatic monthly lead report: ONE email covering every client website for the previous calendar month, linking to each website's full report page. */
require_once __DIR__ . '/entries_lib.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/render.php';

/** The month as [start 'Y-m-d H:i:s', end (exclusive) 'Y-m-d H:i:s', label 'June 2026', key '2026-06']. */
function report_period(string $ym): array {
    $t = strtotime($ym . '-01 00:00:00');
    return [date('Y-m-d 00:00:00', $t), date('Y-m-d 00:00:00', strtotime('+1 month', $t)), date('F Y', $t), date('Y-m', $t)];
}

function report_recipients(): array { return split_emails(setting('report_recipients', '')); }

/** Lead counts per active website and form for the month (spam excluded). Websites with no forms are still listed. */
function report_summary(string $ym): array {
    [$from, $to] = report_period($ym);
    $sites = [];
    foreach (q("SELECT id,name FROM sites WHERE status='active' ORDER BY name")->fetchAll() as $s) $sites[$s['id']] = ['id' => (int)$s['id'], 'name' => $s['name'], 'total' => 0, 'forms' => []];
    $rows = q('SELECT f.id, f.name, f.site_id, COUNT(e.id) c FROM forms f LEFT JOIN entries e ON e.form_id=f.id AND e.is_spam=0 AND e.created_at >= ? AND e.created_at < ? GROUP BY f.id, f.name, f.site_id ORDER BY f.name', [$from, $to])->fetchAll();
    foreach ($rows as $r) if (isset($sites[$r['site_id']])) { $sites[$r['site_id']]['forms'][] = ['name' => $r['name'], 'count' => (int)$r['c']]; $sites[$r['site_id']]['total'] += (int)$r['c']; }
    return ['sites' => array_values($sites), 'total' => array_sum(array_column($sites, 'total'))];
}

/** The combined email (complete HTML document, email-safe inline styles, same look as the report page). Returns [html, total leads, label]. */
function build_combined_report_email(string $ym): array {
    require_once __DIR__ . '/report_page.php';
    [, , $label] = report_period($ym);
    $sum = report_summary($ym);
    $app = (string)setting('site_name', 'TopTen Form');
    $font = 'font-family:Inter,Arial,Helvetica,sans-serif';
    $period = date('F j', strtotime($ym . '-01')) . ' &ndash; ' . date('F j, Y', strtotime('last day of ' . $ym . '-01'));
    $cards = '';
    foreach ($sum['sites'] as $s) {
        $rows = '';
        foreach ($s['forms'] as $f) {
            $rows .= '<tr><td style="padding:9px 14px;border-top:1px solid #eceef6;font-size:14px;color:' . ($f['count'] ? '#1f2933' : '#9aa1b2') . '">' . e($f['name']) . '</td>'
                . '<td align="right" style="padding:9px 14px;border-top:1px solid #eceef6;font-size:14px;font-weight:700;color:' . ($f['count'] ? '#4338ca' : '#9aa1b2') . '">' . $f['count'] . '</td></tr>';
        }
        if (!$rows) $rows = '<tr><td colspan="2" style="padding:12px 14px;border-top:1px solid #eceef6;font-size:13px;color:#9aa1b2">No forms yet.</td></tr>';
        $cards .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e0e7ff;border-radius:16px;border-collapse:separate;margin:0 0 18px;background:#ffffff">'
            . '<tr><td style="padding:16px 18px;background:#f5f7ff;border-radius:16px 16px 0 0"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
            . '<td style="' . $font . ';font-size:17px;font-weight:800;color:#111827">' . e($s['name']) . '</td>'
            . '<td align="right" style="' . $font . ';white-space:nowrap"><span style="display:inline-block;background:#4f46e5;color:#ffffff;font-size:13px;font-weight:700;border-radius:999px;padding:5px 14px">' . $s['total'] . ' lead' . ($s['total'] === 1 ? '' : 's') . '</span></td></tr></table></td></tr>'
            . '<tr><td style="padding:0"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="' . $font . '">'
            . '<tr><td style="padding:9px 14px;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#6b7285">Form</td><td align="right" style="padding:9px 14px;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#6b7285">Leads</td></tr>'
            . $rows . '</table></td></tr>'
            . '<tr><td style="padding:14px 18px;border-top:1px solid #eceef6"><a href="' . e(report_url($s['id'], $ym)) . '" style="display:inline-block;background:#eef2ff;color:#4338ca;font-weight:700;font-size:14px;text-decoration:none;border-radius:10px;padding:10px 18px;border:1px solid #c7d2fe;' . $font . '">View full report &rarr;</a></td></tr></table>';
    }
    if (!$sum['sites']) $cards = '<p style="color:#6b7285;' . $font . '">There are no active websites.</p>';
    $html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Monthly lead report</title></head>'
        . '<body style="margin:0;padding:0;background:#f1f3f9"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f3f9;padding:24px 10px"><tr><td align="center">'
        . '<table role="presentation" width="640" cellpadding="0" cellspacing="0" style="width:100%;max-width:640px;background:#ffffff;border-radius:20px;overflow:hidden;border:1px solid #e0e7ff;' . $font . ';color:#1f2933">'
        // header: platform logo + title
        . '<tr><td style="padding:26px 30px 8px"><img src="' . e(report_platform_logo()) . '" alt="' . e($app) . '" height="52" style="display:block;height:52px;width:auto;max-width:240px;border:0"></td></tr>'
        . '<tr><td style="padding:14px 30px 4px"><div style="font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#6366f1">Monthly performance digest</div>'
        . '<div style="font-size:28px;font-weight:900;line-height:1.2;color:#0f172a;margin-top:4px">Lead Acquisition Report</div>'
        . '<div style="font-size:15px;color:#475569;margin-top:6px">Reporting period: <b style="color:#0f172a">' . $period . '</b></div></td></tr>'
        // first CTA box: all websites
        . '<tr><td style="padding:22px 30px 8px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-radius:20px;background:#4f46e5;background-image:linear-gradient(135deg,#6366f1,#4f46e5 45%,#9333ea)"><tr><td style="padding:24px 26px">'
        . '<div style="font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#e0e7ff">Total leads &middot; all websites</div>'
        . '<div style="font-size:54px;font-weight:900;line-height:1.1;color:#ffffff;margin-top:6px">' . $sum['total'] . '</div>'
        . '<div style="font-size:14px;color:#e0e7ff;margin-top:4px">' . e($label) . ' &middot; ' . count($sum['sites']) . ' website' . (count($sum['sites']) === 1 ? '' : 's') . '</div></td></tr></table></td></tr>'
        // websites
        . '<tr><td style="padding:18px 30px 8px"><div style="font-size:18px;font-weight:800;color:#0f172a;margin:0 0 12px">Leads by website</div>' . $cards . '</td></tr>'
        . '<tr><td style="padding:16px 30px;background:#f8f9fc;border-top:1px solid #eceef6;font-size:12px;line-height:1.6;color:#6b7285;text-align:center">'
        . 'Spam is excluded from all counts. Each &ldquo;View full report&rdquo; link opens that website&rsquo;s report with every lead, contact details and messages.<br>&copy; ' . date('Y') . ' ' . e($app) . ' &mdash; automated lead reporting.</td></tr>'
        . '</table></td></tr></table></body></html>';
    return [$html, $sum['total'], $label];
}

/** Queue the single combined email. Returns the total number of leads reported. */
function queue_monthly_report(string $ym, array $to): int {
    [$html, $total, $label] = build_combined_report_email($ym);
    queue_job('mail', ['to' => implode(', ', $to), 'cc' => '', 'bcc' => '', 'from_name' => '', 'from_email' => '', 'reply_to' => '',
        'subject' => 'Top Ten Form Monthly Lead Report - ' . $label, 'body' => $html]);
    return $total;
}

/** Called from cron: queue last month's combined report once. */
function send_monthly_reports(): int {
    if (setting('report_enabled', '0') !== '1' || !($to = report_recipients())) return 0;
    $ym = date('Y-m', strtotime('first day of last month'));
    if ($ym < setting('report_from', '0000-00')) return 0;   // never back-fill months before the feature was switched on
    if (setting('report_sent_all', '') === $ym) return 0;
    if (q("INSERT INTO settings (k,v) VALUES ('report_sent_all',?) ON DUPLICATE KEY UPDATE v=IF(v=VALUES(v), v, VALUES(v))", [$ym])->rowCount() === 0) return 0;   // another request already claimed it
    queue_monthly_report($ym, $to);
    return 1;
}
