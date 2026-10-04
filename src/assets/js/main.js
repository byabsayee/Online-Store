(function () {
  'use strict';

  /* ---------------------------------------------------------- toast --- */
  function toast(message) {
    var box = document.getElementById('toast');
    if (!box) return;
    var item = document.createElement('div');
    item.className = 'toast-item';
    item.textContent = message;
    box.appendChild(item);
    setTimeout(function () { item.remove(); }, 3200);
  }
  window.showToast = toast;

  /* --------------------------------------------- mobile drawer + search --- */
  var root = document.documentElement;
  var navToggle = document.getElementById('navToggle');
  var mobileNav = document.getElementById('mobileNav');
  var header = document.getElementById('siteHeader');
  var searchToggle = document.getElementById('searchToggle');
  var lastFocus = null;

  function focusables(container) {
    return Array.prototype.slice.call(container.querySelectorAll('a[href], button:not([disabled]), input, select, textarea, [tabindex]:not([tabindex="-1"])'))
      .filter(function (el) { return el.offsetParent !== null; });
  }
  function openNav() {
    if (!mobileNav) return;
    lastFocus = document.activeElement;
    mobileNav.classList.add('open');
    mobileNav.setAttribute('aria-hidden', 'false');
    if (navToggle) navToggle.setAttribute('aria-expanded', 'true');
    root.classList.add('nav-locked');
    setTimeout(function () { var c = mobileNav.querySelector('.mnav-close'); if (c) c.focus(); }, 60);
  }
  function closeNav(restore) {
    if (!mobileNav || !mobileNav.classList.contains('open')) return;
    mobileNav.classList.remove('open');
    mobileNav.setAttribute('aria-hidden', 'true');
    if (navToggle) navToggle.setAttribute('aria-expanded', 'false');
    root.classList.remove('nav-locked');
    if (restore !== false && lastFocus && lastFocus.focus) lastFocus.focus();
  }
  if (navToggle) navToggle.addEventListener('click', openNav);
  if (mobileNav) {
    mobileNav.querySelectorAll('[data-nav-close]').forEach(function (el) { el.addEventListener('click', function () { closeNav(); }); });
    // Following a link inside the drawer: close it so the page doesn't flash open on Back.
    mobileNav.querySelectorAll('a[href]').forEach(function (a) { a.addEventListener('click', function () { closeNav(false); }); });
    // Keep Tab inside the open drawer.
    mobileNav.addEventListener('keydown', function (e) {
      if (e.key !== 'Tab') return;
      var f = focusables(mobileNav.querySelector('.mnav-panel'));
      if (!f.length) return;
      var first = f[0], last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });
    // Swipe the panel left to close.
    var panel = mobileNav.querySelector('.mnav-panel'), sx = null, sy = null;
    panel.addEventListener('touchstart', function (e) { sx = e.touches[0].clientX; sy = e.touches[0].clientY; }, { passive: true });
    panel.addEventListener('touchend', function (e) {
      if (sx === null) return;
      var dx = e.changedTouches[0].clientX - sx, dy = Math.abs(e.changedTouches[0].clientY - sy);
      if (dx < -70 && dy < 60) closeNav();
      sx = null;
    }, { passive: true });
  }
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeNav(); });
  // If the window grows past the phone layout with the drawer open, tidy up.
  window.addEventListener('resize', function () { if (window.innerWidth > 860) closeNav(false); });

  /* Header search: on phones the field is tucked away until the magnifier is tapped. */
  function setSearchOpen(open) {
    if (!header) return;
    header.classList.toggle('search-open', open);
    if (searchToggle) searchToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) {
      var inp = header.querySelector('.search-form input[name=q]');
      if (inp) { window.scrollTo({ top: 0, behavior: 'smooth' }); setTimeout(function () { inp.focus(); }, 80); }
    }
  }
  if (searchToggle) searchToggle.addEventListener('click', function () { setSearchOpen(!header.classList.contains('search-open')); });
  document.querySelectorAll('[data-open-search]').forEach(function (b) { b.addEventListener('click', function () { setSearchOpen(true); }); });
  // Landing on a results page? keep the field open so the query is visible.
  if (header && window.innerWidth <= 860 && /[?&]q=./.test(location.search)) header.classList.add('search-open');

  /* ------------------------------------------------------- csrf token -- */
  function getCsrf() {
    var el = document.querySelector('meta[name="csrf-token"]');
    return el ? el.getAttribute('content') : '';
  }

  function postJSON(url, data) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrf() },
      body: JSON.stringify(data)
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, message: 'Something went wrong — please refresh the page.' }; });
    });
  }

  /* ---------------------------------------------------- cart badge ----- */
  function updateCartBadge(count) {
    document.querySelectorAll('[data-cart-badge]').forEach(function (b) {
      b.textContent = count;
      b.hidden = !(count > 0);
    });
  }

  /* ------------------------------------------------ add-to-cart forms -- */
  document.querySelectorAll('.js-add-cart').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = form.querySelector('button[type=submit]');
      var productId = form.querySelector('[name=product_id]').value;
      var variantField = form.querySelector('[name=variant_id]');
      var variantId = variantField ? variantField.value : '';
      var qtyField = form.querySelector('[name=quantity]');
      var qty = qtyField ? parseInt(qtyField.value, 10) || 1 : 1;
      if (btn) { btn.disabled = true; }
      postJSON('/api/cart_add.php', { product_id: productId, variant_id: variantId || null, quantity: qty })
        .then(function (res) {
          if (res.ok) {
            updateCartBadge(res.cart_count);
            toast(res.message || 'Added to cart');
          } else {
            toast(res.message || 'Could not add to cart');
            if (res.login_required) { window.location.href = '/login'; }
          }
        })
        .catch(function () { toast('Network error — please try again'); })
        .finally(function () { if (btn) { btn.disabled = false; } });
    });
  });

  /* -------------------------------------------------- favorite toggle -- */
  function updateWishCount(productId, count) {
    document.querySelectorAll('[data-wish-count="' + productId + '"]').forEach(function (el) {
      var n = parseInt(count, 10) || 0;
      var num = el.querySelector('[data-wish-num]');
      if (num) num.textContent = n;
      var word = el.querySelector('[data-wish-word]');
      if (word) word.textContent = n === 1 ? 'person has' : 'people have';
      el.hidden = n < 1;
    });
  }
  document.querySelectorAll('.js-fav-toggle').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      var productId = btn.getAttribute('data-product-id');
      postJSON('/api/favorite_toggle.php', { product_id: productId })
        .then(function (res) {
          if (res.ok) {
            btn.classList.toggle('active', res.favorited);
            var svg = btn.querySelector('svg');
            if (svg) svg.setAttribute('fill', res.favorited ? 'currentColor' : 'none');
            // Product-page button also carries a text label; card hearts are icon-only.
            var label = btn.querySelector('.fav-label');
            if (label) label.textContent = res.favorited ? 'Saved' : (btn.getAttribute('data-off-label') || 'Save for later');
            if (typeof res.wish_count !== 'undefined') updateWishCount(productId, res.wish_count);
            toast(res.favorited ? 'Saved to your wishlist' : 'Removed from wishlist');
          } else if (res.login_required) {
            window.location.href = '/login';
          } else {
            toast(res.message || 'Something went wrong');
          }
        })
        .catch(function () { toast('Network error — please try again'); });
    });
  });

  /* --------------------------------------------------------- qty steps -- */
  document.querySelectorAll('.qty-stepper').forEach(function (stepper) {
    var input = stepper.querySelector('input');
    stepper.querySelectorAll('button').forEach(function (btn) {
      btn.addEventListener('click', function () {
        // Read limits now, not at page load: the product page changes max when a variant is picked.
        var min = parseInt(input.getAttribute('min') || '1', 10);
        var max = parseInt(input.getAttribute('max') || '999', 10);
        var val = parseInt(input.value, 10) || min;
        val = btn.classList.contains('minus') ? val - 1 : val + 1;
        val = Math.max(min, Math.min(max, val));
        input.value = val;
        input.dispatchEvent(new Event('change', { bubbles: true }));
      });
    });
  });

  /* ------------------------------------------------- cart page updates -- */
  document.querySelectorAll('.js-cart-qty').forEach(function (input) {
    input.addEventListener('change', function () {
      var itemId = input.getAttribute('data-item-id');
      postJSON('/api/cart_update.php', { item_id: itemId, quantity: input.value })
        .then(function (res) {
          if (res.ok) { window.location.reload(); } else { toast(res.message || 'Could not update the cart'); }
        })
        .catch(function () { toast('Network error — please try again'); });
    });
  });
  document.querySelectorAll('.js-cart-remove').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      var itemId = btn.getAttribute('data-item-id');
      postJSON('/api/cart_remove.php', { item_id: itemId }).then(function (res) {
        if (res.ok) { window.location.reload(); } else { toast(res.message || 'Could not remove that item'); }
      }).catch(function () { toast('Network error — please try again'); });
    });
  });

  /* -------------------------------------------------------- gallery ---- */
  document.querySelectorAll('.gallery-thumbs img').forEach(function (thumb) {
    thumb.addEventListener('click', function () {
      var main = document.querySelector('.gallery-main img');
      if (!main) return;
      main.src = thumb.getAttribute('data-full') || thumb.src;
      document.querySelectorAll('.gallery-thumbs img').forEach(function (t) { t.classList.remove('active'); });
      thumb.classList.add('active');
    });
  });

  /* ------------------------------------------------------ day / night --- */
  var themeBtns = document.querySelectorAll('[data-theme-toggle]');
  var themeMeta = document.querySelector('meta[name="theme-color"]');
  function currentTheme() { return root.getAttribute('data-theme') === 'dark' ? 'dark' : 'light'; }
  function syncThemeBtn() {
    var dark = currentTheme() === 'dark';
    var label = dark ? 'Switch to light mode' : 'Switch to dark mode';
    themeBtns.forEach(function (btn) {
      btn.setAttribute('aria-label', label);
      btn.setAttribute('title', label);
      btn.setAttribute('aria-pressed', dark ? 'true' : 'false');
      var t = btn.querySelector('[data-theme-label]');
      if (t) t.textContent = dark ? 'Dark mode on' : 'Dark mode';
    });
    if (themeMeta) themeMeta.setAttribute('content', dark ? '#1a2030' : '#f8f6ee');
  }
  function setTheme(t, remember) {
    root.classList.add('theme-fade');
    root.setAttribute('data-theme', t);
    if (remember) { try { localStorage.setItem('kafeel-theme', t); } catch (e) {} }
    syncThemeBtn();
    setTimeout(function () { root.classList.remove('theme-fade'); }, 350);
  }
  themeBtns.forEach(function (btn) {
    btn.addEventListener('click', function () { setTheme(currentTheme() === 'dark' ? 'light' : 'dark', true); });
  });
  syncThemeBtn();
  // Until the visitor picks a side, follow the operating system live.
  if (window.matchMedia) {
    var mq = window.matchMedia('(prefers-color-scheme: dark)');
    var follow = function (e) {
      var stored = null;
      try { stored = localStorage.getItem('kafeel-theme'); } catch (err) {}
      if (stored !== 'light' && stored !== 'dark') setTheme(e.matches ? 'dark' : 'light', false);
    };
    if (mq.addEventListener) mq.addEventListener('change', follow); else if (mq.addListener) mq.addListener(follow);
  }

  /* ---------------------------------------- product page: mobile extras -- */
  var buyBar = document.getElementById('buyBar');
  var mainForm = document.getElementById('addCartForm');
  if (buyBar && mainForm) {
    var bbBtn = document.getElementById('buyBarBtn'), bbPrice = document.getElementById('buyBarPrice');
    var mainBtn = document.getElementById('addCartBtn'), mainPrice = document.getElementById('productPrice');
    var syncBar = function () {
      if (mainPrice && bbPrice) bbPrice.textContent = mainPrice.textContent.trim();
      if (mainBtn && bbBtn) { bbBtn.disabled = mainBtn.disabled; bbBtn.textContent = mainBtn.textContent.trim(); }
    };
    syncBar();
    if (window.MutationObserver) {
      var mo = new MutationObserver(syncBar);
      if (mainPrice) mo.observe(mainPrice, { childList: true, characterData: true, subtree: true });
      if (mainBtn) mo.observe(mainBtn, { attributes: true, childList: true, characterData: true, subtree: true });
    }
    bbBtn.addEventListener('click', function () {
      mainForm.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
    });
    // Show the bar whenever the real button isn't on screen.
    var anchor = mainForm.querySelector('.product-actions');
    if (anchor && 'IntersectionObserver' in window) {
      new IntersectionObserver(function (en) { buyBar.classList.toggle('show', !en[0].isIntersecting); }, { threshold: 0 }).observe(anchor);
    } else { buyBar.classList.add('show'); }
  }

  /* Gallery: swipe between photos and show "2 / 5". */
  var galMain = document.querySelector('.gallery-main');
  var galThumbs = Array.prototype.slice.call(document.querySelectorAll('.gallery-thumbs img'));
  if (galMain && galThumbs.length > 1) {
    var counter = document.createElement('span');
    counter.className = 'gallery-count';
    galMain.appendChild(counter);
    var galIndex = function () { var i = galThumbs.findIndex(function (t) { return t.classList.contains('active'); }); return i < 0 ? 0 : i; };
    var galSync = function () { counter.textContent = (galIndex() + 1) + ' / ' + galThumbs.length; };
    galThumbs.forEach(function (t) { t.addEventListener('click', function () { setTimeout(galSync, 0); }); });
    var gx = null, gy = null;
    galMain.addEventListener('touchstart', function (e) { gx = e.touches[0].clientX; gy = e.touches[0].clientY; }, { passive: true });
    galMain.addEventListener('touchend', function (e) {
      if (gx === null) return;
      var dx = e.changedTouches[0].clientX - gx, dy = Math.abs(e.changedTouches[0].clientY - gy);
      gx = null;
      if (Math.abs(dx) < 45 || dy > 60) return;
      var n = (galIndex() + (dx < 0 ? 1 : -1) + galThumbs.length) % galThumbs.length;
      galThumbs[n].click();
      galThumbs[n].scrollIntoView({ block: 'nearest', inline: 'center', behavior: 'smooth' });
    }, { passive: true });
    galSync();
  }

  /* ------------------------------------------------------- coupon box --- */
  var couponBox = document.getElementById('couponBox');
  if (couponBox) {
    var cForm = document.getElementById('couponForm'), cInput = document.getElementById('couponCode');
    var cApplyBtn = document.getElementById('couponApply'), cApplied = document.getElementById('couponApplied');
    var cCode = document.getElementById('couponAppliedCode'), cDesc = document.getElementById('couponAppliedDesc');
    var cMsg = document.getElementById('couponMsg'), cRemove = document.getElementById('couponRemove');
    var cMsgSet = function (text, kind) { cMsg.textContent = text || ''; cMsg.className = 'coupon-msg' + (text ? ' ' + kind : ''); };
    var cChanged = function (discount, code) {
      document.dispatchEvent(new CustomEvent('coupon:changed', { detail: { discount: discount, code: code } }));
    };
    cInput.addEventListener('input', function () { cInput.value = cInput.value.toUpperCase(); if (cMsg.textContent) cMsgSet(''); });
    cForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var code = cInput.value.trim();
      if (!code) { cMsgSet('Please enter a coupon code.', 'err'); cInput.focus(); return; }
      cApplyBtn.disabled = true;
      postJSON('/api/coupon_apply.php', { code: code })
        .then(function (res) {
          if (res.ok) {
            cCode.textContent = res.code; cDesc.textContent = res.description || '';
            cForm.hidden = true; cApplied.hidden = false; cInput.value = '';
            cMsgSet('');
            cChanged(res.discount, res.code);
          } else { cMsgSet(res.message || 'That coupon could not be applied.', 'err'); cInput.select(); }
        })
        .catch(function () { cMsgSet('Network error — please try again.', 'err'); })
        .finally(function () { cApplyBtn.disabled = false; });
    });
    cRemove.addEventListener('click', function () {
      cRemove.disabled = true;
      postJSON('/api/coupon_apply.php', { remove: true })
        .then(function (res) {
          if (res.ok) {
            cApplied.hidden = true; cForm.hidden = false; cMsgSet('Coupon removed.', 'ok');
            cChanged(0, '');
            cInput.focus();
          } else { cMsgSet(res.message || 'Could not remove the coupon.', 'err'); }
        })
        .catch(function () { cMsgSet('Network error — please try again.', 'err'); })
        .finally(function () { cRemove.disabled = false; });
    });
  }

  /* ---------------- account tabs: bring the current one into view (phones) --- */
  var acctActive = document.querySelector('.account-nav a.active');
  if (acctActive && acctActive.scrollIntoView) {
    var acctNav = acctActive.parentNode;
    if (acctNav.scrollWidth > acctNav.clientWidth) {
      acctNav.scrollLeft = Math.max(0, acctActive.offsetLeft - (acctNav.clientWidth - acctActive.offsetWidth) / 2);
    }
  }

  /* ------------------------------------------------- search suggestions -- */
  var searchForm = document.querySelector('form[data-suggest]');
  if (searchForm) {
    var sInput = searchForm.querySelector('input[name=q]');
    var sBox = document.getElementById('searchSuggest');
    var sTimer = null, sReq = 0, sActive = -1;

    var hideSuggest = function () { sBox.hidden = true; sBox.innerHTML = ''; sActive = -1; };

    var highlight = function (text, words) {
      var frag = document.createDocumentFragment();
      var lower = text.toLowerCase();
      var marks = [];
      words.forEach(function (w) {
        var i = lower.indexOf(w.toLowerCase());
        if (w && i > -1) marks.push([i, i + w.length]);
      });
      marks.sort(function (a, b) { return a[0] - b[0]; });
      var pos = 0;
      marks.forEach(function (m) {
        if (m[0] < pos) return;
        frag.appendChild(document.createTextNode(text.slice(pos, m[0])));
        var el = document.createElement('mark'); el.textContent = text.slice(m[0], m[1]); frag.appendChild(el);
        pos = m[1];
      });
      frag.appendChild(document.createTextNode(text.slice(pos)));
      return frag;
    };

    var renderSuggest = function (items, q) {
      sBox.innerHTML = '';
      if (!items.length) { hideSuggest(); return; }
      var words = q.split(/\s+/).filter(Boolean);
      items.forEach(function (it) {
        var a = document.createElement('a');
        a.href = '/product/' + encodeURIComponent(it.slug);
        var img = document.createElement('img'); img.src = it.image; img.alt = ''; img.loading = 'lazy';
        var wrap = document.createElement('div');
        var name = document.createElement('div'); name.className = 's-name'; name.appendChild(highlight(it.name, words));
        var meta = document.createElement('div'); meta.className = 's-meta'; meta.textContent = it.price + (it.category ? ' · ' + it.category : '');
        wrap.appendChild(name); wrap.appendChild(meta);
        a.appendChild(img); a.appendChild(wrap);
        sBox.appendChild(a);
      });
      var all = document.createElement('a');
      all.href = '/search?q=' + encodeURIComponent(q); all.className = 's-all';
      all.textContent = 'See all results for “' + q + '”';
      sBox.appendChild(all);
      sBox.hidden = false; sActive = -1;
    };

    sInput.addEventListener('input', function () {
      clearTimeout(sTimer);
      var q = sInput.value.trim();
      if (q.length < 2) { hideSuggest(); return; }
      sTimer = setTimeout(function () {
        var req = ++sReq;
        fetch('/api/search_suggest.php?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
          .then(function (r) { return r.json(); })
          .then(function (res) { if (req === sReq) renderSuggest(res.items || [], q); })
          .catch(function () { hideSuggest(); });
      }, 180);
    });

    sInput.addEventListener('keydown', function (e) {
      var links = sBox.hidden ? [] : Array.prototype.slice.call(sBox.querySelectorAll('a'));
      if (e.key === 'Escape') { hideSuggest(); return; }
      if (!links.length) return;
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        sActive = (sActive + (e.key === 'ArrowDown' ? 1 : -1) + links.length) % links.length;
        links.forEach(function (l, i) { l.classList.toggle('is-active', i === sActive); });
      } else if (e.key === 'Enter' && sActive > -1) {
        e.preventDefault();
        window.location.href = links[sActive].href;
      }
    });

    document.addEventListener('click', function (e) { if (!searchForm.contains(e.target)) hideSuggest(); });
    sInput.addEventListener('focus', function () { if (sBox.children.length) sBox.hidden = false; });
  }
})();
