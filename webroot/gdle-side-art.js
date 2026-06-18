/* gdle-side-art.js — Guitardle app-icon art under the side cards on /archive-poc/.
   Buck-lane stopgap for canonical commit 903addb (buck/guitardle-polish, merge
   pending with the coordinator): the merged page ships its own .gdle-side-art
   inside the row markup, so this layer bails when it finds one already there. */
(function () {
  'use strict';
  if (window.__lgGdleSideArt) return;
  window.__lgGdleSideArt = true;

  function go() {
    var side = document.querySelector('.row--guitardle .gdle-side');
    if (!side || side.querySelector('.gdle-side-art')) return;

    var css = document.createElement('style');
    css.id = 'lg-gdle-side-art-css';
    css.textContent =
      '.gdle-side-art{display:block;width:min(310px,78%);height:auto;margin:14px auto 0;' +
      'filter:drop-shadow(0 10px 26px rgba(26,32,23,.25))}' +
      '@media (max-width:900px){.gdle-side-art{display:none}}';
    document.head.appendChild(css);

    var img = document.createElement('img');
    img.className = 'gdle-side-art';
    img.src = '/guitardle-icon-512.webp?v=1';
    img.alt = '';
    img.setAttribute('aria-hidden', 'true');
    img.loading = 'lazy';
    img.width = 512;
    img.height = 512;
    side.appendChild(img);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', go);
  } else {
    go();
  }
})();
