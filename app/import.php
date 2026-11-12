<?php
/** Import entries from CSV or Excel (.xlsx). Imported entries never send emails or webhooks and are never treated as spam. */

const IMPORT_MAX_ROWS = 10000;
const IMPORT_MAX_BYTES = 8388608;

/** Read a CSV/XLSX file into rows (first row = headers). Returns [rows|null, error]. */
function import_read(string $path, string $ext): array {
    if (filesize($path) > IMPORT_MAX_BYTES) return [null, 'The file is larger than 8 MB.'];
    $rows = $ext === 'xlsx' ? import_read_xlsx($path) : import_read_csv($path);
    if (is_string($rows)) return [null, $rows];
    $rows = array_values(array_filter($rows, fn($r) => array_filter($r, fn($c) => trim((string)$c) !== '')));
    if (count($rows) < 2) return [null, 'The file needs a header row and at least one data row.'];
    if (count($rows) - 1 > IMPORT_MAX_ROWS) return [null, 'A single import is limited to ' . IMPORT_MAX_ROWS . ' rows. Split the file and import it in parts.'];
    return [$rows, ''];
}

function import_read_csv(string $path) {
    $raw = (string)file_get_contents($path);
    if (substr($raw, 0, 3) === "\xEF\xBB\xBF") $raw = substr($raw, 3);
    if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    $first = strtok($raw, "\n") ?: '';
    $best = ','; $max = -1;
    foreach ([',', ';', "\t", '|'] as $d) { $c = substr_count($first, $d); if ($c > $max) { $max = $c; $best = $d; } }
    $h = fopen('php://temp', 'r+'); fwrite($h, $raw); rewind($h);
    $rows = [];
    while (($r = fgetcsv($h, 0, $best, '"', '\\')) !== false) { if ($r === [null]) continue; $rows[] = array_map(fn($c) => (string)$c, $r); if (count($rows) > IMPORT_MAX_ROWS + 5) break; }
    fclose($h);
    return $rows;
}

function import_xlsx_col(string $ref): int { // "C5" -> 2
    preg_match('/^([A-Z]+)/', $ref, $m); $n = 0;
    foreach (str_split($m[1] ?? 'A') as $ch) $n = $n * 26 + (ord($ch) - 64);
    return $n - 1;
}

function import_read_xlsx(string $path) {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return 'That is not a valid Excel (.xlsx) file.';
    $shared = [];
    if (($ss = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $x = @simplexml_load_string($ss, 'SimpleXMLElement', LIBXML_NONET);
        if ($x) foreach ($x->si as $si) { $t = ''; if (isset($si->t)) $t = (string)$si->t; else foreach ($si->r as $r) $t .= (string)$r->t; $shared[] = $t; }
    }
    $sheet = null;
    for ($i = 0; $i < $zip->numFiles; $i++) { $n = $zip->getNameIndex($i); if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $n) && ($sheet === null || strnatcmp($n, $sheet) < 0)) $sheet = $n; }
    if ($sheet === null) { $zip->close(); return 'No worksheet found in the Excel file.'; }
    $xml = $zip->getFromName($sheet); $zip->close();
    $x = @simplexml_load_string((string)$xml, 'SimpleXMLElement', LIBXML_NONET);
    if (!$x) return 'The worksheet could not be read.';
    $rows = [];
    foreach ($x->sheetData->row as $row) {
        $line = [];
        foreach ($row->c as $c) {
            $col = import_xlsx_col((string)$c['r']); $t = (string)$c['t']; $v = '';
            if ($t === 's') $v = $shared[(int)$c->v] ?? '';
            elseif ($t === 'inlineStr') { $v = isset($c->is->t) ? (string)$c->is->t : ''; if ($v === '' && isset($c->is->r)) foreach ($c->is->r as $r) $v .= (string)$r->t; }
            elseif ($t === 'b') $v = ((string)$c->v) === '1' ? 'Yes' : 'No';
            else $v = (string)$c->v;
            $line[$col] = $v;
        }
        if ($line) { $max = max(array_keys($line)); $out = []; for ($i = 0; $i <= $max; $i++) $out[] = $line[$i] ?? ''; $rows[] = $out; }
        if (count($rows) > IMPORT_MAX_ROWS + 5) break;
    }
    return $rows;
}

function import_norm(string $s): string { return preg_replace('/[^a-z0-9]+/', '', mb_strtolower($s)); }

/** Suggest where each column goes: 'f:<field name>', 'meta:<what>' or '' (skip). */
function import_automap(array $headers, array $fields): array {
    $byLabel = []; foreach ($fields as $f) { $byLabel[import_norm($f['label'])] = 'f:' . $f['name']; $byLabel[import_norm($f['name'])] = 'f:' . $f['name']; }
    $meta = ['date' => 'meta:created_at', 'datecreated' => 'meta:created_at', 'created' => 'meta:created_at', 'createdat' => 'meta:created_at', 'submitted' => 'meta:created_at', 'submittedat' => 'meta:created_at',
        'status' => 'meta:status', 'ip' => 'meta:ip', 'ipaddress' => 'meta:ip', 'pageurl' => 'meta:page_url', 'page' => 'meta:page_url', 'sourcepage' => 'meta:source_page', 'sourcepageurl' => 'meta:source_page',
        'source' => 'meta:utm_source', 'utmsource' => 'meta:utm_source', 'campaign' => 'meta:utm_campaign', 'utmcampaign' => 'meta:utm_campaign', 'medium' => 'meta:utm_medium', 'utmmedium' => 'meta:utm_medium'];
    $used = []; $out = [];
    foreach ($headers as $i => $h) {
        $k = import_norm((string)$h); $m = '';
        if (in_array($k, ['id', 'form', 'assignedto', ''], true)) $m = '';
        elseif (isset($byLabel[$k]) && !isset($used[$byLabel[$k]])) $m = $byLabel[$k];
        elseif (isset($meta[$k]) && !isset($used[$meta[$k]])) $m = $meta[$k];
        if ($m !== '') $used[$m] = 1;
        $out[$i] = $m;
    }
    return $out;
}

/** Convert a cell to a MySQL datetime in the app's timezone (accepts text dates and Excel serial numbers). */
function import_date(string $v): ?string {
    $v = trim($v); if ($v === '') return null;
    if (is_numeric($v) && (float)$v > 20000 && (float)$v < 80000) { $ts = (int)round(((float)$v - 25569) * 86400); return date('Y-m-d H:i:s', $ts); }
    $ts = strtotime($v);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

/** Status text -> database value (or null when the cell is empty / unknown). */
function import_status(string $v): ?string {
    $k = import_norm($v); if ($k === '') return null;
    foreach (['new' => 'new', 'contacted' => 'contacted', 'won' => 'won', 'lost' => 'lost', 'nostatus' => '', 'none' => '', 'empty' => ''] as $name => $db) if ($k === $name) return $db;
    return null;
}

/**
 * Insert entries. $map = [columnIndex => 'f:name' | 'meta:x' | ''], $opts = [default_status, mark_read, skip_dupes].
 * @return array ['imported', 'empty', 'dupes']
 */
function import_rows(array $form, array $rows, array $map, array $opts): array {
    $fields = []; foreach (form_input_fields($form) as $f) $fields[$f['name']] = $f;
    $res = ['imported' => 0, 'empty' => 0, 'dupes' => 0];
    $defStatus = status_to_db((string)($opts['default_status'] ?? 'none')) ?? '';
    $mapped = []; foreach ($map as $t) if (strpos($t, 'f:') === 0 && isset($fields[substr($t, 2)])) $mapped[] = substr($t, 2);
    for ($r = 1; $r < count($rows); $r++) {
        $row = $rows[$r]; $data = []; $meta = [];
        foreach ($map as $i => $target) {
            if ($target === '') continue; $v = trim((string)($row[$i] ?? ''));
            if (strpos($target, 'f:') === 0) {
                $name = substr($target, 2); if (!isset($fields[$name]) || in_array($fields[$name]['type'], ['file', 'signature'], true)) continue;
                $type = $fields[$name]['type'];
                if ($type === 'checkbox') $data[$name] = $v === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $v)), 'strlen'));
                else $data[$name] = mb_substr($v, 0, 10000);
            } else $meta[substr($target, 5)] = $v;
        }
        if (!array_filter($data, fn($v) => $v !== '' && $v !== [])) { $res['empty']++; continue; }
        foreach ($fields as $n => $f) if (!array_key_exists($n, $data)) $data[$n] = $f['type'] === 'checkbox' ? [] : '';
        $created = import_date($meta['created_at'] ?? '') ?: date('Y-m-d H:i:s');
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        if (!empty($opts['skip_dupes'])) { // same date and same answers in every column the file has
            $dupe = false;
            foreach (q('SELECT data_json FROM entries WHERE form_id=? AND created_at=?', [$form['id'], $created])->fetchAll() as $cand) {
                $old = json_decode($cand['data_json'], true) ?: []; $same = true;
                foreach ($mapped as $n) if (flat_value($old[$n] ?? '') !== flat_value($data[$n] ?? '')) { $same = false; break; }
                if ($same) { $dupe = true; break; }
            }
            if ($dupe) { $res['dupes']++; continue; }
        }
        $status = isset($meta['status']) ? (import_status($meta['status']) ?? $defStatus) : $defStatus;
        // the exported "Source" column is a derived label; only a real source name should become the campaign source
        if (in_array(mb_strtolower(trim($meta['utm_source'] ?? '')), ['direct', 'own site (internal page)', 'internal'], true)) $meta['utm_source'] = '';
        $utm = array_filter(['utm_source' => $meta['utm_source'] ?? '', 'utm_campaign' => $meta['utm_campaign'] ?? '', 'utm_medium' => $meta['utm_medium'] ?? '']);
        $page = $meta['page_url'] ?? ''; $src = $meta['source_page'] ?? '';
        q('INSERT INTO entries (form_id,data_json,ip,user_agent,page_url,is_read,is_spam,status,utm_json,source_page,created_at) VALUES (?,?,?,?,?,?,0,?,?,?,?)',
            [$form['id'], $json, mb_substr($meta['ip'] ?? '', 0, 64), 'Imported', preg_match('#^https?://#i', $page) ? mb_substr($page, 0, 500) : null, !empty($opts['mark_read']) ? 1 : 0, $status,
             $utm ? json_encode($utm, JSON_UNESCAPED_UNICODE) : null, preg_match('#^https?://#i', $src) ? mb_substr($src, 0, 500) : null, $created]);
        $res['imported']++;
    }
    return $res;
}

/** A CSV with the right headers (and one example row) for a form. */
function import_template_csv(array $form): string {
    $fields = form_input_fields($form);
    $head = array_merge(['Date', 'Status'], array_map(fn($f) => $f['label'], array_filter($fields, fn($f) => !in_array($f['type'], ['file', 'signature'], true))), ['IP', 'Page URL', 'Source page', 'Source', 'Campaign', 'Medium']);
    $ex = [date('Y-m-d H:i:s'), 'No status'];
    foreach ($fields as $f) { if (in_array($f['type'], ['file', 'signature'], true)) continue; $ex[] = $f['type'] === 'email' ? 'jane@example.com' : ($f['type'] === 'tel' ? '(555) 123-4567' : ($f['type'] === 'checkbox' ? 'Option 1, Option 2' : 'Example')); }
    array_push($ex, '203.0.113.10', 'https://www.example.com/contact', 'https://www.example.com/services', 'google', 'spring-sale', 'cpc');
    $o = fopen('php://temp', 'r+'); fwrite($o, "\xEF\xBB\xBF"); fputcsv($o, $head); fputcsv($o, $ex); rewind($o); $s = stream_get_contents($o); fclose($o);
    return $s;
}
