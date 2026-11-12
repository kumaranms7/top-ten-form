<?php
/**
 * Private storage: uploads, backups, logs, temp files. Lives OUTSIDE the web root whenever possible,
 * so files can never be requested directly by URL (works on Apache, nginx, anything).
 */
function storage_dir(): string {
    static $dir;
    if ($dir) return $dir;
    $cfg = (string)setting('storage_dir', '');
    $candidates = [];
    if ($cfg !== '') $candidates[] = $cfg;
    $doc = !empty($_SERVER['DOCUMENT_ROOT']) ? rtrim(str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']) ?: $_SERVER['DOCUMENT_ROOT']), '/') : '';
    if ($cfg === '' && $doc !== '') $candidates[] = dirname($doc) . '/topten-form-storage';   // one level above the web root
    $candidates[] = TF_ROOT . '/storage';                                                     // last resort (protected by .htaccess)
    foreach ($candidates as $c) {
        $c = rtrim(str_replace('\\', '/', $c), '/');
        if (!is_dir($c)) @mkdir($c, 0755, true);
        if (is_dir($c) && is_writable($c)) { $dir = $c; break; }
    }
    if (!$dir) throw new RuntimeException('No writable storage folder. Set one in Settings.');
    foreach (['uploads', 'backups', 'tmp'] as $sub) if (!is_dir("$dir/$sub")) @mkdir("$dir/$sub", 0755, true);
    if (!is_file("$dir/.htaccess")) @file_put_contents("$dir/.htaccess", "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    // remember the choice so cron/CLI (which has no document root) uses the same path
    if ($cfg === '' && $doc !== '' && $dir !== rtrim(str_replace('\\', '/', TF_ROOT . '/storage'), '/')) set_setting('storage_dir', $dir);
    return $dir;
}
function upload_path(string $rel = ''): string { return storage_dir() . '/uploads' . ($rel !== '' ? '/' . ltrim($rel, '/') : ''); }

/** True when the storage folder is not reachable through the web server's document root. */
function storage_is_private(): bool {
    $doc = !empty($_SERVER['DOCUMENT_ROOT']) ? rtrim(str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']) ?: ''), '/') : '';
    if ($doc === '') return true;
    return stripos(storage_dir() . '/', $doc . '/') !== 0;
}

/** Move files from the old in-app uploads/ folder to private storage (one-time, safe to repeat). Returns files moved. */
function migrate_legacy_uploads(): int {
    $old = TF_ROOT . '/uploads'; $new = upload_path(); $n = 0;
    if (!is_dir($old) || realpath($old) === realpath($new)) return 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($old, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) {
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($old) + 1));
        if ($rel === '.htaccess') continue;
        if ($f->isDir()) { @mkdir("$new/$rel", 0755, true); continue; }
        if ($rel === 'mail.log') { @rename($f->getPathname(), storage_dir() . '/mail.log'); continue; }
        @mkdir(dirname("$new/$rel"), 0755, true);
        if (@rename($f->getPathname(), "$new/$rel")) $n++;
    }
    return $n;
}
