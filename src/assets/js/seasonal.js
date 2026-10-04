(function () {
  'use strict';

  /**
   * Seasonal screen animation (snow / leaves / rain). The admin decides
   * whether it exists at all; each visitor can switch it on/off with the
   * header button (remembered in localStorage). Visitors who asked their OS
   * for reduced motion start with it off, but can still turn it on.
   */
  var currentScript = document.currentScript;
  var effect = (currentScript && currentScript.getAttribute('data-effect')) || 'snow';
  if (effect === 'none') return;

  var KEY = 'kafeel-fx';
  var btns = document.querySelectorAll('[data-fx-toggle]');
  var reduceMotion = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

  function saved() { try { return localStorage.getItem(KEY); } catch (e) { return null; } }
  function save(v) { try { localStorage.setItem(KEY, v); } catch (e) {} }
  function wantsOn() {
    var v = saved();
    if (v === 'on') return true;
    if (v === 'off') return false;
    return !reduceMotion;
  }
  function isDark() { return document.documentElement.getAttribute('data-theme') === 'dark'; }

  var canvas = null, ctx = null, W = 0, H = 0, particles = [], raf = 0, running = false;

  function rand(min, max) { return Math.random() * (max - min) + min; }

  function makeParticle(fromTop) {
    var p;
    if (effect === 'rain') {
      p = { x: rand(0, W), y: rand(-H, 0), len: rand(10, 22), speed: rand(6, 11), drift: rand(-0.5, 0.5), opacity: rand(0.25, 0.5) };
    } else if (effect === 'leaves') {
      p = { x: rand(0, W), y: rand(-H, 0), size: rand(6, 11), speed: rand(0.5, 1.4), drift: rand(-0.8, 0.8), angle: rand(0, Math.PI * 2), spin: rand(-0.02, 0.02), hue: rand(20, 42), opacity: rand(0.5, 0.85) };
    } else {
      p = { x: rand(0, W), y: rand(-H, 0), size: rand(1.8, 4.2), speed: rand(0.4, 1.2), drift: rand(-0.4, 0.4), opacity: rand(0.55, 0.9) };
    }
    if (fromTop) p.y = -20;
    return p;
  }

  function resize() {
    if (!canvas) return;
    W = canvas.width = window.innerWidth;
    H = canvas.height = window.innerHeight;
  }

  function drawSnow(p) {
    ctx.beginPath();
    ctx.arc(p.x, p.y, p.size, 0, Math.PI * 2);
    // White flakes vanish on a light page, so day mode uses a cool blue-grey.
    ctx.fillStyle = (isDark() ? 'rgba(255,255,255,' : 'rgba(120,150,195,') + p.opacity + ')';
    ctx.fill();
    p.y += p.speed; p.x += p.drift;
  }

  function drawLeaf(p) {
    ctx.save();
    ctx.translate(p.x, p.y);
    ctx.rotate(p.angle);
    ctx.fillStyle = 'hsla(' + p.hue + ', 55%, ' + (isDark() ? 52 : 42) + '%, ' + p.opacity + ')';
    ctx.beginPath();
    ctx.ellipse(0, 0, p.size, p.size * 0.6, 0, 0, Math.PI * 2);
    ctx.fill();
    ctx.restore();
    p.y += p.speed;
    p.x += p.drift + Math.sin(p.y / 40) * 0.6;
    p.angle += p.spin;
  }

  function drawRain(p) {
    ctx.beginPath();
    ctx.moveTo(p.x, p.y);
    ctx.lineTo(p.x + p.drift * 2, p.y + p.len);
    ctx.strokeStyle = (isDark() ? 'rgba(180,200,225,' : 'rgba(70,95,135,') + p.opacity + ')';
    ctx.lineWidth = 1;
    ctx.stroke();
    p.y += p.speed * 4; p.x += p.drift;
  }

  var draw = effect === 'rain' ? drawRain : (effect === 'leaves' ? drawLeaf : drawSnow);

  function tick() {
    if (!running) return;
    ctx.clearRect(0, 0, W, H);
    for (var i = 0; i < particles.length; i++) {
      draw(particles[i]);
      if (particles[i].y > H + 20) particles[i] = makeParticle(true);
    }
    raf = requestAnimationFrame(tick);
  }

  function start() {
    if (running) return;
    canvas = document.createElement('canvas');
    canvas.setAttribute('aria-hidden', 'true');
    canvas.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;pointer-events:none;z-index:9999;';
    document.body.appendChild(canvas);
    ctx = canvas.getContext('2d');
    resize();
    var count = W < 640 ? 26 : 55; // subtle, not a blizzard
    particles = [];
    for (var i = 0; i < count; i++) particles.push(makeParticle(false));
    running = true;
    raf = requestAnimationFrame(tick);
  }

  function stop() {
    running = false;
    cancelAnimationFrame(raf);
    if (canvas && canvas.parentNode) canvas.parentNode.removeChild(canvas);
    canvas = ctx = null;
  }

  function syncButton() {
    var on = running;
    var label = on ? 'Turn screen animation off' : 'Turn screen animation on';
    btns.forEach(function (btn) {
      btn.setAttribute('aria-pressed', on ? 'true' : 'false');
      btn.setAttribute('aria-label', label);
      btn.setAttribute('title', label);
    });
  }

  window.addEventListener('resize', resize);
  document.addEventListener('visibilitychange', function () {
    // Don't burn battery animating a hidden tab.
    if (document.hidden) { if (running) { running = false; cancelAnimationFrame(raf); } }
    else if (canvas && !running) { running = true; raf = requestAnimationFrame(tick); }
  });

  btns.forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (running) { stop(); save('off'); } else { start(); save('on'); }
      syncButton();
    });
  });

  if (wantsOn()) start();
  syncButton();
})();
