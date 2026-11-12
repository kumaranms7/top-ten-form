<?php
/** Webhooks: POST every new (non-spam) entry as JSON to any URL (Zapier, Make, your CRM...). Delivered from the background queue with retries. */

/** Reject anything that is not a plain http(s) URL or that points at cloud-metadata / link-local addresses. Returns an error message or ''. */
function webhook_url_error(string $url): string {
    if (!preg_match('#^https?://[^\s/$.?\#][^\s]*$#i', $url) || strlen($url) > 500) return 'Enter a full URL starting with http:// or https://';
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    if ($host === '') return 'That URL has no host name.';
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (array)@gethostbynamel($host);
    foreach ($ips as $ip) if (strpos($ip, '169.254.') === 0) return 'Link-local / cloud-metadata addresses are not allowed.';
    return '';
}

/** Build the JSON payload for an entry. */
function webhook_payload(array $form, array $fields, int $entryId, array $vals, array $meta): array {
    $data = []; $labels = []; $files = [];
    foreach ($fields as $f) {
        if (!is_input_field($f)) continue;
        $labels[$f['name']] = $f['label'];
        $v = $vals[$f['name']] ?? '';
        if (is_array($v) && isset($v['file'])) { $files[$f['name']] = $v['name']; $data[$f['name']] = $v['name']; }
        else $data[$f['name']] = $v;
    }
    return [
        'event' => 'entry.created',
        'form' => ['id' => (int)$form['id'], 'name' => $form['name']],
        'entry' => ['id' => $entryId, 'created_at' => date('c'), 'data' => $data, 'labels' => $labels, 'files' => (object)$files, 'meta' => $meta],
    ];
}

function queue_webhooks(array $form, array $fields, int $entryId, array $vals, array $meta): void {
    $hooks = q('SELECT id FROM webhooks WHERE form_id=? AND active=1', [$form['id']])->fetchAll();
    if (!$hooks) return;
    $payload = webhook_payload($form, $fields, $entryId, $vals, $meta);
    foreach ($hooks as $h) queue_job('webhook', ['webhook_id' => (int)$h['id'], 'payload' => $payload], (int)$form['id'], $entryId);
}

/** Send one webhook request. Returns [bool ok, string error] and writes a log row. */
function webhook_send(array $hook, array $payload, ?int $entryId, int $deliveryId = 0): array {
    $err = webhook_url_error($hook['url']);
    if ($err) { q("INSERT INTO webhook_logs (webhook_id,entry_id,status,http_code,response) VALUES (?,?,'failed',NULL,?)", [$hook['id'], $entryId, $err]); return [false, $err]; }
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $headers = ['Content-Type: application/json', 'User-Agent: TopTenForm/' . TF_VERSION, 'X-TopTen-Event: ' . ($payload['event'] ?? 'entry.created'), 'X-TopTen-Delivery: ' . $deliveryId];
    if (!empty($hook['secret'])) $headers[] = 'X-TopTen-Signature: sha256=' . hash_hmac('sha256', $body, $hook['secret']);
    if (!function_exists('curl_init')) return [false, 'PHP cURL extension is required for webhooks'];
    $c = curl_init($hook['url']);
    curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS]);
    $resp = curl_exec($c); $code = (int)curl_getinfo($c, CURLINFO_RESPONSE_CODE); $cerr = curl_error($c); curl_close($c);
    $ok = $code >= 200 && $code < 300;
    $snippet = $resp === false ? $cerr : mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags((string)$resp))), 0, 300);
    q('INSERT INTO webhook_logs (webhook_id,entry_id,status,http_code,response) VALUES (?,?,?,?,?)', [$hook['id'], $entryId, $ok ? 'ok' : 'failed', $code ?: null, $snippet]);
    return [$ok, $ok ? '' : ($resp === false ? $cerr : "HTTP $code")];
}

/** Queue handler. A deleted or disabled webhook silently completes. */
function deliver_webhook(array $p, array $job): array {
    $hook = q('SELECT * FROM webhooks WHERE id=?', [(int)($p['webhook_id'] ?? 0)])->fetch();
    if (!$hook || !$hook['active']) return [true, ''];
    return webhook_send($hook, (array)$p['payload'], $job['entry_id'] ? (int)$job['entry_id'] : null, (int)$job['id']);
}
