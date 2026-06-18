/* mobile-hub.js — MOBILE behaviors for The Hub (≤640px).  OWNER: Buck.
 *
 * BEHAVIORS ONLY — never the look (the look is mobile-hub.css, CSS-arranging the flat
 * fc-* contract; see docs/hub-mobile-desktop-split.md). Loaded deferred via pwa.js,
 * mobile-gated at runtime. Self-contained, no deps, no emoji. Idempotent.
 *
 * Does:
 *   1. killCompactOnMobile — strip html.hub-compact on phones (no compact mode on
 *      mobile; localStorage untouched so desktop keeps the user's choice).
 *   2. wireLongPressReactions — press-and-hold the like / engagement row opens the
 *      SHARED discovery-backed reaction picker (.fcr-palette) by delegating to
 *      forums.js's own .fcr-add toggle. A plain tap leaves the native like toggle
 *      alone. We do NOT build a mobile-only reaction system (coordinator, 2026-06-06).
 *
 * Dropped vs the old app-mobile-fixes.js: the sort-bar header-tuck + hide-view-toggles
 * (now canonical in forums.css), and the old .lg-react-bar stopgap (replaced by the
 * real shared picker). Site-wide guards (archive→hub redirect, hamburger-dupe trim,
 * footer/map/drawer overflow) remain in app-mobile-fixes.js / their canonical homes.
 */
(function () {
  'use strict';
  if (window.__loothMobileHub) return;
  window.__loothMobileHub = true;

  var isMobile = function () {
    try { return window.matchMedia('(max-width:640px)').matches; } catch (e) { return false; }
  };

  /* 1 ─────────────────────────── kill compact on mobile ─────────────────────── */
  function killCompactOnMobile() {
    try {
      if (!isMobile()) return;
      var h = document.documentElement;
      var strip = function () { if (h.classList.contains('hub-compact')) h.classList.remove('hub-compact'); };
      strip();
      if ('MutationObserver' in window) {
        new MutationObserver(strip).observe(h, { attributes: true, attributeFilter: ['class'] });
      }
    } catch (e) {}
  }

  /* 2 ─────────────────── long-press → shared .fcr reaction picker ────────────────
     forums.js already owns the picker: clicking .fcr-add toggles .fcr-palette and
     .fcr-opt commits the reaction to the discovery-backed store. We only add the
     mobile TRIGGER: a press-and-hold on the engagement row opens that same palette.
     A short tap is left untouched so the existing quick-like still fires. */
  function wireLongPressReactions() {
    if (document.body.getAttribute('data-lg-mobile-react')) return;
    document.body.setAttribute('data-lg-mobile-react', '1');

    var HOLD_MS = 380;
    var timer = null, longPressed = false, originBar = null;

    function fcrBarFor(el) {
      // A press inside a reply/comment opens THAT comment's own reaction bar.
      // (Buck 2026-06-08: hold-to-react worked on the post but not on comments
      // because this always resolved the CARD-level .fcr — the first one in the
      // card — never the reply's. Resolve the reply's .fcr first.)
      var stub = el.closest && el.closest('.reply-stub');
      if (stub) { var rf = stub.querySelector('.fcr'); if (rf) return rf; }
      // The card carries TWO engagement rows: the canonical reaction row (.fc-actions
      // → .fcr) and hub-polish's heart/reply/share row (.lg-card-actions → .lg-act-like).
      // A long-press on EITHER should open the one real .fcr picker, so resolve it from
      // the whole card, not just .fc-actions.
      var card = el.closest && el.closest('.feed-card');
      if (card) { var f = card.querySelector('.fcr'); if (f) return f; }
      var slot = el.closest && el.closest('.fc-actions');
      return slot ? slot.querySelector('.fcr') : null;
    }
    function holdTargetFrom(el) {
      // open the picker from a press on the like heart OR anywhere on the reaction row —
      // for the POST (.lg-act-like / .fc-actions / .fcr-chips) AND for a COMMENT
      // (its Like / action row / reaction bar).
      return (el.closest && (
        el.closest('.lg-act-like') || el.closest('.fcr-chips') || el.closest('.fc-actions') ||
        el.closest('.lg-fb-like') || el.closest('.lg-fb-actions') || el.closest('.reply-stub__actions')
      )) || null;
    }
    function openShared(bar) {
      if (!bar) return;
      var add = bar.querySelector('.fcr-add');
      var pal = bar.querySelector('.fcr-palette');
      if (!add || !pal) return;          // anon viewers have no add trigger → nothing to open
      if (!pal.hidden) return;           // already open
      // Open the palette DIRECTLY rather than via add.click(): a synthetic click here
      // is caught by our own capture-phase swallower below (it consumes longPressed),
      // which left the palette closed during the hold. Replicate forums.js's
      // closePalettes() + reveal so no extra click is dispatched and longPressed
      // survives to swallow the real release click (keeping the palette open).
      [].forEach.call(document.querySelectorAll('.fcr-palette'), function (p) {
        if (p !== pal) p.hidden = true;
      });
      pal.hidden = false;
    }

    document.addEventListener('pointerdown', function (e) {
      if (!isMobile()) return;
      if (e.target.closest && e.target.closest('.fcr-palette')) return;  // a press inside the open palette
      var tgt = holdTargetFrom(e.target);
      if (!tgt) return;
      longPressed = false;
      originBar = fcrBarFor(tgt);
      clearTimeout(timer);
      timer = setTimeout(function () {
        longPressed = true;
        openShared(originBar);
      }, HOLD_MS);
    }, true);

    var cancel = function () { clearTimeout(timer); };
    document.addEventListener('pointerup', cancel, true);
    document.addEventListener('pointercancel', cancel, true);
    document.addEventListener('pointermove', function (e) {
      // a drag/scroll cancels the hold
      if (timer && (Math.abs(e.movementX) > 6 || Math.abs(e.movementY) > 6)) cancel();
    }, true);

    // swallow the click that follows a long-press so the native like toggle / card-open
    // does not also fire
    document.addEventListener('click', function (e) {
      if (longPressed) {
        longPressed = false;
        if (e.target.closest && !e.target.closest('.fcr-opt')) {
          e.stopImmediatePropagation();
          e.preventDefault();
        }
      }
    }, true);

    // iOS: suppress the long-press context/callout over BOTH engagement rows + picker
    // (the heart lives in .lg-card-actions, the reactions in .fc-actions).
    document.addEventListener('contextmenu', function (e) {
      if (e.target.closest && (e.target.closest('.fc-actions') || e.target.closest('.lg-card-actions') || e.target.closest('.fcr-palette') || e.target.closest('.reply-stub__actions') || e.target.closest('.lg-fb-actions'))) {
        if (isMobile()) e.preventDefault();
      }
    }, true);
  }

  function init() {
    killCompactOnMobile();
    wireLongPressReactions();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
