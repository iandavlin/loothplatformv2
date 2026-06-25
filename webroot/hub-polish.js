/* Looth Hub — visual polish for the activity feed (client-side, injected
   site-wide via /pwa.js). The Hub is the app's HOME surface: this makes the
   feed feel app-like and media-forward without touching the canonical Hub
   tree (bb-mirror, coordinator-owned).

   WHAT THIS DOES (mobile/app viewport, <=640px):
   - Richer hero: taller header, a brand scrim for depth + legibility, a bolder
     cream serif title, and a one-line tagline so the landing feels alive.
   - App-card feed: 16px radius (brand), a soft float shadow so each post reads
     as a physical card, more air between cards, and a press/tap feedback state.
   - Media-forward covers: keep cover images big and punchy but cap runaway
     portrait shots so the feed stays scannable.
   - Cleaner kind-badge pills + slightly larger titles.
   - Kills a pre-existing horizontal-scroll bug on the Hub: the feed (and the
     per-card replies) are CSS grids whose 1fr track was sized to the widest
     card''s min-content (grid items default to min-width:auto), and several
     nowrap lines (forum breadcrumb, reply author/time) refused to shrink. The
     net effect was the document scrolling sideways ~380px on a phone. Fixed
     with minmax(0,1fr) tracks, min-width:0 on the grid items, ellipsis on the
     nowrap author line, and overflow:hidden clipping at the card edge. Confirmed
     document scrollWidth back to viewport width (no horizontal scroll).

   WHY CLIENT-SIDE: the Hub is served from the bb-mirror tree (forums.css), which
   is coordinator-owned. A <style> appended to <head> AFTER forums.css wins on
   plain source-order specificity (no !important), so these rules layer cleanly
   over the existing mobile pass (@media max-width:640px). If/when the canonical
   forums.css absorbs them, this becomes a harmless duplicate.

   PLUS (all widths): pills the Hub control rail's top-level Type rows + category
   PARENTS, while leaving the LEAVES flat so the missing pill divides a parent
   from its subforums (Ian's category-nav polish).

   Self-contained: one <style> + (on the main Hub only) one tagline node.
   Path-gated to /hub. No deps, no emoji. */
(function () {
  'use strict';
  if (window.__loothHubPolish) return;
  window.__loothHubPolish = true;

  var STYLE_ID = 'looth-hub-polish';
  var TAGLINE_CLASS = 'lg-hub-tagline';
  var TAGLINE_TEXT =
    'The latest builds, repairs, and conversations from across Looth.';

  // Only act on the Hub and its listing sub-paths. No-op elsewhere.
  function onHubPath() {
    return /^\/hub(\/|$)/.test(location.pathname || '/');
  }


  // Add the hero tagline ONLY on the main activity Hub (title "The Hub"),
  // never on category listing pages (which carry their own label).
  function addTagline() {
    var title = document.querySelector('.feed-page .forum-header__title');
    if (!title || !/^\s*the hub\s*$/i.test(title.textContent || '')) return;
    var body = document.querySelector('.feed-page .forum-header__body');
    if (!body || body.querySelector('.' + TAGLINE_CLASS)) return;
    var span = document.createElement('span');
    span.className = TAGLINE_CLASS;
    span.textContent = TAGLINE_TEXT;
    body.appendChild(span);
  }

  // Mobile (<=960px): move the canonical corner-hamburger (#bb-ham, the filter
  // drawer trigger) into the feed sort bar as a clear "Filters" chip. At the
  // top-left corner it overlaps the shared site-header hamburger (76x76, z:300)
  // and swallows its taps, so the site menu is unreachable on the Hub. Moving
  // the node keeps its click handler intact and frees the site hamburger.
  // Defensive: no-op off-mobile or if either node is missing. Canonical home is
  // _chrome.php; this is a hub-gated client guard until that lands.
  function relocateFilterToggle() {
    if (!window.matchMedia('(max-width:960px)').matches) return;
    var bar = document.querySelector('.feed-sort-bar');
    if (!bar) return;
    // Server-rendered proxy: wire its click to the canonical #bb-ham
    var proxy = bar.querySelector('.lg-filters-chip');
    if (proxy) {
      if (!proxy.getAttribute('data-lg-wired')) {
        proxy.setAttribute('data-lg-wired', '1');
        var hamRef = document.getElementById('bb-ham');
        if (hamRef) proxy.addEventListener('click', function () { hamRef.click(); });
      }
      return;
    }
    // Fallback: move #bb-ham itself into the bar
    var ham = document.getElementById('bb-ham');
    if (!ham || ham.getAttribute('data-lg-relocated')) return;
    ham.setAttribute('data-lg-relocated', '1');
    ham.classList.add('lg-filters-chip');
    if (!ham.querySelector('.lg-filters-chip__tx')) {
      var tx = document.createElement('span');
      tx.className = 'lg-filters-chip__tx';
      tx.textContent = 'Filters';
      ham.appendChild(tx);
    }
    bar.insertBefore(ham, bar.firstChild);
  }

  // ── Desktop filter rail (RETIRED 2026-06-11) ────────────────────────────────
  // setupDesktopFilterNav + the ultrawide auto-open (lg-nav-open / lg-nav-open-wide)
  // targeted the left nav aside, which the hub no longer renders — filters are the
  // canonical centered #hub-fmodal on ALL viewports (Ian 2026-06-11). Retired per
  // coordinator ask; the pref keys are dead.

  // (Popular-tags search dropdown REMOVED 2026-06-10 — Ian: "popular tags
  // removed". ensureTagSugCss + wireDesktopSearchTags retired.)

  // Recompose ONE feed card into the Style-Sandbox card layout: build a top
  // meta row [OP avatar + author . time | category pill] from nodes the live
  // bb-mirror markup scatters (avatar/author sit at the BOTTOM in
  // .feed-card__op-meta; the category is in the TOP breadcrumb), which CSS
  // alone can't reorder across branches. The cover lift + bottom-line slim are
  // CSS. Idempotent (data-lg-card) and wrapped so one bad card can't break the
  // feed.
  // Sandbox action-row icons (inline SVG, no emoji) — thumbs-up / chat / share-box.
  // (Was a heart; Buck 2026-06-11: the Like action applies a 👍 reaction, so the
  // icon is a thumbs-up to match.)
  var ICO_LIKE = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3z"/><path d="M7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"/></svg>';
  var ICO_REPLIES = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.5 8.5 0 0 1-12.3 7.6L3 21l1.9-5.7A8.5 8.5 0 1 1 21 11.5z"/></svg>';
  var ICO_SHARE = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 12v7a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-7"/><path d="M16 6l-4-4-4 4"/><path d="M12 2v13"/></svg>';
  // Instagram-style bookmark — outline normally, fills (currentColor) when saved.
  var ICO_SAVE = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><path d="M6 3h12a1 1 0 0 1 1 1v17l-7-4.5L5 21V4a1 1 0 0 1 1-1z"/></svg>';

  // ── Category icons on feed cards (mobile ≤640) — Buck+Ian meeting 2026-06-08 ──
  // Prepend a small line-SVG glyph to each card's category label so repair /
  // restoration / 3D-printing etc. read at a glance. Keys off the card's
  // server-set data-cat (forum category) + a label-keyword refine. Style matches
  // u.php's section icons (stroke=currentColor) so it inherits the label color and
  // is dark-mode-safe. NO external assets needed; if Jerry's real icon art arrives,
  // swap the path data per key in CAT_ICON_PATHS — nothing else changes.
  var CAT_ICON_PATHS = {
    repair:      '<path d="M14.5 5.3a3.6 3.6 0 0 1 .8 3.9l5 5a2 2 0 0 1-2.8 2.8l-5-5a3.6 3.6 0 0 1-4.6-4.6l2.2 2.2 2-2-2.2-2.2a3.6 3.6 0 0 1 4.6 0z"/><path d="m6.5 14.5-3 3 3 3 3-3"/>',
    builds:      '<path d="M3 21h18"/><path d="M5 21V8l7-4 7 4v13"/><path d="M9 21v-6h6v6"/>',
    tools:       '<path d="M4 7l5 5"/><path d="m9 12 9 9 2-2-9-9"/><path d="M4 7 7 4l4 4-3 3z"/><path d="M14 5l5 5"/>',
    business:    '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M3 12h18"/>',
    market:      '<path d="M4 6h16l-1.3 8.6a2 2 0 0 1-2 1.7H7.3a2 2 0 0 1-2-1.7L4 6z"/><path d="M4 6 3 3H1"/><circle cx="9" cy="20" r="1.2"/><circle cx="17" cy="20" r="1.2"/>',
    acoustic:    '<path d="M9 17V5l10-2v12"/><circle cx="6.5" cy="17" r="2.5"/><circle cx="16.5" cy="15" r="2.5"/>',
    sponsors:    '<circle cx="12" cy="8.5" r="5"/><path d="M9 12.5 7.5 21l4.5-2.6L16.5 21 15 12.5"/>',
    looths:      '<path d="M12 21s7-5.8 7-11a7 7 0 1 0-14 0c0 5.2 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/>',
    suggestions: '<path d="M9.5 19h5"/><path d="M10 22h4"/><path d="M12 2a6 6 0 0 0-3.6 10.8c.6.5 1 1.2 1.1 2h5c.1-.8.5-1.5 1.1-2A6 6 0 0 0 12 2z"/>',
    general:     '<path d="M21 11.5a8.5 8.5 0 0 1-12.3 7.6L3 21l1.9-5.7A8.5 8.5 0 1 1 21 11.5z"/>',
    restoration: '<path d="M12 8v4l2.5 2.5"/><path d="M3.5 12a8.5 8.5 0 1 0 2.2-5.7"/><path d="M3 4v3.5h3.5"/>',
    printing3d:  '<path d="M12 3 4 7v10l8 4 8-4V7z"/><path d="m4 7 8 4 8-4"/><path d="M12 11v10"/>',
    electronics: '<path d="M9 3v4M15 3v4M9 17v4M15 17v4M3 9h4M3 15h4M17 9h4M17 15h4"/><rect x="7" y="7" width="10" height="10" rx="1.5"/>',
    finishing:   '<path d="M5 3h11l3 3v6l-9 1v8a2 2 0 0 1-4 0v-8H5z"/><path d="M5 3v6h11"/>',
    cnc:         '<rect x="3" y="4" width="18" height="12" rx="1.5"/><path d="M7 20h10"/><path d="M9 16v4M15 16v4"/><path d="M8 8h8M8 11h5"/>',
    organisation:'<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18"/><path d="M8 4v16"/>'
  };
  function svgCat(key) {
    var p = CAT_ICON_PATHS[key]; if (!p) return '';
    return '<svg class="lg-cat-ico" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" ' +
           'stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + p + '</svg>';
  }
  function pickCatIcon(catKey, label) {
    var t = (label || '').toLowerCase();
    if (/3d|print/.test(t)) return 'printing3d';
    if (/cnc/.test(t)) return 'cnc';
    if (/restor/.test(t)) return 'restoration';
    if (/finish|paint|spray|lacqu/.test(t)) return 'finishing';
    if (/amp|pickup|pedal|electron|wiring|pre-?amp/.test(t)) return 'electronics';
    if (/organis|organiz|shop org/.test(t)) return 'organisation';
    if (CAT_ICON_PATHS[catKey]) return catKey;
    return 'general';
  }
  function addCategoryIcon(card) {
    try {
      if (!window.matchMedia('(max-width:640px)').matches) return;
      if (!card || card.getAttribute('data-lg-catico')) return;
      var label = card.querySelector('.fc-category.lg-card-cat') || card.querySelector('.lg-card-cat');
      if (!label) return;
      if (label.querySelector('.lg-cat-ico')) { card.setAttribute('data-lg-catico', '1'); return; }
      var catKey = (card.getAttribute('data-cat') || '').trim().toLowerCase();
      var svg = svgCat(pickCatIcon(catKey, (label.textContent || '').trim()));
      if (!svg) { card.setAttribute('data-lg-catico', '1'); return; }
      label.insertAdjacentHTML('afterbegin', svg);
      label.classList.add('lg-has-catico');
      card.setAttribute('data-lg-catico', '1');
    } catch (e) {}
  }

  // Total reply count for a card: prefer the "View N replies" expander text,
  // else fall back to counting rendered reply stubs.
  function replyCount(card) {
    var exp = card.querySelector('.feed-card__expand');
    if (exp) {
      var m = (exp.textContent || '').match(/(\d+)/);
      if (m) return parseInt(m[1], 10);
    }
    return card.querySelectorAll('.reply-stub').length;
  }

  // After the action-row "replies" expands a thread, bb-mirror's native loader
  // shows only the first batch (~5) plus its own ".replies-loadmore" button.
  // Auto-click that button until up to `target` (10) replies are visible; the
  // native button then stays as the "Show more" control for the rest. Bounded,
  // and waits for each AJAX batch to land before clicking again so it can't
  // double-fire. Mobile only (the action row is mobile only). Drives the
  // coordinator's native loader, doesn't replace it.
  function autoLoadReplies(card, target) {
    if (card.__lgAL) return;            // one run per card at a time
    card.__lgAL = true;
    var last = -1, pending = false, tries = 0;
    function stop() { clearInterval(iv); card.__lgAL = false; }
    var iv = setInterval(function () {
      if (++tries > 40) { stop(); return; }
      var full = card.querySelector('.feed-card__replies-full');
      if (!full) return;
      revealReplyImages(full);
      enhanceReplyReactions(full);
      // force every loaded reply visible (native rendering hides some)
      var sl = full.querySelectorAll('.reply-stub');
      for (var q = 0; q < sl.length; q++) { sl[q].classList.remove('lg-rhide'); sl[q].classList.add('lg-rshow'); }
      var stubs = sl.length;
      if (stubs >= target) { stop(); return; }
      var lm = full.querySelector('.replies-loadmore');
      if (!lm) { if (stubs > 0) stop(); return; } // all loaded, or not loaded yet
      if (pending) { if (stubs !== last) { pending = false; last = stubs; } return; }
      last = stubs; pending = true; lm.click();
    }, 200);
  }

  // Make the native "View N replies" expander ALSO auto-unfold to 10 on mobile,
  // so replies unfold the same whether you tap the action-row "replies" or the
  // native button. Idempotent per card; only fires when expanding (not collapsing).
  function wireExpand(card) {
    if (!window.matchMedia('(max-width:640px)').matches) return;
    var exp = card.querySelector('.feed-card__expand');
    if (!exp || exp.getAttribute('data-lg-exp')) return;
    exp.setAttribute('data-lg-exp', '1');
    // Capture phase runs BEFORE the native toggle handler, so the button text is
    // still its pre-click state: "View N replies" = expanding, "Hide replies" =
    // collapsing. autoLoadReplies polls, so it tolerates the AJAX not being done.
    // Capture phase runs before the native toggle. On EXPAND, let the native
    // loader run, then cap the shown replies to 3 with a "View all N replies"
    // button (the "view more"). On collapse, let the native toggle do its thing.
    exp.addEventListener('click', function () {
      if (card.__lgPreviewing) return;
      var txt = exp.textContent || '';
      if (/hide/i.test(txt)) return;                       // collapsing
      var m = txt.match(/(\d+)/); var total = m ? parseInt(m[1], 10) : 0;
      card.__lgPreviewing = true;
      var tries = 0;
      var iv = setInterval(function () {
        if (++tries > 40) { clearInterval(iv); card.__lgPreviewing = false; return; }
        var full = card.querySelector('.feed-card__replies-full');
        if (!full || !full.querySelectorAll('.reply-stub').length) return; // wait for AJAX
        clearInterval(iv);
        revealReplyImages(full);
        enhanceReplyReactions(full);
        capPreview(card, full, exp, total);
      }, 150);
    }, true);
  }

  // NOTE (reactions-surface lane, 2026-06-06): the generic-emoji long-press
  // reaction picker (Buck's `wireReactions` stub: 👍❤️🔥🤘😆😮, no backend) was
  // ripped out. It faked reactions with the wrong glyph set and never persisted.
  // The REAL reaction experience is the comments+reactions engine's BuddyBoss
  // palette picker, which lives ON COMMENTS inside the comments modal
  // (archive-poc comments.php → comment-react.php → discovery.comment_reactions).
  // Feed-card / reply "Like" stays a plain visual toggle — there is no
  // card/reply-level reaction backend to consume. If product wants persisted
  // feed-card or reply reactions, that's an engine contract addition (route to
  // the comments+reactions lane), not a surface stub.

  // Lightweight transient toast (bottom-center, above the tab bar). Reused by
  // Share (copy-link confirmation) and any future optimistic action. Idempotent
  // singleton; auto-dismisses.
  var lgToastEl = null, lgToastT = null;
  function lgToast(msg) {
    try {
      if (!lgToastEl) {
        if (!document.getElementById('lg-toast-css')) {
          var st = document.createElement('style'); st.id = 'lg-toast-css';
          st.textContent =
            '.lg-toast{position:fixed;left:50%;bottom:84px;transform:translate(-50%,16px);' +
            'background:#1a1d1a;color:#fff;font:600 13.5px/1.2 var(--lg-font-sans,system-ui,sans-serif);' +
            'padding:11px 18px;border-radius:999px;z-index:9999;opacity:0;pointer-events:none;' +
            'box-shadow:0 8px 24px -8px rgba(0,0,0,.5);transition:opacity .2s,transform .2s;max-width:80vw;text-align:center}' +
            '.lg-toast.is-show{opacity:1;transform:translate(-50%,0)}';
          document.head.appendChild(st);
        }
        lgToastEl = document.createElement('div');
        lgToastEl.className = 'lg-toast';
        lgToastEl.setAttribute('role', 'status');
        document.body.appendChild(lgToastEl);
      }
      lgToastEl.textContent = msg;
      lgToastEl.classList.add('is-show');
      clearTimeout(lgToastT);
      lgToastT = setTimeout(function () { lgToastEl.classList.remove('is-show'); }, 1900);
    } catch (e) {}
  }

  // Build the sandbox Like / N replies / Share row and insert it before the
  // replies block (after the cover+title+excerpt). "Replies" expands the inline
  // replies; Share uses the Web Share API (falls back to copy link); Like is a
  // visual toggle only (no reactions backend yet — coordinator lane). Idempotent.
  // ── Tap-to-read clamped bodies that have NO Read-more (Buck bug 2026-06-08) ──
  // forums.css clamps .feed-card__op-excerpt to -webkit-line-clamp:6, expecting a
  // "Read more" button to reveal the rest. But _feed.php only renders that button
  // when the body is TEXT-longer than the 440-char snippet — a body under 440 chars
  // that still wraps past 6 lines gets clamped with "…" and NO way to expand. The
  // full text is already in the DOM (just CSS-hidden), so we add a synthetic
  // "Read more" that unclamps it. Mobile only; skipped when a native read-more exists.
  function ensureUnclampCss() {
    if (document.getElementById('lg-unclamp-css')) return;
    var s = document.createElement('style'); s.id = 'lg-unclamp-css';
    s.textContent = [
      '@media (max-width:640px){',
      '.feed-page .feed-card__op-excerpt.lg-unclamp,.feed-page .fc-excerpt.lg-unclamp .feed-card__op-excerpt{',
      '-webkit-line-clamp:unset!important;display:block!important;overflow:visible!important;max-height:none!important}',
      '.feed-page .lg-rm-syn{display:block;width:100%;text-align:left;background:none;border:0;cursor:pointer;',
      'padding:6px 0 2px;margin:0;font:600 13px/1.3 var(--lg-font-sans,system-ui,-apple-system,"Segoe UI",sans-serif);color:var(--lguser-accent-d,#52613d)}',
      '}'
    ].join('\n');
    (document.head || document.documentElement).appendChild(s);
  }
  // ── Long-author-name blowout fix (Buck bug 2026-06-11) ──────────────────────
  // forums.css gives .reply-stub__author white-space:nowrap + flex-shrink:0 for
  // its native HEAD-row ellipsis. fbStyleReply moves that same node INTO the
  // bubble as the block .lg-fb-name line, where the un-wrappable name drives the
  // bubble/col to max-content width (e.g. 618px at a 390px viewport for
  // "Dave Staudte (rhymms with ...) NB Guitar Repair (...)"), shoving the comment
  // text and photo off the screen edge. Let the name wrap once it lives in a
  // bubble, and clamp the column + any raw content <img> as a backstop. Applies
  // to the inline feed replies AND the discussion sheet (the base nowrap rule is
  // unscoped, so #looth-rep-sheet inherits the same bug).
  function ensureReplyNameWrapCss() {
    if (document.getElementById('lg-fbname-wrap-css')) return;
    var s = document.createElement('style'); s.id = 'lg-fbname-wrap-css';
    s.textContent =
      '.feed-page .lg-fb-col,#looth-rep-sheet .lg-fb-col{max-width:100%}' +
      '.feed-page .lg-fb-bubble .lg-fb-name,#looth-rep-sheet .lg-fb-bubble .lg-fb-name{' +
      'white-space:normal;overflow-wrap:anywhere}' +
      '.feed-page .lg-fb-bubble img,#looth-rep-sheet .lg-fb-bubble img{max-width:100%;height:auto}';
    (document.head || document.documentElement).appendChild(s);
  }

  // Register a card; the clamp check is DEFERRED to when the card scrolls into view.
  // (Measuring scrollHeight>clientHeight at build time is unreliable for cards still
  // below the fold — they lay out later and were wrongly read as "not clamped", so
  // far-down posts never got the button = Buck's "works on some, not that exact post".)
  var excerptIO = null;
  function ensureExcerptReadMore(card) {
    if (!window.matchMedia('(max-width:640px)').matches) return;
    if (!card || card.getAttribute('data-lg-exrm')) return;
    if (card.querySelector('.feed-card__read-more:not(.lg-rm-syn)')) return;  // native read-more owns it
    if (!card.querySelector('.feed-card__op-excerpt')) return;                // nothing to expand
    card.setAttribute('data-lg-exrm', '1');                                   // registered (button added on view)
    if (!('IntersectionObserver' in window)) { evalExcerptClamp(card); return; }
    if (!excerptIO) excerptIO = new IntersectionObserver(function (ents) {
      ents.forEach(function (en) { if (en.isIntersecting) { evalExcerptClamp(en.target); excerptIO.unobserve(en.target); } });
    }, { threshold: 0.01 });
    excerptIO.observe(card);
  }
  function evalExcerptClamp(card) {
    if (card.querySelector('.lg-rm-syn')) return;
    if (card.querySelector('.feed-card__read-more:not(.lg-rm-syn)')) return;
    var ex = card.querySelector('.feed-card__op-excerpt'); if (!ex) return;
    if (ex.scrollHeight <= ex.clientHeight + 3) return;   // genuinely not clamped (now laid out + in view)
    ensureUnclampCss();
    var btn = document.createElement('button');
    btn.type = 'button'; btn.className = 'feed-card__read-more lg-rm-syn'; btn.setAttribute('data-state', 'collapsed');
    btn.textContent = 'Read more ▾';
    (ex.parentNode || card).insertBefore(btn, ex.nextSibling);
    btn.addEventListener('click', function (e) {
      e.preventDefault(); e.stopPropagation();
      var open = ex.classList.toggle('lg-unclamp');
      btn.textContent = open ? 'Show less ▴' : 'Read more ▾';
      btn.setAttribute('data-state', open ? 'expanded' : 'collapsed');
    });
  }

  function buildActions(card) {
    // Server-rendered path (_feed.php feed_action_bar): the bar already ships with
    // the card — JS only WIRES it, never rebuilds it (mirrors relayCard's
    // data-lg-card guard, kills the post-paint pop-in). Fallback below builds the
    // row for any legacy/dynamic card that lacks one.
    addCategoryIcon(card);
    ensureExcerptReadMore(card);
    var row = card.querySelector('.lg-card-actions');
    if (!row) {
      var n = replyCount(card);
      var label = n === 0 ? 'Reply' : (n === 1 ? '1 reply' : n + ' replies');
      row = document.createElement('div');
      row.className = 'feed-card__actions lg-card-actions';
      row.innerHTML =
        '<span class="lg-act lg-act-like" role="button" tabindex="0">' + ICO_LIKE + 'Like</span>' +
        '<span class="lg-act lg-act-replies" role="button" tabindex="0">' + ICO_REPLIES + label + '</span>' +
        '<span class="lg-act lg-act-share" role="button" tabindex="0">' + ICO_SHARE + 'Share</span>';
      var replies = card.querySelector('.feed-card__replies');
      var header = card.querySelector('.feed-card__header');
      if (replies) replies.parentNode.insertBefore(row, replies);
      else if (header && header.nextSibling) header.parentNode.insertBefore(row, header.nextSibling);
      else if (header) header.parentNode.appendChild(row);
      else card.appendChild(row);
    }
    wireActions(card, row);
  }

  // Attach handlers to an action row (server-rendered or built). Idempotent via
  // data-lg-wired so it's safe to call on every relay pass / re-wire.
  function wireActions(card, row) {
    if (!row || row.getAttribute('data-lg-wired')) return;
    row.setAttribute('data-lg-wired', '1');
    var like = row.querySelector('.lg-act-like');
    if (like) {
      // server-rendered bars still ship the old HEART — swap it for the
      // thumbs-up so the icon matches the 👍 reaction Like applies
      var lic = like.querySelector('svg.ico, svg');
      if (lic) lic.outerHTML = ICO_LIKE;
      like.addEventListener('click', function () { like.classList.toggle('is-on'); });
    }
    var rep = row.querySelector('.lg-act-replies');
    if (rep) rep.addEventListener('click', function (e) {
      // A Reply tap must NEVER fall through to a legacy-page navigation (smoke-test
      // audit 2026-06-11, holes H1–H3): claim the event FIRST, then pick the best
      // in-place surface per viewport. Mobile discussion → the modal, thread first
      // (Buck: "one intuitive button"); mobile content → FB comments, else the
      // content sheet (anon cards have no comments button). Desktop keeps the
      // native inline surfaces; topic last-resort = a title click, which opens the
      // fork's §4e discussion modal instead of navigating.
      if (e) { e.preventDefault(); e.stopPropagation(); }
      var isTopic = !!card.getAttribute('data-topic-id');
      if (window.matchMedia('(max-width:640px)').matches) {
        if (isTopic) { openRepliesSheet(card, { toReplies: true }); return; }
        var cmr = card.querySelector('[data-comments], .feed-card__comments-btn');
        if (cmr) { cmr.click(); return; }
        var cl = card.querySelector('.fc-title a, .feed-card__title a');
        var ct = card.querySelector('.fc-title, .feed-card__title');
        openContentSheet((cl && cl.href) || card.getAttribute('data-href'),
          ct ? (ct.textContent || '').trim() : '');
        return;
      }
      var exp = card.querySelector('.feed-card__expand');
      if (exp) { exp.click(); return; }                          // has replies → expand inline
      var cta = card.querySelector('.feed-card__reply-cta');
      if (cta) { cta.click(); return; }                          // forum topic → reply composer
      var cm = card.querySelector('.feed-card__comments-btn');
      if (cm) { cm.click(); return; }                            // content card (article/loothprint) → comments
      var t = card.querySelector('.fc-title, .feed-card__title');
      if (isTopic && t) {                                        // topic last-resort → §4e desktop modal
        t.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, view: window }));
        return;
      }
      var l = card.querySelector('.feed-card__title a');         // desktop content w/o any surface →
      var href = (l && l.getAttribute('href')) || card.getAttribute('data-href');
      if (href) location.href = href;                            // post page (canonical: CPTs click through)
    });
    var share = row.querySelector('.lg-act-share');
    if (share) share.addEventListener('click', function () {
      var link = card.querySelector('.feed-card__title a');
      var url = link ? link.href : location.href;
      var title = link ? (link.textContent || '').trim() : 'Looth Hub';
      function legacyCopy() {
        try {
          var ta = document.createElement('textarea');
          ta.value = url; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
          document.body.appendChild(ta); ta.select(); ta.setSelectionRange(0, url.length);
          var ok = document.execCommand('copy'); document.body.removeChild(ta); return ok;
        } catch (e) { return false; }
      }
      function copyLink() {
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(url).then(
            function () { lgToast('Link copied'); },
            function () { lgToast(legacyCopy() ? 'Link copied' : 'Couldn’t copy link'); }
          );
        } else { lgToast(legacyCopy() ? 'Link copied' : 'Couldn’t copy link'); }
      }
      try {
        if (navigator.share) {
          navigator.share({ title: title, url: url }).catch(function (err) {
            if (err && err.name === 'AbortError') return;        // user dismissed the native sheet — no toast
            copyLink();                                          // sheet failed/unavailable → copy + confirm
          });
        } else { copyLink(); }
      } catch (e) { copyLink(); }
    });
    // Instagram-style SAVE bookmark (Buck 2026-06-08), right-aligned. Persists to the
    // ACCOUNT via the CANONICAL archive-poc save API (discovery.saved_posts) so mobile
    // + desktop share ONE store — wired per the coordinator's contract (b820532).
    if (!row.querySelector('.lg-act-save')) {
      var sv = document.createElement('span');
      sv.className = 'lg-act lg-act-save'; sv.setAttribute('role', 'button'); sv.setAttribute('tabindex', '0');
      sv.setAttribute('aria-label', 'Save'); sv.innerHTML = ICO_SAVE;
      row.appendChild(sv);
      var sref = cardSaveRef(card);
      if (sref && lgSavedSet[sref.key]) sv.classList.add('is-on');
      sv.addEventListener('click', function (e) { e.stopPropagation(); lgToggleSave(card, sv); });
    }
  }
  // Resolve a card to the canonical {post_type,item_id} the save API keys on. Content
  // cards carry data-post-type + data-item-id; discussion/topic cards carry
  // data-topic-id (post_type 'topic'). null if neither (not savable).
  function cardSaveRef(card) {
    var pt = card.getAttribute('data-post-type'), id = card.getAttribute('data-item-id');
    if (!pt || !id) {
      // The fork's pared card markup (2026-06-10) moved the save contract off the
      // card root onto inner carriers (.fc-save / .fcr / comments btn) — Vanessa's
      // "save icon won't toggle": the root lookup came up empty so we bailed.
      var car = card.querySelector('[data-post-type][data-item-id]');
      if (car) { pt = car.getAttribute('data-post-type'); id = car.getAttribute('data-item-id'); }
    }
    if ((!pt || !id) && card.getAttribute('data-topic-id')) { pt = 'topic'; id = card.getAttribute('data-topic-id'); }
    if (!pt || !id) return null;
    id = parseInt(id, 10); if (!id) return null;
    return { pt: pt, id: id, key: pt + ':' + id };
  }
  function postUrl(card) {
    var l = card.querySelector('.feed-card__title a');
    return (l && l.getAttribute('href')) || card.getAttribute('data-href') || '';
  }
  // Canonical save API: GET /archive-api/v0/save-post returns a WP nonce + saved-state;
  // POST toggles; GET /archive-api/v0/my-saved is the render-ready list. Cache the nonce.
  var lgSaveNonce = null, lgSavedSet = {}, lgSavedItems = [];
  function lgGetNonce(cb) {
    if (lgSaveNonce) { cb(lgSaveNonce); return; }
    fetch('/archive-api/v0/save-post', { credentials: 'include' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) { lgSaveNonce = (d && d.nonce) || null; cb(lgSaveNonce); })
      .catch(function () { cb(null); });
  }
  function lgToggleSave(card, btn) {
    var ref = cardSaveRef(card); if (!ref) return;
    var saving = !btn.classList.contains('is-on');
    btn.classList.toggle('is-on', saving);                 // optimistic
    lgSavedSet[ref.key] = saving ? 1 : 0;
    if (typeof lgToast === 'function') lgToast(saving ? 'Saved' : 'Removed from saved');
    lgGetNonce(function (nonce) {
      if (!nonce) return;                                  // not logged in → can't persist
      fetch('/archive-api/v0/save-post', {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
        body: JSON.stringify({ post_type: ref.pt, item_id: ref.id, _wpnonce: nonce, unsave: !saving })
      }).then(function (r) { return r.ok ? r.json() : null; }).then(function (d) {
        if (d && typeof d.saved === 'boolean') { btn.classList.toggle('is-on', d.saved); lgSavedSet[ref.key] = d.saved ? 1 : 0; }
        try { window.dispatchEvent(new Event('lg-saved-changed')); } catch (e) {}
      }).catch(function () {});
    });
  }
  // On load, pull the account's saved list (render-ready) so bookmark fill-state +
  // the Saved view are correct. Anon → {authenticated:false}; leave buttons off.
  function lgSyncSaved(cb) {
    fetch('/archive-api/v0/my-saved', { credentials: 'include' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (d && d.authenticated && Array.isArray(d.items)) {
          lgSavedItems = d.items;
          var set = {}; d.items.forEach(function (it) { set[it.post_type + ':' + it.item_id] = 1; });
          lgSavedSet = set;
          document.querySelectorAll('.lg-act-save').forEach(function (b) {
            var card = b.closest('.feed-card'); if (!card) return;
            var ref = cardSaveRef(card); if (ref) b.classList.toggle('is-on', !!set[ref.key]);
          });
        }
        if (cb) cb();
      })
      .catch(function () { if (cb) cb(); });
  }

  // Reshape the feed sort bar toward the sandbox: clone the hero "+ New post"
  // button into the bar (wired to the original's click) so MOBILE shows it in
  // the bar while DESKTOP keeps the hero button. Pill-tab styling + Filters/New
  // post on the right + hidden inline search are CSS, mobile-only. Idempotent.
  function restyleSortBar() {
    try {
      var bar = document.querySelector('.feed-sort-bar');
      if (!bar || bar.getAttribute('data-lg-bar')) return;
      bar.setAttribute('data-lg-bar', '1');
      var hero = document.querySelector('.forum-header__new-post');
      if (hero && !bar.querySelector('.lg-newpost')) {
        var clone = hero.cloneNode(true);
        clone.classList.add('lg-newpost');
        clone.removeAttribute('id');
        clone.addEventListener('click', function (e) { e.preventDefault(); hero.click(); });
        bar.appendChild(clone);
      }
    } catch (e) {}
  }
  // Saved pill NEXT TO Trending (Buck 2026-06-10) — nav-only entry to the
  // canonical server-rendered ?saved=1 view. Mobile only (canonical owns the
  // desktop bar per the bespoke-cutover lane wall). Own idempotent pass with
  // delayed retries — the bar's tabs can land after the first run() pass.
  // The drawer/rail "Saved posts" entry is hidden (moved here); You-menu link stays.
  function ensureSavedPill() {
    try {
      if (!window.matchMedia('(max-width:640px)').matches) return;
      var bar = document.querySelector('.feed-sort-bar');
      if (!bar || bar.querySelector('.lg-saved-pill')) return;
      var tabs = bar.querySelectorAll('a'), trend = null;
      for (var ti = 0; ti < tabs.length; ti++) {
        if (/trending/i.test((tabs[ti].textContent || '').trim())) { trend = tabs[ti]; break; }
      }
      if (!trend) return;                                   // tabs not in yet — retry will catch it
      var onSaved = /[?&]saved=1/.test(location.search);
      var sp = document.createElement('a');
      var base = trend.className.replace(/\b(active|is-active)\b/g, '').replace(/\s+/g, ' ').trim();
      sp.className = (base + ' lg-saved-pill' + (onSaved ? ' active is-active' : '')).trim();
      sp.href = onSaved ? location.pathname : (location.pathname + '?saved=1');
      sp.textContent = 'Saved';
      trend.parentNode.insertBefore(sp, trend.nextSibling);
      var olds = document.querySelectorAll('a[href*="saved=1"]:not(.lg-saved-pill)');
      for (var oi = 0; oi < olds.length; oi++) {
        if (olds[oi].closest('.feed-sort-bar') || olds[oi].closest('#looth-sheet')) continue;
        var row = olds[oi].closest('li, .hub-rail__row, .hub-rail__item') || olds[oi];
        row.style.display = 'none';
      }
    } catch (e) {}
  }
  // "Saved" pill + client-rendered saved view RETIRED 2026-06-10 (bespoke-cutover,
  // audit C5): superseded by the canonical server-side Saved view — ?saved=1
  // constrains the feed union in _feed.php (9bcf24e) and the rail "Saved posts"
  // toggle (+ the You-menu link) is the entry point. lgToggleSave/lgSyncSaved
  // above STAY: they wire + state-sync the per-card bookmark buttons.

  // Tap the post text (clamped excerpt OR expanded full body) to toggle the
  // canonical "Read more" expander. Mobile only (we hide the Read-more button
  // there to match the sandbox), so desktop behavior is unchanged. Clicks on
  // links inside the text are left alone. Idempotent per node.
  function wireTextToggle(card) {
    if (!window.matchMedia('(max-width:640px)').matches) return;
    var rm = card.querySelector('.feed-card__read-more');
    if (!rm) return;
    var targets = [
      card.querySelector('.feed-card__op-excerpt'),
      card.querySelector('.feed-card__full-body')
    ];
    for (var i = 0; i < targets.length; i++) {
      var el = targets[i];
      if (!el || el.getAttribute('data-lg-txt')) continue;
      el.setAttribute('data-lg-txt', '1');
      el.style.cursor = 'pointer';
      el.addEventListener('click', function (ev) {
        if (ev.target.closest && ev.target.closest('a')) return;
        // The whole feed card has a click-to-open-thread handler; stop the
        // excerpt tap from bubbling to it so we expand inline instead of
        // navigating away.
        ev.stopPropagation();
        rm.click();
      });
    }
  }

  function relayCard(card) {
    try {
      if (!card) return;
      var metaTop = card.querySelector('.feed-card__meta-top');
      if (!metaTop) return;
      // Server-rendered: skip DOM recompose, just wire behaviors
      if (card.getAttribute('data-lg-card')) {
        buildActions(card);
        wireTextToggle(card);
        wireExpand(card);
        return;
      }
      card.setAttribute('data-lg-card', '1');

      var ctxForum = card.querySelector('.feed-card__ctx-forum');
      var ctxParent = card.querySelector('.feed-card__ctx-parent');
      var catText = (ctxForum && ctxForum.textContent.trim()) ||
        (ctxParent && ctxParent.textContent.trim()) || '';
      var timeNode = metaTop.querySelector('.feed-card__time');
      var timeText = timeNode ? timeNode.textContent.trim() : '';
      var opMeta = card.querySelector('.feed-card__op-meta');
      var avatar = opMeta && opMeta.querySelector('.avatar-init');
      var author = card.querySelector('.feed-card__op-author');

      var frag = document.createDocumentFragment();
      if (avatar) {
        var av = avatar.cloneNode(true);
        av.classList.add('lg-card-avatar');
        frag.appendChild(av);
      }
      var idLine = document.createElement('span');
      idLine.className = 'lg-card-id';
      if (author) {
        var a2 = author.cloneNode(true);
        a2.className = 'lg-card-author';
        idLine.appendChild(a2);
      }
      frag.appendChild(idLine);
      if (timeText) {
        var ts = document.createElement('span');
        ts.className = 'lg-card-time';
        ts.textContent = timeText;
        frag.appendChild(ts);
      }
      if (catText) {
        var cat = document.createElement('span');
        cat.className = 'feed-card__kind-badge lg-card-cat';
        cat.textContent = catText;
        frag.appendChild(cat);
      }
      metaTop.textContent = '';
      metaTop.appendChild(frag);
      buildActions(card);
      wireTextToggle(card);
      wireExpand(card);
    } catch (e) { /* never let one card break the feed */ }
  }

  function relayCards(root) {
    var scope = (root && root.querySelectorAll) ? root : document;
    var cards = scope.querySelectorAll('.feed-card:not([data-lg-card])');
    for (var i = 0; i < cards.length; i++) relayCard(cards[i]);
  }

  // Reply photos ship deferred: the canonical markup hides them behind a
  // ".reply-stub__img-open" ("Show image") button with the real URL in the
  // image's data-src. Buck wants reply photos shown, so auto-trigger that native
  // reveal for every VISIBLE reply (the button's own handler sets src from
  // data-src) and hide the now-redundant button (which also carries an emoji we
  // don't want). Hidden overflow stubs are left for when they become visible.
  // Idempotent via data-lg-shown.
  function revealReplyImages(scope) {
    var root = (scope && scope.querySelectorAll) ? scope : document;
    var btns = root.querySelectorAll('.reply-stub__img-open:not([data-lg-shown])');
    for (var i = 0; i < btns.length; i++) {
      var b = btns[i];
      var stub = b.closest ? b.closest('.reply-stub') : null;
      // skip only replies we explicitly hide past the cap; reveal everything else
      // (the native code may have them display:none until we force-show them)
      if (stub && stub.classList.contains('lg-rhide')) continue;
      b.setAttribute('data-lg-shown', '1');
      try { b.click(); } catch (e) {}
      b.style.display = 'none';
    }
  }

  // Recompose each loaded reply Facebook-style with a plain Like toggle (no
  // reaction picker — see the wireReactions rip-out note above).
  function enhanceReplyReactions(scope) {
    var root = (scope && scope.querySelectorAll) ? scope : document;
    var stubs = root.querySelectorAll('.reply-stub:not([data-lg-fb])');
    for (var i = 0; i < stubs.length; i++) fbStyleReply(stubs[i]);
  }

  // Recompose one reply into Facebook-style: avatar on the left, a rounded
  // bubble with the bold name + comment text, then a Like / Reply / time action
  // row beneath. Like: tap to like, long-press for the reaction picker. Reply:
  // opens an inline reply box. Idempotent; guarded so one bad reply can't break
  // the feed.
  function fbStyleReply(stub) {
    try {
      if (!stub || stub.getAttribute('data-lg-fb')) return;
      var head = stub.querySelector('.reply-stub__head');
      var body = stub.querySelector('.reply-stub__body');
      if (!head || !body) return;
      stub.setAttribute('data-lg-fb', '1');
      var avatar = head.querySelector('.avatar-init, .avatar-init--img');
      var author = head.querySelector('.reply-stub__author');
      var time = head.querySelector('.reply-stub__time');
      // Capture the native reply button's reply-to id + author (for nested posting
      // and the composer's "Replying to:" pill) before we drop the head, since
      // canonical carries them there.
      var nativeReply = head.querySelector('.reply-stub__reply');
      if (nativeReply && nativeReply.dataset.replyTo) stub.setAttribute('data-lg-replyto', nativeReply.dataset.replyTo);
      if (nativeReply && nativeReply.dataset.replyToAuthor) stub.setAttribute('data-lg-replyto-author', nativeReply.dataset.replyToAuthor);
      if (nativeReply && nativeReply.dataset.replyToSlug) stub.setAttribute('data-lg-replyto-slug', nativeReply.dataset.replyToSlug);

      var col = document.createElement('div'); col.className = 'lg-fb-col';
      var bubble = document.createElement('div'); bubble.className = 'lg-fb-bubble';
      if (author) { author.classList.add('lg-fb-name'); bubble.appendChild(author); }
      bubble.appendChild(body);                 // text + images move intact
      col.appendChild(bubble);

      var actions = document.createElement('div'); actions.className = 'lg-fb-actions';
      var like = document.createElement('span'); like.className = 'lg-fb-act lg-fb-like'; like.setAttribute('role', 'button'); like.textContent = 'Like';
      var reply = document.createElement('span'); reply.className = 'lg-fb-act lg-fb-reply'; reply.setAttribute('role', 'button'); reply.textContent = 'Reply';
      actions.appendChild(like);
      actions.appendChild(reply);
      if (time) { time.classList.add('lg-fb-time'); actions.appendChild(time); }
      // Keep the server-rendered moderation controls (pencil/trash, revealed under
      // .feed--can-moderate + wired by wireModalModeration) — they live in the head
      // we are about to drop, so move them into the actions row (Buck 2026-06-10).
      // Keep the (server-rendered) pencil/trash in the DOM for their
      // data-reply-id/raw, but HIDE them — present ONE "Edit" button that opens an
      // Edit/Delete popup like desktop (Ian 2026-06-25). Revealed under
      // .feed--can-moderate by wireModalModeration (own reply OR mod).
      var modBtns = head.querySelectorAll('.reply-stub__edit, .reply-stub__trash');
      for (var mb = 0; mb < modBtns.length; mb++) { modBtns[mb].classList.add('lg-fb-modbtn--hide'); actions.appendChild(modBtns[mb]); }
      if (modBtns.length) {
        var more = document.createElement('span');
        more.className = 'lg-fb-act lg-fb-more';
        more.setAttribute('role', 'button');
        more.textContent = 'Edit';
        actions.appendChild(more);
      }
      col.appendChild(actions);

      stub.insertBefore(col, head);
      if (avatar) stub.insertBefore(avatar, col);
      if (head.parentNode) head.remove();

      like.addEventListener('click', function () { like.classList.toggle('is-on'); });
      reply.addEventListener('click', function () { openReplyComposer(stub, author, col); });

      revealReplyImages(stub);
    } catch (e) {}
  }

  // Pull the signed-in member's avatar (shared header) for the reply box.
  function myAvatarSrc() {
    var img = document.querySelector('.lg-chrome__avatar img, .lg-chrome__account img');
    return img && (img.currentSrc || img.getAttribute('src')) || '';
  }

  // Reply to a COMMENT (Buck 2026-06-12): open the same pull-up composer sheet
  // used for OP replies, with the context pill naming the comment's author, and
  // post with reply_to so the reply nests under its parent. The old inline
  // @-box (openReplyBox) stays as the fallback for stubs without a reply id
  // (e.g. optimistic inserts) and any non-mobile edge.
  function openReplyComposer(stub, author, col) {
    var rid = parseInt(stub.getAttribute('data-lg-replyto') || '0', 10);
    var aname = (stub.getAttribute('data-lg-replyto-author') || (author && author.textContent) || '').trim();
    if (!rid || !window.matchMedia('(max-width:640px)').matches) { openReplyBox(col, author, stub); return; }
    var sheet = stub.closest('#looth-rep-sheet');
    if (sheet) {
      openComposerSheet({
        tid: sheet.getAttribute('data-tid'), fid: sheet.getAttribute('data-fid'),
        replyTo: rid, replyToName: aname, focus: true
      });
      return;
    }
    // Feed-card teaser reply: open the discussion sheet behind the composer (both
    // synchronously within the tap so iOS shows the keyboard) — the post-success
    // thread reload then shows the new reply nested in place.
    var card = stub.closest('.feed-card');
    if (card) {
      openRepliesSheet(card, { toReplies: true });
      var sh = document.getElementById('looth-rep-sheet');
      openComposerSheet({
        tid: sh && sh.getAttribute('data-tid'), fid: sh && sh.getAttribute('data-fid'),
        replyTo: rid, replyToName: aname, focus: true
      });
      return;
    }
    openReplyBox(col, author, stub);
  }

  // Inline reply box (Facebook-style) under a comment, @mentioning its author.
  // Submit posts via submitReply(). Idempotent per column.
  function openReplyBox(col, author, stub) {
    var existing = col.querySelector('.lg-fb-replybox');
    if (existing) { var t0 = existing.querySelector('textarea'); if (t0) t0.focus(); return; }
    var box = document.createElement('div'); box.className = 'lg-fb-replybox';
    var avi = myAvatarSrc();
    var aviEl = document.createElement('span'); aviEl.className = 'lg-fb-myavi';
    if (avi) aviEl.innerHTML = '<img src="' + avi + '" alt="">';
    var wrap = document.createElement('div'); wrap.className = 'lg-fb-replywrap';
    var ta = document.createElement('textarea'); ta.className = 'lg-fb-replyinput'; ta.rows = 1; ta.placeholder = 'Write a reply…';
    var name = author ? (author.textContent || '').trim().split(/[\s,]/)[0] : '';
    if (name) ta.value = '@' + name + ' ';
    var send = document.createElement('button'); send.type = 'button'; send.className = 'lg-fb-send'; send.textContent = 'Post';
    send.disabled = false;
    wrap.appendChild(ta); wrap.appendChild(send);
    box.appendChild(aviEl); box.appendChild(wrap);
    col.appendChild(box);
    ta.focus();
    try { ta.setSelectionRange(ta.value.length, ta.value.length); } catch (e) {}
    ta.addEventListener('input', function () { ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight, 120) + 'px'; });
    send.addEventListener('click', function () { submitReply(ta.value, box, send, stub); });
  }

  // Post the reply via the canonical BuddyBoss flow: lazily fetch the auth nonce
  // from /bb-mirror-api/v0/auth.php, then POST to /wp-json/buddyboss/v1/reply
  // with topic/forum (from the card's reply CTA) and reply_to (this reply's id,
  // for nesting). On success, optimistically show the new comment.
  function submitReply(text, box, send, stub) {
    text = (text || '').trim();
    if (!text) return;
    send.disabled = true;
    var note = box.querySelector('.lg-fb-note') || document.createElement('div');
    note.className = 'lg-fb-note'; note.textContent = 'Posting…';
    if (!note.parentNode) box.appendChild(note);

    var card = stub.closest('.feed-card');
    var cta = card && card.querySelector('.feed-card__reply-cta[data-frm-open]');
    // Inside #looth-rep-sheet (the mobile discussion modal) the thread is fetched
    // fresh — no .feed-card ancestor — so source the ids off the sheet itself.
    var sheet = !card && stub.closest('#looth-rep-sheet');
    var topicId = parseInt((cta && cta.dataset.topicId) || (card && card.dataset.topicId) || (sheet && sheet.getAttribute('data-tid')) || '', 10);
    var forumId = parseInt((cta && cta.dataset.forumId) || (sheet && sheet.getAttribute('data-fid')) || '', 10);
    var replyTo = parseInt(stub.getAttribute('data-lg-replyto') || '0', 10);
    var myName = 'You';

    fetch('/bb-mirror-api/v0/auth.php', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.authenticated) throw new Error('Sign in to reply.');
        myName = d.display_name || 'You';
        var payload = { topic_id: topicId, forum_id: forumId, content: text };
        if (replyTo) payload.reply_to = replyTo;
        return fetch('/wp-json/buddyboss/v1/reply', {
          method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': d.nonce },
          body: JSON.stringify(payload)
        });
      })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        if (!res.ok) throw new Error((res.j && (res.j.message || res.j.code)) || 'Could not post.');
        appendOptimisticReply(stub, myName, text);
        box.parentNode && box.remove();
      })
      .catch(function (e) {
        note.textContent = (e && e.message) || 'Could not post.';
        send.disabled = false;
      });
  }

  // Show the just-posted reply immediately (the real one nests on next load).
  function appendOptimisticReply(stub, name, text) {
    var full = stub.closest('.feed-card__replies-full') || stub.parentNode;
    if (!full) return;
    var el = document.createElement('div');
    el.className = 'reply-stub lg-rshow';
    el.setAttribute('data-lg-fb', '1');
    var avi = myAvatarSrc();
    el.innerHTML =
      '<span class="avatar-init avatar-init--img">' + (avi ? '<img src="' + avi + '" alt="">' : '') + '</span>' +
      '<div class="lg-fb-col"><div class="lg-fb-bubble">' +
      '<span class="lg-fb-name"></span><span class="reply-stub__excerpt"></span></div>' +
      '<div class="lg-fb-actions"><span class="lg-fb-act lg-fb-like" role="button">Like</span>' +
      '<span class="lg-fb-act lg-fb-reply" role="button">Reply</span><span class="lg-fb-time">now</span></div></div>';
    el.querySelector('.lg-fb-name').textContent = name;
    el.querySelector('.reply-stub__excerpt').textContent = text;
    if (stub.nextSibling) full.insertBefore(el, stub.nextSibling); else full.appendChild(el);
    var like = el.querySelector('.lg-fb-like');
    like.addEventListener('click', function () { like.classList.toggle('is-on'); });
    var rep = el.querySelector('.lg-fb-reply');
    rep.addEventListener('click', function () { openReplyBox(el.querySelector('.lg-fb-col'), el.querySelector('.lg-fb-name'), el); });
  }

  // Mobile only: watch the feed subtree for replies loading in (native expand,
  // loadmore, or our autoLoadReplies) and reveal their photos. Desktop keeps the
  // native click-to-reveal button untouched.
  var replyImgObserver = null;
  function watchReplyImages() {
    if (!window.matchMedia('(max-width:640px)').matches) return;
    var first = document.querySelector('.feed-card');
    var host = first && first.parentElement;
    if (!host || replyImgObserver) return;
    revealReplyImages(host);
    enhanceReplyReactions(host);
    replyImgObserver = new MutationObserver(function () { revealReplyImages(host); enhanceReplyReactions(host); });
    replyImgObserver.observe(host, { childList: true, subtree: true });
  }

  // Inline reply preview: as a card scrolls near the viewport, lazily load its
  // replies and show the first 3 on the feed, with "View all N replies" to
  // expand the rest. Mobile only; one load per card. Reuses the native loader.
  function previewReplies(card) {
    if (card.__lgPrev) return;
    card.__lgPrev = true;
    var exp = card.querySelector('.feed-card__expand');
    if (!exp) return;                                 // no replies on this card
    if (/hide/i.test(exp.textContent || '')) return;  // already expanded by the user
    var total = replyCount(card);                      // count while button still says "View N"
    card.__lgPreviewing = true;                        // tell wireExpand not to auto-load to 10
    exp.click();                                       // native: load + show the first batch
    var tries = 0;
    var iv = setInterval(function () {
      if (++tries > 40) { clearInterval(iv); card.__lgPreviewing = false; return; }
      var full = card.querySelector('.feed-card__replies-full');
      if (!full || !full.querySelectorAll('.reply-stub').length) return; // wait for AJAX
      clearInterval(iv);
      capPreview(card, full, exp, total);
    }, 150);
  }

  // Collapse the just-loaded batch to a ONE-reply teaser (always show at least
  // one reply, with its photo) + a "Show all N replies" button that loads EVERY
  // reply in one tap. (Buck 2026-06-08: the old preview capped to 3 but raced the
  // AJAX — it stuck at one reply and its "View all" called the flaky
  // autoLoadReplies, so the thread "only loaded one reply no matter what I
  // clicked." Teaser is now intentionally one; "Show all" drives the proven
  // autoLoadAllReplies that walks every batch.) Idempotent via data-lg-capped.
  function capPreview(card, full, exp, total) {
    if (!card.getAttribute('data-lg-capped')) {
      card.setAttribute('data-lg-capped', '1');
      var stubs = full.querySelectorAll('.reply-stub');
      for (var i = 0; i < stubs.length; i++) {
        // teaser: show the first reply, hide the rest until "Show all"
        if (i < 1) { stubs[i].classList.remove('lg-rhide'); stubs[i].classList.add('lg-rshow'); }
        else { stubs[i].classList.remove('lg-rshow'); stubs[i].classList.add('lg-rhide'); }
      }
      revealReplyImages(full);          // the teaser reply keeps its photo
      enhanceReplyReactions(full);
      if (exp) exp.style.display = 'none';
      var lm = full.querySelector('.replies-loadmore');
      if (lm) lm.style.display = 'none';
      if (total > 1 && !full.querySelector('.lg-viewall')) {
        var va = document.createElement('button');
        va.type = 'button'; va.className = 'lg-viewall';
        va.textContent = 'Show all ' + total + ' replies';
        va.addEventListener('click', function () {
          card.removeAttribute('data-lg-capped');
          var all = full.querySelectorAll('.reply-stub');
          for (var k = 0; k < all.length; k++) { all[k].classList.remove('lg-rhide'); all[k].classList.add('lg-rshow'); }
          if (lm) lm.style.display = '';
          if (exp) exp.style.display = '';
          va.remove();
          autoLoadAllReplies(card);     // walks every remaining batch, then reveals all
        });
        full.appendChild(va);
      }
    }
    card.__lgPreviewing = false;
  }

  var previewObserver = null;
  function observePreviewCards(root) {
    if (!previewObserver) return;
    var scope = (root && root.querySelectorAll) ? root : document;
    var cards = scope.querySelectorAll('.feed-card:not([data-lg-prevobs])');
    for (var i = 0; i < cards.length; i++) {
      cards[i].setAttribute('data-lg-prevobs', '1');
      previewObserver.observe(cards[i]);
    }
  }
  function watchPreviewReplies() {
    if (!window.matchMedia('(max-width:640px)').matches) return;
    if (previewObserver || !('IntersectionObserver' in window)) return;
    previewObserver = new IntersectionObserver(function (entries) {
      for (var i = 0; i < entries.length; i++) {
        if (entries[i].isIntersecting) {
          previewReplies(entries[i].target);
          previewObserver.unobserve(entries[i].target);
        }
      }
    }, { rootMargin: '3000px 0px' });
    observePreviewCards(document);
  }

  var cardObserver = null;
  function watchCards() {
    var first = document.querySelector('.feed-card');
    var host = first && first.parentElement;
    if (!host || cardObserver) return;
    cardObserver = new MutationObserver(function (muts) {
      for (var i = 0; i < muts.length; i++) {
        var added = muts[i].addedNodes;
        for (var j = 0; j < added.length; j++) {
          var n = added[j];
          if (!n || n.nodeType !== 1) continue;
          if (n.classList && n.classList.contains('feed-card')) relayCard(n);
          else if (n.querySelectorAll) relayCards(n);
        }
      }
    });
    cardObserver.observe(host, { childList: true });
  }

  // Transform the canonical New-post modal (#ntm-form) into a Facebook-style
  // "write a post": hide Forum/Title/free-text-Tags, show avatar + name + a
  // "What's on your mind?" body + selectable CATEGORY chips (from the forum
  // options) that route + tag the post. Title is auto-derived from the first
  // line; forum defaults to General (3837). Idempotent.
  function fbStyleComposer() {
    // Mobile-only (<=640): the fb-style composer is the MOBILE layer. On desktop the
    // native #ntm-form owns the picker (a native single-select - no "two forums" bug),
    // per the 640 split + the "no JS-reshape the look on desktop" rule. Gated here so
    // both call sites (init + composer-open) honor it. (coordinator, ntm-picker unblock)
    if (!window.matchMedia('(max-width:640px)').matches) return;
    var form = document.getElementById('ntm-form');
    if (!form || form.getAttribute('data-lg-fbc')) return;
    var forumSel = document.getElementById('ntm-forum');
    var titleIn = document.getElementById('ntm-title-in');
    var tagsIn = document.getElementById('ntm-tags');
    var body = document.getElementById('ntm-content');
    var submit = document.getElementById('ntm-submit');
    if (!forumSel || !submit) return;
    form.setAttribute('data-lg-fbc', '1');
    form.classList.add('lg-fbc');

    // Ian 2026-06-17: the full composer is taller (picker + title + tags + quick-
    // tags + editor), so on mobile top-anchor the dialog and cap it to the VISUAL
    // viewport — the iOS keyboard shrinks visualViewport from the bottom, so a
    // top-anchored, internally-scrolling panel keeps the focused field visible
    // instead of being shoved under the keyboard. --lg-vvh tracks that height.
    if (!document.getElementById('lg-fbc-layout-css')) {
      var laySt = document.createElement('style'); laySt.id = 'lg-fbc-layout-css';
      laySt.textContent =
        '@media (max-width:640px){' +
        '.ntm-overlay{align-items:flex-start}' +
        '.ntm-dialog{width:calc(100vw - 16px);margin:8px auto;padding:16px;gap:12px;' +
          'max-height:calc(var(--lg-vvh,100dvh) - 16px)}' +
        '.lg-fbc #ntm-forum.ntm-forumlist{max-height:148px}' +
        // Accordion trigger that collapses the (tall) forum list into one row.
        '.lg-fbc-forumtrig{display:flex;align-items:center;gap:8px;width:100%;border:1px solid var(--border,#dcd7ca);' +
          'background:var(--bg-card,#fff);border-radius:10px;padding:11px 13px;cursor:pointer;text-align:left;' +
          'font:600 14px/1.15 var(--lg-font-sans,system-ui,-apple-system,"Segoe UI",sans-serif);color:var(--fg,#1f231e)}' +
        '.lg-fbc-forumtrig__lb{color:var(--fg-muted,#5b5f58);font-weight:600}' +
        '.lg-fbc-forumtrig__val{font-weight:700;flex:1 1 auto;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}' +
        '.lg-fbc-forumtrig__chev{color:var(--fg-muted,#5b5f58);transition:transform .15s ease}' +
        '.lg-fbc-forumtrig.is-open .lg-fbc-forumtrig__chev{transform:rotate(180deg)}' +
        // unset = required-and-not-yet-chosen (prompt look); err = tried to post empty
        '.lg-fbc-forumtrig.is-unset .lg-fbc-forumtrig__val{color:var(--fg-soft,#8d9088);font-weight:600}' +
        '.lg-fbc-forumtrig--err{border-color:#c0392b!important;box-shadow:0 0 0 1px #c0392b}' +
        '.lg-fbc-forumtrig__req{color:#c0392b;font-weight:700;margin-left:2px}' +
        // While the composer is open, hide the 3-button bar (it sits above the
        // overlay at a very high z-index and would cover the Post/Council buttons).
        // Pure CSS: tabbar is a later body sibling of the overlay; :not([hidden])
        // = open, so this auto-toggles with the modal.
        '.ntm-overlay:not([hidden]) ~ #looth-tabbar{display:none!important}' +
        '}';
      document.head.appendChild(laySt);
    }
    if (window.visualViewport && !window.__lgVVBound) {
      window.__lgVVBound = true;
      var vv = window.visualViewport;
      var setVVH = function () { document.documentElement.style.setProperty('--lg-vvh', vv.height + 'px'); };
      setVVH();
      vv.addEventListener('resize', setVVH);
      vv.addEventListener('scroll', setVVH);
    }

    // The native forum picker #ntm-forum is now a <div role=radiogroup
    // class=ntm-forumlist> (category headers + radio leaves, name=forum_id) that
    // hub-coord rebuilt from the old <select> — it IS the single-select
    // category->leaf list Ian asked for. So the mobile composer just SHOWS it
    // (below) instead of hiding it + building a duplicate. This is light mobile
    // polish (scroll cap + checked theming); base .ntm-fl styling is hub-coord's
    // in forums.css. (buck-coord, ntm-picker — contract changed under §A)
    if (!document.getElementById('lg-fbc-list-css')) {
      var fbcSt = document.createElement('style'); fbcSt.id = 'lg-fbc-list-css';
      fbcSt.textContent =
        '.lg-fbc #ntm-forum.ntm-forumlist{max-height:230px;overflow:auto;border:1px solid var(--lg-line,#e3ddd0);border-radius:10px;background:#fff;margin:10px 0 4px}' +
        '.lg-fbc #ntm-forum .ntm-fl__cat{position:sticky;top:0;background:#fff;padding:8px 12px 4px;font:700 10.5px/1 var(--lg-font-sans,system-ui,sans-serif);letter-spacing:.07em;text-transform:uppercase;color:var(--lguser-accent,#6b7c52)}' +
        '.lg-fbc #ntm-forum .ntm-fl__cat:not(:first-child){border-top:1px solid var(--lg-line,#e3ddd0);margin-top:2px;padding-top:8px}' +
        '.lg-fbc #ntm-forum .ntm-fl__leaf{display:flex;align-items:center;gap:8px;padding:8px 12px;margin:0;font:500 14px/1.2 var(--lg-font-sans,system-ui,sans-serif);color:var(--lg-ink,#323532);cursor:pointer}' +
        '.lg-fbc #ntm-forum .ntm-fl__leaf:has(input:checked){background:var(--lguser-pill,#eef2e3);font-weight:700}' +
        '.lg-fbc #ntm-forum .ntm-fl__leaf input{accent-color:var(--lguser-accent,#6b7c52);flex:0 0 auto;margin:0}';
      document.head.appendChild(fbcSt);
    }

    // Quiet iOS's AutoFill QuickType bar (the key / card / location chips above the
    // keyboard) and tune the return key (Ian 2026-06-17: "shrink the keyboard
    // commands"). The chips are iOS-owned and can't be fully removed from a web
    // page, but autocomplete=off is the lever that stops Safari offering passwords/
    // cards/contacts on these plain text fields. Mobile-only (in fbStyleComposer).
    form.setAttribute('autocomplete', 'off');
    if (titleIn) {
      titleIn.setAttribute('autocomplete', 'off');
      titleIn.setAttribute('autocapitalize', 'sentences');
      titleIn.setAttribute('autocorrect', 'on');
      titleIn.setAttribute('spellcheck', 'true');
      titleIn.setAttribute('enterkeyhint', 'next');
    }
    if (tagsIn) {
      tagsIn.setAttribute('autocomplete', 'off');
      tagsIn.setAttribute('autocapitalize', 'none');
      tagsIn.setAttribute('autocorrect', 'off');
      tagsIn.setAttribute('spellcheck', 'false');
      tagsIn.setAttribute('enterkeyhint', 'done');
    }
    var qeKb = document.getElementById('ntm-editor');
    qeKb = qeKb && qeKb.querySelector('.ql-editor');
    if (qeKb) {
      qeKb.setAttribute('autocapitalize', 'sentences');
      qeKb.setAttribute('autocorrect', 'on');
      qeKb.setAttribute('spellcheck', 'true');
    }

    var quicktags = document.getElementById('ntm-quicktags');
    // Ian 2026-06-17: the mobile composer now carries the SAME controls as desktop
    // — leaf-forum picker, title (server-required), tags, and the Council/Weekly
    // quick-tags — instead of the old stripped "quick post" (reverses Buck
    // 2026-06-08's hide-everything). Only the body label + paste hint stay hidden
    // as declutter; the visible body is the Quill editor (#ntm-editor).
    var editor = document.getElementById('ntm-editor') || body;
    var blab = editor && editor.previousElementSibling;
    if (blab && blab.tagName === 'LABEL') blab.style.display = 'none';
    [].slice.call(form.querySelectorAll('.ntm-paste-hint,.ntm-tip,[class*="hint"],[class*="tip"]')).forEach(function (t) { t.style.display = 'none'; });

    // Friendlier labels for the workflow quick-tags on mobile. data-tag still
    // drives the toggle in forums.js (it only flips the is-on class, never the
    // text), so the toggle stays intact — we just change the visible wording.
    if (quicktags) {
      var qLabels = { councilyes: 'Council of Elders', weeklyyes: 'Weekly email' };
      [].forEach.call(quicktags.querySelectorAll('.ntm-qtag'), function (b) {
        var nice = qLabels[(b.dataset.tag || '').toLowerCase()];
        if (nice) b.textContent = nice;
      });
    }

    // Preselect a sensible default leaf (General #3837) so a post never fails on an
    // empty forum — but SHOW the picker so the member chooses where it goes.
    // No default forum (Ian 2026-06-17): the member must deliberately choose where
    // the post goes — a required action, no auto-selected "General". forums.js
    // already rejects an empty forum on submit; the guard below surfaces that in
    // the collapsed accordion (expand + flag) instead of focusing a hidden radio.

    // Collapse the leaf-forum list into an accordion (Ian 2026-06-17: "the forum is
    // now confusing"). A compact summary row shows the chosen forum; tapping it
    // expands the existing #ntm-forum radiogroup, and picking a leaf collapses it
    // back with the new label. The radio that submits is unchanged.
    var forumLabEl = document.getElementById('ntm-forum-label');
    if (forumLabEl) forumLabEl.style.display = 'none';
    if (forumSel && !form.querySelector('.lg-fbc-forumtrig')) {
      var trig = document.createElement('button');
      trig.type = 'button';
      trig.className = 'lg-fbc-forumtrig';
      trig.setAttribute('aria-expanded', 'false');
      trig.innerHTML = '<span class="lg-fbc-forumtrig__lb">Forum</span>' +
        '<span class="lg-fbc-forumtrig__val"></span><span class="lg-fbc-forumtrig__chev" aria-hidden="true">▾</span>';
      var valEl = trig.querySelector('.lg-fbc-forumtrig__val');
      forumSel.parentNode.insertBefore(trig, forumSel);

      function fbcChosen() { return forumSel.querySelector('input[name="forum_id"]:checked'); }
      function fbcSelLabel() {
        var r = fbcChosen();
        var leaf = r && r.closest('.ntm-fl__leaf');
        return leaf ? leaf.textContent.replace(/\s+/g, ' ').trim() : '';
      }
      function fbcSyncTrig() {
        var has = !!fbcChosen();
        // textContent = XSS-safe; show a prompt (+ required *) until one is picked
        valEl.textContent = has ? fbcSelLabel() : 'Choose a forum';
        trig.classList.toggle('is-unset', !has);
        if (has) trig.classList.remove('lg-fbc-forumtrig--err');
      }
      function fbcSetOpen(open) {
        forumSel.style.display = open ? '' : 'none';
        trig.classList.toggle('is-open', open);
        trig.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) forumSel.scrollIntoView({ block: 'nearest' });
      }
      forumSel.style.display = 'none';   // collapsed by default
      fbcSyncTrig();
      trig.addEventListener('click', function () { fbcSetOpen(forumSel.style.display === 'none'); });
      forumSel.addEventListener('change', function () { fbcSyncTrig(); fbcSetOpen(false); });
      // Keep the label accurate when the modal re-opens (incl. a leaf "+ Post here"
      // that preselects a forum programmatically — no change event fires then).
      var fbcOv = document.querySelector('.ntm-overlay');
      if (fbcOv) new MutationObserver(function () {
        if (!fbcOv.hasAttribute('hidden')) fbcSyncTrig();
      }).observe(fbcOv, { attributes: true, attributeFilter: ['hidden'] });

      // Required action: block the post until a forum is chosen. Capture-phase on
      // document so we intercept before forums.js's submit handler (which would
      // otherwise error against the now-hidden radio list). Expand + flag instead.
      document.addEventListener('submit', function (e) {
        if (e.target !== form || fbcChosen()) return;
        e.preventDefault(); e.stopPropagation();
        trig.classList.add('lg-fbc-forumtrig--err');
        fbcSyncTrig();
        fbcSetOpen(true);
        trig.scrollIntoView({ block: 'center' });
      }, true);
    }

    // avatar + name header
    var nameBtn = document.querySelector('.lg-chrome__account');
    var name = nameBtn ? (nameBtn.textContent || '').replace(/\s+/g, ' ').trim().split(/\s{2,}|·/)[0] : 'You';
    var avi = myAvatarSrc();
    var head = document.createElement('div'); head.className = 'lg-fbc-head';
    head.innerHTML = '<span class="lg-fbc-avi">' + (avi ? '<img src="' + avi + '" alt="">' : '') + '</span>' +
      '<span class="lg-fbc-name"></span>';
    head.querySelector('.lg-fbc-name').textContent = name;
    if (editor && editor.parentNode) editor.parentNode.insertBefore(head, editor);
    if (body && 'placeholder' in body) body.placeholder = "What’s on your mind?";
    var ql = form.querySelector('.ql-editor'); if (ql) ql.setAttribute('data-placeholder', "What’s on your mind?");

    // Declutter: hide Quill's full format toolbar; give a clean two-button row —
    // Photo (drives Quill's existing image upload) + YouTube link (Buck 2026-06-08:
    // "just photos and option to add a youtube link"). The image button still works
    // hidden (we .click() it); a pasted/typed YouTube URL embeds on render.
    if (!document.getElementById('lg-fbc-add-css')) {
      var addSt = document.createElement('style'); addSt.id = 'lg-fbc-add-css';
      addSt.textContent =
        // hide Quill's format toolbar via CSS (race-proof — it mounts after fbStyle runs)
        '.lg-fbc .ql-toolbar{display:none!important}' +
        '.lg-fbc .lg-fbc-add{display:flex;gap:9px;margin:12px 0 2px}' +
        '.lg-fbc .lg-fbc-addbtn{display:inline-flex;align-items:center;gap:7px;border:1px solid var(--lg-line,#e3ddd0);' +
        'background:var(--lg-cream,#fbfbf8);color:var(--lg-ink,#323532);border-radius:999px;padding:9px 15px;cursor:pointer;' +
        'font:600 13.5px/1 var(--lg-font-sans,system-ui,-apple-system,"Segoe UI",sans-serif)}' +
        '.lg-fbc .lg-fbc-addbtn:active{background:var(--lg-sage-tint,#eef2e3)}' +
        '.lg-fbc .lg-fbc-addbtn svg{width:18px;height:18px;flex:0 0 auto}' +
        'html[data-lguser-theme="dark"] .lg-fbc .lg-fbc-addbtn{background:#222629;border-color:#333833;color:#e5e7e1}';
      document.head.appendChild(addSt);
    }
    if (editor && editor.parentNode && !form.querySelector('.lg-fbc-add')) {
      var addRow = document.createElement('div'); addRow.className = 'lg-fbc-add';
      addRow.innerHTML =
        '<button type="button" class="lg-fbc-addbtn" data-fbc-photo><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="8.5" cy="10" r="1.8"/><path d="M4 17l4.5-4.5 3 3L16 11l4 4"/></svg>Photo</button>' +
        '<button type="button" class="lg-fbc-addbtn" data-fbc-yt><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="6" width="18" height="12" rx="3"/><path d="M10 9.5l5 2.5-5 2.5z" fill="currentColor"/></svg>YouTube link</button>';
      editor.parentNode.insertBefore(addRow, editor.nextSibling);
      addRow.querySelector('[data-fbc-photo]').addEventListener('click', function () {
        // iOS-safe: drive the upload tray directly (forums.js exposes lgNtmPhoto)
        // so input.click() stays inside THIS user gesture. The old path bounced
        // through Quill's display:none .ql-toolbar .ql-image, which iOS Safari
        // refuses to honor as a file-picker gesture (the bug Ian hit).
        if (typeof window.lgNtmPhoto === 'function') { window.lgNtmPhoto(); return; }
        var imgBtn = form.querySelector('.ql-toolbar .ql-image');   // fallback
        if (imgBtn) imgBtn.click();
      });
      addRow.querySelector('[data-fbc-yt]').addEventListener('click', function () {
        var url = window.prompt('Paste a YouTube link to embed:');
        if (!url) return;
        url = url.trim();
        if (!/^https?:\/\/(www\.)?(youtube\.com|youtu\.be)\//i.test(url)) { alert('That doesn’t look like a YouTube link.'); return; }
        var qe = form.querySelector('.ql-editor'); if (!qe) return;
        qe.focus();
        // Insert the bare URL on its own line — it embeds on render (bbProcessEmbeds).
        try { document.execCommand('insertText', false, '\n' + url + '\n'); }
        catch (e) { qe.appendChild(document.createTextNode(' ' + url + ' ')); }
        qe.dispatchEvent(new Event('input', { bubbles: true }));
      });
    }

    function bodyText() {
      if (body && 'value' in body && body.value) return body.value;
      var q = form.querySelector('.ql-editor');
      return q ? (q.textContent || '').trim() : '';
    }
    // Right before the canonical submit: safety auto-title only (title is now a
    // required step-1 field in the wizard, so this rarely fires). No forum default
    // — the member must choose (validated when leaving step 1).
    submit.addEventListener('click', function () {
      if (titleIn && !titleIn.value.trim()) {
        var b = bodyText();
        titleIn.value = ((b ? b.split(/\n/)[0].slice(0, 80) : '') || '').trim() || 'New post';
      }
    }, true);
    // MOBILE: after a successful post the canonical composer redirects to the new
    // TOPIC page (a single-post / desktop-style view) after 600ms. Buck wants to
    // stay on the Hub. Watch the status; the instant it flips to "Posted/Redirect",
    // go to the Hub feed — this fires before the canonical's setTimeout, so we win
    // the race and the user lands back on the feed with their new post present.
    var ntmStatusEl = document.getElementById('ntm-status');
    if (ntmStatusEl && window.MutationObserver && !ntmStatusEl.getAttribute('data-lg-postnav')) {
      ntmStatusEl.setAttribute('data-lg-postnav', '1');
      new MutationObserver(function () {
        if (/posted|redirect/i.test(ntmStatusEl.textContent || '')) {
          // Use the SAME dest the canonical composer computed (hub feed centered on
          // the new post) instead of bare /hub/ (Ian 6/17). Falls back to /hub/.
          try { window.location.href = window.__lgPostDest || '/hub/'; } catch (e) {}
        }
      }).observe(ntmStatusEl, { childList: true, characterData: true, subtree: true });
    }

    // ===== Instagram-style 3-step wizard (Ian 2026-06-17) =====================
    // Reorganize the (now full) composer into 3 focused screens with Back/Next +
    // a 1·2·3 progress strip, instead of one tall scroll:
    //   1 Title + Forum   2 Photos   3 Text + Tags + Council/Weekly + Anon → Post
    if (!document.getElementById('lg-fbc-wiz-css')) {
      var wst = document.createElement('style'); wst.id = 'lg-fbc-wiz-css';
      wst.textContent =
        '.lg-fbc .lg-fbc-step{display:none}' +
        '.lg-fbc .lg-fbc-step.is-on{display:block}' +
        '.lg-fbc-steph{font:700 12px/1 var(--lg-font-sans,system-ui,sans-serif);color:var(--fg-muted,#5b5f58);' +
          'text-transform:uppercase;letter-spacing:.05em;margin:0 0 12px}' +
        '.lg-fbc-dots{display:flex;gap:6px;justify-content:center;margin:0 0 12px}' +
        '.lg-fbc-dots i{width:7px;height:7px;border-radius:50%;background:var(--border,#dcd7ca);transition:all .18s ease}' +
        '.lg-fbc-dots i.on{background:var(--lguser-accent,#6b7c52);width:22px;border-radius:4px}' +
        '.lg-fbc-nav{display:flex;align-items:center;gap:10px;margin-top:14px}' +
        '.lg-fbc-nav__sp{flex:1 1 auto}' +
        '.lg-fbc-nav button{font:700 15px/1 var(--lg-font-sans,system-ui,sans-serif);border-radius:999px;' +
          'padding:12px 22px;cursor:pointer;border:0}' +
        '.lg-fbc-back{background:none;border:1px solid var(--border,#dcd7ca);color:var(--fg,#1f231e);' +
          'display:inline-flex;align-items:center;gap:6px;padding:11px 16px}' +
        '.lg-fbc-next{background:var(--lguser-accent,#52613d);color:#fff}' +
        '.lg-fbc .lg-fbc-nav .ntm-submit{background:var(--lguser-accent,#52613d);color:#fff;border:0;' +
          'border-radius:999px;padding:12px 22px;font:700 15px/1 var(--lg-font-sans,system-ui,sans-serif)}' +
        '.lg-fbc-gallery{display:flex;flex-wrap:wrap;gap:8px;margin:8px 0 2px}' +
        '.lg-fbc-emptyph{color:var(--fg-soft,#8d9088);font:500 13.5px/1.4 var(--lg-font-sans,system-ui,sans-serif);' +
          'padding:22px 0;text-align:center}';
      document.head.appendChild(wst);
    }

    var wForum  = document.getElementById('ntm-forum');
    if (wForum && !form.querySelector('.lg-fbc-step')) {
      var wTitle    = document.getElementById('ntm-title-in');
      var wTitleLab = wTitle && wTitle.previousElementSibling && wTitle.previousElementSibling.tagName === 'LABEL' ? wTitle.previousElementSibling : null;
      var wForumLab = document.getElementById('ntm-forum-label');
      var wTrig     = form.querySelector('.lg-fbc-forumtrig');
      var wHead     = form.querySelector('.lg-fbc-head');
      var wEditor   = document.getElementById('ntm-editor');
      var wBody     = document.getElementById('ntm-content');
      var wAddRow   = form.querySelector('.lg-fbc-add');
      var wTags     = document.getElementById('ntm-tags');
      var wTagsLab  = wTags && wTags.previousElementSibling && wTags.previousElementSibling.tagName === 'LABEL' ? wTags.previousElementSibling : null;
      var wQuick    = document.getElementById('ntm-quicktags');
      var wAnon     = form.querySelector('.ntm-anon');
      var wSubmit   = document.getElementById('ntm-submit');
      var wActions  = wSubmit && wSubmit.closest('.ntm-row');
      var wStatus   = document.getElementById('ntm-status');

      function wStep(n, label) {
        var d = document.createElement('div'); d.className = 'lg-fbc-step'; d.dataset.step = n;
        d.innerHTML = '<p class="lg-fbc-steph">' + label + '</p>'; return d;
      }
      var ws1 = wStep(1, 'Step 1 of 4 · Title &amp; forum');
      var ws2 = wStep(2, 'Step 2 of 4 · Add photos');
      var ws3 = wStep(3, 'Step 3 of 4 · Write your post');
      var ws4 = wStep(4, 'Step 4 of 4 · Tags &amp; options');

      // Step 1: title + forum
      if (wTitleLab) ws1.appendChild(wTitleLab);
      if (wTitle) ws1.appendChild(wTitle);
      if (wForumLab) { wForumLab.style.display = ''; ws1.appendChild(wForumLab); }
      if (wTrig) ws1.appendChild(wTrig);
      ws1.appendChild(wForum);

      // Step 2: photos (button row + a thumbnail gallery)
      if (wAddRow) ws2.appendChild(wAddRow);
      var wGal = document.createElement('div'); wGal.className = 'lg-fbc-gallery'; ws2.appendChild(wGal);
      var wEmpty = document.createElement('div'); wEmpty.className = 'lg-fbc-emptyph';
      wEmpty.textContent = 'No photos yet — tap “Photo” to add one. (Optional)';
      ws2.appendChild(wEmpty);

      // Step 3: body only (keyboard step — tags moved to step 4 so they don't get
      // lost behind the keyboard, Ian 6/17).
      if (wHead) ws3.appendChild(wHead);
      if (wEditor) ws3.appendChild(wEditor);
      if (wBody) ws3.appendChild(wBody);
      // Step 4: tags + quick-tags + anon → Post
      if (wTagsLab) ws4.appendChild(wTagsLab);
      if (wTags) ws4.appendChild(wTags);
      if (wQuick) ws4.appendChild(wQuick);
      if (wAnon) ws4.appendChild(wAnon);

      var wSteps = document.createElement('div'); wSteps.className = 'lg-fbc-steps';
      wSteps.appendChild(ws1); wSteps.appendChild(ws2); wSteps.appendChild(ws3); wSteps.appendChild(ws4);

      var wDots = document.createElement('div'); wDots.className = 'lg-fbc-dots';
      wDots.innerHTML = '<i></i><i></i><i></i><i></i>';

      var wNav = document.createElement('div'); wNav.className = 'lg-fbc-nav';
      var wBack = document.createElement('button'); wBack.type = 'button'; wBack.className = 'lg-fbc-back';
      wBack.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg><span>Cancel</span>';
      var wSp = document.createElement('span'); wSp.className = 'lg-fbc-nav__sp';
      var wNext = document.createElement('button'); wNext.type = 'button'; wNext.className = 'lg-fbc-next'; wNext.textContent = 'Next';
      wNav.appendChild(wBack); wNav.appendChild(wSp); wNav.appendChild(wNext);
      if (wSubmit) wNav.appendChild(wSubmit);          // Post lives in the nav, last step only
      if (wStatus) wNav.appendChild(wStatus);

      var anchor = wActions || (wSubmit && wSubmit.parentNode) || form;
      anchor.parentNode ? anchor.parentNode.insertBefore(wDots, anchor) : form.appendChild(wDots);
      anchor.parentNode ? anchor.parentNode.insertBefore(wSteps, anchor) : form.appendChild(wSteps);
      anchor.parentNode ? anchor.parentNode.insertBefore(wNav, anchor) : form.appendChild(wNav);
      if (wActions) wActions.style.display = 'none';    // original Post/Cancel row retired

      var wCur = 1;
      function wShow(n) {
        wCur = Math.max(1, Math.min(4, n));
        [ws1, ws2, ws3, ws4].forEach(function (s) { s.classList.toggle('is-on', +s.dataset.step === wCur); });
        [].forEach.call(wDots.children, function (d, i) { d.classList.toggle('on', i === wCur - 1); });
        wBack.querySelector('span').textContent = wCur === 1 ? 'Cancel' : 'Back';
        if (wCur === 4) { wNext.style.display = 'none'; if (wSubmit) wSubmit.style.display = ''; }
        else { wNext.style.display = ''; if (wSubmit) wSubmit.style.display = 'none'; }
        try { form.scrollTop = 0; var dlg = form.closest('.ntm-dialog'); if (dlg) dlg.scrollTop = 0; } catch (e) {}
      }
      wBack.addEventListener('click', function () {
        if (wCur === 1) { var c = document.getElementById('ntm-cancel'); if (c) c.click(); return; }
        wShow(wCur - 1);
      });
      wNext.addEventListener('click', function () {
        if (wCur === 1) {
          if (wTitle && !wTitle.value.trim()) {
            wTitle.focus();
            wTitle.style.boxShadow = '0 0 0 2px #c0392b';
            setTimeout(function () { wTitle.style.boxShadow = ''; }, 1200);
            return;
          }
          if (!wForum.querySelector('input[name="forum_id"]:checked')) {
            wForum.style.display = '';                  // expand the picker
            if (wTrig) { wTrig.classList.add('is-open', 'lg-fbc-forumtrig--err'); wTrig.scrollIntoView({ block: 'center' }); }
            return;
          }
        }
        wShow(wCur + 1);
      });
      wShow(1);

      // Relocate the upload tray (forceTray thumbnails) into step 2 + toggle the
      // empty hint as photos come and go.
      function wSyncGal() {
        var tray = form.querySelector('.lg-mtray');
        if (tray && tray.parentNode !== wGal) wGal.appendChild(tray);
        var has = tray && tray.querySelector('.lg-mtray__item');
        wEmpty.style.display = has ? 'none' : '';
      }
      wSyncGal();
      new MutationObserver(wSyncGal).observe(form, { childList: true, subtree: true });
    }
  }

  // Mobile only: turn the shared header into a single live-search bubble.
  // Inserts a pill input into .lg-chrome__inner (the canonical hamburger/logo/
  // aside are hidden by CSS), and a results panel into <body> that live-queries
  // the suggest endpoint as you type. Hub mode returns {kind,title,url} (the
  // linkable set); author mode exists but yields no URLs, so it's not wired.
  // Debounced + sequence-guarded so out-of-order responses can't clobber a
  // newer query. Idempotent; guarded so a failure can't break the header.
  var SEARCH_ICO =
    '<svg class="lg-hub-search__ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
    'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
    '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>';
  function buildTopSearch() {
    try {
      if (!window.matchMedia('(max-width:640px)').matches) return;
      var inner = document.querySelector('#site-header .lg-chrome__inner');
      if (!inner || inner.querySelector('.lg-hub-search')) return;

      var wrap = document.createElement('div');
      wrap.className = 'lg-hub-search';
      wrap.innerHTML = SEARCH_ICO +
        '<input type="search" enterkeyhint="search" autocomplete="off" ' +
        'aria-label="Search the Hub" placeholder="Search the Hub">';
      inner.appendChild(wrap);
      var input = wrap.querySelector('input');

      var panel = document.createElement('div');
      panel.className = 'lg-hub-search__panel';
      panel.setAttribute('role', 'listbox');
      document.body.appendChild(panel);

      // ── suggested category pills (Buck 2026-06-11: typing "3d" should surface
      // a "#3D Printing" pill at the TOP — the same categories the cards wear).
      // Harvested once from the filter rail's cat=/leaf= links, so labels, counts
      // and targets always match the real filters. Prefix matches rank first.
      if (!document.getElementById('lg-hs-cats-css')) {
        var hcss = document.createElement('style'); hcss.id = 'lg-hs-cats-css';
        hcss.textContent =
          '.lg-hub-search__cats{display:flex;gap:7px;flex-wrap:wrap;padding:11px 12px 5px}' +
          '.lg-hs-cat{display:inline-flex;align-items:center;background:var(--lg-sage-tint,#eef2e3);color:var(--lg-sage-d,#6b7c52);' +
          'border-radius:999px;padding:7px 12px;font:700 12.5px/1 var(--lg-font-sans,system-ui,sans-serif);text-decoration:none;white-space:nowrap}' +
          'html[data-lguser-theme="dark"] .lg-hs-cat{background:#243024;color:#b6c79a}' +
          '.lg-hs-tag{border:1px solid var(--lg-sage-3,#d4e0b8)}' +
          'html[data-lguser-theme="dark"] .lg-hs-tag{border-color:#3a4a36}' +
          '.lg-hub-search__go{display:flex;align-items:center;gap:9px;padding:12px;color:var(--lg-ink,#323532);' +
          'font:600 13.5px/1.3 var(--lg-font-sans,system-ui,sans-serif);text-decoration:none;' +
          'border-top:1px solid var(--lg-line,#e3ddd0)}' +
          '.lg-hub-search__cats+.lg-hub-search__go{border-top:0}' +
          '.lg-hub-search__go svg{flex:0 0 auto;width:15px;height:15px;stroke:var(--lg-sage-d,#6b7c52)}' +
          '.lg-hub-search__go b{font-weight:700}' +
          'html[data-lguser-theme="dark"] .lg-hub-search__go{color:#d8dcd4;border-top-color:#33352f}' +
          'html[data-lguser-theme="dark"] .lg-hub-search__go svg{stroke:#b6c79a}';
        (document.head || document.documentElement).appendChild(hcss);
      }
      var hubCats = null;
      function harvestCats() {
        if (hubCats) return hubCats;
        var seen = {}, out = [];
        var links = document.querySelectorAll('a.hub-rail__nm[href*="cat="], a.hub-rail__nm[href*="leaf="]');
        for (var i = 0; i < links.length; i++) {
          var label = (links[i].textContent || '').trim();
          if (!label || seen[label.toLowerCase()]) continue;
          seen[label.toLowerCase()] = 1;
          out.push({ label: label, href: links[i].getAttribute('href') });
        }
        if (out.length) hubCats = out;                       // cache once populated
        return out;
      }
      // ── hashtag suggestions (Buck 2026-06-12: the # phrases already on posts
      // are the intuitive way to filter — surface the matching ones at the TOP
      // of the dropdown). Harvested live from the server-rendered card chips
      // (a.fc-tag.tag-chip), whose hrefs already point at the /hub/?q=<tag>
      // feed filter. Re-queried per render — infinite scroll keeps adding
      // cards, so unlike harvestCats() this must NOT cache.
      function harvestTags() {
        var seen = {}, out = [];
        var chips = document.querySelectorAll('a.fc-tag.tag-chip');
        for (var i = 0; i < chips.length; i++) {
          var label = (chips[i].textContent || '').trim();
          if (!label) continue;
          var key = label.toLowerCase().replace(/^[#@]/, '');
          if (seen[key]) { seen[key].count++; continue; }
          seen[key] = { label: label, href: chips[i].getAttribute('href'), count: 1 };
          out.push(seen[key]);
        }
        out.sort(function (a, b) { return b.count - a.count; });
        return out;
      }
      function matchHits(list, ql, getLabel) {
        var starts = [], contains = [];
        list.forEach(function (x) {
          var l = getLabel(x).toLowerCase().replace(/^[#@]/, '');
          if (l.indexOf(ql) === 0) starts.push(x);
          else if (l.indexOf(ql) > -1) contains.push(x);
        });
        return starts.concat(contains);
      }
      // One pill row: matching hashtags FIRST, then matching categories,
      // deduped by label, capped at 8. Pills navigate — they ARE filters.
      function pillRowFor(q) {
        var ql = q.toLowerCase().replace(/^[#@]/, '');
        var tags = matchHits(harvestTags(), ql, function (t) { return t.label; }).slice(0, 6);
        var cats = matchHits(harvestCats(), ql, function (c) { return c.label; });
        var used = {}, pills = [];
        tags.forEach(function (t) {
          used[t.label.toLowerCase().replace(/^[#@]/, '')] = 1;
          var disp = /^[@#]/.test(t.label) ? t.label : ('#' + t.label);
          pills.push({ cls: 'lg-hs-cat lg-hs-tag', href: t.href, text: disp });
        });
        cats.forEach(function (c) {
          if (used[c.label.toLowerCase()]) return;
          pills.push({ cls: 'lg-hs-cat', href: c.href, text: '#' + c.label });
        });
        pills = pills.slice(0, 8);
        if (!pills.length) return null;
        var row = document.createElement('div');
        row.className = 'lg-hub-search__cats';
        pills.forEach(function (p) {
          var a = document.createElement('a');
          a.className = p.cls;
          a.href = p.href;
          a.textContent = p.text;
          row.appendChild(a);
        });
        return row;
      }
      // "Filter Hub by <word>" action row — applies the existing server-side
      // full-text feed filter (/hub/?q=). Always offered while typing, so any
      // word can filter the Hub even when nothing matches the suggest box.
      function filterRowFor(q) {
        var a = document.createElement('a');
        a.className = 'lg-hub-search__go';
        a.href = '/hub/?q=' + encodeURIComponent(q);
        a.innerHTML = SEARCH_ICO.replace('lg-hub-search__ico', 'lg-hub-search__goico');
        var t = document.createElement('span');
        t.appendChild(document.createTextNode('Filter the Hub by “'));
        var b = document.createElement('b'); b.textContent = q;
        t.appendChild(b);
        t.appendChild(document.createTextNode('”'));
        a.appendChild(t);
        return a;
      }
      function applyFilter(q) {
        try { location.href = '/hub/?q=' + encodeURIComponent(q); } catch (e) {}
      }

      function position() {
        var r = wrap.getBoundingClientRect();
        panel.style.top = Math.round(r.bottom + 8) + 'px';
      }
      function open() { position(); panel.classList.add('is-open'); }
      function close() { panel.classList.remove('is-open'); }

      // Search results open IN PLACE (Buck 2026-06-11: tapping a result was
      // navigating to the legacy page). Discussions resolve their topic id from
      // the topic page (one fetch — the suggest payload has no id) and open the
      // discussion modal via a stub card; everything else opens the content
      // sheet. Category pills (.lg-hs-cat) still navigate — they ARE filters.
      panel.addEventListener('click', function (e) {
        var a = e.target.closest && e.target.closest('a.lg-hub-search__item');
        if (!a) return;
        var url = a.getAttribute('data-url') || a.getAttribute('href');
        var kind = a.getAttribute('data-kind') || '';
        var title = a.getAttribute('data-title') || (a.textContent || '').trim();
        try { if (new URL(url, location.href).origin !== location.origin) return; } catch (err) { return; }
        e.preventDefault(); e.stopPropagation();
        close();
        function asContent() { openContentSheet(url, title); }
        if (/discussion|topic|reply|forum/i.test(kind)) {
          fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.text() : ''; })
            .then(function (html) {
              var m = (html || '').match(/data-topic-id="(\d+)"/);
              if (!m) { asContent(); return; }
              var stub = document.createElement('div');
              stub.setAttribute('data-topic-id', m[1]);
              var t = document.createElement('span'); t.className = 'fc-title'; t.textContent = title;
              stub.appendChild(t);
              openRepliesSheet(stub);
            })
            .catch(asContent);
        } else {
          asContent();
        }
      }, true);
      var timer = null, seq = 0;
      // pending=true → instant skeleton (pills + filter row) drawn on keystroke,
      // before the suggest fetch returns; the full render replaces it.
      function render(results, q, pending) {
        if (q !== input.value.trim()) return;     // stale: input moved on
        results = results || [];
        var pills = pillRowFor(q);
        panel.innerHTML = '';
        if (pills) panel.appendChild(pills);      // matching #tags + categories on top
        panel.appendChild(filterRowFor(q));       // any word can filter the feed
        if (!results.length) {
          if (!pending) {
            var n0 = document.createElement('div');
            n0.className = 'lg-hub-search__note';
            n0.textContent = 'No posts match “' + q + '”';
            panel.appendChild(n0);
          }
          open();
          return;
        }
        results.slice(0, 12).forEach(function (r) {
          if (!r || !r.url) return;
          var a = document.createElement('a');
          a.className = 'lg-hub-search__item';
          a.href = r.url;
          a.setAttribute('data-url', r.url);
          a.setAttribute('data-kind', r.kind || 'page');
          a.setAttribute('data-title', r.title || '');
          var k = document.createElement('span');
          k.className = 'lg-hub-search__k';
          k.textContent = (r.kind || 'result').replace(/[_-]/g, ' ');
          var t = document.createElement('span');
          t.className = 'lg-hub-search__t';
          t.textContent = r.title || r.url;
          a.appendChild(k); a.appendChild(t);
          panel.appendChild(a);
        });
        open();
      }
      function query(q) {
        var mine = ++seq;
        fetch('/hub/?suggest=hub&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (d) { if (mine === seq) render((d && d.results) || [], q); })
          .catch(function () { if (mine === seq) close(); });
      }

      input.addEventListener('input', function () {
        var q = input.value.trim();
        clearTimeout(timer);
        if (q.length < 2) { seq++; close(); return; }
        render(null, q, true);                     // instant pills + filter row
        timer = setTimeout(function () { query(q); }, 180);
      });
      input.addEventListener('focus', function () {
        if (input.value.trim().length >= 2 && panel.childNodes.length) open();
      });
      input.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { input.value = ''; close(); input.blur(); }
        // Enter = filter the feed by whatever was typed (the keyboard already
        // shows a Search key via enterkeyhint). Empty + an active ?q= clears it.
        if (e.key === 'Enter') {
          e.preventDefault();
          var q = input.value.trim();
          if (q.length >= 2) { applyFilter(q); return; }
          try {
            if (!q && new URLSearchParams(location.search).get('q')) location.href = '/hub/';
          } catch (err) {}
        }
      });
      // Surface an already-applied keyword filter in the bubble so it's
      // visible and editable (and clearable via empty + Enter).
      try {
        var activeQ = new URLSearchParams(location.search).get('q');
        if (activeQ) input.value = activeQ;
      } catch (err) {}
      // Close when tapping outside the bubble + panel.
      document.addEventListener('pointerdown', function (e) {
        if (!wrap.contains(e.target) && !panel.contains(e.target)) close();
      }, true);
      window.addEventListener('resize', function () {
        if (panel.classList.contains('is-open')) position();
      });
    } catch (e) { /* never let search break the header */ }
  }

  // Mobile only: hide the sticky header on scroll-down, reveal it on the
  // slightest scroll-up (Instagram/Twitter-style). The header is already
  // position:sticky;top:0 site-wide; we just toggle a translateY tuck class.
  // rAF-throttled; asymmetric thresholds so a tiny upward flick pops it back
  // while hiding needs a more deliberate downward scroll. Never tucks near the
  // very top, while the search input is focused, or while the results panel is
  // open. Idempotent.
  function wireHeaderAutoHide() {
    if (!window.matchMedia('(max-width:640px)').matches) return;
    var hdr = document.getElementById('site-header');
    if (!hdr || hdr.getAttribute('data-lg-autohide')) return;
    hdr.setAttribute('data-lg-autohide', '1');
    var lastY = window.pageYOffset || 0, ticking = false;
    function update() {
      ticking = false;
      var y = window.pageYOffset || 0;
      var dy = y - lastY;
      lastY = y;
      var ae = document.activeElement;
      var searching = ae && ae.closest && ae.closest('.lg-hub-search');
      var panelOpen = document.querySelector('.lg-hub-search__panel.is-open');
      if (y < 80 || searching || panelOpen) { hdr.classList.remove('lg-chrome--tuck'); return; }
      if (dy < -2) hdr.classList.remove('lg-chrome--tuck');   // scrolling up -> show
      else if (dy > 8) hdr.classList.add('lg-chrome--tuck');  // scrolling down -> hide
    }
    window.addEventListener('scroll', function () {
      if (!ticking) { ticking = true; requestAnimationFrame(update); }
    }, { passive: true });
  }

  // ── Fast filters ──────────────────────────────────────────────────────────
  // The canonical type/category mute switches are <a href="/hub/?mute_toggle=…">
  // anchors, so every tap was a full page reload — slow, the drawer slammed
  // shut, and flipping several quickly was impossible. We intercept them: flip
  // the switch instantly, hide/show the loaded cards we can map client-side
  // (types via data-kind / .feed-card--topic, categories via data-cat; sub-forum
  // l: tokens reconcile on refresh), keep the drawer open, and after the user
  // stops toggling (debounced) replay the toggles to the server and soft-swap
  // the feed from its authoritative HTML. Any failure falls back to one reload.
  function tokenMatcher(token) {
    var i = token.indexOf(':'); if (i < 0) return null;
    var scope = token.slice(0, i), val = token.slice(i + 1);
    if (scope === 't') {
      if (val === 'discussions') return function (c) { return c.classList.contains('feed-card--topic'); };
      return function (c) { return c.getAttribute('data-kind') === val; };
    }
    if (scope === 'c') return function (c) { return c.getAttribute('data-cat') === val; };
    return null; // l: sub-forum — no card attribute; handled by the refresh
  }
  function applyClientFilter(feed) {
    var muted = [];
    [].slice.call(document.querySelectorAll('.hub-sw')).forEach(function (sw) {
      if (sw.classList.contains('is-on')) return;        // on = visible
      var href = sw.getAttribute('href') || '';
      var k = href.indexOf('mute_toggle=');
      if (k < 0) return;
      var token = decodeURIComponent(href.slice(k + 12).split('&')[0]);
      var fn = tokenMatcher(token);
      if (fn) muted.push(fn);
    });
    [].slice.call(feed.querySelectorAll('.feed-card')).forEach(function (c) {
      c.style.display = muted.some(function (fn) { return fn(c); }) ? 'none' : '';
    });
  }
  function wireFastFilters() {
    if (!window.matchMedia('(max-width:640px)').matches) return; // mobile only — desktop keeps its native behavior
    var feed = document.querySelector('.feed');
    if (!feed || document.body.getAttribute('data-lg-fastfilters')) return;
    document.body.setAttribute('data-lg-fastfilters', '1');

    if (!document.getElementById('lg-fastfilters-css')) {
      var st = document.createElement('style'); st.id = 'lg-fastfilters-css';
      st.textContent = '.feed.lg-feed-syncing{opacity:.55;transition:opacity .15s}' +
        '.hub-sw{transition:background-color .12s,box-shadow .12s}';
      document.head.appendChild(st);
    }

    var queue = [];        // one server-toggle URL per click (replays exactly)
    var pending = false, syncing = false, timer = null;

    function schedule() { pending = true; clearTimeout(timer); timer = setTimeout(sync, 900); }
    function sync() {
      if (!pending || syncing) return;
      syncing = true; pending = false;
      feed.classList.add('lg-feed-syncing');
      var jobs = queue.splice(0), chain = Promise.resolve();
      jobs.forEach(function (url) {
        chain = chain.then(function () {
          return fetch(url, { credentials: 'same-origin' }).then(function () {}, function () {});
        });
      });
      chain.then(function () {
        return fetch(location.href, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.text(); });
      }).then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var fresh = doc.querySelector('.feed');
        if (!fresh) throw new Error('no feed in response');
        feed.innerHTML = fresh.innerHTML;     // cardObserver + relayCards re-wire
        relayCards(document);
        observePreviewCards(document);
        applyClientFilter(feed);
        applyFreshFeed(feed);                 // keep the filtered set freshly shuffled
        feed.classList.remove('lg-feed-syncing');
        syncing = false;
        if (pending) schedule();              // toggles arrived mid-sync
      }).catch(function () {
        try { sessionStorage.setItem('lg-filters-keepopen', '1'); } catch (e) {}
        location.reload();
      });
    }

    document.addEventListener('click', function (ev) {
      var sw = ev.target.closest && ev.target.closest('.hub-sw');
      if (!sw) return;
      ev.preventDefault(); ev.stopPropagation();
      sw.classList.toggle('is-on');             // instant visual flip
      var href = sw.getAttribute('href');
      if (href) queue.push(href);
      applyClientFilter(feed);                   // instant feed response
      schedule();
    }, true);

    // Keep newly infinite-scrolled cards consistent with active mutes.
    if ('MutationObserver' in window) {
      new MutationObserver(function (muts) {
        for (var i = 0; i < muts.length; i++) {
          if (muts[i].addedNodes && muts[i].addedNodes.length) { applyClientFilter(feed); return; }
        }
      }).observe(feed, { childList: true });
    }
  }
  // After the hard-reload fallback, re-open the filters drawer so the user lands
  // back where they were instead of a closed drawer.
  function reopenFiltersIfFlagged() {
    try {
      if (sessionStorage.getItem('lg-filters-keepopen') !== '1') return;
      sessionStorage.removeItem('lg-filters-keepopen');
    } catch (e) { return; }
    document.body.classList.add('nav-open');
    var ham = document.getElementById('bb-ham');
    if (ham) ham.setAttribute('aria-expanded', 'true');
    var ov = document.getElementById('bb-overlay');
    if (ov) ov.setAttribute('aria-hidden', 'false');
  }

  // ── Fresh Feed (RETIRED 2026-06-07) ─────────────────────────────────────────
  // The Hub now has a REAL server-side "Random" sort (front-door default: a
  // popularity-weighted seeded shuffle over the WHOLE DB — surfaces old/popular
  // posts and stays coherent across infinite scroll). That supersedes this old
  // client-side shuffle, which only reordered the already-loaded cards AND injected
  // a DUPLICATE "Fresh" pill next to the server's "Random" tab (Buck: "remove the
  // fresh bubble so it's just random"). Both are now no-ops — the server order is
  // authoritative. Stubs kept so existing call sites stay valid.
  function freshActive() { return false; }
  function applyFreshFeed() { /* server owns Random now — no client reshuffle */ }

  // Hide filter rows whose count is 0 (e.g. empty "Local Looths") so the drawer
  // only lists categories that actually have content. Mobile only. Idempotent.
  function hideEmptyFilterRows() {
    // No-op: empty rows now carry .hub-rail__row--empty server-side and are
    // hidden by a mobile-only CSS rule in forums.css (no DOM touch, no flicker).
  }

  // Fresh pill RETIRED 2026-06-07: the server now renders the real "Random" tab
  // (front-door default), so injecting a client-side "Fresh" pill just duplicated
  // it (Buck: "remove the fresh bubble so it's just random"). No-op now.
  function wireFreshPill() { /* server renders the Random tab; no client pill */ }

  // Keep mobile Hub taps ON the hub (Buck 2026-06-07: "keep it all on the hub, no
  // clicking into the page; only loothprints click through"). Discussions already
  // expand inline (forums.js). CONTENT/CPT cards normally navigate to their own page;
  // on mobile we intercept the post-navigation targets (title link + cover) and open
  // the card's inline comments thread (a modal over the feed) instead — so you stay on
  // the Hub. EXCEPTIONS that still behave normally: data-kind="loothprint" (the only
  // click-through), gated teasers (→ their paywall page), videos (play inline), and any
  // real control / author link. INTERIM: full inline article/video BODY render is a
  // server change in flight (coordinator); until then tapping opens the comments thread.
  // ── In-app content sheet (Buck 2026-06-08) ──────────────────────────────────
  // Tapping an article/video/imgcap/sponsor/event content card on mobile opens a
  // full-screen sheet showing the REAL post — its article body AND comments —
  // rendered chrome-free by the server's ?embed=1 mode (body.lg-embed, no site
  // header/footer; commit 0c35ad8). Inside the iframe, /pwa.js self-suppresses
  // (window.top !== window.self) so no nested tab bar / app-shell leaks in. A
  // history entry is pushed so the phone back-gesture closes the sheet (like the
  // image lightbox) instead of navigating the PWA away. Mobile only.
  var lgCs = null, lgCsHist = false, lgCsScroll = '';

  // (lgMirrorTheme + lgPostPolish removed 2026-06-10 — they only ran from the
  // desktop quick-view, retired by Ian. The embed self-themes (app-settings.js
  // is enqueued in the embed <head>); lgPostPolish's content dark-surface rules
  // now live in the content sheet's lg-cs-embed-css inject below, where the
  // mobile sheet — the only consumer left — actually needs them.)
  function lgCsEnsure() {
    if (lgCs) return;
    if (!document.getElementById('lg-cs-css')) {
      var st = document.createElement('style'); st.id = 'lg-cs-css';
      st.textContent =
        // Pull-up bottom sheet (Buck 2026-06-09: "a popup like a pull-up menu"). A dimmed
        // backdrop + a panel that slides up from the bottom with a rounded top + drag handle.
        // Tap backdrop / drag down / ✕ to dismiss. .is-open shows it; .is-up runs the slide.
        '#looth-content-sheet{position:fixed;inset:0;z-index:2147483550;display:none}' +
        '#looth-content-sheet.is-open{display:block}' +
        '#looth-content-sheet .lcs-backdrop{position:absolute;inset:0;background:rgba(15,16,12,.5);opacity:0;transition:opacity .26s ease}' +
        '#looth-content-sheet.is-up .lcs-backdrop{opacity:1}' +
        '#looth-content-sheet .lcs-panel{position:absolute;left:0;right:0;bottom:0;top:max(6vh,env(safe-area-inset-top,0px));' +
        'display:flex;flex-direction:column;background:var(--lg-cream,#fbfbf8);border-radius:18px 18px 0 0;overflow:hidden;' +
        'box-shadow:0 -10px 36px rgba(0,0,0,.28);transform:translateY(100%);transition:transform .3s cubic-bezier(.32,.72,0,1);will-change:transform}' +
        '#looth-content-sheet.is-up .lcs-panel{transform:translateY(0)}' +
        '#looth-content-sheet .lcs-grab{flex:0 0 auto;height:22px;display:flex;align-items:center;justify-content:center;' +
        'touch-action:none;cursor:grab}' +
        '#looth-content-sheet .lcs-grab::before{content:"";width:40px;height:5px;border-radius:3px;background:var(--lg-line,#d8d2c4)}' +
        '#looth-content-sheet .lcs-bar{flex:0 0 auto;height:42px;display:flex;align-items:center;gap:10px;padding:0 8px 0 12px;' +
        'border-bottom:1px solid var(--lg-line,#e3ddd0);background:var(--lg-cream,#fbfbf8);touch-action:none}' +
        '#looth-content-sheet .lcs-x{width:34px;height:34px;border:0;border-radius:50%;background:var(--lg-sage-tint,#eef2e3);' +
        'color:var(--lg-sage-d,#6b7c52);font-size:19px;line-height:34px;text-align:center;cursor:pointer;flex:0 0 auto}' +
        '#looth-content-sheet .lcs-ttl{flex:1 1 auto;min-width:0;font:700 14px/1.2 var(--lg-font-serif,Georgia,serif);' +
        'color:var(--lg-charcoal,#1a1d1a);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' +
        '#looth-content-sheet .lcs-frame{flex:1 1 auto;width:100%;border:0;display:block;background:var(--lg-cream,#fbfbf8)}' +
        'html[data-lguser-theme="dark"] #looth-content-sheet .lcs-panel,html[data-lguser-theme="dark"] #looth-content-sheet .lcs-bar,' +
        'html[data-lguser-theme="dark"] #looth-content-sheet .lcs-frame{background:#16181a}' +
        'html[data-lguser-theme="dark"] #looth-content-sheet .lcs-ttl{color:#f2f4ee}' +
        'html[data-lguser-theme="dark"] #looth-content-sheet .lcs-grab::before{background:#3a403a}';
      (document.head || document.documentElement).appendChild(st);
    }
    lgCs = document.createElement('div');
    lgCs.id = 'looth-content-sheet'; lgCs.setAttribute('role', 'dialog'); lgCs.setAttribute('aria-modal', 'true'); lgCs.setAttribute('aria-label', 'Post');
    lgCs.innerHTML =
      '<div class="lcs-backdrop"></div>' +
      '<div class="lcs-panel">' +
        '<div class="lcs-grab" aria-hidden="true"></div>' +
        '<div class="lcs-bar"><button class="lcs-x" type="button" aria-label="Close">✕</button><span class="lcs-ttl"></span></div>' +
        '<iframe class="lcs-frame" title="Post" referrerpolicy="same-origin" allow="fullscreen; clipboard-write; web-share" allowfullscreen></iframe>' +
      '</div>';
    (document.body || document.documentElement).appendChild(lgCs);
    lgCs.querySelector('.lcs-x').addEventListener('click', function () { closeContentSheet(); });
    lgCs.querySelector('.lcs-backdrop').addEventListener('click', function () { closeContentSheet(); });
    // Shared panel-drag helpers (used by the grab/header drag AND the in-iframe
    // overscroll-pull-down wired in the frame load handler below).
    var lcsPanel = lgCs.querySelector('.lcs-panel');
    function csDragTo(dy) { lcsPanel.style.transition = 'none'; lcsPanel.style.transform = 'translateY(' + Math.max(0, dy) + 'px)'; }
    function csDragReset() { lcsPanel.style.transition = ''; lcsPanel.style.transform = ''; }
    function csDragEnd(dy) { csDragReset(); if (dy > 110) closeContentSheet(); }
    // Drag the handle/header down to dismiss (snaps back if not dragged far enough).
    (function () {
      var startY = 0, dy = 0, dragging = false;
      function start(e) { startY = (e.touches ? e.touches[0].clientY : e.clientY); dy = 0; dragging = true; }
      function move(e) {
        if (!dragging) return;
        dy = Math.max(0, (e.touches ? e.touches[0].clientY : e.clientY) - startY);
        csDragTo(dy);
        if (dy > 2 && e.cancelable) e.preventDefault();
      }
      function end() { if (!dragging) return; dragging = false; csDragEnd(dy); }
      ['.lcs-grab', '.lcs-bar'].forEach(function (sel) {
        var el = lgCs.querySelector(sel); if (!el) return;
        el.addEventListener('touchstart', start, { passive: true });
        el.addEventListener('touchmove', move, { passive: false });
        el.addEventListener('touchend', end);
        el.addEventListener('mousedown', start);
      });
      document.addEventListener('mousemove', move);
      document.addEventListener('mouseup', end);
    })();
    // Hide the site chrome inside the framed post. CONTENT posts are already
    // chrome-free (body.lg-embed), but DISCUSSION topics (?embed=1) are NOT — without
    // this their site header / nav / footer would show inside the sheet. Same hide-set
    // the desktop quick-view uses. (No theme work here — the embed page themes itself.)
    var _lcsf = lgCs.querySelector('.lcs-frame');
    if (_lcsf) _lcsf.addEventListener('load', function () {
      try {
        var d = _lcsf.contentDocument;
        if (d && !d.getElementById('lg-cs-embed-css')) {
          var st = d.createElement('style'); st.id = 'lg-cs-embed-css';
          st.textContent = '#site-header,.lg-chrome,#site-footer,footer,#looth-tabbar,#looth-pwa-banner,.bb-layout__nav,.nav-tree,.bb-mirror__searchbar--sidebar,.lg-set-gear,#lg-dset-pop{display:none!important}' +
            'body{padding-top:0!important}.bb-layout__content,.bb-layout__content .page{max-width:none!important}' +
            // DARK: discussion post/reply cards (.post) hardcode white and the embed's
            // own dark theme doesn't cover them — recolor so they aren't blinding.
            'html[data-lguser-dark="1"] .post,html[data-lguser-dark="1"] .bbp-reply,html[data-lguser-dark="1"] .bbp-topic{background:#1e2124!important;border-color:#2c312d!important;color:#e5e7e1!important}' +
            'html[data-lguser-dark="1"] .search-form__input{background:#1e2124!important;color:#e5e7e1!important;border-color:#2c312d!important}' +
            // POLISH the forum post/reply cards (Buck 2026-06-09: discussion looked legacy).
            // Drop the thick 4px left-accent strip + 6px radius for the brand card shape.
            '.post,.bbp-reply,.bbp-topic{border-width:1px!important;border-style:solid!important;' +
            'border-radius:16px!important;box-shadow:0 1px 4px rgba(0,0,0,.05)!important;padding:16px!important;margin-bottom:14px!important}' +
            'html:not([data-lguser-dark="1"]) .post,html:not([data-lguser-dark="1"]) .bbp-reply,html:not([data-lguser-dark="1"]) .bbp-topic{border-color:var(--lg-line,#e3ddd0)!important}' +
            // CLEANUP legacy forum chrome (Buck 2026-06-09): drop the green corner
            // hamburger, flatten the gradient category box, and fix the author name
            // contrast (it hardcodes near-black → invisible on the dark card).
            '.corner-hamburger{display:none!important}' +
            '.forum-header,.forum-header--post{background:none!important;background-image:none!important;border:0!important;box-shadow:none!important;border-radius:0!important;padding:4px 2px 8px!important;overflow:visible!important}' +
            '.forum-header__label{display:none!important}' +
            '.forum-header__title,.forum-header__title--link{font-family:var(--lg-font-serif,Georgia,serif)!important;font-size:21px!important;line-height:1.2!important;color:var(--lg-charcoal,#1a1d1a)!important}' +
            '.forum-header__home,.forum-header__parent{color:var(--lg-sage-d,#6b7c52)!important;font-size:12px!important;font-weight:600!important}' +
            '.post__author{font-family:var(--lg-font-serif,Georgia,serif)!important}' +
            'html[data-lguser-dark="1"] .post__author{color:#e5e7e1!important}' +
            // Comment composer: hide the quicktags/format toolbar (same as the post composer) so
            // the "Write a comment" box reads as a clean single line above the keyboard (Buck 2026-06-09).
            '.ntm-quicktags,.quicktags-toolbar,.wp-editor-tools,.bbp-the-content-wrapper .wp-editor-tabs{display:none!important}' +
            // REPLIES as Facebook-style comments (Buck 2026-06-09): avatar + grey rounded
            // bubble (name + text), compact, actions below. The OP (.post--op) stays the card.
            '.post:not(.post--op){display:grid!important;grid-template-columns:34px 1fr!important;column-gap:8px!important;align-items:start!important;background:none!important;border:0!important;box-shadow:none!important;border-radius:0!important;padding:5px 0!important;margin:0 0 2px!important}' +
            'html[data-lguser-dark="1"] .post:not(.post--op){background:none!important}' +
            '.post:not(.post--op) .post__avatar-wrap{width:34px!important;height:34px!important;margin:0!important}' +
            '.post:not(.post--op) .post__avatar-wrap img{border-radius:50%!important;width:100%!important;height:100%!important;object-fit:cover!important}' +
            '.post:not(.post--op) .post__content{display:block!important;min-width:0!important;padding:0!important}' +
            '.post:not(.post--op) .post__head{display:flex!important;align-items:baseline!important;flex-wrap:wrap!important;gap:7px!important;background:var(--lguser-bubble,var(--lg-sage-tint,#eef2e3))!important;border-radius:16px 16px 0 0!important;padding:8px 13px 2px!important;margin:0!important}' +
            '.post:not(.post--op) .post__author{font:700 13px/1.3 var(--lg-font-serif,Georgia,serif)!important;color:var(--lg-charcoal,#1a1d1a)!important}' +
            '.post:not(.post--op) .post__time{font:500 11px/1.2 var(--lg-font-sans,system-ui,sans-serif)!important;color:var(--lg-mute,#6b6f6b)!important}' +
            '.post:not(.post--op) .post__body{background:var(--lguser-bubble,var(--lg-sage-tint,#eef2e3))!important;border-radius:0 0 16px 16px!important;padding:2px 13px 9px!important;margin:0!important;color:var(--lg-ink,#1a1d1a)!important}' +
            '.post:not(.post--op) .post__body p{margin:0 0 6px!important;font-size:14px!important;line-height:1.42!important}' +
            '.post:not(.post--op) .post__body p:last-child{margin-bottom:0!important}' +
            '.post:not(.post--op) .post__actions{display:flex!important;gap:16px!important;margin:5px 0 0 13px!important;padding:0!important;background:none!important;border:0!important}' +
            '.post:not(.post--op) .post__actions button{font:700 12px/1 var(--lg-font-sans,system-ui,sans-serif)!important;color:var(--lg-mute,#6b6f6b)!important;background:none!important;border:0!important;padding:0!important}' +
            // ── CONTENT post (article/video/imgcap/sponsor/event) polish — merged from
            // the retired desktop quick-view's lgPostPolish (Buck 2026-06-10: those
            // dark fixes only ran on desktop; the sheet is the only consumer now).
            // Several .lg-* surfaces hardcode a cream fill and ignore the tokens.
            'html[data-lguser-dark="1"] body,html[data-lguser-dark="1"] .lg-standalone-main{background:#15171a!important}' +
            'html[data-lguser-dark="1"] .lg-post-header__meta-strip,' +
            'html[data-lguser-dark="1"] .lg-callout--links,' +
            'html[data-lguser-dark="1"] .lg-callout--note,' +
            'html[data-lguser-dark="1"] .lg-post-footer__author,' +
            'html[data-lguser-dark="1"] .lg-standalone-comments,' +
            'html[data-lguser-dark="1"] .lg-cmodal__panel,' +
            'html[data-lguser-dark="1"] .lg-cmodal__head{background:#1e2124!important;border-color:#2c312d!important;color:#e5e7e1!important}' +
            'html[data-lguser-dark="1"] .lg-wysiwyg{background:#1e2124!important;color:#e5e7e1!important}' +     // keep its amber accent border
            'html[data-lguser-dark="1"] .lg-wysiwyg p,html[data-lguser-dark="1"] .lg-callout--note p,' +
            'html[data-lguser-dark="1"] .lg-post-footer__author *{color:#e5e7e1!important}' +
            'html[data-lguser-dark="1"] .lg-cmodal__backdrop{background:rgba(0,0,0,.6)!important}' +
            'html[data-lguser-dark="1"] figure.lg-image,html[data-lguser-dark="1"] .lg-image__frame,' +
            'html[data-lguser-dark="1"] .lg-post-footer__card,html[data-lguser-dark="1"] .lg-post-footer__carousel-btn,' +
            'html[data-lguser-dark="1"] .lg-post-footer__card *{background:#1e2124!important;border-color:#2c312d!important;color:#e5e7e1!important}' +
            // MOBILE stacked hero title band (scoped ≤640 so desktop overlay heroes are untouched)
            '@media (max-width:640px){html[data-lguser-dark="1"] .lg-post-header__body{background:#1e2124!important;color:#e5e7e1!important}}' +
            // ── Per-type media pass (Buck 2026-06-10: "articles look ok, most other
            // things don't"): video embeds + inline images fill the sheet width cleanly.
            '.lg-wysiwyg iframe,.lg-post-body iframe,.post__body iframe,.lg-embed-video iframe{width:100%!important;max-width:100%!important;aspect-ratio:16/9;height:auto!important;border:0;border-radius:12px}' +
            '.lg-wysiwyg img,.lg-post-body img,.post__body img,figure.lg-image img{max-width:100%!important;height:auto!important;border-radius:12px}' +
            '.lg-wysiwyg video,.post__body video{width:100%!important;max-width:100%!important;height:auto!important;border-radius:12px;background:#000}' +
            'figure{margin:14px 0!important;max-width:100%!important}' +
            'figcaption{font-size:12.5px;color:var(--lg-mute,#6b6f6b);padding-top:6px}' +
            'html[data-lguser-dark="1"] figcaption{color:#9aa097!important}' +
            // dark-audit 2026-06-10: the post tagline/byline under the title kept its
            // light-theme muted ink → near-invisible on the dark sheet. Raise contrast.
            'html[data-lguser-dark="1"] .lg-post-header__tagline,html[data-lguser-dark="1"] .lg-post-header__byline,' +
            'html[data-lguser-dark="1"] .lg-post-header__pub,html[data-lguser-dark="1"] .lg-post-header__eyebrow{color:#b9bfb6!important}' +
            'html[data-lguser-dark="1"] .lg-post-header__name{color:#e5e7e1!important}' +
            // dark: the tag chips keep their light-theme fill → glaring on the dark
            // sheet (Buck 2026-06-10 "these pills look too brite"). Dark chip + sage text.
            'html[data-lguser-dark="1"] .lg-post-header__chip{background:#243024!important;border-color:transparent!important;color:#b6c79a!important}' +
            'html[data-lguser-dark="1"] .lg-post-header__chip--tier{background:#2e2a1c!important;color:#d8c08a!important}';
          (d.head || d.documentElement).appendChild(st);
        }
        // Overscroll-to-dismiss (Buck 2026-06-09): when the post is scrolled to the
        // very top and you keep pulling DOWN, drag the sheet down (and close past a
        // threshold) instead of rubber-banding. The post lives in this same-origin
        // iframe, so we listen on its document and drive the parent panel directly.
        if (d && !d.__lgCsOverscroll) {
          d.__lgCsOverscroll = 1;
          var sTop = function () { var se = d.scrollingElement || d.documentElement || d.body; return se ? se.scrollTop : 0; };
          var sy = 0, pdy = 0, pulling = false;
          d.addEventListener('touchstart', function (e) {
            pulling = (sTop() <= 0); if (pulling) { sy = e.touches[0].clientY; pdy = 0; }
          }, { passive: true });
          d.addEventListener('touchmove', function (e) {
            if (!pulling) return;
            var dy = e.touches[0].clientY - sy;
            if (sTop() <= 0 && dy > 0) { pdy = dy; csDragTo(dy); if (e.cancelable) e.preventDefault(); }
            else { if (pdy > 0) csDragReset(); pulling = false; pdy = 0; }
          }, { passive: false });
          d.addEventListener('touchend', function () { if (pulling) { pulling = false; csDragEnd(pdy); pdy = 0; } }, { passive: true });
        }
      } catch (e) {}
    });
  }
  function lgCsEmbedUrl(href) {
    try {
      var u = new URL(href, location.href);
      if (u.origin !== location.origin) return null;          // never frame off-site
      u.searchParams.set('embed', '1');
      return u.pathname + u.search + u.hash;
    } catch (e) { return href + (href.indexOf('?') === -1 ? '?' : '&') + 'embed=1'; }
  }
  function openContentSheet(href, title) {
    if (!href) return;
    var url = lgCsEmbedUrl(href);
    if (!url) { location.href = href; return; }               // off-origin → just navigate
    lgCsEnsure();
    var t = lgCs.querySelector('.lcs-ttl'); if (t) t.textContent = title || '';
    var frame = lgCs.querySelector('.lcs-frame');
    frame.src = url;
    lgCs.classList.add('is-open');                              // display:block (panel still translated down)
    // next frames: add .is-up so the panel slides up from translateY(100%) → 0.
    requestAnimationFrame(function () { requestAnimationFrame(function () { if (lgCs) lgCs.classList.add('is-up'); }); });
    lgCsScroll = document.body.style.overflow; document.body.style.overflow = 'hidden';
    if (!lgCsHist) { try { history.pushState({ lgCs: 1 }, ''); lgCsHist = true; } catch (e) {} }
  }
  function closeContentSheet(fromPop) {
    if (!lgCs || !lgCs.classList.contains('is-open')) return;
    lgCs.classList.remove('is-up');                            // slide the panel back down
    var frame = lgCs.querySelector('.lcs-frame');
    setTimeout(function () {                                   // after the slide-out, fully hide + free the iframe
      if (lgCs && !lgCs.classList.contains('is-up')) { lgCs.classList.remove('is-open'); if (frame) frame.removeAttribute('src'); }
    }, 320);
    document.body.style.overflow = lgCsScroll || '';
    if (lgCsHist && !fromPop) { lgCsHist = false; try { history.back(); } catch (e) {} }
    else { lgCsHist = false; }
  }
  window.addEventListener('popstate', function () { if (lgCs && lgCs.classList.contains('is-open')) closeContentSheet(true); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && lgCs && lgCs.classList.contains('is-open')) closeContentSheet(); });

  // (lgShouldPopup / lgInlineExpand retired 2026-06-10 — "the modal is king":
  // every discussion tap opens the modal, no expand-inline-if-small heuristic.)

  // ── Polish the Facebook-style comments composer (Buck 2026-06-09) ───────────
  // The comments live in a same-origin iframe (/archive-api/v0/comments) the
  // forums.js modal opens. Its "Add a comment…" box looked unfinished; inject a
  // clean Messenger-style composer (rounded pill input + sage Post button, pinned
  // to the bottom) into that frame whenever it appears. The frame self-themes, and
  // we mirror the theme tokens too so dark resolves.
  function lgPolishCommentsFrame() {
    var CSS =
      '.lgc-compose{position:sticky!important;bottom:0!important;display:flex!important;align-items:center!important;gap:8px!important;' +
      'background:var(--lg-cream,#fbfbf8)!important;border-top:1px solid var(--lg-line,#e3ddd0)!important;' +
      'padding:10px 12px calc(10px + env(safe-area-inset-bottom,0px))!important;margin:0!important;z-index:5!important}' +
      '.lgc-replyto{order:-1!important;flex-basis:100%!important;margin:0 0 4px!important}.lgc-replyto:empty{display:none!important}' +
      '#lgc-textarea{flex:1 1 auto!important;min-width:0!important;width:auto!important;background:#fff!important;' +
      'border:1px solid #d8d2c4!important;border-radius:22px!important;padding:11px 15px!important;min-height:44px!important;max-height:120px!important;' +
      'font-size:15px!important;line-height:1.35!important;resize:none!important;color:#111!important;box-sizing:border-box!important}' +
      '#lgc-textarea::placeholder{color:#888!important}' +
      '.lgc-actions{flex:0 0 auto!important;display:flex!important;align-items:center!important;gap:6px!important;margin:0!important;padding:0!important}' +
      '.lgc-err{order:-1!important;flex-basis:100%!important;margin:0 0 4px!important;color:var(--lg-rust,#c66845)!important}.lgc-err:empty{display:none!important}' +
      '.lgc-submit{background:var(--lg-sage-d,#6b7c52)!important;color:#fff!important;border:0!important;border-radius:999px!important;' +
      'padding:0 18px!important;height:40px!important;font:700 13px/40px var(--lg-font-sans,system-ui,sans-serif)!important;white-space:nowrap!important}' +
      'html[data-lguser-dark="1"] .lgc-compose{background:#15171a!important;border-top-color:#2c312d!important}' +
      // DARK pass for the THREAD (Buck 2026-06-10 "miscoloration on the comment text
      // bubble"): comments.php is self-contained LIGHT (body #fff, ink #1a1d1a) with
      // zero dark rules, while the pre-paint boot darkens the frame's canvas — so
      // names/text painted near-black on dark. data-lguser-dark is mirrored onto the
      // frame's <html> by inject() below, so these own the dark render. The composer
      // textarea deliberately STAYS the white-box/black-text look (Buck's pick).
      'html[data-lguser-dark="1"] body{background:#1b1e21!important;color:#e5e7e1!important}' +
      'html[data-lguser-dark="1"] .lgc-name,html[data-lguser-dark="1"] .lgc-name a{color:#f2f4ee!important}' +
      'html[data-lguser-dark="1"] .lgc-text{color:#e5e7e1!important}' +
      'html[data-lguser-dark="1"] .lgc-time{color:#80867d!important}' +
      'html[data-lguser-dark="1"] .lgc-body{border-top-color:#2c312d!important}' +
      'html[data-lguser-dark="1"] .lgc-children{border-left-color:#2c312d!important}' +
      'html[data-lguser-dark="1"] .lgc-reply,html[data-lguser-dark="1"] .lgc-edit{color:#9cb37d!important}' +
      'html[data-lguser-dark="1"] .lgc-del{color:#d98a6c!important}' +
      'html[data-lguser-dark="1"] .lgc-edited{color:#80867d!important}' +
      'html[data-lguser-dark="1"] .lgc-empty,html[data-lguser-dark="1"] .lgc-login,html[data-lguser-dark="1"] .lgc-replyto{color:#9aa097!important}' +
      'html[data-lguser-dark="1"] .lgc-rx{background:#222629!important;border-color:#333833!important;color:#cdd0ca!important}' +
      'html[data-lguser-dark="1"] .lgc-rx.is-mine{background:#2a341f!important;border-color:#3d5233!important;color:#b0c693!important}' +
      'html[data-lguser-dark="1"] .lgc-rx-add{background:#222629!important;border-color:#333833!important;color:#9aa097!important}' +
      'html[data-lguser-dark="1"] .lgc-rx-palette{background:#2a2e31!important;border-color:#3a3f3a!important}' +
      'html[data-lguser-dark="1"] .lgc-av{background:#2c312d!important}' +
      'html[data-lguser-dark="1"] .lgc-editbox textarea{background:#fff!important;color:#111!important}';
    function inject(f) {
      try {
        var d = f.contentDocument; if (!d || !d.documentElement) return;
        var src = document.documentElement, s = src.style, dst = d.documentElement;   // mirror theme so tokens resolve
        for (var i = 0; i < s.length; i++) { var p = s[i]; if (p.lastIndexOf('--lg', 0) === 0) dst.style.setProperty(p, s.getPropertyValue(p)); }
        var dk = src.getAttribute('data-lguser-dark'); if (dk != null) dst.setAttribute('data-lguser-dark', dk);
        if (d.getElementById('lg-cc-css')) return;
        var st = d.createElement('style'); st.id = 'lg-cc-css'; st.textContent = CSS;
        (d.head || d.documentElement).appendChild(st);
      } catch (e) {}
    }
    function hook(f) {
      if (!f || f.getAttribute('data-lg-cc')) return;
      f.setAttribute('data-lg-cc', '1');
      inject(f);
      f.addEventListener('load', function () { inject(f); });
    }
    function scan() {
      var list = document.querySelectorAll('iframe[src*="archive-api/v0/comments"], .lg-cmodal__frame');
      for (var i = 0; i < list.length; i++) hook(list[i]);
    }
    try {
      var pending = false;
      var mo = new MutationObserver(function () {            // debounced — the comments frame is added deep in the tree
        if (pending) return; pending = true;
        setTimeout(function () { pending = false; scan(); }, 150);
      });
      // attributeFilter src: the frame is added first, then forums.js sets its src
      // (an attribute change, not childList) — without this we'd scan too early and miss it.
      mo.observe(document.body || document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['src'] });
    } catch (e) {}
    scan();
  }

  function keepContentOnHub() {
    if (document.body.getAttribute('data-lg-keephub')) return;
    document.body.setAttribute('data-lg-keephub', '1');
    document.addEventListener('click', function (e) {
      try {
        if (!window.matchMedia('(max-width:640px)').matches) return;

        // ── Discussion topics (Buck 2026-06-09: "pop up everything on mobile, match
        // desktop"). Previously a topic tap expanded inline; now tapping its title or
        // post body/excerpt opens the SAME full-screen sheet (iframe of the topic
        // ?embed=1, chrome hidden on load) the desktop quick-view uses. Reactions /
        // reply / author links / the expand caret still self-handle (inline).
        var topic = e.target.closest('.feed-card--topic');
        if (topic && !e.target.closest('.feed-card--content')) {
          // MODAL IS KING (Buck+Ian call 2026-06-10, finalized 2026-06-10 session 2):
          // EVERY discussion tap opens the mobile discussion modal — the upgraded
          // #looth-rep-sheet (OP via /?body= + Facebook-style replies + composer),
          // our buck-lane clone of the desktop §4e modal. No inline-expand heuristic.
          // If the fork's §4e ever un-gates for mobile, it claims the tap first
          // (earlier-registered capture listener calls preventDefault) — defer to it.
          if (e.defaultPrevented) return;
          // Video covers no longer play inline on mobile (Ian 2026-06-17) — drop
          // .fc-cover--video from the exempt list so tapping a topic video cover
          // opens the discussion like any other topic tap.
          if (e.target.closest('button, a[href*="/u/"], .lg-act, .lg-act-replies, .lg-card-actions, .fcr, .fcr-palette, [data-comments], video, iframe, .feed-card__read-more, .feed-card__expand, .fc-readmore, .reply-stub, .fc-reply')) return;
          var tOnText = e.target.closest('.feed-card__title a, .fc-title a, .feed-card__title, .fc-title, .feed-card__op-excerpt, .fc-excerpt, .feed-card__op, .feed-card__full-body, .fc-full-body, .fc-cover, .feed-card__cover');
          if (!tOnText) return;
          e.preventDefault(); e.stopPropagation();              // beat forums.js inline expand
          openRepliesSheet(topic);
          return;
        }

        var card = e.target.closest && e.target.closest('.feed-card--content');
        if (!card) return;

        // (A) The REPLY action opens the Facebook-style comments modal (.lg-cmodal,
        // archive-api/v0/comments) via the card's [data-comments] trigger — NOT the
        // forum-thread sheet. (Buck 2026-06-09: that modal IS the comments view he wants.)
        // Claim the event BEFORE the trigger lookup (audit 2026-06-11 H3: anon users
        // have no comments button → the tap used to bubble on to a page navigation);
        // with no trigger, fall back to the content sheet — comments render in the embed.
        if (e.target.closest('.lg-act-replies')) {
          e.preventDefault(); e.stopPropagation();
          var cmr = card.querySelector('[data-comments], .feed-card__comments-btn');
          if (cmr) { cmr.click(); return; }
          // No comments button (anon) → just go to the post (Ian 2026-06-17: CPT
          // cards click through to the full page, no content-sheet modal).
          var cl0 = card.querySelector('.fc-title a, .feed-card__title a');
          var ch0 = (cl0 && cl0.href) || card.getAttribute('data-href');
          if (ch0) location.href = ch0;
          return;
        }

        // (B) CLICK THROUGH (Ian 2026-06-17): tapping a content/CPT card (title /
        // cover / excerpt / body — incl. video covers, which no longer play inline)
        // NAVIGATES to the full standalone post page, which is mobile-reactive
        // (fits, no horizontal slide, pinch-zoom). The old #looth-content-sheet
        // pull-up modal is gone. Mobile only — this handler is 640-gated above.
        if (e.defaultPrevented) return;
        if (e.target.closest('button, a[href*="/u/"], .lg-act, .lg-card-actions, .fcr, .fcr-palette, [data-comments], video, iframe, .reply-stub, .fc-save')) return;
        var ca = e.target.closest('a[href]');
        var clink = card.querySelector('.fc-title a[href], .feed-card__title a[href], .fc-cover a[href], .feed-card__cover a[href]');
        var chref = (ca && ca.href) || (clink && clink.href) || card.getAttribute('data-href');
        if (!chref) return;
        e.preventDefault(); e.stopPropagation();
        location.href = chref;            // full-page clickthrough — no modal
      } catch (err) {}
    }, true);                                                 // capture: run before forums.js
  }

  // (DESKTOP quick-view modal RETIRED 2026-06-10 — Ian: "no modals for cpts,
  // only discussions". Title/cover anchors navigate to the post page.)

  // ── Loothprint inline sheet (Buck 2026-06-08) ───────────────────────────────
  // Tapping a loothprint stays on the Hub and opens a sheet with the print's cover/
  // title + a Download button and an Email/Share-the-file action. The download file
  // (wp-content/uploads/*.zip|stl|3mf…) lives on the loothprint page, so we fetch that
  // page same-origin, extract the file link(s), and present them. No navigation.
  function lpEsc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function lpInjectStyles() {
    if (document.getElementById('looth-lp-style')) return;
    var css = [
      '#looth-lp-sheet{position:fixed;inset:0;z-index:2147483500;display:none}',
      '#looth-lp-sheet.is-open{display:block}',
      '#looth-lp-sheet .llp-back{position:absolute;inset:0;background:rgba(26,29,26,.55)}',
      '#looth-lp-sheet .llp-card{position:absolute;left:10px;right:10px;bottom:10px;max-height:88vh;overflow:auto;-webkit-overflow-scrolling:touch;background:var(--lg-cream,#fbfbf8);border-radius:18px;padding:0 0 14px;box-shadow:0 -8px 30px rgba(26,29,26,.32);font:15px/1.45 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:var(--lg-ink,#323532);animation:looth-pwa-up .26s ease}',
      '#looth-lp-sheet .llp-cover{width:100%;max-height:200px;object-fit:cover;display:block;border-radius:18px 18px 0 0;background:var(--lg-sage-tint,#eef2e3)}',
      '#looth-lp-sheet .llp-x{position:absolute;top:8px;right:10px;width:32px;height:32px;border:0;border-radius:50%;background:rgba(26,29,26,.5);color:#fff;font-size:20px;line-height:30px;cursor:pointer}',
      '#looth-lp-sheet .llp-body{padding:14px 16px 4px}',
      '#looth-lp-sheet .llp-kind{font:700 10px/1 var(--lg-font-sans,system-ui);letter-spacing:.08em;text-transform:uppercase;color:var(--lg-sage-d,#6b7c52)}',
      '#looth-lp-sheet .llp-t{font:700 18px/1.25 var(--lg-font-serif,Georgia,serif);color:var(--lg-charcoal,#1a1d1a);margin:5px 0 8px}',
      '#looth-lp-sheet .llp-ex{font-size:14px;color:var(--lg-ink,#323532)}',
      '#looth-lp-sheet .llp-desc{font-size:14.5px;line-height:1.55;color:var(--lg-ink,#323532);margin-top:8px}',
      '#looth-lp-sheet .llp-desc p{margin:0 0 9px}',
      '#looth-lp-sheet .llp-acts{padding:12px 16px 2px;display:flex;flex-direction:column;gap:9px}',
      '#looth-lp-sheet .llp-btn{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;box-sizing:border-box;text-decoration:none;border:0;cursor:pointer;border-radius:12px;padding:13px;font:600 15px/1 var(--lg-font-sans,system-ui)}',
      '#looth-lp-sheet .llp-dl{background:var(--lg-sage,#87986a);color:#fff}',
      '#looth-lp-sheet .llp-dl:active{background:var(--lg-sage-d,#6b7c52)}',
      '#looth-lp-sheet .llp-email{background:var(--lg-sage-tint,#eef2e3);color:var(--lg-sage-d,#6b7c52)}',
      '#looth-lp-sheet .llp-btn svg{width:18px;height:18px;flex:0 0 auto}',
      '#looth-lp-sheet .llp-open{display:block;text-align:center;color:var(--lg-mute,#6b6f6b);font-weight:600;padding:8px;text-decoration:underline}',
      '#looth-lp-sheet .llp-note{padding:8px 16px;color:var(--lg-mute,#6b6f6b);font-size:13px}'
    ].join('');
    var s = document.createElement('style'); s.id = 'looth-lp-style'; s.textContent = css;
    (document.head || document.documentElement).appendChild(s);
  }
  // Share the loothprint file. MUST run synchronously inside the click gesture —
  // the old version fetched the blob first and THEN called navigator.share, but by
  // the time the async fetch resolved the transient user-activation had expired, so
  // share() silently rejected and the button "did nothing" (Buck 2026-06-08). Now the
  // blob is pre-fetched in the background (openLoothprintSheet) and passed in as a
  // ready File; we share it (or fall back to the link) without any awaiting.
  function lpShare(file, url, title) {
    try {
      if (file && navigator.canShare && navigator.canShare({ files: [file] })) {
        navigator.share({ files: [file], title: title, text: 'Loothprint: ' + title }).catch(function () {});
        return;
      }
      if (navigator.share) {
        navigator.share({ title: title, text: 'Loothprint: ' + title, url: url }).catch(function () {});
        return;
      }
      navigator.clipboard.writeText(url).then(
        function () { if (typeof lgToast === 'function') lgToast('Download link copied'); },
        function () {}
      );
    } catch (e) {
      try { navigator.clipboard.writeText(url); if (typeof lgToast === 'function') lgToast('Download link copied'); } catch (_) {}
    }
  }
  // Download a loothprint file reliably. The old plain <a href download> pointed
  // straight at an application/octet-stream URL — in the installed (standalone) PWA
  // that tried to NAVIGATE the app to the file (broke / did nothing), and iOS often
  // ignores the download attribute (Buck 2026-06-08: "download didn't work"). Fetch
  // the bytes into a blob and save via a blob URL — no app navigation. `<a download>`
  // doesn't need a user gesture, so the async fetch is fine.
  function lpDownload(url, name) {
    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.blob() : Promise.reject(); })
      .then(function (blob) {
        var u = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = u; a.download = name || 'file'; a.rel = 'noopener';
        document.body.appendChild(a); a.click();
        setTimeout(function () { a.remove(); URL.revokeObjectURL(u); }, 6000);
      })
      .catch(function () { try { window.open(url, '_blank'); } catch (e) { location.href = url; } });
  }
  // Page popup. For a loothprint CARD: openLoothprintSheet(card). For a search
  // result (any kind): openLoothprintSheet(null, {href,title,kind}) — Buck
  // 2026-06-08: search taps open a popup, not the desktop page; loothprints show
  // full details (description + Download/Email). Pulls the page's description so
  // it's "the entire details," and only loothprints get the file actions.
  function openLoothprintSheet(card, opts) {
    lpInjectStyles();
    opts = opts || {};
    var href = opts.href || (card && card.getAttribute('data-href')) || '';
    var title = opts.title || (card && (function () { var t = card.querySelector('.feed-card__title, .fc-title'); return t ? (t.textContent || '').trim() : ''; })()) || 'Details';
    var kind = (opts.kind || (card ? 'loothprint' : 'page') || '').toString().toLowerCase();
    var isLoothprint = /loothprint/.test(kind) || (!!card && card.getAttribute('data-kind') === 'loothprint');
    var coverImg = card && card.querySelector('.feed-card__cover-img, .fc-cover img, .feed-card__cover img');
    var cover = coverImg ? (coverImg.currentSrc || coverImg.getAttribute('src') || '') : (opts.cover || '');
    var exEl = card && card.querySelector('.feed-card__op-excerpt, .fc-excerpt');
    var excerpt = exEl ? (exEl.textContent || '').trim().slice(0, 180) : '';
    var kindLabel = isLoothprint ? 'Loothprint' : (kind.replace(/[_-]/g, ' ') || 'Details');

    var sheet = document.getElementById('looth-lp-sheet');
    if (!sheet) {
      sheet = document.createElement('div'); sheet.id = 'looth-lp-sheet'; sheet.setAttribute('role', 'dialog');
      sheet.setAttribute('aria-label', 'Details');
      (document.body || document.documentElement).appendChild(sheet);
      sheet.addEventListener('click', function (e) { if (e.target.closest('[data-llp-close]')) sheet.classList.remove('is-open'); });
    }
    sheet.innerHTML =
      '<div class="llp-back" data-llp-close></div>' +
      '<div class="llp-card">' +
        (cover ? '<img class="llp-cover" src="' + lpEsc(cover) + '" alt="">' : '') +
        '<button class="llp-x" type="button" aria-label="Close" data-llp-close>&times;</button>' +
        '<div class="llp-body"><div class="llp-kind">' + lpEsc(kindLabel) + '</div>' +
          '<div class="llp-t">' + lpEsc(title) + '</div>' +
          (excerpt ? '<div class="llp-ex">' + lpEsc(excerpt) + '</div>' : '') +
          '<div class="llp-desc" id="llp-desc"></div>' +
        '</div>' +
        '<div class="llp-acts" id="llp-acts">' + (isLoothprint ? '<div class="llp-note">Loading the print file…</div>' : '') + '</div>' +
      '</div>';
    sheet.classList.add('is-open');

    if (!href) return;
    fetch(href, { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.text() : Promise.reject(); })
      .then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        // Description: the page's main content → "entire details" in the popup.
        var descBox = sheet.querySelector('#llp-desc');
        var descEl = doc.querySelector('.entry-content, .bb-card-content, .lg-event-header__detail, .wp-block-post-content, article .content, main');
        if (descBox && descEl) {
          var ps = descEl.querySelectorAll('p'); var out = '';
          for (var pi = 0; pi < ps.length && pi < 12; pi++) { var pt = (ps[pi].textContent || '').trim(); if (pt) out += '<p>' + lpEsc(pt) + '</p>'; }
          descBox.innerHTML = out || ('<p>' + lpEsc((descEl.textContent || '').trim().slice(0, 800)) + '</p>');
        }
        // Cover fallback (og:image / first content image) when we had none from a card.
        if (!cover) {
          var og = doc.querySelector('meta[property="og:image"]');
          var cim = (og && og.getAttribute('content')) || (descEl && descEl.querySelector('img') && descEl.querySelector('img').getAttribute('src'));
          if (cim) { try { cim = new URL(cim, href).href; } catch (e) {} var cardEl = sheet.querySelector('.llp-card'); if (cardEl && !cardEl.querySelector('.llp-cover')) { var im = document.createElement('img'); im.className = 'llp-cover'; im.src = cim; cardEl.insertBefore(im, cardEl.firstChild.nextSibling); } }
        }
        var acts = sheet.querySelector('#llp-acts'); if (!acts) return;
        if (!isLoothprint) { acts.innerHTML = ''; return; }      // non-loothprint: details only, no file actions
        var DLRX = /\.(zip|stl|3mf|step|stp|f3d|gcode|rar|7z|obj)(\?|$)/i;
        var seen = {}, files = [];
        [].forEach.call(doc.querySelectorAll('a[href]'), function (a) {
          var u = a.getAttribute('href') || ''; if (!u) return;
          var abs; try { abs = new URL(u, href).href; } catch (e) { return; }
          if (!DLRX.test(abs) || seen[abs]) return; seen[abs] = 1;
          files.push({ url: abs, name: decodeURIComponent(abs.split('/').pop().split('?')[0]) });
        });
        // No "Open the full loothprint" link here — that full listing view is for
        // desktop only (Buck 2026-06-08). The mobile sheet is download + share.
        // Tier-gated viewer: the page renders a gate CTA instead of the file link,
        // so zero extracted files means "your tier can't see it", not "no file".
        // Say so honestly instead of the misleading "no downloadable file".
        if (!files.length && doc.querySelector('.lg-gate-cta--download,[data-lg-gate="download"]')) {
          acts.innerHTML = '<div class="llp-note">The print file is for higher-tier members. Upgrade your membership to download it.</div>';
          return;
        }
        if (!files.length) { acts.innerHTML = '<div class="llp-note">No downloadable file found for this print.</div>'; return; }
        // Pre-fetch the file blob NOW so the share button can attach the real file
        // synchronously within its click gesture (see lpShare). Non-blocking.
        var shareFile = null;
        fetch(files[0].url, { credentials: 'same-origin' })
          .then(function (r) { return r.ok ? r.blob() : null; })
          .then(function (b) { if (b) shareFile = new File([b], files[0].name, { type: b.type || 'application/octet-stream' }); })
          .catch(function () {});
        var dlIco = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"/><path d="m7 12 5 5 5-5"/><path d="M5 21h14"/></svg>';
        var mailIco = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>';
        var out = files.map(function (f) {
          return '<button class="llp-btn llp-dl" type="button" data-llp-dl data-url="' + lpEsc(f.url) + '" data-name="' + lpEsc(f.name) + '">' + dlIco + 'Download ' + (files.length > 1 ? lpEsc(f.name) : 'file') + '</button>';
        }).join('');
        out += '<button class="llp-btn llp-email" type="button" data-llp-email>' + mailIco + 'Email / share the file</button>';
        acts.innerHTML = out;
        [].forEach.call(acts.querySelectorAll('[data-llp-dl]'), function (b) {
          b.addEventListener('click', function () { lpDownload(b.getAttribute('data-url'), b.getAttribute('data-name')); });
        });
        var em = acts.querySelector('[data-llp-email]');
        if (em) em.addEventListener('click', function () { lpShare(shareFile, files[0].url, title); });
      })
      .catch(function () {
        var acts = sheet.querySelector('#llp-acts');
        if (acts) acts.innerHTML = isLoothprint ? '<div class="llp-note">Couldn’t load the file right now. Try again in a moment.</div>' : '';
        var db = sheet.querySelector('#llp-desc'); if (db && !db.innerHTML) db.innerHTML = '<div class="llp-note">Couldn’t load the details right now.</div>';
      });
  }
  // (Search-preview popup RETIRED 2026-06-10 — same rule: search results
  // navigate to the post page.)

  // ── Shared video-teardown guard (Ian 2026-06-17) ───────────────────────────
  // BOTH video engines (this auto-stop + the scroll-autoplay below) must NOT tear
  // a video down while it's in iOS's native fullscreen player. iOS does not report
  // iframe fullscreen (document.fullscreenElement stays null — the real <video> is
  // inside the cross-origin iframe), and entering fullscreen auto-rotates to
  // landscape, firing the observers with the host non-intersecting → the iframe got
  // removed ("video disappears in fullscreen + landscape"). We can't detect it, so
  // we suspend teardown around an orientation change + while the page is hidden or
  // an iframe is focused. Bound once on load.
  var lgVidOrientLockUntil = 0;
  function lgVidArmLock() { lgVidOrientLockUntil = Date.now() + 4000; }
  window.addEventListener('orientationchange', lgVidArmLock);
  if (window.screen && screen.orientation && screen.orientation.addEventListener) {
    try { screen.orientation.addEventListener('change', lgVidArmLock); } catch (e) {}
  }
  function lgVideoProtected() {
    if (document.fullscreenElement || document.webkitFullscreenElement) return true;
    if (document.hidden) return true;                 // native player / backgrounded
    if (Date.now() < lgVidOrientLockUntil) return true; // just rotated → don't tear down
    var ae = document.activeElement;
    return !!(ae && ae.tagName === 'IFRAME');           // user is inside the player
  }

  // ── Stop an inline YouTube video when it scrolls off-screen (Buck 2026-06-08) ──
  // forums.js plays a content video by injecting an iframe.fc-video (autoplay, no JS-API)
  // into the .fc-cover--video host. Once it scrolls out of view it would keep playing
  // audio; remove the iframe when its host leaves the viewport (the thumb stays, so it
  // reverts to the play facade). (True PiP/float isn't clean — reparenting an iframe
  // reloads it — so per Buck we stop instead.)
  function wireVideoAutoStop() {
    if (document.body.getAttribute('data-lg-vidstop')) return;
    document.body.setAttribute('data-lg-vidstop', '1');
    if (!('IntersectionObserver' in window) || !('MutationObserver' in window)) return;
    function watch(iframe) {
      var host = iframe.closest && iframe.closest('.fc-cover--video');
      if (!host) return;
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) {
          // Don't remove a video that's gone FULLSCREEN — fullscreen pushes the
          // feed behind it out of view, so the host reads as non-intersecting; the
          // old code then yanked the iframe and fullscreen "warped back" instantly
          // (Buck bug 2026-06-08). iOS doesn't report iframe fullscreen, so use the
          // shared guard (orientation lock / hidden / focused iframe) — Ian 6/17.
          if (lgVideoProtected()) return;
          if (!en.isIntersecting && iframe.parentNode) {
            iframe.parentNode.removeChild(iframe);   // stop playback; thumb facade returns
            io.disconnect();
          }
        });
      }, { threshold: 0 });
      io.observe(host);
    }
    [].forEach.call(document.querySelectorAll('.fc-cover--video iframe.fc-video'), watch);
    var root = document.getElementById('hub-feed-results') || document.querySelector('.feed') || document.body;
    new MutationObserver(function (muts) {
      muts.forEach(function (m) {
        [].forEach.call(m.addedNodes || [], function (n) {
          if (n.nodeType !== 1) return;
          if (n.matches && n.matches('iframe.fc-video')) watch(n);
          else if (n.querySelector) { var f = n.querySelector('iframe.fc-video'); if (f) watch(f); }
        });
      });
    }).observe(root, { childList: true, subtree: true });
  }

  // ── Autoplay feed videos MUTED as they scroll into view (Buck 2026-06-08:
  // "feel like Instagram"). Feed videos are click-to-play; this watches the
  // .fc-cover--video hosts and, for the SINGLE most-visible one (≥60%), injects a
  // muted+playsinline+autoplay iframe so it plays silently (mobile blocks sound
  // autoplay; YouTube shows its own tap-to-unmute). Only one plays at a time; the
  // auto iframe is removed when it leaves view (the thumb facade returns) — but
  // NEVER while fullscreen (would warp back). User-clicked (sound) iframes are
  // left alone. Mobile only; desktop keeps click-to-play. ─────────────────────
  // YouTube IFrame-API postMessage (iframe must carry enablejsapi=1).
  function ytPost(f, func) { try { f.contentWindow.postMessage(JSON.stringify({ event: 'command', func: func, args: [] }), '*'); } catch (e) {} }
  function ytIdFrom(src) { var m = (src || '').match(/\/embed\/([\w-]{6,})/); return m ? m[1] : ''; }
  // Tap-to-unmute overlay (Buck 2026-06-08): a muted autoplay video shows a small
  // speaker-off badge; the FIRST tap unmutes it + removes the overlay, so every tap
  // after that reaches YouTube's own controls. Applies to cover + inline videos.
  function lgUnmuteCss() {
    if (document.getElementById('lg-unmute-css')) return;
    var s = document.createElement('style'); s.id = 'lg-unmute-css';
    s.textContent =
      '.lg-unmute{position:absolute;inset:0;z-index:6;background:transparent;border:0;padding:0;margin:0;cursor:pointer;display:flex;align-items:flex-end;justify-content:flex-end}' +
      '.lg-unmute__b{margin:0 10px 10px 0;min-width:34px;height:34px;padding:0 11px;border-radius:999px;background:rgba(0,0,0,.6);color:#fff;display:flex;align-items:center;gap:6px;font:600 12px/1 var(--lg-font-sans,system-ui,sans-serif)}' +
      '.lg-unmute__b svg{width:17px;height:17px;flex:0 0 auto}';
    (document.head || document.documentElement).appendChild(s);
  }
  function addUnmuteOverlay(host, f) {
    if (host.querySelector('.lg-unmute')) return;
    lgUnmuteCss();
    if (getComputedStyle(host).position === 'static') host.style.position = 'relative';
    var ov = document.createElement('button'); ov.type = 'button'; ov.className = 'lg-unmute';
    ov.innerHTML = '<span class="lg-unmute__b"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H2v6h4l5 4z"/><path d="m22 9-6 6M16 9l6 6"/></svg>Tap to unmute</span>';
    ov.addEventListener('click', function (e) {
      e.preventDefault(); e.stopPropagation();
      // If the first tap lands where YouTube's fullscreen button sits (the
      // player's bottom-right corner), do BOTH: unmute AND go fullscreen —
      // instead of the overlay eating the click (Buck 2026-06-11).
      var goFs = false;
      try {
        var r = ov.getBoundingClientRect();
        goFs = (e.clientX > r.right - 72) && (e.clientY > r.bottom - 56);
      } catch (err) {}
      ytPost(f, 'unMute'); ytPost(f, 'playVideo');
      f.setAttribute('data-lg-unmuted', '1');
      ov.parentNode && ov.parentNode.removeChild(ov);    // first tap done → taps now reach YouTube
      if (goFs) {
        try {
          var fn = f.requestFullscreen || f.webkitRequestFullscreen;
          if (fn) { var pr = fn.call(f); if (pr && pr.catch) pr.catch(function () {}); }
        } catch (err) {}
      }
    });
    host.appendChild(ov);
  }
  // ── Inline-play video-link cards (Buck 2026-06-12) ──────────────────────────
  // Some ENTITLED content cards (e.g. kind "shorty") render as a bare thumbnail
  // + the raw youtube URL in the excerpt — the video only plays after clicking
  // through. Promote the cover into a real video host
  // (.fc-cover--video[data-yt-play]) so the existing mobile autoplay/unmute/
  // auto-stop/single-video machinery plays it RIGHT ON THE CARD, and hide the
  // raw link text. Gated teasers are never touched (their payload has no URL
  // anyway). Mobile only; desktop = coord lane (server fix asked: _feed.php
  // should emit data-yt-play for these kinds, which makes this pass a no-op).
  function lgYtIdFromUrl(u) {
    var m = (u || '').match(/(?:youtu\.be\/|youtube\.com\/(?:watch\?[^#\s]*v=|embed\/|shorts\/|live\/))([\w-]{6,})/);
    return m ? m[1] : '';
  }
  function promoteVideoLinkCards() {
    if (!window.matchMedia('(max-width:640px)').matches) return;
    var cards = document.querySelectorAll('.feed-card--content:not([data-lg-vidpromo])');
    var promoted = false;
    for (var i = 0; i < cards.length; i++) {
      var card = cards[i];
      card.setAttribute('data-lg-vidpromo', '1');
      if (card.classList.contains('feed-card--gated') || card.getAttribute('data-gated') === '1') continue;
      var cover = card.querySelector('.fc-cover');
      if (!cover || cover.classList.contains('fc-cover--gated')) continue;
      var ex = card.querySelector('.fc-excerpt');
      if (!ex) continue;
      var id = '', hideEls = [];
      var links = ex.querySelectorAll('a[href]');
      for (var k = 0; k < links.length; k++) {
        var cand = lgYtIdFromUrl(links[k].getAttribute('href'));
        if (!cand) continue;
        if (!id) id = cand;
        // hide the matched anchor (its whole <p> when the link is all it holds)
        var par = links[k].parentElement;
        hideEls.push((par && par.tagName === 'P' && par.textContent.trim() === (links[k].textContent || '').trim()) ? par : links[k]);
      }
      if (!id) {
        // raw text URL (this kind's excerpt isn't even autolinked)
        var ps = ex.querySelectorAll('p');
        for (var p2 = 0; p2 < ps.length; p2++) {
          var t = (ps[p2].textContent || '').trim();
          var cand2 = lgYtIdFromUrl(t);
          if (cand2 && /^https?:\/\/\S+$/.test(t)) { id = cand2; hideEls.push(ps[p2]); break; }
        }
      }
      var isVideoCover = cover.classList.contains('fc-cover--video');
      if (!isVideoCover) {
        if (!id) continue;
        cover.classList.add('fc-cover--video');
        cover.setAttribute('data-yt-play', id);
        try { if (getComputedStyle(cover).position === 'static') cover.style.position = 'relative'; } catch (e) {}
        promoted = true;
      }
      // The raw URL is noise on ANY card whose cover already plays the video —
      // server-rendered video kinds show it too (Buck 2026-06-12), not just the
      // shorty cards this pass promotes.
      for (var h = 0; h < hideEls.length; h++) hideEls[h].style.display = 'none';
    }
    if (promoted) {
      // nudge the autoplay engine's childList observer so new hosts get observed
      var root = document.getElementById('hub-feed-results') || document.querySelector('.feed');
      if (root) {
        var n = document.createElement('i');
        n.style.display = 'none';
        root.appendChild(n);
        setTimeout(function () { if (n.parentNode) n.parentNode.removeChild(n); }, 0);
      }
    }
  }
  var lgVidPromoMo = null;
  function wireVideoLinkCards() {
    return;   // DISABLED on mobile (Ian 2026-06-17): no inline video on mobile, so
              // don't promote content cards into play-on-card video hosts — they
              // click through to the post instead.
    if (!window.matchMedia('(max-width:640px)').matches) return;
    promoteVideoLinkCards();
    setTimeout(promoteVideoLinkCards, 800); setTimeout(promoteVideoLinkCards, 2500);
    if (!lgVidPromoMo && 'MutationObserver' in window) {
      var t = null;
      var root = document.getElementById('hub-feed-results') || document.querySelector('.feed') || document.body;
      lgVidPromoMo = new MutationObserver(function () {
        if (t) return;
        t = setTimeout(function () { t = null; promoteVideoLinkCards(); }, 250);
      });
      lgVidPromoMo.observe(root, { childList: true, subtree: true });
    }
  }

  function wireVideoAutoplay() {
    return;   // DISABLED on mobile (Ian 2026-06-17): no inline video on mobile —
              // feed videos are static thumbnails that click through to the post.
    if (!window.matchMedia('(max-width:640px)').matches) return;
    if (document.body.getAttribute('data-lg-vidauto')) return;
    document.body.setAttribute('data-lg-vidauto', '1');
    if (!('IntersectionObserver' in window)) return;
    var ratios = (typeof WeakMap !== 'undefined') ? new WeakMap() : null;
    var rmap = ratios || { _k: [], _v: [], get: function (k) { var i = this._k.indexOf(k); return i < 0 ? 0 : this._v[i]; }, set: function (k, v) { var i = this._k.indexOf(k); if (i < 0) { this._k.push(k); this._v.push(v); } else this._v[i] = v; } };

    // Shared teardown guard (orientation lock / hidden / focused iframe) — see
    // lgVideoProtected above. iOS doesn't report iframe fullscreen, so this is how
    // both engines avoid killing a fullscreen+landscape video (Ian 2026-06-17).
    function fs() { return lgVideoProtected(); }
    function isInline(host) { return host.classList && host.classList.contains('bb-embed--video'); }
    function ensurePlaying(host) {
      if (isInline(host)) {                              // inline body video (existing iframe)
        var inf = host.querySelector('iframe'); if (!inf) return;
        if (!inf.getAttribute('data-lg-auto')) {         // first play → autoplay muted + enable JS API
          var iid = ytIdFrom(inf.src); if (!iid) return;
          inf.setAttribute('data-lg-auto', '1');
          inf.setAttribute('allow', 'accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture; fullscreen');
          inf.src = 'https://www.youtube.com/embed/' + iid + '?autoplay=1&mute=1&playsinline=1&rel=0&modestbranding=1&enablejsapi=1';
          addUnmuteOverlay(host, inf);
        } else { ytPost(inf, 'playVideo'); }             // back in view → resume (don't re-mute)
        return;
      }
      if (host.querySelector('iframe')) return;          // cover already playing (auto or clicked)
      var id = host.getAttribute('data-yt-play'); if (!id) return;
      var f = document.createElement('iframe');
      f.className = 'fc-video'; f.setAttribute('data-lg-auto', '1');
      f.src = 'https://www.youtube.com/embed/' + encodeURIComponent(id) + '?autoplay=1&mute=1&playsinline=1&rel=0&modestbranding=1&enablejsapi=1';
      f.title = 'Video';
      f.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; fullscreen';
      f.allowFullscreen = true; f.referrerPolicy = 'strict-origin-when-cross-origin';
      f.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;border:0;background:#000;z-index:5;';
      host.appendChild(f);
      addUnmuteOverlay(host, f);
    }
    function stopAuto(host) {
      if (fs()) return;                                  // never unload a fullscreen video
      if (isInline(host)) { var inf = host.querySelector('iframe[data-lg-auto]'); if (inf) ytPost(inf, 'pauseVideo'); return; }
      var f = host.querySelector('iframe[data-lg-auto]');
      if (f && f.parentNode) f.parentNode.removeChild(f);
      var ov = host.querySelector('.lg-unmute'); if (ov) ov.parentNode.removeChild(ov);
    }
    function allHosts() {
      var a = [].slice.call(document.querySelectorAll('.fc-cover--video[data-yt-play]'))
        // No scroll-autoplay for video CPT cards (Ian 2026-06-17) — they stay
        // click-to-play. Topic/user videos keep the Instagram-style autoplay.
        .filter(function (h) {
          var c = h.closest('.feed-card');
          return !(c && c.classList.contains('feed-card--content'));
        });
      // inline videos: only the YouTube ones (have an /embed/ iframe)
      [].forEach.call(document.querySelectorAll('.bb-embed--video'), function (h) {
        var ifr = h.querySelector('iframe'); if (ifr && /youtube\.com\/embed\//.test(ifr.src || '')) a.push(h);
      });
      return a;
    }
    function reconcile() {
      if (fs()) return;                                  // leave playback alone while fullscreen
      var hosts = allHosts();
      var best = null, bestR = 0;
      hosts.forEach(function (h) { var r = rmap.get(h) || 0; if (r > bestR) { bestR = r; best = h; } });
      hosts.forEach(function (h) { if (h === best && bestR >= 0.6) ensurePlaying(h); else stopAuto(h); });
    }
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) { rmap.set(en.target, en.isIntersecting ? en.intersectionRatio : 0); });
      reconcile();
    }, { threshold: [0, 0.3, 0.6, 0.9] });
    function observeAll() {
      allHosts().forEach(function (h) {
        if (!h.getAttribute('data-lg-vauto')) { h.setAttribute('data-lg-vauto', '1'); io.observe(h); }
      });
    }
    observeAll();
    var root = document.getElementById('hub-feed-results') || document.querySelector('.feed') || document.body;
    if ('MutationObserver' in window) new MutationObserver(observeAll).observe(root, { childList: true, subtree: true });
  }

  // ── Desktop hover-to-play (Buck 2026-06-09): on desktop, hovering the mouse over a
  // feed video starts it playing MUTED (YouTube/Netflix-style preview); CLICKING it
  // unmutes. Moving the mouse away stops a still-muted preview so the thumbnail
  // returns; once you've clicked to unmute it keeps playing. Mobile is untouched (it
  // has its own scroll-into-view autoplay). Reuses the same muted-iframe swap + the
  // click-to-unmute overlay as the mobile path; the auto-stop observer already unloads
  // any .fc-video that scrolls out of view. Desktop only. ──────────────────────────
  function wireDesktopVideoHover() {
    if (window.matchMedia('(max-width:640px)').matches) return;     // desktop only
    if (document.body.getAttribute('data-lg-vidhover')) return;
    document.body.setAttribute('data-lg-vidhover', '1');

    function playMuted(host) {
      if (host.querySelector('iframe')) return;                     // already playing (hover or clicked)
      var id = host.getAttribute('data-yt-play'); if (!id) return;
      if (getComputedStyle(host).position === 'static') host.style.position = 'relative';
      var f = document.createElement('iframe');
      f.className = 'fc-video'; f.setAttribute('data-lg-hover', '1');
      f.src = 'https://www.youtube.com/embed/' + encodeURIComponent(id) +
        '?autoplay=1&mute=1&playsinline=1&rel=0&modestbranding=1&enablejsapi=1';
      f.title = 'Video';
      f.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; fullscreen';
      f.allowFullscreen = true; f.referrerPolicy = 'strict-origin-when-cross-origin';
      f.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;border:0;background:#000;z-index:5;';
      host.appendChild(f);
      addUnmuteOverlay(host, f);                                    // first click unmutes + removes overlay
      var lbl = host.querySelector('.lg-unmute__b');                // desktop wording
      if (lbl && lbl.lastChild && lbl.lastChild.nodeType === 3) lbl.lastChild.textContent = 'Click to unmute';
    }
    function stopHover(host) {
      // never tear a player down because fullscreen promotion re-computed the
      // hover chain (top-layer elements drop ancestor :hover -> mouseleave)
      if (document.fullscreenElement || document.webkitFullscreenElement) return;
      var f = host.querySelector('iframe.fc-video[data-lg-hover]');
      if (!f || f.getAttribute('data-lg-unmuted')) return;          // engaged (clicked) videos keep playing
      if (f.parentNode) f.parentNode.removeChild(f);
      var ov = host.querySelector('.lg-unmute'); if (ov && ov.parentNode) ov.parentNode.removeChild(ov);
    }
    function wireHost(host) {
      if (host.getAttribute('data-lg-hoverwired')) return;
      host.setAttribute('data-lg-hoverwired', '1');
      host.addEventListener('mouseenter', function () {
        // Hover takes over playback: if this video already has an iframe (e.g. it was
        // PAUSED when another video stole play), resume it; otherwise start the muted
        // preview. Either way, pause every OTHER video so only this one plays (Buck
        // 2026-06-09: "hover a new video → new plays, the old pauses").
        var f = host.querySelector('iframe.fc-video');
        if (f) { ytPost(f, 'playVideo'); stopOtherVideos(f); }
        else playMuted(host);                                   // new preview → its observer pauses the others
      });
      host.addEventListener('mouseleave', function () { stopHover(host); });
    }
    // (v170 body-embed hover-autoplay REMOVED 2026-06-11 — coord/Ian: intrusive
    //  + collided with the desktop modal's embeds. Covers-only, as in v114.)
    function wireAll() {
      [].forEach.call(document.querySelectorAll('.fc-cover--video[data-yt-play]'), wireHost);
    }
    wireAll();
    var root = document.getElementById('hub-feed-results') || document.querySelector('.feed') || document.body;
    if ('MutationObserver' in window) new MutationObserver(wireAll).observe(root, { childList: true, subtree: true });
  }

  // ── Only ONE video plays at a time (Buck 2026-06-09, desktop + mobile). However a
  // video starts — hover-preview, click-to-play, scroll autoplay — the moment a new
  // YouTube iframe appears we stop every OTHER one. Buck 2026-06-09 refinement: the
  // playing video should PAUSE (stay as a paused frame you can resume by hovering it
  // again), not get yanked back to its thumbnail. So any iframe WE control (carries
  // enablejsapi=1) is paused via the JS API; only a native no-API iframe is removed.
  // A MutationObserver catches all play paths uniformly. Gated by the "Videos"
  // setting (LGSettings 'playone', default ON) so it's toggleable in the cog. ──────
  function singleVideoEnabled() {
    try {
      var v = (window.LGSettings && window.LGSettings.getPlayone) ? window.LGSettings.getPlayone()
            : (window.LGSettings && window.LGSettings.get ? (window.LGSettings.get('playone') || 'on') : 'on');
      return v !== 'off';
    } catch (e) { return true; }
  }
  function isYtFrame(f) { return !!(f && f.tagName === 'IFRAME' && /youtube(?:-nocookie)?\.com\/embed\//.test(f.src || '')); }
  function stopOtherVideos(except) {
    if (!singleVideoEnabled()) return;
    [].forEach.call(document.querySelectorAll('iframe'), function (f) {
      if (f === except || !isYtFrame(f)) return;
      if (/[?&]enablejsapi=1/.test(f.src || '')) {            // ours → PAUSE (stays as a paused frame)
        ytPost(f, 'pauseVideo');
      } else {                                                // native, no JS API → remove (thumb returns)
        var cover = f.closest && f.closest('.fc-cover--video');
        if (cover) {
          if (f.parentNode) f.parentNode.removeChild(f);
          var ov = cover.querySelector('.lg-unmute'); if (ov && ov.parentNode) ov.parentNode.removeChild(ov);
        }
      }
    });
  }
  function enforceSingleVideo() {
    if (document.body.getAttribute('data-lg-1vid')) return;
    document.body.setAttribute('data-lg-1vid', '1');
    if (!('MutationObserver' in window)) return;
    var root = document.getElementById('hub-feed-results') || document.querySelector('.feed') || document.body;
    new MutationObserver(function (muts) {
      muts.forEach(function (m) {
        [].forEach.call(m.addedNodes || [], function (n) {
          if (n.nodeType !== 1) return;
          var f = (n.matches && n.matches('iframe')) ? n : (n.querySelector ? n.querySelector('iframe') : null);
          if (isYtFrame(f)) stopOtherVideos(f);              // a new video started → stop the rest
        });
      });
    }).observe(root, { childList: true, subtree: true });
  }

  // ── Expand a thread = load ALL replies in one tap (Buck 2026-06-08) ──────────
  // bb-mirror loads replies in batches of 5 ("Load N more replies" / .replies-loadmore),
  // so a 35-reply thread needed 6 taps — felt like load-more "wasn't doing it". When the
  // user expands a thread (or taps load-more), auto-click the batch loader until every
  // reply is in. Capped so a giant thread can't runaway-fetch.
  // While auto-loading, hide the per-batch churn (stubs popping in 5s + the "Load N more"/
  // "Loading…" button flicker = the "funky" Buck saw) behind ONE steady "Loading replies…"
  // state, then reveal them all at once.
  function ensureReplyLoadCss() {
    if (document.getElementById('lg-rload-css')) return;
    var s = document.createElement('style'); s.id = 'lg-rload-css';
    s.textContent =
      // While auto-walking the batches, KEEP the already-loaded replies visible so
      // the thread fills in progressively (it must never look "stuck on one reply"
      // — Buck 2026-06-08). Hide only the per-batch loadmore button so its
      // "Load N more / Loading…" text can't flicker, and show one calm
      // "Loading replies…" line at the bottom until every batch is in.
      '.feed-card.lg-rload .replies-loadmore{visibility:hidden!important;position:absolute!important;height:0!important;margin:0!important;padding:0!important;overflow:hidden!important}' +
      '.feed-card.lg-rload .feed-card__replies-full{position:relative;padding-bottom:30px}' +
      '.feed-card.lg-rload .feed-card__replies-full::after{content:"Loading replies\\2026";position:absolute;left:0;right:0;bottom:6px;text-align:center;color:var(--lg-mute,#6b6f6b);font:600 13px/1 var(--lg-font-sans,system-ui,sans-serif)}';
    (document.head || document.documentElement).appendChild(s);
  }
  function autoLoadAllReplies(card) {
    if (!card || card.__lgLoadingAll) return;
    card.__lgLoadingAll = true;
    ensureReplyLoadCss();
    if (card.querySelector('.feed-card__replies-full')) card.classList.add('lg-rload'); // mask churn
    var tries = 0, waited = 0, seen = false, finished = false;
    function finish() {
      if (finished) return; finished = true; clearTimeout(hardStop);
      // Reveal every loaded reply (the teaser had hidden all but one), restore
      // their photos + reaction rows, then drop the "Loading replies…" mask.
      var full = card.querySelector('.feed-card__replies-full');
      if (full) {
        var all = full.querySelectorAll('.reply-stub');
        for (var z = 0; z < all.length; z++) { all[z].classList.remove('lg-rhide'); all[z].classList.add('lg-rshow'); }
        revealReplyImages(full);
        enhanceReplyReactions(full);
      }
      card.classList.remove('lg-rload'); card.__lgLoadingAll = false;
    }
    var hardStop = setTimeout(finish, 9000);     // never leave the "Loading replies…" mask stuck
    (function step() {
      if (finished) return;
      if (tries++ > 80) { finish(); return; }
      var btn = card.querySelector('.replies-loadmore');
      var present = btn && btn.offsetParent !== null;
      if (present) {
        seen = true; waited = 0;
        if (/loading/i.test(btn.textContent || '')) { setTimeout(step, 280); return; }  // mid-fetch
        btn.click(); setTimeout(step, 380); return;
      }
      // load-more not present
      if (seen) { finish(); return; }            // it was there, now gone → all batches in → reveal
      if (++waited > 8) { finish(); return; }     // never appeared (≤5 replies, no load-more) → done
      setTimeout(step, 350);
    })();
  }
  // ── Replies popup (Buck 2026-06-08): "View N replies" / load-more opens a
  // bottom sheet (like events/loothprints) with ALL replies, instead of expanding
  // inline. We drive the canonical loader to fill .feed-card__replies-full, then
  // RELOCATE that element into the sheet (keeps reactions/reply-boxes wired); on
  // close we move it back and collapse the card. ──────────────────────────────
  var lrsScroll = '';
  function ensureRepStyles() {
    if (document.getElementById('lg-rep-css')) return;
    var s = document.createElement('style'); s.id = 'lg-rep-css';
    s.textContent = [
      '#looth-rep-sheet{position:fixed;inset:0;z-index:2147483520;display:none}',
      '#looth-rep-sheet.is-open{display:block}',
      '#looth-rep-sheet .lrs-back{position:absolute;inset:0;background:rgba(26,29,26,.55)}',
      '#looth-rep-sheet .lrs-card{position:absolute;left:0;right:0;bottom:0;top:max(6vh,env(safe-area-inset-top,0px));display:flex;flex-direction:column;' +
        'background:var(--lg-cream,#fbfbf8);border-radius:18px 18px 0 0;box-shadow:0 -8px 30px rgba(26,29,26,.32);animation:looth-pwa-up .26s ease;will-change:transform}',
      // Design-system grab handle (same as the content sheet) — drag down to dismiss.
      '#looth-rep-sheet .lrs-grab{flex:0 0 auto;height:20px;display:flex;align-items:center;justify-content:center;touch-action:none;cursor:grab}',
      '#looth-rep-sheet .lrs-grab::before{content:"";width:40px;height:5px;border-radius:3px;background:var(--lg-line,#d8d2c4)}',
      '#looth-rep-sheet .lrs-hd{flex:0 0 auto;display:flex;align-items:center;gap:10px;padding:2px 14px 11px;border-bottom:1px solid var(--lg-line,#e3ddd0);touch-action:none}',
      '#looth-rep-sheet .lrs-t{flex:1 1 auto;min-width:0;font:700 16px/1.25 var(--lg-font-serif,Georgia,serif);color:var(--lg-charcoal,#1a1d1a);' +
        'white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
      '#looth-rep-sheet .lrs-x{flex:0 0 auto;width:32px;height:32px;border:0;border-radius:50%;background:var(--lg-sage-tint,#eef2e3);' +
        'color:var(--lg-sage-d,#6b7c52);font-size:20px;line-height:1;cursor:pointer}',
      '#looth-rep-sheet .lrs-body{flex:1 1 auto;overflow-y:auto;-webkit-overflow-scrolling:touch;padding:10px 14px 24px}',
      '#looth-rep-sheet .lrs-note{padding:20px 6px;color:var(--lg-mute,#6b6f6b);font:14px/1.5 var(--lg-font-sans,system-ui)}',
      // ── OP at the top of the sheet (Buck 2026-06-10: clone the desktop discussion
      // modal, "similar but better" — the modal shows the POST + thread, like desktop
      // §4e, with the FB comments + composer this sheet already had). ──
      '#looth-rep-sheet .lrs-op{padding:2px 0 12px;border-bottom:1px solid var(--lg-line,#e3ddd0);margin:0 0 14px}',
      '#looth-rep-sheet .lrs-op[hidden]{display:none}',
      '#looth-rep-sheet .lrs-op__meta{display:flex;align-items:center;gap:10px;margin-bottom:9px}',
      '#looth-rep-sheet .lrs-op__meta .fc-avatar img,#looth-rep-sheet .lrs-op__meta .avatar-init{width:38px;height:38px;border-radius:50%;object-fit:cover;font-size:15px}',
      '#looth-rep-sheet .lrs-op__id{display:flex;flex-direction:column;gap:2px;min-width:0}',
      '#looth-rep-sheet .lrs-op__id .fc-author,#looth-rep-sheet .lrs-op__id .fc-author__name{font-weight:700;font-family:var(--lg-font-serif,Georgia,serif);color:var(--lg-charcoal,#1a1d1a);text-decoration:none}',
      '#looth-rep-sheet .lrs-op__id .fc-time{font-size:12.5px;color:var(--lg-mute,#6b6f6b)}',
      '#looth-rep-sheet .lrs-op__body{font-size:15.5px;line-height:1.6;color:var(--lg-ink,#1a1d1a);overflow-wrap:break-word}',
      '#looth-rep-sheet .lrs-op__body p{margin:0 0 8px}',
      '#looth-rep-sheet .lrs-op__body img{max-width:100%;height:auto;border-radius:12px}',
      '#looth-rep-sheet .lrs-op__body a{color:var(--lg-sage-d,#6b7c52)}',
      '#looth-rep-sheet .lrs-op__acts{display:flex;align-items:center;gap:10px;margin-top:8px;padding:8px 0 2px;position:relative;border-top:1px solid var(--lg-line,#e6e8e2)}',
      '#looth-rep-sheet .lrs-op__del{margin-left:auto;display:inline-flex;align-items:center;gap:6px;background:none;border:0;color:var(--lg-mute,#6b6f6b);font:inherit;font-size:13px;font-weight:600;cursor:pointer;padding:6px 9px;border-radius:8px}',
      // [hidden] must beat the display:inline-flex above — author CSS overrides the UA
      // [hidden] rule, so without this the delete button shows to NON-authors (JS leaves
      // hidden=true for them; only the click handler is gated). Author/mod-only render.
      '#looth-rep-sheet .lrs-op__del[hidden]{display:none}',
      '#looth-rep-sheet .lrs-op__del:hover{background:rgba(193,51,51,.1);color:#c33}',
      'html[data-lguser-theme="dark"] #looth-rep-sheet .lrs-op__acts{border-top-color:#2c312d}',
      'html[data-lguser-theme="dark"] #looth-rep-sheet .lrs-op__del{color:#80867d}',
      // dark pass for the new pieces (the shell/bubbles/actions are already covered
      // by app-settings' dark style + the rules below)
      'html[data-lguser-theme="dark"] #looth-rep-sheet .lrs-grab::before{background:#3a403a}',
      'html[data-lguser-theme="dark"] #looth-rep-sheet .lrs-hd{border-bottom-color:#2c312d}',
      'html[data-lguser-theme="dark"] #looth-rep-sheet .lrs-x{background:#262b30;color:#9cb37d}',
      'html[data-lguser-theme="dark"] #looth-rep-sheet .lrs-op{border-bottom-color:#2c312d}',
      'html[data-lguser-theme="dark"] #looth-rep-sheet .lrs-op__id .fc-author,html[data-lguser-theme="dark"] #looth-rep-sheet .lrs-op__id .fc-author__name{color:#f2f4ee}',
      'html[data-lguser-theme="dark"] #looth-rep-sheet .lrs-op__id .fc-time{color:#80867d}',
      'html[data-lguser-theme="dark"] #looth-rep-sheet .lrs-op__body{color:#e5e7e1}',
      'html[data-lguser-theme="dark"] #looth-rep-sheet .lrs-op__body a{color:#9cb37d}',
      'html[data-lguser-theme="dark"] #looth-rep-sheet .lrs-note{color:#9aa097}',
      '#looth-rep-sheet .feed-card__replies-full{display:block!important}',
      '#looth-rep-sheet .feed-card__replies-full *{visibility:visible!important}',
      // ── Facebook-style comments in the sheet (the .lg-fb-* styling is .feed-page-
      // scoped in forums.css so it never reaches the sheet — mirror it here, token-
      // based so it adapts to dark, + FB "Like · Reply · time" action row). Buck 2026-06-08.
      '#looth-rep-sheet .reply-stub{display:flex!important;gap:8px;align-items:flex-start;background:none!important;border:0!important;padding:0!important;margin:0 0 16px!important}',
      '#looth-rep-sheet .reply-stub .avatar-init,#looth-rep-sheet .reply-stub .avatar-init--img{width:32px;height:32px;border-radius:50%;flex:0 0 auto;font-size:13px;overflow:hidden}',
      '#looth-rep-sheet .reply-stub .avatar-init img{width:100%;height:100%;object-fit:cover;display:block}',
      // Nested sub-replies (Buck 2026-06-12): the thread fragment orders children
      // directly under their parent and tags them reply-stub--child — indent them
      // FB-style with a smaller avatar so they read as a stacked sub-thread.
      // (Must follow the flattener rule above: same specificity, later wins.)
      '#looth-rep-sheet .reply-stub.reply-stub--child{margin:0 0 14px 38px!important}',
      '#looth-rep-sheet .reply-stub--child .avatar-init,#looth-rep-sheet .reply-stub--child .avatar-init--img{width:26px;height:26px;font-size:11px}',
      '#looth-rep-sheet .reply-stub__reply-to{color:var(--lg-sage-d,#6b7c52);font-weight:600;font-size:12.5px}',
      'html[data-lguser-theme="dark"] #looth-rep-sheet .reply-stub__reply-to{color:#9cb37d}',
      '#looth-rep-sheet .lg-fb-col{display:flex;flex-direction:column;min-width:0;flex:1 1 auto;align-items:flex-start}',
      '#looth-rep-sheet .lg-fb-bubble{background:var(--lguser-bubble,#eceff3);border-radius:16px;padding:8px 13px;max-width:100%}',
      '#looth-rep-sheet .lg-fb-name{display:block;font-weight:700;font-size:13.5px;color:var(--lg-charcoal,#1a1d1a);text-decoration:none;margin:0 0 1px}',
      '#looth-rep-sheet .lg-fb-bubble .reply-stub__body,#looth-rep-sheet .lg-fb-bubble .reply-stub__excerpt{margin:0;font-size:14px;line-height:1.45;color:var(--lg-ink,#1a1d1a);display:block;-webkit-line-clamp:unset;overflow:visible;max-height:none}',
      '#looth-rep-sheet .lg-fb-bubble .reply-stub__img{margin-top:6px;border-radius:12px;max-width:100%}',
      '#looth-rep-sheet .lg-fb-actions{display:flex;align-items:center;gap:0;padding:5px 12px 0;font:600 12.5px/1 var(--lg-font-sans,system-ui,sans-serif);color:var(--lg-mute,#65676b)}',
      '#looth-rep-sheet .lg-fb-act{cursor:pointer;font-weight:700;color:var(--lg-mute,#65676b)}',
      '#looth-rep-sheet .lg-fb-reply::before,#looth-rep-sheet .lg-fb-time::before{content:"·";margin:0 6px;color:#8a8d91;font-weight:400}',
      '#looth-rep-sheet .lg-fb-time{font-weight:400;color:#8a8d91}',
      '#looth-rep-sheet .lg-fb-like.is-on{color:#1877f2}',
      '#looth-rep-sheet .lg-fb-replybox{display:flex;gap:8px;align-items:flex-start;margin:8px 0 2px;width:100%}',
      '#looth-rep-sheet .lg-fb-replywrap{display:flex;align-items:flex-end;gap:6px;flex:1 1 auto;background:var(--lguser-bubble,#eceff3);border-radius:18px;padding:4px 6px 4px 12px}',
      '#looth-rep-sheet .lg-fb-replyinput{flex:1 1 auto;border:0;background:none;resize:none;outline:none;font:14px/1.4 var(--lg-font-sans,system-ui,sans-serif);color:var(--lg-ink,#1a1d1a);max-height:120px;padding:4px 0}',
      '#looth-rep-sheet .lg-fb-send{border:0;background:none;cursor:pointer;color:var(--lg-sage-d,#52613d);font:700 13px/1 var(--lg-font-sans,system-ui,sans-serif);padding:6px 8px}',
      // also apply the FB "·"-separated action format to the INLINE feed comments
      '.feed-page .lg-fb-actions{gap:0!important}',
      '.feed-page .lg-fb-act{font-weight:700!important}',
      '.feed-page .lg-fb-reply::before,.feed-page .lg-fb-time::before{content:"·";margin:0 6px;color:#8a8d91;font-weight:400}',
      '.feed-page .lg-fb-like.is-on{color:#1877f2!important}',
      // ── Facebook-style top-level composer pinned to the bottom of the sheet ──
      '#looth-rep-sheet .lrs-comp{flex:0 0 auto;display:flex;flex-wrap:wrap;align-items:flex-end;gap:9px;padding:9px 12px calc(9px + env(safe-area-inset-bottom,0px));border-top:1px solid var(--lg-line,#e3ddd0);background:var(--lg-cream,#fbfbf8);will-change:transform;transition:transform .18s ease;position:relative;z-index:3}',
      '#looth-rep-sheet .lrs-comp__av{width:32px;height:32px;border-radius:50%;overflow:hidden;flex:0 0 auto;background:var(--lg-sage-tint,#eef2e3)}',
      '#looth-rep-sheet .lrs-comp__av img{width:100%;height:100%;object-fit:cover;display:block}',
      // The pinned bar is a TRIGGER for the composer sheet now (Buck 2026-06-10:
      // "fix the look") — a clean FB-style pill: avatar + muted "Write a comment…",
      // no live input chrome, no Post/photo buttons (those live in the sheet).
      '#looth-rep-sheet .lrs-comp__wrap{display:flex;align-items:center;gap:4px;flex:1 1 auto;min-width:0;background:var(--lguser-bubble,#eceff3);border:0;border-radius:999px;padding:10px 16px;cursor:pointer}',
      '#looth-rep-sheet .lrs-comp__input{flex:1 1 auto;min-width:0;border:0;background:none;outline:none;resize:none;font:15px/1.3 var(--lg-font-sans,system-ui,sans-serif);color:var(--lg-mute,#65676b);height:20px;max-height:20px;overflow:hidden;padding:0;pointer-events:none;cursor:pointer}',
      '#looth-rep-sheet .lrs-comp__input::placeholder{color:var(--lg-mute,#65676b)}',
      '#looth-rep-sheet .lrs-comp .lrs-comp__photo,#looth-rep-sheet .lrs-comp .lrs-comp__send,' +
        '#looth-rep-sheet .lrs-comp .lrs-comp__previews,#looth-rep-sheet .lrs-comp .lrs-comp__status{display:none!important}',
      'html[data-lguser-theme="dark"] #looth-rep-sheet .lrs-comp__wrap,html[data-lguser-dark="1"] #looth-rep-sheet .lrs-comp__wrap{background:#262b30!important}',
      'html[data-lguser-theme="dark"] #looth-rep-sheet .lrs-comp__input,html[data-lguser-dark="1"] #looth-rep-sheet .lrs-comp__input{color:#9aa097!important}',
      'html[data-lguser-theme="dark"] #looth-rep-sheet .lrs-comp__input::placeholder,html[data-lguser-dark="1"] #looth-rep-sheet .lrs-comp__input::placeholder{color:#9aa097!important}',
      '#looth-rep-sheet .lrs-comp__send{flex:0 0 auto;border:0;background:none;cursor:pointer;color:var(--lg-sage-d,#52613d);font:700 14px/1 var(--lg-font-sans,system-ui,sans-serif);padding:7px 9px}',
      '#looth-rep-sheet .lrs-comp__photo{flex:0 0 auto;border:0;background:none;cursor:pointer;color:var(--lg-sage-d,#52613d);padding:5px 4px;line-height:0}',
      '#looth-rep-sheet .lrs-comp__photo svg{width:21px;height:21px}',
      '#looth-rep-sheet .lrs-comp__previews{flex-basis:100%;display:flex;gap:8px;flex-wrap:wrap;order:5}',
      '#looth-rep-sheet .lrs-comp__previews:empty{display:none}',
      '#looth-rep-sheet .lrs-comp__pv{position:relative;display:inline-block;margin-top:8px}',
      '#looth-rep-sheet .lrs-comp__pv img{width:56px;height:56px;object-fit:cover;border-radius:10px;display:block;border:1px solid var(--lg-line,#e3ddd0)}',
      '#looth-rep-sheet .lrs-comp__pv-x{position:absolute;top:-7px;right:-7px;width:20px;height:20px;border:0;border-radius:50%;background:rgba(26,29,26,.75);color:#fff;font:700 13px/20px sans-serif;cursor:pointer;padding:0}',
      '#looth-rep-sheet .lrs-comp__send:disabled{color:#b0b3b8;cursor:default}',
      '#looth-rep-sheet .lrs-comp__status{flex-basis:100%;font:12px/1.3 var(--lg-font-sans,system-ui,sans-serif);color:#8a8d91;padding:2px 0 0 41px}',
      'html[data-lguser-theme="dark"] #looth-rep-sheet .lrs-comp{background:#1b1e21;border-color:#2c312d}'
    ].join('\n');
    (document.head || document.documentElement).appendChild(s);
  }
  // Canonical reply post: auth.php → nonce, then POST REPLY_BASE/reply {topic_id,content}.
  var LRS_REPLY_BASE = ((document.getElementById('frm-form') || { dataset: {} }).dataset.restBase) || '/wp-json/buddyboss/v1';
  var lrsMediaIds = [];   // pending bbp_media upload_ids for the quick-comment composer
  var lrsAuth = null;
  function lrsGetAuth(cb) {
    if (lrsAuth) { cb(lrsAuth); return; }
    fetch('/bb-mirror-api/v0/auth.php', { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) { lrsAuth = d || { authenticated: false }; cb(lrsAuth); })
      .catch(function () { cb({ authenticated: false }); });
  }
  function lrsEsc(s) { return String(s).replace(/[&<>]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]; }); }
  function lrsViewerAvatar() {
    var sels = ['.lg-chrome__aside img[src*="/avatars/"]', '.lg-chrome__aside img[src*="/profile-media/"]', '#site-header img[src*="/avatars/"]', 'header img[src*="/avatars/"]'];
    for (var i = 0; i < sels.length; i++) { var el = document.querySelector(sels[i]); if (el && el.src && !/logo/i.test(el.src)) return el.src; }
    return null;
  }
  var lrsHist = false;
  function lrsClose(fromPop) {
    var sh = document.getElementById('looth-rep-sheet'); if (!sh) return;
    sh.classList.remove('is-open');
    document.body.style.overflow = lrsScroll || '';
    var comp = sh.querySelector('.lrs-comp'); if (comp) comp.style.transform = '';   // reset keyboard lift
    var t = sh.querySelector('#lrs-thread'); if (t) t.innerHTML = '';
    var op = sh.querySelector('#lrs-op'); if (op) { op.innerHTML = ''; op.hidden = true; }
    // Phone back-gesture support (same pattern as the content sheet's lgCsHist).
    if (lrsHist && !fromPop) { lrsHist = false; try { history.back(); } catch (e) {} }
    else { lrsHist = false; }
  }
  window.addEventListener('popstate', function () {
    // The image lightbox stacks ABOVE the sheets and pushes its OWN history
    // entry — while it's up (back gesture) or just closed (tap-close pops its
    // entry via history.back), this pop belongs to IT: don't also close a sheet.
    var lb0 = document.getElementById('lg-lb');
    if (lb0 && lb0.classList.contains('is-on')) return;
    if (window.__lgLbPop && Date.now() - window.__lgLbPop < 600) return;
    // Composer sheet stacks on top of the modal — phone back closes IT first and
    // re-pushes the modal's history entry so a second back closes the modal.
    var cs = document.getElementById('looth-comp-sheet');
    if (cs && cs.classList.contains('is-open')) {
      closeComposerSheet();
      var sh0 = document.getElementById('looth-rep-sheet');
      if (sh0 && sh0.classList.contains('is-open') && lrsHist) { try { history.pushState({ lgRs: 1 }, ''); } catch (e) {} }
      return;
    }
    var sh = document.getElementById('looth-rep-sheet');
    if (sh && sh.classList.contains('is-open')) lrsClose(true);
  });
  function lrsEnhance(full) { try { revealReplyImages(full); enhanceReplyReactions(full); } catch (e) {} }
  // The sheet shows the WHOLE drained thread — that rendered count is the truth
  // the user can see. Push it back onto the card's reply-count displays (same
  // trick as the desktop dmodal's reconcileCount; Ian 2026-06-11 "numbers must
  // jive"). Covers stale mirrored reply_count AND replies posted from the sheet.
  function lrsReconcileCount(full, tid) {
    if (!tid) return;
    var n = full.querySelectorAll('.reply-stub').length;
    if (!n) return;
    var ctl = document.querySelector('.fc-facepile[data-topic-id="' + tid + '"]') ||
              document.querySelector('.feed-card__expand[data-topic-id="' + tid + '"]');
    var card = ctl && ctl.closest('.feed-card');
    if (!card) return;
    var word = n === 1 ? 'reply' : 'replies';
    var fc = card.querySelector('.fc-facepile__count');
    if (fc) fc.textContent = n + ' ' + word;
    var exp = card.querySelector('.feed-card__expand');
    if (exp) exp.innerHTML = 'View ' + n + ' ' + word + ' ▼';
  }
  // Walk the canonical .replies-loadmore (forums.js's delegated handler fetches the
  // next page + inserts it) until every reply is in the sheet.
  function lrsLoadAll(full, tid) {
    lrsEnhance(full);
    var tries = 0;
    (function step() {
      if (++tries > 160) { lrsEnhance(full); lrsReconcileCount(full, tid); return; }
      var btn = full.querySelector('.replies-loadmore');
      if (!btn) { lrsEnhance(full); lrsReconcileCount(full, tid); return; }   // all batches in
      // forums.js sets disabled + "Loading…" synchronously on click and removes the
      // button when the page lands — so poll fast and only click an idle button.
      if (btn.disabled || /loading/i.test(btn.textContent || '')) { setTimeout(step, 80); return; }
      btn.click();                                            // append next page
      lrsEnhance(full);
      setTimeout(step, 90);
    })();
  }
  // Fetch the thread into the sheet body (reused on open AND after posting a reply).
  // Scroll the sheet body so the thread starts at the top (Buck 2026-06-11: "if i
  // click replys ... can it be scrolled to where the replys start"). Only inside a
  // short window after a replies-intent open, and never after the user scrolled.
  function lrsScrollToReplies() {
    var sh = document.getElementById('looth-rep-sheet');
    if (!sh || !sh.__lgToReplies || Date.now() - sh.__lgToReplies > 5000) return;
    var body = sh.querySelector('#lrs-body'), th = sh.querySelector('#lrs-thread');
    if (!body || !th) return;
    body.scrollTop += th.getBoundingClientRect().top - body.getBoundingClientRect().top - 6;
  }
  function lrsLoadThread(tid, sort) {
    var sh = document.getElementById('looth-rep-sheet'); if (!sh) return;
    // Replies land in #lrs-thread so reloading after a post keeps the OP above intact.
    var body = sh.querySelector('#lrs-thread') || sh.querySelector('#lrs-body'); if (!body) return;
    body.innerHTML = '<div class="lrs-note">Loading replies…</div>';
    var base = (window.LG_FORUM_BASE || '/forum').toString().replace(/\/+$/, '');
    // Optional sort (newest|oldest) — the ?replies fragment re-emits its own sort bar
    // with the active state, so passing &sort keeps the toggle correct after reload.
    var url = base + '/?replies=' + encodeURIComponent(tid) + (sort ? '&sort=' + encodeURIComponent(sort) : '');
    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.text() : Promise.reject(); })
      .then(function (html) {
        body.innerHTML = '<div class="feed-card__replies-full lg-rshow"></div>';
        var full = body.querySelector('.feed-card__replies-full');
        full.innerHTML = html;
        if (!full.querySelector('.reply-stub')) { body.innerHTML = '<div class="lrs-note">No replies yet. Be the first to reply.</div>'; if (sh) sh.__lgToReplies = 0; return; }
        lrsLoadAll(full, tid);
        lrsScrollToReplies();
      })
      .catch(function () { body.innerHTML = '<div class="lrs-note">Couldn’t load replies right now.</div>'; });
  }
  // Reply-sort toggle (Newest/Oldest) INSIDE the mobile discussion sheet must stay in
  // the NEW system. The canonical forums.js handler (bubble phase) swaps in the RAW
  // ?replies fragment — un-enhanced (no FB-style recompose / revealed photos /
  // reactions) so it reads as the "legacy" thread (Ian 2026-06-25). Intercept in the
  // CAPTURE phase (runs before that bubble handler) and re-run lrsLoadThread with the
  // chosen sort, so replies re-render FB-style in place. Sheet-scoped: the inline feed
  // + desktop modal keep the canonical handler.
  document.addEventListener('click', function (e) {
    if (!e.target.closest) return;
    var b = e.target.closest('.replies-sort__btn');
    if (!b) return;
    var sheet = e.target.closest('#looth-rep-sheet');
    if (!sheet) return;                                       // not the mobile sheet → canonical handler
    e.preventDefault(); e.stopPropagation();                  // beat forums.js's bubble handler either way
    if (b.classList.contains('is-active')) return;            // already this sort → no-op
    var tid = parseInt(sheet.getAttribute('data-tid'), 10) || 0;
    if (!tid) return;
    lrsLoadThread(tid, b.getAttribute('data-sort') || '');
  }, true);
  // Lift the reply composer above the on-screen keyboard (visualViewport delta).
  function lrsAdjustKb() {
    var sh = document.getElementById('looth-rep-sheet');
    var comp = sh && sh.querySelector('.lrs-comp');
    if (!comp) return;
    if (!sh.classList.contains('is-open') || !window.visualViewport) { comp.style.transform = ''; return; }
    var vv = window.visualViewport;
    var kb = Math.max(0, Math.round(window.innerHeight - vv.height - vv.offsetTop));
    comp.style.transform = kb > 1 ? ('translateY(-' + kb + 'px)') : '';
  }
  // OP at the top of the sheet (Buck 2026-06-10: clone the desktop discussion modal
  // "similar but better"). Meta is cloned off the card for instant paint; the full
  // resolved body (mentions, links, attachments) comes from the fork's /?body=
  // endpoint — the same data path desktop §4e uses.
  function lrsLoadOp(sh, card, tid) {
    var op = sh.querySelector('#lrs-op');
    if (!op) return;
    op.innerHTML = ''; op.hidden = false;
    var meta = document.createElement('div'); meta.className = 'lrs-op__meta';
    var av = card.querySelector('.fc-avatar, .feed-card__avatar');
    if (av) meta.appendChild(av.cloneNode(true));
    var mw = document.createElement('div'); mw.className = 'lrs-op__id';
    var au = card.querySelector('.fc-author, .feed-card__op-author');
    if (au) {
      au = au.cloneNode(true);
      // drop category/kind chips that ride inside the card's author block — the
      // modal header is about WHO posted, the badge noise stays on the card
      var chips = au.querySelectorAll('.fc-category, .fc-cat, .lg-card-cat, .fc-kind, .fc-kindpill, .fc-badge, .feed-card__cat, .feed-card__kind, .fc-breadcrumb');
      for (var ci = 0; ci < chips.length; ci++) chips[ci].remove();
      mw.appendChild(au);
    }
    var tm = card.querySelector('.fc-time, .feed-card__time');
    if (tm) mw.appendChild(tm.cloneNode(true));
    meta.appendChild(mw);
    op.appendChild(meta);
    var body = document.createElement('div'); body.className = 'lrs-op__body';
    var ex = card.querySelector('.feed-card__full-body, .fc-full-body, .feed-card__op-excerpt, .fc-excerpt');
    body.innerHTML = ex ? ex.innerHTML : '';
    op.appendChild(body);
    // React to the OP itself (dmodal parity, Ian 2026-06-11): clone the card's
    // TOPIC reaction bar into the sheet. Canonical forums.js delegates .fcr
    // clicks on document (they work on the clone) and doReact re-renders EVERY
    // .fcr with the same data-post-type+item-id — so the card and the sheet
    // can never disagree. Skip reply-level bars when picking the card's.
    var cardBar = card.querySelector('.fc-actions .fcr');
    if (!cardBar) {
      var bars0 = card.querySelectorAll('.fcr');
      for (var bi = 0; bi < bars0.length; bi++) {
        if (!bars0[bi].closest('.reply-stub')) { cardBar = bars0[bi]; break; }
      }
    }
    // OP action bar (Ian 2026-06-15: FB-style — all the OP's actions together in
    // one row at the bottom of the post). Reaction bar (if any) on the left, the
    // delete-post control on the right (author or moderator only).
    var acts = document.createElement('div');
    acts.className = 'lrs-op__acts';
    if (cardBar) {
      var opBar = cardBar.cloneNode(true);
      var opPal = opBar.querySelector('.fcr-palette');
      if (opPal) opPal.hidden = true;
      acts.appendChild(opBar);
    }
    if (tid) {
      // Delete the whole post — shown when the viewer is the author (viewer
      // wp_user_id === the card's data-author-id) OR a moderator (can_edit_others).
      // Server (api/v0/reply.php DELETE {topic_id}) re-checks the cap regardless.
      var opAuthorId = parseInt(card.getAttribute('data-author-id'), 10) || 0;
      var del = document.createElement('button');
      del.type = 'button'; del.className = 'lrs-op__del'; del.hidden = true;
      del.setAttribute('aria-label', 'Delete this post');
      del.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6M10 11v6M14 11v6"/></svg><span>Delete</span>';
      acts.appendChild(del);
      lrsGetAuth(function (a) {
        if (!a || !a.authenticated) return;
        var mine = opAuthorId && a.wp_user_id && parseInt(a.wp_user_id, 10) === opAuthorId;
        if (!mine && !a.can_edit_others) return;
        del.hidden = false;
        del.addEventListener('click', function (ev) {
          ev.preventDefault(); ev.stopPropagation();
          if (!a.nonce) { alert('Not signed in.'); return; }
          if (!window.confirm('Delete this post? This removes the discussion and its replies and can’t be undone.')) return;
          del.disabled = true;
          fetch('/bb-mirror-api/v0/reply', {
            method: 'DELETE', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': a.nonce },
            body: JSON.stringify({ topic_id: tid })
          })
            .then(function (r) { return r.json().then(function (j) { return { s: r.status, ok: r.ok, j: j }; }, function () { return { s: r.status, ok: r.ok, j: {} }; }); })
            .then(function (res) {
              if (res.s === 401) { del.disabled = false; alert('Please sign in.'); return; }
              if (res.s === 403) { del.disabled = false; alert('You can only delete your own posts.'); return; }
              if (!res.ok) { del.disabled = false; alert('Could not delete: ' + ((res.j && (res.j.message || res.j.error)) || 'failed')); return; }
              lrsClose();
              try { card.remove(); } catch (e) {}
            })
            .catch(function (err) { del.disabled = false; alert('Network error: ' + err.message); });
        });
      });
      // Edit the OP (author/admin) — opens the SAME mobile composer used to edit a
      // reply (openComposerSheet), now with a title field, pre-filled, photos
      // loaded as removable thumbs; Save → owned topic PUT (+ topic-media). Unified
      // "new edit" (Ian 2026-06-25): the OP no longer opens the 3-modal wizard.
      // Gated to author (data-author-id) OR mod.
      var opForumId = parseInt(card.getAttribute('data-forum-id'), 10) || 0;
      var edit = document.createElement('button');
      edit.type = 'button'; edit.className = 'lrs-op__del lrs-op__edit'; edit.hidden = true;
      edit.setAttribute('aria-label', 'Edit this post');
      edit.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg><span>Edit</span>';
      acts.insertBefore(edit, del);
      lrsGetAuth(function (ae) {
        if (!ae || !ae.authenticated) return;
        var mineE = opAuthorId && ae.wp_user_id && parseInt(ae.wp_user_id, 10) === opAuthorId;
        if (!mineE && !ae.can_edit_others) return;
        edit.hidden = false;
        edit.addEventListener('click', function (ev) {
          ev.preventDefault(); ev.stopPropagation();
          var tEl = document.querySelector('#looth-rep-sheet .lrs-t');
          var ttl = ((tEl && tEl.textContent) || '').trim();
          // Plain-text body for the textarea composer (same flatten as reply edit;
          // rich formatting is desktop-only, by design). body.innerHTML = fetched OP.
          var bodyText = (body.innerHTML || '')
            .replace(/<img[^>]*>/gi, '').replace(/<br\s*\/?>/gi, '\n')
            .replace(/<\/p>\s*<p[^>]*>/gi, '\n\n').replace(/<[^>]+>/g, '').trim();
          if (typeof openComposerSheet === 'function') {
            // Leave the replies sheet open BEHIND the composer (parity with reply
            // edit); we reload on save anyway.
            openComposerSheet({ editTopicId: tid, tid: tid, fid: opForumId, title: ttl, bodyText: bodyText, focus: true });
            return;
          }
          // Fallback: the old new-topic wizard.
          if (typeof window.lgNtmEditTopic === 'function') {
            lrsClose();
            window.lgNtmEditTopic(tid, opForumId, ttl, body.innerHTML);
          }
        });
      });
    }
    if (acts.children.length) op.appendChild(acts);
    if (!tid) return;
    var base = (window.LG_FORUM_BASE || '/forum').toString().replace(/\/+$/, '');
    fetch(base + '/?body=' + encodeURIComponent(tid), { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.text() : ''; })
      .then(function (html) {
        if (html && html.trim()) body.innerHTML = html;
        // the full OP grows above the thread → re-anchor a replies-intent open
        // (again shortly after, for images in the OP body landing late)
        lrsScrollToReplies();
        setTimeout(lrsScrollToReplies, 450);
      })
      .catch(function () {});
  }
  function openRepliesSheet(card, opts) {
    if (!card) return;
    ensureRepStyles();
    var exp = card.querySelector('.feed-card__expand');
    // topic id from the expander OR (for 0-reply posts with no expander) the card.
    var tid = (exp && (exp.dataset.topicId || exp.getAttribute('data-topic-id'))) || card.getAttribute('data-topic-id');
    var fid = card.getAttribute('data-forum-id') || '';
    var m = exp && (exp.textContent || '').match(/(\d+)/); var n = m ? m[1] : '';
    var sh = document.getElementById('looth-rep-sheet');
    if (!sh) {
      sh = document.createElement('div'); sh.id = 'looth-rep-sheet';
      sh.innerHTML = '<div class="lrs-back" data-lrs-close></div><div class="lrs-card">' +
        '<div class="lrs-grab" aria-hidden="true"></div>' +
        '<div class="lrs-hd"><span class="lrs-t"></span><button class="lrs-x" type="button" data-lrs-close aria-label="Close">&times;</button></div>' +
        '<div class="lrs-body" id="lrs-body"><div class="lrs-op" id="lrs-op" hidden></div><div id="lrs-thread"></div></div>' +
        '<div class="lrs-comp"><span class="lrs-comp__av" id="lrs-comp-av"></span>' +
          '<div class="lrs-comp__wrap"><textarea class="lrs-comp__input" id="lrs-comp-input" rows="1" placeholder="Write a comment…"></textarea>' +
          '<button class="lrs-comp__photo" id="lrs-comp-photo" type="button" aria-label="Add photo" title="Add photo">' +
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="8.5" cy="10" r="1.8"/><path d="M4 17l4.5-4.5 3 3L16 11l4 4"/></svg></button>' +
          '<button class="lrs-comp__send" id="lrs-comp-send" type="button" disabled>Post</button></div>' +
          '<input type="file" id="lrs-comp-file" accept="image/*" style="display:none">' +
          '<div class="lrs-comp__previews" id="lrs-comp-previews"></div>' +
          '<span class="lrs-comp__status" id="lrs-comp-status"></span></div>';
      document.body.appendChild(sh);
      sh.addEventListener('click', function (e) { if (e.target.closest('[data-lrs-close]')) lrsClose(); });
      // Drag-to-dismiss (design-system gesture, same as the content sheet): drag the
      // grab/header down anytime, or overscroll-pull from the body when at the top.
      (function () {
        var cardEl = sh.querySelector('.lrs-card');
        function dragTo(dy) { cardEl.style.transition = 'none'; cardEl.style.transform = 'translateY(' + Math.max(0, dy) + 'px)'; }
        function dragReset() { cardEl.style.transition = ''; cardEl.style.transform = ''; }
        function dragEnd(dy) { dragReset(); if (dy > 110) lrsClose(); }
        function attach(el, atTopGuard) {
          if (!el) return;
          var sy = 0, dy = 0, on = false;
          el.addEventListener('touchstart', function (e) {
            if (atTopGuard && !atTopGuard()) { on = false; return; }
            sy = e.touches[0].clientY; dy = 0; on = true;
          }, { passive: true });
          el.addEventListener('touchmove', function (e) {
            if (!on) return;
            dy = e.touches[0].clientY - sy;
            if (dy <= 0) { if (atTopGuard) { on = false; dragReset(); } return; }   // pulled up → let the body scroll
            if (atTopGuard && !atTopGuard()) { on = false; dragReset(); return; }
            dragTo(dy);
            if (e.cancelable) e.preventDefault();
          }, { passive: false });
          el.addEventListener('touchend', function () { if (!on) return; on = false; dragEnd(Math.max(0, dy)); });
        }
        attach(sh.querySelector('.lrs-grab'), null);
        attach(sh.querySelector('.lrs-hd'), null);
        var bodyEl = sh.querySelector('#lrs-body');
        attach(bodyEl, function () { return bodyEl.scrollTop <= 0; });
        // the user taking over scrolling cancels any pending auto-anchor to the thread
        bodyEl.addEventListener('touchstart', function () { sh.__lgToReplies = 0; }, { passive: true });
        bodyEl.addEventListener('wheel', function () { sh.__lgToReplies = 0; }, { passive: true });
      })();
      // composer wiring (once): enable Post on input; auto-grow; submit
      var inp = sh.querySelector('#lrs-comp-input'), send = sh.querySelector('#lrs-comp-send');
      inp.addEventListener('input', function () {
        send.disabled = !inp.value.trim() && !lrsMediaIds.length;
        inp.style.height = 'auto'; inp.style.height = Math.min(inp.scrollHeight, 120) + 'px';
      });
      send.addEventListener('click', function () { lrsSubmit(sh); });
      // Photo attach (Buck 2026-06-10: "when adding a quick comment we also need to
      // be able to add photos"). Same contract as the canonical composers: POST
      // {restBase}/media/upload (FormData 'file', X-WP-Nonce) -> {upload_id,
      // upload_thumb}; the reply POST then carries bbp_media:[ids] and the mirror
      // renders the images natively.
      var photoBtn = sh.querySelector('#lrs-comp-photo'), fileIn = sh.querySelector('#lrs-comp-file');
      photoBtn.addEventListener('click', function () { fileIn.click(); });
      fileIn.addEventListener('change', function () {
        var file = fileIn.files && fileIn.files[0];
        fileIn.value = '';
        if (!file) return;
        var status = sh.querySelector('#lrs-comp-status');
        status.textContent = 'Uploading photo…';
        lrsGetAuth(function (a) {
          if (!a || !a.authenticated) { status.textContent = 'Sign in to add photos.'; return; }
          var fd = new FormData(); fd.append('file', file);
          fetch(LRS_REPLY_BASE + '/media/upload', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'X-WP-Nonce': a.nonce }, body: fd
          })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (res) {
              if (!res.ok || !res.j.upload_id) { status.textContent = 'Photo upload failed: ' + ((res.j && res.j.message) || 'error'); return; }
              lrsMediaIds.push(res.j.upload_id);
              status.textContent = '';
              var pv = sh.querySelector('#lrs-comp-previews');
              var chip = document.createElement('span');
              chip.className = 'lrs-comp__pv';
              chip.setAttribute('data-upload-id', res.j.upload_id);
              chip.innerHTML = '<img src="' + String(res.j.upload_thumb || res.j.upload).replace(/"/g, '&quot;') + '" alt="">' +
                '<button type="button" class="lrs-comp__pv-x" aria-label="Remove photo">&times;</button>';
              chip.querySelector('.lrs-comp__pv-x').addEventListener('click', function () {
                var ix = lrsMediaIds.indexOf(res.j.upload_id);
                if (ix > -1) lrsMediaIds.splice(ix, 1);
                chip.remove();
                send.disabled = !inp.value.trim() && !lrsMediaIds.length;
              });
              pv.appendChild(chip);
              send.disabled = false;
            })
            .catch(function (err) { status.textContent = 'Upload error: ' + err.message; });
        });
      });
      // Keyboard-aware composer: pin the text box just above the on-screen keyboard
      // so it's visible the instant you focus it (Buck: "I can't see the text box
      // off the bat"). visualViewport shrinks when the keyboard opens → lift by the delta.
      if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', lrsAdjustKb);
        window.visualViewport.addEventListener('scroll', lrsAdjustKb);
      }
      inp.addEventListener('focus', function () { setTimeout(lrsAdjustKb, 120); setTimeout(lrsAdjustKb, 330); });
      inp.addEventListener('blur', function () { setTimeout(lrsAdjustKb, 80); });
      // FB-style (Ian 2026-06-10): the pinned bar is now a TRIGGER — tapping any
      // part of it opens the floating composer sheet instead of typing inline.
      inp.setAttribute('readonly', 'readonly');
      sh.querySelector('.lrs-comp').addEventListener('click', function (ev) {
        ev.preventDefault(); ev.stopPropagation();
        openComposerSheet({
          tid: sh.getAttribute('data-tid'), fid: sh.getAttribute('data-fid'),
          title: (sh.querySelector('.lrs-t') || {}).textContent, focus: true
        });
      }, true);
    }
    // per-open setup
    sh.setAttribute('data-tid', tid || ''); sh.setAttribute('data-fid', fid);
    // Title = the post's title (this is the discussion modal now, not just a reply list).
    var ttlEl = card.querySelector('.fc-title, .feed-card__title');
    var ttl = ttlEl ? (ttlEl.textContent || '').trim() : '';
    sh.querySelector('.lrs-t').textContent = ttl || (n ? (n + ' replies') : 'Replies');
    var bd0 = sh.querySelector('#lrs-body'); if (bd0) bd0.scrollTop = 0;
    // Replies intent (Buck 2026-06-11): opened from a replies control → land on the
    // thread, not the OP. Timestamped so late async loads (OP body) can re-anchor
    // for a few seconds without hijacking the sheet later (e.g. post-reply reloads).
    sh.__lgToReplies = (opts && opts.toReplies) ? Date.now() : 0;
    var av = sh.querySelector('#lrs-comp-av'); var avs = lrsViewerAvatar();
    av.innerHTML = avs ? '<img src="' + avs.replace(/"/g, '&quot;') + '" alt="">' : '';
    var inp2 = sh.querySelector('#lrs-comp-input'); inp2.value = ''; inp2.style.height = 'auto';
    sh.querySelector('#lrs-comp-send').disabled = true;
    sh.querySelector('#lrs-comp-status').textContent = '';
    lrsMediaIds.length = 0;
    var pv0 = sh.querySelector('#lrs-comp-previews'); if (pv0) pv0.innerHTML = '';
    lrsScroll = document.body.style.overflow; document.body.style.overflow = 'hidden';
    sh.classList.add('is-open');
    if (!lrsHist) { try { history.pushState({ lgRs: 1 }, ''); lrsHist = true; } catch (e) {} }
    // Reply intent → open the FB-style composer sheet on top (focus is synchronous
    // within the originating tap so iOS honors the keyboard).
    if (opts && opts.focus) openComposerSheet({ tid: tid, fid: fid, title: ttl, focus: true });
    lrsLoadOp(sh, card, tid);
    // No topic id (audit 2026-06-11 H6: dynamically built legacy cards) → no thread
    // to fetch and nothing valid to post to; hide the composer instead of letting
    // Post submit to a null topic.
    var compEl = sh.querySelector('.lrs-comp');
    if (compEl) compEl.style.display = tid ? '' : 'none';
    if (!tid) { (sh.querySelector('#lrs-thread') || sh.querySelector('#lrs-body')).innerHTML = '<div class="lrs-note">Couldn’t load replies.</div>'; return; }
    lrsLoadThread(tid);
  }
  // Post a top-level reply to the topic, then reload the thread to show it.
  function lrsSubmit(sh) {
    var inp = sh.querySelector('#lrs-comp-input'), send = sh.querySelector('#lrs-comp-send'), status = sh.querySelector('#lrs-comp-status');
    var text = (inp.value || '').trim(); if (!text && !lrsMediaIds.length) return;
    var tid = parseInt(sh.getAttribute('data-tid'), 10); if (!tid) return;
    var fid = parseInt(sh.getAttribute('data-fid'), 10);
    send.disabled = true; status.textContent = 'Posting…';
    lrsGetAuth(function (a) {
      if (!a || !a.authenticated) { status.textContent = 'Sign in to reply.'; send.disabled = false; return; }
      var payload = { topic_id: tid, content: text ? '<p>' + lrsEsc(text).replace(/\n/g, '<br>') + '</p>' : '' };
      if (fid) payload.forum_id = fid;
      if (lrsMediaIds.length) payload.bbp_media = lrsMediaIds.slice();
      fetch(LRS_REPLY_BASE + '/reply', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': a.nonce },
        body: JSON.stringify(payload)
      })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
          if (!res.ok) { status.textContent = (res.j && (res.j.message || res.j.code)) || 'Could not post.'; send.disabled = false; return; }
          inp.value = ''; inp.style.height = 'auto'; status.textContent = '';
          lrsMediaIds.length = 0;
          var pv = sh.querySelector('#lrs-comp-previews'); if (pv) pv.innerHTML = '';
          lrsLoadThread(tid);                                  // reload so the new reply shows
          var b = sh.querySelector('#lrs-body'); if (b) setTimeout(function () { b.scrollTop = b.scrollHeight; }, 600);
        })
        .catch(function () { status.textContent = 'Network error.'; send.disabled = false; });
    });
  }

  // ── FB-style reply composer sheet (Ian via Buck 2026-06-10: replies should
  // compose like Facebook's share sheet) — a compact floating card over the
  // content: grab pill, your avatar + name, a context pill naming the post, a
  // big open input, photo attach, and ONE big Post button. Opens from the card's
  // Reply action and from the modal's pinned "Write a comment…" bar (now a
  // trigger). Posting reuses the same canonical REST flow as lrsSubmit.
  var lcpMediaIds = [];
  function ensureCompSheet() {
    var sh = document.getElementById('looth-comp-sheet');
    if (sh) return sh;
    if (!document.getElementById('lg-lcp-css')) {
      var st = document.createElement('style'); st.id = 'lg-lcp-css';
      var D = 'html[data-lguser-theme="dark"]';
      st.textContent = [
        '#looth-comp-sheet{position:fixed;inset:0;z-index:2147483560;display:none}',
        '#looth-comp-sheet.is-open{display:block}',
        // light scrim — the thread in the modal behind stays readable ABOVE the
        // composer card (Buck 2026-06-10: show the replies while writing)
        '#looth-comp-sheet .lcp-back{position:absolute;inset:0;background:rgba(15,16,12,.18)}',
        '#looth-comp-sheet .lcp-card{position:absolute;left:10px;right:10px;bottom:max(10px,env(safe-area-inset-bottom,0px));' +
          'background:#fff;border-radius:22px;box-shadow:0 10px 44px rgba(0,0,0,.3);padding:2px 16px 14px;' +
          'animation:looth-pwa-up .26s ease;will-change:transform;font:15px/1.4 var(--lg-font-sans,system-ui,sans-serif)}',
        '#looth-comp-sheet .lcp-grab{height:18px;display:flex;align-items:center;justify-content:center;touch-action:none;cursor:grab}',
        '#looth-comp-sheet .lcp-grab::before{content:"";width:36px;height:4px;border-radius:3px;background:#d8d2c4}',
        '#looth-comp-sheet .lcp-head{display:flex;align-items:center;gap:10px;margin:4px 0 2px}',
        '#looth-comp-sheet .lcp-av{width:38px;height:38px;border-radius:50%;overflow:hidden;flex:0 0 auto;background:var(--lg-sage-tint,#eef2e3)}',
        '#looth-comp-sheet .lcp-av img{width:100%;height:100%;object-fit:cover;display:block}',
        '#looth-comp-sheet .lcp-id{min-width:0;flex:1 1 auto}',
        '#looth-comp-sheet .lcp-name{font:700 15px/1.2 var(--lg-font-sans,system-ui,sans-serif);color:var(--lg-charcoal,#1a1d1a)}',
        '#looth-comp-sheet .lcp-ctx{display:inline-block;margin-top:4px;background:var(--lg-sage-tint,#eef2e3);color:var(--lg-sage-d,#6b7c52);' +
          'font:600 11.5px/1 var(--lg-font-sans,system-ui,sans-serif);border-radius:999px;padding:5px 10px;max-width:240px;' +
          'overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:top}',
        '#looth-comp-sheet .lcp-input{width:100%;box-sizing:border-box;border:0;outline:0;background:none;resize:none;' +
          'font:17px/1.45 var(--lg-font-sans,system-ui,sans-serif);color:var(--lg-ink,#1a1d1a);min-height:84px;max-height:200px;padding:10px 2px 6px}',
        '#looth-comp-sheet .lcp-input::placeholder{color:#9aa097}',
        '#looth-comp-sheet .lcp-title{width:100%;box-sizing:border-box;margin:2px 0 6px;border:0;border-bottom:1px solid var(--lg-line,#e3e0d8);outline:0;background:none;' +
          'font:700 17px/1.3 var(--lg-font-sans,system-ui,sans-serif);color:var(--lg-ink,#1a1d1a);padding:6px 2px}',
        '#looth-comp-sheet .lcp-title::placeholder{color:#9aa097;font-weight:600}',
        '#looth-comp-sheet .lcp-row{display:flex;align-items:center;gap:10px;margin:0 0 10px;flex-wrap:wrap}',
        '#looth-comp-sheet .lcp-photo{flex:0 0 auto;border:0;background:none;cursor:pointer;color:var(--lg-sage-d,#6b7c52);padding:4px 2px;line-height:0}',
        '#looth-comp-sheet .lcp-photo svg{width:22px;height:22px}',
        '#looth-comp-sheet .lcp-previews{display:flex;gap:8px;flex-wrap:wrap}',
        '#looth-comp-sheet .lcp-previews:empty{display:none}',
        '#looth-comp-sheet .lcp-pv{position:relative;display:inline-block}',
        '#looth-comp-sheet .lcp-pv img{width:52px;height:52px;object-fit:cover;border-radius:10px;display:block;border:1px solid var(--lg-line,#e3ddd0)}',
        '#looth-comp-sheet .lcp-pv-x{position:absolute;top:-7px;right:-7px;width:20px;height:20px;border:0;border-radius:50%;background:rgba(26,29,26,.75);color:#fff;font:700 13px/20px sans-serif;cursor:pointer;padding:0}',
        '#looth-comp-sheet .lcp-status{flex-basis:100%;font:12px/1.3 var(--lg-font-sans,system-ui,sans-serif);color:#8a8d91}',
        '#looth-comp-sheet .lcp-status:empty{display:none}',
        '#looth-comp-sheet .lcp-post{display:block;width:100%;box-sizing:border-box;border:0;border-radius:12px;cursor:pointer;' +
          'background:var(--lg-sage,#87986a);color:#fff;font:700 15px/1 var(--lg-font-sans,system-ui,sans-serif);padding:14px}',
        '#looth-comp-sheet .lcp-post:disabled{background:#c9cfc0;cursor:default}',
        // dark pass
        D + ' #looth-comp-sheet .lcp-card{background:#1b1e21;color:#e5e7e1}',
        D + ' #looth-comp-sheet .lcp-grab::before{background:#3a403a}',
        D + ' #looth-comp-sheet .lcp-name{color:#f2f4ee}',
        D + ' #looth-comp-sheet .lcp-ctx{background:#243024;color:#b6c79a}',
        D + ' #looth-comp-sheet .lcp-input{color:#e5e7e1;background:none!important;border:0!important}',
        D + ' #looth-comp-sheet .lcp-input::placeholder{color:#7e857c}',
        D + ' #looth-comp-sheet .lcp-title{color:#f2f4ee;border-bottom-color:#343a33}',
        D + ' #looth-comp-sheet .lcp-title::placeholder{color:#7e857c}',
        D + ' #looth-comp-sheet .lcp-av{background:#262b30}',
        D + ' #looth-comp-sheet .lcp-pv img{border-color:#2c312d}',
        D + ' #looth-comp-sheet .lcp-post{background:var(--lg-sage-d,#6b7c52)}',
        D + ' #looth-comp-sheet .lcp-post:disabled{background:#2c312d;color:#7e857c}'
      ].join('\n');
      (document.head || document.documentElement).appendChild(st);
    }
    sh = document.createElement('div'); sh.id = 'looth-comp-sheet';
    sh.setAttribute('role', 'dialog'); sh.setAttribute('aria-modal', 'true'); sh.setAttribute('aria-label', 'Write a reply');
    sh.innerHTML =
      '<div class="lcp-back" data-lcp-close></div>' +
      '<div class="lcp-card">' +
        '<div class="lcp-grab" aria-hidden="true"></div>' +
        '<div class="lcp-head"><span class="lcp-av" id="lcp-av"></span>' +
          '<div class="lcp-id"><div class="lcp-name" id="lcp-name">You</div><span class="lcp-ctx" id="lcp-ctx" hidden></span></div></div>' +
        // Title row — shown ONLY when editing a TOPIC/OP (editTopicId), so this same
        // composer doubles as the OP editor on mobile (parity with desktop). Hidden
        // for replies. (Ian 2026-06-25, "new edit".)
        '<input class="lcp-title" id="lcp-title" type="text" placeholder="Post title" maxlength="200" autocomplete="off" hidden>' +
        '<textarea class="lcp-input" id="lcp-input" rows="3" placeholder="Write a comment…"></textarea>' +
        '<div class="lcp-row">' +
          '<button class="lcp-photo" id="lcp-photo" type="button" aria-label="Add photo" title="Add photo">' +
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="8.5" cy="10" r="1.8"/><path d="M4 17l4.5-4.5 3 3L16 11l4 4"/></svg></button>' +
          '<input type="file" id="lcp-file" accept="image/*" style="display:none">' +
          '<span class="lcp-previews" id="lcp-previews"></span>' +
          '<span class="lcp-status" id="lcp-status"></span>' +
        '</div>' +
        '<button class="lcp-post" id="lcp-post" type="button" disabled>Post</button>' +
      '</div>';
    (document.body || document.documentElement).appendChild(sh);
    sh.addEventListener('click', function (e) { if (e.target.closest('[data-lcp-close]')) closeComposerSheet(); });
    var card = sh.querySelector('.lcp-card');
    // drag the grab pill down to dismiss
    (function () {
      var grab = sh.querySelector('.lcp-grab'), sy = 0, dy = 0, on = false;
      grab.addEventListener('touchstart', function (e) { sy = e.touches[0].clientY; dy = 0; on = true; card.style.transition = 'none'; }, { passive: true });
      grab.addEventListener('touchmove', function (e) {
        if (!on) return; dy = Math.max(0, e.touches[0].clientY - sy);
        card.style.transform = 'translateY(' + dy + 'px)'; if (e.cancelable) e.preventDefault();
      }, { passive: false });
      grab.addEventListener('touchend', function () {
        if (!on) return; on = false; card.style.transition = ''; card.style.transform = '';
        if (dy > 90) closeComposerSheet();
      });
    })();
    var ta = sh.querySelector('#lcp-input'), post = sh.querySelector('#lcp-post');
    var titleElW = sh.querySelector('#lcp-title');
    // Enable Post/Save: topic edit needs BOTH a title and a body; everything else
    // (reply create/edit) allows photo-only.
    function lcpRecalcPost() {
      var c = sh.__lcpCtx || {};
      if (c.editTopicId) { post.disabled = !(ta.value.trim() && titleElW && titleElW.value.trim()); return; }
      var keepN = (c.keepMedia && c.keepMedia.length) || 0;
      post.disabled = !ta.value.trim() && !lcpMediaIds.length && !keepN;
    }
    ta.addEventListener('input', function () {
      lcpRecalcPost();
      ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight, 200) + 'px';
    });
    if (titleElW) titleElW.addEventListener('input', lcpRecalcPost);
    post.addEventListener('click', function () { lcpSubmit(sh); });
    // photo attach — same canonical contract as the modal composer (media/upload → bbp_media)
    var photoBtn = sh.querySelector('#lcp-photo'), fileIn = sh.querySelector('#lcp-file');
    photoBtn.addEventListener('click', function () { fileIn.click(); });
    fileIn.addEventListener('change', function () {
      var file = fileIn.files && fileIn.files[0];
      fileIn.value = '';
      if (!file) return;
      var status = sh.querySelector('#lcp-status');
      status.textContent = 'Uploading photo…';
      lrsGetAuth(function (a) {
        if (!a || !a.authenticated) { status.textContent = 'Sign in to add photos.'; return; }
        var fd = new FormData(); fd.append('file', file);
        fetch(LRS_REPLY_BASE + '/media/upload', { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': a.nonce }, body: fd })
          .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
          .then(function (res) {
            if (!res.ok || !res.j.upload_id) { status.textContent = 'Photo upload failed: ' + ((res.j && res.j.message) || 'error'); return; }
            lcpMediaIds.push(res.j.upload_id);
            status.textContent = '';
            var pv = sh.querySelector('#lcp-previews');
            var chip = document.createElement('span'); chip.className = 'lcp-pv';
            chip.innerHTML = '<img src="' + String(res.j.upload_thumb || res.j.upload).replace(/"/g, '&quot;') + '" alt="">' +
              '<button type="button" class="lcp-pv-x" aria-label="Remove photo">&times;</button>';
            chip.querySelector('.lcp-pv-x').addEventListener('click', function () {
              var ix = lcpMediaIds.indexOf(res.j.upload_id);
              if (ix > -1) lcpMediaIds.splice(ix, 1);
              chip.remove();
              post.disabled = !ta.value.trim() && !lcpMediaIds.length;
            });
            pv.appendChild(chip);
            post.disabled = false;
          })
          .catch(function (err) { status.textContent = 'Upload error: ' + err.message; });
      });
    });
    // keyboard-aware: lift the floating card above the on-screen keyboard
    function lcpKb() {
      if (!sh.classList.contains('is-open') || !window.visualViewport) { card.style.transform = ''; return; }
      var vv = window.visualViewport;
      var kb = Math.max(0, Math.round(window.innerHeight - vv.height - vv.offsetTop));
      card.style.transform = kb > 1 ? ('translateY(-' + kb + 'px)') : '';
    }
    if (window.visualViewport) {
      window.visualViewport.addEventListener('resize', lcpKb);
      window.visualViewport.addEventListener('scroll', lcpKb);
    }
    ta.addEventListener('focus', function () { setTimeout(lcpKb, 120); setTimeout(lcpKb, 330); });
    ta.addEventListener('blur', function () { setTimeout(lcpKb, 80); });
    return sh;
  }
  function openComposerSheet(o) {
    o = o || {};
    var sh = ensureCompSheet();
    // Rebuilt fresh on EVERY open — replyTo from a comment-reply open can never
    // leak into a later OP-reply open (and vice versa).
    sh.__lcpCtx = {
      tid: parseInt(o.tid, 10) || 0, fid: parseInt(o.fid, 10) || 0,
      replyTo: parseInt(o.replyTo, 10) || 0, replyToName: (o.replyToName || '').trim()
    };
    var av = sh.querySelector('#lcp-av'); var avs = lrsViewerAvatar();
    av.innerHTML = avs ? '<img src="' + avs.replace(/"/g, '&quot;') + '" alt="">' : '';
    var nameEl = sh.querySelector('#lcp-name');
    nameEl.textContent = 'You';
    lrsGetAuth(function (a) { if (a && a.display_name) nameEl.textContent = a.display_name; });
    var ctx = sh.querySelector('#lcp-ctx');
    // Replying to a comment → name the comment's author; otherwise the topic title.
    var who = sh.__lcpCtx.replyTo ? (sh.__lcpCtx.replyToName || 'a comment') : (o.title || '').trim();
    if (who) { ctx.hidden = false; ctx.textContent = 'Replying to: ' + (who.length > 34 ? who.slice(0, 33) + '…' : who); }
    else { ctx.hidden = true; }
    var ta = sh.querySelector('#lcp-input');
    ta.value = ''; ta.style.height = 'auto';
    var postBtn = sh.querySelector('#lcp-post');
    postBtn.disabled = true;
    sh.querySelector('#lcp-status').textContent = '';
    sh.querySelector('#lcp-previews').innerHTML = '';
    lcpMediaIds.length = 0;
    var titleEl = sh.querySelector('#lcp-title');
    if (titleEl) { titleEl.value = ''; titleEl.hidden = true; }
    // ── EDIT MODE — reuse this composer to EDIT a reply (Ian 2026-06-25): pre-fill
    //    the text, label the button "Save", and load existing photos as removable
    //    thumbs below the input (✕ drops the id from keepMedia → removed on save). ──
    sh.__lcpCtx.editReplyId = parseInt(o.editReplyId, 10) || 0;
    sh.__lcpCtx.editTopicId = parseInt(o.editTopicId, 10) || 0;
    sh.__lcpCtx.keepMedia   = [];
    if (sh.__lcpCtx.editTopicId) {
      // ── TOPIC/OP edit — the SAME composer doubles as the OP editor (unified
      //    "new edit", parity with desktop): show the title field, pre-fill
      //    title + body, load existing photos as removable thumbs. Save → owned
      //    reply.php topic PUT (+ topic-media.php for photos). ──
      var teid = sh.__lcpCtx.editTopicId, pvElT = sh.querySelector('#lcp-previews');
      ctx.hidden = false; ctx.textContent = '✎ Editing your post';
      if (titleEl) { titleEl.hidden = false; titleEl.value = o.title || ''; }
      ta.value = o.bodyText || '';
      postBtn.textContent = 'Save';
      postBtn.disabled = !(ta.value.trim() && titleEl && titleEl.value.trim());
      fetch('/bb-mirror-api/v0/topic-media?topic_id=' + teid, { credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) {
          if (!d || !d.ok || !d.media || sh.__lcpCtx.editTopicId !== teid) return;
          if (d.media.length) sh.__lcpCtx.topicHadMedia = true;   // so removals get synced on save
          d.media.forEach(function (m) {
            sh.__lcpCtx.keepMedia.push(m.media_id);
            var chip = document.createElement('span'); chip.className = 'lcp-pv';
            chip.innerHTML = '<img src="' + String(m.thumb || m.url).replace(/"/g, '&quot;') + '" alt="">' +
              '<button type="button" class="lcp-pv-x" aria-label="Remove photo">&times;</button>';
            chip.querySelector('.lcp-pv-x').addEventListener('click', function () {
              var ix = sh.__lcpCtx.keepMedia.indexOf(m.media_id);
              if (ix > -1) sh.__lcpCtx.keepMedia.splice(ix, 1);
              chip.remove();
            });
            pvElT.appendChild(chip);
          });
        })
        .catch(function () {});
    } else if (sh.__lcpCtx.editReplyId) {
      ctx.hidden = false; ctx.textContent = '✎ Editing your reply';
      ta.value = o.bodyText || '';
      postBtn.textContent = 'Save';
      postBtn.disabled = !ta.value.trim();
      var eid = sh.__lcpCtx.editReplyId, pvEl = sh.querySelector('#lcp-previews');
      fetch('/bb-mirror-api/v0/reply?reply_id=' + eid, { credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) {
          if (!d || !d.ok || !d.media || sh.__lcpCtx.editReplyId !== eid) return;
          d.media.forEach(function (m) {
            sh.__lcpCtx.keepMedia.push(m.media_id);
            var chip = document.createElement('span'); chip.className = 'lcp-pv';
            chip.innerHTML = '<img src="' + String(m.thumb || m.url).replace(/"/g, '&quot;') + '" alt="">' +
              '<button type="button" class="lcp-pv-x" aria-label="Remove photo">&times;</button>';
            chip.querySelector('.lcp-pv-x').addEventListener('click', function () {
              var ix = sh.__lcpCtx.keepMedia.indexOf(m.media_id);
              if (ix > -1) sh.__lcpCtx.keepMedia.splice(ix, 1);
              chip.remove();
              postBtn.disabled = !ta.value.trim() && !lcpMediaIds.length && !sh.__lcpCtx.keepMedia.length;
            });
            pvEl.appendChild(chip);
          });
          postBtn.disabled = !ta.value.trim() && !lcpMediaIds.length && !sh.__lcpCtx.keepMedia.length;
        })
        .catch(function () {});
    } else {
      postBtn.textContent = 'Post';
    }
    sh.classList.add('is-open');
    // bring the latest replies into view in the modal behind, so the user reads
    // the conversation right above the composer while writing
    var rs = document.getElementById('looth-rep-sheet');
    if (rs && rs.classList.contains('is-open')) {
      var bd = rs.querySelector('#lrs-body');
      if (bd) { try { bd.scrollTo({ top: bd.scrollHeight, behavior: 'smooth' }); } catch (e) { bd.scrollTop = bd.scrollHeight; } }
    }
    if (o.focus !== false) { try { ta.focus({ preventScroll: true }); } catch (e) { try { ta.focus(); } catch (e2) {} } }
  }
  function closeComposerSheet() {
    var sh = document.getElementById('looth-comp-sheet');
    if (sh) sh.classList.remove('is-open');
    // belt-and-braces vs the per-open rebuild: a closed sheet can't hold a stale
    // comment target
    if (sh && sh.__lcpCtx) sh.__lcpCtx.replyTo = 0;
  }
  function lcpSubmit(sh) {
    var ta = sh.querySelector('#lcp-input'), post = sh.querySelector('#lcp-post'), status = sh.querySelector('#lcp-status');
    var ctx = sh.__lcpCtx || {};
    var text = (ta.value || '').trim();
    // TOPIC/OP edit → owned reply.php topic PUT (title+body), then topic-media.php
    // for photo keep/add/remove (the SAME endpoints the wizard used). Reload after,
    // since OP photos can't be patched in place on the mobile sheet.
    if (ctx.editTopicId) {
      var titleElS = sh.querySelector('#lcp-title');
      var tTitle = (titleElS && titleElS.value.trim()) || '';
      if (!text)   { status.textContent = "Post can't be empty."; return; }
      if (!tTitle) { status.textContent = 'Title is required.'; if (titleElS) titleElS.focus(); return; }
      post.disabled = true; status.textContent = 'Saving…';
      lrsGetAuth(function (a) {
        if (!a || !a.authenticated) { status.textContent = 'Sign in to edit.'; post.disabled = false; return; }
        var teid  = ctx.editTopicId;
        var added = lcpMediaIds.slice();
        var keep  = (ctx.keepMedia || []).slice();
        var syncPhotos = ctx.topicHadMedia || added.length > 0;
        fetch('/bb-mirror-api/v0/reply', {
          method: 'PUT', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': a.nonce },
          body: JSON.stringify({ topic_id: teid, title: tTitle, content: '<p>' + lrsEsc(text).replace(/\n/g, '<br>') + '</p>' })
        })
          .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }, function () { return { ok: r.ok, j: {} }; }); })
          .then(function (res) {
            if (!res.ok) { status.textContent = (res.j && (res.j.message || res.j.error)) || 'Could not save.'; post.disabled = false; return; }
            var finish = function () { try { location.reload(); } catch (e) { closeComposerSheet(); } };
            if (syncPhotos) {
              status.textContent = 'Saving photos…';
              fetch('/bb-mirror-api/v0/topic-media', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': a.nonce },
                body: JSON.stringify({ topic_id: teid, keep_media_ids: keep, add_upload_ids: added })
              }).then(finish, finish);
            } else { finish(); }
          })
          .catch(function () { status.textContent = 'Network error.'; post.disabled = false; });
      });
      return;
    }
    // EDIT mode → PUT the owned endpoint (author-or-mod): content + new photos
    // (media_ids) + kept photos (keep_media_ids; removes the rest, no orphans).
    if (ctx.editReplyId) {
      var keepN = (ctx.keepMedia && ctx.keepMedia.length) || 0;
      if (!text && !lcpMediaIds.length && !keepN) { status.textContent = "Reply can't be empty."; return; }
      post.disabled = true; status.textContent = 'Saving…';
      lrsGetAuth(function (a) {
        if (!a || !a.authenticated) { status.textContent = 'Sign in to edit.'; post.disabled = false; return; }
        var payload = {
          reply_id: ctx.editReplyId,
          content: text ? '<p>' + lrsEsc(text).replace(/\n/g, '<br>') + '</p>' : '',
          keep_media_ids: (ctx.keepMedia || []).slice()
        };
        if (lcpMediaIds.length) payload.media_ids = lcpMediaIds.slice();
        fetch('/bb-mirror-api/v0/reply', {
          method: 'PUT', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': a.nonce },
          body: JSON.stringify(payload)
        })
          .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }, function () { return { ok: r.ok, j: {} }; }); })
          .then(function (res) {
            if (!res.ok) { status.textContent = (res.j && (res.j.message || res.j.error)) || 'Could not save.'; post.disabled = false; return; }
            closeComposerSheet();
            var rs = document.getElementById('looth-rep-sheet');
            var rtid = rs && parseInt(rs.getAttribute('data-tid'), 10);
            if (rs && rs.classList.contains('is-open') && rtid) lrsLoadThread(rtid);
          })
          .catch(function () { status.textContent = 'Network error.'; post.disabled = false; });
      });
      return;
    }
    if (!text && !lcpMediaIds.length) return;
    var tid = ctx.tid; if (!tid) { status.textContent = 'Couldn’t find the post.'; return; }
    post.disabled = true; status.textContent = 'Posting…';
    lrsGetAuth(function (a) {
      if (!a || !a.authenticated) { status.textContent = 'Sign in to reply.'; post.disabled = false; return; }
      var payload = { topic_id: tid, content: text ? '<p>' + lrsEsc(text).replace(/\n/g, '<br>') + '</p>' : '' };
      if (ctx.fid) payload.forum_id = ctx.fid;
      if (ctx.replyTo) payload.reply_to = ctx.replyTo;   // nest under the comment being replied to
      if (lcpMediaIds.length) payload.bbp_media = lcpMediaIds.slice();
      var wasNested = !!ctx.replyTo;
      fetch(LRS_REPLY_BASE + '/reply', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': a.nonce },
        body: JSON.stringify(payload)
      })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
          if (!res.ok) { status.textContent = (res.j && (res.j.message || res.j.code)) || 'Could not post.'; post.disabled = false; return; }
          var newId = (res.j && (res.j.reply_id || res.j.id)) || 0;
          closeComposerSheet();
          // live-refresh the discussion modal if it's open on this topic
          var rs = document.getElementById('looth-rep-sheet');
          if (rs && rs.classList.contains('is-open') && parseInt(rs.getAttribute('data-tid'), 10) === tid) {
            lrsLoadThread(tid);
            var b = rs.querySelector('#lrs-body');
            if (b && wasNested && newId) {
              // The thread sorts newest TOP-LEVEL first, so a nested reply can land
              // anywhere — poll for its stub (batches stream in via lrsLoadAll) and
              // center it; fall back to the bottom if it never shows (e.g. held
              // for moderation).
              var tries = 0;
              (function find() {
                var el = rs.querySelector('#lrs-thread .reply-stub[data-reply-id="' + newId + '"]');
                if (el) { try { el.scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch (e) { el.scrollIntoView(); } return; }
                if (++tries > 30) { b.scrollTop = b.scrollHeight; return; }
                setTimeout(find, 200);
              })();
            } else if (b) {
              setTimeout(function () { b.scrollTop = b.scrollHeight; }, 600);
            }
          }
        })
        .catch(function () { status.textContent = 'Network error.'; post.disabled = false; });
    });
  }

  function wireExpandLoadAll() {
    if (!window.matchMedia('(max-width:640px)').matches) return;   // mobile only — desktop keeps native inline expand
    if (document.body.getAttribute('data-lg-loadall')) return;
    document.body.setAttribute('data-lg-loadall', '1');
    document.addEventListener('click', function (e) {
      var exp = e.target.closest && e.target.closest('.feed-card__expand');
      if (exp) {
        var c = exp.closest('.feed-card');
        if (!c || c.__lgRepLoading || c.__lgPreviewing) return;          // our/teaser programmatic clicks
        if (/hide/i.test(exp.textContent || '')) return;                 // collapsing → let native
        e.preventDefault(); e.stopPropagation();
        openRepliesSheet(c, { toReplies: true });                        // "View N replies" → popup, thread on top
        return;
      }
      var lm = e.target.closest && e.target.closest('.replies-loadmore');
      if (lm) { var c2 = lm.closest('.feed-card'); if (c2 && !c2.__lgRepLoading) { e.preventDefault(); e.stopPropagation(); openRepliesSheet(c2, { toReplies: true }); } }
    }, true);                                                  // capture: own the tap, drive the loader ourselves
  }

  // Immersive (edge-to-edge) Hub feed — toggled via the "Hub feed" setting
  // (app-settings.js sets data-lguser-feed="immersive" on <html>). Instagram-style:
  // drop the card border / radius / side gutter and let cover photos go full-bleed
  // to the screen edges, while the text + meta stay inset by the card padding. The
  // default ('cards') is untouched — these rules only match when the attribute is
  // set, so injecting them always on the Hub is a no-op otherwise. Injected once.
  function ensureImmersiveCss() {
    if (document.getElementById('lg-immersive-css')) return;
    var P = 'html[data-lguser-feed="immersive"] .feed-page';
    var css = [
      // remove the feed-page side gutter so cards span the full viewport width
      P + '{padding-left:0!important;padding-right:0!important}',
      // tighten the gap between posts to ~half (Buck 2026-06-08): the feed is a
      // grid with row-gap 16px + the card's own 6px margin = ~22px. Halve both.
      P + ' .feed{row-gap:8px!important}',
      // strip the card chrome; keep a slim divider between posts (IG-style)
      P + ' .feed-card{border-radius:0!important;box-shadow:none!important;border-left:0!important;' +
        'border-right:0!important;border-top:0!important;border-bottom:1px solid var(--lguser-line,#e3ddd0)!important;margin:0 0 3px!important}',
      // cover photos break out of the 14px card padding → edge to edge
      P + ' .feed-card__cover,' + P + ' .fc-cover{margin-left:-14px!important;margin-right:-14px!important;' +
        'width:auto!important;max-width:none!important;border-radius:0!important}',
      P + ' .feed-card__cover-img,' + P + ' .fc-cover img{border-radius:0!important;width:100%!important;max-width:none!important}'
    ].join('\n');
    // Mobile/app only — desktop always keeps the bordered card feed regardless of
    // the user's feed setting (Buck 2026-06-08: none of the immersive work touches desktop).
    css = '@media (max-width:640px){\n' + css + '\n}';
    var s = document.createElement('style'); s.id = 'lg-immersive-css'; s.textContent = css;
    (document.head || document.documentElement).appendChild(s);
  }

  // Desktop Hub polish (Buck 2026-06-09): the Hub front door was 100% mobile-gated
  // (every other block here is @media max-width:640) so the DESKTOP feed was raw
  // forums.css — a flat single column that ignored the user's app theme. This adds
  // the desktop look, mirroring the mobile app:
  //   - MOSAIC layout (Buck's pick from the desktop Style Sandbox): the first post
  //     is a full-width hero, the rest fall into a 2-column card grid.
  //   - Card treatment matching the app: 16px radius + soft float shadow.
  //   - THEME-FOLLOWING: the feed reads the same --lguser-* tokens app-settings.js
  //     sets, so picking Sage/Amber/Rust/Dark in settings recolors the desktop feed
  //     exactly like mobile. The crux (Buck 2026-06-09): forums.css already defines
  //     --lguser-* under prefers-color-scheme:dark, so with NO theme picked the
  //     tokens read OS-dark and the default correctly FOLLOWS THE OS (Buck's call) —
  //     we only chain the card BACKGROUND to forums' --bg-card (the one token the
  //     themes set but forums leaves to OS), so default stays OS-consistent while a
  //     picked theme's --lguser-card wins. Verified live at 1280/3440px, both the
  //     OS-dark default and a picked light theme (amber) render correctly.
  // Gated min-width:641 so it never touches the mobile/app pass. Injected once.
  function ensureDesktopCss() {
    if (document.getElementById('lg-desktop-css')) return;
    var P = '.feed-page';
    // Picked-LIGHT-theme selector: app-settings sets both data-lguser-dark="0"
    // AND data-lguser-theme="<id>" on <html>. Matches cream/sage/amber/rust/custom
    // but NOT the no-pick default (which stays on forums' OS/native theme) and NOT
    // the picked Dark theme (dark="1"). Used to re-theme the desktop SHELL.
    var L = 'html[data-lguser-dark="0"]:not([data-lguser-theme="default"])';
    var css = [
      // (page-bg rule RETIRED 2026-06-10 with the C10 unification: default = body
      // var(--bg); picked light = the forums.css bridge repoints --bg; picked dark
      // = the boot script's critical CSS. First frame paints themed already.)
      // MASONRY geometry (feed max-width 2100 / column-width 370 / 16:9 covers)
      // RETIRED from this overlay 2026-06-10 (bespoke-cutover, audit C2): it lives in
      // forums.css @media(min-width:641) — commit 88955fb — and paints first-frame
      // server-side. forums.css is the ONLY masonry-geometry source now. The
      // .feed-card display:block/break-inside props below still ship until the C3
      // arrangement/chrome fold: they also SUPPRESS forums.css's desktop grid
      // arrangement, so removing them is a deliberate visual step, not a dupe kill.
      // card shell matches the app; bg chains to forums --bg-card so DEFAULT follows
      // the OS while a picked theme's --lguser-card overrides. Masonry props here too:
      // each card is a whole block in the column flow with an even vertical rhythm.
      P + ' .feed-card{background:var(--lguser-card,var(--bg-card,#fff))!important;' +
        'border:1px solid var(--lguser-line,#e3ddd0)!important;border-radius:16px!important;' +
        'box-shadow:0 1px 2px rgba(26,29,26,.05),0 10px 22px -14px rgba(26,29,26,.28)!important;' +
        'display:block!important;width:100%!important;margin:0 0 18px!important;column-span:none!important;' +
        'break-inside:avoid!important;-webkit-column-break-inside:avoid!important;page-break-inside:avoid!important}',
      // text + pills follow the theme tokens (OS-aware for default)
      P + ' .feed-card__title,' + P + ' .feed-card__title a,' + P + ' .feed-card__op-author{color:var(--lguser-ink,#1a1d1a)!important}',
      P + ' .feed-card__op-excerpt,' + P + ' .fc-excerpt,' + P + ' .fc-time,' + P + ' .fc-category{color:var(--lguser-mute,#6b6f6b)!important}',
      P + ' .feed-card__kind-badge,' + P + ' .fc-cat-chip{background:var(--lguser-pill,#eef2e3)!important;' +
        'color:var(--lguser-accent-d,#52613d)!important;border-color:transparent!important}',

      // ── SHELL COHERENCE token repoint + rail-label force MOVED to forums.css
      // 2026-06-10 (bespoke-cutover, audit C10 unification): same gate selector,
      // plain cascade instead of !important, applied on the FIRST frame. The two
      // theme systems are bridged server-side now — this overlay no longer
      // re-themes the shell after load.

      // ── Reaction summary chips (.fcr-chip/.fcr-add): forums.css backs them with
      // var(--bg-card) (un-themed), so they were dark islands + failed-AA count text
      // on a light card. Chain to the themed card surface (default still falls to
      // --bg-card, staying dark-coherent).
      P + ' .fcr-chip{background:var(--lguser-card,var(--bg-card,#fff))!important}',

      // ── Make the "Add reaction" button OBVIOUS on desktop (Buck 2026-06-09): the
      // native .fcr-add was a tiny 28×24 "☺+" — easy to miss. Turn it into a clear,
      // bigger labelled pill ("☺ React") with the accent tint, a real hover state,
      // and a 34px hit height (also helps the elderly-friendly goal). Desktop only.
      P + ' .fcr-add{height:34px!important;min-width:0!important;width:auto!important;padding:0 14px!important;' +
        'border-radius:999px!important;gap:6px!important;background:var(--lguser-pill,var(--lg-sage-tint,#eef2e3))!important;' +
        'border:1px solid var(--lguser-line,#e3ddd0)!important;color:var(--lguser-accent-d,var(--lg-sage-d,#52613d))!important;' +
        'font-size:18px!important;line-height:1!important;transition:background .12s,color .12s,transform .05s}',
      P + ' .fcr-add>span{display:none!important}',                       // hide the tiny "+"
      P + ' .fcr-add::after{content:"React"!important;font:700 13px/1 var(--lg-font-sans,system-ui,-apple-system,sans-serif)!important;color:inherit}',
      P + ' .fcr-add:hover{background:var(--lguser-accent,var(--lg-sage,#87986a))!important;color:#fff!important}',
      P + ' .fcr-add:active{transform:translateY(1px)}',

      // (Desktop .lg-card-actions surfacing + bookmark-pill RETIRED 2026-06-10 —
      // Ian: "the bottom row on cpts could use some love". The canonical
      // .fc-actions row (reactions left, comment + ☆ save right) is THE row;
      // forums.css owns its desktop layout. Mobile action row untouched.)

      // ── Reply composer. The DESKTOP card composer is .fc-composer > .fc-composer__wrap
      // (the input "bubble") > input.fc-composer__input. The wrap hardcodes a dark
      // var(--bg-card) bubble + the input text is dark → invisible on a light theme.
      // Theme the bubble to the comment-bubble/pill surface and bind the input text
      // to --lguser-ink (default/dark fall back to --bg-card + --fg, staying legible).
      P + ' .fc-composer__wrap{background:var(--lguser-bubble,var(--lguser-pill,var(--bg-card,#eceff3)))!important;color:var(--lguser-mute,var(--fg-muted,#6b6f6b))!important}',
      P + ' .fc-composer__input{color:var(--lguser-ink,var(--fg,#1a1d1a))!important;background:transparent!important}',
      P + ' .fc-composer__input::placeholder{color:var(--lguser-mute,var(--fg-muted,#6b6f6b))!important}',
      // also cover the native reply-form textarea + the inline fb-composer input
      // (same dark-on-light defect via forums.css hardcoded white bg + color:var(--fg)).
      P + ' .reply-form,' + P + ' .reply-form textarea,' + P + ' .feed-card__inline-compose .fic-input{' +
        'background:var(--lguser-card,var(--bg-card,#fff))!important;color:var(--lguser-ink,var(--fg,#1a1d1a))!important;' +
        'border-color:var(--lguser-line,#e3ddd0)!important}',

      // ── Desktop grid-card meta uses .fc-* classes (not the .lg-card-* the mobile
      // pass themes). Theme author/time/category so the meta row follows too.
      P + ' .fc-author__name,' + P + ' .fc-author__name a{color:var(--lguser-ink,#1a1d1a)!important}',
      P + ' .fc-time{color:var(--lguser-mute,#6b6f6b)!important}',

      // (MOSAIC first-child hero RETIRED 2026-06-10 — Ian: uniform cards.
      // forums.css owns uniform cover sizing in both layouts now.)

      // (Author-search hide RETIRED 2026-06-10 — Ian: "we need author search
      // back". The server-rendered .hub-tsearch--author box shows again.)

      // ── Make the "Search the Hub" box LONGER (Buck 2026-06-09: it's stubby). Let it
      // grow to fill the sort bar's free space (with the rail collapsed there's plenty).
      P + ' .feed-sort-bar .feed-toolbar-search{flex:1 1 320px!important;min-width:240px!important;max-width:560px!important}',
      P + ' .feed-sort-bar .feed-toolbar-search .hub-tsearch--q{flex:1 1 auto!important;width:auto!important}',
      P + ' .feed-sort-bar .feed-toolbar-search .hub-tsearch--q .hub-tsearch__in{flex:1 1 auto!important;width:auto!important;min-width:0!important}',

      // ── Hide the cryptic forums-native quick toggles in the sort bar (Buck
      // 2026-06-09): "T" (.feed-text-toggle, cycle text size) and "C"
      // (.feed-theme-toggle, cycle color theme) are now fully covered — clearly
      // labelled — by the header settings gear (LGSettings: Text size + Color), and
      // "Cpt" (.feed-compact-toggle, compact view) is power-user clutter. Drop all
      // three so the toolbar is clean + consistent with mobile.
      '.feed-sort-bar .feed-text-toggle,.feed-sort-bar .feed-theme-toggle,.feed-sort-bar .feed-compact-toggle{display:none!important}'
    ].join('\n');
    css = '@media (min-width:641px){\n' + css + '\n}';
    // WIDE-SCREEN geometry, round 3 (Buck 2026-06-11 night, on his 1080p:
    // "it should max on 3 columns for this, have some buffer on the sides so
    // you dont over stretch the cards"): CAP the feed per column-count band
    // (a column ≈ 560px max) and center it — extra width becomes side margin
    // until a WHOLE new ~560px column fits, then the cap steps up. This is
    // the restored 1716/2294/2872 design the 17:21 forums.css cutover gutted;
    // it diverges from Ian's 6/11 "no caps, content meets the banner" — Buck
    // overrode that on sight tonight, flagged to the coordinator. Overlay
    // <style> loads after forums.css → same-specificity rules win in cascade
    // (forums' own 4@2400/5@3200 steps agree with these bands, so no fight).
    // 3-col cap is 1520 (not the old 1716): the ~272px nav rail eats into the
    // viewport, and at Buck's 1920×1080 the available 1648px must still leave
    // VISIBLE side buffer (≈64px/side, ≈478px cards) — 1716 left zero.
    // Round 4 (Buck 2026-06-11 night): "max on 4 columns" — the 5/6-col steps
    // are gone. The 4-col rule also PINS 4 against forums.css's 5@3200 (overlay
    // loads later → wins in cascade at every width above 2294). Width beyond
    // the capped feed goes to side margins, and past ~2620px the filter
    // sidebar auto-opens to spend it (see setupDesktopFilterNav).
    css += '\n@media (min-width:1101px){.feed-page .feed{max-width:1520px;margin-left:auto;margin-right:auto}}' +
           '\n@media (min-width:2294px){.feed-page .feed{max-width:2294px;column-count:4}}';
    var s = document.createElement('style'); s.id = 'lg-desktop-css'; s.textContent = css;
    (document.head || document.documentElement).appendChild(s);
  }

  // ── HUB STYLE: user-pickable desktop LAYOUT + an Appearance panel in the left
  // nav rail (Buck 2026-06-09: "give me my hub style and theme settings under
  // these [filters]... different versions of layout for people that want the hub
  // to look different, like our style cards"). Four layouts, persisted per-device
  // and applied as html[data-lg-hublayout]; the base (masonry) is the default. The
  // panel sits under the category filters: layout style-cards + theme swatches +
  // text size (theme/size proxy LGSettings, so they stay in sync with the gear).
  // Desktop only. ───────────────────────────────────────────────────────────────
  // Hub layout picker + the sidebar "Hub style" panel RETIRED 2026-06-10
  // (bespoke-cutover; Ian: the header GEAR is the only page-state control zone).
  // - The Mosaic/Stream toggle lives in the gear panel (app-settings buildPanel).
  // - data-lg-hublayout is set PRE-PAINT by the nginx boot script (localStorage
  //   lg-hub-layout; legacy cards/compact values map to masonry = Mosaic).
  // - Stream variant CSS lives in forums.css @media(min-width:641).

  // Tapping the BODY text of a reply (not the author name, not a link/action)
  // expands the collapsed thread — same as tapping "View N replies" (Buck
  // 2026-06-08). Only fires while collapsed; once expanded a body tap is inert.
  // Loothprint body tap -> the loothprint details sheet (Buck 2026-06-11:
  // clicking the body text of a loothprint card did NOTHING on desktop -- the
  // sheet's last caller was severed in the 6/10 cutover, leaving
  // openLoothprintSheet orphaned). Title/cover anchors still navigate (Ian:
  // CPTs click through); this only claims the otherwise-dead body/excerpt
  // taps. ALL widths. Registered BEFORE wirePostBodyExpand so the sheet wins
  // over the synthetic read-more toggle on mobile.
  function wireLoothprintTap() {
    if (document.body.getAttribute('data-lg-lptap')) return;
    document.body.setAttribute('data-lg-lptap', '1');
    document.addEventListener('click', function (e) {
      var card = e.target.closest && e.target.closest('.feed-card[data-kind="loothprint"]');
      if (!card) return;
      var body = e.target.closest('.feed-card__op-excerpt, .fc-excerpt, .feed-card__header-body, .fc-body');
      if (!body) return;
      if (e.target.closest('a, button, .feed-card__title, .fc-title, .lg-act, .lg-card-actions, .fc-actions, .fcr, .fcr-palette, .reply-stub, .fc-author')) return;
      e.preventDefault(); e.stopPropagation();
      try { openLoothprintSheet(card); } catch (err) {}
    }, true);
  }

  function wireReplyBodyExpand() {
    if (!window.matchMedia('(max-width:640px)').matches) return;   // mobile only — desktop unchanged
    if (document.body.getAttribute('data-lg-replybody')) return;
    document.body.setAttribute('data-lg-replybody', '1');
    document.addEventListener('click', function (e) {
      var body = e.target.closest && e.target.closest('.reply-stub__body, .reply-stub__excerpt');
      if (!body) return;
      // never hijack the author name, a link, or an action/reaction control
      if (e.target.closest('a, button, .lg-fb-name, .reply-stub__author, .lg-fb-actions, .reply-stub__actions, .fcr, .fcr-palette')) return;
      var card = body.closest('.feed-card'); if (!card) return;
      // Tapping a reply opens the full replies popup (Buck 2026-06-08) — the teaser
      // reply shows first; tapping it (or "View N replies") opens the sheet with all.
      e.preventDefault(); e.stopPropagation();
      openRepliesSheet(card, { toReplies: true });
    }, true);  // capture: beat the card's open-thread handler
  }

  // Tapping the BODY text of a POST expands it inline (Buck 2026-06-08: "a post
  // with a large body — tapping the text does nothing and I can't see it all").
  // forums.js already does this for topic cards, but only inside its own `feed`
  // element, so cards in other contexts (and any card it missed) didn't respond.
  // This document-delegated fallback covers ALL post bodies: it mirrors forums.js
  // (toggle feed-card--max + drive the canonical .feed-card__read-more, which
  // lazy-fetches the full body). Capture + stopPropagation so forums.js can't
  // also fire and double-toggle. Cards with no read-more (content/articles whose
  // full text lives on their own page) are left to their normal tap behavior.
  function wirePostBodyExpand() {
    if (!window.matchMedia('(max-width:640px)').matches) return;   // mobile only — desktop keeps forums.js's native expand
    if (document.body.getAttribute('data-lg-postbody')) return;
    document.body.setAttribute('data-lg-postbody', '1');
    document.addEventListener('click', function (e) {
      var ex = e.target.closest && e.target.closest('.feed-card__op-excerpt, .fc-excerpt');
      if (!ex) return;
      if (e.target.closest('.reply-stub')) return;   // replies have their own handler
      if (e.target.closest('a, button, .lg-act, .lg-card-actions, .fcr, .fcr-palette')) return;
      var card = ex.closest('.feed-card'); if (!card) return;
      var rm = card.querySelector('.feed-card__read-more:not(.lg-rm-syn)');   // native expander
      if (rm) {
        e.preventDefault(); e.stopPropagation();
        var willOpen = !card.classList.contains('feed-card--max');
        card.classList.toggle('feed-card--max', willOpen);
        var wantState = willOpen ? 'expanded' : 'collapsed';
        if (rm.dataset.state !== wantState) rm.click();  // forums.js read-more handler does the fetch + show/hide
        return;
      }
      // No native read-more: if we added a synthetic "Read more" (clamped body with
      // full text in the DOM), tapping the body toggles it too.
      var syn = card.querySelector('.lg-rm-syn');
      if (syn) { e.preventDefault(); e.stopPropagation(); syn.click(); }
    }, true);  // capture: own the tap so forums.js's feed-scoped handler can't double-fire
  }

  // ── Image lightbox: tap ANY Hub image → snappy fullscreen, one tap to close,
  // pinch-to-zoom + pan (Buck 2026-06-08). Mobile only (pinch is a touch gesture;
  // desktop keeps forums.js's native lightbox). Captures covers (incl. content
  // cards — which otherwise open comments), reply images, and body images;
  // excludes video/gated covers (play/paywall), loothprint covers (→ their sheet),
  // and avatars. Runs in CAPTURE before keepContentOnHub/forums.js. ────────────
  var lgLb = null, lgImg = null, lgS = 1, lgTx = 0, lgTy = 0, lgScrollY = '';
  function lgApplyTf() { if (lgImg) lgImg.style.transform = 'translate(-50%,-50%) translate(' + lgTx + 'px,' + lgTy + 'px) scale(' + lgS + ')'; }
  function lgEnsureLb() {
    if (lgLb) return;
    if (!document.getElementById('lg-lb-css')) {
      var st = document.createElement('style'); st.id = 'lg-lb-css';
      st.textContent =
        '#lg-lb{position:fixed;inset:0;z-index:2147483600;background:rgba(0,0,0,.93);display:none;' +
        'touch-action:none;overscroll-behavior:contain;-webkit-user-select:none;user-select:none}' +
        '#lg-lb.is-on{display:block}' +
        '#lg-lb img{position:absolute;top:50%;left:50%;max-width:100vw;max-height:100vh;' +
        'transform:translate(-50%,-50%);will-change:transform;-webkit-user-drag:none;user-select:none}' +
        '#lg-lb .lg-lb-x{position:fixed;top:calc(env(safe-area-inset-top,0px) + 10px);right:14px;z-index:2;' +
        'width:38px;height:38px;border:0;border-radius:50%;background:rgba(0,0,0,.45);color:#fff;font-size:19px;line-height:38px;cursor:pointer}';
      document.head.appendChild(st);
    }
    lgLb = document.createElement('div'); lgLb.id = 'lg-lb';
    lgLb.innerHTML = '<button class="lg-lb-x" type="button" aria-label="Close">✕</button><img alt="">';
    document.body.appendChild(lgLb);
    lgImg = lgLb.querySelector('img');
    lgLb.querySelector('.lg-lb-x').addEventListener('click', function (e) { e.stopPropagation(); lgCloseLb(); });
    wireLbGestures();
  }
  var lgHist = false;
  function lgOpenLb(url) {
    lgEnsureLb();
    lgS = 1; lgTx = 0; lgTy = 0; lgApplyTf();
    lgImg.src = url; lgLb.classList.add('is-on');
    lgScrollY = document.body.style.overflow; document.body.style.overflow = 'hidden';
    // Push a history entry so the phone's back gesture/button CLOSES the lightbox
    // instead of navigating away (which on a PWA reads as "the app closed").
    if (!lgHist) { try { history.pushState({ lgLb: 1 }, ''); lgHist = true; } catch (e) {} }
  }
  function lgCloseLb(fromPop) {
    if (!lgLb) return;
    // Stamp every close so the sheets' popstate handlers can tell "this pop
    // belongs to the lightbox" (its pushed entry) from a real back-out.
    window.__lgLbPop = Date.now();
    lgLb.classList.remove('is-on'); lgImg.removeAttribute('src');
    document.body.style.overflow = lgScrollY || '';
    // If we pushed a history entry and this close came from a tap/✕/Esc (not the
    // back gesture itself), pop our entry back off so history stays balanced.
    if (lgHist && !fromPop) { lgHist = false; try { history.back(); } catch (e) {} }
    else { lgHist = false; }
  }
  function wireLbGestures() {
    var startDist = 0, startS = 1, sox = 0, soy = 0, pinch = false;
    var panning = false, sTx = 0, sTy = 0, p0x = 0, p0y = 0, tStart = 0, moved = 0, x0 = 0, y0 = 0;
    function dist(t) { return Math.hypot(t[0].clientX - t[1].clientX, t[0].clientY - t[1].clientY); }
    function mid(t) { return { x: (t[0].clientX + t[1].clientX) / 2, y: (t[0].clientY + t[1].clientY) / 2 }; }
    lgLb.addEventListener('touchstart', function (e) {
      if (e.touches.length === 2) {
        pinch = true; panning = false; startDist = dist(e.touches); startS = lgS;
        var p = mid(e.touches), cx = window.innerWidth / 2, cy = window.innerHeight / 2;
        sox = (p.x - cx - lgTx) / lgS; soy = (p.y - cy - lgTy) / lgS; e.preventDefault();
      } else if (e.touches.length === 1) {
        tStart = Date.now(); moved = 0; x0 = e.touches[0].clientX; y0 = e.touches[0].clientY;
        if (lgS > 1) { panning = true; sTx = lgTx; sTy = lgTy; p0x = x0; p0y = y0; }
      }
    }, { passive: false });
    lgLb.addEventListener('touchmove', function (e) {
      if (pinch && e.touches.length === 2) {
        var s = Math.max(1, Math.min(6, startS * (dist(e.touches) / startDist)));
        var p = mid(e.touches), cx = window.innerWidth / 2, cy = window.innerHeight / 2;
        lgS = s; lgTx = p.x - cx - sox * s; lgTy = p.y - cy - soy * s; lgApplyTf(); e.preventDefault();
      } else if (panning && e.touches.length === 1) {
        var t = e.touches[0]; lgTx = sTx + (t.clientX - p0x); lgTy = sTy + (t.clientY - p0y);
        moved += Math.abs(t.clientX - x0) + Math.abs(t.clientY - y0); lgApplyTf(); e.preventDefault();
      } else if (e.touches.length === 1) {
        var t1 = e.touches[0]; moved = Math.abs(t1.clientX - x0) + Math.abs(t1.clientY - y0);
      }
    }, { passive: false });
    lgLb.addEventListener('touchend', function (e) {
      if (e.touches.length > 0) return;
      if (pinch) { pinch = false; if (lgS <= 1.03) { lgS = 1; lgTx = 0; lgTy = 0; lgApplyTf(); } return; }
      var quick = (Date.now() - tStart) < 300;
      if (!panning && quick && moved < 12) { lgCloseLb(); return; }   // clean tap → close
      panning = false;
    });
    lgLb.addEventListener('click', function (e) { if (e.target === lgImg || e.target === lgLb) lgCloseLb(); }); // mouse fallback
  }
  function wireImageLightbox() {
    if (!window.matchMedia('(max-width:640px)').matches) return;     // mobile only
    if (document.body.getAttribute('data-lg-imglb')) return;
    document.body.setAttribute('data-lg-imglb', '1');
    document.addEventListener('click', function (e) {
      if (e.target.closest('.fc-avatar, .avatar-init, .avi-sm, .lg-card-avatar, button, .lg-act, .lg-card-actions, .fcr, .fcr-palette, a.dir-link')) return;
      var src = null;
      var cover = e.target.closest('.feed-card__cover, .fc-cover');
      if (cover) {
        if (cover.classList.contains('fc-cover--video') || cover.classList.contains('fc-cover--gated')) return;
        var card = cover.closest('.feed-card');
        if (card && card.getAttribute('data-kind') === 'loothprint') return;   // loothprint → its sheet
        var cimg = cover.querySelector('.feed-card__cover-img, img');
        src = cimg && (cimg.currentSrc || cimg.getAttribute('src'));
      } else {
        var img = e.target.closest('.reply-stub__img, .feed-card__full-body img, .post__body img, .lg-fb-bubble img, .feed-card__op img, .lrs-op__body img');
        if (img && img.tagName === 'IMG') {
          var wrap = img.closest('a[href]');
          var href = (wrap && /\.(jpe?g|png|gif|webp|avif)(\?|#|$)/i.test(wrap.getAttribute('href') || '')) ? wrap.getAttribute('href') : null;
          src = href || img.currentSrc || img.getAttribute('src');
        }
      }
      if (!src) return;
      e.preventDefault(); e.stopPropagation();
      // img.php-resized images carry an explicit width — ask for a lightbox-
      // size rendition, sharper than the inline copy (dmodal parity, Ian 6/11).
      lgOpenLb(src.replace(/([?&]w=)\d+/, '$11600'));
    }, true);   // capture: beat keepContentOnHub + forums.js
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') lgCloseLb(); });
    // Back gesture / button → close the lightbox (our pushed entry is being popped).
    window.addEventListener('popstate', function () { if (lgLb && lgLb.classList.contains('is-on')) lgCloseLb(true); });
  }

  // ── Hub filter drawer (RETIRED 2026-06-11) ──────────────────────────────────
  // wireFilterDrawer/ensureFilterDrawerCss slid .bb-layout__nav in as a mobile
  // drawer; the hub no longer renders that aside — the canonical centered filters
  // modal (#hub-fmodal) owns the Filters chip on ALL viewports (Ian 2026-06-11).

  // ── Facebook-style reaction picker (Buck 2026-06-08: "match the format Facebook
  // uses"). Restyle the .fcr-palette popup into FB's rounded pill of big circular
  // emoji that pop/scale on press. Mobile; applies in the feed AND the replies
  // sheet (#looth-rep-sheet), so selectors aren't .feed-page-scoped. ───────────
  function ensureFbReactionsCss() {
    if (document.getElementById('lg-fbreact-css')) return;
    var s = document.createElement('style'); s.id = 'lg-fbreact-css';
    s.textContent = [
      '@media (max-width:640px){',
      // only style the palette when OPEN — forums.js hides it via [hidden]; an
      // unconditional display:flex!important kept every palette stuck open.
      '.fcr-palette[hidden]{display:none!important}',
      '.fcr-palette:not([hidden]){display:flex!important;gap:2px!important;padding:5px 8px!important;background:#fff!important;',
      'border:1px solid rgba(0,0,0,.06)!important;border-radius:999px!important;box-shadow:0 6px 22px rgba(0,0,0,.24)!important;width:max-content!important}',
      '.fcr-opt{width:44px!important;height:44px!important;padding:0!important;border:0!important;background:transparent!important;',
      'border-radius:50%!important;display:inline-flex!important;align-items:center;justify-content:center;line-height:1;',
      'font-size:30px!important;cursor:pointer;transform-origin:center bottom;transition:transform .14s cubic-bezier(.34,1.56,.64,1)!important}',
      '.fcr-opt:hover,.fcr-opt:active{background:transparent!important;transform:scale(1.45) translateY(-7px)!important}',
      '.fcr-opt .fcr-emoji{font-size:30px!important}',
      '.fcr-opt .fcr-img{width:32px!important;height:32px!important}',
      // reaction summary chips: tighter, FB-ish rounded
      '.fcr-chip{border-radius:999px!important;padding:2px 8px 2px 6px!important}',
      '.fcr-chip .fcr-emoji{font-size:16px!important}',
      // dark-mode picker surface (light emoji on a dark pill)
      'html[data-lguser-theme="dark"] .fcr-palette{background:#2a2e31!important;border-color:#3a3f3a!important}',
      'html[data-lguser-theme="dark"] .fcr-chip{background:#262b30!important;color:#e5e7e1!important}',
      // Buck 2026-06-08: show the FULL photo in the feed — no 3/2 height crop.
      // forums.css caps covers at aspect-ratio 3/2 + object-fit:cover; let them flow
      // at natural ratio so nothing is cut off (trade: some CLS as images load).
      '.feed-page .feed-card__cover-img,.feed-page .fc-cover img{aspect-ratio:auto!important;height:auto!important;max-height:none!important;object-fit:contain!important}',
      // Buck 2026-06-10: ONE save control on mobile — the Instagram bookmark
      // (.lg-act-save). Hide the canonical ☆ star (server-rendered by _feed.php
      // feed_save_btn; same /archive-api/v0/save-post store, so nothing is lost).
      // Desktop keeps the star — its .fc-actions row has no bookmark.
      '.feed-page .fc-save{display:none!important}',
      // Buck 2026-06-08: like button sits too far from the edge — pull the action
      // row closer to the screen edge (FB-style), keep the save bookmark flush right.
      '.feed-page .lg-card-actions{padding-left:7px!important;padding-right:10px!important;gap:20px!important}',
      // Instagram save bookmark — pushed to the right of the action row; fills when saved
      '.feed-page .lg-card-actions .lg-act-save{margin-left:auto}',
      '.feed-page .lg-card-actions .lg-act-save .ico{fill:none;stroke:currentColor}',
      '.feed-page .lg-card-actions .lg-act-save.is-on{color:var(--lguser-ink,#1a1d1a)}',
      '.feed-page .lg-card-actions .lg-act-save.is-on .ico{fill:currentColor;stroke:currentColor}',
      // Label the bookmark (Buck 2026-06-11: the bare icon read as MISSING on
      // phones next to the labelled Like/replies/Share). is-on flips the text.
      '.feed-page .lg-card-actions .lg-act-save::after{content:"Save";font:inherit}',
      '.feed-page .lg-card-actions .lg-act-save.is-on::after{content:"Saved"}',
      // Category icon before the label text — inherits the label color (currentColor,
      // so dark-mode-safe), optical-center nudge.
      '.feed-page .fc-category.lg-card-cat .lg-cat-ico,.feed-page .lg-card-cat .lg-cat-ico{display:inline-block;vertical-align:-2px;margin-right:5px;flex:0 0 auto;opacity:.85}',
      '.feed-page .lg-card-cat.lg-has-catico{display:inline-flex;align-items:center}',
      '}'
    ].join('');
    (document.head || document.documentElement).appendChild(s);
  }

  // ── Ian 2026-06-10: keep the author filter in the drawer, just relabel it.
  // (Placeholder lives in canonical _filter-rail.php — client relabel, zero risk;
  // a one-line canonical copy change would make this redundant.)
  function relabelAuthorFilter() {
    try {
      var ins = document.querySelectorAll('input.hub-tsearch__in[name="author"]');
      for (var i = 0; i < ins.length; i++) ins[i].placeholder = 'Search by author…';
    } catch (e) {}
  }

  // ── DARK-theme polish for the comments modal shell + the New-post composer
  // (Buck 2026-06-10). The composer overlay PINS a light palette in forums.css
  // (.ntm-overlay token block, a deliberate canonical call for the legacy hub
  // themes) — under the app's picked-Dark we re-point those same tokens dark so
  // the dialog, inputs, forum list and Quill all flip coherently. The lgc-modal
  // head/panel hardcode cream/white with no dark rules at all.
  function ensurePunchDarkCss() {
    if (document.getElementById('lg-punch-dark-css')) return;
    var D = 'html[data-lguser-theme="dark"]';
    var st = document.createElement('style'); st.id = 'lg-punch-dark-css';
    st.textContent =
      // comments modal shell (parent doc; the frame's inside is handled in
      // lgPolishCommentsFrame)
      D + ' .lgc-modal__panel{background:#1b1e21!important}' +
      D + ' .lgc-modal__head{background:#15171a!important;border-bottom-color:#2c312d!important}' +
      D + ' .lgc-modal__title{color:#f2f4ee!important}' +
      D + ' .lgc-modal__close{color:#cdd0ca!important}' +
      D + ' .lgc-modal__close:hover{background:#2c312d!important;color:#f2f4ee!important}' +
      D + ' .lgc-modal__frame{background:#1b1e21!important}' +
      // New-post composer: re-point the pinned-light tokens dark
      D + ' .ntm-overlay{--bg:#222629;--bg-card:#1b1e21;--fg:#e5e7e1;--fg-muted:#9aa097;' +
        '--fg-soft:#7e857c;--border:#333833;--lg-sage-tint:#2a341f}' +
      D + ' .ntm-overlay .ql-container.ql-snow{background:#222629!important;border-color:#333833!important;color:#e5e7e1!important}' +
      D + ' .ntm-overlay .ql-editor{color:#e5e7e1!important}' +
      D + ' .ntm-overlay .ql-editor.ql-blank::before{color:#7e857c!important}' +
      D + ' .ntm-overlay .ntm-anon__tx{color:#cdd0ca!important}' +
      D + ' .ntm-overlay input[type="checkbox"]{accent-color:#87986a}' +
      D + ' .ntm-overlay .ntm-cancel{background:#222629!important;border-color:#333833!important;color:#cdd0ca!important}' +
      D + ' .lg-fbc-head .lg-fbc-name{color:#f2f4ee!important}' +
      // mobile forum picker (hidden by default, kept consistent if shown)
      D + ' .lg-fbc #ntm-forum.ntm-forumlist,' + D + ' .lg-fbc #ntm-forum .ntm-fl__cat{background:#1b1e21!important;border-color:#333833!important}' +
      D + ' .lg-fbc #ntm-forum .ntm-fl__cat{color:#9cb37d!important}' +
      D + ' .lg-fbc #ntm-forum .ntm-fl__leaf{color:#e5e7e1!important}';
    (document.head || document.documentElement).appendChild(st);
  }

  // -- §4e discussion modal -- MOBILE styling (Buck+Ian call 2026-06-10: "copy the
  // current state of the modal over to mobile"). The fork's forums.css styles
  // #lg-dmodal only at >=641; this is the <=640 pass: same token-driven look,
  // full-screen bottom-sheet shape (matches the content sheet / replies sheet).
  // Inert until the fork's click handler is un-gated for mobile.
  function ensureDmodalMobileCss() {
    if (document.getElementById('lg-dmodal-mobile-css')) return;
    var st = document.createElement('style'); st.id = 'lg-dmodal-mobile-css';
    st.textContent =
      '@media (max-width:640px){' +
      '#lg-dmodal{position:fixed;inset:0;z-index:8800}' +
      '#lg-dmodal[hidden]{display:none}' +
      '#lg-dmodal .lg-dmodal__back{position:absolute;inset:0;background:rgba(10,12,10,.58)}' +
      '#lg-dmodal .lg-dmodal__panel{position:absolute;left:0;right:0;bottom:0;top:max(4vh,env(safe-area-inset-top,0px));' +
        'width:auto;max-width:none;max-height:none;margin:0;display:flex;flex-direction:column;overflow:hidden;' +
        'background:var(--bg-card,#fff);color:var(--fg,#1f231e);border:0;border-radius:18px 18px 0 0;' +
        'box-shadow:0 -10px 36px rgba(0,0,0,.28);animation:looth-pwa-up .26s ease}' +
      '#lg-dmodal .lg-dmodal__head{display:flex;align-items:center;gap:10px;padding:12px 14px;border-bottom:1px solid var(--border,#e3ddd0)}' +
      '#lg-dmodal .lg-dmodal__title{flex:1;min-width:0;margin:0;font:700 16px/1.25 var(--font-head,var(--lg-font-serif,Georgia,serif));' +
        'color:var(--fg,#1a1d1a);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' +
      '#lg-dmodal .lg-dmodal__size{display:none}' +
      '#lg-dmodal .lg-dmodal__x{flex:none;width:34px;height:34px;border:0;border-radius:50%;cursor:pointer;' +
        'background:var(--lg-sage-tint,#eef2e3);color:var(--lg-sage-d,#6b7c52);font:400 20px/1 var(--font-body,system-ui)}' +
      '#lg-dmodal .lg-dmodal__scroll{flex:1 1 auto;overflow-y:auto;-webkit-overflow-scrolling:touch;padding:14px 14px 28px;background:transparent;max-width:none}' +
      '#lg-dmodal .lg-dmodal__meta{display:flex;align-items:center;gap:10px;margin-bottom:10px}' +
      '#lg-dmodal .lg-dmodal__meta .fc-avatar img,#lg-dmodal .lg-dmodal__meta .fc-avatar .avatar-init{width:38px;height:38px;border-radius:50%;object-fit:cover}' +
      '#lg-dmodal .lg-dmodal__meta-id{display:flex;flex-direction:column;gap:2px;min-width:0}' +
      '#lg-dmodal .lg-dmodal__meta .fc-author__name{font-weight:700;color:var(--fg,#1a1d1a)}' +
      '#lg-dmodal .lg-dmodal__meta .fc-time{font-size:12.5px;color:var(--fg-muted,#6b6f6b)}' +
      '#lg-dmodal .lg-dmodal__body{font-size:15.5px;line-height:1.6;color:var(--fg,#1a1d1a);margin-bottom:4px}' +
      '#lg-dmodal .lg-dmodal__body img{max-width:100%;height:auto;border-radius:10px}' +
      '#lg-dmodal .lg-dmodal__thread{border-top:1px solid var(--border,#e3ddd0);padding-top:6px}' +
      '#lg-dmodal .lg-dmodal__note{padding:18px 4px;color:var(--fg-muted,#6b6f6b);font:14px/1.5 var(--font-body,system-ui)}' +
      '#lg-dmodal .lg-dmodal__opacts{display:flex;align-items:center;gap:16px;padding:10px 0;margin-bottom:12px;border-bottom:1px solid var(--border,#e3ddd0)}' +
      '#lg-dmodal .lg-dmodal__act,#lg-dmodal .reply-stub__reply{display:inline-flex;align-items:center;gap:5px;cursor:pointer;' +
        'background:none;border:0;padding:2px 4px;box-shadow:none;color:var(--lg-sage-d,#586b3f);font:700 13px/1 var(--font-body,system-ui)}' +
      '#lg-dmodal .lg-dmodal__acts{display:flex;align-items:center;gap:14px;padding:6px 0 0;width:100%}' +
      // [hidden] must beat .lg-dmodal__act display:inline-flex — else hidden delete/edit
      // buttons leak to non-authors on the mobile front-page modal. (Ian 6/17)
      '#lg-dmodal .lg-dmodal__act[hidden]{display:none!important}' +
      '#lg-dmodal .lg-dmodal__thread .reply-stub{padding:12px 0;margin:0;border-top:1px solid var(--border-soft,var(--border,#eee7da));background:none!important;border-radius:0}' +
      '#lg-dmodal .lg-dmodal__thread .reply-stub:first-child{border-top:0}' +
      '#lg-dmodal .lg-dmodal__thread .reply-stub--child{margin-left:34px;border-top:0;padding-top:4px}' +
      '#lg-dmodal .lg-dmodal__thread .avatar-init,#lg-dmodal .lg-dmodal__thread .reply-stub img.avatar{width:32px;height:32px;font-size:13px}' +
      '#lg-dmodal .lg-dmodal__thread .reply-stub__author{font-size:13.5px;font-weight:700}' +
      '#lg-dmodal .lg-dmodal__thread .reply-stub__excerpt,#lg-dmodal .lg-dmodal__thread .reply-stub__body{font-size:14.5px;line-height:1.55}' +
      '#lg-dmodal .lg-dmodal__thread .reply-stub__head .reply-stub__reply{display:none}' +
      '#lg-dmodal .lg-dmodal__thread .replies-loadmore{display:none}' +
      '}';
    (document.head || document.documentElement).appendChild(st);
  }

  // Phone back-gesture closes the discussion modal instead of leaving the Hub
  // (same pattern as the content sheet's lgCsHist). Mobile only.
  function wireDmodalMobileHistory() {
    if (!window.matchMedia('(max-width:640px)').matches) return;
    if (document.body.getAttribute('data-lg-dmhist')) return;
    document.body.setAttribute('data-lg-dmhist', '1');
    var hist = false, watched = null;
    function el() { return document.getElementById('lg-dmodal'); }
    function onTog() {
      var m = el(); if (!m) return;
      if (!m.hidden && !hist) { hist = true; try { history.pushState({ lgDm: 1 }, ''); } catch (e) {} }
      else if (m.hidden && hist) { hist = false; try { history.back(); } catch (e) {} }
    }
    try {
      var mo = new MutationObserver(function () {
        var m = el(); if (!m || watched === m) return;
        watched = m;
        new MutationObserver(onTog).observe(m, { attributes: true, attributeFilter: ['hidden'] });
        onTog();
      });
      mo.observe(document.body || document.documentElement, { childList: true });
    } catch (e) {}
    window.addEventListener('popstate', function () {
      var m = el();
      if (m && !m.hidden) { hist = false; var x = m.querySelector('[data-dm-close]'); if (x) x.click(); }
    });
  }

  // -- D2: edit/delete inside the modal surfaces (Buck+Ian call 2026-06-10:
  // admins edit/delete ANYTHING; users their own). The fork server-renders
  // (pencil) .reply-stub__edit + (trash) .reply-stub__trash on every reply row,
  // revealed by an ancestor .feed--can-moderate -- which forums.js sets on the
  // in-page FEED only -- and wired by feed-scoped listeners. Inside #lg-dmodal /
  // #looth-rep-sheet (body-appended) the buttons were invisible AND dead.
  // Reveal + wire them here. The PUT/DELETE endpoints re-check caps server-side
  // regardless of any client gating. Own-reply (non-moderator) reveal needs the
  // author id on the row -- server change, queued for the coordinator.
  function wireModalModeration() {
    if (document.body.getAttribute('data-lg-modmod')) return;
    document.body.setAttribute('data-lg-modmod', '1');
    lrsGetAuth(function (a) {
      if (!a || !a.authenticated) return;                  // author OR moderator (Ian 6/17)
      var viewerId = parseInt(a.wp_user_id, 10) || 0;
      var canMod   = !!a.can_edit_others;
      function mark() {
        ['lg-dmodal', 'looth-rep-sheet'].forEach(function (id) {
          var el = document.getElementById(id);
          if (!el) return;
          if (canMod) {
            // Moderator/admin: reveal controls across the whole modal (unchanged).
            if (!el.classList.contains('feed--can-moderate')) el.classList.add('feed--can-moderate');
          } else if (viewerId) {
            // Author (non-mod): reveal edit/trash only on their OWN reply rows
            // (data-author-id is now on every .reply-stub; server re-checks caps).
            el.querySelectorAll('.reply-stub[data-author-id]').forEach(function (stub) {
              if ((parseInt(stub.getAttribute('data-author-id'), 10) || 0) === viewerId
                  && !stub.classList.contains('feed--can-moderate')) {
                stub.classList.add('feed--can-moderate');
              }
            });
          }
        });
      }
      mark();
      // Watch the SUBTREE (throttled) — the mobile sheet (#looth-rep-sheet) and the
      // desktop modal load their reply threads DEEP inside themselves, so a
      // body-only (non-subtree) observer never fired for new stubs and own-reply
      // edit/trash stayed hidden on mobile (Ian 2026-06-25).
      var modPend = false;
      try {
        new MutationObserver(function () {
          if (modPend) return; modPend = true;
          requestAnimationFrame(function () { modPend = false; mark(); });
        }).observe(document.body, { childList: true, subtree: true });
      } catch (e) {}
    });
    // ── Edit/Delete via a single "Edit" button → popup (Ian 2026-06-25, mobile
    //    parity with desktop). Edit opens the mobile reply composer
    //    (openComposerSheet) PRE-FILLED — text + add/remove real-attachment photos,
    //    owned PUT (no orphans). Delete → owned endpoint. The raw pencil/trash stay
    //    hidden in the DOM only for their data-reply-id / data-reply-raw. ──
    var lgFbMenuEl = null, lgFbMenuStub = null;
    function lgFbCloseMenus() { if (lgFbMenuEl) { try { lgFbMenuEl.remove(); } catch (e) {} lgFbMenuEl = null; lgFbMenuStub = null; } }
    function lgFbReplyMeta(stub) {
      var edEl = stub.querySelector('.reply-stub__edit');
      var rid  = parseInt((edEl && edEl.getAttribute('data-reply-id')) || stub.getAttribute('data-reply-id') || '0', 10) || 0;
      var raw  = (edEl && edEl.getAttribute('data-reply-raw')) || '';
      var ex   = stub.querySelector('.reply-stub__excerpt');
      var text = raw
        ? raw.replace(/<img[^>]*>/gi, '').replace(/<br\s*\/?>/gi, '\n').replace(/<\/p>\s*<p[^>]*>/gi, '\n\n').replace(/<[^>]+>/g, '').trim()
        : (ex ? (ex.innerText || ex.textContent || '').trim() : '');
      return { rid: rid, text: text };
    }
    function lgFbToggleMenu(btn) {
      var stub = btn.closest('.reply-stub');
      if (lgFbMenuEl && lgFbMenuStub === stub) { lgFbCloseMenus(); return; }
      lgFbCloseMenus();
      lgFbMenuStub = stub;
      var m = document.createElement('div');
      m.className = 'lg-fb-menu';
      m.innerHTML =
        '<button type="button" class="lg-fb-menu__item lg-fb-menu__edit">✎ Edit</button>' +
        '<button type="button" class="lg-fb-menu__item lg-fb-menu__del">🗑 Delete</button>';
      document.body.appendChild(m);
      lgFbMenuEl = m;
      var r = btn.getBoundingClientRect();
      var mw = m.offsetWidth || 168;
      m.style.top  = (r.bottom + 6) + 'px';
      m.style.left = Math.max(8, Math.min(r.left, window.innerWidth - mw - 8)) + 'px';
    }
    document.addEventListener('click', function (ev) {
      if (!ev.target.closest) return;
      var moreBtn = ev.target.closest('.lg-fb-more');
      if (moreBtn) { ev.preventDefault(); ev.stopPropagation(); lgFbToggleMenu(moreBtn); return; }
      var editItem = ev.target.closest('.lg-fb-menu__edit');
      var delItem  = ev.target.closest('.lg-fb-menu__del');
      if (!editItem && !delItem) { if (lgFbMenuEl) lgFbCloseMenus(); return; }
      ev.preventDefault(); ev.stopPropagation();
      var stub = lgFbMenuStub; lgFbCloseMenus();
      if (!stub) return;
      var meta = lgFbReplyMeta(stub);
      if (!meta.rid) return;
      var sheet = stub.closest('#looth-rep-sheet');
      if (editItem) {
        if (typeof openComposerSheet === 'function') {
          openComposerSheet({
            tid: sheet ? sheet.getAttribute('data-tid') : '',
            fid: sheet ? sheet.getAttribute('data-fid') : '',
            editReplyId: meta.rid, bodyText: meta.text, focus: true
          });
        }
        return;
      }
      if (!window.confirm('Delete this reply? This can’t be undone.')) return;
      lrsGetAuth(function (a) {
        if (!a || !a.nonce) { alert('Not signed in.'); return; }
        fetch('/bb-mirror-api/v0/reply', { method: 'DELETE', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': a.nonce }, body: JSON.stringify({ reply_id: meta.rid }) })
          .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }, function () { return { ok: r.ok, j: {} }; }); })
          .then(function (res) {
            if (res.j && res.j.error === 'forbidden') { alert('You can only delete your own replies.'); return; }
            if (!res.ok) { alert('Could not delete: ' + ((res.j && (res.j.message || res.j.error)) || 'failed')); return; }
            try { stub.remove(); } catch (e) {}
          })
          .catch(function (err) { alert('Network error: ' + err.message); });
      });
    });
  }

  // ── New-post composer un-wedge (Vanessa 2026-06-11) ─────────────────────────
  // The fork's ntm overlay caches its auth state: ONE transient auth.php failure
  // (FPM hiccup) leaves it wedged on "Loading…"/"Sign in" for the whole page
  // session even though you ARE signed in — reopening never retries. Real fix =
  // retry-on-open in the fork (msg'd to fable). Stopgap: when the overlay opens
  // into anon/loading but auth.php says authenticated, do one guarded reload
  // (fresh closure → clean auth) and auto-reopen the composer.
  function wireComposerUnwedge() {
    if (document.body.getAttribute('data-lg-unwedge')) return;
    document.body.setAttribute('data-lg-unwedge', '1');
    document.addEventListener('click', function (e) {
      if (!e.target.closest) return;
      if (!e.target.closest('.forum-header__new-post, .lg-newpost, [data-ntm-open]')) return;
      setTimeout(function () {
        try {
          var ov = document.getElementById('ntm-overlay');
          if (!ov || ov.hidden) return;
          var anon = document.getElementById('ntm-anon'), loading = document.getElementById('ntm-loading');
          var wedged = (anon && !anon.hidden) || (loading && !loading.hidden);
          if (!wedged) return;
          fetch('/bb-mirror-api/v0/auth.php', { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
              if (!d || !d.authenticated) return;             // genuinely signed out — leave the prompt
              var last = 0;
              try { last = parseInt(sessionStorage.getItem('lgNtmKick'), 10) || 0; } catch (e2) {}
              if (Date.now() - last < 60000) return;          // at most one auto-recover a minute
              try {
                sessionStorage.setItem('lgNtmKick', String(Date.now()));
                sessionStorage.setItem('lgNtmReopen', '1');
              } catch (e3) {}
              location.reload();
            })
            .catch(function () {});
        } catch (err) {}
      }, 2500);                                               // give the fork's own fetch a fair chance
    }, true);
    // after a recovery reload, reopen the composer so the user keeps their flow
    try {
      if (sessionStorage.getItem('lgNtmReopen') === '1') {
        sessionStorage.removeItem('lgNtmReopen');
        setTimeout(function () {
          var b = document.querySelector('.forum-header__new-post, [data-ntm-open]');
          if (b) b.click();
        }, 1200);
      }
    } catch (e4) {}
  }

  function run() {
    if (!onHubPath()) return;
    if (!document.querySelector('.feed-page')) return; // listing pages only
    ensureFbReactionsCss();
    wireImageLightbox();
    ensureImmersiveCss();
    ensureDesktopCss();
    wireLoothprintTap();
    wireReplyBodyExpand();
    wirePostBodyExpand();
    wireFastFilters();
    reopenFiltersIfFlagged();
    wireVideoAutoStop();
    wireVideoLinkCards();
    wireVideoAutoplay();
    wireDesktopVideoHover();
    enforceSingleVideo();
    wireExpandLoadAll();
    addTagline();
    relocateFilterToggle();
    restyleSortBar();
    ensureSavedPill();
    setTimeout(ensureSavedPill, 1500); setTimeout(ensureSavedPill, 4000);
    lgSyncSaved();
    relabelAuthorFilter();
    ensurePunchDarkCss();
    ensureDmodalMobileCss();
    wireDmodalMobileHistory();
    wireModalModeration();
    wireFreshPill();
    buildTopSearch();
    wireHeaderAutoHide();
    keepContentOnHub();
    wireComposerUnwedge();
    lgPolishCommentsFrame();
    ensureReplyNameWrapCss();
    relayCards(document);
    // Server-rendered cards carry data-lg-card, so relayCards skips them (forums.js
    // owns their expand/read-more). Their action bar is hub-polish-exclusive and
    // ships server-rendered now, so wire it here on first paint (no rebuild = no flash).
    var svrCards = document.querySelectorAll('.feed-card[data-lg-card]');
    for (var ci = 0; ci < svrCards.length; ci++) buildActions(svrCards[ci]);
    watchCards();
    hideEmptyFilterRows();
    applyFreshFeed();
    watchReplyImages();
    fbStyleComposer();
    // Transform the composer when it's opened (in case it mounts lazily).
    document.addEventListener('click', function (e) {
      if (e.target.closest && e.target.closest('.forum-header__new-post,.lg-newpost,[data-ntm-open]')) {
        setTimeout(fbStyleComposer, 0);
      }
    }, true);
    // The pre-paint bootstrap added html.lg-feed-booting (feed held at opacity:0)
    // so the canonical single-column → mosaic relayout above never shows as a snap.
    // Now that the first relayCards pass is done, fade the arranged feed in. The
    // bootstrap also clears this class on a safety timeout, so the feed can never
    // get stuck hidden if this code path ever fails to run.
    revealFeed();
  }

  // Drop the boot-hold class on the next frame so the opacity transition (defined
  // in the injected <head> <style>) animates the already-arranged feed in.
  function revealFeed() {
    var de = document.documentElement;
    if (!de.classList.contains('lg-feed-booting')) return;
    requestAnimationFrame(function () { de.classList.remove('lg-feed-booting'); });
  }

  function start() {
    if (!onHubPath()) return;
    if (document.body) run();
    else document.addEventListener('DOMContentLoaded', run);
  }
  start();
})();

/* ── Fullscreen column pin (Buck 2026-06-11, desktop fullscreen warp-back) ──
 * Entering video fullscreen resizes the viewport to MONITOR size; the
 * canonical mosaic re-bucketer (forums.js) sees the CSS column-count band
 * change and physically moves cards between column wrappers — which reloads
 * the fullscreen player iframe and kicks the user straight back out. While a
 * fullscreen is active we pin .feed{column-count} (the SIGNAL colCount()
 * reads — the bucketed feed is display:flex, so this changes no visual) to
 * its pre-fullscreen value, making want===have a no-op. On exit the pin
 * lifts and a synthetic resize lets the canonical handler heal the layout
 * at the real window size. */
(function () {
  function fsEl() { return document.fullscreenElement || document.webkitFullscreenElement; }
  function onChange() {
    var pin = document.getElementById("lg-fs-colpin");
    if (fsEl()) {
      if (pin) return;
      var feed = document.querySelector(".feed-page .feed");
      if (!feed) return;
      var n = parseInt(getComputedStyle(feed).columnCount, 10);
      if (!(n >= 1 && n <= 8)) return;
      var st = document.createElement("style");
      st.id = "lg-fs-colpin";
      st.textContent = ".feed-page .feed{column-count:" + n + " !important}";
      document.head.appendChild(st);
    } else if (pin) {
      pin.parentNode.removeChild(pin);
      setTimeout(function () { try { window.dispatchEvent(new Event("resize")); } catch (e) {} }, 120);
    }
  }
  document.addEventListener("fullscreenchange", onChange, false);
  document.addEventListener("webkitfullscreenchange", onChange, false);
})();

/* ── No pinch / double-tap zoom on the mobile Hub (Buck, 2026-06-11) ──
   The Hub is an app surface; page zoom makes it feel like a web page.
   Mobile-only (<=640), desktop untouched. Three layers because engines
   differ in what they honor:
   - viewport meta maximum-scale=1 + user-scalable=no (Android Chrome / TWA)
   - touch-action: pan-x pan-y on html/body — blocks pinch AND double-tap
     zoom in Chromium while leaving panning alone, so feed scroll and the
     pull-up-sheet drag handlers are unaffected
   - gesturestart/gesturechange preventDefault (iOS Safari ignores
     user-scalable=no since iOS 10; this is the only lever it respects) */
(function () {
  if (!window.matchMedia('(max-width:640px)').matches) return;
  var m = document.querySelector('meta[name="viewport"]');
  if (!m) {
    m = document.createElement('meta');
    m.setAttribute('name', 'viewport');
    document.head.appendChild(m);
  }
  m.setAttribute('content', 'width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no');
  var st = document.createElement('style');
  st.id = 'lg-hub-nozoom';
  st.textContent = 'html,body{touch-action:pan-x pan-y}';
  document.head.appendChild(st);
  ['gesturestart', 'gesturechange'].forEach(function (ev) {
    document.addEventListener(ev, function (e) { e.preventDefault(); }, { passive: false });
  });
  // Last-resort belt: kill any 2-finger move at the event level. Catches engines
  // that ignore the meta/touch-action levers (Samsung Internet's force-zoom
  // default, iOS Safari). One-finger scroll + sheet drags are untouched.
  document.addEventListener('touchmove', function (e) {
    if (e.touches.length > 1) e.preventDefault();
  }, { passive: false });
})();
