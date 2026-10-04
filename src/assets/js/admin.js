(function () {
  'use strict';
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };
  function el(tag, attrs, children) {
    var n = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'class') n.className = attrs[k];
      else if (k === 'text') n.textContent = attrs[k];
      else if (k === 'value') n.value = attrs[k];
      else n.setAttribute(k, attrs[k]);
    });
    (children || []).forEach(function (c) { if (c) n.appendChild(c); });
    return n;
  }

  /* ------------------------------------------------ character counters --- */
  $$('[data-counter-for]').forEach(function (c) {
    var input = document.getElementById(c.getAttribute('data-counter-for')), max = c.getAttribute('data-max');
    if (!input) return;
    var upd = function () { c.textContent = input.value.length + ' / ' + max; };
    input.addEventListener('input', upd); upd();
  });

  /* ---------------------------------------------------------- tag chips --- */
  $$('input[data-chips]').forEach(function (input) {
    var tags = [];
    var box = el('div', { 'class': 'chip-input' });
    var typer = el('input', { type: 'text', placeholder: input.getAttribute('placeholder') || '' });
    input.type = 'hidden';
    input.parentNode.insertBefore(box, input);
    box.appendChild(typer);

    function sync() { input.value = tags.join(', '); }
    function render() {
      $$('.tag', box).forEach(function (t) { t.remove(); });
      tags.forEach(function (t, i) {
        var rm = el('button', { type: 'button', 'aria-label': 'Remove ' + t, text: '×' });
        rm.addEventListener('click', function () { tags.splice(i, 1); render(); sync(); });
        box.insertBefore(el('span', { 'class': 'tag' }, [document.createTextNode(t), rm]), typer);
      });
    }
    function add(raw) {
      raw.split(/[,\n;]+/).forEach(function (part) {
        var t = part.replace(/\s+/g, ' ').trim().slice(0, 40);
        if (!t || tags.length >= 20) return;
        if (!tags.some(function (x) { return x.toLowerCase() === t.toLowerCase(); })) tags.push(t);
      });
      render(); sync();
    }
    add(input.value);
    typer.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); add(typer.value); typer.value = ''; }
      else if (e.key === 'Backspace' && !typer.value && tags.length) { tags.pop(); render(); sync(); }
    });
    typer.addEventListener('blur', function () { if (typer.value.trim()) { add(typer.value); typer.value = ''; } });
    typer.addEventListener('paste', function (e) {
      var text = (e.clipboardData || window.clipboardData).getData('text');
      if (/[,\n;]/.test(text)) { e.preventDefault(); add(text); }
    });
    box.addEventListener('click', function () { typer.focus(); });
  });

  /* --------------------------------------------------- image previews ----- */
  $$('input[type=file][data-preview]').forEach(function (input) {
    var target = document.querySelector(input.getAttribute('data-preview'));
    if (!target) return;
    input.addEventListener('change', function () {
      $$('.is-new', target).forEach(function (n) { n.remove(); });
      if (input.hasAttribute('data-replace')) $$('.thumb-tile', target).forEach(function (n) { n.style.opacity = '.35'; });
      Array.prototype.forEach.call(input.files, function (file) {
        if (!/^image\//.test(file.type)) return;
        var img = el('img', { alt: '' });
        img.src = URL.createObjectURL(file);
        target.appendChild(el('div', { 'class': 'thumb-tile is-new' }, [img]));
      });
    });
  });

  /* --------------------------------------------------------- page slug ---- */
  var nameEl = document.getElementById('name'), slugEl = document.getElementById('slug'), slugPrev = document.getElementById('slugPreview');
  if (nameEl && slugEl && slugPrev) {
    var slugify = function (s) { return s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, ''); };
    var show = function () { slugPrev.textContent = slugify(slugEl.value) || slugify(nameEl.value) || '…'; };
    nameEl.addEventListener('input', show); slugEl.addEventListener('input', show); show();
  }

  /* ------------------------------------------------ unsaved-changes note --- */
  var pform = document.getElementById('productForm'), note = document.getElementById('dirtyNote');
  if (pform && note) {
    var dirty = false, submitting = false;
    pform.addEventListener('input', function () { dirty = true; note.textContent = 'Unsaved changes'; });
    pform.addEventListener('change', function () { dirty = true; note.textContent = 'Unsaved changes'; });
    pform.addEventListener('submit', function () { submitting = true; });
    window.addEventListener('beforeunload', function (e) { if (dirty && !submitting) { e.preventDefault(); e.returnValue = ''; } });
  }

  /* ========================================================================
     Variant editor: Colors + Sizes + per-combination stock
     ======================================================================== */
  var mount = document.getElementById('variantEditor');
  if (!mount || !window.KAFEEL_VARIANT_EDITOR) return;
  var init = window.KAFEEL_VARIANT_EDITOR;
  var stockInput = document.getElementById('stock'), stockHint = document.getElementById('stockHint');
  var uid = 0, nextRid = function () { return 'r' + (++uid); };

  var colors = [], sizes = [];     // {rid, name, swatch, image, weight, h, w, d}
  var comboState = {};             // "cRid|sRid" -> {id, sku, delta, stock, active}

  var colorList = el('div', { 'class': 'opt-list' });
  var sizeList = el('div', { 'class': 'opt-list' });
  var comboWrap = el('div');

  function addBtn(label, fn) { var b = el('button', { type: 'button', 'class': 'btn btn-outline btn-sm', text: label }); b.addEventListener('click', fn); return b; }

  mount.appendChild(el('div', { 'class': 'opt-block' }, [
    el('h3', { text: 'Colors' }), el('div', { 'class': 'help', text: 'Optional. Give each color a swatch and/or a photo — the main picture switches to it when chosen.' }),
    colorList, addBtn('+ Add color', function () { addColor({}); refreshCombos(); focusLast(colorList); })
  ]));
  mount.appendChild(el('div', { 'class': 'opt-block' }, [
    el('h3', { text: 'Sizes' }), el('div', { 'class': 'help', text: 'Optional. Leave a size\'s dimensions or weight empty to use the product defaults above.' }),
    sizeList, addBtn('+ Add size', function () { addSize({}); refreshCombos(); focusLast(sizeList); })
  ]));
  mount.appendChild(el('div', { 'class': 'opt-block' }, [
    el('h3', { text: 'Stock & price per combination' }), comboWrap
  ]));

  function focusLast(list) { var last = list.lastElementChild; if (last) { var i = last.querySelector('input[type=text]'); if (i) i.focus(); } }

  function imgPicker(fieldBase, obj) {
    var thumb = obj.image ? el('img', { src: obj.image, alt: '' }) : el('span', { 'class': 'ph', text: '＋' });
    var file = el('input', { type: 'file', name: fieldBase + '_image[]', accept: 'image/jpeg,image/png,image/webp,image/gif' });
    var existing = el('input', { type: 'hidden', name: fieldBase + '_image_existing[]', value: obj.image || '' });
    var label = el('label', { text: obj.image ? 'Change' : 'Add photo' }, [file]);
    var clear = el('button', { type: 'button', 'class': 'clear-photo', title: 'Remove this photo', text: 'clear', style: obj.image ? '' : 'display:none' });
    var holder = el('div', { 'class': 'img-pick' }, [thumb, label, clear, existing]);
    file.addEventListener('change', function () {
      if (!file.files[0]) return;
      var img = el('img', { alt: '' }); img.src = URL.createObjectURL(file.files[0]);
      holder.replaceChild(img, holder.firstChild); thumb = img;
      label.firstChild.textContent = 'Change'; clear.style.display = '';
    });
    clear.addEventListener('click', function () {
      existing.value = ''; file.value = '';
      var ph = el('span', { 'class': 'ph', text: '＋' }); holder.replaceChild(ph, holder.firstChild);
      label.firstChild.textContent = 'Add photo'; clear.style.display = 'none';
    });
    return holder;
  }

  function addColor(c) {
    var o = { rid: nextRid(), name: c.name || '', swatch: c.swatch || '', image: c.image || '' };
    var picker = el('input', { type: 'color', title: 'Swatch color (optional)', value: o.swatch || '#cccccc', style: o.swatch ? '' : 'opacity:.45' });
    var hidden = el('input', { type: 'hidden', name: 'color_swatch[]', value: o.swatch });
    picker.addEventListener('input', function () { hidden.value = picker.value; picker.style.opacity = ''; });
    var name = el('input', { type: 'text', name: 'color_name[]', value: o.name, placeholder: 'e.g. Chestnut brown', maxlength: 60, 'aria-label': 'Color name' });
    name.addEventListener('input', function () { o.name = name.value.trim(); refreshCombos(); });
    var rm = el('button', { type: 'button', 'class': 'rm', title: 'Remove color', text: '×' });
    rm.addEventListener('click', function () { colors = colors.filter(function (x) { return x !== o; }); row.remove(); refreshCombos(); });
    var row = el('div', { 'class': 'opt-row color' }, [el('div', {}, [picker, hidden]), name, imgPicker('color', o), rm]);
    colors.push(o); colorList.appendChild(row);
    return o;
  }

  function numField(label, nm, val, ph, o, key) {
    var input = el('input', { type: 'number', min: 0, name: nm, value: val === null || val === undefined ? '' : val, placeholder: ph || '' });
    input.addEventListener('input', function () { o[key] = input.value; });
    return el('div', {}, [el('span', { 'class': 'mini-label', text: label }), input]);
  }
  function baseVal(id) { var e = document.getElementById(id); return e && e.value ? e.value : ''; }

  function addSize(s) {
    var o = { rid: nextRid(), name: s.name || '', image: s.image || '', weight: s.weight, h: s.h, w: s.w, d: s.d };
    var name = el('input', { type: 'text', name: 'size_name[]', value: o.name, placeholder: 'e.g. Large', maxlength: 60, 'aria-label': 'Size name' });
    name.addEventListener('input', function () { o.name = name.value.trim(); refreshCombos(); });
    var rm = el('button', { type: 'button', 'class': 'rm', title: 'Remove size', text: '×' });
    rm.addEventListener('click', function () { sizes = sizes.filter(function (x) { return x !== o; }); row.remove(); refreshCombos(); });
    var row = el('div', { 'class': 'opt-row size' }, [
      el('div', {}, [el('span', { 'class': 'mini-label', text: 'Size name' }), name]),
      numField('Weight g', 'size_weight[]', o.weight, baseVal('weight_grams'), o, 'weight'),
      numField('Height mm', 'size_h[]', o.h, baseVal('height_mm'), o, 'h'),
      numField('Width mm', 'size_w[]', o.w, baseVal('width_mm'), o, 'w'),
      numField('Depth mm', 'size_d[]', o.d, baseVal('depth_mm'), o, 'd'),
      imgPicker('size', o), rm
    ]);
    sizes.push(o); sizeList.appendChild(row);
    return o;
  }

  /* ---- combinations table ---- */
  function key(c, s) { return (c ? c.rid : '') + '|' + (s ? s.rid : ''); }

  function refreshCombos() {
    var cs = colors.filter(function (c) { return c.name; }), ss = sizes.filter(function (s) { return s.name; });
    comboWrap.innerHTML = '';
    var total = 0, count = 0;
    if (!cs.length && !ss.length) {
      comboWrap.appendChild(el('div', { 'class': 'combo-empty', text: 'No colors or sizes yet — this product uses the single Stock number above.' }));
      setStockMode(false, 0); return;
    }
    var table = el('table', { 'class': 'combo-table' });
    table.appendChild(el('thead', {}, [el('tr', {}, ['Combination', 'SKU', 'Price ± (' + (window.KAFEEL_CURRENCY || '') + ')', 'Stock', 'On sale'].map(function (h) { return el('th', { text: h }); }))]));
    var tbody = el('tbody'); var n = 0;
    (cs.length ? cs : [null]).forEach(function (c) {
      (ss.length ? ss : [null]).forEach(function (s) {
        var k = key(c, s);
        var st = comboState[k] || (comboState[k] = { id: '', sku: '', delta: 0, stock: 0, active: 1 });
        var i = n++; count++;
        var pre = 'combos[' + i + ']';
        var label = [c && c.name, s && s.name].filter(Boolean).join(' / ');
        var tr = el('tr', { 'class': st.active ? '' : 'is-off' });
        var inp = function (field, type, attrs) {
          var a = { type: type, name: pre + '[' + field + ']', value: st[field] === null || st[field] === undefined ? '' : st[field] };
          Object.keys(attrs || {}).forEach(function (x) { a[x] = attrs[x]; });
          var input = el('input', a);
          input.addEventListener('input', function () { st[field] = input.value; if (field === 'stock') updateTotal(); });
          return input;
        };
        var active = el('input', { type: 'checkbox', name: pre + '[active]', value: '1' }); active.checked = !!Number(st.active);
        active.addEventListener('change', function () { st.active = active.checked ? 1 : 0; tr.classList.toggle('is-off', !active.checked); updateTotal(); });
        tr.appendChild(el('td', { 'class': 'combo-name' }, [
          document.createTextNode(label),
          el('input', { type: 'hidden', name: pre + '[color]', value: c ? c.name : '' }),
          el('input', { type: 'hidden', name: pre + '[size]', value: s ? s.name : '' }),
          el('input', { type: 'hidden', name: pre + '[id]', value: st.id || '' })
        ]));
        tr.appendChild(el('td', {}, [inp('sku', 'text', { maxlength: 60, placeholder: 'optional' })]));
        tr.appendChild(el('td', {}, [inp('delta', 'number', { step: '0.01' })]));
        tr.appendChild(el('td', {}, [inp('stock', 'number', { min: 0 })]));
        tr.appendChild(el('td', {}, [active]));
        tbody.appendChild(tr);
      });
    });
    table.appendChild(tbody); comboWrap.appendChild(table);
    var totalEl = el('div', { 'class': 'help', style: 'margin:10px 0 0;' });
    comboWrap.appendChild(totalEl);
    function updateTotal() {
      var t = 0;
      Object.keys(comboState).forEach(function (k) { /* only visible ones count */ });
      $$('tbody tr', table).forEach(function (tr) {
        var stock = parseInt(tr.querySelector('input[name$="[stock]"]').value, 10) || 0;
        if (tr.querySelector('input[type=checkbox]').checked) t += stock;
      });
      totalEl.textContent = count + ' combination' + (count === 1 ? '' : 's') + ' · ' + t + ' in stock in total';
      setStockMode(true, t);
    }
    updateTotal();
  }

  function setStockMode(managed, total) {
    if (!stockInput) return;
    stockInput.readOnly = managed;
    stockInput.style.background = managed ? '#f1f0ea' : '';
    if (managed) stockInput.value = total;
    if (stockHint) stockHint.textContent = managed ? 'Calculated from the combinations below.' : 'Units on hand. Ignored once you add colors or sizes below — stock is then tracked per combination.';
  }

  // ---- load initial data
  var colorByName = {}, sizeByName = {};
  (init.colors || []).forEach(function (c) { colorByName[c.name.toLowerCase()] = addColor(c); });
  (init.sizes || []).forEach(function (s) { sizeByName[s.name.toLowerCase()] = addSize(s); });
  (init.combos || []).forEach(function (v) {
    var c = v.color ? colorByName[String(v.color).toLowerCase()] : null;
    var s = v.size ? sizeByName[String(v.size).toLowerCase()] : null;
    if ((v.color && !c) || (v.size && !s)) return;
    comboState[key(c, s)] = { id: v.id || '', sku: v.sku || '', delta: v.delta || 0, stock: v.stock || 0, active: v.active === 0 ? 0 : 1 };
  });
  refreshCombos();
})();
