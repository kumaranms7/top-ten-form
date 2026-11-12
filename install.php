<?php
define('TF_INSTALLING', 1);
require __DIR__ . '/app/bootstrap.php';
if ($tfInstalled) { exit('Already installed. Delete config/config.php to reinstall (this keeps your database tables).'); }

$err = ''; $done = false;
$v = ['db_host' => 'localhost', 'db_name' => 'topten_form', 'db_user' => 'root', 'db_pass' => '', 'name' => '', 'email' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($v as $k => $_) $v[$k] = trim($_POST[$k] ?? '');
    $pass = $_POST['password'] ?? '';
    try {
        if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL)) throw new Exception('Enter a valid admin email.');
        if (strlen($pass) < 8) throw new Exception('Password must be at least 8 characters.');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $v['db_name'])) throw new Exception('Database name may only contain letters, numbers and underscore.');
        $pdo = new PDO("mysql:host={$v['db_host']};charset=utf8mb4", $v['db_user'], $v['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$v['db_name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$v['db_name']}`");
        foreach (array_filter(array_map('trim', explode(';', file_get_contents(__DIR__ . '/app/schema.sql')))) as $sql) $pdo->exec($sql);
        $st = $pdo->prepare('INSERT INTO users (name,email,password,role) VALUES (?,?,?,"admin")');
        $st->execute([$v['name'] ?: 'Administrator', $v['email'], password_hash($pass, PASSWORD_DEFAULT)]);
        $defaults = ['site_name' => 'TopTen Form', 'timezone' => 'America/New_York', 'mail_driver' => 'phpmail', 'mail_from_email' => $v['email'], 'mail_from_name' => 'TopTen Form',
            'session_timeout' => '120', 'max_upload_mb' => '5', 'allowed_ext' => 'jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx,txt,zip', 'retention_days' => '0', 'captcha_provider' => 'none'];
        $st = $pdo->prepare('INSERT INTO settings (k,v) VALUES (?,?)');
        foreach ($defaults as $k => $val) $st->execute([$k, $val]);
        $cfg = "<?php\ndefine('DB_HOST', " . var_export($v['db_host'], true) . ");\ndefine('DB_NAME', " . var_export($v['db_name'], true) . ");\ndefine('DB_USER', " . var_export($v['db_user'], true) . ");\ndefine('DB_PASS', " . var_export($v['db_pass'], true) . ");\n";
        if (!@file_put_contents(__DIR__ . '/config/config.php', $cfg)) throw new Exception('Could not write config/config.php. Check folder permissions.');
        $done = true;
    } catch (Throwable $ex) { $err = $ex->getMessage(); }
}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Install - TopTen Form</title>
<link rel="icon" href="favicon.ico?v=1" sizes="any"><link rel="shortcut icon" href="favicon.ico?v=1"><link rel="stylesheet" href="admin/assets/admin.css?v=6"></head><body class="auth-body">
<div class="auth-card" style="max-width:480px">
<img class="auth-logo" src="admin/assets/img/logo.webp" width="129" height="56" alt="Top Ten Website Marketing">
<h1 class="center">Installer</h1>
<?php if ($done): ?>
  <div class="alert ok">Installed successfully.</div>
  <p><a class="btn" href="admin/login.php">Go to admin login</a></p>
  <p class="muted">For security, delete <code>install.php</code> now.</p>
<?php else: ?>
  <?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>
  <form method="post">
    <h3>Database</h3>
    <label>Host<input name="db_host" value="<?= e($v['db_host']) ?>" required></label>
    <label>Database name<input name="db_name" value="<?= e($v['db_name']) ?>" required></label>
    <label>User<input name="db_user" value="<?= e($v['db_user']) ?>" required></label>
    <label>Password <small>(leave empty if root has no password)</small><input type="password" name="db_pass" value="<?= e($v['db_pass']) ?>" autocomplete="new-password"></label>
    <h3>Admin account</h3>
    <label>Name<input name="name" value="<?= e($v['name']) ?>"></label>
    <label>Email<input type="email" name="email" value="<?= e($v['email']) ?>" required></label>
    <label>Password (min 8 chars)<input type="password" name="password" required></label>
    <button class="btn" type="submit">Install</button>
  </form>
<?php endif; ?>
</div></body></html>
