<?php
/** Generates the static, copy-paste HTML for a form. No PHP needed on the target website. */

function form_defaults(): array {
    return [
        'submit_text' => 'Submit', 'success_mode' => 'message', 'success_message' => 'Thank you! Your message has been sent.',
        'redirect_url' => '', 'allowed_origins' => '', 'captcha_type' => 'question', 'captcha_questions' => '', 'css_class' => '', 'save_entries' => 1, 'pass_values' => 0,
        'save_resume' => 0, 'spam_protection' => 'standard', 'theme' => theme_default(), 'custom_css' => '',
    ];
}
function form_settings(array $form): array {
    $s = array_merge(form_defaults(), json_decode($form['settings_json'] ?? '[]', true) ?: []);
    $s['theme'] = sanitize_theme($s['theme']);
    return $s;
}
function form_fields(array $form): array { return json_decode($form['fields_json'] ?? '[]', true) ?: []; }

/** Compact logic description the form script understands: {"a":"show","m":"all","r":[["field","op","value"],...]} */
function logic_attr(array $f): string {
    $l = $f['logic'] ?? null;
    if (!$l || empty($l['rules'])) return '';
    $r = array_map(fn($x) => [$x['field'], $x['op'], $x['value']], $l['rules']);
    return ' data-logic="' . e(json_encode(['a' => $l['action'] ?? 'show', 'm' => $l['match'] ?? 'all', 'r' => $r], JSON_UNESCAPED_UNICODE)) . '"';
}

/** Will this conditional field be hidden before any visitor input? (a "show when ..." rule that needs a value to match) */
function logic_starts_hidden(array $f): bool {
    $l = $f['logic'] ?? null;
    if (!$l || empty($l['rules']) || ($l['action'] ?? 'show') !== 'show') return false;
    $needsValue = fn($r) => in_array($r['op'] ?? '', ['is', 'contains', 'not_empty', 'gt', 'lt'], true) && (($r['value'] ?? '') !== '' || ($r['op'] ?? '') === 'not_empty');
    $hits = array_map($needsValue, $l['rules']);
    return ($l['match'] ?? 'all') === 'any' ? !in_array(false, $hits, true) : in_array(true, $hits, true);
}

/** Captcha markup. Built-in: question/image box + answer. Google/Cloudflare: an empty placeholder that form.js fills in only when the visitor starts the form (keeps the third-party script out of page load). */
function captcha_block_html(string $type, string $label): string {
    $lab = '<span class="tf-label tf-cap-lab"><span class="tf-cap-lt" data-def="' . e($label) . '">' . e($label) . '</span> <span class="tf-req">*</span></span>';
    if (($prov = captcha_provider()) !== 'none') {
        return '<div class="tf-field tf-full tf-captcha tf-ext-cap" data-name="tf_captcha" data-type="captcha" data-provider="' . e($prov) . '" data-sitekey="' . e(captcha_cfg()[1]) . '">' . $lab
            . '<div class="tf-ext-slot"></div><span class="tf-error" aria-live="polite"></span></div>';
    }
    return '<div class="tf-field tf-full tf-captcha" data-name="tf_captcha" data-type="captcha" data-ctype="' . e($type) . '">' . $lab
        . '<div class="tf-cap-box"><div class="tf-cap-q" aria-live="polite">Loading...</div><button type="button" class="tf-cap-refresh" aria-label="Show a new security check" title="New one">&#8635;</button></div>'
        . '<input type="text" name="tf_captcha_answer" class="tf-cap-ans" autocomplete="off" autocapitalize="off" spellcheck="false" required aria-label="Your answer to the security check" placeholder="Your answer"><input type="hidden" name="tf_captcha_id" value="">'
        . ($type !== 'question' ? '<button type="button" class="tf-cap-alt">Can&rsquo;t see it? Use a text question instead</button>' : '')
        . '<span class="tf-error" aria-live="polite"></span></div>';
}

function render_field_html(array $f, int $fid): string {
    $id = "tf{$fid}-" . $f['id'];
    $name = e($f['name']);
    $type = $f['type'];
    $req = !empty($f['required']) ? ' required' : '';
    $star = !empty($f['required']) ? ' <span class="tf-req">*</span>' : '';
    $cls = 'tf-field tf-' . e($f['width'] ?? 'full') . (($f['css_class'] ?? '') !== '' ? ' ' . e($f['css_class']) : '');
    if (logic_starts_hidden($f)) $cls .= ' tf-hidden';   // fields that only appear on a condition start hidden (no layout jump when the script runs)
    $attr = ' data-name="' . $name . '" data-type="' . e($type) . '"' . logic_attr($f);
    $help = ($f['help'] ?? '') !== '' ? '<small class="tf-help">' . e($f['help']) . '</small>' : '';
    $ph = ($f['placeholder'] ?? '') !== '' ? ' placeholder="' . e($f['placeholder']) . '"' : '';
    $pp = function (string $k, string $fallback = '') use ($f) { $v = (string)(($f['placeholders'] ?? [])[$k] ?? '') ?: $fallback; return $v !== '' ? ' placeholder="' . e($v) . '"' : ''; };
    $label = '<label class="tf-label" for="' . $id . '">' . e($f['label']) . $star . '</label>';
    $plainLabel = '<span class="tf-label">' . e($f['label']) . $star . '</span>';
    $opts = $f['options'] ?? [];
    $err = '<span class="tf-error" aria-live="polite"></span>';
    $cur = e($f['currency'] ?? '');
    $curSpan = $cur !== '' ? '<span class="tf-cur">' . $cur . '</span>' : '';

    switch ($type) {
        case 'captcha':
            return captcha_block_html((string)($f['captcha_type'] ?: 'question'), trim((string)$f['label']) ?: 'Security check');
        case 'heading':
            return "<div class=\"tf-field tf-full\"$attr><h3 class=\"tf-heading\">" . e($f['label']) . '</h3></div>';
        case 'paragraph':
            return "<div class=\"tf-field tf-full\"$attr><p class=\"tf-paragraph\">" . nl2br(e($f['label'])) . '</p></div>';
        case 'hidden':
            return '<input type="hidden" name="' . $name . '" value="' . e($f['default'] ?? '') . '">';
        case 'textarea':
            return "<div class=\"$cls\"$attr>$label<textarea id=\"$id\" name=\"$name\" rows=\"4\"$ph$req>" . e($f['default'] ?? '') . "</textarea>$help$err</div>";
        case 'select':
            $o = '<option value="">' . e(($f['placeholder'] ?? '') ?: 'Select...') . '</option>';
            foreach ($opts as $op) $o .= '<option value="' . e($op) . '"' . (($f['default'] ?? '') === $op ? ' selected' : '') . '>' . e($op) . '</option>';
            return "<div class=\"$cls\"$attr>$label<select id=\"$id\" name=\"$name\"$req>$o</select>$help$err</div>";
        case 'radio':
        case 'checkbox':
            $g = '';
            foreach ($opts as $i => $op) {
                $oid = $id . '-' . $i;
                $nm = $type === 'checkbox' ? $name . '[]' : $name;
                $g .= '<label class="tf-choice" for="' . $oid . '"><input type="' . $type . '" id="' . $oid . '" name="' . $nm . '" value="' . e($op) . '"' . ($type === 'radio' ? $req : '') . '> <span>' . e($op) . '</span></label>';
            }
            return "<div class=\"$cls\"$attr data-group=\"$type\"" . (!empty($f['required']) ? ' data-required="1"' : '') . ">$plainLabel<div class=\"tf-choices\">$g</div>$help$err</div>";
        case 'consent':
            return "<div class=\"$cls\"$attr><label class=\"tf-choice\" for=\"$id\"><input type=\"checkbox\" id=\"$id\" name=\"$name\" value=\"Yes\"$req> <span>" . safe_inline_html((string)$f['label']) . "$star</span></label>$help$err</div>";
        case 'file':
            return "<div class=\"$cls\"$attr>$label<input type=\"file\" id=\"$id\" name=\"$name\"$req>$help$err</div>";
        case 'name':
            $fmt = $f['name_format'] ?? 'first_last';
            if ($fmt === 'full') return "<div class=\"$cls\"$attr>$label<input type=\"text\" id=\"$id\" name=\"{$name}_full\" autocomplete=\"name\" data-part=\"full\"" . $pp('full', (string)($f['placeholder'] ?? '')) . "$req>$help$err</div>";
            $mid = $fmt === 'first_middle_last' ? "<div class=\"tf-col\"><input type=\"text\" name=\"{$name}_middle\" autocomplete=\"additional-name\" data-part=\"middle\"" . $pp('middle') . "><span class=\"tf-sub\">Middle name</span></div>" : '';
            return "<div class=\"$cls\"$attr>$plainLabel<div class=\"tf-cols\"><div class=\"tf-col\"><input type=\"text\" id=\"$id\" name=\"{$name}_first\" autocomplete=\"given-name\" data-part=\"first\"" . $pp('first') . "$req><span class=\"tf-sub\">First name</span></div>"
                . $mid . "<div class=\"tf-col\"><input type=\"text\" name=\"{$name}_last\" autocomplete=\"family-name\" data-part=\"last\"" . $pp('last') . "$req><span class=\"tf-sub\">Last name</span></div></div>$help$err</div>";
        case 'address':
            $fmt = $f['address_format'] ?? 'detailed'; $r = !empty($f['required']) ? ' required' : '';
            if ($fmt === 'full') return "<div class=\"$cls\"$attr>$label<input type=\"text\" id=\"$id\" name=\"{$name}_full\" autocomplete=\"street-address\" data-part=\"full\"" . $pp('full', (string)($f['placeholder'] ?? '') ?: 'Street, City, State ZIP') . "$req>$help$err</div>";
            if ($fmt === 'street_city_state_zip') return "<div class=\"$cls\"$attr>$plainLabel<div class=\"tf-addr\">"
                . "<div class=\"tf-col tf-wide\"><input type=\"text\" id=\"$id\" name=\"{$name}_street\" autocomplete=\"address-line1\" data-part=\"street\"" . $pp('street') . "$r><span class=\"tf-sub\">Street address</span></div>"
                . "<div class=\"tf-col tf-c2\"><input type=\"text\" name=\"{$name}_city\" autocomplete=\"address-level2\" data-part=\"city\"" . $pp('city') . "$r><span class=\"tf-sub\">City</span></div>"
                . "<div class=\"tf-col tf-c4\"><input type=\"text\" name=\"{$name}_state\" autocomplete=\"address-level1\" data-part=\"state\"" . $pp('state') . "><span class=\"tf-sub\">State</span></div>"
                . "<div class=\"tf-col tf-c4\"><input type=\"text\" name=\"{$name}_zip\" autocomplete=\"postal-code\" data-part=\"zip\"" . $pp('zip') . "><span class=\"tf-sub\">ZIP</span></div></div>$help$err</div>";
            return "<div class=\"$cls\"$attr>$plainLabel<div class=\"tf-addr\">"
                . "<div class=\"tf-col tf-wide\"><input type=\"text\" id=\"$id\" name=\"{$name}_street\" autocomplete=\"address-line1\" data-part=\"street\"" . $pp('street') . "$r><span class=\"tf-sub\">Street address</span></div>"
                . "<div class=\"tf-col tf-wide\"><input type=\"text\" name=\"{$name}_line2\" autocomplete=\"address-line2\" data-part=\"line2\"" . $pp('line2') . "><span class=\"tf-sub\">Apt, suite (optional)</span></div>"
                . "<div class=\"tf-col\"><input type=\"text\" name=\"{$name}_city\" autocomplete=\"address-level2\" data-part=\"city\"" . $pp('city') . "$r><span class=\"tf-sub\">City</span></div>"
                . "<div class=\"tf-col\"><input type=\"text\" name=\"{$name}_state\" autocomplete=\"address-level1\" data-part=\"state\"" . $pp('state') . "><span class=\"tf-sub\">State / Province</span></div>"
                . "<div class=\"tf-col\"><input type=\"text\" name=\"{$name}_zip\" autocomplete=\"postal-code\" data-part=\"zip\"" . $pp('zip') . "><span class=\"tf-sub\">ZIP / Postal code</span></div>"
                . "<div class=\"tf-col\"><input type=\"text\" name=\"{$name}_country\" autocomplete=\"country-name\" data-part=\"country\"" . $pp('country') . "><span class=\"tf-sub\">Country</span></div>"
                . "</div>$help$err</div>";
        case 'rating':
            $max = max(3, min(10, (int)($f['max'] ?? 5))); $g = '';
            for ($i = $max; $i >= 1; $i--) $g .= "<input type=\"radio\" id=\"$id-$i\" name=\"$name\" value=\"$i\"><label for=\"$id-$i\" title=\"$i\">&#9733;</label>";
            return "<div class=\"$cls\"$attr data-group=\"radio\"" . (!empty($f['required']) ? ' data-required="1"' : '') . ">$plainLabel<div class=\"tf-stars\" role=\"radiogroup\">$g</div>$help$err</div>";
        case 'time':
            return "<div class=\"$cls\"$attr>$label<input type=\"time\" id=\"$id\" name=\"$name\"" . (($f['default'] ?? '') !== '' ? ' value="' . e($f['default']) . '"' : '') . "$req>$help$err</div>";
        case 'price':
            return "<div class=\"$cls\"$attr>$label<div class=\"tf-money\">$curSpan<input type=\"number\" id=\"$id\" name=\"$name\" step=\"0.01\" min=\"0\" inputmode=\"decimal\"$ph" . (($f['default'] ?? '') !== '' ? ' value="' . e($f['default']) . '"' : '') . "$req></div>$help$err</div>";
        case 'calculated':
            return "<div class=\"$cls\"$attr>$label<div class=\"tf-money\">$curSpan<input type=\"text\" id=\"$id\" name=\"$name\" readonly tabindex=\"-1\" value=\"0\" data-formula=\"" . e($f['formula'] ?? '') . "\"></div>$help</div>";
        case 'signature':
            return "<div class=\"$cls\"$attr>$plainLabel<div class=\"tf-sigbox\"><canvas class=\"tf-sig\" width=\"640\" height=\"170\" aria-label=\"Signature pad\"></canvas>"
                . "<button type=\"button\" class=\"tf-sig-clear\">Clear</button></div><input type=\"hidden\" name=\"$name\" value=\"\"$req>$help$err</div>";
        default: // text, email, tel, number, date
            $t = in_array($type, ['text', 'email', 'tel', 'number', 'date'], true) ? $type : 'text';
            $val = ($f['default'] ?? '') !== '' ? ' value="' . e($f['default']) . '"' : '';
            if ($t === 'tel') {
                $pf = $f['phone_format'] ?? 'any';
                if ($pf === 'us') { if ($ph === '') $ph = ' placeholder="(555) 123-4567"'; $ph .= ' inputmode="tel" maxlength="14" autocomplete="tel-national" data-phone="us"'; }
                elseif ($pf === 'international') { if ($ph === '') $ph = ' placeholder="+44 20 7946 0958"'; $ph .= ' inputmode="tel" maxlength="26" autocomplete="tel" data-phone="intl"'; }
            }
            return "<div class=\"$cls\"$attr>$label<input type=\"$t\" id=\"$id\" name=\"$name\"$ph$val$req>$help$err</div>";
    }
}

/** Cache-busted URL of a minified frontend asset (version = file mtime, so browsers can cache it for a year). */
function asset_url(string $baseUrl, string $file): string {
    return $baseUrl . '/public/assets/' . $file . '?v=' . (@filemtime(TF_ROOT . '/public/assets/' . $file) ?: 1);
}

/** Split the field list into steps at each "page break" field. Returns [['title'=>..., 'fields'=>[...]], ...]. */
function form_steps(array $fields): array {
    $steps = [['title' => '', 'fields' => []]];
    foreach ($fields as $f) {
        if ($f['type'] === 'pagebreak') {
            if (!$steps[count($steps) - 1]['fields'] && count($steps) === 1) { $steps[0]['title'] = $f['label']; continue; } // page break at the very top just titles step 1
            $steps[] = ['title' => $f['label'], 'fields' => []]; continue;
        }
        $steps[count($steps) - 1]['fields'][] = $f;
    }
    return array_values(array_filter($steps, fn($s, $i) => $s['fields'] || $i === 0, ARRAY_FILTER_USE_BOTH));
}

/** Just the <form>...</form> markup. */
function render_form_markup(array $form, string $baseUrl): string {
    $fid = (int)$form['id'];
    $s = form_settings($form);
    $fields = form_fields($form);
    $hasFile = false; foreach ($fields as $f) if ($f['type'] === 'file') $hasFile = true;
    $steps = form_steps($fields); $multi = count($steps) > 1;

    $body = '';
    if ($multi) {
        $total = count($steps);
        $body .= "    <div class=\"tf-progress\" data-total=\"$total\"><div class=\"tf-bar\"><span></span></div><div class=\"tf-steptitle\"></div></div>\n";
        foreach ($steps as $i => $st) {
            $inner = ''; foreach ($st['fields'] as $f) $inner .= '        ' . render_field_html($f, $fid) . "\n";
            $body .= "    <fieldset class=\"tf-step\" data-step=\"$i\" data-title=\"" . e($st['title']) . "\"><div class=\"tf-fields\">\n$inner    </div></fieldset>\n";
        }
    } else {
        $inner = ''; foreach ($steps[0]['fields'] as $f) $inner .= '        ' . render_field_html($f, $fid) . "\n";
        $body .= "    <div class=\"tf-fields\">\n$inner    </div>\n";
    }

    if (($fc = form_captcha($form)) && !$fc['field']) { // captcha is mandatory for every form (Settings) and the form has no Captcha field: add one at the end (last step)
        $capHtml = '    ' . captcha_block_html($fc['type'], $fc['label']) . "
";
        if ($multi) { $pos = strrpos($body, '</div></fieldset>'); if ($pos !== false) $body = substr($body, 0, $pos) . '</div>' . $capHtml . '</fieldset>' . substr($body, $pos + strlen('</div></fieldset>')); }
        else $body .= $capHtml;
    }
    $cls = 'tf-form' . ($s['css_class'] ? ' ' . e($s['css_class']) : '') . theme_classes($s['theme']);
    $style = theme_style($s['theme']);
    $css = trim((string)$s['custom_css']) !== '' ? scope_css($s['custom_css'], "#tf-form-$fid") : '';

    $out  = "<form class=\"$cls\" id=\"tf-form-{$fid}\" data-form-id=\"{$fid}\"" . ($style ? ' style="' . e($style) . '"' : '') . " action=\"{$baseUrl}/public/submit.php\" method=\"post\"" . ($hasFile ? ' enctype="multipart/form-data"' : '') . " novalidate>\n";
    if ($css !== '') $out .= "    <style>$css</style>\n";
    $out .= "    <input type=\"hidden\" name=\"tf_form_id\" value=\"{$fid}\">\n";
    $out .= $body;
    $out .= "    <div class=\"tf-hp\" aria-hidden=\"true\"><label>Leave this empty<input type=\"text\" name=\"tf_website\" tabindex=\"-1\" autocomplete=\"off\"></label></div>\n";
    $out .= "    <div class=\"tf-actions\">\n";
    if ($multi) $out .= "        <button type=\"button\" class=\"tf-prev\">Previous</button><button type=\"button\" class=\"tf-next\">Next</button>\n";
    $out .= "        <button type=\"submit\" class=\"tf-submit\">" . e($s['submit_text']) . "</button>\n";
    if (!empty($s['save_resume'])) $out .= "        <button type=\"button\" class=\"tf-save-later\">Save &amp; continue later</button>\n";
    $out .= "    </div>\n";
    $out .= "    <div class=\"tf-message\" role=\"status\" aria-live=\"polite\"></div>\n";
    return $out . "</form>\n";
}

/** Full static code: CSS link + form + JS link (the "Full HTML" option, also what the PHP embed fetches). */
function render_form_html(array $form, string $baseUrl): string {
    return "<!-- TopTen Form: " . e($form['name']) . " -->\n"
        . "<link rel=\"stylesheet\" href=\"" . asset_url($baseUrl, 'form.min.css') . "\">\n\n"
        . render_form_markup($form, $baseUrl) . "\n"
        . "<script src=\"" . asset_url($baseUrl, 'form.min.js') . "\" defer></script>\n";
}

/** Rough rendered height (px) of a form, used to reserve its space before the JavaScript embed injects it (prevents layout shift). Deliberately a little low. */
function form_est_height(array $form): int {
    $fc = form_captcha($form); $steps = form_steps(form_fields($form)); $best = 0;
    foreach ($steps as $i => $st) {
        $h = 0; $row = 0.0;
        $list = $st['fields'];
        if ($fc && !$fc['field'] && $i === count($steps) - 1) $list[] = ['type' => 'captcha', 'width' => 'full', 'captcha_type' => $fc['type']];
        foreach ($list as $f) {
            $t = $f['type'];
            if ($t === 'hidden' || logic_starts_hidden($f)) continue;
            $fh = match (true) {
                $t === 'textarea' => 140, $t === 'signature' => 230, $t === 'rating' => 70, $t === 'heading' => 36, $t === 'paragraph' => 48, $t === 'consent' => 44,
                $t === 'name' => ($f['name_format'] ?? '') === 'full' ? 80 : 90, $t === 'address' => ($f['address_format'] ?? '') === 'full' ? 80 : (($f['address_format'] ?? '') === 'street_city_state_zip' ? 150 : 230),
                in_array($t, ['radio', 'checkbox'], true) => 30 + 28 * max(1, count($f['options'] ?? [])),
                $t === 'captcha' => captcha_provider() !== 'none' ? 105 : (($f['captcha_type'] ?? '') === 'question' ? 100 : 190),
                default => 96,
            };
            $w = ['full' => 1.0, 'half' => 0.5, 'third' => 0.34][$f['width'] ?? 'full'] ?? 1.0;
            if ($row + $w > 1.01) { $row = 0.0; }
            if ($row == 0.0) $h += $fh;   // a new row starts: it is as tall as its first field
            $row += $w;
            if ($row >= 0.99) $row = 0.0;
        }
        $best = max($best, $h);
    }
    return (int)round(($best + 90 + (count($steps) > 1 ? 50 : 0)) * 0.97);   // + button row
}
