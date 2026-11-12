<?php
/**
 * Update from a ZIP: inspect -> automatic backup -> replace code files (keeps config, uploads, logos) -> database upgrades run
 * automatically on the next request. Every replaced file is saved so the update can be rolled back.
 */
const UPDATE_DIRS = ['admin', 'app', 'public', 'vendor'];
const UPDATE_ROOT_FILES = ['index.php', 'cron.php', 'install.php', 'build-assets.bat', 'CHANGELOG.md', 'README.md'];
const UPDATE_EXT = ['php', 'js', 'css', 'json', 'md', 'txt', 'sql', 'htaccess', 'bat', 'html', 'webp', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'map'];

function update_safe_path(string $rel): bool {
    if ($rel === '' || $rel[0] === '/' || strpos($rel, '..') !== false || strpos($rel, '\\') !== false || preg_match('/^[A-Za-z]:/', $rel) || strpos($rel, "\0") !== false) return false;
    return true;
}

/** Is this archive entry something the updater may write? Returns the path relative to the app root, or null. */
function update_target(string $rel): ?string {
    if (substr($rel, -1) === '/' || !update_safe_path($rel)) return null;
    $top = explode('/', $rel)[0];
    $base = basename($rel); $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION)); if ($base === '.htaccess') $ext = 'htaccess';
    if (!in_array($ext, UPDATE_EXT, true)) return null;
    if (strpos($rel, '/') === false) return in_array($rel, UPDATE_ROOT_FILES, true) ? $rel : null;
    if (!in_array($top, UPDATE_DIRS, true) || strpos($rel, 'public/media/') === 0) return null;
    return $rel;
}

/** Read an update ZIP without running anything. Returns [info|null, error]. */
function update_inspect(string $zipPath): array {
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) return [null, 'That file is not a valid ZIP.'];
    if ($zip->numFiles > 6000) { $zip->close(); return [null, 'The archive has too many files.']; }
    // the package may have its files at the top or inside one folder
    $prefix = null;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $nm = $zip->getNameIndex($i);
        if (!update_safe_path($nm)) { $zip->close(); return [null, 'The archive contains an unsafe path: ' . $nm]; }
        if (preg_match('#^((?:[^/]+/)?)app/version\.php$#', $nm, $m)) $prefix = $m[1];
    }
    if ($prefix === null || $zip->locateName($prefix . 'app/bootstrap.php') === false) { $zip->close(); return [null, 'This does not look like a TopTen Form update package (app/version.php was not found).']; }
    if (!preg_match("/TF_VERSION',\s*'([0-9]+(?:\.[0-9]+){1,3})'/", (string)$zip->getFromName($prefix . 'app/version.php'), $vm)) { $zip->close(); return [null, 'Could not read the version number from the package.']; }
    $files = []; $total = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $nm = $zip->getNameIndex($i); if ($prefix !== '' && strpos($nm, $prefix) !== 0) continue;
        $rel = update_target(substr($nm, strlen($prefix))); if ($rel === null) continue;
        $st = $zip->statIndex($i); $total += $st['size'];
        if ($st['size'] > 20 * 1048576) { $zip->close(); return [null, 'A file in the package is unusually large: ' . $nm]; }
        $files[] = ['index' => $i, 'rel' => $rel, 'size' => $st['size']];
    }
    if ($total > 120 * 1048576) { $zip->close(); return [null, 'The package is too large.']; }
    $changelog = (string)$zip->getFromName($prefix . 'CHANGELOG.md');
    $zip->close();
    return [['version' => $vm[1], 'prefix' => $prefix, 'files' => $files, 'count' => count($files), 'bytes' => $total, 'changelog' => mb_substr($changelog, 0, 6000), 'cmp' => version_compare($vm[1], TF_VERSION)], ''];
}

function update_rollback_dir(): string { $d = storage_dir() . '/rollback'; if (!is_dir($d)) @mkdir($d, 0755, true); return $d; }

function update_flush_opcache(): void { if (function_exists('opcache_reset')) @opcache_reset(); }

/** Install the package. Returns [ok, message, rollbackId]. */
function update_apply(string $zipPath): array {
    [$info, $err] = update_inspect($zipPath); if (!$info) return [false, $err, ''];
    [$bk, $berr] = backup_create('pre-update');
    if (!$bk) return [false, 'Could not take the automatic backup first: ' . $berr, ''];
    $id = date('Ymd-His') . '-' . $info['version']; $rb = update_rollback_dir() . '/' . $id; @mkdir($rb . '/files', 0755, true);
    $manifest = ['from' => TF_VERSION, 'to' => $info['version'], 'backup' => $bk, 'created_at' => date('c'), 'replaced' => [], 'added' => []];
    $zip = new ZipArchive(); $zip->open($zipPath);
    try {
        foreach ($info['files'] as $f) {
            $dest = TF_ROOT . '/' . $f['rel'];
            if (is_file($dest)) { @mkdir(dirname("$rb/files/{$f['rel']}"), 0755, true); if (!copy($dest, "$rb/files/{$f['rel']}")) throw new RuntimeException('Could not save a rollback copy of ' . $f['rel']); $manifest['replaced'][] = $f['rel']; }
            else $manifest['added'][] = $f['rel'];
            @mkdir(dirname($dest), 0755, true);
            if (file_put_contents($dest, $zip->getFromIndex($f['index'])) === false) throw new RuntimeException('Could not write ' . $f['rel'] . ' (check folder permissions)');
        }
        $zip->close();
        file_put_contents("$rb/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT));
        update_flush_opcache();
        // keep the 3 most recent rollbacks
        $all = glob(update_rollback_dir() . '/*', GLOB_ONLYDIR) ?: []; rsort($all);
        foreach (array_slice($all, 3) as $old) update_rmdir($old);
        return [true, 'Updated ' . TF_VERSION . ' to ' . $info['version'] . ' (' . $info['count'] . ' files).', $id];
    } catch (Throwable $e) {
        $zip->close();
        file_put_contents("$rb/manifest.json", json_encode($manifest));
        update_rollback($id);
        return [false, 'The update was stopped and rolled back: ' . $e->getMessage(), ''];
    }
}

function update_rmdir(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    @rmdir($dir);
}

function update_rollbacks(): array {
    $out = [];
    foreach (glob(update_rollback_dir() . '/*', GLOB_ONLYDIR) ?: [] as $d) {
        $m = json_decode((string)@file_get_contents("$d/manifest.json"), true);
        if ($m) $out[] = ['id' => basename($d)] + $m;
    }
    usort($out, fn($a, $b) => strcmp($b['id'], $a['id']));
    return $out;
}

/** Put every replaced file back and remove the files that the update added. Returns [ok, message]. */
function update_rollback(string $id): array {
    if (!preg_match('/^\d{8}-\d{6}-[0-9.]+$/', $id)) return [false, 'Invalid rollback id.'];
    $rb = update_rollback_dir() . '/' . $id; $m = json_decode((string)@file_get_contents("$rb/manifest.json"), true);
    if (!$m) return [false, 'Rollback data not found.'];
    $n = 0;
    foreach ($m['replaced'] as $rel) if (update_safe_path($rel) && is_file("$rb/files/$rel") && copy("$rb/files/$rel", TF_ROOT . '/' . $rel)) $n++;
    foreach ($m['added'] as $rel) if (update_safe_path($rel) && is_file(TF_ROOT . '/' . $rel)) @unlink(TF_ROOT . '/' . $rel);
    update_flush_opcache();
    return [true, "Rolled back to version {$m['from']} ($n files restored). If the database was upgraded, restore the backup \"{$m['backup']}\" too."];
}
