<?php
/** Versioned database upgrades. Each step is idempotent, so it is safe on fresh installs and on existing ones. */
function db_has_column(string $table, string $col): bool {
    return (bool)q('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$table, $col])->fetchColumn();
}
function db_add_column(string $table, string $col, string $def): void {
    if (!db_has_column($table, $col)) db()->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
}
function db_has_index(string $table, string $name): bool {
    return (bool)q('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?', [$table, $name])->fetchColumn();
}

function migration_list(): array {
    return [
        1 => function () { /* base schema (schema.sql) */ },
        2 => function () {
            $pdo = db();
            $tail = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
            // entries: status, assignment, spam, traffic source
            db_add_column('entries', 'status', "ENUM('new','contacted','won','lost') NOT NULL DEFAULT 'new'");
            db_add_column('entries', 'assigned_to', 'INT NULL');
            db_add_column('entries', 'is_spam', 'TINYINT(1) NOT NULL DEFAULT 0');
            db_add_column('entries', 'spam_reason', 'VARCHAR(190) NULL');
            db_add_column('entries', 'utm_json', 'TEXT NULL');
            db_add_column('entries', 'referrer', 'VARCHAR(500) NULL');
            if (!db_has_index('entries', 'idx_form_spam_date')) $pdo->exec('ALTER TABLE entries ADD INDEX idx_form_spam_date (form_id, is_spam, created_at)');
            db_add_column('notifications', 'condition_json', 'TEXT NULL');
            db_add_column('users', 'twofa_enabled', 'TINYINT(1) NOT NULL DEFAULT 0');
            $pdo->exec("CREATE TABLE IF NOT EXISTS entry_notes (id INT AUTO_INCREMENT PRIMARY KEY, entry_id INT NOT NULL, user_id INT NULL, note TEXT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX (entry_id)) $tail");
            $pdo->exec("CREATE TABLE IF NOT EXISTS jobs (id INT AUTO_INCREMENT PRIMARY KEY, type VARCHAR(20) NOT NULL, payload LONGTEXT NOT NULL, status ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending', attempts INT NOT NULL DEFAULT 0, next_attempt DATETIME NOT NULL, last_error TEXT NULL, form_id INT NULL, entry_id INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, done_at DATETIME NULL, INDEX (status, next_attempt), INDEX (form_id)) $tail");
            $pdo->exec("CREATE TABLE IF NOT EXISTS webhooks (id INT AUTO_INCREMENT PRIMARY KEY, form_id INT NOT NULL, name VARCHAR(190) NOT NULL, url VARCHAR(500) NOT NULL, secret VARCHAR(190) NULL, active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX (form_id)) $tail");
            $pdo->exec("CREATE TABLE IF NOT EXISTS webhook_logs (id INT AUTO_INCREMENT PRIMARY KEY, webhook_id INT NOT NULL, entry_id INT NULL, status ENUM('ok','failed') NOT NULL, http_code INT NULL, response VARCHAR(500) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX (webhook_id)) $tail");
            $pdo->exec("CREATE TABLE IF NOT EXISTS spam_rules (id INT AUTO_INCREMENT PRIMARY KEY, type ENUM('word','ip','email_domain') NOT NULL, value VARCHAR(190) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq (type, value)) $tail");
            $pdo->exec("CREATE TABLE IF NOT EXISTS drafts (id INT AUTO_INCREMENT PRIMARY KEY, form_id INT NOT NULL, token CHAR(40) NOT NULL UNIQUE, data_json LONGTEXT NOT NULL, email VARCHAR(190) NULL, expires_at DATETIME NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) $tail");
            $pdo->exec("CREATE TABLE IF NOT EXISTS twofa_codes (user_id INT PRIMARY KEY, code_hash CHAR(64) NOT NULL, expires_at DATETIME NOT NULL, attempts INT NOT NULL DEFAULT 0, sent_at DATETIME NOT NULL, purpose VARCHAR(20) NOT NULL DEFAULT 'login') $tail");
            $pdo->exec("CREATE TABLE IF NOT EXISTS trusted_devices (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, token_hash CHAR(64) NOT NULL, user_agent VARCHAR(255) NULL, expires_at DATETIME NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX (user_id), INDEX (token_hash)) $tail");
            $pdo->exec("CREATE TABLE IF NOT EXISTS user_prefs (user_id INT NOT NULL, k VARCHAR(100) NOT NULL, v MEDIUMTEXT NULL, PRIMARY KEY (user_id, k)) $tail");
            $defaults = ['cron_key' => bin2hex(random_bytes(16)), 'last_cron' => '0', 'spam_min_seconds' => '2', 'spam_retention_days' => '30',
                'av_mode' => 'auto', 'backup_schedule' => 'off', 'backup_keep' => '7'];
            foreach ($defaults as $k => $v) q('INSERT IGNORE INTO settings (k,v) VALUES (?,?)', [$k, $v]);
            // private uploads folder (only possible from a web request, where the document root is known)
            if (!empty($_SERVER['DOCUMENT_ROOT'])) { try { storage_dir(); migrate_legacy_uploads(); } catch (Throwable $e) { error_log($e->getMessage()); } }
        },
        3 => function () {
            $pdo = db();
            // "No status" (empty) is now a valid entry status; entries record the page the visitor came from
            $pdo->exec("ALTER TABLE entries MODIFY status ENUM('','new','contacted','won','lost') NOT NULL DEFAULT 'new'");
            db_add_column('entries', 'source_page', 'VARCHAR(500) NULL');
            $pdo->exec("CREATE TABLE IF NOT EXISTS spam_tokens (nonce CHAR(16) PRIMARY KEY, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX (created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            foreach (['spam_dupe_minutes' => '10', 'spam_block_disposable' => '1', 'spam_secret' => bin2hex(random_bytes(24)), 'status_reset_days' => '30'] as $k => $v) q('INSERT IGNORE INTO settings (k,v) VALUES (?,?)', [$k, $v]);
        },
        4 => function () {
            db()->exec("CREATE TABLE IF NOT EXISTS captcha_challenges (id CHAR(20) PRIMARY KEY, form_id INT NOT NULL, answers_json TEXT NOT NULL, attempts TINYINT NOT NULL DEFAULT 0, passed TINYINT NOT NULL DEFAULT 0, expires_at DATETIME NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX (expires_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            q('INSERT IGNORE INTO settings (k,v) VALUES (?,?)', ['captcha_required', '1']);
        },
        7 => function () {   // monthly lead reports: on by default, first report covers the current month (no back-fill)
            q("INSERT IGNORE INTO settings (k,v) VALUES ('report_enabled','1'),('report_recipients','pete@metroseo.net'),('report_from',?)", [date('Y-m')]);
        },
        6 => function () {   // form tags (comma-separated labels to tell similar forms apart)
            db_add_column('forms', 'tags', "VARCHAR(500) NOT NULL DEFAULT ''");
        },
        5 => function () {
            $pdo = db(); $tail = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
            $pdo->exec("CREATE TABLE IF NOT EXISTS sites (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(190) NOT NULL, slug VARCHAR(190) NOT NULL, status ENUM('active','suspended') NOT NULL DEFAULT 'active',
                company VARCHAR(190) NULL, website_url VARCHAR(500) NULL, allowed_domains TEXT NULL, contact_email VARCHAR(190) NULL, phone VARCHAR(60) NULL, address VARCHAR(500) NULL, notes TEXT NULL,
                logo VARCHAR(100) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_slug (slug)) $tail");
            $pdo->exec("CREATE TABLE IF NOT EXISTS site_settings (site_id INT NOT NULL, k VARCHAR(100) NOT NULL, v MEDIUMTEXT NULL, PRIMARY KEY (site_id, k)) $tail");
            $pdo->exec("CREATE TABLE IF NOT EXISTS user_sites (user_id INT NOT NULL, site_id INT NOT NULL, role ENUM('admin','editor','viewer') NOT NULL DEFAULT 'editor', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (user_id, site_id), INDEX (site_id)) $tail");
            db_add_column('users', 'is_super', 'TINYINT(1) NOT NULL DEFAULT 0');
            db_add_column('forms', 'site_id', 'INT NOT NULL DEFAULT 0');
            if (!db_has_index('forms', 'idx_site')) $pdo->exec('ALTER TABLE forms ADD INDEX idx_site (site_id)');
            db_add_column('activity_log', 'site_id', 'INT NULL');
            // spam rules become per site (0 = platform-wide)
            db_add_column('spam_rules', 'site_id', 'INT NOT NULL DEFAULT 0');
            if (db_has_index('spam_rules', 'uq')) $pdo->exec('ALTER TABLE spam_rules DROP INDEX uq');
            if (!db_has_index('spam_rules', 'uq_site')) $pdo->exec('ALTER TABLE spam_rules ADD UNIQUE KEY uq_site (type, value, site_id)');
            // everything that exists today becomes the "Default site"
            $sid = (int)q('SELECT id FROM sites ORDER BY id LIMIT 1')->fetchColumn();
            if (!$sid) {
                q('INSERT INTO sites (name,slug,contact_email) VALUES (?,?,?)', ['Default site', 'default-site', (string)setting('mail_from_email', '')]);
                $sid = (int)db()->lastInsertId();
            }
            q('UPDATE forms SET site_id=? WHERE site_id=0', [$sid]);
            q('UPDATE activity_log SET site_id=? WHERE site_id IS NULL', [$sid]);
            foreach (q('SELECT id, role FROM users ORDER BY id')->fetchAll() as $u) q('INSERT IGNORE INTO user_sites (user_id,site_id,role) VALUES (?,?,?)', [$u['id'], $sid, in_array($u['role'], ['admin', 'editor', 'viewer'], true) ? $u['role'] : 'editor']);
            if (!q('SELECT 1 FROM users WHERE is_super=1 LIMIT 1')->fetchColumn()) { // the first administrator becomes the super admin
                $first = q("SELECT id FROM users WHERE role='admin' AND active=1 ORDER BY id LIMIT 1")->fetchColumn() ?: q('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
                if ($first) q('UPDATE users SET is_super=1 WHERE id=?', [$first]);
            }
        },
        8 => function () {   // captcha is now a form field: forms that had the (mandatory) captcha get an explicit Captcha field at the end, keeping their chosen type
            if (setting('captcha_required', '1') !== '1') return;
            foreach (q('SELECT id, fields_json, settings_json FROM forms')->fetchAll() as $f) {
                $fields = json_decode($f['fields_json'] ?? '[]', true) ?: [];
                foreach ($fields as $x) if (($x['type'] ?? '') === 'captcha') continue 2;
                $st = json_decode($f['settings_json'] ?? '[]', true) ?: [];
                $t = in_array($st['captcha_type'] ?? '', ['math', 'text', 'question'], true) ? $st['captcha_type'] : 'math';
                $fields[] = ['id' => 'fcap' . $f['id'], 'type' => 'captcha', 'name' => 'captcha_' . (count($fields) + 1), 'label' => 'Security check', 'placeholder' => '', 'default' => '', 'help' => '', 'required' => false,
                    'width' => 'full', 'css_class' => '', 'options' => [], 'formula' => '', 'currency' => '', 'max' => 0, 'placeholders' => [], 'name_format' => '', 'address_format' => '', 'phone_format' => '', 'captcha_type' => $t, 'logic' => null];
                q('UPDATE forms SET fields_json=? WHERE id=?', [json_encode($fields, JSON_UNESCAPED_UNICODE), $f['id']]);
            }
        },
    ];
}

function run_migrations(): void {
    $latest = max(array_keys(migration_list()));
    if ((int)setting('db_version', 0) >= $latest) return;
    if (!q("SELECT GET_LOCK('tf_migrate', 15)")->fetchColumn()) return;
    try {
        $cur = (int)q("SELECT v FROM settings WHERE k='db_version'")->fetchColumn();
        $list = migration_list(); ksort($list);   // always in version order
        foreach ($list as $v => $fn) {
            if ($v <= $cur) continue;
            $fn();
            set_setting('db_version', (string)$v);
        }
    } finally { q("SELECT RELEASE_LOCK('tf_migrate')"); }
}
