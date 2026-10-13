/* ==========================================================================
   HazeGuard AI — landing + shell enhancements
   Replaces reveal.js. Pure progressive enhancement: with JS off, or with
   reduced motion on, every section is simply visible.

   What it does
     1. starts the hero sequence once the splash has gone
     2. reveals sections and tiles once, as they scroll into view
     3. lights the statement sentence word by word as it scrolls
     4. switches the nav from dark to light glass and fades the hero on scroll
     5. reads the AQI that airquality.js writes, sets data-band (colour) and
        counts the number up. It never writes data, it only observes it
     6. pauses the background video when it is not on screen
     7. keeps the browser theme colour and today's date correct

   Safe to load after app.js and airquality.js. It touches no state of theirs.
   ========================================================================== */
(function () {
  'use strict';

  var root = document.documentElement;
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)');
  var dark = window.matchMedia('(prefers-color-scheme: dark)');

  function $(sel, ctx) { return (ctx || document).querySelector(sel); }
  function $$(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }
  function clamp(n, lo, hi) { return Math.min(hi, Math.max(lo, n)); }
  function onChange(mq, fn) { if (mq.addEventListener) mq.addEventListener('change', fn); else if (mq.addListener) mq.addListener(fn); }

  /* ---- 1. hero sequence: wait for the splash to start leaving ------------ */
  (function ready() {
    var splash = document.getElementById('splash');
    var go = function () { root.classList.add('is-ready'); };
    if (!splash) { go(); return; }
    var done = false;
    var mo = new MutationObserver(check);
    function check() {
      if (done) return;
      if (!splash.isConnected || splash.classList.contains('is-leaving')) {
        done = true; mo.disconnect(); go();
      }
    }
    mo.observe(splash, { attributes: true, attributeFilter: ['class'] });
    mo.observe(document.body, { childList: true });
    setTimeout(function () { if (!done) { done = true; mo.disconnect(); go(); } }, 2400);
    check();
  })();

  /* ---- 2. reveal once, on scroll ---------------------------------------- */
  (function reveal() {
    var items = $$('[data-in]');
    if (!items.length) return;
    if (!('IntersectionObserver' in window)) {
      items.forEach(function (el) { el.classList.add('is-in'); });
      return;
    }
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (!en.isIntersecting) return;
        en.target.classList.add('is-in');
        io.unobserve(en.target);
      });
    }, { threshold: 0.16, rootMargin: '0px 0px -6% 0px' });
    items.forEach(function (el) { io.observe(el); });
  })();

  /* ---- 3. statement: split into words ----------------------------------- */
  var words = [];
  var statement = $('[data-words]');
  if (statement) {
    var text = statement.textContent.trim().split(/\s+/);
    statement.textContent = '';
    text.forEach(function (w, i) {
      var s = document.createElement('span');
      s.className = 'w';
      s.textContent = w;
      statement.appendChild(s);
      words.push(s);
      if (i < text.length - 1) statement.appendChild(document.createTextNode(' '));
    });
  }

  /* ---- 4. scroll: nav theme, hero fade, statement light ------------------ */
  var hero = $('.hero');
  var topbar = $('#topbar');
  var landing = document.getElementById('screen-landing');
  var ticking = false;
  var lit = -1;

  function update() {
    ticking = false;
    if (!landing || landing.hidden) return;
    var vh = window.innerHeight || 800;

    if (hero) {
      var hb = hero.getBoundingClientRect().bottom;
      if (topbar) topbar.classList.toggle('is-light', hb < 64);
      if (!reduce.matches) {
        var p = clamp(window.scrollY / (hero.offsetHeight * 0.75), 0, 1);
        hero.style.setProperty('--hero-p', p.toFixed(3));
        root.style.setProperty('--video-scale', (1.02 + p * 0.07).toFixed(3));
      }
    }

    if (words.length) {
      var n;
      if (reduce.matches) {
        n = words.length;
      } else {
        var r = statement.getBoundingClientRect();
        var prog = clamp((vh * 0.82 - r.top) / (vh * 0.5 + r.height * 0.35), 0, 1);
        n = Math.round(prog * words.length);
      }
      if (n !== lit) {
        for (var i = 0; i < words.length; i++) words[i].classList.toggle('is-lit', i < n);
        lit = n;
      }
    }
  }
  function schedule() { if (!ticking) { ticking = true; requestAnimationFrame(update); } }
  window.addEventListener('scroll', schedule, { passive: true });
  window.addEventListener('resize', schedule);
  window.addEventListener('hashchange', schedule);
  update();

  // The brand link on the landing page should return to the top, not just set a hash.
  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('[data-go="landing"]');
    if (a && document.body.dataset.screen === 'landing') {
      window.scrollTo({ top: 0, behavior: reduce.matches ? 'auto' : 'smooth' });
    }
  });

  /* ---- 5. AQI: colour band + count-up ------------------------------------ */
  function bandOf(n) { return n <= 50 ? 1 : n <= 100 ? 2 : n <= 150 ? 3 : n <= 200 ? 4 : 5; }

  function watch(el) {
    var host = el.closest('.instrument, .aqi-hero');
    var shown = null;
    var raf = 0;

    function apply() {
      var t = el.textContent.trim();
      if (t === shown) return;                 // our own write
      var n = parseInt(t, 10);
      if (!isFinite(n) || String(n) !== t) {   // "—", "Checking…", empty: no reading
        if (host) host.removeAttribute('data-band');
        return;
      }
      if (host) host.setAttribute('data-band', String(bandOf(n)));
      if (reduce.matches) return;

      cancelAnimationFrame(raf);
      var t0 = performance.now();
      var dur = 1200;
      (function tick(now) {
        var k = clamp((now - t0) / dur, 0, 1);
        var eased = 1 - Math.pow(1 - k, 4);
        shown = String(Math.round(n * eased));
        el.textContent = shown;
        if (k < 1) raf = requestAnimationFrame(tick); else shown = String(n);
      })(t0);
    }

    new MutationObserver(apply).observe(el, { childList: true, characterData: true, subtree: true });
    apply();
  }
  $$('[data-aqi-value]').forEach(watch);

  /* ---- 6. background video: only play when it can be seen ---------------- */
  var video = $('.bg-video');
  var conn = navigator.connection || {};
  if (video && conn.saveData) {
    // Data Saver on: skip the download, the painted frame behind it takes over.
    video.pause();
    video.removeAttribute('src');
    video.load();
    video = null;
  }
  function syncVideo() {
    if (!video) return;
    var want = document.body.dataset.kind !== 'app' && !document.hidden && !reduce.matches;
    if (want) { var p = video.play(); if (p && p.catch) p.catch(function () {}); }
    else video.pause();
  }
  new MutationObserver(syncVideo).observe(document.body, { attributes: true, attributeFilter: ['data-kind'] });
  document.addEventListener('visibilitychange', syncVideo);
  onChange(reduce, function () { syncVideo(); schedule(); });
  syncVideo();

  /* ---- 7. browser chrome colour + today's date --------------------------- */
  var themeMeta = $('meta[name="theme-color"]');
  function syncTheme() {
    if (!themeMeta) return;
    var app = document.body.dataset.kind === 'app';
    themeMeta.setAttribute('content', app && !dark.matches ? '#f5f5f7' : '#000000');
  }
  new MutationObserver(syncTheme).observe(document.body, { attributes: true, attributeFilter: ['data-kind'] });
  onChange(dark, syncTheme);
  syncTheme();

  var now = new Date();
  var long = now.toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
  $$('[data-today]').forEach(function (el) { el.textContent = long; });
  var planDate = $('#plan-date');
  if (planDate) {
    var iso = new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    if (!planDate.value || planDate.value < iso) planDate.value = iso;
  }
})();
