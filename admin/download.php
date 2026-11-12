<?php
require __DIR__ . '/../app/bootstrap.php';
require_login();
$en = q('SELECT data_json FROM entries WHERE id=? AND form_id IN ' . site_forms_sql(), [(int)($_GET['entry'] ?? 0)])->fetch();
$v = $en ? (json_decode($en['data_json'], true)[$_GET['field'] ?? ''] ?? null) : null;
if (!is_array($v) || empty($v['file'])) { http_response_code(404); exit('Not found'); }
$path = realpath(upload_path($v['file']));
if (!$path || strpos($path, realpath(upload_path())) !== 0 || !is_file($path)) { http_response_code(404); exit('File missing'); }

// Only plain images may be shown inline (for entry previews); everything else is always a download.
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$imgTypes = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'];
$inline = !empty($_GET['inline']) && isset($imgTypes[$ext]);
header('Content-Type: ' . ($inline ? $imgTypes[$ext] : 'application/octet-stream'));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; sandbox");
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace(['"', "\r", "\n"], '', $v['name']) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
