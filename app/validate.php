<?php
/**
 * Server-side validation of a form submission (the browser checks are only a convenience).
 * Handles every field type, conditional logic (hidden fields are ignored), calculated fields, uploads and signatures.
 *
 * @return array [errors[name=>msg], vals[name=>value], uploads[name=>['tmp'|'content','name','ext']]]
 */
function validate_submission(array $fields, array $post, array $filesIn): array {
    $errors = []; $vals = []; $uploads = [];
    $eff = []; // effective values (hidden fields count as empty), used for logic and formulas

    foreach ($fields as $f) {
        if (!is_input_field($f)) continue;
        $n = $f['name']; $label = $f['label'] ?: $n; $type = $f['type'];
        $req = !empty($f['required']);

        // ---- conditional logic: hidden fields are skipped entirely
        if (!logic_visible($f, $eff)) { $vals[$n] = $type === 'checkbox' ? [] : ''; $eff[$n] = $vals[$n]; continue; }

        if ($type === 'calculated') {
            $v = format_number(formula_eval((string)($f['formula'] ?? ''), $eff)); $vals[$n] = $v; $eff[$n] = $v; continue;
        }

        // ---- file upload
        if ($type === 'file') {
            $up = $filesIn[$n] ?? null;
            if (!$up || ($up['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { if ($req) $errors[$n] = "$label is required."; $vals[$n] = ''; $eff[$n] = ''; continue; }
            if ($up['error'] !== UPLOAD_ERR_OK) { $errors[$n] = 'Upload failed.'; continue; }
            $ext = strtolower(pathinfo($up['name'], PATHINFO_EXTENSION));
            $okExt = array_map('trim', explode(',', strtolower(setting('allowed_ext'))));
            if ($up['size'] > (int)setting('max_upload_mb', 5) * 1048576) { $errors[$n] = 'File is too large.'; continue; }
            if (!in_array($ext, $okExt, true)) { $errors[$n] = 'This file type is not allowed.'; continue; }
            if ($gate = upload_gate($up['tmp_name'], $up['name'], $ext)) { $errors[$n] = $gate; continue; }
            $uploads[$n] = ['tmp' => $up['tmp_name'], 'name' => basename($up['name']), 'ext' => $ext];
            $vals[$n] = basename($up['name']); $eff[$n] = $vals[$n]; continue;
        }

        // ---- signature (PNG data URL drawn on a canvas)
        if ($type === 'signature') {
            $d = (string)($post[$n] ?? '');
            if ($d === '') { if ($req) $errors[$n] = "$label is required."; $vals[$n] = ''; $eff[$n] = ''; continue; }
            if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $d, $m) || strlen($m[1]) > 600000) { $errors[$n] = 'Invalid signature.'; continue; }
            $bin = base64_decode($m[1], true);
            if ($bin === false || substr($bin, 0, 8) !== "\x89PNG\r\n\x1a\n") { $errors[$n] = 'Invalid signature.'; continue; }
            $uploads[$n] = ['content' => $bin, 'name' => 'signature.png', 'ext' => 'png'];
            $vals[$n] = 'signature.png'; $eff[$n] = 'signed'; continue;
        }

        // ---- composite fields: assemble from their parts (the format decides which parts exist)
        if ($type === 'name') {
            $fmt = $f['name_format'] ?? 'first_last';
            if ($fmt === 'full') { $v = trim((string)($post[$n . '_full'] ?? '')); $first = $v; $last = $v; }
            else {
                $first = trim((string)($post[$n . '_first'] ?? '')); $mid = $fmt === 'first_middle_last' ? trim((string)($post[$n . '_middle'] ?? '')) : ''; $last = trim((string)($post[$n . '_last'] ?? ''));
                $v = trim(implode(' ', array_filter([$first, $mid, $last], 'strlen')));
            }
            if ($v === '') { if ($req) $errors[$n] = "$label is required."; }
            elseif ($req && $fmt !== 'full' && ($first === '' || $last === '')) $errors[$n] = $first === '' ? 'Please enter your first name.' : 'Please enter your last name.';
            elseif (mb_strlen($v) > 300) $errors[$n] = 'Value is too long.';
            $vals[$n] = $v; $eff[$n] = $v; continue;
        }
        if ($type === 'address') {
            $fmt = $f['address_format'] ?? 'detailed';
            if ($fmt === 'full') { $line = trim((string)($post[$n . '_full'] ?? '')); }
            else {
                $p = [];
                foreach (['street', 'line2', 'city', 'state', 'zip', 'country'] as $k) $p[$k] = ($fmt === 'street_city_state_zip' && in_array($k, ['line2', 'country'], true)) ? '' : trim((string)($post[$n . '_' . $k] ?? ''));
                $line = implode(', ', array_filter([$p['street'], $p['line2'], $p['city'], trim($p['state'] . ' ' . $p['zip']), $p['country']], 'strlen'));
                if ($line !== '' && $req && ($p['street'] === '' || $p['city'] === '')) { $errors[$n] = 'Please enter at least the street and city.'; $vals[$n] = $line; $eff[$n] = $line; continue; }
            }
            if ($line === '') { if ($req) $errors[$n] = "$label is required."; }
            elseif (mb_strlen($line) > 600) $errors[$n] = 'Value is too long.';
            $vals[$n] = $line; $eff[$n] = $line; continue;
        }

        // ---- plain fields
        $v = $post[$n] ?? '';
        if ($type === 'checkbox') $v = is_array($v) ? array_values(array_filter(array_map(fn($x) => trim((string)$x), $v), 'strlen')) : [];
        else $v = is_array($v) ? '' : trim((string)$v);
        if ($type === 'hidden') { $vals[$n] = mb_substr((string)$v, 0, 1000); $eff[$n] = $vals[$n]; continue; }
        $empty = ($v === '' || $v === []);
        if ($empty) { if ($req) $errors[$n] = $type === 'consent' ? 'You must agree to continue.' : "$label is required."; $vals[$n] = $v; $eff[$n] = $v; continue; }

        if (in_array($type, ['select', 'radio'], true) && !in_array($v, $f['options'] ?? [], true)) $errors[$n] = 'Invalid selection.';
        elseif ($type === 'checkbox' && array_diff($v, $f['options'] ?? [])) $errors[$n] = 'Invalid selection.';
        elseif ($type === 'email' && !filter_var($v, FILTER_VALIDATE_EMAIL)) $errors[$n] = 'Enter a valid email address.';
        elseif ($type === 'number' && !is_numeric($v)) $errors[$n] = 'Enter a valid number.';
        elseif ($type === 'price' && (!is_numeric($v) || (float)$v < 0 || !preg_match('/^\d+(\.\d{1,2})?$/', $v))) $errors[$n] = 'Enter a valid amount (up to 2 decimals).';
        elseif ($type === 'tel') { [$perr, $pval] = validate_phone($v, $f['phone_format'] ?? 'any'); if ($perr) $errors[$n] = $perr; else $v = $pval; }
        elseif ($type === 'date' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) $errors[$n] = 'Enter a valid date.';
        elseif ($type === 'time' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v)) $errors[$n] = 'Enter a valid time.';
        elseif ($type === 'rating' && !(ctype_digit($v) && (int)$v >= 1 && (int)$v <= max(3, (int)($f['max'] ?? 5)))) $errors[$n] = 'Invalid rating.';
        elseif (is_string($v) && mb_strlen($v) > 10000) $errors[$n] = 'Value is too long.';
        $vals[$n] = $v; $eff[$n] = $v;
    }
    return [$errors, $vals, $uploads];
}

/** Phone numbers. US: exactly 10 digits, stored as (555) 123-4567. International: "+" then the country code and number (8-15 digits). Any: the old lenient rule. Returns [error, value]. */
function validate_phone(string $v, string $format): array {
    $digits = preg_replace('/\D/', '', $v);
    if ($format === 'us') {
        if (strlen($digits) === 11 && $digits[0] === '1') $digits = substr($digits, 1);
        if (strlen($digits) !== 10 || $digits[0] < '2') return ['Enter a 10-digit US phone number, like (555) 123-4567.', $v];
        return ['', '(' . substr($digits, 0, 3) . ') ' . substr($digits, 3, 3) . '-' . substr($digits, 6)];
    }
    if ($format === 'international') {
        if ($v[0] !== '+' || !preg_match('/^\+[0-9 ().\-]+$/', $v) || strlen($digits) < 8 || strlen($digits) > 15) return ['Enter the full number with the country code, like +44 20 7946 0958.', $v];
        return ['', preg_replace('/\s+/', ' ', $v)];
    }
    return preg_match('/^[0-9+().\-\s]{5,25}$/', $v) ? ['', $v] : ['Enter a valid phone number.', $v];
}
