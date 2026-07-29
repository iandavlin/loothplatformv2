/* events-mobile.js — mobile treatment for the events landing.
 *
 * Buck 2026-06-08 asked for a bottom sheet here: tapping an event card opened an
 * inline popup with the cover, date, when, tier, description and its primary
 * action, so phones never landed on the desktop event page by accident. That
 * sheet is RETIRED — Ian, 2026-07-29: on mobile, stop opening the modal
 * entirely; a tap on an event goes straight to the event's post page. Mobile now
 * does what desktop has always done, so the card's own href is simply left alone
 * and there is no interception at all. Removing the sheet also fixed both
 * defects Ian reported from his phone the same day, by deleting the surface they
 * lived on: the sheet's `.lev-cover` forced a 16:9 poster into a fixed 170px box
 * (cropping the date typeset into the artwork), and its description selector led
 * with `.lg-event-header__detail`, printing the time line a second time. The
 * destination post page has neither problem — under 768px `post-header/shell.css`
 * runs the hero at `height:auto; object-fit:unset`, so the poster is uncropped,
 * and the event header states the date exactly once.
 *
 * What remains here is the landing's own mobile chrome: the shared top bar is
 * replaced by a sticky search bar that filters the upcoming-events list.
 *
 * Buck-owned client layer, loaded via /pwa.js, path-gated to /events + ≤640.
 */
(function () {
  'use strict';
  if (window.__loothEventsMobile) return;
  var path = location.pathname || '';
  if (path.indexOf('/events') !== 0) return;                 // events landing only
  if (!window.matchMedia('(max-width:640px)').matches) return;
  window.__loothEventsMobile = true;

  injectStyles();

  // No click handler on a.lg-evland__card, deliberately. The anchor navigates to
  // the event's post page on its own — that IS Ian's ruling. Anything added here
  // that calls preventDefault() reintroduces the modal.

  // On the events landing: replace the shared top bar with a search bar that
  // filters the upcoming-events list (Buck 2026-06-08).
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', buildEventsSearch);
  else buildEventsSearch();

  function txt(el) { return el ? (el.textContent || '').trim() : ''; }

  function injectStyles() {
    if (document.getElementById('looth-ev-style')) return;
    var css = [
      /* events landing: replace the shared top bar with an upcoming-events search bar */
      'html.lgev .lg-chrome{display:none!important}',
      /* drop the tall shared footer on mobile — the bottom tab bar covers nav (Buck 2026-06-08) */
      'html.lgev .lg-chrome-foot{display:none!important}',
      '#lgev-search{position:sticky;top:0;z-index:1200;background:var(--lg-cream,#fbfbf8);' +
        'padding:calc(env(safe-area-inset-top,0px) + 10px) 12px 10px}',
      '#lgev-search .lgev-ubar{display:flex;align-items:center;gap:9px;background:#fff;' +
        'border:1px solid var(--lg-line,#e3ddd0);border-radius:999px;padding:10px 14px;box-shadow:0 4px 16px rgba(26,29,26,.14)}',
      '#lgev-search .lgev-ic{width:18px;height:18px;flex:0 0 auto;color:var(--lg-mute,#6b6f6b)}',
      '#lgev-search .lgev-input{flex:1 1 auto;min-width:0;border:0;outline:0;background:transparent;' +
        'font:15px/1.2 var(--lg-font-sans,system-ui,sans-serif);color:var(--lg-ink,#323532)}',
      '#lgev-search .lgev-clear{flex:0 0 auto;width:26px;height:26px;border:0;background:none;' +
        'color:var(--lg-mute,#6b6f6b);font-size:20px;line-height:1;cursor:pointer}',
      '.lgev-empty{padding:24px 16px;text-align:center;color:var(--lg-mute,#6b6f6b);font:14px/1.5 var(--lg-font-sans,system-ui,sans-serif)}',
      /* DARK pass (2026-06-10). The sheet's dark rules went with the sheet; only
         the landing's own search bar + empty state need darkening now. */
      'html[data-lguser-theme="dark"] #lgev-search{background:#15171a}',
      'html[data-lguser-theme="dark"] .lgev-empty{color:#9aa097}'
    ].join('\n');
    var s = document.createElement('style'); s.id = 'looth-ev-style'; s.textContent = css;
    (document.head || document.documentElement).appendChild(s);
  }

  // Replace the shared top bar with a sticky "Search upcoming events" bar that
  // filters the landing's event cards by title/date as you type.
  function buildEventsSearch() {
    try {
      if (document.getElementById('lgev-search')) return;
      var land = document.querySelector('.lg-evland');
      if (!land) return;                                         // only on the events landing
      document.documentElement.classList.add('lgev');           // CSS hides .lg-chrome
      var bar = document.createElement('div');
      bar.id = 'lgev-search';
      bar.innerHTML =
        '<div class="lgev-ubar">' +
          '<svg class="lgev-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>' +
          '<input type="text" class="lgev-input" placeholder="Search upcoming events" autocomplete="off" aria-label="Search upcoming events">' +
          '<button type="button" class="lgev-clear" aria-label="Clear" hidden>&times;</button>' +
        '</div>';
      land.parentNode.insertBefore(bar, land);
      var input = bar.querySelector('.lgev-input'), clr = bar.querySelector('.lgev-clear');
      function apply() {
        var q = input.value.trim().toLowerCase();
        clr.hidden = !input.value;
        var anyAll = false;
        [].forEach.call(document.querySelectorAll('.lg-evland__card'), function (c) {
          var hay = ((txt(c.querySelector('.lg-evland__title')) + ' ' + txt(c.querySelector('.lg-evland__when')) + ' ' + txt(c.querySelector('.lg-evland__region'))) || '').toLowerCase();
          var show = !q || hay.indexOf(q) > -1;
          c.style.display = show ? '' : 'none';
          if (show) anyAll = true;
        });
        // collapse sections (+ their headers) that have no visible cards
        [].forEach.call(document.querySelectorAll('.lg-evland__section'), function (sec) {
          var any = false;
          [].forEach.call(sec.querySelectorAll('.lg-evland__card'), function (c) { if (c.style.display !== 'none') any = true; });
          sec.style.display = any ? '' : 'none';
        });
        var empty = document.getElementById('lgev-empty');
        if (q && !anyAll) {
          if (!empty) { empty = document.createElement('div'); empty.id = 'lgev-empty'; empty.className = 'lgev-empty'; land.appendChild(empty); }
          empty.textContent = 'No upcoming events match “' + input.value.trim() + '”.';
          empty.style.display = '';
        } else if (empty) { empty.style.display = 'none'; }
      }
      input.addEventListener('input', apply);
      clr.addEventListener('click', function () { input.value = ''; apply(); input.focus(); });
    } catch (e) { /* never break the landing */ }
  }
})();
