<?php
require __DIR__ . '/_layout.php';
require_login('editor');
$id = (int)($_GET['id'] ?? 0);
$form = site_form($id);
if (!$form) { header('Location: forms.php'); exit; }
$base = base_url();
$html = render_form_html($form, $base);
$embedHtml = $base . '/public/embed.php?id=' . $id . '&type=html';
$embedJs = $base . '/public/embed.php?id=' . $id . '&type=js';

$safeName = trim(str_replace(['-->', '*/', '?>'], '', preg_replace('/\s+/', ' ', $form['name'])));
$jsCode = "<!-- TopTen Form: " . $safeName . " -->\n<div id=\"topten-form-$id\"></div>\n<script src=\"$embedJs\" async></script>\n";

$phpCode = <<<PHP
<?php
// TopTen Form: {$safeName}  (always shows the latest saved version of the form)
\$tf_url   = '$embedHtml';
\$tf_cache = sys_get_temp_dir() . '/topten_form_$id.html';
\$tf_ttl   = 300; // seconds to cache. Edits you make in the builder appear on your page within this time.

if (!is_file(\$tf_cache) || time() - filemtime(\$tf_cache) > \$tf_ttl) {
    \$tf_html = false;
    if (function_exists('curl_init')) {
        \$c = curl_init(\$tf_url);
        curl_setopt_array(\$c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_FOLLOWLOCATION => true]);
        \$tf_html = curl_exec(\$c);
        curl_close(\$c);
    } else {
        \$tf_html = @file_get_contents(\$tf_url, false, stream_context_create(['http' => ['timeout' => 4]]));
    }
    if (\$tf_html !== false && strpos(\$tf_html, 'tf-form') !== false) { @file_put_contents(\$tf_cache, \$tf_html, LOCK_EX); }
    elseif (is_file(\$tf_cache)) { @touch(\$tf_cache); } // fetch failed: keep showing the last good copy
}
echo is_file(\$tf_cache) ? file_get_contents(\$tf_cache) : '';
?>
PHP;
$options = [
    'html' => ['Full HTML', 'static', $html, 20],
    'php' => ['PHP embed', 'auto-updates', $phpCode, 21],
    'js' => ['JavaScript embed', 'auto-updates', $jsCode, 6],
];
admin_header('Get code', 'forms', '', 'Pick how to add "' . $form['name'] . '" to your website. No iframes: the real form is placed directly in your page.');
echo form_tabs($id, 'code'); ?>
<div class="card preview-bar"><div class="devices" role="group" aria-label="Code type">
<?php $first = true; foreach ($options as $k => [$label, $badge]): ?><button type="button" data-opt="<?= $k ?>" class="<?= $first ? 'active' : '' ?>"><?= e($label) ?><span class="tag <?= $badge === 'static' ? '' : 'green' ?>" style="margin-left:6px"><?= e($badge) ?></span></button><?php $first = false; endforeach; ?></div></div>

<?php if (captcha_builtin_active()): ?><div class="alert ok"><b>This form includes a security check (captcha).</b> If you pasted this form's code on your website earlier, <b>replace it with the new code</b> (or use the PHP / JavaScript embed, which update by themselves). Older pasted code has no captcha box, so its submissions are refused while "Require a captcha" is on in Settings.</div><?php endif; ?>
<div class="opt" id="opt-html"><div class="card"><h2>Full HTML (static)</h2>
<p class="muted">Plain HTML with the CSS and JS links. Works on any website. <b>After you add or change fields, copy this again</b> and replace it on your page.</p>
<div class="copybox"><button class="btn sm sec" data-copy="c-html">Copy</button><textarea id="c-html" class="code" readonly rows="20"><?= e($options['html'][2]) ?></textarea></div></div>
<div class="card"><h2>Asset URLs (if you prefer to place them separately)</h2>
<label>CSS <input readonly value="<?= e(asset_url($base, 'form.min.css')) ?>" onclick="this.select()"></label>
<label>JS <input readonly value="<?= e(asset_url($base, 'form.min.js')) ?>" onclick="this.select()"></label>
<label>Submit endpoint <input readonly value="<?= e($base) ?>/public/submit.php" onclick="this.select()"></label></div></div>

<div class="opt" id="opt-php" hidden><div class="card"><h2>PHP embed</h2>
<p class="muted">Paste where the form should appear in a <b>.php</b> page. The snippet fetches the form from this app and prints the real HTML into your page (no iframe), then caches it so your page stays fast. <b>Edits made in the builder show up automatically</b>, with no re-pasting.</p>
<div class="copybox"><button class="btn sm sec" data-copy="c-php">Copy</button><textarea id="c-php" class="code" readonly rows="21"><?= e($phpCode) ?></textarea></div>
<ul class="muted" style="margin-bottom:0"><li>Best for speed and SEO: the form is in the page's HTML, so there is no extra loading step or layout jump.</li>
<li>Change <code>$tf_ttl</code> to control how quickly edits appear (300 = up to 5 minutes). If this app is ever unreachable, the last good copy keeps showing.</li>
<li>Needs PHP with cURL or <code>allow_url_fopen</code>. Use the Full HTML option for non-PHP pages.</li></ul></div></div>

<div class="opt" id="opt-js" hidden><div class="card"><h2>JavaScript embed</h2>
<p class="muted">Works on <b>any</b> website (plain HTML, WordPress, etc.). Put the <code>&lt;div&gt;</code> where the form should appear; the script writes the real form (and its styles) into that spot. No iframe. <b>Edits made in the builder show up automatically</b>.</p>
<div class="copybox"><button class="btn sm sec" data-copy="c-js">Copy</button><textarea id="c-js" class="code" readonly rows="6"><?= e($jsCode) ?></textarea></div>
<ul class="muted" style="margin-bottom:0"><li>The form appears a moment after the page loads. For the best page-speed score, prefer the PHP embed or Full HTML when you can.</li>
<li>Placing the <code>&lt;script&gt;</code> elsewhere (e.g. footer) is fine: it finds the <code>&lt;div id="topten-form-<?= $id ?>"&gt;</code> by id. Add <code>data-target="#my-box"</code> to the script tag to target a different element.</li>
<li>Updates take up to a minute because the script is cached briefly.</li></ul></div></div>

<p class="muted">If your website is on a different domain than this app, add it under "Allowed website origins" in the form's settings. For production, host this app on HTTPS so all URLs above are HTTPS too.</p>
<script>
document.querySelectorAll('[data-copy]').forEach(function(b){b.onclick=function(){var t=document.getElementById(b.dataset.copy);t.select();navigator.clipboard.writeText(t.value).then(function(){b.textContent='Copied!';setTimeout(function(){b.textContent='Copy'},1500)})}});
function showOpt(k){document.querySelectorAll('.opt').forEach(function(o){o.hidden=o.id!=='opt-'+k});document.querySelectorAll('[data-opt]').forEach(function(b){b.classList.toggle('active',b.dataset.opt===k)});try{history.replaceState(null,'','#'+k)}catch(e){}}
document.querySelectorAll('[data-opt]').forEach(function(b){b.onclick=function(){showOpt(b.dataset.opt)}});
if(['php','js','html'].indexOf(location.hash.slice(1))>-1)showOpt(location.hash.slice(1));
</script>
<?php admin_footer();
