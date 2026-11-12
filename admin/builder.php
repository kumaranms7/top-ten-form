<?php
require __DIR__ . '/_layout.php';
$me = require_login('editor');
$id = (int)($_GET['id'] ?? 0);
$form = site_form($id, $_SERVER['REQUEST_METHOD'] === 'POST');
if (!$form) { flash('Form not found.', 'err'); header('Location: forms.php'); exit; }

// ---- Save (JSON via fetch) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    header('Content-Type: application/json');
    $in = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($in)) { echo json_encode(['ok' => false, 'error' => 'Bad request']); exit; }
    try { $settings = sanitize_settings((array)($in['settings'] ?? [])); }
    catch (InvalidArgumentException $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage()]); exit; }
    $clean = sanitize_fields((array)($in['fields'] ?? []));
    $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 190) ?: $form['name'];
    q('UPDATE forms SET name=?, slug=?, fields_json=?, settings_json=? WHERE id=?', [$name, slugify($name), json_encode($clean, JSON_UNESCAPED_UNICODE), json_encode($settings, JSON_UNESCAPED_UNICODE), $id]);
    log_activity("Saved form #$id");
    echo json_encode(['ok' => true, 'fields' => $clean]); exit;
}

$fields = form_fields($form); $s = form_settings($form); $t = $s['theme'];
admin_header('Form builder', 'forms', '<meta name="csrf" content="' . e(csrf_token()) . '">', 'Drag fields onto the form, then fine-tune them on the right.');
echo form_tabs($id, 'fields');
$presets = theme_presets();
?>
<div class="savebar form-head"><label><input id="formName" value="<?= e($form['name']) ?>" placeholder="Form name"></label>
<span id="dirtyBadge" class="tag amber" hidden>Unsaved changes</span><span id="saveMsg" class="muted"></span>
<div class="actions"><button type="button" class="btn sec sm ic-only" id="undoBtn" data-tip="Undo (Ctrl+Z)" aria-label="Undo" disabled><?= icon('undo') ?></button><button type="button" class="btn sec sm ic-only" id="redoBtn" data-tip="Redo (Ctrl+Y)" aria-label="Redo" disabled><?= icon('redo') ?></button></div>
<a class="btn sec" href="preview.php?id=<?= $id ?>" target="_blank" rel="noopener"><?= icon('eye') ?>Preview</a><button class="btn" id="saveBtn">Save form</button></div>

<div class="builder">
  <div class="stage"><div class="stage-title">Form preview</div><div id="canvas"></div></div>
  <aside class="sidepanel">
    <div class="ptabs"><button type="button" data-tab="add" class="active">Add field</button><button type="button" data-tab="field">Field properties</button><button type="button" data-tab="form">Form properties</button></div>
    <div class="ptab" id="tab-add"><p class="muted" style="margin-top:0">Drag onto the form, or click to add.</p><div class="palette-grid" id="paletteGrid"></div></div>
    <div class="ptab" id="tab-field" hidden><div id="props"></div></div>
    <div class="ptab" id="settingsCard" hidden>
      <details open><summary>After submission</summary>
        <label class="inline"><input type="radio" name="s_mode" value="message" <?= $s['success_mode'] === 'message' ? 'checked' : '' ?>> Show a success message</label>
        <label class="inline"><input type="radio" name="s_mode" value="redirect" <?= $s['success_mode'] === 'redirect' ? 'checked' : '' ?>> Redirect to a Thank You page</label>
        <label>Success message<textarea id="s_success_message" rows="2"><?= e($s['success_message']) ?></textarea></label>
        <label>Thank You page URL <small>(full URL)</small><input id="s_redirect_url" value="<?= e($s['redirect_url']) ?>" placeholder="https://www.yoursite.com/thank-you.html"></label>
        <label class="inline"><input type="checkbox" id="s_pass_values" <?= !empty($s['pass_values']) ? 'checked' : '' ?>> Pass submitted values to the Thank You URL</label>
      </details>
      <details><summary>Look &amp; feel (theme)</summary>
        <label>Preset<select id="th_preset"><?php foreach ($presets as $k => $p) echo '<option value="' . $k . '"' . ($t['preset'] === $k ? ' selected' : '') . '>' . e($p['name']) . '</option>'; ?><option value="custom" <?= $t['preset'] === 'custom' ? 'selected' : '' ?>>Custom</option></select></label>
        <div class="row2"><label>Accent color<input type="color" id="th_accent" value="<?= e($t['accent']) ?>"></label><label>Text color<input type="color" id="th_text" value="<?= e($t['text']) ?>"></label>
        <label>Border color<input type="color" id="th_border" value="<?= e($t['border']) ?>"></label>
        <label>Form background<input type="color" id="th_bg" value="<?= e($t['bg'] ?: '#ffffff') ?>"><span class="inline"><input type="checkbox" id="th_bg_on" <?= $t['bg'] !== '' ? 'checked' : '' ?>> use background</span></label></div>
        <label>Corner radius: <b id="th_radius_v"><?= (int)$t['radius'] ?></b>px<input type="range" id="th_radius" min="0" max="30" value="<?= (int)$t['radius'] ?>"></label>
        <label>Text size: <b id="th_fs_v"><?= (int)$t['font_size'] ?></b>px<input type="range" id="th_fs" min="12" max="22" value="<?= (int)$t['font_size'] ?>"></label>
        <div class="row2"><label>Input style<select id="th_input"><?php foreach (['outlined' => 'Outlined', 'filled' => 'Filled', 'underline' => 'Underline'] as $k => $l) echo '<option value="' . $k . '"' . ($t['input_style'] === $k ? ' selected' : '') . ">$l</option>"; ?></select></label>
        <label>Button style<select id="th_button"><?php foreach (['solid' => 'Solid', 'outline' => 'Outline', 'pill' => 'Pill'] as $k => $l) echo '<option value="' . $k . '"' . ($t['button_style'] === $k ? ' selected' : '') . ">$l</option>"; ?></select></label></div>
        <p class="muted" style="margin-bottom:0">Use <b>Preview</b> (after saving) to see the real result.</p>
      </details>
      <details><summary>Steps &amp; saving</summary>
        <p class="muted" style="margin-top:0">Add a <b>Page break</b> field to split the form into steps with a progress bar. The page break's label becomes the next step's title.</p>
        <label class="inline"><input type="checkbox" id="s_save_resume" <?= !empty($s['save_resume']) ? 'checked' : '' ?>> Let visitors "Save &amp; continue later" (emails them a link, valid 30 days)</label>
        <label>Submit button text<input id="s_submit_text" value="<?= e($s['submit_text']) ?>"></label>
      </details>
      <details><summary>Custom CSS</summary>
        <p class="muted" style="margin-top:0">Applies only to this form (it can't affect the rest of your website).</p>
        <label><textarea id="s_custom_css" rows="7" class="code" spellcheck="false" placeholder=".tf-submit { letter-spacing: .05em; }"><?= e($s['custom_css']) ?></textarea></label>
        <label>Extra CSS class <small>(optional)</small><input id="s_css_class" value="<?= e($s['css_class']) ?>"></label>
      </details>
      <details><summary>Spam protection</summary>
        <p class="muted" style="margin-top:0"><b>On for every form.</b> Visitors answer one simple security check; the other layers are invisible to them. Suspected spam is kept in the Spam folder (never emailed) so nothing is lost.</p>
        <label>Protection level<select id="s_spam"><?php foreach (['standard' => 'Standard (recommended)', 'strict' => 'Strict (tighter limits, token required)', 'basic' => 'Basic (honeypot, rate limit and your rules only)'] as $k => $l) echo '<option value="' . $k . '"' . (($s['spam_protection'] ?? 'standard') === $k ? ' selected' : '') . ">$l</option>"; ?></select></label>
        <?php if (captcha_provider() !== 'none'): ?><div class="alert ok" style="margin:0 0 12px">Every form uses <b><?= captcha_provider() === 'turnstile' ? 'Cloudflare Turnstile' : 'Google reCAPTCHA' ?></b>, chosen in <a href="settings.php">Settings</a>. The built-in captcha below is not used while that is on.</div><?php endif; ?>
        <label>Captcha (built-in security check)<select id="s_captcha_type" <?= captcha_provider() !== 'none' ? 'disabled' : '' ?>><?php foreach (['math' => 'Math problem (recommended)', 'text' => 'Text image (type the characters)', 'question' => 'Question and answer (you write them)'] as $k => $l) echo '<option value="' . $k . '"' . (($s['captcha_type'] ?? 'math') === $k ? ' selected' : '') . ">$l</option>"; ?></select></label>
        <label id="capQWrap" <?= ($s['captcha_type'] ?? 'math') === 'question' ? '' : 'hidden' ?>>Your questions <small>(one per line: <code>Question | answer, other accepted answer</code>. One is picked at random. Leave empty to use simple built-in questions.)</small><textarea id="s_captcha_q" rows="4" placeholder="What is the name of our company? | Acme&#10;How many days in a week? | 7, seven"><?= e($s['captcha_questions'] ?? '') ?></textarea></label>
        <ul class="muted" style="margin:0 0 6px;padding-left:18px"><li>A captcha is required on every form</li><li>Hidden honeypot field and per-visitor rate limits</li><li>Server-signed, single-use form token and server-side timing</li><li>Link / HTML injection, disposable emails, repeated messages</li><li>Your own blocked words, domains and IPs (<a href="spam.php">Spam rules</a>)</li></ul>
      </details>
      <details><summary>Security &amp; storage</summary>
        <label>Allowed website origins <small>(one per line, e.g. https://www.yoursite.com. Empty = any site)</small><textarea id="s_allowed_origins" rows="3"><?= e($s['allowed_origins']) ?></textarea></label>
        <label class="inline"><input type="checkbox" id="s_save_entries" <?= $s['save_entries'] ? 'checked' : '' ?>> Save entries in the database</label>
      </details>
    </div>
  </aside>
</div>
<script>window.TF = {fields: <?= json_encode($fields) ?>, types: <?= json_encode(field_types()) ?>, presets: <?= json_encode($presets) ?>, saveUrl: 'builder.php?id=<?= $id ?>'};</script>
<?php admin_footer('<script src="assets/logic-editor.js?v=1"></script><script src="assets/builder.js?v=9"></script>');
