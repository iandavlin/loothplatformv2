/* notif-reply.js — tap a notification, get a reply modal.
 *
 * Ian, 2026-07-30 (layout A, picked from two drawn options): tapping a notification
 * DEFAULTS to opening a reply modal. The modal shows the reply that generated the
 * notification, plus a composer, plus a link to the full post. There is deliberately
 * NO second action on the notification row — the row is one tap, and the full-post
 * link lives inside the modal. Layout B (which also rendered the member's own post
 * above the reply) was NOT chosen: "do not render the member's own post for context".
 *
 * WHAT THIS FILE IS NOT: a composer, and a reply write path. Both already exist.
 *   - the composer is hub-polish.js's openComposerSheet, reached through its own
 *     published seam window.lgOpenComposer({tid, fid, replyTo, replyToName});
 *   - submit goes wherever that composer already sends it (POST
 *     /bb-mirror-api/v0/reply). This file never posts anything.
 * All this file does is: read the notification's link, fetch the quote of the reply
 * it points at, and hand both to the composer. It is the join, not the parts.
 *
 * WHY THE QUOTE GOES INSIDE THE COMPOSER rather than into a second stacked sheet:
 * Ian's ask is ONE surface — their reply, then the box you answer in. The composer is
 * already an LgSheets-managed sheet with back/ESC handling, dark theme, media, and
 * per-topic drafts; a second sheet over it would fight all of that and would give the
 * phone two things to dismiss. hub-polish.js carries a single hidden slot (#lgc-quote)
 * that only this file ever writes.
 *
 * DRAFTS ARE THE MEMBER'S OWN, PER TOPIC (Ian: "Keep drafts per topic"). This file
 * passes NO body text, so the composer's reply mode does what it already does:
 * restores lgcDrafts[tid] — the member's unfinished reply for THAT discussion — or
 * opens blank. It never seeds the composer with the quoted reply's words, which is
 * the prefill bug edit-post-parity fixed the same day.
 *
 * FLAG: body[data-lg-notifreply="1"], written by bb-mirror/web/_chrome.php from
 * LG_NOTIF_QUICKREPLY_ENABLED (default OFF). pwa.js does not even request this file
 * when the attribute is absent, and the check below is the second lock: absent reads
 * as OFF, so a stale cached shell degrades to "feature off", never to half-wired.
 */
(function () {
  'use strict';

  /** The ONE read of the gate in this file. Absent attribute → OFF (fail closed). */
  function lgNqrEnabled() {
    var b = document.body;
    return !!(b && b.getAttribute('data-lg-notifreply') === '1');
  }
  if (!lgNqrEnabled()) return;

  /* ── styles ───────────────────────────────────────────────────────────────────
     Scoped to #looth-comp-sheet .lgc-quote so nothing here can reach the rest of the
     composer, let alone the page. Dark matches BOTH signals the pre-paint boot script
     stamps (data-lguser-theme AND data-lguser-dark) — hub-polish's sign-in sheet does
     the same, and matching only one is how a panel renders white on a dark page.
     Every dark value is an EXPLICIT PIN, not a token: --lg-sage-tint and friends do
     not re-point for dark on /hub, which is the defect a previous lane shipped. */
  var D1 = 'html[data-lguser-theme="dark"]';
  var D2 = 'html[data-lguser-dark="1"]';
  function dk(sel) { return D1 + ' ' + sel + ',' + D2 + ' ' + sel; }

  var css = [
    '#looth-comp-sheet .lgc-quote{margin:10px 14px 0;padding:0}',
    '#looth-comp-sheet .lgc-quote[hidden]{display:none}',
    // the server fragment
    // the discussion title — the modal must say WHICH thread it is about
    '#looth-comp-sheet .lg-nqr-quote__where{margin:0 0 7px;font:700 11px/1.35 var(--lg-font-sans,system-ui,sans-serif);' +
      'letter-spacing:.04em;text-transform:uppercase;color:var(--lg-mute,#6b6f6b);' +
      'overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}',
    '#looth-comp-sheet .lg-nqr-quote__q{border-left:3px solid var(--lg-sage,#87986a);padding:1px 0 1px 11px}',
    '#looth-comp-sheet .lg-nqr-quote__head{display:flex;align-items:center;gap:7px;margin:0 0 4px;min-width:0}',
    '#looth-comp-sheet .lg-nqr-quote__avi{flex:0 0 auto;display:inline-flex}',
    '#looth-comp-sheet .lg-nqr-quote__avi img,#looth-comp-sheet .lg-nqr-quote__avi .avatar-init{' +
      'width:22px;height:22px;border-radius:50%;object-fit:cover;font-size:10px}',
    '#looth-comp-sheet .lg-nqr-quote__who{font:700 12.5px/1.3 var(--lg-font-sans,system-ui,sans-serif);' +
      'color:var(--lg-ink,#323532);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0}',
    '#looth-comp-sheet .lg-nqr-quote__time{flex:0 0 auto;font:400 11px/1 var(--lg-font-sans,system-ui,sans-serif);' +
      'color:var(--lg-mute,#6b6f6b)}',
    // 4-line clamp; .is-open removes it (see the Show-more note below)
    '#looth-comp-sheet .lg-nqr-quote__body{font:400 13.5px/1.55 var(--lg-font-sans,system-ui,sans-serif);' +
      'color:var(--lg-ink,#323532);overflow:hidden;display:-webkit-box;-webkit-line-clamp:4;-webkit-box-orient:vertical;' +
      'overflow-wrap:anywhere}',
    '#looth-comp-sheet .lg-nqr-quote.is-open .lg-nqr-quote__body{display:block;-webkit-line-clamp:unset;max-height:34vh;overflow:auto}',
    '#looth-comp-sheet .lg-nqr-quote__body p{margin:0 0 6px}',
    '#looth-comp-sheet .lg-nqr-quote__body p:last-child{margin-bottom:0}',
    '#looth-comp-sheet .lg-nqr-quote__body img{max-width:100%;height:auto;border-radius:8px}',
    '#looth-comp-sheet .lg-nqr-quote__body a{color:var(--lg-sage-d,#6b7c52)}',
    '#looth-comp-sheet .lg-nqr-quote__empty{color:var(--lg-mute,#6b6f6b)}',
    // show-more toggle (only rendered when the text really is clipped)
    '#looth-comp-sheet .lgc-quote__more{display:block;background:none;border:0;padding:6px 0 0;cursor:pointer;' +
      'font:600 12.5px/1 var(--lg-font-sans,system-ui,sans-serif);color:var(--lg-sage-d,#6b7c52)}',
    // "…continues in the discussion" — shown only when the SERVER truncated, so a
    // member who expands the quote is never told they are now seeing all of it.
    '#looth-comp-sheet .lgc-quote__cont{display:block;margin:5px 0 0;' +
      'font:400 11.5px/1.4 var(--lg-font-sans,system-ui,sans-serif);color:var(--lg-mute,#6b6f6b)}',
    // COALESCED ROWS. 3 of 17 reply notifications on live right now are merged,
    // one of them from four different people — a minority case but not a rare one.
    '#looth-comp-sheet .lgc-quote__multi{margin:7px 0 0;' +
      'font:600 11.5px/1.4 var(--lg-font-sans,system-ui,sans-serif);color:var(--lg-mute,#6b6f6b)}',
    // the full-post link — Ian's "link/button to the full post", INSIDE the modal
    '#looth-comp-sheet .lgc-quote__open{display:inline-flex;align-items:center;gap:6px;margin:10px 0 0;' +
      'text-decoration:none;font:700 12.5px/1 var(--lg-font-sans,system-ui,sans-serif);' +
      'color:var(--lg-sage-d,#6b7c52);background:var(--lg-sage-tint,#eef2e3);border-radius:9px;padding:9px 12px}',
    '#looth-comp-sheet .lgc-quote__open:active{background:var(--lg-line,#e3ddd0)}',
    // ≥44px touch target on phones without changing the visual size
    '@media (max-width:480px){#looth-comp-sheet .lgc-quote__open{padding:12px 13px}' +
      '#looth-comp-sheet .lgc-quote__more{padding:10px 0 4px}}',
    // ── dark: explicit pins, both signals ──
    dk('#looth-comp-sheet .lg-nqr-quote__q') + '{border-left-color:#5d6b48}',
    dk('#looth-comp-sheet .lg-nqr-quote__where') + '{color:#9aa79b}',
    dk('#looth-comp-sheet .lg-nqr-quote__who') + '{color:#f2f4ee}',
    dk('#looth-comp-sheet .lg-nqr-quote__time') + '{color:#9aa79b}',
    dk('#looth-comp-sheet .lg-nqr-quote__body') + '{color:#e5e7e1}',
    dk('#looth-comp-sheet .lg-nqr-quote__body a') + '{color:#b0c693}',
    dk('#looth-comp-sheet .lg-nqr-quote__empty') + '{color:#9aa79b}',
    dk('#looth-comp-sheet .lgc-quote__more') + '{color:#b0c693}',
    dk('#looth-comp-sheet .lgc-quote__cont') + '{color:#9aa79b}',
    dk('#looth-comp-sheet .lgc-quote__multi') + '{color:#9aa79b}',
    dk('#looth-comp-sheet .lgc-quote__open') + '{background:#243024;color:#b6c79a}',
    dk('#looth-comp-sheet .lgc-quote__open:active') + '{background:#2c352c}'
  ].join('\n');

  var st = document.createElement('style');
  st.id = 'lg-nqr-css';
  st.textContent = css;
  (document.head || document.documentElement).appendChild(st);

  /* ── the link is the only place the source reply id lives ─────────────────────
     NOT ref.anchor. notify-bridge.php stamps anchor_id = 0 for forum.reply_to_topic
     and forum.followed_topic, and for forum.reply_to_reply it is the PARENT reply
     (the member's own comment), not the reply that rang them. Only target_url carries
     the generating reply, via lg_notify_topic_url($topic_id, $reply_id) → &reply=.
     Measured on dev2 + live before this was written, not inferred from the code.

     A missing &reply= is NORMAL, not an error: an @mention in a brand-new discussion
     rings before any reply exists, and dev2 still holds a pre-anchor row (id 471,
     2026-07-13) whose link has none. Both come through here as replyId 0, and the
     server quotes the opening post instead. */
  /* Where the quote is read from. Normally the real endpoint.
     UNDER A LANE PREVIEW the branch is mounted at /preview/<lane>/hub and the flag is
     ON for that path only, so the API has to be read from the matching preview path —
     otherwise the modal would call the REAL endpoint, whose flag is off, get the plain
     OP fragment back, find no quote and quietly fall back to navigating. A preview
     that silently demonstrates nothing is worse than no preview, because it reads as
     "the feature does not work".
     Derived from the mount the app already publishes (window.LG_FORUM_BASE) rather
     than a second config, and the pattern cannot match the production mount '/hub',
     so this is provably inert off a preview. */
  function nqrApiBase() {
    var fb = (window.LG_FORUM_BASE || '').toString();
    var m = fb.match(/^(\/preview\/[A-Za-z0-9_-]+)\/hub\/?$/);
    return (m ? m[1] : '') + '/bb-mirror-api/v0';
  }

  /* "Alice and 1 other replied" — the backend coalesces a second replier into ONE
     row and re-points its link at the NEWEST reply, so the quote is the most recent of
     several. Say so. Showing one reply and silently hiding three is the kind of quiet
     wrongness that only surfaces when a member wonders where the other answers went.
     Measured on live before writing this: 3 of 17 reply rows are coalesced, one of
     them from four people. */
  function nqrMultiNote(actors) {
    var n = parseInt(actors, 10) || 1;
    if (n < 2) return '';
    return '<p class="lgc-quote__multi">Showing the most recent of ' + n + ' replies.</p>';
  }

  function nqrParse(link) {
    if (!link) return null;
    var u;
    try { u = new URL(link, location.origin); } catch (e) { return null; }
    var t = u.searchParams.get('topic') || '';         // "<forum-slug>/<topic-slug>"
    if (!t) return null;
    var ix = t.indexOf('/');
    if (ix < 1 || ix === t.length - 1) return null;
    return {
      forum:   t.slice(0, ix),
      topic:   t.slice(ix + 1),
      replyId: parseInt(u.searchParams.get('reply') || '0', 10) || 0,
      href:    u.href
    };
  }

  /* Wire the Show-more toggle once the quote is in the DOM.
     ONLY rendered when the body is genuinely clipped (scrollHeight beats
     clientHeight). A control that expands nothing is worse than no control.
     When the SERVER truncated the text (data-more="1"), expanding cannot reveal the
     rest — so that case says so in words and leaves "Open the full discussion" as the
     real affordance, rather than implying the whole reply is now on screen. */
  function nqrWireMore(slot) {
    var q = slot && slot.querySelector('.lg-nqr-quote');
    var body = q && q.querySelector('.lg-nqr-quote__body');
    if (!q || !body) return;
    if (body.scrollHeight <= body.clientHeight + 1) return;      // not clipped
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'lgc-quote__more';
    btn.textContent = 'Show more';
    btn.addEventListener('click', function () {
      q.classList.add('is-open');
      btn.remove();
      if (q.getAttribute('data-more') === '1') {
        var n = document.createElement('span');
        n.className = 'lgc-quote__cont';
        n.textContent = '…this reply continues in the discussion.';
        q.appendChild(n);
      }
    });
    body.insertAdjacentElement('afterend', btn);
  }

  /* ── the entry point the notification surfaces call ───────────────────────────
     Returns TRUE when this file has taken the intent (including the case where it
     then falls back to navigation on a failed fetch), FALSE when it is not available
     — flag off, no composer on this page, or an unparseable link. Callers MUST treat
     false as "do what you did before", never as "failed": bottom-nav.js and
     social-modals.js both render notifications on pages where hub-polish.js is not
     injected at all (pwa.js only injects it under /hub), and on those pages the
     correct behaviour is exactly today's — follow the link to the discussion, which
     is where the composer lives. */
  window.lgOpenNotifReply = function (o) {
    o = o || {};
    if (!lgNqrEnabled()) return false;
    if (typeof window.lgOpenComposer !== 'function') return false;   // no composer here
    var p = nqrParse(o.link);
    if (!p) return false;

    var url = nqrApiBase() + '/topic?forum=' + encodeURIComponent(p.forum) +
              '&topic=' + encodeURIComponent(p.topic) +
              '&reply_context=' + encodeURIComponent(String(p.replyId));

    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.text() : null; })
      .then(function (html) {
        var q = null;
        if (html) {
          try { q = new DOMParser().parseFromString(html, 'text/html').querySelector('.lg-nqr-quote'); }
          catch (e) { q = null; }
        }
        // No quote — a deleted topic, a forum that went private, the flag off on the
        // server while on in this shell, or a network failure. Do the thing the row
        // did before rather than showing an empty modal.
        if (!q) { location.href = p.href; return; }

        var tid = parseInt(q.getAttribute('data-topic-id'), 10) || 0;
        var fid = parseInt(q.getAttribute('data-forum-id'), 10) || 0;
        var rid = parseInt(q.getAttribute('data-reply-id'), 10) || 0;
        if (!tid) { location.href = p.href; return; }

        var opened = window.lgOpenComposer({
          tid: tid,
          fid: fid,
          // Reply TO the reply that rang you, so the answer threads under it. When
          // the quote fell back to the opening post (rid 0) this is a plain topic
          // reply — which is what a mention-in-a-new-discussion actually is.
          replyTo:     rid,
          replyToName: rid ? (q.getAttribute('data-author') || '') : '',
          title:       q.getAttribute('data-topic-title') || '',
          // NO bodyText — the composer's reply mode restores the MEMBER'S OWN
          // per-topic draft, never this quote's words.
          // The coalesce note rides WITH the quote so it is scrubbed by the same
          // clear on the next open — a stale "most recent of 4" over an unrelated
          // reply would be its own small lie.
          quoteHtml:   q.outerHTML + nqrMultiNote(o.actors),
          fullPostUrl: p.href,
          focus:       true
        });
        if (!opened) { location.href = p.href; return; }
        // Wire Show-more after the composer has laid the slot out.
        var sheet = document.getElementById('looth-comp-sheet');
        var slot  = sheet && sheet.querySelector('#lgc-quote');
        if (slot) setTimeout(function () { nqrWireMore(slot); }, 0);
      })
      .catch(function () { location.href = p.href; });

    return true;
  };
})();
