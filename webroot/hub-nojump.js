/* hub-nojump.js — Looth PWA (all widths; /hub only)
 *
 * Scroll-jump mitigation (Buck 2026-06-11: "as I scroll the hub things jump
 * around"). Root cause: canonical _feed.php emits .feed-card__cover-img with
 * NO width/height attributes and lazy loading, so a card renders ~0-tall for
 * the cover and then grows by the FULL image height (200-500px+) when the
 * photo arrives — shoving everything below it, and re-balancing the desktop
 * masonry columns. Native scroll anchoring can't cover the worst cases (the
 * card you're reading growing under you; column re-distribution).
 *
 * This layer gives every not-yet-loaded cover a placeholder height, so the
 * post-load correction is a small +/- delta instead of a 0-to-full-height
 * blowout. The REAL fix is server-side — emit width/height attrs in
 * _feed.php (fork, coordinator lane; requested via msg 2026-06-11) — after
 * which this layer's placeholders simply never differ much from truth.
 */
(function () {
  'use strict';
  if (window.__lgNoJump) return;
  window.__lgNoJump = true;
  if (!/^\/hub(\/|$)/.test(location.pathname)) return;

  var PH = 280; // typical mobile cover height; desktop columns are narrower

  function ensureCss() {
    if (document.getElementById('lg-nojump-css')) return;
    var st = document.createElement('style');
    st.id = 'lg-nojump-css';
    st.textContent =
      '.feed-card__cover-img[data-lg-ph]{min-height:' + PH + 'px;' +
        'background:var(--lguser-pill,var(--lg-sage-tint,#eef2e3))}' +
      '@media (min-width:641px){.feed-card__cover-img[data-lg-ph]{min-height:200px}}';
    document.head.appendChild(st);
  }

  // Fetch covers well BEFORE their card nears the screen (Buck: "leave them
  // there, just make it a lite load"). Native loading=lazy fires too close to
  // the viewport — the photo lands while you watch and the card grows under
  // you. Still lazy (nothing fetches for cards far down a long feed), just
  // triggered ~3000px ahead so the settle happens off-screen.
  var aheadIO = ('IntersectionObserver' in window)
    ? new IntersectionObserver(function (ents) {
        for (var i = 0; i < ents.length; i++) {
          if (!ents[i].isIntersecting) continue;
          var im = ents[i].target;
          aheadIO.unobserve(im);
          try { im.loading = 'eager'; if (im.decode) im.decode().catch(function(){}); } catch (e) {}
        }
      }, { rootMargin: '3000px 0px' })
    : null;

  function tag(img) {
    if (img.__lgPh) return;
    img.__lgPh = true;
    if (img.complete) return; // already loaded (or failed) — nothing to reserve
    img.setAttribute('data-lg-ph', '1');
    var done = function () { img.removeAttribute('data-lg-ph'); };
    img.addEventListener('load', done, { once: true });
    img.addEventListener('error', done, { once: true });
    if (aheadIO) aheadIO.observe(img);
  }

  function sweep(root) {
    var imgs = (root.querySelectorAll ? root : document)
      .querySelectorAll('.feed-card__cover-img');
    for (var i = 0; i < imgs.length; i++) tag(imgs[i]);
  }

  function boot() {
    var feed = document.querySelector('.feed');
    if (!feed) { setTimeout(boot, 600); return; }
    ensureCss();
    sweep(document);
    new MutationObserver(function (muts) {
      for (var m = 0; m < muts.length; m++) {
        var added = muts[m].addedNodes;
        for (var n = 0; n < added.length; n++) {
          if (added[n].nodeType === 1) sweep(added[n]);
        }
      }
    }).observe(feed, { childList: true, subtree: true });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
