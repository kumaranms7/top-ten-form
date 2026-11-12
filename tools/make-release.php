<?php
/**
 * Build an update package that the admin "Updates" page can install.
 *   php tools/make-release.php            -> topten-form-<version>.zip in the project folder
 *   php tools/make-release.php <outdir>   -> into another folder
 * Includes the program files only. Never includes config/, uploads/, storage/, public/media/ or any ZIP.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
preg_match("/TF_VERSION',\s*'([^']+)'/", (string)file_get_contents($root . '/app/version.php'), $m);
$version = $m[1] ?? '0.0.0';
$out = rtrim($argv[1] ?? $root, '/\\') . "/topten-form-$version.zip";
$dirs = ['admin', 'app', 'public', 'vendor']; $rootFiles = ['index.php', 'cron.php', 'install.php', 'build-assets.bat', 'CHANGELOG.md', 'README.md'];
$skip = fn(string $rel) => strpos($rel, 'public/media/') === 0 || preg_match('#\.(zip|log)$#i', $rel);

$zip = new ZipArchive();
if ($zip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { fwrite(STDERR, "Cannot create $out\n"); exit(1); }
$n = 0;
foreach ($rootFiles as $f) if (is_file("$root/$f")) { $zip->addFile("$root/$f", $f); $n++; }
foreach ($dirs as $d) {
    if (!is_dir("$root/$d")) continue;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$d", FilesystemIterator::SKIP_DOTS)) as $f) {
        if (!$f->isFile()) continue;
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
        if ($skip($rel)) continue;
        $zip->addFile($f->getPathname(), $rel); $n++;
    }
}
$zip->close();
echo "Built $out\n  version $version, $n files, " . round(filesize($out) / 1024) . " KB\n";
