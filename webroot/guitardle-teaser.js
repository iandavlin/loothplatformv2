/* Guitardle Hub teaser — "Play today's Guitardle" strip under the Hub sort bar.
   The daily game lives below the fold on the desktop-only /archive-poc/ landing,
   so phones could never reach it and desktop rarely scrolled to it. This layer
   surfaces it where users actually are (the Hub) and opens the game in-place:
   mobile = pull-up bottom sheet (standard gestures: pill/header drag-to-dismiss,
   backdrop tap, X, phone back-gesture), desktop = centered modal (backdrop, X,
   Esc). The game loads in a same-origin iframe of its STANDALONE page (not
   ?embed=1) — at sheet width its own <=499px layout pins the keyboard to the
   sheet bottom. pwa.js never boots inside iframes (top!==self guard), so no app
   chrome leaks in and the mobile archive redirect can't fire there.
   The iframe is HIDDEN on close, never destroyed: the game forfeits a day on
   reload, so a mid-game peek at the feed must not kill the round.
   Buck-lane client layer, loaded via /pwa.js on /hub. v1 2026-06-12; v2 z-tier
   above the tab bar; v3 Guitardle icon (Buck-supplied placeholder art served at
   /guitardle-icon.png — also a candidate for the game page favicon, Ian's call);
   v6 overscroll-to-close (Buck, 2026-06-11) — pull-down from inside the game
   body dismisses the sheet (same-origin iframe doc listeners, atTop guard). */
(function () {
  'use strict';
  if (window.__loothGdleTeaser) return;
  window.__loothGdleTeaser = true;
  if (window.top !== window.self) return;
  if (!/^\/hub(\/|$)/.test(location.pathname || '')) return;

  var DARK = 'html[data-lguser-theme="dark"]';
  var css = [
    '.lg-gdle-teaser{display:flex;align-items:center;gap:12px;max-width:720px;margin:10px auto 4px;padding:11px 14px;',
    'background:#eef2e3;border:1px solid #d4e0b8;border-radius:14px;font-family:inherit;cursor:pointer;',
    'user-select:none;-webkit-user-select:none;transition:transform .12s,box-shadow .12s}',
    '.lg-gdle-teaser:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(26,32,23,.12)}',
    '.lg-gdle-teaser__tile{flex:0 0 auto;width:40px;height:40px}',
    '.lg-gdle-teaser__tile img{width:100%;height:100%;display:block;object-fit:contain}',
    '.lg-gdle-ttl-ic{width:20px;height:20px;border-radius:5px;display:inline-block}',
    '.lg-gdle-teaser__txt{flex:1 1 auto;min-width:0}',
    /* the word as mini game tiles — colors mirror the icon art (amber T, green A) */
    '.lg-gdle-tl{display:flex;gap:2px;margin-bottom:4px}',
    '.lg-gdle-tl span{width:17px;height:17px;display:flex;align-items:center;justify-content:center;border-radius:3px;',
    'background:#3a3f3a;color:#fff;font:700 11px/1 Jost,system-ui,sans-serif}',
    '.lg-gdle-tl .lt-am{background:#d9a13b;color:#241d10}',
    '.lg-gdle-tl .lt-gr{background:#6aaa64;color:#fff}',
    '.lg-gdle-teaser__s{display:block;font-size:12px;color:#6b6f6b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
    '.lg-gdle-teaser__btn{flex:0 0 auto;border:0;cursor:pointer;background:#ECB351;color:#2a2210;font:700 13px/1 inherit;',
    'padding:10px 18px;border-radius:999px;-webkit-tap-highlight-color:transparent;box-shadow:0 1px 0 rgba(26,32,23,.18);',
    'transition:background .12s}',
    '.lg-gdle-teaser__btn:hover{background:#f1c46e}',
    '.lg-gdle-teaser__btn--done{background:transparent;color:#8a6c2c;border:1px solid #ECB351;box-shadow:none;font-weight:600}',
    '.lg-gdle-teaser__btn--done:hover{background:rgba(236,179,81,.14)}',
    '@media (max-width:640px){.lg-gdle-teaser{margin:8px 10px 2px;padding:10px 12px}}',
    DARK + ' .lg-gdle-teaser{background:linear-gradient(180deg,#252b1f,#1b201a);border-color:#3c4332}',
    DARK + ' .lg-gdle-teaser:hover{box-shadow:0 4px 14px rgba(0,0,0,.45)}',
    DARK + ' .lg-gdle-tl span{background:#474d47}',
    DARK + ' .lg-gdle-tl .lt-am{background:#d9a13b;color:#241d10}',
    DARK + ' .lg-gdle-tl .lt-gr{background:#6aaa64;color:#fff}',
    DARK + ' .lg-gdle-teaser__s{color:#9aab7f}',
    DARK + ' .lg-gdle-teaser__btn--done{color:#ECB351;border-color:#8a6c2c}',
    /* shared overlay */
    /* z tier: above the bottom tab bar (~2147481500), same band as profile-sheet */
    '#lg-gdle-back{position:fixed;inset:0;background:rgba(26,29,26,.55);z-index:2147483520;opacity:0;transition:opacity .2s}',
    '#lg-gdle-back.on{opacity:1}',
    /* mobile sheet */
    '#lg-gdle-sheet{position:fixed;left:0;right:0;bottom:0;height:92dvh;z-index:2147483540;background:#EAF0DD;',
    'border-radius:18px 18px 0 0;display:flex;flex-direction:column;overflow:hidden;touch-action:none;',
    'box-shadow:0 -8px 30px rgba(26,32,23,.25);transform:translateY(100%);transition:transform .26s cubic-bezier(.3,.7,.3,1)}',
    '#lg-gdle-sheet.on{transform:translateY(0)}',
    '#lg-gdle-sheet.drag{transition:none}',
    '.lg-gdle-sheet__head{flex:0 0 auto;padding:6px 8px 4px;cursor:grab}',
    '.lg-gdle-sheet__pill{width:44px;height:5px;border-radius:3px;background:#C2D5AA;margin:0 auto 6px}',
    '.lg-gdle-sheet__row{display:flex;align-items:center;justify-content:space-between;padding:0 8px}',
    '.lg-gdle-sheet__title{display:inline-flex;align-items:center;gap:7px;font:700 15px/1 var(--lg-font-serif,Georgia,serif);color:#3a4a2e}',
    '.lg-gdle-x{border:0;background:none;cursor:pointer;font-size:22px;line-height:1;color:#6b7a5a;padding:4px 8px}',
    '#lg-gdle-sheet iframe,#lg-gdle-modal iframe{flex:1 1 auto;width:100%;border:0;display:block;background:#EAF0DD}',
    DARK + ' #lg-gdle-sheet{background:#1d211b}',
    DARK + ' .lg-gdle-sheet__pill{background:#39402f}',
    DARK + ' .lg-gdle-sheet__title{color:#cfdabd}',
    /* desktop modal */
    '#lg-gdle-modal{position:fixed;left:50%;top:50%;transform:translate(-50%,-50%) scale(.97);width:min(640px,94vw);',
    'height:min(860px,92vh);z-index:2147483540;background:#EAF0DD;border-radius:18px;display:flex;flex-direction:column;',
    'overflow:hidden;box-shadow:0 18px 60px rgba(26,32,23,.35);opacity:0;transition:opacity .18s,transform .18s}',
    '#lg-gdle-modal.on{opacity:1;transform:translate(-50%,-50%) scale(1)}',
    '.lg-gdle-modal__row{flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;padding:10px 8px 6px 16px}',
    DARK + ' #lg-gdle-modal{background:#1d211b}'
  ].join('');

  function todayStr() {
    var t = new Date();
    return t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0') + '-' + String(t.getDate()).padStart(2, '0');
  }
  function playedToday() {
    try { return localStorage.getItem('guitardle_lastPlayed') === todayStr(); } catch (e) { return false; }
  }
  function isMember() {
    return !!document.querySelector('.lg-chrome__account-menu, .lg-chrome [data-lg-user]');
  }
  function gameUrl() {
    return '/archive-poc/guitardle/index.html?aud=' + (isMember() ? 'm' : 'p');
  }
  function isPhone() { return window.matchMedia('(max-width:640px)').matches; }

  var back = null, panel = null, pushed = false;

  function ensureOverlay() {
    if (panel) return;
    back = document.createElement('div');
    back.id = 'lg-gdle-back';
    back.addEventListener('click', close);

    if (isPhone()) {
      panel = document.createElement('div');
      panel.id = 'lg-gdle-sheet';
      panel.innerHTML =
        '<div class="lg-gdle-sheet__head"><div class="lg-gdle-sheet__pill"></div>' +
        '<div class="lg-gdle-sheet__row"><span class="lg-gdle-sheet__title"><img class="lg-gdle-ttl-ic" src="/guitardle-icon.png?v=2" alt="">Guitardle</span>' +
        '<button type="button" class="lg-gdle-x" aria-label="Close">&times;</button></div></div>' +
        '<iframe title="Guitardle" src="' + gameUrl() + '"></iframe>';
      wireDrag(panel, panel.querySelector('.lg-gdle-sheet__head'));
      wireIframeOverscroll(panel, panel.querySelector('iframe'));
    } else {
      panel = document.createElement('div');
      panel.id = 'lg-gdle-modal';
      panel.innerHTML =
        '<div class="lg-gdle-modal__row"><span class="lg-gdle-sheet__title"><img class="lg-gdle-ttl-ic" src="/guitardle-icon.png?v=2" alt="">Guitardle</span>' +
        '<button type="button" class="lg-gdle-x" aria-label="Close">&times;</button></div>' +
        '<iframe title="Guitardle" src="' + gameUrl() + '"></iframe>';
    }
    panel.querySelector('.lg-gdle-x').addEventListener('click', close);
    document.body.appendChild(back);
    document.body.appendChild(panel);
  }

  /* Pull-down-to-dismiss from the grab pill / header (the body is an iframe, so
     drags can only start on the sheet's own chrome — same one-finger pattern as
     profile-sheet wireDrag). */
  function wireDrag(sheet, handle) {
    var startY = 0, dy = 0, on = false;
    handle.addEventListener('touchstart', function (e) {
      if (!e.touches || e.touches.length !== 1) return;
      on = true; startY = e.touches[0].clientY; dy = 0;
      sheet.classList.add('drag');
    }, { passive: true });
    handle.addEventListener('touchmove', function (e) {
      if (!on) return;
      dy = Math.max(0, e.touches[0].clientY - startY);
      sheet.style.transform = 'translateY(' + dy + 'px)';
    }, { passive: true });
    handle.addEventListener('touchend', function () {
      if (!on) return;
      on = false; sheet.classList.remove('drag');
      sheet.style.transform = '';
      if (dy > 110) close();
    });
  }

  /* Overscroll-to-close (Buck, 2026-06-11): the sheet body is the game iframe,
     so parent-document drags can never start there. Same-origin lets us listen
     inside the iframe's OWN document instead: when the game is scrolled to the
     very top (standard atTop guard — scrollingElement plus the touch target's
     scrollable ancestors) and the finger pulls DOWN, the sheet follows and
     releases past the same ~110px threshold as the header drag. Upward or
     sideways gestures, or any scrolled-down state, stay with the game.
     Re-attached on every iframe load (each navigation replaces the document). */
  function wireIframeOverscroll(sheet, ifr) {
    function attach() {
      var doc;
      try { doc = ifr.contentDocument; } catch (e) { return; } /* cross-origin safety */
      if (!doc || doc.__lgGdleOverscroll) return;
      doc.__lgGdleOverscroll = true;

      var startY = 0, startX = 0, dy = 0, eligible = false, engaged = false;

      function atTop(node) {
        var se = doc.scrollingElement || doc.documentElement;
        if (se && se.scrollTop > 0) return false;
        for (var el = node; el && el !== doc.documentElement; el = el.parentElement) {
          if (el.scrollTop > 0 && el.scrollHeight > el.clientHeight) return false;
        }
        return true;
      }

      doc.addEventListener('touchstart', function (e) {
        if (!e.touches || e.touches.length !== 1) { eligible = false; return; }
        startY = e.touches[0].clientY; startX = e.touches[0].clientX;
        dy = 0; engaged = false;
        eligible = atTop(e.target);
      }, { passive: true });

      doc.addEventListener('touchmove', function (e) {
        if (!eligible || !e.touches || e.touches.length !== 1) return;
        dy = e.touches[0].clientY - startY;
        var dx = e.touches[0].clientX - startX;
        if (!engaged) {
          /* upward or horizontal-dominant start = the game's gesture, not ours */
          if (dy < -8 || Math.abs(dx) > Math.abs(dy)) { eligible = false; return; }
          if (dy <= 8) return; /* slop — taps on the keyboard never engage */
          engaged = true;
          sheet.classList.add('drag');
        }
        e.preventDefault(); /* sheet owns the gesture — stop the game rubber-banding */
        sheet.style.transform = 'translateY(' + Math.max(0, dy) + 'px)';
      }, { passive: false });

      function end() {
        if (!engaged) { eligible = false; return; }
        engaged = false; eligible = false;
        sheet.classList.remove('drag');
        sheet.style.transform = '';
        if (dy > 110) close();
      }
      doc.addEventListener('touchend', end);
      doc.addEventListener('touchcancel', end);
    }
    ifr.addEventListener('load', attach);
    attach(); /* in case the document is already live */
  }

  function open() {
    ensureOverlay();
    back.style.display = 'block';
    panel.style.display = 'flex';
    requestAnimationFrame(function () {
      back.classList.add('on');
      panel.classList.add('on');
    });
    document.documentElement.style.overflow = 'hidden';
    if (!pushed) {
      try { history.pushState({ lgGdle: 1 }, ''); pushed = true; } catch (e) {}
    }
  }

  /* Hide, don't destroy — reloading the iframe mid-game would forfeit the day. */
  function hide() {
    if (!panel) return;
    back.classList.remove('on');
    panel.classList.remove('on');
    document.documentElement.style.overflow = '';
    setTimeout(function () {
      if (back && !back.classList.contains('on')) { back.style.display = 'none'; panel.style.display = 'none'; }
    }, 280);
    refreshBtn();
  }

  function close() {
    if (pushed) { pushed = false; try { history.back(); return; } catch (e) {} }
    hide();
  }

  window.addEventListener('popstate', function () {
    if (panel && panel.classList.contains('on')) { pushed = false; hide(); }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && panel && panel.classList.contains('on')) close();
  });

  var btnEl = null;
  function refreshBtn() {
    if (!btnEl) return;
    if (playedToday()) {
      btnEl.textContent = 'Played - see board';
      btnEl.classList.add('lg-gdle-teaser__btn--done');
    } else {
      btnEl.textContent = 'Play';
      btnEl.classList.remove('lg-gdle-teaser__btn--done');
    }
  }

  function buildTeaser() {
    if (document.querySelector('.lg-gdle-teaser')) return true;
    var bar = document.querySelector('.feed-sort-bar');
    var feed = document.querySelector('.feed');
    if (!bar && !feed) return false;

    var el = document.createElement('div');
    el.className = 'lg-gdle-teaser';
    var tiles = 'GUITARDLE'.split('').map(function (L, i) {
      var cls = i === 3 ? ' class="lt-am"' : (i === 4 ? ' class="lt-gr"' : '');
      return '<span' + cls + '>' + L + '</span>';
    }).join('');
    el.innerHTML =
      '<span class="lg-gdle-teaser__tile" aria-hidden="true"><img src="/guitardle-icon.png?v=2" alt=""></span>' +
      '<span class="lg-gdle-teaser__txt"><span class="lg-gdle-tl" aria-label="Guitardle">' + tiles + '</span>' +
      '<span class="lg-gdle-teaser__s">The daily guitar phrase game</span></span>' +
      '<button type="button" class="lg-gdle-teaser__btn"></button>';
    btnEl = el.querySelector('.lg-gdle-teaser__btn');
    refreshBtn();
    el.addEventListener('click', open);   /* whole banner opens the game */

    if (bar && bar.parentNode) bar.parentNode.insertBefore(el, bar.nextSibling);
    else feed.parentNode.insertBefore(el, feed);
    return true;
  }

  function boot() {
    var s = document.createElement('style');
    s.id = 'lg-gdle-teaser-css';
    s.textContent = css;
    (document.head || document.documentElement).appendChild(s);
    if (buildTeaser()) return;
    var tries = 0;
    var iv = setInterval(function () {
      if (buildTeaser() || ++tries > 25) clearInterval(iv);
    }, 400);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
