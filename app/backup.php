<?php
/**
 * Backups: one ZIP containing database.sql (pure PHP, no mysqldump needed), uploaded files and logos, plus a manifest.
 * Stored in the private storage folder. Restoring takes a safety backup first.
 */
const BACKUP_SKIP_TABLES = ['rate_limits', 'password_resets', 'twofa_codes', 'trusted_devices']; // transient / security-sensitive

function backup_dir(): string { $d = storage_dir() . '/backups'; if (!is_dir($d)) @mkdir($d, 0755, true); return $d; }

/** Stream the whole database to an SQL file: one statement per line (restore relies on that). Returns table count. */
function db_dump_to_file(string $path): int {
    $pdo = db(); $fh = fopen($path, 'w'); if (!$fh) throw new RuntimeException('Cannot write the dump file');
    $pdo->exec("SET time_zone='+00:00'"); // dump timestamps in UTC so the backup is portable
    $n = 0;
    try {
        fwrite($fh, "-- TopTen Form database dump | app " . TF_VERSION . " | " . date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET time_zone='+00:00';\nSET sql_mode='NO_AUTO_VALUE_ON_ZERO';\n");
        $tables = array_column($pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_NUM), 0);
        foreach ($tables as $t) {
            if (in_array($t, BACKUP_SKIP_TABLES, true)) continue;
            $n++;
            $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
            fwrite($fh, "DROP TABLE IF EXISTS `$t`;\n" . preg_replace('/\s*\R\s*/', ' ', $create) . ";\n");
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            $st = $pdo->query("SELECT * FROM `$t`");
            $cols = null; $batch = [];
            while ($row = $st->fetch(PDO::FETCH_NUM)) {
                if ($cols === null) { $cols = []; for ($i = 0; $i < $st->columnCount(); $i++) $cols[] = '`' . $st->getColumnMeta($i)['name'] . '`'; }
                $batch[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $row)) . ')';
                if (count($batch) >= 200) { fwrite($fh, "INSERT INTO `$t` (" . implode(',', $cols) . ') VALUES ' . implode(',', $batch) . ";\n"); $batch = []; }
            }
            $st->closeCursor();
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            if ($batch) fwrite($fh, "INSERT INTO `$t` (" . implode(',', $cols) . ') VALUES ' . implode(',', $batch) . ";\n");
        }
        fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
    } finally { fclose($fh); $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true); sync_db_timezone($pdo); }
    return $n;
}

function backup_add_dir(ZipArchive $zip, string $dir, string $prefix): int {
    $c = 0; if (!is_dir($dir)) return 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || $f->getFilename() === '.htaccess') continue;
        $zip->addFile($f->getPathname(), $prefix . '/' . str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1))); $c++;
    }
    return $c;
}

/** Create a backup ZIP. Returns [filename|null, error]. */
function backup_create(string $reason = 'manual'): array {
    try {
        $reason = preg_replace('/[^a-z0-9-]/', '', strtolower($reason)) ?: 'manual';
        $name = 'topten-backup-' . date('Ymd-His') . '-' . $reason . '.zip'; $path = backup_dir() . '/' . $name;
        $tmp = storage_dir() . '/tmp/dump_' . bin2hex(random_bytes(4)) . '.sql';
        $tables = db_dump_to_file($tmp);
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Cannot create the ZIP file');
        $zip->addFile($tmp, 'database.sql');
        $files = backup_add_dir($zip, upload_path(), 'uploads'); $logos = backup_add_dir($zip, media_dir(), 'media');
        $zip->addFromString('manifest.json', json_encode(['format' => 1, 'app_version' => TF_VERSION, 'db_version' => (int)setting('db_version', 0), 'created_at' => date('c'), 'reason' => $reason,
            'site' => setting('site_name', ''), 'tables' => $tables, 'upload_files' => $files, 'logo_files' => $logos, 'forms' => (int)q('SELECT COUNT(*) FROM forms')->fetchColumn(), 'entries' => (int)q('SELECT COUNT(*) FROM entries')->fetchColumn()], JSON_PRETTY_PRINT));
        if (!$zip->close()) throw new RuntimeException('Could not finish the ZIP file (disk full?)');
        @unlink($tmp);
        return [$name, ''];
    } catch (Throwable $e) { if (!empty($tmp)) @unlink($tmp); if (!empty($path)) @unlink($path); return [null, $e->getMessage()]; }
}

function backup_list(): array {
    $out = [];
    foreach (glob(backup_dir() . '/topten-backup-*.zip') ?: [] as $f) {
        preg_match('/topten-backup-(\d{8}-\d{6})-([a-z0-9-]+)\.zip$/', basename($f), $m);
        $out[] = ['name' => basename($f), 'size' => filesize($f), 'time' => filemtime($f), 'reason' => $m[2] ?? 'manual'];
    }
    usort($out, fn($a, $b) => $b['time'] <=> $a['time']);
    return $out;
}
function backup_path(string $name): ?string {
    if (!preg_match('/^topten-backup-\d{8}-\d{6}-[a-z0-9-]+\.zip$/', $name)) return null;
    $p = backup_dir() . '/' . $name; return is_file($p) ? $p : null;
}

/** Keep only the newest $keep scheduled backups (manual, pre-restore and pre-update backups are never auto-deleted). */
function backup_prune(int $keep): void {
    $sched = array_values(array_filter(backup_list(), fn($b) => $b['reason'] === 'scheduled'));
    foreach (array_slice($sched, max(1, $keep)) as $b) @unlink(backup_dir() . '/' . $b['name']);
}

/** Cron hook: create the scheduled backup when it is due. */
function run_scheduled_backup(): void {
    $mode = setting('backup_schedule', 'off'); if (!in_array($mode, ['daily', 'weekly'], true)) return;
    $every = $mode === 'daily' ? 23 * 3600 : 6.9 * 86400;
    if (time() - (int)setting('last_backup', 0) < $every) return;
    set_setting('last_backup', (string)time()); // claim first so overlapping requests don't double up
    [$name, $err] = backup_create('scheduled');
    set_setting('last_backup_status', $name ? 'ok: ' . $name : 'FAILED: ' . $err);
    if ($name) backup_prune((int)setting('backup_keep', 7));
}

/** Restore from a backup ZIP file. Returns [ok, message]. A safety backup is taken first. */
function backup_restore(string $zipPath): array {
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) return [false, 'That file is not a valid ZIP.'];
    $mf = json_decode((string)$zip->getFromName('manifest.json'), true);
    if (!is_array($mf) || ($mf['format'] ?? 0) !== 1 || $zip->locateName('database.sql') === false) { $zip->close(); return [false, 'This is not a TopTen Form backup.']; }
    if ((int)($mf['db_version'] ?? 0) > max(array_keys(migration_list()))) { $zip->close(); return [false, 'This backup comes from a newer version of the app (database v' . (int)$mf['db_version'] . '). Update the app first.']; }
    [$safety, $serr] = backup_create('pre-restore');
    if (!$safety) { $zip->close(); return [false, 'Could not take the safety backup first: ' . $serr]; }
    $keep = []; foreach (['storage_dir', 'app_url', 'cron_key', 'last_cron', 'av_probe', 'last_backup'] as $k) $keep[$k] = setting($k, null);
    $sql = storage_dir() . '/tmp/restore_' . bin2hex(random_bytes(4)) . '.sql';
    try {
        file_put_contents($sql, $zip->getFromName('database.sql'));
        $pdo = db(); $fh = fopen($sql, 'r'); $n = 0;
        while (($line = fgets($fh)) !== false) {
            $line = trim($line);
            if ($line === '' || strpos($line, '--') === 0) continue;
            $pdo->exec($line); $n++;
        }
        fclose($fh); @unlink($sql);
        $GLOBALS['tf_settings'] = null; sync_db_timezone($pdo);
        foreach ($keep as $k => $v) if ($v !== null && $v !== '') set_setting($k, (string)$v); // keep this server's own paths/keys
        // files
        $files = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nm = $zip->getNameIndex($i);
            if (strpos($nm, '..') !== false || $nm[0] === '/' || substr($nm, -1) === '/') continue;
            if (strpos($nm, 'uploads/') === 0) { $dest = upload_path(substr($nm, 8)); }
            elseif (strpos($nm, 'media/') === 0 && preg_match('/^logo_[a-f0-9]{16}\.(png|jpg|gif|webp)$/', basename($nm))) { $dest = media_dir() . '/' . basename($nm); }
            else continue;
            @mkdir(dirname($dest), 0755, true);
            if (file_put_contents($dest, $zip->getFromIndex($i)) !== false) $files++;
        }
        $zip->close();
        run_migrations();
        return [true, "Restored $n database statements and $files file(s). Safety copy: $safety"];
    } catch (Throwable $e) {
        @unlink($sql); $zip->close();
        return [false, 'Restore failed part-way: ' . $e->getMessage() . '. Your data from before the restore is saved in ' . $safety . ' (restore that file to go back).'];
    }
}
