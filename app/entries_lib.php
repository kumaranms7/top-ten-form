<?php
/** Helpers shared by the entries list, entry detail, exports and webhooks. */

/** Status tokens used in forms/URLs. 'none' is stored in the database as an empty status. */
const ENTRY_STATUSES = ['new' => 'New', 'contacted' => 'Contacted', 'won' => 'Won', 'lost' => 'Lost', 'none' => 'No status'];
const ENTRY_STATUS_COLOR = ['new' => '', 'contacted' => 'amber', 'won' => 'green', 'lost' => 'red', 'none' => 'slate'];
function status_to_db(string $token): ?string { return $token === 'none' ? '' : (isset(ENTRY_STATUSES[$token]) ? $token : null); }
function status_token(string $db): string { return $db === '' ? 'none' : $db; }

/** Any stored value as plain text (arrays joined, files by name). */
function flat_value($v): string {
    if (is_array($v)) return isset($v['name']) ? (string)$v['name'] : implode(', ', array_map('strval', $v));
    return (string)$v;
}

/** Fields that hold data (no headings / paragraphs / page breaks). */
function form_input_fields(array $form): array {
    return array_values(array_filter(form_fields($form), 'is_input_field'));
}

/** Columns the current user chose for this form's entry table (field names, in order). Defaults to the first four fields. */
function entry_columns(array $form, ?int $userId): array {
    $names = array_column(form_input_fields($form), 'name');
    if ($userId) {
        $row = q('SELECT v FROM user_prefs WHERE user_id=? AND k=?', [$userId, 'cols_' . $form['id']])->fetchColumn();
        $saved = $row ? json_decode($row, true) : null;
        if (is_array($saved)) { $saved = array_values(array_filter($saved, fn($n) => in_array($n, $names, true))); if ($saved) return $saved; }
    }
    return array_slice($names, 0, 4);
}

function save_entry_columns(int $userId, int $formId, array $cols): void {
    q('INSERT INTO user_prefs (user_id,k,v) VALUES (?,?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)', [$userId, 'cols_' . $formId, json_encode(array_values($cols))]);
}

function entry_sources(array $en): array {
    $utm = json_decode((string)($en['utm_json'] ?? ''), true) ?: [];
    $ref = (string)($en['referrer'] ?? '');
    $host = $ref !== '' ? preg_replace('/^www\./', '', (string)parse_url($ref, PHP_URL_HOST)) : '';
    $own = preg_replace('/^www\./', '', (string)parse_url((string)($en['page_url'] ?? ''), PHP_URL_HOST));
    $label = $utm['utm_source'] ?? ($host === '' ? 'Direct' : ($own !== '' && $host === $own ? 'Own site (internal page)' : $host));
    return ['utm' => $utm, 'referrer' => $ref, 'host' => $host, 'label' => $label, 'page' => (string)($en['source_page'] ?? '')];
}

/** The user's "Reply to" address for an entry: the first email-type field with a value. */
function entry_reply_email(array $form, array $data): string {
    foreach (form_input_fields($form) as $f) if ($f['type'] === 'email' && !empty($data[$f['name']])) return (string)$data[$f['name']];
    return '';
}
