/* Awesome Reviews — front-end behavior (auto scroll + arrows + Read more)
 * Safe against double-binding.
 */
(function () {
  'use strict';

  if (window.__ARW_VIEW_BOUND__) return;
  window.__ARW_VIEW_BOUND__ = true;

  function q(root, sel)  { return root.querySelector(sel); }
  function qa(root, sel) { return Array.prototype.slice.call(root.querySelectorAll(sel)); }
  function viewport(root) { return q(root, '.arw-viewport') || root; }
  function wrap(root)     { return q(viewport(root), '.arw-wrap'); }

  function gapPx(w) {
    if (!w) return 16;
    var cs = getComputedStyle(w);
    var v = cs.getPropertyValue('column-gap') || cs.getPropertyValue('gap') || '16';
    var n = parseFloat(v);
    return isFinite(n) ? n : 16;
  }

  function stepPx(root) {
    var w = wrap(root);
    if (!w) return 0;
    var first = w.children[0];
    var bw = first ? (first.getBoundingClientRect().width || first.offsetWidth || 0) : 0;
    if (!bw) bw = viewport(root).clientWidth * 0.9;
    return bw + gapPx(w);
  }

  function clampArrows(root) {
    var w = wrap(root); if (!w) return;
    var vp = viewport(root);
    var prev = q(vp, '.arw-nav--prev') || q(vp, '.arw-prev');
    var next = q(vp, '.arw-nav--next') || q(vp, '.arw-next');
    if (!prev || !next) return;
    var max = Math.max(0, w.scrollWidth - w.clientWidth - 1);
    prev.disabled = w.scrollLeft <= 0;
    next.disabled = w.scrollLeft >= max;
  }

  // Delegated clicks (Read more + arrows)
  document.addEventListener('click', function (e) {
    // Read more/less
    var more = e.target.closest && e.target.closest('.arw-more');
    if (more) {
      e.preventDefault();
      var card = more.closest('.arw-card');
      var p = card && q(card, '.arw-text');
      if (!p) return;
      var collapsed = p.getAttribute('data-collapsed') !== '0';
      p.setAttribute('data-collapsed', collapsed ? '0' : '1');
      var moreTxt = more.getAttribute('data-more') || 'Read more';
      var lessTxt = more.getAttribute('data-less') || 'Read less';
      more.textContent = collapsed ? lessTxt : moreTxt;
      return;
    }

    // Slider arrows (support both .arw-nav* and .arw-btn)
    var btn = e.target.closest && (e.target.closest('.arw-nav') || e.target.closest('.arw-btn'));
    if (btn) {
      var root = btn.closest('.arw.arw--layout-slider');
      if (!root) return;
      var w = wrap(root); if (!w) return;

      var isPrev = btn.classList.contains('arw-nav--prev') || btn.classList.contains('arw-prev');
      var dir = isPrev ? -1 : 1;
      var step = stepPx(root) || (w.clientWidth * 0.9);
      var target = w.scrollLeft + dir * step;

      var max = Math.max(0, w.scrollWidth - w.clientWidth);
      if (dir > 0 && target > max) target = 0;
      if (dir < 0 && target < 0)   target = max;

      w.scrollTo({ left: Math.round(target), behavior: 'smooth' });

      root.dataset.arwPauseUntil = String(Date.now() + 5000);
      setTimeout(function () { clampArrows(root); }, 250);
    }
  });

  function initSlider(root) {
    if (root.dataset.arwAutoInit === '1') return;
    root.dataset.arwAutoInit = '1';

    var w = wrap(root); if (!w) return;

    // Auto scroll every 2s (customizable via data-auto)
    var delay = parseInt(root.getAttribute('data-auto') || '2000', 10);
    if (!isFinite(delay) || delay < 500) delay = 2000;

    function paused() {
      var until = parseInt(root.dataset.arwPauseUntil || '0', 10) || 0;
      return Date.now() < until;
    }

    function next() {
      if (paused()) return;
      var s = stepPx(root) || (w.clientWidth * 0.9);
      var max = Math.max(0, w.scrollWidth - w.clientWidth);
      var t = w.scrollLeft + s;
      if (t > max) t = 0; // loop
      w.scrollTo({ left: Math.round(t), behavior: 'smooth' });
      setTimeout(function () { clampArrows(root); }, 250);
    }

    var timer = setInterval(next, delay);

    // Pause on hover/focus, then resume
    ['mouseenter', 'focusin'].forEach(function (ev) {
      root.addEventListener(ev, function () {
        root.dataset.arwPauseUntil = String(Date.now() + 5000);
      });
    });
    ['mouseleave', 'focusout'].forEach(function (ev) {
      root.addEventListener(ev, function () {
        root.dataset.arwPauseUntil = String(Date.now() + 1500);
      });
    });

    // Keep arrow state fresh
    var raf;
    w.addEventListener('scroll', function () {
      cancelAnimationFrame(raf);
      raf = requestAnimationFrame(function () {
        root.dataset.arwPauseUntil = String(Date.now() + 5000);
        clampArrows(root);
      });
    }, { passive: true });

    var resizeRaf;
    window.addEventListener('resize', function () {
      cancelAnimationFrame(resizeRaf);
      resizeRaf = requestAnimationFrame(function () {
        clampArrows(root);
      });
    }, { passive: true });

    document.addEventListener('visibilitychange', function () {
      if (document.hidden) root.dataset.arwPauseUntil = String(Date.now() + 60000);
    });

    clampArrows(root);
  }

  function boot() {
    qa(document, '.arw.arw--layout-slider').forEach(initSlider);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }

  document.addEventListener('wp-navigate', boot);
})();
