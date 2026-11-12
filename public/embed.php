<?php
/**
 * Serves a form for embedding (real markup, never an iframe).
 *   embed.php?id=1&type=html  -> ready-to-print HTML (link + form + script); used by the PHP embed snippet
 *   embed.php?id=1&type=js    -> JavaScript that injects the form into the page; used by the JS embed snippet
 * Always reflects the latest saved form, so edits in the builder show up without re-pasting code.
 */
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/render.php';

$type = ($_GET['type'] ?? 'html') === 'js' ? 'js' : 'html';
$form = q('SELECT * FROM forms WHERE id=?', [(int)($_GET['id'] ?? 0)])->fetch();
if ($form) { use_site((int)$form['site_id']); $st = site_by_id((int)$form['site_id']); if (!$st || $st['status'] !== 'active') $form['status'] = 'disabled'; }
$base = base_url();
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=60'); // short, so edits go live within a minute

if (!$form || $form['status'] !== 'active') {
    http_response_code($form ? 200 : 404);
    $why = $form ? 'disabled' : 'not found';
    if ($type === 'js') { header('Content-Type: application/javascript; charset=utf-8'); echo "/* TopTen Form: $why */"; }
    else { header('Content-Type: text/html; charset=utf-8'); echo "<!-- TopTen Form: $why -->"; }
    exit;
}

if ($type === 'html') {
    header('Content-Type: text/html; charset=utf-8');
    echo render_form_html($form, $base);
    exit;
}

header('Content-Type: application/javascript; charset=utf-8');
$cfg = [
    'id' => (int)$form['id'],
    'html' => render_form_markup($form, $base, false),
    'css' => asset_url($base, 'form.min.css'),
    'js' => asset_url($base, 'form.min.js'),
    'extra' => array_values(array_filter([captcha_script_url($form)])),
];
$json = json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
echo <<<JS
(function(){
  var d=document,c=$json,me=d.currentScript;
  var sel=me&&me.getAttribute('data-target');
  var el=(sel&&d.querySelector(sel))||d.getElementById('topten-form-'+c.id);
  if(!el){el=d.createElement('div');el.id='topten-form-'+c.id;if(me&&me.parentNode)me.parentNode.insertBefore(el,me);else d.body.appendChild(el);}
  if(el.getAttribute('data-tf-ready'))return;el.setAttribute('data-tf-ready','1');
  if(!d.querySelector('link[data-tf-css]')){var l=d.createElement('link');l.rel='stylesheet';l.href=c.css;l.setAttribute('data-tf-css','1');d.head.appendChild(l);}
  el.innerHTML=c.html;
  function load(src,cb){var s=d.createElement('script');s.src=src;s.async=true;if(cb)s.onload=cb;d.head.appendChild(s);return s;}
  function boot(){window.TopTenForm&&window.TopTenForm.init(el);}
  if(window.TopTenForm)boot();
  else if(d.querySelector('script[data-tf-js]')){var t=setInterval(function(){if(window.TopTenForm){clearInterval(t);boot();}},50);}
  else{load(c.js,boot).setAttribute('data-tf-js','1');}
  c.extra.forEach(function(u){if(!d.querySelector('script[src="'+u+'"]'))load(u);});
})();
JS;
