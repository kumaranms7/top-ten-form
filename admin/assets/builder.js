(function () {
  'use strict';
  var TF = window.TF, types = TF.types;
  var fields = (TF.fields || []).slice();
  var sel = null, dragIdx = null, uid = Date.now();
  var canvas = document.getElementById('canvas'), props = document.getElementById('props');
  var NOINPUT = ['heading', 'paragraph', 'pagebreak', 'captcha'], NOREQ = ['heading', 'paragraph', 'pagebreak', 'captcha', 'hidden', 'calculated'];

  /* ---------- icons + colours per field type ---------- */
  var ICONS = {
    text: '<path d="M4 7V4h16v3M9 20h6M12 4v16"/>', email: '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>',
    tel: '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.8 19.8 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/>',
    number: '<path d="M4 9h16M4 15h16M10 3 8 21M16 3l-2 18"/>', textarea: '<path d="M17 6H3M21 12H3M15 18H3"/>',
    select: '<rect x="3" y="5" width="18" height="14" rx="3"/><path d="m9 11 3 3 3-3"/>', radio: '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/>',
    checkbox: '<path d="m9 11 3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
    date: '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>', file: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/>',
    hidden: '<path d="M9.9 9.9a3 3 0 1 0 4.2 4.2M10.7 5.1A10.4 10.4 0 0 1 12 5c7 0 10 7 10 7a13 13 0 0 1-1.7 2.7M6.6 6.6A13.5 13.5 0 0 0 2 12s3 7 10 7a9.7 9.7 0 0 0 5.4-1.6M2 2l20 20"/>',
    consent: '<path d="M20 13c0 5-3.5 7.5-7.7 9a1 1 0 0 1-.7 0C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.2-2.7a1.2 1.2 0 0 1 1.5 0C14.5 3.8 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
    heading: '<path d="M6 12h12M6 4v16M18 4v16"/>', paragraph: '<path d="M13 4v16M17 4v16M19 4H9.5a4.5 4.5 0 0 0 0 9H13"/>',
    name: '<circle cx="12" cy="8" r="4"/><path d="M4 21v-1a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v1"/>', address: '<path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/>',
    rating: '<path d="m12 2 3.1 6.3 6.9 1-5 4.9 1.2 6.8L12 17.8 5.8 21l1.2-6.8-5-4.9 6.9-1z"/>', time: '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
    price: '<path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
    calculated: '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M8 6h8M8 10h.01M12 10h.01M16 10h.01M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01M16 18h.01"/>',
    captcha: '<path d="M12 2 4 5v6c0 5 3.4 8.7 8 11 4.6-2.3 8-6 8-11V5z"/><path d="m9 12 2 2 4-4"/>', signature: '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>', pagebreak: '<path d="M3 12h18M7 8l-4 4 4 4M17 8l4 4-4 4"/>',
    copy: '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>', trash: '<path d="M3 6h18M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6M10 11v6M14 11v6M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>'
  };
  var COLORS = { text: 'indigo', email: 'pink', tel: 'green', number: 'orange', textarea: 'violet', select: 'sky', radio: 'rose', checkbox: 'teal', date: 'amber', file: 'blue', hidden: 'slate', consent: 'green',
    heading: 'indigo', paragraph: 'sky', name: 'indigo', address: 'teal', rating: 'amber', time: 'orange', price: 'green', calculated: 'violet', signature: 'pink', pagebreak: 'slate', captcha: 'green' };
  function col(t) { return 'c-' + (COLORS[t] || 'indigo'); }
  function svg(n) { return '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' + (ICONS[n] || '') + '</svg>'; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function slug(s) { return String(s).toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '') || 'field'; }
  function uniqueName(base) { var n = base, i = 2; while (fields.some(function (f) { return f.name === n; })) n = base + '_' + i++; return n; }
  function current() { return fields.filter(function (x) { return x.id === sel; })[0]; }

  /* ---------- dirty flag + undo/redo ---------- */
  var dirty = false, badge = document.getElementById('dirtyBadge');
  function setDirty(v) { dirty = v; if (badge) badge.hidden = !v; }
  var hist = [JSON.stringify(fields)], hi = 0, undoBtn = document.getElementById('undoBtn'), redoBtn = document.getElementById('redoBtn'), commitTimer = null;
  function syncHist() { undoBtn.disabled = hi <= 0; redoBtn.disabled = hi >= hist.length - 1; }
  function commit() { // record the current fields as an undo step
    clearTimeout(commitTimer);
    var snap = JSON.stringify(fields); if (snap === hist[hi]) return;
    hist = hist.slice(0, hi + 1); hist.push(snap); if (hist.length > 100) hist.shift(); hi = hist.length - 1; syncHist();
  }
  function commitSoon() { clearTimeout(commitTimer); commitTimer = setTimeout(commit, 500); }
  function restore() { fields = JSON.parse(hist[hi]); if (!current()) sel = null; setDirty(true); render(); syncHist(); }
  function undo() { commit(); if (hi > 0) { hi--; restore(); } }
  function redo() { if (hi < hist.length - 1) { hi++; restore(); } }
  undoBtn.addEventListener('click', undo); redoBtn.addEventListener('click', redo);
  document.addEventListener('keydown', function (ev) {
    if (!(ev.ctrlKey || ev.metaKey)) return;
    var t = ev.target, typing = t && (/^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName) || t.isContentEditable);
    if (typing) return;
    var k = ev.key.toLowerCase();
    if (k === 'z' && !ev.shiftKey) { ev.preventDefault(); undo(); } else if (k === 'y' || (k === 'z' && ev.shiftKey)) { ev.preventDefault(); redo(); }
  });

  /* ---------- tabs ---------- */
  function showTab(name) {
    [].forEach.call(document.querySelectorAll('.ptabs button'), function (b) { b.classList.toggle('active', b.getAttribute('data-tab') === name); });
    document.getElementById('tab-add').hidden = name !== 'add';
    document.getElementById('tab-field').hidden = name !== 'field';
    document.getElementById('settingsCard').hidden = name !== 'form';
  }
  [].forEach.call(document.querySelectorAll('.ptabs button'), function (b) { b.addEventListener('click', function () { showTab(b.getAttribute('data-tab')); }); });
  document.getElementById('paletteGrid').innerHTML = Object.keys(types).map(function (k) {
    return '<div class="pitem ' + col(k) + '" draggable="true" data-type="' + k + '"><span class="ico">' + svg(k) + '</span><span>' + esc(types[k]) + '</span></div>';
  }).join('');

  /* ---------- field model ---------- */
  function newField(type) {
    var label = types[type];
    var f = { id: 'f' + (uid++), type: type, label: label, name: uniqueName(slug(label)), placeholder: '', default: '', help: '', required: false, width: 'full', css_class: '', options: [], formula: '', currency: '', max: 0, logic: null };
    if (['select', 'radio', 'checkbox'].indexOf(type) > -1) f.options = ['Option 1', 'Option 2', 'Option 3'];
    if (type === 'heading') f.label = 'Section heading';
    if (type === 'paragraph') f.label = 'Descriptive text goes here.';
    if (type === 'consent') f.label = 'I agree to the terms and privacy policy';
    if (type === 'pagebreak') f.label = 'Next step';
    if (type === 'captcha') { f.label = 'Security check'; f.captcha_type = 'question'; }
    if (type === 'price' || type === 'calculated') f.currency = '$';
    if (type === 'rating') f.max = 5;
    if (type === 'name') f.name_format = 'first_last';
    if (type === 'address') f.address_format = 'street_city_state_zip';
    if (type === 'tel') f.phone_format = 'us';
    if (type === 'calculated') f.formula = '';
    return f;
  }
  function addField(type, at) {
    if (type === 'captcha' && fields.some(function (x) { return x.type === 'captcha'; })) { alert('This form already has a Captcha field.'); return; }
    var f = newField(type);
    if (at == null || at > fields.length) fields.push(f); else fields.splice(at, 0, f);
    sel = f.id; setDirty(true); render(); showTab('field'); commit();
  }

  function canvasHtml() {
    return fields.map(function (f, i) {
      var isInput = NOINPUT.indexOf(f.type) === -1;
      var tools = '<div class="tools"><button type="button" class="dup" title="Duplicate" data-dup="' + i + '">' + svg('copy') + '</button><button type="button" class="del" title="Delete" data-del="' + i + '">' + svg('trash') + '</button></div>';
      var badgeHtml = (f.logic && f.logic.rules && f.logic.rules.length ? '<span class="lg-badge">logic</span>' : '');
      if (f.type === 'pagebreak') return '<div class="cf pb ' + col(f.type) + ' full' + (f.id === sel ? ' sel' : '') + '" draggable="true" data-i="' + i + '" data-id="' + f.id + '"><span class="t">Page break</span>' + tools + '<div class="l">&rarr; ' + esc(f.label) + '</div></div>';
      return '<div class="cf ' + col(f.type) + ' ' + esc(f.width) + (f.id === sel ? ' sel' : '') + '" draggable="true" data-i="' + i + '" data-id="' + f.id + '">' +
        '<span class="t">' + esc(types[f.type]) + (f.type === 'name' ? ' · ' + ({ first_last: 'first + last', first_middle_last: 'first + middle + last', full: 'full name' }[f.name_format || 'first_last']) : '') + (f.type === 'address' ? ' · ' + ({ street_city_state_zip: 'street, city, state, zip', detailed: 'detailed', full: 'full address' }[f.address_format || 'detailed']) : '') + (f.type === 'tel' ? ' · ' + ({ us: 'US', international: 'international', any: 'any format' }[f.phone_format || 'any']) : '') + (f.required ? ' · required' : '') + badgeHtml + '</span>' + tools +
        '<div class="l">' + esc(f.label) + (f.required ? ' <span style="color:#c62828">*</span>' : '') + '</div>' +
        (isInput && f.type !== 'hidden' ? '<div class="fake"></div>' : '') + '</div>';
    }).join('') + '<div class="endzone">Drop here to add a field at the end</div>';
  }
  function render() {
    canvas.innerHTML = fields.length ? canvasHtml() : '<div class="empty">Drag fields here to build your form</div>';
    renderProps();
  }

  function renderProps() {
    var f = current();
    if (!f) { props.innerHTML = '<p class="muted" style="margin:0">Select a field on the form to edit its properties.</p>'; return; }
    var isInput = NOINPUT.indexOf(f.type) === -1, hasOpts = ['select', 'radio', 'checkbox'].indexOf(f.type) > -1;
    var h = '<h2>' + esc(types[f.type]) + '</h2>';
    var lblText = f.type === 'pagebreak' ? 'Title of the next step' : (isInput ? 'Label' : 'Text');
    if (f.type !== 'captcha') h += '<label>' + lblText + (f.type === 'paragraph' ? '<textarea data-k="label" rows="4">' + esc(f.label) + '</textarea>' : '<input data-k="label" value="' + esc(f.label) + '">') + '</label>';
    if (isInput) {
      h += '<label>Field name <small>(letters, numbers, _; used in logic and email tags)</small><input data-k="name" value="' + esc(f.name) + '"></label>';
      if (['text', 'email', 'tel', 'number', 'textarea', 'select', 'price'].indexOf(f.type) > -1) h += '<label>Placeholder<input data-k="placeholder" value="' + esc(f.placeholder) + '"></label>';
      if (['file', 'consent', 'radio', 'checkbox', 'name', 'address', 'rating', 'signature', 'calculated'].indexOf(f.type) === -1) h += '<label>Default value<input data-k="default" value="' + esc(f.default) + '"></label>';
      var PH = { name: { full: 'Full name', first: 'First name', middle: 'Middle name', last: 'Last name' }, address: { full: 'Full address', street: 'Street address', line2: 'Apt, suite', city: 'City', state: 'State', zip: 'ZIP', country: 'Country' } };
      var PHF = { name: { full: ['full'], first_last: ['first', 'last'], first_middle_last: ['first', 'middle', 'last'] }, address: { full: ['full'], street_city_state_zip: ['street', 'city', 'state', 'zip'], detailed: ['street', 'line2', 'city', 'state', 'zip', 'country'] } };
      if (PH[f.type]) {
        var fmtKey = f.type === 'name' ? (f.name_format || 'first_last') : (f.address_format || 'detailed');
        h += '<fieldset class="phset"><legend>Placeholders</legend>' + PHF[f.type][fmtKey].map(function (p) { return '<label>' + PH[f.type][p] + '<input data-k="ph_' + p + '" value="' + esc((f.placeholders || {})[p] || '') + '"></label>'; }).join('') + '</fieldset>';
      }
      if (f.type !== 'hidden') h += '<label>Help text<input data-k="help" value="' + esc(f.help) + '"></label>';
      if (NOREQ.indexOf(f.type) === -1) h += '<label class="inline"><input type="checkbox" data-k="required"' + (f.required ? ' checked' : '') + '> Required</label>';
    }
    var opt = function (cur, list) { return list.map(function (o) { return '<option value="' + o[0] + '"' + (cur === o[0] ? ' selected' : '') + '>' + o[1] + '</option>'; }).join(''); };
    if (f.type === 'name') h += '<label>Name format<select data-k="name_format">' + opt(f.name_format || 'first_last', [['first_last', 'First name + Last name'], ['first_middle_last', 'First + Middle + Last name'], ['full', 'Full name (one box)']]) + '</select></label>';
    if (f.type === 'address') h += '<label>Address format<select data-k="address_format">' + opt(f.address_format || 'detailed', [['street_city_state_zip', 'Street + City + State + ZIP'], ['detailed', 'Detailed (adds apartment line + country)'], ['full', 'Full address (one box)']]) + '</select></label>';
    if (f.type === 'captcha') h += '<label>Captcha type<select data-k="captcha_type">' + opt(f.captcha_type || 'question', [['question', 'Question and answer (random easy question: weekdays, months, sums, colours)'], ['math', 'Math problem (image)'], ['text', 'Text image (type the characters)']]) + '</select></label>' +
      '<p class="muted" style="margin-top:-6px">Always required. If Google reCAPTCHA or Cloudflare Turnstile is set in Settings, that is shown instead of this type. Only one captcha per form.</p>';
    if (f.type === 'tel') h += '<label>Phone format<select data-k="phone_format">' + opt(f.phone_format || 'any', [['us', 'US: (XXX) XXX-XXXX'], ['international', 'International: +country code + full number'], ['any', 'Any format (not checked)']]) + '</select></label>';
    if (hasOpts) h += '<label>Options <small>(one per line)</small><textarea data-k="options" rows="5">' + esc(f.options.join('\n')) + '</textarea></label>';
    if (f.type === 'price' || f.type === 'calculated') h += '<label>Currency symbol<input data-k="currency" maxlength="3" value="' + esc(f.currency) + '"></label>';
    if (f.type === 'calculated') {
      var refs = fields.filter(function (x) { return x.id !== f.id && ['number', 'price', 'calculated', 'rating'].indexOf(x.type) > -1; }).map(function (x) { return '<code>{' + esc(x.name) + '}</code>'; }).join(' ');
      h += '<label>Formula <small>(use + - * / and parentheses)</small><input data-k="formula" value="' + esc(f.formula) + '" placeholder="{quantity} * {price}"></label>' +
        '<p class="muted" style="margin-top:-8px">Number fields you can use: ' + (refs || 'none yet (add Number or Price fields)') + '</p>';
    }
    if (f.type === 'rating') h += '<label>Number of stars<select data-k="max">' + [3, 4, 5, 6, 7, 8, 9, 10].map(function (n) { return '<option' + ((+f.max || 5) === n ? ' selected' : '') + '>' + n + '</option>'; }).join('') + '</select></label>';
    if (f.type !== 'hidden' && f.type !== 'pagebreak' && f.type !== 'captcha') {
      h += '<label>Width<select data-k="width">' + ['full', 'half', 'third'].map(function (w) { return '<option value="' + w + '"' + (f.width === w ? ' selected' : '') + '>' + w + '</option>'; }).join('') + '</select></label>';
      h += '<label>CSS class<input data-k="css_class" value="' + esc(f.css_class) + '"></label>';
    }
    if (f.type !== 'pagebreak' && f.type !== 'captcha') h += '<details' + (f.logic ? ' open' : '') + ' class="lgwrap"><summary>Conditional logic</summary><div id="lgBox"></div></details>';
    props.innerHTML = h;
    var box = document.getElementById('lgBox');
    if (box) {
      var others = fields.filter(function (x) { return x.id !== f.id && NOINPUT.indexOf(x.type) === -1 && x.type !== 'hidden'; }).map(function (x) { return { name: x.name, label: x.label, type: x.type, options: x.options }; });
      TFLogic.mount(box, { logic: f.logic, fields: others, withAction: true, onChange: function (lg) { f.logic = lg; setDirty(true); canvas.innerHTML = canvasHtml(); commitSoon(); } });
    }
  }

  /* ---------- property edits ---------- */
  props.addEventListener('input', function (ev) {
    var k = ev.target.getAttribute('data-k'); if (!k) return;
    var f = current(); if (!f) return;
    if (k === 'required') f.required = ev.target.checked;
    else if (k === 'options') f.options = ev.target.value.split('\n').map(function (s) { return s.trim(); }).filter(Boolean);
    else if (k === 'max') f.max = +ev.target.value;
    else if (k.indexOf('ph_') === 0) { f.placeholders = f.placeholders || {}; f.placeholders[k.slice(3)] = ev.target.value; }
    else f[k] = ev.target.value;
    setDirty(true); canvas.innerHTML = canvasHtml(); commitSoon(); // canvas only, so typing keeps focus
  });
  props.addEventListener('change', function (ev) { var k = ev.target.getAttribute('data-k'); if (k === 'width' || k === 'required' || k === 'name_format' || k === 'address_format') { render(); commit(); } else if (k) commit(); });

  /* ---------- palette + canvas interactions ---------- */
  [].forEach.call(document.querySelectorAll('.pitem'), function (it) {
    it.addEventListener('click', function () { addField(it.getAttribute('data-type')); });
    it.addEventListener('dragstart', function (ev) { ev.dataTransfer.setData('text/plain', 'new:' + it.getAttribute('data-type')); ev.dataTransfer.effectAllowed = 'copy'; });
  });
  canvas.addEventListener('click', function (ev) {
    var dp = ev.target.closest('[data-dup]');
    if (dp) {
      var src = fields[+dp.getAttribute('data-dup')], cp = JSON.parse(JSON.stringify(src));
      cp.id = 'f' + (uid++); cp.name = uniqueName(src.name); cp.label = src.label + ' (copy)';
      fields.splice(+dp.getAttribute('data-dup') + 1, 0, cp); sel = cp.id; setDirty(true); render(); showTab('field'); commit(); return;
    }
    var d = ev.target.closest('[data-del]');
    if (d) { fields.splice(+d.getAttribute('data-del'), 1); sel = null; setDirty(true); render(); commit(); return; }
    var c = ev.target.closest('.cf'); if (c) { sel = c.getAttribute('data-id'); render(); showTab('field'); }
  });
  canvas.addEventListener('dragstart', function (ev) {
    var c = ev.target.closest('.cf'); if (!c) return;
    dragIdx = +c.getAttribute('data-i'); ev.dataTransfer.setData('text/plain', 'move:' + dragIdx); ev.dataTransfer.effectAllowed = 'move';
    setTimeout(function () { c.classList.add('dragging'); }, 0);
  });
  canvas.addEventListener('dragend', function () { dragIdx = null; canvas.classList.remove('over'); render(); });
  canvas.addEventListener('dragover', function (ev) {
    ev.preventDefault(); canvas.classList.add('over');
    [].forEach.call(canvas.querySelectorAll('.drop-before,.drop-after'), function (x) { x.classList.remove('drop-before', 'drop-after'); });
    var t = dropTarget(ev); if (t.el) t.el.classList.add(t.after ? 'drop-after' : 'drop-before');
  });
  /* where a drop lands: before or after the field under the pointer (left/top half = before), so the last field can be followed by more */
  function dropTarget(ev) {
    var c = ev.target.closest('.cf'); if (!c) return { el: null, at: fields.length, after: false };
    var r = c.getBoundingClientRect(), i = +c.getAttribute('data-i');
    var after = ev.clientX > r.left + r.width / 2 && c.getAttribute('data-i') !== null;
    return { el: c, at: after ? i + 1 : i, after: after };
  }
  canvas.addEventListener('dragleave', function (ev) { if (ev.target === canvas) canvas.classList.remove('over'); });
  canvas.addEventListener('drop', function (ev) {
    ev.preventDefault(); canvas.classList.remove('over');
    var data = ev.dataTransfer.getData('text/plain') || '';
    var at = dropTarget(ev).at;
    if (data.indexOf('new:') === 0) addField(data.slice(4), at);
    else if (data.indexOf('move:') === 0) {
      var from = +data.slice(5); if (isNaN(from)) return;
      var f = fields.splice(from, 1)[0]; if (from < at) at--;
      fields.splice(at, 0, f); setDirty(true); render(); commit();
    }
  });

  /* ---------- form-level settings (theme etc.) ---------- */
  function el(id) { return document.getElementById(id); }
  function val(id) { var e = el(id); if (!e) return ''; return e.type === 'checkbox' ? e.checked : e.value; }
  var themeIds = ['th_accent', 'th_text', 'th_border', 'th_bg', 'th_bg_on', 'th_radius', 'th_fs', 'th_input', 'th_button'];
  function applyPreset(key) {
    var p = TF.presets[key]; if (!p) return;
    el('th_accent').value = p.accent; el('th_text').value = p.text; el('th_border').value = p.border;
    el('th_bg').value = p.bg || '#ffffff'; el('th_bg_on').checked = !!p.bg;
    el('th_radius').value = p.radius; el('th_fs').value = p.font_size; el('th_input').value = p.input_style; el('th_button').value = p.button_style;
    el('th_radius_v').textContent = p.radius; el('th_fs_v').textContent = p.font_size;
  }
  el('th_preset').addEventListener('change', function () { if (this.value !== 'custom') applyPreset(this.value); });
  themeIds.forEach(function (id) {
    el(id).addEventListener('input', function () {
      if (id === 'th_radius') el('th_radius_v').textContent = this.value;
      if (id === 'th_fs') el('th_fs_v').textContent = this.value;
      el('th_preset').value = 'custom';
    });
  });


  /* ---------- save ---------- */
  el('saveBtn').addEventListener('click', function () {
    var btn = this, msg = el('saveMsg');
    commit();
    var payload = {
      name: val('formName'), fields: fields,
      settings: {
        submit_text: val('s_submit_text'), css_class: val('s_css_class'), success_message: val('s_success_message'), redirect_url: val('s_redirect_url'),
        success_mode: document.querySelector('input[name=s_mode]:checked').value, pass_values: val('s_pass_values'),
        allowed_origins: val('s_allowed_origins'), spam_protection: val('s_spam'), save_entries: val('s_save_entries'), save_resume: val('s_save_resume'),
        custom_css: val('s_custom_css'),
        theme: { preset: val('th_preset'), accent: val('th_accent'), text: val('th_text'), border: val('th_border'), bg: val('th_bg_on') ? val('th_bg') : '', radius: +val('th_radius'), font_size: +val('th_fs'), input_style: val('th_input'), button_style: val('th_button') }
      }
    };
    btn.disabled = true; msg.textContent = 'Saving...'; msg.style.color = '';
    fetch(TF.saveUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name=csrf]').content }, body: JSON.stringify(payload) })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.ok) { fields = d.fields; setDirty(false); render(); hist = [JSON.stringify(fields)]; hi = 0; syncHist(); msg.textContent = 'Saved. Re-copy the HTML only if you use the Full HTML option.'; msg.style.color = '#059669'; }
        else { msg.textContent = d.error || 'Save failed'; msg.style.color = '#e11d48'; }
      })
      .catch(function () { msg.textContent = 'Save failed (network or session expired).'; msg.style.color = '#e11d48'; })
      .then(function () { btn.disabled = false; });
  });

  /* ---------- unsaved-changes protection ---------- */
  ['input', 'change'].forEach(function (t) {
    ['.form-head', '#settingsCard'].forEach(function (s) { var e = document.querySelector(s); if (e) e.addEventListener(t, function () { setDirty(true); }); });
  });
  document.addEventListener('click', function (ev) {
    if (!dirty || ev.defaultPrevented || ev.button !== 0 || ev.ctrlKey || ev.metaKey || ev.shiftKey) return;
    var a = ev.target.closest('a[href]'); if (!a || a.target === '_blank' || /^(#|javascript:)/.test(a.getAttribute('href'))) return;
    if (!window.confirm('You have unsaved changes. If you leave this page they will be lost. Leave without saving?')) { ev.preventDefault(); return; }
    dirty = false; // already confirmed, skip the browser's second prompt
  }, true);
  window.addEventListener('beforeunload', function (ev) { if (dirty) { ev.preventDefault(); ev.returnValue = ''; } });
  render(); syncHist();
})();
