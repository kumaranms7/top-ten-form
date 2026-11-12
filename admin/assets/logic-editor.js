/* Conditional-logic editor, shared by the form builder (show/hide a field) and notification routing (send only when...).
   TFLogic.mount(container, { logic, fields:[{name,label,type,options}], withAction, onChange(logicOrNull) }) */
(function () {
  'use strict';
  var OPS = { is: 'is', is_not: 'is not', contains: 'contains', not_contains: 'does not contain', gt: 'is greater than', lt: 'is less than', empty: 'is empty', not_empty: 'is not empty' };
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  function mount(box, o) {
    var fields = o.fields || [], st = o.logic && o.logic.rules && o.logic.rules.length ? JSON.parse(JSON.stringify(o.logic)) : null;
    var enabled = !!st;
    if (!st) st = { action: 'show', match: 'all', rules: [] };
    function byName(n) { return fields.filter(function (f) { return f.name === n; })[0]; }
    function emit() {
      var rules = st.rules.filter(function (r) { return r.field; });
      o.onChange(enabled && rules.length ? { action: st.action, match: st.match, rules: rules } : null);
    }
    function valueControl(r, i) {
      if (r.op === 'empty' || r.op === 'not_empty') return '';
      var f = byName(r.field), opts = f && f.options && f.options.length ? f.options : (f && f.type === 'consent' ? ['Yes'] : null);
      if (opts && ['is', 'is_not', 'contains', 'not_contains'].indexOf(r.op) > -1)
        return '<select data-r="value" data-i="' + i + '"><option value="">Choose...</option>' + opts.map(function (v) { return '<option' + (v === r.value ? ' selected' : '') + '>' + esc(v) + '</option>'; }).join('') + '</select>';
      return '<input data-r="value" data-i="' + i + '" value="' + esc(r.value) + '" placeholder="value">';
    }
    function draw() {
      var h = '<label class="inline"><input type="checkbox" data-lg="enable"' + (enabled ? ' checked' : '') + '> ' + (o.withAction ? 'Use conditional logic' : 'Only send when certain answers match') + '</label>';
      if (enabled) {
        h += '<div class="lg-body">';
        h += o.withAction
          ? '<div class="lg-line"><select data-lg="action"><option value="show"' + (st.action === 'show' ? ' selected' : '') + '>Show</option><option value="hide"' + (st.action === 'hide' ? ' selected' : '') + '>Hide</option></select> this field when <select data-lg="match"><option value="all"' + (st.match === 'all' ? ' selected' : '') + '>all</option><option value="any"' + (st.match === 'any' ? ' selected' : '') + '>any</option></select> of these match:</div>'
          : '<div class="lg-line">Send when <select data-lg="match"><option value="all"' + (st.match === 'all' ? ' selected' : '') + '>all</option><option value="any"' + (st.match === 'any' ? ' selected' : '') + '>any</option></select> of these match:</div>';
        st.rules.forEach(function (r, i) {
          h += '<div class="lg-rule"><select data-r="field" data-i="' + i + '"><option value="">Field...</option>' + fields.map(function (f) { return '<option value="' + esc(f.name) + '"' + (f.name === r.field ? ' selected' : '') + '>' + esc(f.label || f.name) + '</option>'; }).join('') + '</select>' +
            '<select data-r="op" data-i="' + i + '">' + Object.keys(OPS).map(function (k) { return '<option value="' + k + '"' + (k === r.op ? ' selected' : '') + '>' + OPS[k] + '</option>'; }).join('') + '</select>' +
            valueControl(r, i) + '<button type="button" class="lg-del" data-r="del" data-i="' + i + '" aria-label="Remove condition">&times;</button></div>';
        });
        h += '<button type="button" class="btn sec sm" data-lg="add">+ Add condition</button>';
        if (!fields.length) h += '<p class="muted">Add some other fields first, then choose them here.</p>';
        h += '</div>';
      }
      box.innerHTML = h;
    }
    box.addEventListener('change', function (ev) {
      var t = ev.target, lg = t.getAttribute('data-lg'), r = t.getAttribute('data-r'), i = +t.getAttribute('data-i');
      if (lg === 'enable') { enabled = t.checked; if (enabled && !st.rules.length) st.rules.push({ field: '', op: 'is', value: '' }); draw(); emit(); return; }
      if (lg === 'action') st.action = t.value; else if (lg === 'match') st.match = t.value;
      else if (r === 'field') { st.rules[i].field = t.value; st.rules[i].value = ''; draw(); }
      else if (r === 'op') { st.rules[i].op = t.value; draw(); }
      else if (r === 'value') st.rules[i].value = t.value;
      else return;
      emit();
    });
    box.addEventListener('input', function (ev) { var t = ev.target; if (t.getAttribute('data-r') === 'value' && t.tagName === 'INPUT') { st.rules[+t.getAttribute('data-i')].value = t.value; emit(); } });
    box.addEventListener('click', function (ev) {
      var t = ev.target.closest('[data-lg="add"],[data-r="del"]'); if (!t) return;
      if (t.getAttribute('data-lg') === 'add') st.rules.push({ field: '', op: 'is', value: '' });
      else st.rules.splice(+t.getAttribute('data-i'), 1);
      draw(); emit();
    });
    draw();
  }
  window.TFLogic = { mount: mount, OPS: OPS };
})();
