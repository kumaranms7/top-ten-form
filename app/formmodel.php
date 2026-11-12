<?php
/** Form definition model: sanitizing fields/settings (builder save, JSON import, templates) and the theme system. */

function theme_presets(): array {
    $base = ['bg' => '', 'border' => '#cfd5dd', 'text' => '#1f2933', 'radius' => 8, 'font_size' => 16, 'input_style' => 'outlined', 'button_style' => 'solid'];
    return [
        'classic'  => ['name' => 'Classic blue', 'accent' => '#1e6fd9', 'radius' => 6] + $base,
        'violet'   => ['name' => 'Violet', 'accent' => '#7c3aed', 'radius' => 12, 'border' => '#ddd6fe'] + $base,
        'emerald'  => ['name' => 'Emerald', 'accent' => '#059669', 'radius' => 8, 'border' => '#a7f3d0'] + $base,
        'sunset'   => ['name' => 'Sunset', 'accent' => '#f97316', 'radius' => 24, 'button_style' => 'pill', 'border' => '#fed7aa'] + $base,
        'rose'     => ['name' => 'Rose (filled)', 'accent' => '#e11d48', 'radius' => 10, 'input_style' => 'filled', 'border' => '#fecdd3'] + $base,
        'slate'    => ['name' => 'Slate (minimal)', 'accent' => '#334155', 'radius' => 0, 'input_style' => 'underline', 'button_style' => 'outline'] + $base,
        'midnight' => ['name' => 'Midnight (dark)', 'accent' => '#818cf8', 'bg' => '#111827', 'text' => '#e5e7eb', 'border' => '#374151', 'radius' => 10, 'input_style' => 'filled'] + $base,
    ];
}
function theme_default(): array { return ['preset' => 'classic'] + array_diff_key(theme_presets()['classic'], ['name' => 1]); }

function sanitize_color(string $c, string $default = ''): string { $c = trim($c); return preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $c) ? strtolower($c) : $default; }

function sanitize_theme($t): array {
    $d = theme_default(); $t = is_array($t) ? $t : [];
    $out = ['preset' => isset(theme_presets()[$t['preset'] ?? '']) ? $t['preset'] : (($t['preset'] ?? '') === 'custom' ? 'custom' : $d['preset'])];
    $out['accent'] = sanitize_color((string)($t['accent'] ?? ''), $d['accent']);
    $out['text'] = sanitize_color((string)($t['text'] ?? ''), $d['text']);
    $out['border'] = sanitize_color((string)($t['border'] ?? ''), $d['border']);
    $out['bg'] = sanitize_color((string)($t['bg'] ?? ''), '');
    $out['radius'] = max(0, min(30, (int)($t['radius'] ?? $d['radius'])));
    $out['font_size'] = max(12, min(22, (int)($t['font_size'] ?? $d['font_size'])));
    $out['input_style'] = in_array($t['input_style'] ?? '', ['outlined', 'filled', 'underline'], true) ? $t['input_style'] : 'outlined';
    $out['button_style'] = in_array($t['button_style'] ?? '', ['solid', 'outline', 'pill'], true) ? $t['button_style'] : 'solid';
    return $out;
}

/** Inline CSS variables for the <form> element. Only values that differ from the stylesheet defaults are emitted. */
function theme_style(array $t): string {
    $t = sanitize_theme($t); $d = theme_default(); $css = '';
    foreach (['accent' => '--tf-accent', 'text' => '--tf-text', 'border' => '--tf-border'] as $k => $var) if ($t[$k] !== $d[$k]) $css .= "$var:{$t[$k]};";
    if ($t['bg'] !== '') $css .= "--tf-bg:{$t['bg']};";
    if ($t['radius'] !== $d['radius']) $css .= "--tf-radius:{$t['radius']}px;";
    if ($t['font_size'] !== $d['font_size']) $css .= "--tf-fs:{$t['font_size']}px;";
    return $css;
}
function theme_classes(array $t): string {
    $t = sanitize_theme($t); $c = '';
    if ($t['input_style'] !== 'outlined') $c .= ' tf-in-' . $t['input_style'];
    if ($t['button_style'] !== 'solid') $c .= ' tf-btn-' . $t['button_style'];
    if ($t['bg'] !== '') $c .= ' tf-has-bg';
    return $c;
}

/** Prefix every selector with the form's id so custom CSS can never affect the host website. */
function scope_css(string $css, string $scope): string {
    $css = preg_replace('#/\*.*?\*/#s', '', $css);
    $css = preg_replace('/@import[^;]*;?/i', '', $css);
    $css = preg_replace('/expression\s*\(|javascript\s*:|behavior\s*:|-moz-binding|<\s*\/?\s*style/i', '', $css);
    $out = ''; $len = strlen($css); $i = 0;
    while ($i < $len) {
        $brace = strpos($css, '{', $i); if ($brace === false) break;
        $sel = trim(substr($css, $i, $brace - $i));
        // find the matching closing brace
        $depth = 1; $j = $brace + 1;
        while ($j < $len && $depth > 0) { if ($css[$j] === '{') $depth++; elseif ($css[$j] === '}') $depth--; $j++; }
        $body = substr($css, $brace + 1, $j - $brace - 2);
        if ($sel !== '' && $sel[0] === '@') {
            if (preg_match('/^@(media|supports)\b/i', $sel)) $out .= $sel . '{' . scope_css($body, $scope) . '}';
            // other at-rules (@font-face, @keyframes, ...) are dropped
        } elseif ($sel !== '') {
            $parts = array_map(function ($s) use ($scope) {
                $s = trim($s); if ($s === '') return '';
                $s = preg_replace('/^(html|body|:root)\b\s*/i', '', $s);
                return $scope . ($s === '' ? '' : ' ' . $s);
            }, explode(',', $sel));
            $out .= implode(',', array_filter($parts)) . '{' . $body . '}';
        }
        $i = $j;
    }
    return $out;
}

function sanitize_logic($l, bool $withAction = true): ?array {
    if (!is_array($l) || empty($l['rules']) || !is_array($l['rules'])) return null;
    $rules = [];
    foreach (array_slice($l['rules'], 0, 12) as $r) {
        $f = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($r['field'] ?? '')));
        $op = (string)($r['op'] ?? 'is');
        if ($f === '' || !isset(LOGIC_OPS[$op])) continue;
        $rules[] = ['field' => $f, 'op' => $op, 'value' => mb_substr((string)($r['value'] ?? ''), 0, 190)];
    }
    if (!$rules) return null;
    $out = ['match' => ($l['match'] ?? 'all') === 'any' ? 'any' : 'all', 'rules' => $rules];
    if ($withAction) $out = ['action' => ($l['action'] ?? 'show') === 'hide' ? 'hide' : 'show'] + $out;
    return $out;
}

/** Clean a list of field definitions coming from the builder, an import file or a template. */
function sanitize_fields(array $in): array {
    $types = field_types(); $clean = []; $used = [];
    foreach ($in as $f) {
        if (!is_array($f) || !isset($types[$f['type'] ?? ''])) continue;
        $type = $f['type'];
        $name = preg_replace('/[^a-z0-9_]/', '', strtolower(str_replace([' ', '-'], '_', (string)($f['name'] ?? ''))));
        if ($name === '' || strpos($name, 'tf_') === 0 || $name === 'id') $name = $type . '_' . (count($clean) + 1);
        if ($type === 'captcha') { if (!empty($seenCaptcha)) continue; $seenCaptcha = true; }   // one captcha per form
        if (in_array($type, ['heading', 'paragraph', 'pagebreak', 'captcha'], true)) {
            $name = $type . '_' . (count($clean) + 1);   // layout items never claim a data field name (a heading called "Email" must not rename the real Email field)
        } else {
            $base = $name; $i = 2; while (isset($used[$name])) $name = $base . '_' . $i++;
            $used[$name] = 1;
        }
        $opts = array_values(array_filter(array_map('trim', array_map('strval', (array)($f['options'] ?? []))), 'strlen'));
        $formula = preg_replace('/[^0-9a-z_{}+\-*\/(). ]/i', '', (string)($f['formula'] ?? ''));
        $clean[] = [
            'id' => preg_replace('/[^a-z0-9_]/i', '', (string)($f['id'] ?? 'f' . count($clean))) ?: 'f' . count($clean), 'type' => $type, 'name' => $name,
            'label' => mb_substr(trim((string)($f['label'] ?? '')), 0, $type === 'consent' ? 3000 : 500), 'placeholder' => mb_substr((string)($f['placeholder'] ?? ''), 0, 255),
            'default' => mb_substr((string)($f['default'] ?? ''), 0, 255), 'help' => mb_substr((string)($f['help'] ?? ''), 0, 500),
            'required' => !empty($f['required']) && !in_array($type, ['heading', 'paragraph', 'pagebreak', 'captcha', 'hidden', 'calculated'], true),
            'width' => in_array($f['width'] ?? '', ['full', 'half', 'third'], true) ? $f['width'] : 'full',
            'css_class' => preg_replace('/[^A-Za-z0-9_\- ]/', '', (string)($f['css_class'] ?? '')),
            'options' => in_array($type, ['select', 'radio', 'checkbox'], true) ? $opts : [],
            'formula' => $type === 'calculated' ? mb_substr($formula, 0, 300) : '',
            'currency' => in_array($type, ['price', 'calculated'], true) ? mb_substr(trim((string)($f['currency'] ?? '$')), 0, 3) : '',
            'max' => $type === 'rating' ? max(3, min(10, (int)($f['max'] ?? 5))) : 0,
            'placeholders' => in_array($type, ['name', 'address'], true) ? array_filter(array_intersect_key(array_map(fn($v) => mb_substr(trim((string)$v), 0, 100), is_array($f['placeholders'] ?? null) ? $f['placeholders'] : []),
                array_flip(['full', 'first', 'middle', 'last', 'street', 'line2', 'city', 'state', 'zip', 'country'])), 'strlen') : [],
            'name_format' => $type === 'name' ? (in_array($f['name_format'] ?? '', ['first_last', 'first_middle_last', 'full'], true) ? $f['name_format'] : 'first_last') : '',
            'address_format' => $type === 'address' ? (in_array($f['address_format'] ?? '', ['street_city_state_zip', 'detailed', 'full'], true) ? $f['address_format'] : 'detailed') : '',
            'phone_format' => $type === 'tel' ? (in_array($f['phone_format'] ?? '', ['us', 'international', 'any'], true) ? $f['phone_format'] : 'any') : '',
            'captcha_type' => $type === 'captcha' ? (in_array($f['captcha_type'] ?? '', ['math', 'text', 'question'], true) ? $f['captcha_type'] : 'math') : '',
            'logic' => in_array($type, ['pagebreak', 'captcha'], true) ? null : sanitize_logic($f['logic'] ?? null, true),
        ];
    }
    return $clean;
}

/** Clean form settings. Throws InvalidArgumentException with a user-facing message on bad input. */
function sanitize_settings(array $s): array {
    $ru = trim((string)($s['redirect_url'] ?? ''));
    $mode = ($s['success_mode'] ?? '') === 'redirect' ? 'redirect' : 'message';
    if ($mode === 'redirect' && !preg_match('#^https?://#i', $ru)) throw new InvalidArgumentException('Thank-you page URL must start with http:// or https://');
    return [
        'submit_text' => mb_substr(trim((string)($s['submit_text'] ?? '')) ?: 'Submit', 0, 100), 'success_mode' => $mode,
        'success_message' => mb_substr(trim((string)($s['success_message'] ?? '')) ?: 'Thank you!', 0, 1000), 'redirect_url' => $ru,
        'pass_values' => !empty($s['pass_values']) ? 1 : 0, 'allowed_origins' => trim((string)($s['allowed_origins'] ?? '')),
        'captcha_type' => in_array($s['captcha_type'] ?? '', ['math', 'text', 'question'], true) ? $s['captcha_type'] : 'math',
        'captcha_questions' => mb_substr((string)($s['captcha_questions'] ?? ''), 0, 3000), 'css_class' => preg_replace('/[^A-Za-z0-9_\- ]/', '', (string)($s['css_class'] ?? '')),
        'save_entries' => !empty($s['save_entries']) ? 1 : 0, 'save_resume' => !empty($s['save_resume']) ? 1 : 0,
        'spam_protection' => in_array($s['spam_protection'] ?? '', ['standard', 'strict', 'basic'], true) ? $s['spam_protection'] : 'standard',
        'theme' => sanitize_theme($s['theme'] ?? null), 'custom_css' => mb_substr((string)($s['custom_css'] ?? ''), 0, 20000),
    ];
}

/** Clean a tag list ("a, b" or array): trimmed, no commas, max 40 chars each, no duplicates (case-insensitive), max 10. Returns an array. */
function clean_tags($raw): array {
    $out = [];
    foreach (is_array($raw) ? $raw : preg_split('/[,;\n]+/', (string)$raw) as $t) {
        $t = trim(preg_replace('/\s+/', ' ', strip_tags((string)$t))); if ($t === '') continue;
        $t = mb_substr($t, 0, 40); $out[mb_strtolower($t)] ??= $t;
    }
    return array_slice(array_values($out), 0, 10);
}
function form_tags(array $form): array { return clean_tags($form['tags'] ?? ''); }

/** Safe inline HTML for consent text: only links (http/https/mailto/tel), bold, italic, underline and line breaks. Everything else is escaped/stripped. */
function safe_inline_html(string $s): string {
    $s = strip_tags($s, '<a><b><strong><i><em><u><br>');
    return preg_replace_callback('~<(/?)(a|b|strong|i|em|u|br)(?=[\s/>])([^>]*)>~i', function ($m) {
        $t = strtolower($m[2]);
        if ($m[1] === '/') return $t === 'br' ? '' : "</$t>";
        if ($t !== 'a') return $t === 'br' ? '<br>' : "<$t>";
        if (!preg_match("~href\\s*=\\s*(?:\"([^\"]*)\"|'([^']*)'|([^\\s>]+))~i", $m[3], $h)) return '<a>';
        $url = trim(html_entity_decode($h[1] ?: ($h[2] ?: ($h[3] ?? '')), ENT_QUOTES));
        if (!preg_match('~^(https?://|mailto:|tel:)~i', $url)) return '<a>';
        return '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" target="_blank" rel="noopener noreferrer">';
    }, $s);
}
