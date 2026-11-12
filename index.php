<?php
// Fallback for servers without mod_rewrite: same routing as .htaccess.
header('Location: ' . (file_exists(__DIR__ . '/config/config.php') ? 'admin/login.php' : 'install.php'));
exit;
