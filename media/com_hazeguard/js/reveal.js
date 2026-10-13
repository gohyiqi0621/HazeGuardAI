/* Landing scroll-reveal. Progressive enhancement: content is only hidden once
   JS runs (it adds .js-reveal), so a no-JS visit or a failed script load still
   shows everything. Honours prefers-reduced-motion by revealing instantly. */
(function () {
  'use strict';
  var root = document.documentElement;
  root.classList.add('js-reveal');

  var items = Array.prototype.slice.call(document.querySelectorAll('[data-reveal]'));
  if (!items.length) return;

  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (reduce || !('IntersectionObserver' in window)) {
    items.forEach(function (el) { el.classList.add('is-visible'); });
    return;
  }

  var io = new IntersectionObserver(function (entries) {
    entries.forEach(function (entry) {
      if (entry.isIntersecting) {
        entry.target.classList.add('is-visible');
        io.unobserve(entry.target);
      }
    });
  }, { threshold: 0.12, rootMargin: '0px 0px -8% 0px' });

  items.forEach(function (el) { io.observe(el); });
})();
