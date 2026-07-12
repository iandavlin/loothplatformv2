/* Looth "Loothalong" CTA — pinned hero banner at the TOP of the Events landing.
   The Loothalong is the always-on, 24-hour member video room where luthiers keep
   each other company while they work — drop in any hour, mute optional, ask the
   room or just build in good company. This injects a warm "workshop" call-to-
   action above the events grid that sells that always-open, friends-at-the-bench
   feeling (pulsing LIVE dot + animated equalizer = the room is alive right now).

   SECURITY (no-leak rule): the member-only Zoom URL must NEVER appear in client
   JS or the public DOM. This button is a PLAIN LINK whose destination handles
   gating server-side. We point at /loothalong.php, the dedicated gated route: it
   302-redirects entitled members to the live room, and bounces everyone else to
   the auth/upgrade flow. Do NOT embed a Zoom URL here.

   The crew avatars are a generic community motif (person glyphs, NOT named/real
   members and NOT a live headcount) — they convey companionship without claiming
   who is on the call right now. "Live now / Open 24/7" is literally true: it's a
   24-hour room that never closes.

   Loaded site-wide via /pwa.js, so it path-gates to /events/ only.
   Self-contained: injects its own <style> + one banner element. No deps, no emoji. */
(function () {
  'use strict';
  if (window.__loothLoothalong) return;
  window.__loothLoothalong = true;

  // Path gate — only render on the Events landing (and its subpaths). No-op elsewhere.
  if (!/^\/events(\/|$)/.test(location.pathname || '/')) return;

  var EL_ID = 'looth-loothalong';
  var STYLE_ID = 'looth-loothalong-style';

  // Single easy-to-change destination. Server-side gating lives at this route:
  // /loothalong.php 302s entitled members to the live room, others to auth/upgrade.
  var LOOTHALONG_HREF = '/loothalong.php';

  // Generic "person" glyph for the crew cluster (filled, on a tinted disc).
  // NOT a specific member — a community motif only.
  var AVA =
    '<svg viewBox="0 0 24 24" aria-hidden="true">' +
    '<circle cx="12" cy="8.2" r="3.6"/>' +
    '<path d="M4.8 20c0-3.6 3.2-5.6 7.2-5.6s7.2 2 7.2 5.6"/></svg>';

  // Trailing arrow affordance for the join button.
  var ARROW =
    '<svg class="ll-arrow" viewBox="0 0 24 24" aria-hidden="true">' +
    '<path d="M5 12h13"/><path d="M13 6l6 6-6 6"/></svg>';

  function injectStyles() {
    if (document.getElementById(STYLE_ID)) return;
    var P = '#' + EL_ID;
    var css = [
      // ── card: a lit-workshop gradient, warm + inviting ──────────────────
      P + '{position:relative;display:block;overflow:hidden;text-decoration:none;',
      'margin:0 0 20px;padding:18px 18px 17px;border-radius:20px;',
      'border:1px solid var(--lg-sage-d,#6b7c52);',
      'background:radial-gradient(120% 140% at 12% 0%,#9aa97d 0%,var(--lg-sage,#87986a) 42%,var(--lg-sage-d,#6b7c52) 100%);',
      'color:var(--lg-cream,#fbfbf8);box-shadow:0 10px 28px rgba(26,29,26,.18);',
      '-webkit-tap-highlight-color:transparent;',
      'transition:transform .14s ease,box-shadow .18s ease,filter .18s ease}',
      P + ':hover{transform:translateY(-2px);box-shadow:0 16px 38px rgba(26,29,26,.24);filter:brightness(1.02)}',
      P + ':active{transform:translateY(0);filter:brightness(.98);box-shadow:0 6px 18px rgba(26,29,26,.2)}',
      // amber "lamp" glow, top-right, slow breathing
      P + ' .ll-glow{position:absolute;top:-46px;right:-34px;width:200px;height:200px;border-radius:50%;',
      'background:radial-gradient(circle,rgba(236,179,81,.55),rgba(236,179,81,0) 70%);',
      'pointer-events:none;animation:ll-glow 4.5s ease-in-out infinite}',
      // ── top row: LIVE chip + Open 24/7 ──────────────────────────────────
      P + ' .ll-top{position:relative;display:flex;align-items:center;gap:8px}',
      P + ' .ll-live{display:inline-flex;align-items:center;gap:7px;background:rgba(26,29,26,.28);',
      'padding:5px 11px 5px 9px;border-radius:999px;font:800 10.5px/1 var(--lg-font-sans,system-ui,-apple-system,"Segoe UI",sans-serif);',
      'letter-spacing:.10em;text-transform:uppercase}',
      P + ' .ll-dot{width:9px;height:9px;border-radius:50%;background:var(--lg-rust,#c66845);flex:0 0 auto;',
      'animation:ll-pulse 1.8s ease-out infinite}',
      P + ' .ll-eq{display:inline-flex;align-items:flex-end;gap:2px;height:13px;color:var(--lg-amber,#ecb351);margin-left:1px}',
      P + ' .ll-eq i{width:3px;height:13px;border-radius:2px;background:currentColor;transform-origin:bottom;',
      'animation:ll-eq 1s ease-in-out infinite}',
      P + ' .ll-eq i:nth-child(2){animation-delay:.18s}',
      P + ' .ll-eq i:nth-child(3){animation-delay:.36s}',
      P + ' .ll-eq i:nth-child(4){animation-delay:.54s}',
      P + ' .ll-open{margin-left:auto;font:700 11px/1 var(--lg-font-sans,system-ui,sans-serif);',
      'letter-spacing:.08em;text-transform:uppercase;color:rgba(251,251,248,.85)}',
      // ── copy ────────────────────────────────────────────────────────────
      P + ' .ll-title{display:block;font-family:var(--lg-font-serif,Georgia,serif);font-size:30px;line-height:1.04;',
      'font-weight:700;letter-spacing:.01em;margin:12px 0 0}',
      P + ' .ll-tag{display:block;font:600 14.5px/1.35 var(--lg-font-sans,system-ui,sans-serif);margin:5px 0 0;color:#fff}',
      P + ' .ll-sub{display:block;font:13px/1.5 var(--lg-font-sans,system-ui,sans-serif);margin:7px 0 0;color:rgba(251,251,248,.92)}',
      // ── footer: crew cluster + join button ──────────────────────────────
      P + ' .ll-foot{position:relative;display:flex;align-items:center;gap:11px;margin-top:15px}',
      P + ' .ll-crew{display:flex;align-items:center;gap:9px;min-width:0}',
      P + ' .ll-ava{display:flex;align-items:center;flex:0 0 auto}',
      P + ' .ll-ava span{width:30px;height:30px;border-radius:50%;border:2px solid var(--lg-cream,#fbfbf8);',
      'margin-left:-9px;display:flex;align-items:center;justify-content:center}',
      P + ' .ll-ava span:first-child{margin-left:0}',
      P + ' .ll-ava svg{width:17px;height:17px;display:block;fill:rgba(251,251,248,.95);stroke:none}',
      P + ' .ll-crewl{font:600 11.5px/1.2 var(--lg-font-sans,system-ui,sans-serif);color:rgba(251,251,248,.9);max-width:104px}',
      P + ' .ll-btn{margin-left:auto;display:inline-flex;align-items:center;gap:8px;position:relative;overflow:hidden;',
      'flex:0 0 auto;background:var(--lg-cream,#fbfbf8);color:var(--lg-sage-d,#6b7c52);',
      'font:800 14px/1 var(--lg-font-sans,system-ui,sans-serif);padding:12px 16px;border-radius:999px;',
      'box-shadow:0 4px 14px rgba(26,29,26,.22)}',
      P + ' .ll-btn .ll-arrow{width:17px;height:17px;display:block;fill:none;stroke:currentColor;stroke-width:2.4;',
      'stroke-linecap:round;stroke-linejoin:round;transition:transform .15s ease}',
      P + ':hover .ll-btn .ll-arrow{transform:translateX(3px)}',
      P + ' .ll-sheen{position:absolute;inset:0;width:55%;transform:translateX(-120%);',
      'background:linear-gradient(100deg,transparent,rgba(255,255,255,.7),transparent);animation:ll-sheen 3.6s ease-in-out infinite}',
      // ── keyframes ───────────────────────────────────────────────────────
      '@keyframes ll-pulse{0%{box-shadow:0 0 0 0 rgba(198,104,69,.55)}70%{box-shadow:0 0 0 9px rgba(198,104,69,0)}100%{box-shadow:0 0 0 0 rgba(198,104,69,0)}}',
      '@keyframes ll-eq{0%,100%{transform:scaleY(.32)}50%{transform:scaleY(1)}}',
      '@keyframes ll-glow{0%,100%{opacity:.55}50%{opacity:1}}',
      '@keyframes ll-sheen{0%{transform:translateX(-120%)}60%,100%{transform:translateX(220%)}}',
      // ── phone width: tighten ────────────────────────────────────────────
      '@media (max-width:480px){',
      P + '{padding:16px 15px 15px}',
      P + ' .ll-title{font-size:26px}',
      P + ' .ll-sub{font-size:12.5px}',
      P + ' .ll-crewl{max-width:84px}',
      P + ' .ll-btn{padding:11px 14px;font-size:13px}}',
      // ── respect reduced-motion ──────────────────────────────────────────
      '@media (prefers-reduced-motion:reduce){',
      P + ' .ll-dot,' + P + ' .ll-eq i,' + P + ' .ll-glow,' + P + ' .ll-sheen{animation:none!important}}'
    ].join('');
    var s = document.createElement('style');
    s.id = STYLE_ID;
    s.textContent = css;
    (document.head || document.documentElement).appendChild(s);
  }

  // Find the most robust insertion point inside the events landing content.
  // Confirmed DOM: .lg-events-landing-page > header + main > .lg-evland >
  //   .lg-evland__head, .lg-evland__grid, ...  We insert as the first child of
  //   the inner content wrapper so the CTA sits above the head/grid.
  function findHost() {
    return document.querySelector('.lg-evland') ||
      document.querySelector('.lg-events-landing-page main') ||
      document.querySelector('.lg-events-landing-page');
  }

  function avatar(tint) {
    return '<span style="background:' + tint + '">' + AVA + '</span>';
  }

  function build() {
    if (document.getElementById(EL_ID)) return; // idempotent
    var host = findHost();
    if (!host) return; // graceful no-op if container not found

    injectStyles();

    var a = document.createElement('a');
    a.id = EL_ID;
    a.href = LOOTHALONG_HREF;
    // Open the gated join route in a NEW tab so the visitor's current
    // loothgroup.com tab is never navigated away to Zoom (Ian 2026-07-12).
    // rel=noopener so the opened tab can't reach back via window.opener.
    a.target = '_blank';
    a.rel = 'noopener';
    a.setAttribute('aria-label', 'Loothalong — the always-on 24/7 luthier hangout room. Drop in any time. Opens in a new tab.');
    a.innerHTML =
      '<span class="ll-glow"></span>' +
      '<span class="ll-top">' +
        '<span class="ll-live"><span class="ll-dot"></span>Live now' +
          '<span class="ll-eq"><i></i><i></i><i></i><i></i></span></span>' +
        '<span class="ll-open">Open 24/7</span>' +
      '</span>' +
      '<span class="ll-title">Loothalong</span>' +
      '<span class="ll-tag">A 24-hour workbench full of friends.</span>' +
      '<span class="ll-sub">Drop in whenever &mdash; ask a question, show a repair, or just keep good ' +
        'company while you work. No agenda, no boss. Just luthiers at the bench.</span>' +
      '<span class="ll-foot">' +
        '<span class="ll-crew">' +
          '<span class="ll-ava">' +
            avatar('#6b7c52') + avatar('#c66845') + avatar('#b98a3e') + avatar('#7d8a5c') +
          '</span>' +
          '<span class="ll-crewl">luthiers hang here</span>' +
        '</span>' +
        '<span class="ll-btn">Pull up a bench' + ARROW + '<span class="ll-sheen"></span></span>' +
      '</span>';

    // Prefer inserting before the grid; else first child of the host.
    var grid = host.querySelector('.lg-evland__grid');
    if (grid && grid.parentNode === host) {
      host.insertBefore(a, grid);
    } else if (host.firstChild) {
      host.insertBefore(a, host.firstChild);
    } else {
      host.appendChild(a);
    }
  }

  function start() {
    if (document.body) build();
    else document.addEventListener('DOMContentLoaded', build);
  }
  start();
})();
