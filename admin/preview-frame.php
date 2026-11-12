<?php
// Bare page rendered inside the preview iframe: the REAL form HTML + real CSS/JS, with submission simulated.
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/render.php';
require_login('viewer');
$form = site_form((int)($_GET['id'] ?? 0));
if (!$form) { http_response_code(404); exit('Form not found'); }
$s = form_settings($form);
$note = $s['success_mode'] === 'redirect' && $s['redirect_url'] !== ''
    ? 'Preview only: on your website the visitor would now be redirected to ' . $s['redirect_url']
    : $s['success_message'];
header('X-Frame-Options: SAMEORIGIN');
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Preview</title>
<style>body{margin:0;padding:28px 22px;font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;background:#fff;color:#1f2933}</style>
<script>
// Preview mode: nothing is saved or emailed. The real form.js still runs (validation, messages); only the network call is stubbed.
var realFetch = window.fetch.bind(window);
window.fetch = function (url, opts) {
  if (opts && opts.method === 'POST') return Promise.resolve({ json: function () { return { success: true, message: <?= json_encode($note) ?>, errors: {} }; } });
  return realFetch(url, opts); // token + captcha challenges are real, so the preview shows the real security check
};
</script></head><body>
<?= render_form_html($form, base_url()) ?>
<script>document.querySelectorAll('.tf-cap-ans').forEach(function(e){e.required=false;e.placeholder='(any answer in preview)'});</script>
</body></html>
