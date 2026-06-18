/*
 * fp-discuss.js — front-page discussion modal (front-page-discussion-modal lane,
 * Ian 2026-06-14).
 *
 * A click on an "Active discussions" card (.dcard) opens a centered modal with
 * the SAME read view + composer as the Hub's own discussion modal (forums.js
 * §4e) — read the OP + threaded replies AND post a reply without leaving the
 * front page. Progressive enhancement: the card stays a real <a href="/hub/…/">,
 * so middle-click / no-JS / cmd-click fall through to the full Hub page.
 *
 * Read  : GET /bb-mirror-api/v0/topic?forum=&topic=  (slug→id + server-side
 *         visibility masks; the OP meta+body) then /hub/?replies=<id> drained
 *         to the whole thread — the same proven endpoints the Hub modal uses.
 * Reply : the canonical frm composer in /hub/forums.js (§4b, delegated on
 *         [data-frm-open]) + /bb-mirror-api/v0/auth nonce + /bb-mirror-api/v0/reply.
 *         Gated on the WP login COOKIE server-side (anon → "Sign in to reply").
 *
 * Composer assets (forums.css/js + Quill + the #frm-overlay markup) load ON
 * INTENT — only on the first card click — never eagerly for anon (CRAFT-STANDARD).
 */
(function () {
  'use strict';

  var TOPIC_API = '/bb-mirror-api/v0/topic';
  var HUB = '/hub';
  var assetsP = null;
  var modal = null;

  // ── lazy asset loaders ─────────────────────────────────────────────────────
  function loadCss(href) {
    return new Promise(function (res) {
      if (document.querySelector('link[data-fpd="' + href + '"]')) return res();
      var l = document.createElement('link');
      l.rel = 'stylesheet'; l.href = href; l.setAttribute('data-fpd', href);
      l.onload = res; l.onerror = res;        // resolve either way; CSS is best-effort
      document.head.appendChild(l);
    });
  }
  function loadJs(src) {
    return new Promise(function (res, rej) {
      if (document.querySelector('script[data-fpd="' + src + '"]')) return res();
      var s = document.createElement('script');
      s.src = src; s.setAttribute('data-fpd', src);
      s.onload = res; s.onerror = rej;
      document.head.appendChild(s);
    });
  }

  // The frm composer overlay markup (mirrors bb-mirror _chrome.php §4b). forums.js
  // wires #frm-overlay at script-exec time, so this MUST be in the DOM before
  // forums.js loads. data-rest-base drives the BB-REST reply target; env-derived
  // from the current origin so dev + live both work.
  function injectFrmMarkup() {
    if (document.getElementById('frm-overlay')) return;
    var restBase = location.origin + '/wp-json/buddyboss/v1';
    var login = '/wp-login.php?redirect_to=' + encodeURIComponent(location.pathname);
    var wrap = document.createElement('div');
    wrap.innerHTML =
      '<div class="ntm-overlay" id="frm-overlay" hidden role="dialog" aria-modal="true" aria-labelledby="frm-heading">' +
        '<div class="ntm-backdrop" id="frm-backdrop"></div>' +
        '<div class="ntm-dialog">' +
          '<h2 class="ntm-heading" id="frm-heading">Reply</h2>' +
          '<p class="frm-context" id="frm-context" hidden>Replying to <span class="frm-context__title"></span></p>' +
          '<div class="ntm-state ntm-state--loading" id="frm-loading" hidden>Loading…</div>' +
          '<div class="ntm-state ntm-state--anon" id="frm-anon" hidden>' +
            '<p class="ntm-anon__msg">Sign in to reply.</p>' +
            '<a class="ntm-anon__link" href="' + login + '">Sign in</a>' +
          '</div>' +
          '<form class="ntm-form" id="frm-form" novalidate hidden data-rest-base="' + restBase + '">' +
            '<input type="hidden" id="frm-topic-id" name="topic_id" value="">' +
            '<input type="hidden" id="frm-forum-id" name="forum_id" value="">' +
            '<label class="ntm-label">Your reply <span class="ntm-label__opt">(formatting, images &amp; links)</span></label>' +
            '<div class="ntm-editor" id="frm-editor"></div>' +
            '<textarea class="ntm-textarea ntm-textarea--fallback" id="frm-content" name="content" rows="5" placeholder="Share your thoughts…" hidden></textarea>' +
            '<p class="ntm-paste-hint">Tip: paste a YouTube, Vimeo, or Instagram link on its own line to embed it.</p>' +
            '<div class="ntm-row">' +
              '<button type="submit" class="ntm-submit" id="frm-submit">Post reply</button>' +
              '<button type="button" class="ntm-cancel" id="frm-cancel">Cancel</button>' +
              '<span class="ntm-status" id="frm-status" aria-live="polite"></span>' +
            '</div>' +
          '</form>' +
        '</div>' +
      '</div>';
    while (wrap.firstChild) document.body.appendChild(wrap.firstChild);
  }

  function v(key) { var m = window.__FPD_V__ || {}; return m[key] ? ('?v=' + m[key]) : ''; }

  function ensureAssets() {
    if (assetsP) return assetsP;
    window.LG_FORUM_BASE = HUB;     // forums.js FORUM_BASE → /hub (replies / load-more)
    injectFrmMarkup();              // before forums.js executes
    // forums.css MUST come before fp-discuss.css so the neutralizer + mobile
    // modal rules win the cascade (same-specificity, later wins).
    assetsP = Promise.all([
      loadCss(HUB + '/forums.css' + v('forumsCss')),
      loadCss('/archive-poc/fp-discuss.css' + v('css')),   // neutralizes forums.css globals + mobile modal
      loadCss('https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css')
    ]).then(function () {
      // Quill is optional — the composer falls back to a textarea if it 404s.
      return loadJs('https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.min.js').catch(function () {});
    }).then(function () {
      return loadJs(HUB + '/forums.js' + v('forumsJs'));   // wires §4b composer + §2e/§4d/§10 (delegated)
    }).catch(function () {});
    return assetsP;
  }

  // ── modal shell (reuses #lg-dmodal so forums.css + the §10 lightbox apply) ──
  function ensureModal() {
    if (modal) return modal;
    modal = document.createElement('div');
    modal.id = 'lg-dmodal';
    modal.hidden = true;
    modal.innerHTML =
      '<div class="lg-dmodal__back" data-dm-close></div>' +
      '<div class="lg-dmodal__panel lg-dmodal--m" role="dialog" aria-modal="true" aria-label="Discussion">' +
        '<header class="lg-dmodal__head">' +
          '<h2 class="lg-dmodal__title"></h2>' +
          '<button type="button" class="lg-dmodal__x" data-dm-close aria-label="Close">&times;</button>' +
        '</header>' +
        '<div class="lg-dmodal__scroll feed-page">' +
          '<div class="lg-dmodal__op"></div>' +
          '<div class="lg-dmodal__replies"></div>' +
        '</div>' +
      '</div>';
    document.body.appendChild(modal);
    modal.addEventListener('click', function (e) {
      if (e.target.closest('[data-dm-close]')) closeModal();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && modal && !modal.hidden) closeModal();
    });
    return modal;
  }
  function closeModal() {
    if (!modal) return;
    modal.hidden = true;
    document.body.style.overflow = '';
  }

  // In the Hub modal the per-reply Reply buttons get topic/forum ids stamped by
  // §4e's fbRows (no .feed-card ancestor for frmOpen to source them from). Same
  // here — stamp them so the canonical composer knows where the reply lands.
  function stampReplyBtns(container, tid, fid) {
    [].forEach.call(container.querySelectorAll('.reply-stub__reply'), function (b) {
      b.setAttribute('data-topic-id', tid);
      if (fid) b.setAttribute('data-forum-id', fid);
    });
  }

  // /hub/?replies= pages 5 at a time (its .replies-loadmore carries the next
  // offset). Drain to the whole thread — same walk the Hub modal does.
  function drain(t, tid, fid, depth) {
    if (depth > 20) return;
    var btn = t.querySelector('.replies-loadmore');
    if (!btn) return;
    var off = btn.getAttribute('data-offset') || '';
    var srt = btn.getAttribute('data-sort') || '';
    btn.remove();
    fetch(HUB + '/?replies=' + encodeURIComponent(tid) + '&offset=' + encodeURIComponent(off) + (srt ? '&sort=' + encodeURIComponent(srt) : ''), { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.text() : ''; })
      .then(function (html) {
        if (!html) return;
        var tmp = document.createElement('div');
        tmp.innerHTML = html;
        while (tmp.firstChild) t.appendChild(tmp.firstChild);
        stampReplyBtns(t, tid, fid);
        if (window.bbProcessEmbeds) window.bbProcessEmbeds(t);
        drain(t, tid, fid, depth + 1);
      })
      .catch(function () {});
  }

  function loadReplies(tid) {
    if (!modal) return;
    var rep = modal.querySelector('.lg-dmodal__replies');
    var opEl = modal.querySelector('.lg-fpd-op');
    var fid = opEl ? (opEl.getAttribute('data-forum-id') || '') : '';
    rep.innerHTML = '<div class="lg-dmodal__note">Loading replies…</div>';
    fetch(HUB + '/?replies=' + encodeURIComponent(tid), { credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw 0; return r.text(); })
      .then(function (html) {
        rep.innerHTML = '<div class="feed-card__replies-full lg-rshow lg-dmodal__thread"></div>';
        var t = rep.firstChild;
        t.innerHTML = html;
        if (!t.querySelector('.reply-stub')) {
          rep.innerHTML = '<div class="lg-dmodal__note">No replies yet. Be the first to reply.</div>';
          return;
        }
        stampReplyBtns(t, tid, fid);
        if (window.bbProcessEmbeds) window.bbProcessEmbeds(t);
        drain(t, tid, fid, 0);
      })
      .catch(function () {
        rep.innerHTML = '<div class="lg-dmodal__note">Couldn’t load replies right now.</div>';
      });
  }

  function open(card) {
    var forum = card.getAttribute('data-forum');
    var topic = card.getAttribute('data-topic');
    var tid = card.getAttribute('data-topic-id') || '';
    if (!forum || !topic) return false;   // not modal-able → let the <a> navigate

    var m = ensureModal();
    var titleEl = card.querySelector('.dcard__title, .lg-hubt__t');
    m.querySelector('.lg-dmodal__title').textContent = titleEl ? titleEl.textContent.trim() : 'Discussion';
    var op = m.querySelector('.lg-dmodal__op');
    var rep = m.querySelector('.lg-dmodal__replies');
    op.innerHTML = '<div class="lg-dmodal__note">Loading…</div>';
    rep.innerHTML = '';
    m.dataset.topicId = tid;
    m.hidden = false;
    document.body.style.overflow = 'hidden';

    ensureAssets();   // kick off composer assets while the reader reads

    fetch(TOPIC_API + '?forum=' + encodeURIComponent(forum) + '&topic=' + encodeURIComponent(topic), { credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error('http ' + r.status); return r.text(); })
      .then(function (html) {
        op.innerHTML = html;
        var opEl = op.querySelector('.lg-fpd-op');
        if (opEl) {
          var t = opEl.getAttribute('data-topic-id'); if (t) { m.dataset.topicId = t; tid = t; }
          var ttl = opEl.getAttribute('data-title'); if (ttl) m.querySelector('.lg-dmodal__title').textContent = ttl;
        }
        if (window.bbProcessEmbeds) window.bbProcessEmbeds(op);
        loadReplies(tid);
      })
      .catch(function () {
        op.innerHTML = '<div class="lg-dmodal__note">Couldn’t load this discussion. ' +
          '<a href="' + (card.getAttribute('href') || '#') + '">Open it in the Hub →</a></div>';
      });
    return true;
  }

  // The canonical composer announces successful posts (forums.js §4b dispatches
  // lg:reply-posted — the Hub modal's refresh hook, "for surfaces with no
  // .feed-card ancestor"). Refresh the thread in place when we're open on it.
  document.addEventListener('lg:reply-posted', function (e) {
    if (!modal || modal.hidden) return;
    var tid = modal.dataset.topicId || '';
    var posted = e.detail && e.detail.topicId;
    if (tid && posted && String(posted) === tid) loadReplies(tid);
  });

  // Intercept primary clicks on a modal-able discussion card. Middle-click and
  // modified clicks fall through to the card's real /hub/ href (fallback).
  document.addEventListener('click', function (e) {
    var card = e.target.closest('.dcard[data-topic], .lg-hubt__card[data-topic]');
    if (!card) return;
    if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    if (open(card)) e.preventDefault();
  });
})();
