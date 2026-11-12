<?php
/** The monthly lead report as a full web page (design: monthly_lead_performance_report_template.html), filled with live data. */
require_once __DIR__ . '/reports.php';

const REPORT_COLORS = ['#6366f1', '#06b6d4', '#10b981', '#ec4899', '#f59e0b', '#8b5cf6', '#f43f5e', '#14b8a6'];

function report_key(): string {
    $k = (string)setting('report_key', '');
    if ($k === '') { $k = bin2hex(random_bytes(20)); set_setting('report_key', $k); }
    return $k;
}
function report_token(int $sid): string { return substr(hash_hmac('sha256', "report-site|$sid", report_key()), 0, 32); }   // one permanent read-only token per website
/** The website's common read-only report link (opens the latest closed month; the page has a month picker). */
function report_share_url(int $sid): string { return base_url() . '/public/report.php?s=' . $sid . '&t=' . report_token($sid); }
/** Same link, opened on a particular month. */
function report_url(int $sid, string $ym): string { return report_share_url($sid) . '&m=' . $ym; }

/** Platform logo (admin logo, else the bundled one) as an absolute URL. */
function report_platform_logo(): string {
    $al = (string)setting('admin_logo', '');
    return ($al !== '' && is_file(media_dir() . '/' . $al)) ? media_url($al) : base_url() . '/admin/assets/img/logo.webp';
}

/** Everything the report shows, for one website and one month ('YYYY-MM'). */
function report_data(array $site, string $ym): array {
    [$from, $to, $label] = report_period($ym);
    $sid = (int)$site['id'];
    $d = ['site' => $site, 'ym' => $ym, 'label' => $label, 'from' => $from, 'to' => $to,
        'days' => (int)date('t', strtotime($from)), 'closed' => strtotime($to) <= time(), 'forms' => [], 'sources' => [], 'campaigns' => []];
    $pf = report_period(date('Y-m', strtotime('-1 month', strtotime($from))));
    $inForms = '(SELECT id FROM forms WHERE site_id=?)';
    $d['prev'] = (int)q("SELECT COUNT(*) FROM entries WHERE is_spam=0 AND form_id IN $inForms AND created_at >= ? AND created_at < ?", [$sid, $pf[0], $pf[1]])->fetchColumn();
    $d['prevLabel'] = date('F', strtotime($pf[0]));
    $d['spam'] = (int)q("SELECT COUNT(*) FROM entries WHERE is_spam=1 AND form_id IN $inForms AND created_at >= ? AND created_at < ?", [$sid, $from, $to])->fetchColumn();
    $forms = q('SELECT * FROM forms WHERE site_id=? ORDER BY name', [$sid])->fetchAll();
    $d['formCount'] = count($forms);
    foreach ($forms as $i => $f) $d['forms'][$f['id']] = ['id' => (int)$f['id'], 'name' => $f['name'], 'color' => REPORT_COLORS[$i % count(REPORT_COLORS)],
        'fields' => array_values(array_filter(form_input_fields($f), fn($x) => !in_array($x['type'], ['signature', 'hidden'], true))), 'rows' => [], 'count' => 0];
    $total = 0; $cap = 5000;
    $st = q("SELECT * FROM entries WHERE is_spam=0 AND form_id IN $inForms AND created_at >= ? AND created_at < ? ORDER BY created_at DESC, id DESC", [$sid, $from, $to]);
    while ($r = $st->fetch()) {
        if (!isset($d['forms'][$r['form_id']])) continue;
        $total++; $F =& $d['forms'][$r['form_id']]; $F['count']++;
        $s = entry_sources($r); $u = $s['utm'];
        $d['sources'][$s['label']] = ($d['sources'][$s['label']] ?? ['n' => 0, 'camp' => []]);
        $d['sources'][$s['label']]['n']++;
        if (!empty($u['utm_campaign'])) { $d['campaigns'][$u['utm_campaign']] = ($d['campaigns'][$u['utm_campaign']] ?? 0) + 1; $d['sources'][$s['label']]['camp'][$u['utm_campaign']] = 1; }
        if ($total <= $cap) {
            $data = json_decode($r['data_json'], true) ?: [];
            $F['rows'][] = ['id' => (int)$r['id'], 'at' => $r['created_at'], 'vals' => $data, 'src' => $s['label'], 'medium' => (string)($u['utm_medium'] ?? ''), 'camp' => (string)($u['utm_campaign'] ?? ''), 'page' => $s['page']];
        }
        unset($F);
    }
    foreach ($d['forms'] as &$F) {   // only show columns that have an answer in at least one lead this month
        $F['fields'] = array_values(array_filter($F['fields'], function ($f) use ($F) { foreach ($F['rows'] as $r) if (trim(flat_value($r['vals'][$f['name']] ?? '')) !== '') return true; return false; }));
    } unset($F);
    $d['total'] = $total;
    uasort($d['sources'], fn($a, $b) => $b['n'] <=> $a['n']); arsort($d['campaigns']);
    return $d;
}

/** The complete HTML document. */
function render_report_page(array $d): string {
    $site = $d['site']; $total = $d['total'];
    $app = (string)setting('site_name', 'TopTen Form');
    $client = (string)$site['name'];
    $logo = report_platform_logo();
    $domains = site_domains($site);
    $pct = fn($n) => $total ? round(100 * $n / $total, 1) : 0;
    $period = date('F j', strtotime($d['from'])) . ' – ' . date('j, Y', strtotime($d['to']) - 86400);
    $reportId = 'RPT-' . str_replace('-', '', $d['ym']) . '-' . str_pad((string)$site['id'], 3, '0', STR_PAD_LEFT);
    $months = [];   // month picker: last 12 months, newest first (the shown month is always included)
    for ($i = 1; $i <= 12; $i++) { $t = strtotime("first day of -$i month"); $months[date('Y-m', $t)] = date('F Y', $t); }
    if (!isset($months[$d['ym']])) $months = [$d['ym'] => $d['label']] + $months;
    $shareUrl = report_share_url((int)$site['id']);
    $forms = $d['forms'];
    $byCount = $forms; uasort($byCount, fn($a, $b) => $b['count'] <=> $a['count']);
    $top = $total ? reset($byCount) : null;
    $srcTop = $d['sources'] ? array_key_first($d['sources']) : null;
    $delta = $d['prev'] > 0 ? round(100 * ($total - $d['prev']) / $d['prev'], 1) : null;
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    ob_start(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex,nofollow">
  <title>Monthly Lead Report - <?= $h($client) ?> - <?= $h($d['label']) ?></title>
  <link rel="icon" href="<?= $h(base_url()) ?>/favicon.ico">
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="https://unpkg.com/lucide@latest"></script>
  <style>
    @media print { .no-print{display:none!important} body{background:#fff!important;font-size:11pt} .shadow-sm,.shadow-md,.shadow-lg,.shadow-xl{box-shadow:none!important} section,.avoid-break{break-inside:avoid} .form-table thead{display:table-header-group} tr{break-inside:avoid} }
    ::-webkit-scrollbar{height:6px;width:6px}::-webkit-scrollbar-track{background:#f1f5f9}::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:9999px}
    .form-table td,.form-table th{vertical-align:top}
    .cell-text{white-space:pre-wrap;overflow-wrap:anywhere;word-break:break-word}
  </style>
</head>
<body class="bg-gradient-to-br from-slate-50 via-indigo-50/30 to-purple-50/40 text-slate-900 antialiased min-h-screen" style="font-family:Inter,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif">

  <header class="no-print bg-white/80 backdrop-blur-md border-b border-indigo-100 sticky top-0 z-50">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-3 flex flex-wrap items-center justify-between gap-4">
      <div class="flex items-center space-x-3">
        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-gradient-to-r from-emerald-500 to-teal-500 text-white"><span class="w-1.5 h-1.5 rounded-full bg-white mr-2"></span>Automated Report</span>
        <span class="text-xs text-slate-500 hidden sm:inline font-medium">Generated: <?= $h(date('M j, Y, g:i a T')) ?></span>
      </div>
      <div class="flex items-center gap-2.5">
        <select onchange="location.href=<?= $h(json_encode(report_share_url((int)$site['id']))) ?>.replace(/&amp;/g,'&')+'&m='+this.value" class="py-2 px-3 text-xs font-semibold rounded-xl text-slate-700 bg-white border border-slate-200 shadow-sm" aria-label="Month"><?php foreach ($months as $mv => $ml): ?><option value="<?= $h($mv) ?>"<?= $mv === $d['ym'] ? ' selected' : '' ?>><?= $h($ml) ?></option><?php endforeach; ?></select>
        <button onclick="window.print()" class="inline-flex items-center px-3.5 py-2 text-xs font-semibold rounded-xl text-slate-700 bg-white border border-slate-200 hover:border-indigo-300 hover:text-indigo-600 shadow-sm"><i data-lucide="printer" class="w-4 h-4 mr-1.5 text-indigo-500"></i>Print / Save PDF</button>
        <button type="button" id="shareBtn" class="inline-flex items-center px-4 py-2 text-xs font-bold rounded-xl text-white bg-gradient-to-r from-indigo-600 via-purple-600 to-pink-500 shadow-md" title="Copy a read-only link to this website's reports"><i data-lucide="share-2" class="w-4 h-4 mr-1.5"></i><span id="shareTxt">Share link</span></button>
      </div>
    </div>
  </header>

  <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 md:py-10">

    <!-- Identity block -->
    <section class="relative bg-white rounded-3xl p-6 sm:p-8 border border-indigo-100 shadow-xl shadow-indigo-500/5 mb-8 overflow-hidden">
      <div class="absolute -top-24 -right-24 w-80 h-80 rounded-full bg-gradient-to-br from-indigo-200/50 via-purple-200/40 to-pink-200/40 blur-3xl pointer-events-none"></div>
      <div class="absolute -bottom-24 -left-24 w-72 h-72 rounded-full bg-gradient-to-tr from-cyan-200/40 to-blue-200/40 blur-3xl pointer-events-none"></div>

      <div class="relative grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_minmax(0,40rem)] gap-8 lg:gap-10 items-center border-b border-indigo-50 pb-8">
        <div>
          <img src="<?= $h($logo) ?>" alt="<?= $h($app) ?>" class="h-14 sm:h-16 w-auto max-w-[16rem] object-contain object-left">
          <div class="flex flex-wrap items-center gap-2 mt-5">
            <span class="text-xs font-extrabold tracking-wider bg-gradient-to-r from-indigo-600 to-pink-600 bg-clip-text text-transparent uppercase">Monthly Performance Digest</span>
            <span class="text-indigo-200">•</span>
            <span class="text-xs font-medium text-slate-500">Previous Calendar Month</span>
          </div>
          <h1 class="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight mt-1">Lead Acquisition Report</h1>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div class="rounded-2xl border border-indigo-100 bg-gradient-to-br from-indigo-50/70 to-white p-5 sm:p-6">
            <p class="text-[11px] font-bold text-indigo-500 uppercase tracking-wider">Client Account</p>
            <p class="text-lg sm:text-xl font-extrabold text-slate-900 mt-1.5 leading-snug break-words"><?= $h($client) ?></p>
            <?php if ($domains): ?><p class="text-xs font-medium text-indigo-600/80 mt-1.5 break-words"><?= $h(implode(' · ', array_slice($domains, 0, 3))) ?></p><?php endif; ?>
            <p class="text-xs text-slate-500 mt-3 pt-3 border-t border-indigo-100"><?= (int)$d['formCount'] ?> web form<?= $d['formCount'] === 1 ? '' : 's' ?> tracked</p>
          </div>
          <div class="rounded-2xl border border-pink-100 bg-gradient-to-br from-pink-50/70 to-white p-5 sm:p-6">
            <p class="text-[11px] font-bold text-pink-500 uppercase tracking-wider">Reporting Period</p>
            <p class="text-lg sm:text-xl font-extrabold text-slate-900 mt-1.5 leading-snug"><?= $h($period) ?></p>
            <p class="text-xs font-medium text-pink-600/80 mt-1.5"><?= (int)$d['days'] ?> days</p>
            <div class="mt-3 pt-3 border-t border-pink-100">
              <?php if ($d['closed']): ?><span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800"><span class="w-1.5 h-1.5 rounded-full bg-emerald-500 mr-1.5"></span>Closed Cycle</span>
              <?php else: ?><span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800"><span class="w-1.5 h-1.5 rounded-full bg-amber-500 mr-1.5"></span>Month in progress</span><?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <div class="relative pt-6 flex flex-wrap items-center justify-between text-xs text-slate-600 gap-y-2">
        <div class="flex flex-wrap items-center gap-4 sm:gap-6">
          <div class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-indigo-500"></span><span><strong class="text-slate-900">Total Days:</strong> <?= (int)$d['days'] ?> Days</span></div>
          <div class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-purple-500"></span><span><strong class="text-slate-900">Web Forms:</strong> <?= (int)$d['formCount'] ?></span></div>
          <div class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-pink-500"></span><span><strong class="text-slate-900">Tracked Campaigns:</strong> <?= count($d['campaigns']) ?></span></div>
        </div>
        <div class="inline-flex items-center gap-1 text-slate-500 font-medium"><i data-lucide="badge-check" class="w-3.5 h-3.5 text-indigo-500"></i>Generated by <?= $h($app) ?></div>
      </div>
    </section>

    <!-- KPI cards -->
    <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">
      <div class="bg-gradient-to-br from-indigo-500 via-indigo-600 to-purple-600 text-white rounded-3xl p-6 shadow-lg shadow-indigo-500/20 relative overflow-hidden">
        <div class="flex items-center justify-between text-indigo-100 mb-3"><span class="text-xs font-bold uppercase tracking-wider">Total Leads</span><div class="w-10 h-10 rounded-xl bg-white/20 flex items-center justify-center"><i data-lucide="users" class="w-5 h-5"></i></div></div>
        <div class="flex items-baseline gap-2"><span class="text-4xl font-black"><?= $total ?></span>
          <?php if ($delta !== null): ?><span class="inline-flex items-center text-xs font-extrabold <?= $delta >= 0 ? 'text-emerald-300' : 'text-rose-200' ?> bg-black/20 px-2 py-0.5 rounded-full"><?= $delta >= 0 ? '+' : '' ?><?= $h($delta) ?>%</span><?php endif; ?></div>
        <p class="text-xs text-indigo-100/90 mt-2 font-medium">vs. <?= (int)$d['prev'] ?> lead<?= $d['prev'] === 1 ? '' : 's' ?> in <?= $h($d['prevLabel']) ?></p>
      </div>
      <div class="bg-gradient-to-br from-purple-500 via-pink-500 to-rose-500 text-white rounded-3xl p-6 shadow-lg shadow-pink-500/20 relative overflow-hidden">
        <div class="flex items-center justify-between text-pink-100 mb-3"><span class="text-xs font-bold uppercase tracking-wider">Top Converting Form</span><div class="w-10 h-10 rounded-xl bg-white/20 flex items-center justify-center"><i data-lucide="file-text" class="w-5 h-5"></i></div></div>
        <div class="text-2xl font-black leading-tight break-words"><?= $top ? $h($top['name']) : '—' ?></div>
        <p class="text-xs text-pink-100/90 mt-2 font-medium"><?= $top ? (int)$top['count'] . ' submission' . ($top['count'] === 1 ? '' : 's') . ' (' . $h($pct($top['count'])) . '% share)' : 'No submissions this month' ?></p>
      </div>
      <div class="bg-gradient-to-br from-amber-500 via-orange-500 to-red-500 text-white rounded-3xl p-6 shadow-lg shadow-orange-500/20 relative overflow-hidden">
        <div class="flex items-center justify-between text-amber-100 mb-3"><span class="text-xs font-bold uppercase tracking-wider">Leading Channel</span><div class="w-10 h-10 rounded-xl bg-white/20 flex items-center justify-center"><i data-lucide="target" class="w-5 h-5"></i></div></div>
        <div class="text-2xl font-black leading-tight break-words"><?= $srcTop !== null ? $h($srcTop) : '—' ?></div>
        <p class="text-xs text-amber-100/90 mt-2 font-medium"><?= $srcTop !== null ? (int)$d['sources'][$srcTop]['n'] . ' lead' . ($d['sources'][$srcTop]['n'] === 1 ? '' : 's') . ' (' . $h($pct($d['sources'][$srcTop]['n'])) . '%)' : 'No source data' ?></p>
      </div>
      <div class="bg-gradient-to-br from-teal-500 via-emerald-500 to-green-600 text-white rounded-3xl p-6 shadow-lg shadow-emerald-500/20 relative overflow-hidden">
        <div class="flex items-center justify-between text-teal-100 mb-3"><span class="text-xs font-bold uppercase tracking-wider">Spam Blocked</span><div class="w-10 h-10 rounded-xl bg-white/20 flex items-center justify-center"><i data-lucide="shield-check" class="w-5 h-5"></i></div></div>
        <div class="text-4xl font-black"><?= (int)$d['spam'] ?></div>
        <p class="text-xs text-teal-100/90 mt-2 font-medium">Filtered out and not counted as leads</p>
      </div>
    </section>

    <!-- Form breakdown + channels -->
    <section class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-8">
      <div class="lg:col-span-2 bg-white rounded-3xl p-6 sm:p-7 border border-indigo-100 shadow-lg shadow-indigo-500/5">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between pb-4 mb-6 border-b border-indigo-50 gap-2">
          <div><h2 class="text-lg font-bold text-slate-900 flex items-center gap-2"><span class="w-3 h-3 rounded-full bg-gradient-to-r from-indigo-500 to-purple-500"></span>Leads by Form Breakdown</h2>
            <p class="text-xs text-slate-500">Comparison of submissions by each web form</p></div>
          <span class="text-xs font-bold text-indigo-700 bg-indigo-50 border border-indigo-200 px-3 py-1 rounded-full self-start sm:self-auto"><?= (int)$d['formCount'] ?> Form<?= $d['formCount'] === 1 ? '' : 's' ?></span>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 items-center">
          <div class="relative h-60 w-full flex items-center justify-center"><?= $total ? '<canvas id="formDoughnutChart"></canvas>' : '<p class="text-sm text-slate-400">No leads this month</p>' ?></div>
          <div class="space-y-3">
            <?php foreach ($byCount as $F): $p = $pct($F['count']); ?>
            <div class="p-3 rounded-2xl border border-slate-100" style="background:<?= $h($F['color']) ?>0d">
              <div class="flex justify-between gap-3 text-xs font-bold mb-1.5">
                <span class="flex items-center gap-2 text-slate-900 min-w-0"><span class="w-3 h-3 rounded-md shrink-0" style="background:<?= $h($F['color']) ?>"></span><span class="break-words"><?= $h($F['name']) ?></span></span>
                <span class="font-extrabold text-slate-900 whitespace-nowrap"><?= (int)$F['count'] ?> <span class="font-medium text-slate-500">(<?= $h($p) ?>%)</span></span>
              </div>
              <div class="w-full rounded-full h-2.5 overflow-hidden" style="background:<?= $h($F['color']) ?>26"><div class="h-2.5 rounded-full" style="width:<?= $h($p) ?>%;background:<?= $h($F['color']) ?>"></div></div>
            </div>
            <?php endforeach; if (!$forms): ?><p class="text-sm text-slate-400">No forms yet.</p><?php endif; ?>
          </div>
        </div>
      </div>

      <div class="bg-white rounded-3xl p-6 sm:p-7 border border-indigo-100 shadow-lg shadow-indigo-500/5">
        <div class="pb-4 mb-4 border-b border-indigo-50"><h2 class="text-lg font-bold text-slate-900 flex items-center gap-2"><span class="w-3 h-3 rounded-full bg-gradient-to-r from-amber-500 to-red-500"></span>Traffic Source / Channel</h2>
          <p class="text-xs text-slate-500">Where the leads came from</p></div>
        <ul class="space-y-3 text-xs">
          <?php $i = 0; foreach (array_slice($d['sources'], 0, 8, true) as $label => $s): $c = REPORT_COLORS[$i++ % count(REPORT_COLORS)]; $camps = array_keys($s['camp']); ?>
          <li class="flex items-center justify-between gap-3 p-3 rounded-2xl border border-slate-100" style="background:<?= $h($c) ?>0d">
            <div class="flex items-center space-x-3 min-w-0">
              <div class="w-7 h-7 rounded-lg text-white flex items-center justify-center font-bold text-xs shrink-0" style="background:<?= $h($c) ?>"><?= $h(mb_strtoupper(mb_substr($label, 0, 1))) ?></div>
              <div class="min-w-0"><p class="font-bold text-slate-900 break-words"><?= $h($label) ?></p>
                <?php if ($camps): ?><p class="text-[10px] text-slate-500 font-medium break-words"><?= $h(implode(', ', array_slice($camps, 0, 2))) ?></p><?php endif; ?></div>
            </div>
            <div class="text-right shrink-0"><p class="font-extrabold text-slate-900"><?= (int)$s['n'] ?></p><span class="inline-block px-1.5 py-0.5 rounded text-[10px] font-bold" style="background:<?= $h($c) ?>26;color:#334155"><?= $h($pct($s['n'])) ?>%</span></div>
          </li>
          <?php endforeach; if (!$d['sources']): ?><li class="text-sm text-slate-400">No source data.</li><?php endif; ?>
        </ul>
      </div>
    </section>

    <!-- One table per form -->
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
      <div><h2 class="text-xl font-bold text-slate-900">Lead Details by Form</h2><p class="text-xs text-slate-500">Every submission with its full answers, source and campaign</p></div>
      <div class="relative no-print"><i data-lucide="search" class="w-4 h-4 text-indigo-400 absolute left-3 top-2.5"></i>
        <input type="text" id="searchInput" onkeyup="filterTables()" placeholder="Search leads, email, message..." class="pl-9 pr-4 py-2 text-xs rounded-xl border border-indigo-200/80 bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 w-56 sm:w-72"></div>
    </div>

    <?php foreach ($forms as $F): if (!$F['count']) continue; ?>
    <section class="form-section bg-white rounded-3xl border border-indigo-100 shadow-lg shadow-indigo-500/5 overflow-hidden mb-8">
      <div class="p-5 sm:p-6 border-b border-indigo-50 flex flex-wrap items-center justify-between gap-3" style="background:linear-gradient(90deg,<?= $h($F['color']) ?>14,#ffffff)">
        <h3 class="text-base sm:text-lg font-bold text-slate-900 flex items-center gap-2.5"><span class="w-3 h-3 rounded-full" style="background:<?= $h($F['color']) ?>"></span><?= $h($F['name']) ?></h3>
        <span class="text-xs font-bold px-3 py-1 rounded-full border" style="color:<?= $h($F['color']) ?>;border-color:<?= $h($F['color']) ?>55;background:<?= $h($F['color']) ?>14"><span class="form-visible"><?= (int)$F['count'] ?></span> lead<?= $F['count'] === 1 ? '' : 's' ?> &middot; <?= $h($pct($F['count'])) ?>% of total</span>
      </div>
      <div class="overflow-x-auto">
        <table class="form-table w-full text-left border-collapse text-xs">
          <thead><tr class="bg-slate-50 border-b border-indigo-100 text-slate-700 font-bold uppercase text-[10px] tracking-wider">
            <th class="py-3.5 px-4 whitespace-nowrap">Submitted</th>
            <?php foreach ($F['fields'] as $f): ?><th class="py-3.5 px-4 <?= $f['type'] === 'textarea' ? 'min-w-[260px]' : 'min-w-[110px]' ?>"><?= $h($f['type'] === 'consent' ? 'Consent' : strip_tags($f['label'])) ?></th><?php endforeach; ?>
            <th class="py-3.5 px-4 whitespace-nowrap">Source / Medium</th>
            <th class="py-3.5 px-4 whitespace-nowrap">Campaign</th>
          </tr></thead>
          <tbody class="divide-y divide-indigo-50/70 text-slate-700">
            <?php foreach ($F['rows'] as $r): $t = strtotime($r['at']); ?>
            <tr class="lead-row hover:bg-indigo-50/40">
              <td class="py-4 px-4 whitespace-nowrap font-semibold text-slate-900"><span class="inline-block w-2 h-2 rounded-full mr-1.5" style="background:<?= $h($F['color']) ?>"></span><?= $h(date('M j, Y', $t)) ?><span class="block text-[10px] text-slate-400 font-normal ml-3.5"><?= $h(date('g:i a', $t)) ?> &middot; #<?= (int)$r['id'] ?></span></td>
              <?php foreach ($F['fields'] as $f): $v = trim(flat_value($r['vals'][$f['name']] ?? '')); ?>
              <td class="py-4 px-4 cell-text"><?php if ($v === ''): ?><span class="text-slate-300">—</span><?php elseif ($f['type'] === 'email'): ?><a href="mailto:<?= $h($v) ?>" class="text-indigo-600 font-medium"><?= $h($v) ?></a><?php else: ?><?= $h($v) ?><?php endif; ?></td>
              <?php endforeach; ?>
              <td class="py-4 px-4"><span class="inline-flex px-2 py-0.5 rounded-md font-bold text-slate-700 bg-slate-100 border border-slate-200 text-[11px]"><?= $h($r['src']) ?><?= $r['medium'] !== '' ? ' / ' . $h($r['medium']) : '' ?></span></td>
              <td class="py-4 px-4 cell-text"><?= $r['camp'] !== '' ? '<code class="px-2 py-0.5 bg-slate-100 text-slate-700 rounded-md font-mono text-[10px] border border-slate-200">' . $h($r['camp']) . '</code>' : '<span class="text-slate-300">—</span>' ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($F['count'] > count($F['rows'])): ?><div class="p-3 text-center text-xs text-slate-500 border-t border-indigo-50">Showing the latest <?= count($F['rows']) ?> of <?= (int)$F['count'] ?>. The first 5,000 leads of the month are listed.</div><?php endif; ?>
    </section>
    <?php endforeach; if (!$total): ?>
    <section class="bg-white rounded-3xl border border-indigo-100 p-10 text-center text-slate-500 mb-8">No leads were received during this period.</section>
    <?php endif; ?>
    <p id="noMatch" class="hidden text-center text-sm text-slate-500 mb-8">No leads match your search.</p>

    <!-- Insights + data notes -->
    <section class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-10">
      <div class="bg-gradient-to-br from-indigo-500/10 via-purple-500/10 to-pink-500/10 border border-indigo-200/80 rounded-3xl p-6">
        <div class="flex items-center gap-2 mb-2 text-indigo-950 font-bold text-sm"><div class="w-7 h-7 rounded-lg bg-gradient-to-r from-indigo-600 to-pink-600 text-white flex items-center justify-center"><i data-lucide="sparkles" class="w-4 h-4"></i></div>Highlights</div>
        <ul class="text-xs text-slate-700 leading-relaxed space-y-1.5 list-disc pl-4">
          <li><strong><?= $total ?></strong> lead<?= $total === 1 ? '' : 's' ?> received in <?= $h($d['label']) ?><?= $delta !== null ? ', ' . ($delta >= 0 ? 'up ' : 'down ') . $h(abs($delta)) . '% from ' . $h($d['prevLabel']) : '' ?>.</li>
          <?php if ($top): ?><li><strong><?= $h($top['name']) ?></strong> was the strongest form with <?= (int)$top['count'] ?> lead<?= $top['count'] === 1 ? '' : 's' ?> (<?= $h($pct($top['count'])) ?>% of the total).</li><?php endif; ?>
          <?php if ($srcTop !== null): ?><li><strong><?= $h($srcTop) ?></strong> was the leading source<?= $d['campaigns'] ? ', and the top campaign was <strong>' . $h(array_key_first($d['campaigns'])) . '</strong>' : '' ?>.</li><?php endif; ?>
        </ul>
      </div>
      <div class="bg-gradient-to-br from-emerald-500/10 via-teal-500/10 to-cyan-500/10 border border-emerald-200/80 rounded-3xl p-6">
        <div class="flex items-center gap-2 mb-2 text-emerald-950 font-bold text-sm"><div class="w-7 h-7 rounded-lg bg-gradient-to-r from-emerald-600 to-teal-500 text-white flex items-center justify-center"><i data-lucide="shield-check" class="w-4 h-4"></i></div>Data Notes</div>
        <p class="text-xs text-slate-700 leading-relaxed"><?= (int)$d['spam'] ?> submission<?= $d['spam'] === 1 ? ' was' : 's were' ?> identified as spam and excluded. Times are shown in <?= $h(date('T')) ?> (<?= $h(date_default_timezone_get()) ?>). Source and campaign come from the visitor's referrer and UTM tags recorded when the form was submitted; “Direct” means none was available.</p>
      </div>
    </section>

    <footer class="border-t border-indigo-100 pt-8 pb-12 flex flex-col lg:flex-row items-center justify-between text-xs text-slate-500 gap-4 text-center lg:text-left">
      <p>© <?= date('Y') ?> <?= $h($app) ?> &mdash; automated lead reporting. All rights reserved.</p>
      <div class="flex flex-col sm:flex-row items-center gap-2 sm:gap-6">
        <span class="font-medium text-indigo-600">Confidential • Prepared for <?= $h($client) ?></span>
        <span class="font-mono text-slate-400">Report ID: <?= $h($reportId) ?></span>
        <span class="text-slate-400">Powered by <?= $h($app) ?> v<?= $h(TF_VERSION) ?></span>
      </div>
    </footer>
  </main>

  <script>
    if (window.lucide) lucide.createIcons();
    (function () {
      var b = document.getElementById('shareBtn'), t = document.getElementById('shareTxt'), url = <?= json_encode($shareUrl) ?>;
      if (!b) return;
      b.addEventListener('click', function () {
        function done() { t.textContent = 'Link copied!'; setTimeout(function () { t.textContent = 'Share link'; }, 2200); }
        if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(url).then(done, function () { window.prompt('Copy this read-only link:', url); });
        else { var i = document.createElement('input'); i.value = url; document.body.appendChild(i); i.select(); try { document.execCommand('copy'); done(); } catch (e) { window.prompt('Copy this read-only link:', url); } document.body.removeChild(i); }
      });
    })();
    <?php if ($total): ?>
    window.addEventListener('DOMContentLoaded', function () {
      var el = document.getElementById('formDoughnutChart'); if (!el || !window.Chart) return;
      var data = <?= json_encode(array_values(array_map(fn($F) => ['n' => $F['name'], 'v' => $F['count'], 'c' => $F['color']], array_filter($forms, fn($F) => $F['count']))), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
      new Chart(el.getContext('2d'), { type: 'doughnut', data: { labels: data.map(function (x) { return x.n; }), datasets: [{ data: data.map(function (x) { return x.v; }), backgroundColor: data.map(function (x) { return x.c; }), borderWidth: 3, borderColor: '#fff', hoverOffset: 8 }] },
        options: { responsive: true, maintainAspectRatio: false, cutout: '70%', plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return ' ' + c.label + ': ' + c.raw + ' leads (' + (c.raw / <?= $total ?> * 100).toFixed(1) + '%)'; } } } } } });
    });
    <?php endif; ?>
    function filterTables() {
      var q = document.getElementById('searchInput').value.toLowerCase(), any = false;
      document.querySelectorAll('.form-section').forEach(function (sec) {
        var n = 0;
        sec.querySelectorAll('.lead-row').forEach(function (r) { var ok = r.innerText.toLowerCase().indexOf(q) !== -1; r.style.display = ok ? '' : 'none'; if (ok) n++; });
        sec.querySelector('.form-visible').textContent = n; sec.style.display = n ? '' : 'none'; if (n) any = true;
      });
      document.getElementById('noMatch').classList.toggle('hidden', any || q === '');
    }
  </script>
</body>
</html>
<?php return ob_get_clean();
}
