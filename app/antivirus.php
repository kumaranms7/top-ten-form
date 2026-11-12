<?php
/**
 * Upload safety: always-on content checks + optional ClamAV scanning.
 * ClamAV (free) must be installed on the server; the app uses it when it is reachable and otherwise falls back to the checks only.
 */
const AV_BLOCKED_EXT = ['php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'exe', 'dll', 'msi', 'bat', 'cmd', 'com', 'scr', 'sh', 'bash', 'js', 'mjs', 'vbs', 'ps1', 'jar', 'jsp', 'asp', 'aspx', 'cgi', 'pl', 'py', 'htaccess', 'html', 'htm', 'svg', 'hta'];

/** Expected MIME families per extension (office formats are reported inconsistently, so they are lenient). */
function av_expected_mime(string $ext): ?array {
    static $m = [
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'gif' => ['image/gif'], 'webp' => ['image/webp'],
        'pdf' => ['application/pdf'], 'txt' => ['text/plain'], 'csv' => ['text/plain', 'text/csv', 'application/csv'],
        'zip' => ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'],
        'doc' => ['application/msword', 'application/x-ole-storage', 'application/CDFV2', 'application/octet-stream'],
        'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/CDFV2', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'],
    ];
    return $m[$ext] ?? null;
}

/** Returns an error message, or '' when the file looks safe. */
function upload_content_check(string $tmpPath, string $origName, string $ext): string {
    if (in_array($ext, AV_BLOCKED_EXT, true)) return 'This file type is not allowed.';
    // any dangerous extension hiding inside the name (shell.php.jpg)
    foreach (explode('.', strtolower($origName)) as $part) if (in_array($part, ['php', 'phtml', 'phar', 'php5', 'php7'], true)) return 'This file name is not allowed.';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE); $mime = $fi ? (string)finfo_file($fi, $tmpPath) : '';
        $exp = av_expected_mime($ext);
        if ($exp && $mime !== '' && !in_array($mime, $exp, true)) return 'The file content does not match its type.';
    }
    $head = (string)@file_get_contents($tmpPath, false, null, 0, 65536);
    if (stripos($head, '<?php') !== false || stripos($head, '<?=') !== false) return 'This file contains code and is not allowed.';
    return '';
}

function av_clamd_target(): string { return trim((string)setting('av_clamd', 'tcp://127.0.0.1:3310')); }

/** ['available' => bool, 'via' => 'clamd'|'clamscan'|'', 'detail' => string] */
function av_status(): array {
    $t = av_clamd_target();
    $s = @stream_socket_client($t, $en, $es, 1.5);
    if ($s) {
        fwrite($s, "zPING\0"); $r = trim((string)fgets($s, 64), "\0\r\n "); fclose($s);
        if ($r === 'PONG') return ['available' => true, 'via' => 'clamd', 'detail' => "ClamAV daemon reachable at $t"];
    }
    $bin = av_clamscan_bin();
    if ($bin) return ['available' => true, 'via' => 'clamscan', 'detail' => "clamscan found: $bin"];
    return ['available' => false, 'via' => '', 'detail' => 'ClamAV is not installed or not reachable. Uploads still get strict type and content checks.'];
}

function av_clamscan_bin(): string {
    $cfg = trim((string)setting('av_clamscan', ''));
    if ($cfg !== '' && is_file($cfg)) return $cfg;
    if (!function_exists('exec') || stripos((string)ini_get('disable_functions'), 'exec') !== false) return '';
    $out = []; @exec(stripos(PHP_OS, 'WIN') === 0 ? 'where clamscan 2>NUL' : 'command -v clamscan 2>/dev/null', $out);
    return !empty($out[0]) && is_file(trim($out[0])) ? trim($out[0]) : '';
}

/**
 * Is the ClamAV daemon reachable? The answer is cached (refreshed by cron), because on Windows a closed port takes ~2 seconds
 * to refuse a connection and visitors must never wait for that.
 */
function av_clamd_up(bool $force = false): bool {
    [$ts, $up] = array_pad(explode('|', (string)setting('av_probe', '0|0')), 2, '0');
    if (!$force && time() - (int)$ts < 600) return $up === '1';
    $s = @stream_socket_client(av_clamd_target(), $en, $es, 2);
    if ($s) fclose($s);
    set_setting('av_probe', time() . '|' . ($s ? '1' : '0'));
    return (bool)$s;
}
/** Called from cron: keeps the cached probe fresh so uploads never block on it. */
function av_probe_refresh(): void {
    [$ts] = array_pad(explode('|', (string)setting('av_probe', '0|0')), 1, '0');
    if (setting('av_mode', 'auto') !== 'off' && time() - (int)$ts >= 240) av_clamd_up(true);
}

/** [ 'clean' | 'infected' | 'unavailable', detail ] */
function av_scan(string $file): array {
    if (setting('av_mode', 'auto') === 'off') return ['unavailable', 'Scanning is turned off'];
    $t = av_clamd_target();
    $s = av_clamd_up() ? @stream_socket_client($t, $en, $es, 2) : false;
    if ($s) {
        stream_set_timeout($s, 30);
        fwrite($s, "zINSTREAM\0");
        $h = fopen($file, 'rb');
        while ($h && !feof($h)) { $chunk = fread($h, 8192); if ($chunk === '' || $chunk === false) break; fwrite($s, pack('N', strlen($chunk)) . $chunk); }
        if ($h) fclose($h);
        fwrite($s, pack('N', 0));
        $r = trim((string)stream_get_contents($s), "\0\r\n "); fclose($s);
        if (substr($r, -2) === 'OK') return ['clean', 'ClamAV: OK'];
        if (substr($r, -5) === 'FOUND') return ['infected', trim(str_replace(['stream:', 'FOUND'], '', $r))];
        return ['unavailable', 'Unexpected ClamAV response: ' . $r];
    }
    $bin = av_clamscan_bin();
    if ($bin) {
        $out = []; $code = 0; @exec(escapeshellarg($bin) . ' --no-summary ' . escapeshellarg($file) . ' 2>&1', $out, $code);
        if ($code === 0) return ['clean', 'clamscan: OK'];
        if ($code === 1) return ['infected', trim(implode(' ', $out))];
    }
    return ['unavailable', 'ClamAV is not available'];
}

/** Full check used by the form endpoint. Returns an error message for the visitor, or '' to accept. */
function upload_gate(string $tmpPath, string $origName, string $ext): string {
    $err = upload_content_check($tmpPath, $origName, $ext);
    if ($err !== '') return $err;
    [$res, $detail] = av_scan($tmpPath);
    if ($res === 'infected') { error_log("TopTen Form: infected upload blocked ($detail)"); return 'The file was blocked by the virus scanner.'; }
    if ($res === 'unavailable' && setting('av_mode', 'auto') === 'required') return 'File uploads are temporarily unavailable.';
    return '';
}
