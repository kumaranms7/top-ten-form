<?php
require __DIR__ . '/_layout.php';
require_login('viewer');
$id = (int)($_GET['id'] ?? 0);
$form = site_form($id);
if (!$form) { flash('Form not found.', 'err'); header('Location: forms.php'); exit; }
admin_header('Preview', 'forms', '', 'This is how the form will look on your website (uses the same HTML, CSS and JS you copy).');
if (can('editor')) echo form_tabs($id, 'preview'); ?>
<?php if ($form['status'] !== 'active'): ?><div class="alert err">This form is disabled, so visitors can't submit it until you enable it on the Forms page.</div><?php endif; ?>
<div class="card preview-bar">
  <div class="devices" role="group" aria-label="Preview size">
    <button type="button" class="active" data-w="100%"><?= icon('monitor') ?>Desktop</button>
    <button type="button" data-w="768px"><?= icon('tablet') ?>Tablet</button>
    <button type="button" data-w="390px"><?= icon('phone') ?>Mobile</button>
  </div>
  <span class="muted">Preview shows the last <b>saved</b> version. Submitting here is simulated: nothing is saved or emailed.</span>
  <a class="btn sec sm" target="_blank" rel="noopener" href="preview-frame.php?id=<?= $id ?>" style="margin-left:auto">Open in new tab</a>
</div>
<div class="preview-stage"><iframe id="pf" title="Form preview" src="preview-frame.php?id=<?= $id ?>" style="width:100%"></iframe></div>
<script>
var pf=document.getElementById('pf');
document.querySelectorAll('.devices button').forEach(function(b){b.onclick=function(){
  document.querySelectorAll('.devices button').forEach(function(x){x.classList.toggle('active',x===b)});
  pf.style.width=b.dataset.w;};});
function fit(){try{pf.style.height=Math.max(520,pf.contentDocument.documentElement.scrollHeight+10)+'px'}catch(e){pf.style.height='700px'}}
pf.addEventListener('load',function(){fit();new ResizeObserver(fit).observe(pf.contentDocument.body)});
</script>
<?php admin_footer();
