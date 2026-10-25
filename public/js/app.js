/* CMAU SaaS staff screens. Plain JS, no build step. */
(function () {
  'use strict';

  // Confirm before destructive submits: <form data-confirm="Remove this line?">
  document.addEventListener('submit', function (e) {
    var msg = e.target.getAttribute && e.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) { e.preventDefault(); }
  });

  function el(tag, attrs, text) {
    var n = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) { n.setAttribute(k, attrs[k]); });
    if (text !== undefined && text !== null) { n.textContent = String(text); }
    return n;
  }

  // ---------------------------------------------------------------- size x qty
  document.querySelectorAll('[data-sku-panel]').forEach(function (panel) {
    var form = panel.querySelector('form');
    var body = panel.querySelector('tbody');
    var head = panel.querySelector('thead tr');
    var modeInput = form.querySelector('input[name=mode]');
    var status = panel.querySelector('[data-status]');
    var totalOut = panel.querySelector('[data-total]');
    var target = parseInt(panel.getAttribute('data-quantity'), 10) || 0;
    var colour = (panel.getAttribute('data-colour') || '').toLowerCase();
    var saved = JSON.parse(panel.getAttribute('data-saved') || '[]');
    var listRows = null;
    var mode = modeInput.value;

    function key(c, s) { return (c || '').toLowerCase() + '|' + (s || '').toLowerCase(); }
    function savedQty(c, s) {
      var k = key(c, s), q = '';
      saved.forEach(function (r) { if (key(r.colour, r.size) === k) { q = r.quantity; } });
      return q;
    }

    function total() {
      var t = 0;
      body.querySelectorAll('input[data-qty]').forEach(function (i) { t += parseInt(i.value, 10) || 0; });
      totalOut.textContent = t + ' of ' + target;
      totalOut.className = 'pill ' + (t === target ? 'ok' : (t === 0 ? '' : 'warn'));
      body.querySelectorAll('tr[data-stock]').forEach(function (tr) {
        var q = parseInt(tr.querySelector('input[data-qty]').value, 10) || 0;
        var s = parseInt(tr.getAttribute('data-stock'), 10);
        tr.classList.toggle('short', !isNaN(s) && q > s);
      });
    }

    function input(name, value, attrs) {
      var i = el('input', Object.assign({ name: name, value: value === null || value === undefined ? '' : value }, attrs || {}));
      return i;
    }

    function renderList() {
      head.innerHTML = '';
      ['Colour', 'Size', 'In stock', 'Supplier SKU', 'Qty'].forEach(function (h) { head.appendChild(el('th', {}, h)); });
      body.innerHTML = '';
      var onlyColour = panel.querySelector('[data-only-colour]');
      var filter = onlyColour && onlyColour.checked && colour;
      listRows.forEach(function (r, i) {
        if (filter && (r.colour || '').toLowerCase() !== colour) { return; }
        var tr = el('tr', { 'data-stock': r.qty === null ? '' : r.qty });
        tr.appendChild(el('td', {}, r.colour || '–'));
        tr.appendChild(el('td', {}, r.size || '–'));
        tr.appendChild(el('td', { class: 'num' }, r.qty === null ? '?' : r.qty.toLocaleString()));
        tr.appendChild(el('td', { class: 'mono small' }, r.sku || ''));
        var td = el('td');
        td.appendChild(input('rows[' + i + '][quantity]', savedQty(r.colour, r.size), { type: 'number', min: '0', class: 'n', 'data-qty': '', style: 'width:90px', 'aria-label': 'Quantity ' + (r.colour || '') + ' ' + (r.size || '') }));
        td.appendChild(input('rows[' + i + '][colour]', r.colour, { type: 'hidden' }));
        td.appendChild(input('rows[' + i + '][size]', r.size, { type: 'hidden' }));
        td.appendChild(input('rows[' + i + '][supplier_sku]', r.sku, { type: 'hidden' }));
        td.appendChild(input('rows[' + i + '][stock_on_hand]', r.qty, { type: 'hidden' }));
        tr.appendChild(td);
        body.appendChild(tr);
      });
      total();
    }

    var manualCount = 0;
    function manualRow(r) {
      var i = manualCount++;
      var tr = el('tr');
      [['colour', r.colour, 'text', 'Colour'], ['size', r.size, 'text', 'Size'], ['quantity', r.quantity, 'number', 'Qty']].forEach(function (f) {
        var td = el('td');
        var attrs = { type: f[2], placeholder: f[3], 'aria-label': f[3] };
        if (f[0] === 'quantity') { attrs.min = '0'; attrs.class = 'n'; attrs['data-qty'] = ''; attrs.style = 'width:90px'; }
        td.appendChild(input('rows[' + i + '][' + f[0] + ']', f[1], attrs));
        tr.appendChild(td);
      });
      var td = el('td');
      var rm = el('button', { type: 'button', class: 'btn link' }, 'Remove');
      rm.addEventListener('click', function () { tr.remove(); total(); });
      td.appendChild(rm);
      tr.appendChild(td);
      body.appendChild(tr);
    }

    function renderManual() {
      head.innerHTML = '';
      ['Colour', 'Size', 'Qty', ''].forEach(function (h) { head.appendChild(el('th', {}, h)); });
      body.innerHTML = '';
      manualCount = 0;
      var rows = saved.length ? saved : [{ colour: panel.getAttribute('data-colour') || '', size: '', quantity: '' }];
      rows.forEach(manualRow);
      total();
    }

    function load() {
      status.textContent = 'Checking live stock…';
      fetch(panel.getAttribute('data-list-url'), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data.ok || !data.rows.length) {
            status.textContent = (data.message || 'No SKU list for this product.') + ' Enter sizes by hand instead.';
            setMode('manual');
            return;
          }
          listRows = data.rows;
          status.textContent = data.rows.length + ' SKUs from the supplier, checked just now.';
          renderList();
        })
        .catch(function () { status.textContent = 'Stock check failed. Try again, or enter sizes by hand.'; });
    }

    function setMode(m) {
      mode = m;
      modeInput.value = m;
      panel.querySelectorAll('[data-mode]').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-mode') === m); });
      panel.querySelectorAll('[data-list-only]').forEach(function (n) { n.hidden = m !== 'list'; });
      panel.querySelectorAll('[data-manual-only]').forEach(function (n) { n.hidden = m !== 'manual'; });
      if (m === 'list') { if (listRows) { renderList(); } else { body.innerHTML = ''; load(); } } else { renderManual(); }
    }

    panel.querySelectorAll('[data-mode]').forEach(function (b) {
      b.addEventListener('click', function () { setMode(b.getAttribute('data-mode')); });
    });
    var add = panel.querySelector('[data-add-row]');
    if (add) { add.addEventListener('click', function () { manualRow({ colour: panel.getAttribute('data-colour') || '', size: '', quantity: '' }); }); }
    var only = panel.querySelector('[data-only-colour]');
    if (only) { only.addEventListener('change', function () { if (listRows) { renderList(); } }); }
    var reload = panel.querySelector('[data-reload]');
    if (reload) { reload.addEventListener('click', load); }
    body.addEventListener('input', total);

    var details = panel.closest('details');
    var started = false;
    function start() { if (!started) { started = true; setMode(mode); } }
    if (details) { details.addEventListener('toggle', function () { if (details.open) { start(); } }); if (details.open) { start(); } } else { start(); }
  });

  // ---------------------------------------------------------- add from range
  document.querySelectorAll('[data-product-search]').forEach(function (box) {
    var q = box.querySelector('input[type=search]');
    var results = box.querySelector('.results');
    var form = box.querySelector('form');
    var chosen = box.querySelector('[data-chosen]');
    var timer = null;

    q.addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(function () {
        if (q.value.trim().length < 2) { results.innerHTML = ''; return; }
        fetch(box.getAttribute('data-search-url') + '?q=' + encodeURIComponent(q.value.trim()), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (list) {
            results.innerHTML = '';
            if (!list.length) { results.appendChild(el('span', { class: 'muted small' }, 'No products found.')); }
            list.forEach(function (p) {
              var b = el('button', { type: 'button' });
              b.appendChild(el('b', {}, p.name));
              b.appendChild(el('span', { class: 'muted' }, '  ' + [p.code, p.supplier, p.id, p.tier].filter(Boolean).join(' · ')));
              b.addEventListener('click', function () { choose(p); });
              results.appendChild(b);
            });
          });
      }, 250);
    });

    function choose(p) {
      results.innerHTML = '';
      form.hidden = false;
      form.querySelector('input[name=product_id]').value = p.id;
      chosen.textContent = p.name + (p.code ? ' (' + p.code + ')' : '') + (p.supplier ? ', ' + p.supplier : '');
      var colours = form.querySelector('select[name=colour]');
      var decs = form.querySelector('select[name=decoration_option_id]');
      colours.innerHTML = '<option value="">Any / not set</option>';
      decs.innerHTML = '<option value="">Unbranded</option>';
      fetch(box.getAttribute('data-options-url').replace('__ID__', p.id), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (o) {
          o.colours.forEach(function (c) { colours.appendChild(el('option', { value: c }, c)); });
          o.decorations.forEach(function (d) { decs.appendChild(el('option', { value: d.id }, d.name + (d.setup ? ' (setup ' + d.setup.toFixed(2) + ')' : ''))); });
        });
    }
  });

  // Decoration option pickers on item edit forms load on first focus.
  document.querySelectorAll('select[data-options-url]').forEach(function (sel) {
    var loaded = false;
    sel.addEventListener('focus', function () {
      if (loaded) { return; }
      loaded = true;
      var current = sel.value;
      fetch(sel.getAttribute('data-options-url'), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (o) {
          o.decorations.forEach(function (d) {
            if (d.id === current) { return; }
            sel.appendChild(el('option', { value: d.id }, d.name));
          });
        });
    });
  });
})();
