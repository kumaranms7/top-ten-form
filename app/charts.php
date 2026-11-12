<?php
/** Dependency-free SVG/HTML charts for the dashboard (nothing extra to download, works offline). */

const CHART_COLORS = ['#6366f1', '#ec4899', '#f97316', '#10b981', '#0ea5e9', '#8b5cf6', '#f59e0b', '#14b8a6'];

/** Round a maximum up to a "nice" axis value. */
function chart_nice_max(float $v): int {
    if ($v <= 4) return 4;
    $p = 10 ** floor(log10($v)); $n = $v / $p;
    $m = $n <= 1 ? 1 : ($n <= 2 ? 2 : ($n <= 5 ? 5 : 10));
    return (int)($m * $p);
}

/** Area/line chart. $points = [[label, value], ...] */
function svg_area_chart(array $points, string $color = '#6366f1', int $w = 820, int $h = 260): string {
    $n = count($points); if ($n < 2) return '<p class="muted">Not enough data yet.</p>';
    $padL = 38; $padR = 14; $padT = 14; $padB = 30; $iw = $w - $padL - $padR; $ih = $h - $padT - $padB;
    $max = chart_nice_max((float)max(array_column($points, 1)) ?: 1);
    $x = fn($i) => $padL + $iw * $i / ($n - 1);
    $y = fn($v) => $padT + $ih - $ih * $v / $max;
    $id = 'g' . substr(md5($color . $n), 0, 6);
    $grid = ''; foreach ([0, .25, .5, .75, 1] as $f) { $gy = $padT + $ih * (1 - $f); $grid .= '<line x1="' . $padL . '" y1="' . $gy . '" x2="' . ($w - $padR) . '" y2="' . $gy . '" stroke="#e8eaf5" stroke-dasharray="3 4"/><text x="' . ($padL - 8) . '" y="' . ($gy + 4) . '" text-anchor="end" font-size="11" fill="#8a90a8">' . round($max * $f) . '</text>'; }
    $line = ''; $dots = ''; $labels = '';
    foreach ($points as $i => [$lab, $v]) {
        $line .= ($i ? 'L' : 'M') . round($x($i), 1) . ' ' . round($y($v), 1) . ' ';
        $dots .= '<circle cx="' . round($x($i), 1) . '" cy="' . round($y($v), 1) . '" r="' . ($n <= 31 ? 3.6 : 2.2) . '" fill="#fff" stroke="' . $color . '" stroke-width="2"><title>' . e($lab) . ': ' . (int)$v . '</title></circle>';
        $step = max(1, (int)ceil($n / 8));
        if (($i % $step === 0 && ($n - 1 - $i) >= $step * 0.7) || $i === $n - 1) $labels .= '<text x="' . round($x($i), 1) . '" y="' . ($h - 8) . '" text-anchor="' . ($i === 0 ? 'start' : ($i === $n - 1 ? 'end' : 'middle')) . '" font-size="11" fill="#8a90a8">' . e($lab) . '</text>';
    }
    $area = $line . 'L' . round($x($n - 1), 1) . ' ' . ($padT + $ih) . ' L' . $padL . ' ' . ($padT + $ih) . ' Z';
    return '<svg viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="Entries over time" class="chart"><defs><linearGradient id="' . $id . '" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="' . $color . '" stop-opacity=".35"/><stop offset="1" stop-color="' . $color . '" stop-opacity="0"/></linearGradient></defs>'
        . $grid . '<path d="' . $area . '" fill="url(#' . $id . ')"/><path d="' . trim($line) . '" fill="none" stroke="' . $color . '" stroke-width="2.6" stroke-linejoin="round" stroke-linecap="round"/>' . $dots . $labels . '</svg>';
}

/** Donut chart + legend. $items = [[label, value], ...] */
function svg_donut(array $items, int $size = 190): string {
    $total = array_sum(array_column($items, 1)); if ($total <= 0) return '<p class="muted">No data yet.</p>';
    $r = 70; $c = 2 * M_PI * $r; $off = 0; $arcs = ''; $legend = '';
    foreach ($items as $i => [$lab, $v]) {
        $col = CHART_COLORS[$i % count(CHART_COLORS)]; $len = $c * $v / $total;
        $arcs .= '<circle cx="95" cy="95" r="' . $r . '" fill="none" stroke="' . $col . '" stroke-width="26" stroke-dasharray="' . round($len, 2) . ' ' . round($c - $len, 2) . '" stroke-dashoffset="' . round(-$off, 2) . '" transform="rotate(-90 95 95)"><title>' . e($lab) . ': ' . (int)$v . '</title></circle>';
        $off += $len;
        $legend .= '<li><span class="dot" style="background:' . $col . '"></span><span class="lab">' . e($lab) . '</span><b>' . (int)$v . '</b><small>' . round(100 * $v / $total) . '%</small></li>';
    }
    return '<div class="donut"><svg viewBox="0 0 190 190" width="' . $size . '" height="' . $size . '" role="img" aria-label="Breakdown">' . $arcs . '<text x="95" y="92" text-anchor="middle" font-size="26" font-weight="700" fill="#14172e">' . (int)$total . '</text><text x="95" y="112" text-anchor="middle" font-size="11" fill="#8a90a8">entries</text></svg><ul class="legend">' . $legend . '</ul></div>';
}

/** Horizontal bar list. $items = [[label, value, ?href], ...] */
function html_bars(array $items): string {
    if (!$items) return '<p class="muted">No data yet.</p>';
    $max = max(array_column($items, 1)) ?: 1; $h = '<ul class="bars">';
    foreach ($items as $i => $it) {
        [$lab, $v] = $it; $col = CHART_COLORS[$i % count(CHART_COLORS)];
        $name = !empty($it[2]) ? '<a href="' . e($it[2]) . '">' . e($lab) . '</a>' : e($lab);
        $h .= '<li><div class="bl">' . $name . '<b>' . (int)$v . '</b></div><div class="bt"><span style="width:' . max(3, round(100 * $v / $max)) . '%;background:' . $col . '"></span></div></li>';
    }
    return $h . '</ul>';
}

/** Single stacked bar with legend (used for lead status). */
function html_stacked(array $items): string {
    $total = array_sum(array_column($items, 1)); if ($total <= 0) return '<p class="muted">No data yet.</p>';
    $bar = ''; $leg = '';
    foreach ($items as [$lab, $v, $col]) {
        if ($v > 0) $bar .= '<span style="width:' . (100 * $v / $total) . '%;background:' . $col . '" title="' . e($lab) . ': ' . (int)$v . '"></span>';
        $leg .= '<li><span class="dot" style="background:' . $col . '"></span>' . e($lab) . ' <b>' . (int)$v . '</b></li>';
    }
    return '<div class="stack">' . $bar . '</div><ul class="legend row-legend">' . $leg . '</ul>';
}
