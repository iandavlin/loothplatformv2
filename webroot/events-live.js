/* Looth Group — events "LIVE NOW" surfacing for the /events/ listing.
   Self-contained: injects its own styles, then scans the listing cards and,
   for any event whose machine-readable window says it is live RIGHT NOW,
   adds a "LIVE NOW" badge and a "Join live" button.

   SAFETY (Ian's no-leak rule): the Zoom URL is the ONE gated field and is
   NEVER emitted on the listing. This script therefore NEVER fabricates or
   exposes a zoom.us URL. The "Join live" button simply follows the card's
   EXISTING detail-page href (.lg-evland__card[href]), where the gated join
   already lives (members -> Zoom, others -> upgrade).

   FORWARD-COMPATIBLE: the listing currently exposes NO start/end timestamps
   on cards. This script keys on data-start / data-end (ISO 8601) attributes.
   If they are absent (today's reality) it cleanly NO-OPs — adds nothing —
   and auto-activates once the coordinator emits the timestamps server-side.

   Loaded site-wide via /pwa.js; only acts on /events(/|$). No deps, no emoji. */
(function () {
  'use strict';
  if (window.__loothEventsLive) return;
  window.__loothEventsLive = true;

  var STYLE_ID = 'looth-events-live-style';
  var BADGE_CLASS = 'lg-evlive__badge';
  var BTN_CLASS = 'lg-evlive__join';
  var DONE_ATTR = 'data-evlive-done';

  // Only run on the events listing (and its sub-paths). No-op elsewhere.
  function onEventsPath() {
    return /^\/events(\/|$)/.test(location.pathname || '/');
  }

  // Parse an ISO 8601 attribute into epoch ms; NaN when missing/invalid.
  function isoMs(el, attr) {
    var v = el && el.getAttribute(attr);
    if (!v) return NaN;
    var t = Date.parse(v);
    return isNaN(t) ? NaN : t;
  }

  // A card is live when now is within [start, end] and both bounds parse.
  function isLive(card, now) {
    var start = isoMs(card, 'data-start');
    var end = isoMs(card, 'data-end');
    if (isNaN(start) || isNaN(end)) return false;
    return now >= start && now <= end;
  }

  function injectStyles() {
    if (document.getElementById(STYLE_ID)) return;
    var css =
      // Card needs positioning so the badge can sit in a corner.
      '.lg-evland__card.lg-evlive--on{position:relative}' +
      // LIVE NOW badge — sage fill, amber pulsing dot, no emoji.
      '.' + BADGE_CLASS + '{position:absolute;top:10px;left:10px;z-index:3;' +
      'display:inline-flex;align-items:center;gap:6px;' +
      'padding:4px 9px;border-radius:999px;' +
      'background:var(--lg-sage-d,#6b7c52);color:#fff;' +
      'font:600 11px/1 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;' +
      'letter-spacing:.05em;text-transform:uppercase;' +
      'box-shadow:0 1px 5px rgba(26,29,26,.22)}' +
      '.' + BADGE_CLASS + ' .lg-evlive__dot{width:7px;height:7px;border-radius:50%;' +
      'background:var(--lg-amber,#ecb351);box-shadow:0 0 0 0 rgba(236,179,81,.7);' +
      'animation:lgEvlivesPulse 1.6s ease-out infinite}' +
      '@keyframes lgEvlivesPulse{0%{box-shadow:0 0 0 0 rgba(236,179,81,.7)}' +
      '70%{box-shadow:0 0 0 7px rgba(236,179,81,0)}' +
      '100%{box-shadow:0 0 0 0 rgba(236,179,81,0)}}' +
      // Join live button — lives inside the card, follows the detail href.
      '.' + BTN_CLASS + '{display:inline-flex;align-items:center;gap:5px;' +
      'margin-top:10px;padding:9px 15px;border-radius:10px;' +
      'background:var(--lg-rust,#c66845);color:#fff;' +
      'font:600 14px/1 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;' +
      'text-decoration:none;-webkit-tap-highlight-color:transparent;' +
      'box-shadow:0 1px 6px rgba(198,104,69,.32);transition:filter .15s ease}' +
      '.' + BTN_CLASS + ':hover{filter:brightness(1.06)}' +
      '.' + BTN_CLASS + ':active{filter:brightness(.94)}' +
      '.' + BTN_CLASS + ' .lg-evlive__arrow{font-size:1.05em;line-height:1}' +
      // Phone viewport: keep the badge readable, button full width of the body.
      '@media (max-width:430px){' +
      '.' + BADGE_CLASS + '{top:8px;left:8px;font-size:10px;padding:3px 8px}' +
      '.' + BTN_CLASS + '{width:100%;justify-content:center;box-sizing:border-box}}';
    var s = document.createElement('style');
    s.id = STYLE_ID;
    s.textContent = css;
    (document.head || document.documentElement).appendChild(s);
  }

  function decorate(card) {
    if (card.getAttribute(DONE_ATTR)) return;
    card.setAttribute(DONE_ATTR, '1');
    card.classList.add('lg-evlive--on');

    // Badge.
    var badge = document.createElement('span');
    badge.className = BADGE_CLASS;
    badge.setAttribute('aria-label', 'Live now');
    badge.innerHTML = '<span class="lg-evlive__dot" aria-hidden="true"></span>Live now';
    card.insertBefore(badge, card.firstChild);

    // Join affordance. The card itself is an <a> to the detail page (where the
    // gated join lives), so we must NOT nest another <a> inside it (invalid HTML).
    // A styled <span> looks like a button; a click bubbles to the card anchor and
    // navigates to the detail-page gate (members -> Zoom, others -> upgrade).
    var btn = document.createElement('span');
    btn.className = BTN_CLASS;
    btn.setAttribute('role', 'button');
    btn.innerHTML = 'Join live <span class="lg-evlive__arrow" aria-hidden="true">&rarr;</span>';
    var body = card.querySelector('.lg-evland__body') || card;
    body.appendChild(btn);
  }

  function scan() {
    if (!onEventsPath()) return;
    var cards = document.querySelectorAll('.lg-evland__grid .lg-evland__card');
    if (!cards.length) return;
    var now = Date.now();
    var anyLive = false;
    var i;
    for (i = 0; i < cards.length; i++) {
      if (isLive(cards[i], now)) { anyLive = true; break; }
    }
    // No card carries a valid live window (today's reality) -> clean no-op.
    if (!anyLive) return;
    injectStyles();
    for (i = 0; i < cards.length; i++) {
      if (isLive(cards[i], now)) decorate(cards[i]);
    }
  }

  function start() {
    if (!onEventsPath()) return;
    if (document.body) scan();
    else document.addEventListener('DOMContentLoaded', scan);
    // Re-check periodically so a card that crosses into its live window during
    // a long-open session lights up without a reload. Cheap; no-ops until live.
    setInterval(scan, 60000);
  }
  start();
})();
