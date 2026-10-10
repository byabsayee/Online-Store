/**
 * Product page picker: separate Color and Size selectors that stay in sync
 * with the real variant rows, and drive price, stock, preview photo,
 * dimensions and weight. Data comes from window.STORE_PRODUCT (product.php).
 */
(function (root) {
  'use strict';

  function create(data, doc) {
    doc = doc || root.document;
    var $ = function (id) { return doc.getElementById(id); };
    var els = {
      price: $('productPrice'), variantField: $('variantIdField'), qty: $('qtyField'), addBtn: $('addCartBtn'),
      stockLine: $('stockLine'), customField: $('customIdField'), customChosen: $('customChosen'), customNote: $('customNote'), mainImg: $('galleryMainImg'), colorChosen: $('colorChosen'), sizeChosen: $('sizeChosen'),
      dimsRow: $('metaDims'), dimsVal: $('metaDimsVal'), weightRow: $('metaWeight'), weightVal: $('metaWeightVal')
    };
    var hasColors = data.colors.length > 0, hasSizes = data.sizes.length > 0;
    var hasCustoms = (data.customs || []).length > 0;
    var simple = data.variants.length === 0; // customization only: stock and cart controls stay as the server rendered them
    var state = { color: null, size: null, custom: '', last: null };

    function money(n) { return data.symbol + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }
    function find(list, name) { for (var i = 0; i < list.length; i++) if (list[i].name === name) return list[i]; return null; }

    /** The variant row for a color/size pair (either may be null when the product doesn't use that option). */
    function variantFor(color, size) {
      for (var i = 0; i < data.variants.length; i++) {
        var v = data.variants[i];
        if ((v.color || null) === (color || null) && (v.size || null) === (size || null)) return v;
      }
      return null;
    }
    function customChosen() {
      if (!hasCustoms || state.custom === '') return null;
      for (var i = 0; i < data.customs.length; i++) if (String(data.customs[i].id) === String(state.custom)) return data.customs[i];
      return null;
    }
    function hasStock(v) { return !!v && v.stock > 0; }
    /** Can this combination be ordered? Real stock, or the product takes orders at zero stock. */
    function inStock(v) { return !!v && (v.stock > 0 || !!data.backorder); }

    /** Is choosing `value` for `kind` possible (with the other axis as currently selected)? */
    function available(kind, value) {
      var v = kind === 'color' ? variantFor(value, hasSizes ? state.size : null) : variantFor(hasColors ? state.color : null, value);
      return inStock(v);
    }

    /** Pick sensible partner when switching one axis makes the current pair impossible. */
    function fixPartner(changed) {
      if (!hasColors || !hasSizes) return;
      var cur = variantFor(state.color, state.size);
      if (inStock(cur)) return;
      var list = changed === 'color' ? data.sizes : data.colors, best = null;
      for (var i = 0; i < list.length; i++) {
        var v = changed === 'color' ? variantFor(state.color, list[i].name) : variantFor(list[i].name, state.size);
        if (inStock(v)) { best = list[i].name; break; }
        if (v && !best) best = list[i].name;
      }
      if (best) { if (changed === 'color') state.size = best; else state.color = best; }
    }

    function imageForState() {
      var c = hasColors ? find(data.colors, state.color) : null;
      var s = hasSizes ? find(data.sizes, state.size) : null;
      var cu = customChosen();
      if (state.last === 'custom' && cu && cu.image) return cu.image;
      if (state.last === 'size' && s && s.image) return s.image;
      if (c && c.image) return c.image;
      if (s && s.image) return s.image;
      return null;
    }

    function pick(a, b) { return a !== null && a !== undefined ? a : b; }

    function resolvedSpecs() {
      var s = hasSizes ? find(data.sizes, state.size) : null;
      return {
        weight: pick(s && s.weight, data.base.weight),
        h: pick(s && s.h, data.base.h), w: pick(s && s.w, data.base.w), d: pick(s && s.d, data.base.d)
      };
    }

    function flash(el) {
      if (!el) return;
      el.classList.remove('is-updated'); void el.offsetWidth; el.classList.add('is-updated');
    }

    function setText(el, text) {
      if (!el) return false;
      var changed = el.textContent.replace(/\s+/g, ' ').trim() !== text;
      el.textContent = text;
      return changed;
    }

    function setImage(src) {
      var img = els.mainImg;
      if (!img) return;
      var target = src || data.base.image;
      if (img.getAttribute('src') === target) return;
      img.classList.add('is-swapping');
      var pre = new root.Image();
      var done = function () { img.setAttribute('src', target); img.classList.remove('is-swapping'); };
      pre.onload = done; pre.onerror = done;
      pre.src = target;
      // Keep the gallery thumbnails in step with the big picture.
      doc.querySelectorAll('.gallery-thumbs img').forEach(function (t) {
        t.classList.toggle('active', (t.getAttribute('data-full') || t.getAttribute('src')) === target);
      });
    }

    function render() {
      var v = simple ? null : variantFor(hasColors ? state.color : null, hasSizes ? state.size : null);

      doc.querySelectorAll('.swatch, .chip').forEach(function (b) {
        var kind = b.getAttribute('data-kind'), val = b.getAttribute('data-value');
        var selected = state[kind] === val;
        b.classList.toggle('is-selected', selected);
        if (kind !== 'custom') b.classList.toggle('is-unavailable', !available(kind, val));
        b.setAttribute('aria-pressed', selected ? 'true' : 'false');
      });
      if (els.colorChosen) els.colorChosen.textContent = state.color || '';
      if (els.sizeChosen) els.sizeChosen.textContent = state.size || '';

      var cu = customChosen();
      if (els.customChosen) els.customChosen.textContent = cu ? cu.name : '';
      if (els.customField) els.customField.value = cu ? String(cu.id) : '';
      if (els.customNote) { els.customNote.textContent = cu && cu.note ? cu.note : ''; els.customNote.hidden = !(cu && cu.note); }

      // price = base + color extra + size extra + combination extra + customization extra
      var cOpt = hasColors ? find(data.colors, state.color) : null, sOpt = hasSizes ? find(data.sizes, state.size) : null;
      var price = data.basePrice + (cOpt && cOpt.delta || 0) + (sOpt && sOpt.delta || 0) + (v ? v.delta : 0) + (cu && cu.delta || 0);
      if (els.price) els.price.textContent = money(price);
      var stock = v ? v.stock : 0;
      var canOrder = inStock(v) || (v && data.preorder);
      if (!simple) {
      if (els.variantField) els.variantField.value = v ? String(v.id) : '';
      if (els.qty) {
        // With "Show stock" off the real number is never sent to the browser, so the server does the capping.
        var qtyCap = stock > 0 ? (data.hideStock ? 99 : stock) : ((data.preorder || data.backorder) ? 99 : 1);
        els.qty.max = String(Math.max(qtyCap, 1));
        if (parseInt(els.qty.value, 10) > qtyCap) els.qty.value = String(Math.max(qtyCap, 1));
      }
      if (els.addBtn) {
        els.addBtn.disabled = !v || !canOrder;
        els.addBtn.textContent = !v ? 'Select an option' : (stock > 0 ? 'Add to cart' : (data.preorder ? 'Pre-order' : (data.backorder ? 'Add to cart' : 'Out of stock')));
      }
      if (els.stockLine) {
        els.stockLine.innerHTML = stock > 10 ? '<span class="pill pill-sage">In stock</span>'
          : stock > 0 ? (data.hideStock ? '<span class="pill pill-sage">In stock</span>' : '<span class="pill pill-rust">Only ' + stock + ' left</span>')
          : data.preorder ? '<span class="pill pill-brass">Pre-order' + (data.preorderNote ? ' — ' + data.preorderNote : '') + '</span>'
          : data.backorder ? '<span class="pill pill-sage">Available to order</span>'
          : '<span class="pill pill-ink">Out of stock</span>';
      }
      }

      // dimensions + weight follow the chosen size
      var sp = resolvedSpecs();
      if (setText(els.weightVal, sp.weight + 'g')) flash(els.weightRow);
      if (els.dimsVal) {
        var f = function (n) { return n ? String(n) : '—'; };
        if (setText(els.dimsVal, f(sp.h) + ' × ' + f(sp.w) + ' × ' + f(sp.d) + ' mm')) flash(els.dimsRow);
      }

      setImage(imageForState());
    }

    function select(kind, value) {
      state[kind] = value;
      state.last = kind === 'custom' && value === '' ? null : kind;
      if (kind !== 'custom') fixPartner(kind);
      render();
    }

    function init() {
      // Start on the first combination that's actually in stock.
      var first = null;
      for (var i = 0; i < data.variants.length; i++) { if (hasStock(data.variants[i])) { first = data.variants[i]; break; } }
      first = first || data.variants[0] || null;
      if (first) { state.color = first.color; state.size = first.size; }
      doc.querySelectorAll('.swatch, .chip').forEach(function (b) {
        b.addEventListener('click', function () { select(b.getAttribute('data-kind'), b.getAttribute('data-value')); });
      });
      // The preview photo is only overridden after the shopper actually picks something.
      var savedLast = state.last; state.last = null;
      var c = hasColors ? find(data.colors, state.color) : null;
      render();
      state.last = savedLast;
      return c;
    }

    return { init: init, select: select, state: state, variantFor: variantFor, available: available, resolvedSpecs: resolvedSpecs, imageForState: imageForState };
  }

  root.StoreProduct = { create: create };
  if (typeof module !== 'undefined' && module.exports) module.exports = root.StoreProduct;

  if (root.STORE_PRODUCT && root.document) {
    var start = function () { root.StoreProduct.instance = create(root.STORE_PRODUCT); root.StoreProduct.instance.init(); };
    if (root.document.readyState === 'loading') root.document.addEventListener('DOMContentLoaded', start); else start();
  }
})(typeof window !== 'undefined' ? window : globalThis);
