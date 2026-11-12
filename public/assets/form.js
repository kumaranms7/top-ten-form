/* TopTen Form - frontend script. Validation, conditional logic, calculated fields, steps, signature pad,
   save & resume, AJAX submit. The server re-checks everything; this only improves the experience. */
(function () {
  'use strict';
  var d = document;

  function msg(form, text, ok) {
    var m = form.querySelector('.tf-message');
    if (!m) return;
    m.textContent = text;
    m.className = 'tf-message ' + (ok ? 'tf-ok' : 'tf-fail');
  }
  function wrapOf(form, name) { return form.querySelector('.tf-field[data-name="' + name + '"]'); }
  function clearErrors(form) {
    [].forEach.call(form.querySelectorAll('.tf-field'), function (f) {
      f.classList.remove('tf-invalid');
      var e = f.querySelector('.tf-error'); if (e) e.textContent = '';
    });
  }
  function setError(form, name, text) {
    var wrap = wrapOf(form, name);
    if (!wrap) { var el = form.querySelector('[name="' + name + '"],[name="' + name + '[]"]'); wrap = el && el.closest('.tf-field'); }
    if (!wrap) return null;
    wrap.classList.add('tf-invalid');
    var e = wrap.querySelector('.tf-error'); if (e) e.textContent = text;
    return wrap;
  }
  function store(k, v) { try { if (v !== undefined) sessionStorage.setItem(k, v); return sessionStorage.getItem(k); } catch (e) { return v || null; } }
  function campaign() {
    var keys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'msclkid', 'fbclid'], found = {}, p = new URLSearchParams(location.search), any = false;
    keys.forEach(function (k) { if (p.get(k)) { found[k] = p.get(k); any = true; } });
    if (any) { store('tf_utm', JSON.stringify(found)); return found; }
    try { var s = store('tf_utm'); return s ? JSON.parse(s) : {}; } catch (e) { return {}; }
  }

  /* ---------- phone helpers ---------- */
  function maskUS(el) {
    var d = el.value.replace(/\D/g, ''); if (d.charAt(0) === '1') d = d.slice(1); /* US area codes never start with 1, so a leading 1 is the country code */ d = d.slice(0, 10);
    var o = ''; if (d.length > 0) o = '(' + d.slice(0, 3); if (d.length >= 4) o += ') ' + d.slice(3, 6); if (d.length >= 7) o += '-' + d.slice(6);
    el.value = o;
  }
  function phoneError(el) {
    var mode = el.getAttribute('data-phone'), dg = el.value.replace(/\D/g, '');
    if (!el.value || !mode) return '';
    if (mode === 'us') { if (dg.length === 11 && dg.charAt(0) === '1') dg = dg.slice(1); return dg.length === 10 ? '' : 'Enter a 10-digit US phone number.'; }
    return (el.value.charAt(0) === '+' && dg.length >= 8 && dg.length <= 15) ? '' : 'Enter the full number starting with + and the country code.';
  }

  /* ---------- values + conditional logic (mirrors app/logic.php) ---------- */
  function val(form, name) {
    var w = wrapOf(form, name);
    if (!w) { var el = form.querySelector('[name="' + name + '"]'); return el ? String(el.value || '').trim() : ''; }
    var t = w.getAttribute('data-type');
    if (w.classList.contains('tf-hidden')) return t === 'checkbox' ? [] : '';
    var q = function (s) { return w.querySelector(s); };
    if (t === 'checkbox') return [].map.call(w.querySelectorAll('input:checked'), function (i) { return i.value; });
    if (t === 'radio' || t === 'rating') { var c = q('input:checked'); return c ? c.value : ''; }
    if (t === 'consent') { var k = q('input'); return k && k.checked ? 'Yes' : ''; }
    if (t === 'signature') { var h = q('input[type=hidden]'); return h && h.value ? 'signed' : ''; }
    if (t === 'file') { var f = q('input'); return f && f.files && f.files.length ? f.files[0].name : ''; }
    if (t === 'name') return [].map.call(w.querySelectorAll('input'), function (i) { return (i.value || '').trim(); }).filter(Boolean).join(' ');
    if (t === 'address') {
      var g = function (p) { var i = q('[data-part="' + p + '"]'); return i ? (i.value || '').trim() : ''; };
      if (q('[data-part="full"]')) return g('full');
      return [g('street'), g('line2'), g('city'), (g('state') + ' ' + g('zip')).trim(), g('country')].filter(Boolean).join(', ');
    }
    var e = q('input,select,textarea'); return e ? String(e.value || '').trim() : '';
  }
  function ruleMatches(op, actual, expected) {
    var exp = String(expected).trim().toLowerCase(), arr = Array.isArray(actual);
    var list = arr ? actual.map(function (x) { return String(x).trim().toLowerCase(); }) : [], str = arr ? '' : String(actual).trim().toLowerCase();
    var num = function (s) { return s !== '' && !isNaN(s) && isFinite(s); };
    switch (op) {
      case 'empty': return arr ? !actual.length : str === '';
      case 'not_empty': return arr ? !!actual.length : str !== '';
      case 'is': return arr ? list.indexOf(exp) > -1 : str === exp;
      case 'is_not': return !ruleMatches('is', actual, expected);
      case 'contains': return arr ? list.indexOf(exp) > -1 : (exp !== '' && str.indexOf(exp) > -1);
      case 'not_contains': return !ruleMatches('contains', actual, expected);
      case 'gt': return !arr && num(str) && num(exp) && parseFloat(str) > parseFloat(exp);
      case 'lt': return !arr && num(str) && num(exp) && parseFloat(str) < parseFloat(exp);
    }
    return false;
  }
  function applyLogic(form) {
    [].forEach.call(form.querySelectorAll('.tf-field[data-logic]'), function (w) {
      var l; try { l = JSON.parse(w.getAttribute('data-logic')); } catch (e) { return; }
      var hit = l.m === 'any' ? false : true;
      for (var i = 0; i < l.r.length; i++) {
        var ok = ruleMatches(l.r[i][1], val(form, l.r[i][0]), l.r[i][2]);
        if (l.m === 'any') { if (ok) { hit = true; break; } } else if (!ok) { hit = false; break; }
      }
      var show = l.a === 'hide' ? !hit : hit;
      w.classList.toggle('tf-hidden', !show);
      [].forEach.call(w.querySelectorAll('input,select,textarea'), function (el) { el.disabled = !show; });
    });
  }

  /* ---------- calculated fields (mirrors formula_eval in app/logic.php) ---------- */
  function evalFormula(f, form) {
    f = String(f || '').trim(); if (!f) return 0;
    var re = /\s*(\{[a-z0-9_]+\}|\d+(?:\.\d+)?|\.\d+|[-+*\/()])/gi;
    if (f.replace(re, '') !== '') return 0;
    var toks = [], m; re.lastIndex = 0;
    while ((m = re.exec(f))) {
      var t = m[1];
      if (t.charAt(0) === '{') { var v = parseFloat(val(form, t.slice(1, -1))); toks.push(isNaN(v) ? 0 : v); }
      else if (/^[\d.]/.test(t)) toks.push(parseFloat(t)); else toks.push(t);
    }
    var p = 0;
    function expr() { var v = term(); while (p < toks.length && (toks[p] === '+' || toks[p] === '-')) { var o = toks[p++], r = term(); v = o === '+' ? v + r : v - r; } return v; }
    function term() { var v = factor(); while (p < toks.length && (toks[p] === '*' || toks[p] === '/')) { var o = toks[p++], r = factor(); v = o === '*' ? v * r : (r === 0 ? 0 : v / r); } return v; }
    function factor() {
      if (p >= toks.length) return 0; var t = toks[p++];
      if (t === '-') return -factor(); if (t === '+') return factor();
      if (t === '(') { var v = expr(); if (toks[p] === ')') p++; return v; }
      return typeof t === 'number' ? t : 0;
    }
    var r = expr(); return isFinite(r) ? r : 0;
  }
  function calc(form) {
    [].forEach.call(form.querySelectorAll('input[data-formula]'), function (el) {
      el.value = String(Math.round(evalFormula(el.getAttribute('data-formula'), form) * 100) / 100);
    });
  }

  /* ---------- validation (browser-side convenience) ---------- */
  function validate(form, scope) {
    var ok = true;
    [].forEach.call((scope || form).querySelectorAll('.tf-field'), function (wrap) {
      if (wrap.classList.contains('tf-hidden')) return;
      var err = '', group = wrap.getAttribute('data-group');
      if (group) {
        if (wrap.hasAttribute('data-required') && !wrap.querySelector('input:checked')) err = 'Please select an option.';
      } else {
        [].forEach.call(wrap.querySelectorAll('input,select,textarea'), function (el) {
          if (err || el.disabled) return;
          if (el.type === 'checkbox') { if (el.required && !el.checked) err = 'This field is required.'; }
          else if (el.required && !String(el.value).trim()) err = 'This field is required.';
          else if (el.value && el.type === 'email' && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(el.value)) err = 'Enter a valid email address.';
          else if (el.getAttribute('data-phone')) err = phoneError(el);
        });
      }
      if (err) { ok = false; wrap.classList.add('tf-invalid'); var e = wrap.querySelector('.tf-error'); if (e) e.textContent = err; }
    });
    return ok;
  }

  /* ---------- multi-step ---------- */
  function visibleSteps(form) {
    var out = [];
    [].forEach.call(form._steps, function (s, i) { if (s.querySelector('.tf-field:not(.tf-hidden)')) out.push(i); });
    return out.length ? out : [0];
  }
  function showStep(form, i) {
    var vis = visibleSteps(form), pos = Math.max(0, vis.indexOf(i));
    if (vis.indexOf(i) < 0) { i = vis[0]; pos = 0; }
    form._cur = i;
    [].forEach.call(form._steps, function (s, k) { s.classList.toggle('tf-current', k === i); });
    var bar = form.querySelector('.tf-bar span'), title = form.querySelector('.tf-steptitle'), t = form._steps[i].getAttribute('data-title');
    if (bar) bar.style.width = ((pos + 1) / vis.length * 100) + '%';
    if (title) title.textContent = 'Step ' + (pos + 1) + ' of ' + vis.length + (t ? ': ' + t : '');
    form.classList.toggle('tf-first', pos === 0); form.classList.toggle('tf-last', pos === vis.length - 1);
  }
  function go(form, dir) {
    var vis = visibleSteps(form), pos = vis.indexOf(form._cur);
    if (dir > 0 && !validate(form, form._steps[form._cur])) return;
    var nx = vis[pos + dir]; if (nx === undefined) return;
    showStep(form, nx);
    try { form.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (e) {}
  }

  /* ---------- signature pad ---------- */
  function initSig(wrap) {
    var cv = wrap.querySelector('.tf-sig'), hid = wrap.querySelector('input[type=hidden]'), ctx = cv.getContext('2d'), down = false, dirty = false;
    ctx.lineWidth = 2.6; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#111';
    function pt(e) { var r = cv.getBoundingClientRect(); return [(e.clientX - r.left) * cv.width / r.width, (e.clientY - r.top) * cv.height / r.height]; }
    cv.addEventListener('pointerdown', function (e) { down = true; dirty = true; var p = pt(e); ctx.beginPath(); ctx.moveTo(p[0], p[1]); ctx.lineTo(p[0] + .1, p[1]); ctx.stroke(); try { cv.setPointerCapture(e.pointerId); } catch (x) {} e.preventDefault(); });
    cv.addEventListener('pointermove', function (e) { if (!down) return; var p = pt(e); ctx.lineTo(p[0], p[1]); ctx.stroke(); e.preventDefault(); });
    function end() { if (!down) return; down = false; hid.value = dirty ? cv.toDataURL('image/png') : ''; hid.dispatchEvent(new Event('change', { bubbles: true })); }
    cv.addEventListener('pointerup', end); cv.addEventListener('pointercancel', end); cv.addEventListener('pointerleave', end);
    wrap.querySelector('.tf-sig-clear').addEventListener('click', function () { ctx.clearRect(0, 0, cv.width, cv.height); hid.value = ''; dirty = false; hid.dispatchEvent(new Event('change', { bubbles: true })); });
    wrap._clearSig = function () { ctx.clearRect(0, 0, cv.width, cv.height); hid.value = ''; dirty = false; };
  }

  /* ---------- built-in captcha (math / text image / question) ---------- */
  function capUrl(form, extra) { return form.action + (form.action.indexOf('?') > -1 ? '&' : '?') + 'tf_form_id=' + encodeURIComponent(form.getAttribute('data-form-id')) + extra; }
  function capRender(form, c) {
    var w = form.querySelector('.tf-captcha:not(.tf-ext-cap)'); if (!w) return;
    var box = w.querySelector('.tf-cap-q'), id = w.querySelector('input[name=tf_captcha_id]'), ans = w.querySelector('input[name=tf_captcha_answer]'), alt = w.querySelector('.tf-cap-alt');
    box.textContent = '';
    if (c && c.builtin === false) { w.style.display = 'none'; ans.required = false; ans.disabled = true; id.disabled = true; return; } // an external captcha took over
    if (!c || !c.id) { box.textContent = (c && c.error) || 'The security check could not load. Please reload the page.'; return; }
    id.value = c.id; ans.value = '';
    if (c.img) { var im = new Image(); im.alt = c.label; im.src = c.img; im.className = 'tf-cap-img'; im.draggable = false; box.appendChild(im); }
    else box.textContent = c.question || '';
    ans.setAttribute('inputmode', c.type === 'math' ? 'numeric' : 'text');
    w.setAttribute('data-ctype', c.type);
    var lt = w.querySelector('.tf-cap-lt'); if (lt) lt.textContent = c.type === 'question' && c.question ? c.question : lt.getAttribute('data-def');
    ans.setAttribute('aria-label', c.type === 'question' && c.question ? c.question : 'Your answer to the security check');
    if (alt) alt.style.display = c.type === 'question' ? 'none' : '';
  }
  function capLoad(form, alt) {
    var w = form.querySelector('.tf-captcha:not(.tf-ext-cap)'); if (!w) return;
    w._alt = !!alt;
    fetch(capUrl(form, '&tf_captcha=1' + (alt ? '&alt=1' : '')), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); }).then(function (j) { capRender(form, j); }).catch(function () { capRender(form, null); });
  }

  /* ---------- Google reCAPTCHA / Cloudflare Turnstile: loaded only when the visitor starts the form (keeps the third-party script out of page load) ---------- */
  var capQ = {};
  function loadCapApi(prov, cb) {
    var api = function () { return prov === 'turnstile' ? window.turnstile : (window.grecaptcha && window.grecaptcha.render ? window.grecaptcha : null); };
    if (api()) { cb(api()); return; }
    (capQ[prov] = capQ[prov] || []).push(cb); if (capQ[prov].length > 1) return;
    var fn = 'tfCapReady_' + prov;
    window[fn] = function () { var q = capQ[prov]; capQ[prov] = []; q.forEach(function (f) { f(api()); }); };
    var s = d.createElement('script'); s.async = true;
    s.src = (prov === 'turnstile' ? 'https://challenges.cloudflare.com/turnstile/v0/api.js' : 'https://www.google.com/recaptcha/api.js') + '?render=explicit&onload=' + fn;
    d.head.appendChild(s);
  }
  function initExtCaptcha(form) {
    var w = form.querySelector('.tf-ext-cap'); if (!w) return;
    var slot = w.querySelector('.tf-ext-slot'), prov = w.getAttribute('data-provider'), started = false;
    function start() {
      if (started) return; started = true;
      ['focusin', 'pointerdown', 'touchstart', 'keydown'].forEach(function (t) { form.removeEventListener(t, start, true); });
      loadCapApi(prov, function (api) { try { w._wid = api.render(slot, { sitekey: w.getAttribute('data-sitekey') }); } catch (e) {} });
    }
    w._start = start;
    ['focusin', 'pointerdown', 'touchstart', 'keydown'].forEach(function (t) { form.addEventListener(t, start, true); });
  }
  function extCapSolved(form) {
    var w = form.querySelector('.tf-ext-cap'); if (!w) return true;
    var r = w.querySelector('[name="g-recaptcha-response"],[name="cf-turnstile-response"]');
    return !!(r && r.value);
  }
  function extCapReset(form) {
    var w = form.querySelector('.tf-ext-cap'); if (!w || w._wid === undefined) return;
    try { (w.getAttribute('data-provider') === 'turnstile' ? window.turnstile : window.grecaptcha).reset(w._wid); } catch (e) {}
  }

  /* ---------- save & continue later ---------- */
  function draftData(form, email) {
    var fd = new FormData();
    [].forEach.call(form.elements, function (el) {
      if (!el.name || el.disabled || el.type === 'file' || el.type === 'submit' || el.type === 'button') return;
      if (/^tf_/.test(el.name) && el.name !== 'tf_form_id') return;
      if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) return;
      var w = el.closest('.tf-field'); if (w && w.getAttribute('data-type') === 'signature') return;
      fd.append(el.name, el.value);
    });
    fd.append('tf_action', 'save_draft'); fd.append('tf_email', email); fd.append('tf_page', location.href);
    return fd;
  }
  function initResume(form) {
    var btn = form.querySelector('.tf-save-later');
    if (btn) btn.addEventListener('click', function () {
      var box = form.querySelector('.tf-resume');
      if (box) { box.parentNode.removeChild(box); return; }
      box = d.createElement('div'); box.className = 'tf-resume';
      box.innerHTML = '<input type="email" placeholder="Your email address" aria-label="Your email address"><button type="button" class="tf-resume-send">Email me a link</button>';
      form.querySelector('.tf-actions').insertAdjacentElement('afterend', box);
      var inp = box.querySelector('input'), sendBtn = box.querySelector('button');
      inp.focus();
      sendBtn.addEventListener('click', function () {
        if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(inp.value)) { msg(form, 'Enter a valid email address to receive your link.', false); return; }
        sendBtn.disabled = true;
        fetch(form.action, { method: 'POST', body: draftData(form, inp.value), headers: { 'X-Requested-With': 'XMLHttpRequest' } })
          .then(function (r) { return r.json(); })
          .then(function (j) { msg(form, j.message || 'Saved.', !!j.success); if (j.success && box.parentNode) box.parentNode.removeChild(box); })
          .catch(function () { msg(form, 'Could not save right now. Please try again.', false); })
          .then(function () { sendBtn.disabled = false; });
      });
    });
    var tok = new URLSearchParams(location.search).get('tf_resume');
    if (tok && /^[a-f0-9]{40}$/.test(tok)) {
      fetch(form.action + (form.action.indexOf('?') > -1 ? '&' : '?') + 'tf_draft=' + tok + '&tf_form_id=' + encodeURIComponent(form.getAttribute('data-form-id')), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j.success) { msg(form, j.message || 'This saved link is no longer valid.', false); return; }
          Object.keys(j.data).forEach(function (k) {
            var v = j.data[k], els = form.querySelectorAll('[name="' + k + '"],[name="' + k + '[]"]');
            [].forEach.call(els, function (el) {
              if (el.type === 'checkbox' || el.type === 'radio') el.checked = Array.isArray(v) ? v.indexOf(el.value) > -1 : el.value === v;
              else if (!Array.isArray(v)) el.value = v;
            });
          });
          var h = d.createElement('input'); h.type = 'hidden'; h.name = 'tf_resume_token'; h.value = tok; form.appendChild(h);
          refresh(form); msg(form, 'Welcome back! Your saved answers are filled in. Re-add any files or signature before sending.', true);
        }).catch(function () {});
    }
  }

  function refresh(form) {
    applyLogic(form); calc(form);
    if (form._steps) showStep(form, form._cur || 0);
  }

  function init(form) {
    if (form.__tf) return; form.__tf = true;
    var started = Date.now(); campaign();
    if (store('tf_ref') === null) store('tf_ref', d.referrer || '');
    form.classList.add('tf-js');
    function getToken() { // server-signed, single-use token for the built-in spam check
      fetch(form.action + (form.action.indexOf('?') > -1 ? '&' : '?') + 'tf_token=1&tf_form_id=' + encodeURIComponent(form.getAttribute('data-form-id')), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json(); }).then(function (j) { form._tok = (j && j.token) || ''; }).catch(function () {});
    }
    getToken();
    initExtCaptcha(form);
    var cw = form.querySelector('.tf-captcha:not(.tf-ext-cap)');
    if (cw) {
      capLoad(form, false);
      cw.querySelector('.tf-cap-refresh').addEventListener('click', function () { capLoad(form, !!cw._alt); });
      var ab = cw.querySelector('.tf-cap-alt'); if (ab) ab.addEventListener('click', function () { capLoad(form, true); });
    }
    form._steps = form.querySelectorAll('fieldset.tf-step');
    if (form._steps.length > 1) { form.classList.add('tf-multi'); form._cur = 0; } else form._steps = null;
    [].forEach.call(form.querySelectorAll('.tf-field[data-type=signature]'), initSig);
    function fixed(ev) { // clear a field's error as soon as the visitor edits it
      if (ev.type === 'input' && ev.target.getAttribute && ev.target.getAttribute('data-phone') === 'us') maskUS(ev.target);
      var w = ev.target.closest && ev.target.closest('.tf-field.tf-invalid');
      if (w) { w.classList.remove('tf-invalid'); var e = w.querySelector('.tf-error'); if (e) e.textContent = ''; }
      refresh(form);
    }
    form.addEventListener('input', fixed);
    form.addEventListener('change', fixed);
    var nx = form.querySelector('.tf-next'), pv = form.querySelector('.tf-prev');
    if (nx) nx.addEventListener('click', function () { go(form, 1); });
    if (pv) pv.addEventListener('click', function () { go(form, -1); });
    initResume(form);
    refresh(form);

    var p = new URLSearchParams(location.search);
    if (p.get('tf_status') && p.get('tf_msg') && !form.id.indexOf('tf-form-')) msg(form, p.get('tf_msg'), p.get('tf_status') === 'ok');

    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      if (form._steps && !form.classList.contains('tf-last')) { go(form, 1); return; } // Enter key on an earlier step = Next
      clearErrors(form);
      var m = form.querySelector('.tf-message'); if (m) { m.textContent = ''; m.className = 'tf-message'; }
      if (!validate(form)) {
        var bad = form.querySelector('.tf-invalid');
        if (bad && form._steps) { var st = bad.closest('.tf-step'); if (st) showStep(form, +st.getAttribute('data-step')); }
        if (bad) try { bad.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (e) {}
        return;
      }
      if (!extCapSolved(form)) { var cw2 = form.querySelector('.tf-ext-cap'); if (cw2._start) cw2._start(); var we = setError(form, 'tf_captcha', 'Please tick the captcha box.'); if (we) try { we.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (e) {} return; }
      var btn = form.querySelector('.tf-submit'), label = btn.textContent;
      btn.disabled = true; btn.textContent = 'Sending...';
      var fd = new FormData(form); fd.append('tf_page', location.href); fd.append('tf_token', form._tok || ''); fd.append('tf_srcpage', d.referrer || '');
      fd.append('tf_elapsed', Date.now() - started); fd.append('tf_utm', JSON.stringify(campaign())); fd.append('tf_ref', store('tf_ref') || d.referrer || '');
      fetch(form.action, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j.success) {
            if (j.redirect) { window.location.href = j.redirect; return; }
            msg(form, j.message, true); form.reset(); getToken(); capLoad(form, false); extCapReset(form);
            [].forEach.call(form.querySelectorAll('.tf-field[data-type=signature]'), function (w) { if (w._clearSig) w._clearSig(); });
            started = Date.now(); if (form._steps) showStep(form, 0); refresh(form);
          } else {
            var first = null;
            for (var k in j.errors) { var w = setError(form, k, j.errors[k]); if (w && !first) first = w; }
            if (j.captcha) capRender(form, j.captcha);                       // the old challenge is gone: show the new one
            else if (j.errors && j.errors.tf_captcha) { var ai = form.querySelector('.tf-cap-ans'); if (ai) { ai.value = ''; ai.focus(); } }
            if (first && form._steps) { var s2 = first.closest('.tf-step'); if (s2) showStep(form, +s2.getAttribute('data-step')); }
            extCapReset(form);
            msg(form, j.message || 'Something went wrong.', false);
          }
        })
        .catch(function () { msg(form, 'Network error. Please try again.', false); })
        .then(function () { btn.disabled = false; btn.textContent = label; });
    });
  }
  function boot(root) { [].forEach.call((root && root.querySelectorAll ? root : d).querySelectorAll('form.tf-form'), init); }
  window.TopTenForm = { init: boot }; // used by the JavaScript embed
  if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', function () { boot(); }); else boot();
})();
