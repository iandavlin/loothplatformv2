/* bb-mirror/web/forums.js
 *
 * Feature areas:
 *   1. Corner hamburger: toggle left-nav open/closed on desktop + drawer on mobile.
 *   2. Feed reply-stack expand: inline reveal of older replies on click.
 *   2b. Feed full-body expand: Read more / Read less inline toggle.
 *   3. Forum pages (unread + reply form + mark-seen) — unchanged.
 */
(function () {
  'use strict';

  // Forum mount base — single source, injected by _chrome.php (window.LG_FORUM_BASE).
  // Never hardcode a path; this makes the /forums-poc → /forum flip (and any
  // future rename) a one-line config change. Fallback matches the launch base.
  var FORUM_BASE = (window.LG_FORUM_BASE || '/forum').replace(/\/+$/, '');

  // Quiet iOS Safari's AutoFill accessory bar (key/card/contact chips) on the
  // Quill rich editors. Quill builds .ql-editor as a bare contenteditable with NO
  // autocomplete attr, so the input/textarea autocomplete=off sweep never reached
  // it and the bar returned on the mobile composer body. Set it post-mount on the
  // generated editor (and re-tune correct/capitalize). Auth fields are untouched.
  function lgQuillNoAutofill(mountEl) {
    try {
      var qe = mountEl && mountEl.querySelector('.ql-editor');
      if (!qe) return;
      qe.setAttribute('autocomplete', 'off');
      qe.setAttribute('autocorrect', 'on');
      qe.setAttribute('autocapitalize', 'sentences');
      qe.setAttribute('spellcheck', 'true');
    } catch (e) {}
  }

  // Strip images out of a stored post/reply body before it's seeded into Quill.
  // bb-mirror content images are ATTACHMENTS (bbp_media), surfaced as removable
  // thumbnails in the composer tray below the editor — NOT inline <img> in the
  // body. When editing, the rendered body HTML still carries those <img> (and the
  // <figure>/<a class="attachment--image"> wrappers the mirror renders them in),
  // so pasting the raw body straight into Quill leaks the image into the editor
  // body (Ian: image shows both inside Quill AND as a thumb below). Drop the image
  // markup here so Quill receives text/formatting only; the tray owns the images.
  function lgStripBodyImages(html) {
    if (!html) return html || '';
    try {
      var tmp = document.createElement('div');
      tmp.innerHTML = html;
      // Remove attachment-image wrappers (figure / a.attachment--image) whole, then
      // any stray <img>, so no empty wrapper shell or caption frame is left behind.
      Array.prototype.forEach.call(
        tmp.querySelectorAll('figure, a.attachment--image, .attachment--image, .wp-block-image'),
        function (el) {
          if (el.querySelector && el.querySelector('img')) el.remove();
          else if (el.tagName === 'IMG') el.remove();
        }
      );
      Array.prototype.forEach.call(tmp.querySelectorAll('img'), function (img) { img.remove(); });
      return tmp.innerHTML;
    } catch (e) {
      // Fallback: regex strip (matches the legacy textarea-fallback behavior).
      return html.replace(/<img[^>]*>/gi, '');
    }
  }

  // ── Shared composer image tray (desktop ≥641 only) ───────────────────────
  // Out-of-body attachment tray = the SINGLE source of truth for bbp_media.
  // Each uploaded image becomes a thumb with a ✕ that splices its id out of the
  // composer's mediaIds array — fixing the old push-only / can't-delete bug
  // (the array used to be append-only; deleting the inline preview was cosmetic).
  // On mobile (≤640: Buck's fbStyleComposer drives ntm's hidden image button;
  // lrs/lcp sheets own replies) we keep the legacy inline-embed path byte-for-
  // byte. The gate is evaluated LIVE per upload, so a desktop window narrowed
  // past 641 also falls back cleanly. The tray DOM is created ONLY inside the
  // desktop branch, so it can never collide with the mobile composer's own
  // add-row, which is injected into this same editor.nextSibling slot.
  var LG_TRAY_MQ = (function () {
    try { return window.matchMedia('(min-width:641px)'); }
    catch (e) { return { matches: false }; }
  })();

  // opts: { editorEl, mediaIds, statusEl, restBase, getNonce(cb), insertInline(url) }
  // mediaIds MUST be mutated in place (push / splice / .length=0) and never
  // reassigned — this helper closes over that same array instance. Returns
  // { handler, reset }: wire handler as the Quill toolbar image handler; call
  // reset() wherever the composer clears (it empties both the array and tray).
  // BuddyBoss rejects webp/heic/heif/avif on media upload — catch them client-
  // side with a clear message instead of a silent server 400. Supported set
  // advertised to users (BB also allows bmp/svg, but those aren't worth listing).
  var LG_IMG_UNSUPPORTED = /\.(webp|heic|heif|avif|tiff?)$/i;
  var LG_IMG_SUPPORTED_TXT = 'JPG, PNG, or GIF';

  function lgComposerTray(opts) {
    var tray = null;
    var hintEl = null;
    // Persistent "supported types" line under the editor (desktop only — mobile's
    // composer is Buck's fbStyleComposer; it hides hint lines anyway).
    function mountHint() {
      if (hintEl || !LG_TRAY_MQ.matches || !opts.editorEl || !opts.editorEl.parentNode) return;
      hintEl = document.createElement('p');
      hintEl.className = 'lg-mtray-hint';
      hintEl.textContent = 'Supported images: JPG, PNG, GIF';
      opts.editorEl.parentNode.insertBefore(hintEl, opts.editorEl.nextSibling);
    }
    function ensureTray() {
      if (tray || !opts.editorEl || !opts.editorEl.parentNode) return tray;
      tray = document.createElement('div');
      tray.className = 'lg-mtray';
      tray.hidden = true;
      // Insert above the hint line so order reads: editor → tray → hint.
      opts.editorEl.parentNode.insertBefore(tray, hintEl || opts.editorEl.nextSibling);
      return tray;
    }
    function fail(msg) {
      opts.statusEl.textContent = msg;
      opts.statusEl.classList.add('lg-msg-error');
    }
    function syncEmpty() {
      if (tray && !tray.querySelector('.lg-mtray__item')) {
        tray.hidden = true;
        tray.classList.remove('is-uploading');
      }
    }
    // Render a removable thumb. `id` is spliced out of `list` (defaults to the new-
    // upload mediaIds) when the ✕ is tapped — pass a different list (e.g. an edit
    // "keep these existing photos" array) to track preloaded media separately.
    function addThumb(url, id, list) {
      var t = ensureTray();
      if (!t) return;
      var rmList = list || opts.mediaIds;
      t.hidden = false;
      var item = document.createElement('span');
      item.className = 'lg-mtray__item';
      var img = document.createElement('img');
      img.className = 'lg-mtray__img';
      img.src = url; img.alt = '';
      var rm = document.createElement('button');
      rm.type = 'button';
      rm.className = 'lg-mtray__rm';
      rm.setAttribute('aria-label', 'Remove image');
      rm.textContent = '✕';
      rm.addEventListener('click', function () {
        var ix = rmList.indexOf(id);
        if (ix > -1) rmList.splice(ix, 1);
        if (item.parentNode) item.parentNode.removeChild(item);
        syncEmpty();
      });
      item.appendChild(img);
      item.appendChild(rm);
      t.appendChild(item);
    }
    function reset() {
      opts.mediaIds.length = 0;
      opts.statusEl.classList.remove('lg-msg-error');
      if (tray) { tray.innerHTML = ''; tray.hidden = true; tray.classList.remove('is-uploading'); }
    }
    function handler() {
      var input = document.createElement('input');
      input.type = 'file';
      input.accept = 'image/*';
      input.onchange = function () {
        var file = input.files && input.files[0];
        if (!file) return;
        // Pre-flight: reject formats BB will refuse (webp/heic/…) up front, so
        // the user gets a clear reason instead of a silent failed upload.
        if (LG_IMG_UNSUPPORTED.test(file.name || '')) {
          var ext = (file.name.split('.').pop() || 'that').toUpperCase();
          fail(ext + ' images aren’t supported — please use ' + LG_IMG_SUPPORTED_TXT + '.');
          return;
        }
        opts.getNonce(function (nonce) {
          if (!nonce) { fail('You’re not signed in.'); return; }
          var desk = opts.forceTray || LG_TRAY_MQ.matches;
          var t = null;
          if (desk) { t = ensureTray(); if (t) { t.hidden = false; t.classList.add('is-uploading'); } }
          opts.statusEl.classList.remove('lg-msg-error');
          opts.statusEl.textContent = 'Uploading image…';
          var fd = new FormData();
          fd.append('file', file);
          fetch(opts.restBase + '/media/upload', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'X-WP-Nonce': nonce }, body: fd,
          })
            // Tolerate a non-JSON body (e.g. a 413 when the file is too large) so
            // we show a real reason rather than a JSON parse error.
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }, function () { return { ok: false, j: null }; }); })
            .then(function (res) {
              if (t) t.classList.remove('is-uploading');
              if (!res.ok || !res.j || !res.j.upload_id) {
                fail('Couldn’t add that image — supported types are ' + LG_IMG_SUPPORTED_TXT + ' (and it must not be too large).');
                syncEmpty();
                return;
              }
              opts.mediaIds.push(res.j.upload_id);
              opts.statusEl.textContent = 'Image attached.';
              var url = res.j.upload_thumb || res.j.upload;
              if (desk) addThumb(url, res.j.upload_id);
              else if (opts.insertInline) opts.insertInline(url);
            })
            .catch(function () {
              if (t) t.classList.remove('is-uploading');
              syncEmpty();
              fail('Upload failed — check your connection and try a ' + LG_IMG_SUPPORTED_TXT + ' image.');
            });
        });
      };
      input.click();
    }
    mountHint();
    return { handler: handler, reset: reset, addThumb: addThumb };
  }

  // ── Text-size toggle (pill beside Compact) ───────────────────────────────
  // 3-state cycle: Normal → Large → Larger → Normal. Scales --lg-read-scale
  // (post/reply/card body copy only). Persists per browser; aria-pressed +
  // data-level + label drive the pill's look.
  (function () {
    var KEY = 'lg_hub_read_level';
    var SCALES = [1, 1.25, 1.5];
    var LABELS = ['Text size', 'Large text', 'Larger text'];
    function level() { var n = parseInt(localStorage.getItem(KEY), 10); return (n === 1 || n === 2) ? n : 0; }
    function apply(n) {
      document.documentElement.style.setProperty('--lg-read-scale', String(SCALES[n]));
      var btn = document.querySelector('.feed-text-toggle');
      if (!btn) return;
      btn.setAttribute('aria-pressed', n > 0 ? 'true' : 'false');
      btn.setAttribute('data-level', String(n));
      var label = btn.querySelector('.feed-text-toggle__label');
      if (label) label.textContent = LABELS[n];
    }
    apply(level());
    document.addEventListener('click', function (e) {
      var btn = e.target.closest && e.target.closest('.feed-text-toggle');
      if (!btn) return;
      var n = (level() + 1) % 3;
      try { localStorage.setItem(KEY, String(n)); } catch (_) {}
      apply(n);
    });
  })();

  // ── Color theme toggle (pill beside Text size) ───────────────────────────
  // 4-state cycle: Default → Panels → Dark → Black. Toggles a class on <html>
  // (hub-theme-panel / hub-theme-dark / hub-theme-black) that re-points the
  // design tokens. Persists per browser; the before-paint script in _chrome.php
  // applies it on load so there's no flash. Mirror of the text-size cycle above.
  (function () {
    var KEY = 'lg_hub_theme';
    var CLASSES = ['', 'hub-theme-panel', 'hub-theme-dark', 'hub-theme-black'];
    var LABELS  = ['Theme', 'Panels', 'Dark', 'Black'];
    function level() { var n = parseInt(localStorage.getItem(KEY), 10); return (n >= 1 && n <= 3) ? n : 0; }
    function apply(n) {
      var de = document.documentElement;
      de.classList.remove('hub-theme-panel', 'hub-theme-dark', 'hub-theme-black');
      if (CLASSES[n]) de.classList.add(CLASSES[n]);
      var btn = document.querySelector('.feed-theme-toggle');
      if (!btn) return;
      btn.setAttribute('aria-pressed', n > 0 ? 'true' : 'false');
      btn.setAttribute('data-level', String(n));
      var label = btn.querySelector('.feed-theme-toggle__label');
      if (label) label.textContent = LABELS[n];
    }
    apply(level());
    document.addEventListener('click', function (e) {
      var btn = e.target.closest && e.target.closest('.feed-theme-toggle');
      if (!btn) return;
      var n = (level() + 1) % 4;
      try { localStorage.setItem(KEY, String(n)); } catch (_) {}
      apply(n);
    });
  })();

  // Discussion (topic) cards do NOT click through to a topic page (Ian) — the card is
  // the unit. A click on the card (incl. its title) expands the body + thread IN PLACE
  // via the card's own affordances instead of navigating. Under ?proto=cards, §1b4's
  // richer expand-in-place (with inline composer/moderation) owns this, so we defer to
  // it there. CONTENT (CPT) cards keep their click-through (handled in §1b4).
  document.addEventListener('click', function (e) {
    var card = e.target.closest('.feed-card--topic');
    if (!card) return;
    var feed = document.getElementById('hub-feed-results') || document.querySelector('.feed');
    if (feed && feed.classList.contains('feed--proto')) return;   // proto handler owns it
    // Let real controls + author/in-thread links keep working; only the title link and
    // bare card area are hijacked away from navigation.
    if (e.target.closest('button, input, textarea, select, label, [role="button"], img, ' +
          '.feed-card__read-more, .feed-card__expand, .feed-card__reply-cta, .reply-stub__reply, ' +
          '.fc-facepile, .fc-composer, ' +   // reply-count opens replies; composer is its own control
          '[data-comments], .fcr, .lg-card-actions')) return;
    var titleA = e.target.closest('.feed-card__title a');
    if (e.target.closest('a') && !titleA) return;                 // author / thread links navigate
    if (titleA) e.preventDefault();                               // title no longer navigates
    if (window.getSelection && String(window.getSelection()).length) return;  // mid-selection
    // Bare-area click expands the POST BODY only (Ian split: replies open via the
    // reply-count control). No read-more on the card = nothing more to show → do
    // nothing (no surprise reflow).
    var rm = card.querySelector('.feed-card__read-more');
    if (rm) rm.click();
  });

  // ── Image lightbox: click any forum image to view it full-size ──────────────
  // Delegated so it covers lazily-loaded thread/body images. Picks the best URL:
  // attachment link href (full-res) > a wrapping image link > the <img> src.
  (function () {
    var lb, lbImg;
    function ensure() {
      if (lb) return;
      lb = document.createElement('div');
      lb.className = 'lg-lightbox'; lb.hidden = true;
      lb.innerHTML = '<button class="lg-lightbox__close" type="button" aria-label="Close">✕</button>'
                   + '<img class="lg-lightbox__img" alt="">';
      lbImg = lb.querySelector('.lg-lightbox__img');
      document.body.appendChild(lb);
      lb.addEventListener('click', function (e) { if (e.target !== lbImg) closeLb(); });
    }
    function openLb(url) {
      if (!url) return;
      ensure();
      lbImg.src = url;
      lb.hidden = false;
      document.body.classList.add('ntm-active');   // reuse scroll-lock
      requestAnimationFrame(function () { lb.classList.add('is-open'); });
    }
    function closeLb() {
      if (!lb) return;
      lb.classList.remove('is-open');
      lb.hidden = true; lbImg.removeAttribute('src');
      document.body.classList.remove('ntm-active');
    }
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && lb && !lb.hidden) closeLb();
    });
    function imgExt(href) { return href && /\.(jpe?g|png|gif|webp|avif)(\?|#|$)/i.test(href); }

    document.addEventListener('click', function (e) {
      // DESKTOP ONLY (Ian 2026-06-26): mobile retired its custom image lightbox
      // (#lg-lb in hub-polish) in favour of the phone's NATIVE pinch-to-zoom.
      // hub-polish used to swallow these clicks in capture before this handler
      // ran; now that it no longer does, gate this native .lg-lightbox to desktop
      // so tapping an image on mobile leaves it inline (page pinch-zoom) instead
      // of opening this overlay. Desktop behaviour is unchanged.
      if (window.matchMedia('(max-width:640px)').matches) return;
      // 1) attachment-gallery image (wrapped in a.attachment--image → full-res href)
      var alink = e.target.closest('a.attachment--image');
      if (alink) { e.preventDefault(); openLb(alink.getAttribute('href')); return; }
      // 2) feed cover image: normally CLICK THROUGH to the post. EXCEPTION (Ian):
      //    forum-TOPIC cards lightbox their cover photo at ALL widths (compact or not)
      //    — the photo is a user upload, viewing it shouldn't open the thread.
      //    Video facade + gated covers keep their own behavior. CONTENT/CPT covers
      //    always navigate to the article (cursor: pointer) — never lightbox, even in
      //    compact mode. The href stays in the DOM for middle-click / open-in-new-tab
      //    / no-JS fallback.
      var cover = e.target.closest('.feed-card__cover');
      if (cover) {
        var ccard = cover.closest('.feed-card');
        var isTopic = ccard && ccard.classList.contains('feed-card--topic');
        if (isTopic && !cover.classList.contains('fc-cover--video') && !cover.classList.contains('fc-cover--gated')) {
          // Topic covers show the zoom-in cursor, so they must NEVER click through —
          // preventDefault UNCONDITIONALLY (even if the cover image hasn't loaded yet,
          // which would otherwise fall through to the <a href> and navigate, producing
          // "magnifier + click-through"). Then lightbox the best available image URL.
          e.preventDefault();
          var cimg = cover.querySelector('.feed-card__cover-img');
          var csrc = cimg && (cimg.currentSrc || cimg.getAttribute('src'));
          if (csrc) openLb(cimg.currentSrc || cimg.src);
          return;
        }
        return;   // content/CPT (or video/gated): click through to the post
      }
      // 3) bare content / reply images (deferred ones have no src yet → skip)
      var img = e.target.closest('.reply-stub__img, .post__body img, .feed-card__full-body img');
      if (img && img.tagName === 'IMG' && img.getAttribute('src')) {
        var wrap = img.closest('a[href]');
        var href = (wrap && imgExt(wrap.getAttribute('href'))) ? wrap.getAttribute('href') : (img.currentSrc || img.src);
        e.preventDefault(); openLb(href);
      }
    });
  })();

  // ── Inline video facade (content video cards) ──────────────────────────────
  // Click the thumb/play → swap a YouTube iframe IN PLACE (overlays the thumb,
  // which stays in the DOM). No iframe until click — no embed up front, fast first
  // paint (Ian). Only ONE video plays at a time; gated cards never get a play
  // host (server renders the lock overlay instead), so this can't reach them.
  (function () {
    // Inline click-to-play is DESKTOP ONLY (Ian 2026-06-17): on mobile, feed
    // videos no longer play on the card — the whole card clicks through to the
    // post (handled by hub-polish keepContentOnHub). Bail on mobile so the tap
    // navigates instead of swapping in a player.
    var lgVidMobile = function () { return window.matchMedia('(max-width:640px)').matches; };
    document.addEventListener('click', function (e) {
      if (lgVidMobile()) return;
      var host = e.target.closest && e.target.closest('.fc-cover--video[data-yt-play]');
      if (!host) return;
      e.preventDefault();
      var id = host.getAttribute('data-yt-play');
      if (!id || host.querySelector('iframe')) return;
      var prev = document.querySelector('.fc-cover--video iframe');  // one at a time
      if (prev) prev.remove();
      var iframe = document.createElement('iframe');
      iframe.className = 'fc-video';
      iframe.src = 'https://www.youtube.com/embed/' + encodeURIComponent(id) + '?autoplay=1&rel=0&modestbranding=1';
      iframe.title = 'Video';
      // explicit `fullscreen` in allow: Safari/Firefox don't fold the legacy
      // allowFullscreen attr into the permissions policy (Buck audit 6/11)
      iframe.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; fullscreen';
      iframe.allowFullscreen = true;
      iframe.referrerPolicy = 'strict-origin-when-cross-origin';
      iframe.style.cssText = 'position:absolute; inset:0; width:100%; height:100%; border:0; background:#000; z-index:5;';
      host.appendChild(iframe);
    });
    // Keyboard: Enter/Space on the focusable play host (desktop only — mobile
    // clicks through, see above).
    document.addEventListener('keydown', function (e) {
      if (lgVidMobile()) return;
      if (e.key !== 'Enter' && e.key !== ' ') return;
      var host = e.target.closest && e.target.closest('.fc-cover--video[data-yt-play]');
      if (!host) return;
      e.preventDefault();
      host.click();
    });
  })();

  // ── 1. Corner hamburger ──────────────────────────────────────────────────
  // Desktop: default = nav visible; hamburger adds body.nav-closed to hide it.
  // Mobile:  default = nav hidden;  hamburger adds body.nav-open to show drawer.
  const ham     = document.getElementById('bb-ham');
  const overlay = document.getElementById('bb-overlay');

  // Shared rail toggle — bound to the corner hamburger (legacy; hidden ≥641) AND
  // the sort-bar "Filters" chip, which is now the desktop control (the green
  // corner wedge is gone on desktop). Mobile/tablet ≤960 = slide-in drawer
  // (nav-open); desktop ≥961 = collapse the persistent rail (nav-closed).
  function syncRailControls(open) {
    if (ham) ham.setAttribute('aria-expanded', open ? 'true' : 'false');
    document.querySelectorAll('.lg-filters-chip').forEach(function (c) {
      c.setAttribute('aria-expanded', open ? 'true' : 'false');
      c.classList.toggle('is-on', open);
    });
  }
  function railIsOpen() {
    return window.innerWidth > 960
      ? !document.body.classList.contains('nav-closed')
      : document.body.classList.contains('nav-open');
  }
  function toggleNav() {
    if (window.innerWidth <= 960) {
      const opening = document.body.classList.toggle('nav-open');
      if (overlay) overlay.setAttribute('aria-hidden', opening ? 'false' : 'true');
    } else {
      document.body.classList.toggle('nav-closed');
    }
    syncRailControls(railIsOpen());
  }

  // Centered filters modal (Ian 2026-06-11): on the hub feed the side rail no
  // longer renders — the "Filters" chip opens #hub-fmodal instead. Forum
  // subpages have no modal, so the chip/hamburger keep the legacy nav toggle.
  const fmodal = document.getElementById('hub-fmodal');
  function fmodalSet(open) {
    if (!fmodal) return;
    fmodal.hidden = !open;
    document.body.classList.toggle('hub-fmodal-lock', open);
    syncRailControls(open);
    if (open) {
      var p = fmodal.querySelector('.hub-fmodal__panel');
      if (p) p.focus();
    }
  }
  // Filter clicks APPLY WITHOUT CLOSING (Ian 2026-06-11: pick several, modal
  // only closes on the × or a click off it). The rail rows are plain server
  // links (zero-JS fallback = navigate); here we fetch the target URL and
  // swap, in place: the feed cards (keeping the .feed NODE alive so the
  // pinned-columns + infinite-scroll observers survive), the modal body
  // (fresh counts + on-states), the chip bar, and the sort-pill hrefs (so a
  // later sort click keeps the picked filters). URL via replaceState so
  // reload/share lands on the same filtered view. Any failure falls back to
  // plain navigation.
  function fmodalApply(href) {
    var mbody = fmodal.querySelector('.hub-fmodal__body');
    if (mbody) mbody.classList.add('is-loading');
    fetch(href, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { return r.ok ? r.text() : Promise.reject(r.status); })
      .then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var oldR = document.getElementById('hub-feed-results');
        var newR = doc.getElementById('hub-feed-results');
        if (oldR && newR) {
          var oldF = oldR.querySelector('.feed'), newF = newR.querySelector('.feed');
          if (oldF && newF) {
            oldF.innerHTML = newF.innerHTML;
            var oldM = oldR.querySelector('.feed-more'), newM = newR.querySelector('.feed-more');
            if (oldM && newM) oldM.innerHTML = newM.innerHTML;
            else if (oldM && !newM) oldM.parentNode.removeChild(oldM);
            else if (!oldM && newM) oldF.insertAdjacentElement('afterend', document.importNode(newM, true));
          } else {
            oldR.innerHTML = newR.innerHTML;   // empty-state transitions
          }
          document.dispatchEvent(new CustomEvent('lg:hub-feed-swapped'));
        }
        var nb = doc.querySelector('.hub-fmodal__body');
        if (mbody && nb) {
          mbody.innerHTML = nb.innerHTML;
          // The modal body just got a fresh DOM (incl. the author/tag search
          // fields). Their autocomplete listeners were bound to the OLD nodes
          // and died with that innerHTML swap — so picking a SECOND tag/author
          // silently did nothing (Ian: "can't do more than one tag"). Tell
          // hub-filters.js to re-wire the now-fresh fields.
          document.dispatchEvent(new CustomEvent('hub:fmodal-body-swapped'));
        }
        var oldC = document.querySelector('.hub-chipbar'), newC = doc.querySelector('.hub-chipbar');
        if (oldC && newC) oldC.replaceWith(document.importNode(newC, true));
        else if (oldC && !newC) oldC.remove();
        else if (!oldC && newC) {
          var bar = document.querySelector('.feed-sort-bar');
          if (bar) bar.insertAdjacentElement('beforebegin', document.importNode(newC, true));
        }
        // Sort/Saved pills keep the new filter set (hrefs are server-built).
        var newAs = {}, list = doc.querySelectorAll('.feed-sort-bar a');
        for (var i = 0; i < list.length; i++) newAs[list[i].textContent.trim()] = list[i].getAttribute('href');
        document.querySelectorAll('.feed-sort-bar a').forEach(function (a) {
          var h = newAs[a.textContent.trim()];
          if (h) a.setAttribute('href', h);
        });
        // Toolbar search forms carry filters as hidden inputs — refresh them.
        ['q', 'author'].forEach(function (kind) {
          var of = document.querySelector('.hub-tsearch--' + kind);
          var nf = doc.querySelector('.hub-tsearch--' + kind);
          if (!of || !nf) return;
          of.querySelectorAll('input[type="hidden"]').forEach(function (n) { n.remove(); });
          nf.querySelectorAll('input[type="hidden"]').forEach(function (n) {
            of.insertBefore(document.importNode(n, true), of.firstChild);
          });
        });
        try { history.replaceState({}, '', href); } catch (e) {}
        if (mbody) mbody.classList.remove('is-loading');
      })
      .catch(function () { location.href = href; });
  }
  if (fmodal) {
    fmodal.addEventListener('click', function (e) {
      if (e.target.closest('[data-hub-fmodal-close]')) { fmodalSet(false); return; }
      var a = e.target.closest('a[href]');
      var mbody = fmodal.querySelector('.hub-fmodal__body');
      if (a && mbody && mbody.contains(a)) {
        e.preventDefault();
        fmodalApply(a.getAttribute('href'));
      }
    });
    // No Esc close — Ian 2026-06-11: the modal closes ONLY via the × or a
    // click off the panel (multi-select sessions shouldn't lose the dialog).
  }

  if (ham) ham.addEventListener('click', toggleNav);
  document.addEventListener('click', function (e) {
    if (e.target.closest && e.target.closest('.lg-filters-chip')) {
      if (fmodal) { fmodalSet(fmodal.hidden); return; }
      toggleNav();
    }
  });
  if (!fmodal) syncRailControls(railIsOpen());   // reflect initial state on the chip(s)

  if (overlay) {
    overlay.addEventListener('click', function () {
      document.body.classList.remove('nav-open');
      if (ham) ham.setAttribute('aria-expanded', 'false');
      overlay.setAttribute('aria-hidden', 'true');
    });
  }

  // ── 1b. Nav section accordions ───────────────────────────────────────────
  document.querySelectorAll('.nav-tree__section').forEach(function (sec) {
    var toggle = sec.querySelector('.nav-tree__section-toggle');
    if (!toggle) return;
    toggle.addEventListener('click', function () {
      var willOpen = !sec.classList.contains('nav-tree__section--open');
      if (willOpen) {
        // single-expand: collapse any other open section first
        document.querySelectorAll('.nav-tree__section--open').forEach(function (o) {
          if (o === sec) return;
          o.classList.remove('nav-tree__section--open');
          var t = o.querySelector('.nav-tree__section-toggle');
          if (t) t.setAttribute('aria-expanded', 'false');
        });
      }
      sec.classList.toggle('nav-tree__section--open', willOpen);
      toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    });
  });

  // ── 1b2. Rail accordion is native <details>. ────────────────────────────────
  // Type/Categories sections + category parents open/close with zero JS. The
  // ONLY enhancement: guarantee single-open on the named top-level sections for
  // browsers without native <details name> exclusive-accordion (pre-2024
  // Safari/Firefox), where two could otherwise be open at once.
  document.querySelectorAll('details.hub-rail__sec[name]').forEach(function (d) {
    d.addEventListener('toggle', function () {
      if (!d.open) return;
      document.querySelectorAll('details.hub-rail__sec[name="' + d.getAttribute('name') + '"]').forEach(function (o) {
        if (o !== d) o.open = false;
      });
    });
  });

  // ── 1b4. Card prototype: expand-in-place to "max" (flag-gated) ──────────────
  // Flag: ?proto=cards (sticky via localStorage; ?proto=off clears). When on,
  // clicking a feed card opens it to the max tier IN PLACE (single-open) and
  // lazy-loads the full body + full reply thread through the existing WP-free
  // endpoints — the "no click-through" Hub direction. Without the flag the feed
  // is untouched. One slice to validate the interaction on real data.
  (function () {
    try {
      if (/[?&]proto=cards/.test(location.search)) localStorage.setItem('lg_card_proto', '1');
      else if (/[?&]proto=off/.test(location.search)) localStorage.removeItem('lg_card_proto');
    } catch (e) {}
    var protoOn; try { protoOn = localStorage.getItem('lg_card_proto') === '1'; } catch (e) { protoOn = false; }
    var feed = document.querySelector('.feed');
    if (!protoOn || !feed) return;
    feed.classList.add('feed--proto');

    // Shared auth/nonce (fetched once) for inline reply compose.
    var protoAuth = null, protoAuthPending = null;
    function protoGetAuth(cb) {
      if (protoAuth) { cb(protoAuth); return; }
      if (protoAuthPending) { protoAuthPending.push(cb); return; }
      protoAuthPending = [cb];
      fetch('/bb-mirror-api/v0/auth.php', { credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) { protoAuth = d || { authenticated: false }; protoAuthPending.forEach(function (f) { f(protoAuth); }); protoAuthPending = null; })
        .catch(function () { protoAuth = { authenticated: false }; protoAuthPending.forEach(function (f) { f(protoAuth); }); protoAuthPending = null; });
    }
    var protoReplyBase = (document.getElementById('frm-form') || { dataset: {} }).dataset.restBase || '/wp-json/buddyboss/v1';

    // Moderator Trash on thread reply stubs (BB-style admin action, in-feed).
    // Reveal the trash controls when the viewer can moderate; the DELETE endpoint
    // re-checks caps server-side, so the UI gate is convenience, not security.
    protoGetAuth(function (auth) {
      if (auth && auth.can_edit_others) feed.classList.add('feed--can-moderate');
      // Own-reply edit/delete (Ian 2026-06-11): tag the viewer's own reply rows
      // with .reply-stub--mine so CSS reveals their edit/trash. Covers lazily
      // loaded threads (feed expand + the §4e modal drains) via a throttled
      // observer; the server endpoint re-checks author-or-mod regardless.
      var myUid = (auth && auth.wp_user_id) || 0;
      if (myUid > 0) {
        var markMine = function () {
          document.querySelectorAll('.reply-stub[data-author-id="' + myUid + '"]:not(.reply-stub--mine)')
            .forEach(function (s) { s.classList.add('reply-stub--mine'); });
        };
        markMine();
        var queued = false;
        try {
          new MutationObserver(function () {
            if (queued) return; queued = true;
            requestAnimationFrame(function () { queued = false; markMine(); });
          }).observe(document.body, { childList: true, subtree: true });
        } catch (e) {}
      }
    });

    // Moderator Edit — inline rich editor on a thread reply stub (PUT /reply/{id};
    // topic/forum read from the card). Quill (same snow toolbar as the new-topic /
    // reply-modal composers); server re-checks caps.
    feed.addEventListener('click', function (ev) {
      var e = ev.target.closest('.reply-stub__edit');
      if (!e || !feed.contains(e)) return;
      ev.preventDefault(); ev.stopPropagation();
      var stub = e.closest('.reply-stub');
      if (!stub || stub.querySelector('.reply-stub__editbox')) return;
      var bodyDiv = stub.querySelector('.reply-stub__body');
      var excerpt = stub.querySelector('.reply-stub__excerpt');
      var card = e.closest('.feed-card');
      var cta = card && card.querySelector('.feed-card__reply-cta[data-frm-open]');
      var id = parseInt(e.getAttribute('data-reply-id'), 10);
      var topicId = card ? parseInt(card.getAttribute('data-topic-id'), 10) : 0;
      var forumId = cta ? parseInt(cta.dataset.forumId, 10) : 0;
      var cur = excerpt ? (excerpt.innerText || excerpt.textContent || '').trim() : '';
      var box = document.createElement('div');
      box.className = 'reply-stub__editbox';
      box.innerHTML =
        '<div class="rse-editor"></div>' +
        '<div class="rse-row"><button type="button" class="rse-save">Save</button>' +
        '<button type="button" class="rse-cancel">Cancel</button><span class="rse-status"></span></div>';
      if (bodyDiv) { bodyDiv.style.display = 'none'; bodyDiv.parentNode.insertBefore(box, bodyDiv.nextSibling); }
      else { stub.appendChild(box); }

      var editorEl = box.querySelector('.rse-editor');
      var status   = box.querySelector('.rse-status');

      // Full reply body for round-trip: prefer the raw stored HTML (data-reply-raw,
      // emitted by _reply-render.php — the COMPLETE body, not the truncated stub
      // excerpt), fall back to the excerpt's HTML, then to its plain text.
      var rawHtml = e.getAttribute('data-reply-raw');
      if (!rawHtml) rawHtml = excerpt ? excerpt.innerHTML : '';
      if (!rawHtml && cur) {
        rawHtml = '<p>' + cur.replace(/[&<>]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]; }).replace(/\n/g, '<br>') + '</p>';
      }

      var rseQuill = null, ta = null;

      // Image button → upload to BB media → inline embed (mirrors frmImageHandler).
      // NB: rse is intentionally NOT on the out-of-body tray — unlike the other
      // composers it does NOT strip <img> on save (an edited reply's images live
      // inline in content_html and round-trip), so its inline images are already
      // real, deletable editor content. A tray would pull them out of the saved
      // HTML with no bbp_media to carry them. Leave rse on the inline path.
      function rseImageHandler() {
        var input = document.createElement('input');
        input.type = 'file'; input.accept = 'image/*';
        input.onchange = function () {
          var file = input.files && input.files[0];
          if (!file) return;
          status.textContent = 'Uploading image…';
          protoGetAuth(function (auth) {
            if (!auth || !auth.nonce) { status.textContent = 'Not signed in.'; return; }
            var fd = new FormData(); fd.append('file', file);
            fetch(protoReplyBase + '/media/upload', {
              method: 'POST', credentials: 'same-origin',
              headers: { 'X-WP-Nonce': auth.nonce }, body: fd,
            })
              .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
              .then(function (res) {
                if (!res.ok || !res.j.upload_id) {
                  status.textContent = 'Image upload failed: ' + ((res.j && res.j.message) || 'error'); return;
                }
                status.textContent = 'Image attached.';
                var range = rseQuill.getSelection(true);
                rseQuill.insertEmbed(range ? range.index : 0, 'image', res.j.upload_thumb || res.j.upload);
              })
              .catch(function (err) { status.textContent = 'Upload error: ' + err.message; });
          });
        };
        input.click();
      }

      // Rich editor — same snow toolbar/options as the new-topic / reply-modal
      // composers (frmQuill). Falls back to a plain textarea if the Quill CDN
      // didn't load.
      if (typeof Quill !== 'undefined') {
        rseQuill = new Quill(editorEl, {
          theme: 'snow',
          placeholder: 'Edit your reply…',
          // Clamp the link/format tooltip inside the editor (same bounds fix as the
          // other Hub composers) so it can't fly off the reply column's edge.
          bounds: editorEl,
          modules: { toolbar: {
            container: [
              [{ header: [2, 3, false] }],
              ['bold', 'italic', 'underline'],
              ['blockquote', 'code-block'],
              [{ list: 'ordered' }, { list: 'bullet' }],
              ['link', 'image'],
              ['clean'],
            ],
            handlers: { image: rseImageHandler },
          } },
        });
  lgQuillNoAutofill(editorEl);
        if (rawHtml) rseQuill.clipboard.dangerouslyPasteHTML(rawHtml);
        rseQuill.focus();
      } else {
        editorEl.innerHTML = '<textarea class="rse-input" autocomplete="off"></textarea>';
        ta = editorEl.querySelector('.rse-input');
        ta.value = cur; ta.focus();
        ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight, 200) + 'px';
      }

      box.querySelector('.rse-cancel').addEventListener('click', function () { box.remove(); if (bodyDiv) bodyDiv.style.display = ''; });
      box.querySelector('.rse-save').addEventListener('click', function () {
        var html;
        if (rseQuill) {
          // Serialize the Quill body. Unlike the new-reply composer we do NOT strip
          // <img>: an existing reply's images already live in content_html and must
          // round-trip (stripping would delete them); newly-inserted images ride
          // inline by their uploaded URL.
          html = rseQuill.root.innerHTML;
          if (html === '<p><br></p>') html = '';
          html = html.trim();
        } else {
          var text = (ta.value || '').trim();
          html = text ? '<p>' + text.replace(/[&<>]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]; }).replace(/\n/g, '<br>') + '</p>' : '';
        }
        if (!html) { status.textContent = "Can't be empty."; return; }
        if (!id || !topicId) { status.textContent = 'Missing reply/topic.'; return; }
        status.textContent = 'Saving…';
        protoGetAuth(function (auth) {
          if (!auth || !auth.nonce) { status.textContent = 'Not signed in.'; return; }
          // Our endpoint authorizes author-OR-moderator + hard-deletes (the native
          // BuddyBoss DELETE is mods-only) — Ian 2026-06-11 own-reply edit/delete.
          fetch('/bb-mirror-api/v0/reply', {
            method: 'PUT', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': auth.nonce },
            body: JSON.stringify({ reply_id: id, content: html }),
          })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }, function () { return { ok: r.ok, j: {} }; }); })
            .then(function (res) {
              if (!res.ok) { status.textContent = (res.j && (res.j.message || res.j.code)) || 'Could not save.'; return; }
              if (excerpt) excerpt.innerHTML = html;
              box.remove(); if (bodyDiv) bodyDiv.style.display = '';
            })
            .catch(function (err) { status.textContent = 'Network error: ' + err.message; });
        });
      });
    });

    feed.addEventListener('click', function (ev) {
      var t = ev.target.closest('.reply-stub__trash');
      if (!t || !feed.contains(t)) return;
      ev.preventDefault(); ev.stopPropagation();
      var id = parseInt(t.getAttribute('data-reply-id'), 10);
      if (!id || !window.confirm('Trash this reply? This can’t be undone.')) return;
      protoGetAuth(function (auth) {
        if (!auth || !auth.nonce) { alert('Not signed in.'); return; }
        t.disabled = true;
        fetch('/bb-mirror-api/v0/reply', {
          method: 'DELETE', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': auth.nonce },
          body: JSON.stringify({ reply_id: id }),
        })
          .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }, function () { return { ok: r.ok, j: {} }; }); })
          .then(function (res) {
            if (!res.ok) { t.disabled = false; alert('Could not trash: ' + ((res.j && (res.j.message || res.j.code)) || 'failed')); return; }
            var stub = t.closest('.reply-stub'); if (stub) stub.remove();
          })
          .catch(function (err) { t.disabled = false; alert('Network error: ' + err.message); });
      });
    });

    // Inline reply composer in the expanded discussion card — post in-feed, no modal.
    function protoMountComposer(card) {
      if (card.querySelector('.feed-card__inline-compose')) return;
      var cta = card.querySelector('.feed-card__reply-cta[data-frm-open]');
      var topicId = card.getAttribute('data-topic-id') || (cta && cta.dataset.topicId) || '';
      var forumId = (cta && cta.dataset.forumId) || '';
      if (!topicId) return;
      var box = document.createElement('div');
      box.className = 'feed-card__inline-compose';
      box.innerHTML =
        '<textarea class="fic-input" rows="1" autocomplete="off" placeholder="Reply to this thread…"></textarea>' +
        '<button type="button" class="fic-send" disabled>Reply</button>' +
        '<div class="fic-status" role="status"></div>';
      (card.querySelector('.feed-card__replies') || card).appendChild(box);
      var ta = box.querySelector('.fic-input'), send = box.querySelector('.fic-send'), status = box.querySelector('.fic-status');
      ta.addEventListener('input', function () { send.disabled = !ta.value.trim(); ta.style.height = 'auto'; ta.style.height = ta.scrollHeight + 'px'; });
      send.addEventListener('click', function () {
        var text = ta.value.trim(); if (!text) return;
        send.disabled = true; status.textContent = 'Posting…';
        protoGetAuth(function (auth) {
          if (!auth || !auth.authenticated) { status.textContent = 'Sign in to reply.'; return; }
          var html = '<p>' + text.replace(/[&<>]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]; }).replace(/\n/g, '<br>') + '</p>';
          var payload = { topic_id: parseInt(topicId, 10), content: html };
          if (parseInt(forumId, 10)) payload.forum_id = parseInt(forumId, 10);
          fetch(protoReplyBase + '/reply', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': auth.nonce },
            body: JSON.stringify(payload),
          })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (res) {
              if (!res.ok) { status.textContent = (res.j && (res.j.message || res.j.code)) || 'Could not post.'; send.disabled = false; return; }
              ta.value = ''; ta.style.height = 'auto'; status.textContent = 'Posted ✓ — refreshing thread…';
              var ex = card.querySelector('.feed-card__expand');   // reload the thread to show the new reply
              if (ex) { card.classList.remove('replies-expanded'); var full = card.querySelector('.feed-card__replies-full'); if (full) { full.dataset.loaded = ''; } ex.click(); }
            })
            .catch(function (err) { status.textContent = 'Network error: ' + err.message; send.disabled = false; });
        });
      });
    }

    feed.addEventListener('click', function (ev) {
      var card = ev.target.closest('.feed-card');
      if (!card || !feed.contains(card)) return;

      // CPT / content cards CLICK THROUGH to the full post (Ian 6/6). Real links +
      // controls work; a bare-area click navigates to the post.
      if (card.classList.contains('feed-card--content')) {
        if (ev.target.closest('a, button, input, textarea, label, select, [data-comments], .feed-card__compact-expand, .lg-card-actions')) return;
        var href = card.getAttribute('data-href');
        if (href) window.location.href = href;
        return;
      }

      // Discussion (topic) cards: expand IN PLACE — no click-through. The title +
      // body + bare area expand; real controls (read-more, reply, view-replies,
      // author/profile links, thread links) keep working.
      if (ev.target.closest('button, input, textarea, label, select, .feed-card__compact-expand, ' +
            '.feed-card__read-more, .feed-card__expand, .feed-card__reply-cta, .reply-stub__reply, ' +
            '.fc-facepile, .fc-composer')) return;
      // author + in-thread links still navigate; only the title link is hijacked to expand
      if (ev.target.closest('a') && !ev.target.closest('.feed-card__title a')) return;
      var titleA = ev.target.closest('.feed-card__title a');
      if (titleA) ev.preventDefault();

      var willOpen = !card.classList.contains('feed-card--max');
      feed.querySelectorAll('.feed-card--max').forEach(function (c) { if (c !== card) c.classList.remove('feed-card--max'); });
      card.classList.toggle('feed-card--max', willOpen);
      if (!willOpen) return;
      var rm = card.querySelector('.feed-card__read-more');          // body-only expand
      if (rm && rm.dataset.state !== 'expanded') rm.click();         // replies open via the reply-count control
      protoMountComposer(card);                                       // inline reply box (no modal)
      card.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    });
  })();

  // ── 1c. Admin: set forum header image (pencil) ──────────────────────────────
  var hdrEdit = document.querySelector('.forum-header__edit-img');
  if (hdrEdit) {
    fetch('/bb-mirror-api/v0/auth.php', { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (!data || !data.authenticated || !data.can_edit_others) return;
        var nonce = data.nonce;
        hdrEdit.hidden = false;
        hdrEdit.addEventListener('click', function () {
          var input = document.createElement('input');
          input.type = 'file'; input.accept = 'image/*';
          input.onchange = function () {
            var file = input.files && input.files[0];
            if (!file) return;
            hdrEdit.disabled = true; hdrEdit.textContent = '…';
            var fd = new FormData(); fd.append('file', file);
            fetch('/wp-json/buddyboss/v1/media/upload', {
              method: 'POST', credentials: 'same-origin',
              headers: { 'X-WP-Nonce': nonce }, body: fd,
            })
              .then(function (r) { return r.json(); })
              .then(function (up) {
                if (!up || !up.upload_id) throw new Error('upload failed');
                // Send the attachment id; the endpoint resolves a clean public URL.
                return fetch('/bb-mirror-api/v0/set-forum-image', {
                  method: 'POST', credentials: 'same-origin',
                  headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                  body: JSON.stringify({ forum_id: parseInt(hdrEdit.dataset.forumId, 10), upload_id: up.upload_id }),
                });
              })
              .then(function (r) { return r.json(); })
              .then(function (res) {
                if (res && res.ok) { window.location.reload(); }
                else { hdrEdit.disabled = false; hdrEdit.textContent = '✎'; alert('Could not set image: ' + ((res && res.error) || 'error')); }
              })
              .catch(function (e) { hdrEdit.disabled = false; hdrEdit.textContent = '✎'; alert('Upload error: ' + e.message); });
          };
          input.click();
        });
      })
      .catch(function () { /* silent */ });
  }

  // ── 2. Feed card replies: lazy-load full thread on "View N replies" ─────────
  // The feed ships only ONE teaser reply per card (perf). The full threaded
  // list is fetched on first expand from <FORUM_BASE>/?replies=<id> and injected
  // into .feed-card__replies-full, then toggled.
  // Delegated: works for expand buttons added dynamically after an inline reply.
  document.addEventListener('click', async function (ev) {
    const btn = ev.target.closest('.feed-card__expand');
    if (!btn) return;
    if (!btn.dataset.collapseLabel) btn.dataset.collapseLabel = btn.textContent;
    const card = btn.closest('.feed-card');
    const full = card && card.querySelector('.feed-card__replies-full');
    if (!full) return;
    const expanded = card.classList.contains('replies-expanded');

    if (expanded) {                       // collapse
      card.classList.remove('replies-expanded');
      full.hidden = true;
      btn.textContent = btn.dataset.collapseLabel;
      return;
    }

    // lazy-fetch the full thread once
    if (!full.dataset.loaded) {
      const orig = btn.textContent;
      btn.textContent = 'Loading…';
      btn.disabled = true;
      try {
        const res = await fetch(FORUM_BASE + '/?replies=' + btn.dataset.topicId);
        if (!res.ok) throw new Error('fetch failed');
        full.innerHTML = await res.text();
        full.dataset.loaded = '1';
      } catch (err) {
        btn.textContent = orig;
        btn.disabled = false;
        return;
      }
      btn.disabled = false;
    }

    card.classList.add('replies-expanded');
    full.hidden = false;
    btn.textContent = 'Hide replies ▲';
  });

  // ── 2-unified. One in-place expand (Ian): a single chevron opens BOTH the full
  // body and the full reply thread together, and collapses both on re-click. It
  // ORCHESTRATES the existing lazy-loaders (read-more §2b + expand-thread §2 above),
  // which stay in the DOM (CSS-hidden) so their fetch/collapse logic — and the
  // composer's post-reload, which clicks .feed-card__expand — keep working.
  document.addEventListener('click', function (e) {
    var u = e.target.closest('.feed-card__expand-all'); if (!u) return;
    e.stopPropagation();
    var card = u.closest('.feed-card'); if (!card) return;
    var rm = card.querySelector('.feed-card__read-more');
    var ex = card.querySelector('.feed-card__expand');
    var expanding = u.getAttribute('aria-expanded') !== 'true';
    if (expanding) {
      if (rm && rm.dataset.state !== 'expanded') rm.click();
      if (ex && !card.classList.contains('replies-expanded')) ex.click();
    } else {
      if (rm && rm.dataset.state === 'expanded') rm.click();
      if (ex && card.classList.contains('replies-expanded')) ex.click();
    }
    u.setAttribute('aria-expanded', expanding ? 'true' : 'false');
    card.classList.toggle('is-expanded-all', expanding);
  });

  // ── 2c. Reply stub inline expand ("… more") ──────────────────────────────
  // Delegated so it works on stubs revealed by the accordion expand above.
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.reply-stub__expand');
    if (!btn) return;
    e.stopPropagation();
    var full = btn.previousElementSibling; // .reply-stub__full
    full.hidden = false;
    btn.remove();
  });

  // Lead-reply image opener: a teaser reply hides its image (and defers loading)
  // until opened. Swap data-src -> src and reveal.
  document.addEventListener('click', function (e) {
    var b = e.target.closest('.reply-stub__img-open');
    if (!b) return;
    e.stopPropagation();
    var img = b.nextElementSibling;
    if (img && img.dataset && img.dataset.src) { img.src = img.dataset.src; img.hidden = false; }
    b.remove();
  });

  // ── 2c-bis. Reply sort toggle (Newest / Oldest) in the expanded thread ──────
  // The ?replies fragment carries the toggle; clicking re-fetches with &sort= and
  // swaps the thread HTML (the fragment re-emits the toggle with active state).
  document.addEventListener('click', function (e) {
    var b = e.target.closest('.replies-sort__btn');
    if (!b || b.classList.contains('is-active')) return;
    var bar = b.closest('.replies-sort');
    var host = b.closest('.feed-card__replies-full');
    if (!bar || !host) return;
    host.style.opacity = '0.5';
    fetch(FORUM_BASE + '/?replies=' + bar.dataset.topicId + '&sort=' + b.dataset.sort)
      .then(function (r) { return r.ok ? r.text() : Promise.reject(new Error('fetch')); })
      .then(function (html) { host.innerHTML = html; host.style.opacity = ''; })
      .catch(function () { host.style.opacity = ''; });
  });

  // ── 2b. Feed card full-body expand (Read more / Read less — lazy fetch) ──
  // Delegated (was per-button) so cards added by filter-swap / infinite-scroll get
  // it too — read-more is now the primary, visible body-only expander (Ian).
  document.addEventListener('click', async function (ev) {
    const btn = ev.target.closest('.feed-card__read-more');
    if (!btn) return;
    {
      const card = btn.closest('.feed-card');
      const body = card.querySelector('.feed-card__full-body');

      if (btn.dataset.state === 'expanded') {
        // collapse this card
        body.hidden = true;
        const excerpt = card.querySelector('.feed-card__op-excerpt');
        if (excerpt) excerpt.style.display = '';
        const embC = card.querySelector('.feed-card__embed');
        if (embC) embC.hidden = false;   // restore inline embed
        btn.textContent = 'Read more ▾';
        btn.dataset.state = 'collapsed';
        return;
      }

      // close any other open post body first
      document.querySelectorAll('.feed-card__read-more[data-state="expanded"]').forEach(other => {
        const otherCard = other.closest('.feed-card');
        const otherBody = otherCard.querySelector('.feed-card__full-body');
        const otherExcerpt = otherCard.querySelector('.feed-card__op-excerpt');
        otherBody.hidden = true;
        if (otherExcerpt) otherExcerpt.style.display = '';
        other.textContent = 'Read more ▾';
        other.dataset.state = 'collapsed';
      });

      // lazy-fetch on first open
      if (!body.dataset.loaded) {
        btn.textContent = 'Loading…';
        btn.disabled = true;
        try {
          const res = await fetch(FORUM_BASE + '/?body=' + btn.dataset.topicId);
          if (!res.ok) throw new Error('fetch failed');
          body.innerHTML = await res.text();
          body.dataset.loaded = '1';
          bbProcessEmbeds(body);
        } catch (e) {
          btn.textContent = 'Read more ▾';
          btn.disabled = false;
          return;
        }
        btn.disabled = false;
      }

      // hide excerpt — use style.display, not hidden, because display:-webkit-box overrides [hidden]
      const excerpt = card.querySelector('.feed-card__op-excerpt');
      if (excerpt) excerpt.style.display = 'none';
      const embE = card.querySelector('.feed-card__embed');
      if (embE) embE.hidden = true;   // full body re-embeds it; avoid duplicate
      body.hidden = false;
      btn.textContent = 'Read less ▲';
      btn.dataset.state = 'expanded';
    }
  });

  // ── 2d. Client-side embeds ───────────────────────────────────────────────
  // Bare provider URLs (stored as plain text in content_html) become iframes /
  // provider blockquotes. Best-effort: YouTube, Vimeo, Twitter/X, Instagram.
  // function declarations are hoisted, so 2b's lazy-loader can call this.
  var bbEmbedScripts = {}; // provider → loaded flag

  function bbLoadScript(key, src) {
    if (bbEmbedScripts[key]) return;
    bbEmbedScripts[key] = true;
    var s = document.createElement('script');
    s.src = src; s.async = true; s.charset = 'utf-8';
    document.body.appendChild(s);
  }

  // Returns an embed wrapper Element for a provider URL, or null.
  function bbBuildEmbed(url) {
    var m;
    // YouTube
    m = url.match(/(?:youtube\.com\/(?:watch\?(?:.*&)?v=|shorts\/|embed\/)|youtu\.be\/)([\w-]{6,})/i);
    if (m) return bbIframeEmbed('https://www.youtube.com/embed/' + m[1]);
    // Vimeo
    m = url.match(/vimeo\.com\/(?:video\/)?(\d+)/i);
    if (m) return bbIframeEmbed('https://player.vimeo.com/video/' + m[1]);
    // Twitter / X
    m = url.match(/(?:twitter\.com|x\.com)\/(\w+)\/status\/(\d+)/i);
    if (m) {
      var bq = document.createElement('blockquote');
      bq.className = 'twitter-tweet';
      var a = document.createElement('a');
      a.href = 'https://twitter.com/' + m[1] + '/status/' + m[2];
      bq.appendChild(a);
      var wrap = document.createElement('div');
      wrap.className = 'bb-embed bb-embed--tweet';
      wrap.appendChild(bq);
      bbLoadScript('twitter', 'https://platform.twitter.com/widgets.js');
      return wrap;
    }
    // Instagram (post / reel / tv)
    m = url.match(/instagram\.com\/(?:p|reel|tv)\/([\w-]+)/i);
    if (m) {
      var permalink = 'https://www.instagram.com/p/' + m[1] + '/';
      var ig = document.createElement('blockquote');
      ig.className = 'instagram-media';
      ig.setAttribute('data-instgrm-permalink', permalink);
      ig.setAttribute('data-instgrm-version', '14');
      var iga = document.createElement('a');
      iga.href = permalink; iga.textContent = 'View this post on Instagram';
      ig.appendChild(iga);
      var igwrap = document.createElement('div');
      igwrap.className = 'bb-embed bb-embed--ig';
      igwrap.appendChild(ig);
      bbLoadScript('instagram', 'https://www.instagram.com/embed.js');
      // if embed.js already loaded earlier, re-process
      if (window.instgrm && window.instgrm.Embeds) {
        setTimeout(function () { window.instgrm.Embeds.process(); }, 50);
      }
      return igwrap;
    }
    return null;
  }

  // Discussion-body YouTube/Vimeo plays FREE for everyone — Ian 2026-06-11
  // ("don't gate youtube videos in discussions"; overrules the earlier
  // no-free-video rule for member-pasted links). Looth's OWN paywalled
  // content stays locked via the server-rendered teaser cards.
  function bbIframeEmbed(src) {
    var wrap = document.createElement('div');
    wrap.className = 'bb-embed bb-embed--video';
    var ifr = document.createElement('iframe');
    ifr.src = src;
    ifr.setAttribute('frameborder', '0');
    ifr.setAttribute('allowfullscreen', '');
    ifr.setAttribute('allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; fullscreen');
    ifr.loading = 'lazy';
    wrap.appendChild(ifr);
    return wrap;
  }

  function bbProcessEmbeds(root) {
    if (!root) return;

    // 1. Bare anchors whose text == href (auto-linkified URLs)
    root.querySelectorAll('a[href]').forEach(function (a) {
      var href = a.getAttribute('href') || '';
      if (a.textContent.trim() !== href) return; // not a bare link
      var embed = bbBuildEmbed(href);
      if (!embed) return;
      // if the anchor is the only child of a <p>, replace the whole <p>
      var target = (a.parentElement && a.parentElement.tagName === 'P'
                    && a.parentElement.childNodes.length === 1) ? a.parentElement : a;
      target.replaceWith(embed);
    });

    // 2. Paragraphs whose entire text is a single provider URL
    root.querySelectorAll('p').forEach(function (p) {
      if (p.querySelector('iframe, blockquote, .bb-embed')) return;
      var txt = p.textContent.trim();
      if (!/^https?:\/\/\S+$/.test(txt)) return;
      var embed = bbBuildEmbed(txt);
      if (embed) p.replaceWith(embed);
    });

    // 3. content_html that is JUST a bare URL with no element wrapper
    if (!root.querySelector('.bb-embed') && root.children.length === 0) {
      var raw = root.textContent.trim();
      if (/^https?:\/\/\S+$/.test(raw)) {
        var embed = bbBuildEmbed(raw);
        if (embed) { root.textContent = ''; root.appendChild(embed); }
      }
    }

    // 3b. Any text node that is EXACTLY a single provider URL → embed it.
    //     Legacy content sometimes leads with a bare provider URL glued straight
    //     to following markup (e.g. an IG reel URL + "<div>…"), so it never sits
    //     alone in a <p> and steps 1–3 miss it; catch it before auto-linking
    //     turns it into a plain text link.
    (function () {
      var w = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null);
      var nodes = [], n;
      while ((n = w.nextNode())) nodes.push(n);
      nodes.forEach(function (node) {
        var txt = (node.nodeValue || '').trim();
        if (!/^https?:\/\/\S+$/.test(txt)) return;
        for (var p = node.parentNode; p && p !== root; p = p.parentNode) {
          if (p.nodeName === 'A' || (p.classList && p.classList.contains('bb-embed'))) return;
        }
        var em = bbBuildEmbed(txt);
        if (em && node.parentNode) node.parentNode.replaceChild(em, node);
      });
    })();

    // 4. Auto-link any remaining bare URLs (legacy posts store them as plain
    //    text; WP make_clickable()s them at render — we echo raw, so do it here).
    bbAutoLink(root);
  }

  // Wrap bare http(s) URLs in text nodes with anchors. Skips text already inside
  // links, embeds, or code so we never double-wrap or break existing markup.
  function bbAutoLink(root) {
    if (!root) return;
    var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
      acceptNode: function (n) {
        if (!n.nodeValue || n.nodeValue.indexOf('http') === -1) return NodeFilter.FILTER_REJECT;
        for (var p = n.parentNode; p && p !== root; p = p.parentNode) {
          var t = p.nodeName;
          if (t === 'A' || t === 'SCRIPT' || t === 'STYLE' || t === 'CODE' || t === 'PRE') return NodeFilter.FILTER_REJECT;
          if (p.classList && p.classList.contains('bb-embed')) return NodeFilter.FILTER_REJECT;
        }
        return NodeFilter.FILTER_ACCEPT;
      }
    });
    var nodes = [], n;
    while ((n = walker.nextNode())) nodes.push(n);
    var re = /https?:\/\/[^\s<>()]+[^\s<>().,;:!?'"\]]/g;
    nodes.forEach(function (node) {
      var text = node.nodeValue, frag = document.createDocumentFragment(), last = 0, m;
      re.lastIndex = 0;
      while ((m = re.exec(text))) {
        if (m.index > last) frag.appendChild(document.createTextNode(text.slice(last, m.index)));
        var a = document.createElement('a');
        a.href = m[0]; a.textContent = m[0];
        a.target = '_blank'; a.rel = 'noopener noreferrer';
        a.className = 'bb-autolink';
        frag.appendChild(a);
        last = m.index + m[0].length;
      }
      if (last === 0) return;                 // no match — leave node untouched
      if (last < text.length) frag.appendChild(document.createTextNode(text.slice(last)));
      node.parentNode.replaceChild(frag, node);
    });
  }

  // Close-filters button in the sidebar (Ian 2026-06-11) — mirrors the Filters
  // pill: same body class + the same lg-nav-open persistence the pre-paint
  // rail-state script reads, so pill/button/first-frame all agree.
  document.addEventListener('click', function (e) {
    var c = e.target.closest && e.target.closest('[data-lg-nav-close]');
    if (!c) return;
    document.body.classList.add('nav-closed');
    try { localStorage.setItem('lg-nav-open', '0'); } catch (err) {}
  });

  // Expose so the §4e discussion modal (separate IIFE) can embed provider URLs
  // in its lazily-fetched OP body + reply thread — the load-time scan below only
  // sees static page content, never the modal's injected HTML.
  window.bbProcessEmbeds = bbProcessEmbeds;

  // Initial scan of any rendered bodies present at load (single-topic pages).
  document.querySelectorAll('.post__body, .feed-card__full-body[data-loaded]').forEach(bbProcessEmbeds);

  // ── 2e. Lazy provider-URL embeds in feed cards ──────────────────────────────
  // The feed shows the plain excerpt; provider posts (IG/YouTube/Vimeo/X) get an
  // inline embed for their first provider URL. Built via IntersectionObserver so
  // we don't pull every provider script up front.
  (function () {
    var slots = document.querySelectorAll('.feed-card__embed[data-embed-url]');
    if (!slots.length) return;
    function fill(slot) {
      if (slot.dataset.embedded) return;
      slot.dataset.embedded = '1';
      var em = bbBuildEmbed(slot.dataset.embedUrl);
      if (em) slot.appendChild(em);
    }
    if (typeof IntersectionObserver === 'undefined') {
      slots.forEach(fill);
      return;
    }
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (!en.isIntersecting) return;
        io.unobserve(en.target);
        fill(en.target);
      });
    }, { rootMargin: '300px' });
    slots.forEach(function (s) { io.observe(s); });
  })();

  // ── 3a. Topic-list page: fetch unread IDs + mark them ───────────────────
  const topicList = document.querySelector('.topic-list');
  if (topicList) {
    const ids = Array.from(topicList.querySelectorAll('[data-topic-id]'))
      .map(function (el) { return parseInt(el.dataset.topicId, 10); })
      .filter(function (n) { return n > 0; });
    if (ids.length) {
      fetch('/bb-mirror-api/v0/unread.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ topic_ids: ids }),
      })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) {
          if (!data || !data.authenticated) return;
          var unread = new Set(data.unread);
          topicList.querySelectorAll('[data-topic-id]').forEach(function (el) {
            if (unread.has(parseInt(el.dataset.topicId, 10))) {
              el.classList.add('topic--unread');
            }
          });
        })
        .catch(function () { /* silent */ });
    }
  }

  // ── 4. New topic modal ───────────────────────────────────────────────────
  var ntmOverlay  = document.getElementById('ntm-overlay');
  var ntmOpen     = document.getElementById('ntm-open');
  var ntmBackdrop = document.getElementById('ntm-backdrop');
  var ntmCancel   = document.getElementById('ntm-cancel');
  var ntmForm     = document.getElementById('ntm-form');
  var ntmForumList= document.getElementById('ntm-forum');  // radiogroup of leaf forums
  var ntmTitleIn  = document.getElementById('ntm-title-in');
  var ntmContentEl= document.getElementById('ntm-content');
  var ntmSubmit   = document.getElementById('ntm-submit');
  var ntmStatus   = document.getElementById('ntm-status');
  var ntmLoading  = document.getElementById('ntm-loading');
  var ntmAnon     = document.getElementById('ntm-anon');

  if (ntmOverlay) {   // modal init no longer requires #ntm-open (button moved to the header banner / leaf "Post here")
    var ntmNonce     = null;
    var ntmAuthorName = '';    // viewer's WP display_name (from auth.php) — matches
                               // topic.author_name, so it drives the post-submit
                               // ?author= landing filter (Ian 6/17).
    var ntmAuthState = 'idle'; // idle | loading | anon | authed
    var ntmQuill     = null;   // Quill instance (lazy)
    var ntmMediaIds  = [];      // upload_ids for bbp_media
    var ntmEditId    = null;    // when set, the composer EDITS this topic (PUT) vs creates (POST)
    var ntmKeepMedia = [];      // edit mode: existing bp_media.id the user is KEEPING (✕ removes)
    var ntmEditHadMedia = false;// edit mode: did the topic have photos at open (so we always sync)
    var ntmRestBase  = ntmForm.dataset.restBase || '/wp-json/buddyboss/v1';
    var ntmEditorEl  = document.getElementById('ntm-editor');

    // Lazy-init Quill on first authed open. Falls back to the plain textarea
    // if the CDN script didn't load.
    function ntmInitEditor() {
      if (ntmQuill || !ntmEditorEl) return;
      if (typeof Quill === 'undefined') {
        // Fallback: reveal the plain textarea
        if (ntmContentEl) ntmContentEl.hidden = false;
        ntmEditorEl.style.display = 'none';
        return;
      }
      ntmQuill = new Quill(ntmEditorEl, {
        theme: 'snow',
        placeholder: 'Share details, ask a question…',
        // Clamp link/format tooltip inside the editor (else it overflows the modal).
        bounds: ntmEditorEl,
        modules: {
          toolbar: {
            container: [
              [{ header: [2, 3, false] }],
              ['bold', 'italic', 'underline'],
              ['blockquote', 'code-block'],
              [{ list: 'ordered' }, { list: 'bullet' }],
              ['link', 'image'],
              ['clean'],
            ],
            handlers: { image: ntmImageHandler },
          },
        },
      });
      lgQuillNoAutofill(ntmEditorEl);
    }

    // Image button → file picker → upload to BB → tray thumb (desktop) or inline
    // preview (mobile legacy). See lgComposerTray. ntmMediaIds = source of truth.
    var ntmTray = lgComposerTray({
      editorEl: ntmEditorEl,
      mediaIds: ntmMediaIds,
      statusEl: ntmStatus,
      restBase: ntmRestBase,
      // Mobile composer is now an Instagram-style wizard with a dedicated photo
      // step; force the thumbnail tray (vs inline-into-body) so picked images
      // show as removable thumbs there (hub-polish relocates the tray). The mobile
      // composer reads ntmMediaIds for bbp_media on submit either way. (Ian 6/17)
      forceTray: true,
      getNonce: function (cb) { cb(ntmNonce); },
      insertInline: function (url) {
        var range = ntmQuill.getSelection(true);
        ntmQuill.insertEmbed(range ? range.index : 0, 'image', url);
      },
    });
    function ntmImageHandler() { ntmTray.handler(); }
    // Direct photo hook for the mobile composer (hub-polish fbStyleComposer). The
    // mobile "Photo" button used to bounce through Quill's display:none
    // .ql-toolbar .ql-image, which iOS Safari refuses to honor as a file-input
    // user gesture. Calling the upload tray straight from the visible button keeps
    // input.click() inside the gesture. Desktop is unaffected (just a global def).
    window.lgNtmPhoto = function () { ntmTray.handler(); };

    // ── Forum radio-list helpers (single-select; replaces the native <select>) ─
    function ntmGetForum() {
      var r = ntmForumList && ntmForumList.querySelector('input[name="forum_id"]:checked');
      return r ? { id: parseInt(r.value, 10), slug: r.dataset.slug } : null;
    }
    function ntmSetForum(id) {
      if (!ntmForumList || !id) return false;
      var r = ntmForumList.querySelector('input[name="forum_id"][value="' + id + '"]');
      if (!r) return false;
      r.checked = true;
      // Fire change so listeners that mirror the selection update too — notably the
      // mobile accordion summary (hub-polish fbcSyncTrig), which otherwise shows a
      // stale "Choose a forum" on programmatic preselect (edit mode + "Post here").
      try { r.dispatchEvent(new Event('change', { bubbles: true })); } catch (e) {}
      var leaf = r.closest('.ntm-fl__leaf');
      if (leaf) leaf.scrollIntoView({ block: 'nearest' });
      return true;
    }
    // Focus the title once a forum is chosen, else the picker (checked row or first).
    function ntmFocusEntry() {
      if (ntmForm.hidden) return;
      if (ntmGetForum()) { ntmTitleIn.focus(); return; }
      var first = ntmForumList && ntmForumList.querySelector('input[name="forum_id"]');
      (first || ntmTitleIn).focus();
    }

    function ntmShowOverlay(overrideForumId) {
      ntmOverlay.hidden = false;
      document.body.classList.add('ntm-active');
      // Heading mode: a fresh open is "New post". Edit mode sets ntmEditId BEFORE
      // calling us (ntmOpenForEdit), then overrides the heading to "Edit post" —
      // so leave it alone when already in edit mode. (Ian 6/17)
      var ntmHd0 = document.getElementById('ntm-heading');
      if (ntmHd0 && !ntmEditId) ntmHd0.textContent = 'New post';
      var ntmAnonChk = document.getElementById('ntm-anon-check');
      if (ntmAnonChk) ntmAnonChk.checked = false;   // anon toggle defaults off per post (Phase 1)
      // Retry auth whenever we're NOT already authed (idle/anon/loading). A single
      // transient auth.php failure used to flip state to 'anon' and, because the
      // guard only retried on 'idle', wedge the composer in Sign-in for the whole
      // page session even for a logged-in member (Buck 2026-06-11).
      if (ntmAuthState !== 'authed') {
        ntmLoadAuth(overrideForumId);
      } else if (overrideForumId) {
        ntmSetForum(overrideForumId);
      }
      setTimeout(ntmFocusEntry, 50);
    }

    // EDIT MODE — open the composer (and the 3-modal wizard, which is just a
    // presentation layer over #ntm-form) pre-filled to EDIT an existing topic; the
    // submit handler then PUTs instead of POSTing. Photos are preserved server-side
    // unless a NEW photo is added (bbp_media omitted), exactly like the desktop
    // topic edit. Exposed as window.lgNtmEditTopic for the mobile modal's OP edit.
    function ntmOpenForEdit(topicId, forumId, title, bodyHtml) {
      ntmEditId = parseInt(topicId, 10) || null;
      if (!ntmEditId) return;
      ntmTray.reset();                                 // clear any stale thumbs + new-upload ids
      ntmKeepMedia.length = 0; ntmEditHadMedia = false;
      ntmShowOverlay(parseInt(forumId, 10) || null);   // opens + preselects forum + inits editor
      var ntmHdE = document.getElementById('ntm-heading');
      if (ntmHdE) ntmHdE.textContent = 'Edit post';    // wizard says "Edit post" (Ian 6/17)
      if (ntmTitleIn) ntmTitleIn.value = title || '';
      if (ntmContentEl) ntmContentEl.value = (bodyHtml || '').replace(/<img[^>]*>/gi, '');
      var ntmSeedHtml = lgStripBodyImages(bodyHtml || '');
      var seedTries = 0;
      (function seed() {
        if (ntmQuill) { ntmQuill.root.innerHTML = ntmSeedHtml || '<p><br></p>'; }
        else if (++seedTries < 30) setTimeout(seed, 100);
      })();
      // Load the topic's EXISTING photos as removable thumbs so they can be deleted
      // during edit (the BB PUT can't touch media — see topic-media.php). Each ✕
      // drops the media id from ntmKeepMedia; on submit we send the kept set there.
      fetch('/bb-mirror-api/v0/topic-media?topic_id=' + ntmEditId, { credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) {
          if (!d || !d.ok || !d.media || ntmEditId !== parseInt(topicId, 10)) return;
          ntmEditHadMedia = d.media.length > 0;
          d.media.forEach(function (m) {
            ntmKeepMedia.push(m.media_id);
            ntmTray.addThumb(m.thumb || m.url, m.media_id, ntmKeepMedia);
          });
        })
        .catch(function () {});
    }
    window.lgNtmEditTopic = ntmOpenForEdit;

    function ntmHideOverlay() {
      ntmOverlay.hidden = true;
      document.body.classList.remove('ntm-active');
      ntmStatus.textContent = '';
      ntmEditId = null;                                 // exit edit mode
      ntmKeepMedia.length = 0; ntmEditHadMedia = false; // clear edit-media state
    }

    function ntmSetState(state) {
      ntmAuthState = state;
      ntmLoading.hidden = (state !== 'loading');
      ntmAnon.hidden    = (state !== 'anon');
      ntmForm.hidden    = (state !== 'authed');
    }

    function ntmLoadAuth(overrideForumId) {
      ntmSetState('loading');
      // Timeout so a hung auth.php doesn't strand the composer in 'loading'
      // forever — it falls to 'anon', which the open-guard above will retry.
      var ntmTimeout = new Promise(function (_, reject) { setTimeout(function () { reject('timeout'); }, 8000); });
      Promise.race([
        fetch('/bb-mirror-api/v0/auth.php', { credentials: 'same-origin' })
          .then(function (r) { return r.ok ? r.json() : Promise.reject('auth ' + r.status); }),
        ntmTimeout,
      ])
        .then(function (data) {
          if (!data.authenticated) { ntmSetState('anon'); return; }
          ntmNonce = data.nonce;
          ntmAuthorName = data.display_name || '';
          ntmSetState('authed');
          ntmInitEditor();
          // pre-select: explicit override (e.g. "Post here" button) > data attr from URL
          var presel = overrideForumId || parseInt(ntmForm.dataset.currentForum, 10);
          if (presel > 0) ntmSetForum(presel);
          setTimeout(ntmFocusEntry, 30);
        })
        .catch(function () { ntmSetState('anon'); });
    }

    // Pull body HTML from Quill (or textarea fallback), stripping preview <img>
    // tags — those images are stored natively via bbp_media and rendered by the mirror.
    function ntmGetContent() {
      var html;
      if (ntmQuill) {
        html = ntmQuill.root.innerHTML;
        if (html === '<p><br></p>') html = '';
      } else {
        html = (ntmContentEl.value || '').trim();
      }
      // strip inline preview images (bbp_media carries the real ones)
      html = html.replace(/<img[^>]*>/gi, '');
      // collapse emptied paragraphs
      html = html.replace(/<p>\s*<\/p>/gi, '').trim();
      return html;
    }

    if (ntmOpen) ntmOpen.addEventListener('click', function () { ntmShowOverlay(null); });
    ntmCancel.addEventListener('click', ntmHideOverlay);
    ntmBackdrop.addEventListener('click', ntmHideOverlay);

    // ── Composer deep-link: /hub/?compose[=<forum-slug>] ────────────────────
    // Off-hub surfaces (front-page What's-New CTAs) land here instead of the
    // legacy WP new-post pages: the composer opens on arrival, preselected to
    // the slug's forum when given (e.g. ?compose=suggestion-box-bug-reporting).
    (function () {
      var m = /[?&]compose(?:=([^&#]*))?(?:&|#|$)/.exec(location.search);
      if (!m) return;
      var slug = decodeURIComponent(m[1] || '');
      var id = null;
      if (slug && ntmForumList) {
        var r = ntmForumList.querySelector('input[name="forum_id"][data-slug="' + slug.replace(/"/g, '') + '"]');
        if (r) id = parseInt(r.value, 10);
      }
      ntmShowOverlay(id);
    })();

    // ── Post-submit landing: /hub/<forum>/?new=<topicId> → scroll the new post's
    // card to center + briefly highlight it, on BOTH desktop and mobile (Ian 6/17:
    // land on the hub centered on the post you just made). Feed may still be
    // rendering → retry briefly.
    (function () {
      var nm = /[?&]new=(\d+)/.exec(location.search);
      if (!nm) return;
      var nid = nm[1], tries = 0;
      (function find() {
        var card = document.querySelector('[data-topic-id="' + nid + '"]');
        if (card) {
          card.scrollIntoView({ block: 'center', behavior: 'smooth' });
          card.style.transition = 'box-shadow .3s';
          card.style.boxShadow = '0 0 0 3px var(--lguser-accent,#52613d)';
          setTimeout(function () { card.style.boxShadow = ''; }, 2400);
          return;
        }
        if (++tries < 20) setTimeout(find, 150);
      })();
    })();

    // ── Quick-add workflow tags (councilyes / weeklyyes) ─────────────────────
    // Each button toggles its tag in/out of the comma-separated #ntm-tags field.
    var ntmQuickTags = document.getElementById('ntm-quicktags');
    var ntmTagsIn    = document.getElementById('ntm-tags');
    function ntmTagList() {
      return (ntmTagsIn.value || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean);
    }
    function ntmSyncQuickTags() {
      if (!ntmQuickTags) return;
      var have = ntmTagList().map(function (s) { return s.toLowerCase(); });
      [].forEach.call(ntmQuickTags.querySelectorAll('.ntm-qtag'), function (b) {
        b.classList.toggle('is-on', have.indexOf(b.dataset.tag.toLowerCase()) > -1);
      });
    }
    if (ntmQuickTags && ntmTagsIn) {
      ntmQuickTags.addEventListener('click', function (e) {
        var btn = e.target.closest('.ntm-qtag');
        if (!btn) return;
        var tag = btn.dataset.tag;
        var list = ntmTagList();
        var i = list.map(function (s) { return s.toLowerCase(); }).indexOf(tag.toLowerCase());
        if (i > -1) list.splice(i, 1); else list.push(tag);
        ntmTagsIn.value = list.join(', ');
        ntmSyncQuickTags();
      });
      // Keep buttons in sync if the user edits the tags field directly.
      ntmTagsIn.addEventListener('input', ntmSyncQuickTags);
    }

    // Any element with [data-ntm-open] opens the modal (e.g. forum header "Post here" button).
    // If it carries data-forum-id, override the pre-selected forum.
    document.addEventListener('click', function (e) {
      var trigger = e.target.closest('[data-ntm-open]');
      if (!trigger || trigger === ntmOpen) return;
      var forumId = trigger.dataset.forumId ? parseInt(trigger.dataset.forumId, 10) : null;
      ntmShowOverlay(forumId);
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !ntmOverlay.hidden) ntmHideOverlay();
    });

    ntmForm.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!ntmNonce) { ntmStatus.textContent = 'Not signed in.'; return; }
      var forum   = ntmGetForum();
      var forumId = forum && forum.id;
      var title   = ntmTitleIn.value.trim();
      var content = ntmGetContent();
      if (!forumId) {
        ntmStatus.textContent = 'Please choose a forum.';
        var firstRow = ntmForumList && ntmForumList.querySelector('input[name="forum_id"]');
        if (firstRow) firstRow.focus();
        return;
      }
      if (!title)   { ntmStatus.textContent = 'Title is required.'; ntmTitleIn.focus(); return; }

      ntmSubmit.disabled = true;
      ntmStatus.textContent = ntmEditId ? 'Saving…' : 'Posting…';

      var payload = { parent: forumId, title: title };
      if (content) payload.content = content;
      // NEW posts attach media via bbp_media on create. On EDIT the BB PUT ignores
      // media entirely (topic-media.php owns add/remove), so don't send it here.
      if (!ntmEditId && ntmMediaIds.length) payload.bbp_media = ntmMediaIds;
      var tagsEl = document.getElementById('ntm-tags');
      var tags = tagsEl && tagsEl.value.trim();
      if (tags) payload.topic_tags = tags;
      // Post anonymously (anon-rebuild lane): server stamps _lg_anon meta on the
      // new topic → forums.topic.is_anon → masked render for non-moderators.
      var ntmAnonChk = document.getElementById('ntm-anon-check');
      if (ntmAnonChk && ntmAnonChk.checked) payload._lg_anon = 1;
      if (ntmEditId) payload.id = ntmEditId;   // edit existing topic (PUT) vs create (POST)

      fetch(ntmRestBase + '/topics' + (ntmEditId ? '/' + ntmEditId : ''), {
        method: ntmEditId ? 'PUT' : 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': ntmNonce },
        body: JSON.stringify(payload),
      })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
          if (res.ok) {
            if (ntmEditId) {   // edited an existing topic → save + refresh in place
              ntmStatus.textContent = 'Saved!';
              var editId   = ntmEditId;
              var finishEdit = function () {
                setTimeout(function () { ntmHideOverlay(); try { location.reload(); } catch (e) {} }, 400);
              };
              // Sync the photo set (kept existing + newly added) via the endpoint
              // that owns forum-media edits — the PUT above can't. Only when the
              // topic had photos OR new ones were added; else nothing to do.
              if (ntmEditHadMedia || ntmMediaIds.length) {
                ntmStatus.textContent = 'Saving photos…';
                fetch('/bb-mirror-api/v0/topic-media', {
                  method: 'POST', credentials: 'same-origin',
                  headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': ntmNonce },
                  body: JSON.stringify({ topic_id: editId, keep_media_ids: ntmKeepMedia, add_upload_ids: ntmMediaIds }),
                }).then(function () { ntmStatus.textContent = 'Saved!'; finishEdit(); }, finishEdit);
              } else {
                finishEdit();
              }
              return;
            }
            // Land on the ROOT /hub/ feed filtered to the POSTER's own activity,
            // newest-first, so their brand-new post leads (Ian 6/17). NOT the
            // forum-scoped /hub/<slug>/ page (reads as the "legacy" page) and NOT
            // the single-topic permalink. ?author= is name-keyed and must match
            // topic.author_name — which is the viewer's WP display_name, exactly
            // what auth.php returned into ntmAuthorName. Newest sort because the Hub
            // root defaults to Random and otherwise honors a saved sort cookie.
            // Anonymous posts are masked → DON'T add ?author= (it wouldn't match the
            // masked card AND would leak the real name in the URL); just land newest.
            // Stash the dest FIRST so the mobile overlay (hub-polish status-watcher)
            // navigates to the SAME target instead of bare /hub/.
            var newId   = res.j && res.j.id;
            var pubPath = ntmForm.dataset.publicPath || FORUM_BASE;
            var postedAnon = !!(ntmAnonChk && ntmAnonChk.checked);
            var dest = pubPath + '/?sort=new';
            if (ntmAuthorName && !postedAnon) dest += '&author=' + encodeURIComponent(ntmAuthorName);
            if (newId) dest += '&new=' + newId;
            window.__lgPostDest = dest;
            ntmStatus.textContent = 'Posted! Redirecting…';
            setTimeout(function () { window.location.href = dest; }, 600);
            return;
          }
          var msg = (res.j && (res.j.message || res.j.code)) || 'Unknown error';
          ntmStatus.textContent = 'Error: ' + msg;
          ntmSubmit.disabled = false;
        })
        .catch(function (err) {
          ntmStatus.textContent = 'Network error: ' + err.message;
          ntmSubmit.disabled = false;
        });
    });
  }

  // ── 4b. Feed reply modal ────────────────────────────────────────────────────
  // A feed card's "Reply" button pops this modal (mirrors the new-topic modal),
  // wired to that card's topic + forum. Posts a top-level reply via BB REST, then
  // drops an optimistic stub into the card. Lazy auth + nonce, like the ntm modal.
  // MUST live above the `.reply-form-wrap` early-return below — the feed has no
  // reply-form-wrap, so anything after that return never runs on the feed.
  var frmOverlay = document.getElementById('frm-overlay');
  if (frmOverlay) {
    var frmBackdrop = document.getElementById('frm-backdrop');
    var frmCancel   = document.getElementById('frm-cancel');
    var frmForm     = document.getElementById('frm-form');
    var frmContent  = document.getElementById('frm-content');
    var frmTopicId  = document.getElementById('frm-topic-id');
    var frmForumId  = document.getElementById('frm-forum-id');
    var frmStatus   = document.getElementById('frm-status');
    var frmSubmit   = document.getElementById('frm-submit');
    var frmLoading  = document.getElementById('frm-loading');
    var frmAnon     = document.getElementById('frm-anon');
    var frmContext  = document.getElementById('frm-context');
    var frmCtxTitle = frmContext && frmContext.querySelector('.frm-context__title');
    var frmRestBase = frmForm.dataset.restBase || '/wp-json/buddyboss/v1';

    var frmNonce = null, frmName = 'You', frmState = 'idle', frmCard = null;
    var frmEditReplyId = 0;                                   // >0 ⇒ editing that reply (PUT) vs creating (POST)
    var frmEditTopicId = 0;                                   // >0 ⇒ editing that TOPIC/OP (title+body) via this composer
    var frmEditTopicForumId = 0;                              // forum of the topic under edit (for refresh/context)
    var frmTopicHadMedia = false;                             // topic-edit: did the OP already have photos? (gates the media sync + reload)
    var frmSubmitLabel = (frmSubmit && frmSubmit.textContent.trim()) || 'Post reply';

    // Title row + body label + heading — used to repurpose this reply composer as
    // the OP editor (lgFrmEditTopic): the title field shows only in topic-edit mode.
    var frmTitleWrap  = document.getElementById('frm-title-wrap');
    var frmTitleInput = document.getElementById('frm-title');
    var frmBodyLabel  = document.getElementById('frm-body-label');
    var frmHeading    = document.getElementById('frm-heading');
    function frmShowTitle(show) {
      if (frmTitleWrap) frmTitleWrap.hidden = !show;
      if (frmBodyLabel) frmBodyLabel.innerHTML = show
        ? 'Body <span class="ntm-label__opt">(formatting, images &amp; links)</span>'
        : 'Your reply <span class="ntm-label__opt">(formatting, images &amp; links)</span>';
    }

    var frmEditorEl = document.getElementById('frm-editor');
    var frmQuill    = null;     // lazy Quill instance (same editor as new-topic)
    var frmMediaIds = [];       // bbp_media upload_ids for this reply
    var frmMediaPreviews = [];  // preview URLs, for the optimistic stub (no refresh)
    var frmKeepMedia = [];      // edit mode: existing bp_media.id to KEEP (✕ removes)
    var frmEditMediaLoaded = false; // edit mode: did we load the existing photo set?
    var frmParentId = 0;        // reply_to: set when replying to a specific reply (nested)
    var frmMentionSlug = '';    // BB nicename to auto-@mention (reply-to-reply only)

    function frmFocus() { if (frmQuill) frmQuill.focus(); else if (frmContent) frmContent.focus(); }

    // Seed the composer with a leading "@slug " when replying to a specific reply.
    // BuddyBoss parses @nicename on save into a real mention + notification, so this
    // is the whole feature — no client-side mention markup needed.
    function frmSeedMention() {
      if (!frmMentionSlug) return;
      var m = '@' + frmMentionSlug + ' ';
      if (frmQuill) { frmQuill.setText(m); frmQuill.setSelection(m.length, 0); }
      else if (frmContent) { frmContent.value = m; frmContent.selectionStart = frmContent.selectionEnd = m.length; }
    }
    function frmReady() { frmSeedMention(); frmFocus(); }

    // Lazy-init Quill; fall back to the plain textarea if the CDN didn't load.
    function frmInitEditor() {
      if (frmQuill || !frmEditorEl) return;
      if (typeof Quill === 'undefined') {
        if (frmContent) frmContent.hidden = false;
        frmEditorEl.style.display = 'none';
        return;
      }
      frmQuill = new Quill(frmEditorEl, {
        theme: 'snow',
        placeholder: 'Share your thoughts…',
        // Clamp the link/format tooltip inside the editor so it can't fly off the
        // modal's left edge (Quill positions .ql-tooltip relative to `bounds`).
        bounds: frmEditorEl,
        modules: { toolbar: {
          container: [
            [{ header: [2, 3, false] }],
            ['bold', 'italic', 'underline'],
            ['blockquote', 'code-block'],
            [{ list: 'ordered' }, { list: 'bullet' }],
            ['link', 'image'],
            ['clean'],
          ],
          handlers: { image: frmImageHandler },
        } },
      });
      lgQuillNoAutofill(frmEditorEl);
    }

    // Image button → upload to BB media → tray thumb (desktop) / inline (mobile).
    var frmTray = lgComposerTray({
      editorEl: frmEditorEl,
      mediaIds: frmMediaIds,
      statusEl: frmStatus,
      restBase: frmRestBase,
      getNonce: function (cb) { cb(frmNonce); },
      insertInline: function (url) {
        var range = frmQuill.getSelection(true);
        frmQuill.insertEmbed(range ? range.index : 0, 'image', url);
      },
    });
    function frmImageHandler() { frmTray.handler(); }

    // Body HTML from Quill (or textarea), stripping preview <img> — the real
    // images ride along as bbp_media and are rendered by the mirror.
    function frmGetContent() {
      var html = frmQuill ? frmQuill.root.innerHTML : (frmContent.value || '').trim();
      if (html === '<p><br></p>') html = '';
      html = html.replace(/<img[^>]*>/gi, '').replace(/<p>\s*<\/p>/gi, '').trim();
      return html;
    }

    function frmResetEditor() {
      frmTray.reset();          // clears frmMediaIds (in place) + the tray thumbs
      frmKeepMedia.length = 0; frmEditMediaLoaded = false;   // clear edit-media state
      frmMediaPreviews = [];
      if (frmQuill) frmQuill.setText('');
      else if (frmContent) frmContent.value = '';
      var frmAnonChk = document.getElementById('frm-anon-check');
      if (frmAnonChk) frmAnonChk.checked = false;   // anon toggle defaults off per reply (Phase 1)
    }

    function frmSetState(s) {
      frmState = s;
      frmLoading.hidden = (s !== 'loading');
      frmAnon.hidden    = (s !== 'anon');
      frmForm.hidden    = (s !== 'authed');
    }
    function frmLoadAuth() {
      frmSetState('loading');
      fetch('/bb-mirror-api/v0/auth.php', { credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : Promise.reject('auth'); })
        .then(function (d) {
          if (!d.authenticated) { frmSetState('anon'); return; }
          frmNonce = d.nonce; frmName = d.display_name || 'You';
          frmSetState('authed');
          frmInitEditor();
          setTimeout(frmReady, 30);
        })
        .catch(function () { frmSetState('anon'); });
    }
    function frmOpen(trigger) {
      frmEditReplyId = 0;                                     // create mode (not edit)
      frmEditTopicId = 0; frmEditTopicForumId = 0;            // not a topic edit
      frmShowTitle(false);                                    // replies have no title field
      if (frmHeading) frmHeading.textContent = 'Reply';
      if (frmSubmit) frmSubmit.textContent = frmSubmitLabel;
      // The card is the trigger's ancestor (used for the optimistic stub + to
      // source topic/forum when the trigger is a per-reply button).
      frmCard = trigger.closest('.feed-card');
      var replyTo = parseInt(trigger.dataset.replyTo, 10) || 0;
      frmParentId = replyTo;
      frmMentionSlug = (replyTo && trigger.dataset.replyToSlug) ? trigger.dataset.replyToSlug : '';
      // A per-reply "Reply" button only carries reply-to/-author; topic + forum
      // live on the card's top-level reply CTA. The card CTA carries them directly.
      var src = trigger;
      if (replyTo && frmCard) {
        var cta = frmCard.querySelector('.feed-card__reply-cta[data-frm-open]');
        if (cta) src = cta;
      }
      frmTopicId.value = src.dataset.topicId || (frmCard && frmCard.dataset.topicId) || '';
      frmForumId.value = src.dataset.forumId || '';
      var title = src.dataset.topicTitle || '';
      if (frmCtxTitle) {
        if (replyTo) {
          frmCtxTitle.textContent = '↩ Replying to ' + (trigger.dataset.replyToAuthor || 'a reply') + (title ? ' · ' + title : '');
          frmContext.hidden = false;
        } else if (title) { frmCtxTitle.textContent = title; frmContext.hidden = false; }
        else if (frmContext) { frmContext.hidden = true; }
      }
      frmStatus.textContent = '';
      frmResetEditor();
      frmOverlay.hidden = false;
      document.body.classList.add('ntm-active');
      if (frmState !== 'authed') frmLoadAuth();
      else { frmInitEditor(); setTimeout(frmReady, 30); }
    }
    function frmClose() {
      frmOverlay.hidden = true;
      document.body.classList.remove('ntm-active');
      frmStatus.textContent = '';
      frmEditReplyId = 0;                                     // exit edit mode
      frmEditTopicId = 0; frmEditTopicForumId = 0;            // exit topic-edit mode
      frmShowTitle(false);
      if (frmHeading) frmHeading.textContent = 'Reply';
      if (frmTitleInput) frmTitleInput.value = '';
      if (frmSubmit) frmSubmit.textContent = frmSubmitLabel;  // restore "Post reply"
    }

    // EDIT MODE — open this SAME composer (Quill modal) pre-filled to edit an
    // existing reply, instead of the inline box (Ian 2026-06-25: "pop open the same
    // quill editor used to make the reply"). Submit then PUTs vs POSTs. Exposed for
    // the discussion-modal Edit button (dmReplyEdit). Mirrors lgNtmEditTopic.
    window.lgFrmEditReply = function (replyId, bodyHtml) {
      replyId = parseInt(replyId, 10) || 0;
      if (!replyId) return;
      frmEditReplyId = replyId;
      frmEditTopicId = 0; frmEditTopicForumId = 0;            // reply edit, not topic
      frmShowTitle(false);                                    // replies have no title field
      if (frmHeading) frmHeading.textContent = 'Edit reply';
      frmParentId = 0; frmMentionSlug = ''; frmCard = null;
      if (frmCtxTitle) { frmCtxTitle.textContent = '✎ Editing your reply'; frmContext.hidden = false; }
      if (frmTopicId) frmTopicId.value = '';
      if (frmForumId) frmForumId.value = '';
      frmStatus.textContent = '';
      frmResetEditor();
      frmOverlay.hidden = false;
      document.body.classList.add('ntm-active');
      if (frmSubmit) frmSubmit.textContent = 'Save';
      // Load the reply's existing photos as removable thumbs (✕ drops the id from
      // frmKeepMedia; on save we send the kept set so dropped photos are deleted).
      fetch('/bb-mirror-api/v0/reply?reply_id=' + replyId, { credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) {
          if (!d || !d.ok || !d.media || frmEditReplyId !== replyId) return;
          frmEditMediaLoaded = true;
          d.media.forEach(function (m) { frmKeepMedia.push(m.media_id); frmTray.addThumb(m.thumb || m.url, m.media_id, frmKeepMedia); });
        })
        .catch(function () {});
      var frmSeedHtml = lgStripBodyImages(bodyHtml || '');
      var seedTries = 0;
      function frmSeedBody() {
        if (frmQuill) {
          frmQuill.setContents([]);                              // clear placeholder/blank state
          frmQuill.clipboard.dangerouslyPasteHTML(frmSeedHtml || '');
          frmQuill.setSelection(frmQuill.getLength(), 0);
          frmQuill.focus();
          return;
        }
        // Quill is the editor we actually show; WAIT for it to init before falling
        // back to the plain textarea — #frm-content always exists in the DOM, so the
        // old `else if (frmContent)` seeded the HIDDEN textarea and left Quill empty
        // (the "edit text doesn't populate" bug, Ian 2026-06-25).
        if (typeof Quill !== 'undefined' && ++seedTries < 50) { setTimeout(frmSeedBody, 60); return; }
        if (frmContent) { frmContent.value = (bodyHtml || '').replace(/<img[^>]*>/gi, ''); }
      }
      if (frmState !== 'authed') frmLoadAuth(); else frmInitEditor();
      frmSeedBody();
    };

    // EDIT MODE (TOPIC/OP) — open this SAME Quill composer to edit the OP, unified
    // with the reply edit above (Ian 2026-06-25: "new edit" — OP uses the same
    // composer as replies, not the new-topic wizard). The title field shows here;
    // submit PUTs the owned reply.php topic-edit path and (when photos changed)
    // POSTs topic-media.php — exactly the endpoints the wizard used. Mirrors
    // lgFrmEditReply; exposed for the discussion-modal OP Edit button.
    window.lgFrmEditTopic = function (topicId, forumId, title, bodyHtml) {
      topicId = parseInt(topicId, 10) || 0;
      if (!topicId) return;
      frmEditTopicId = topicId;
      frmEditTopicForumId = parseInt(forumId, 10) || 0;
      frmTopicHadMedia = false;                              // reset; set true below if the GET finds photos
      frmEditReplyId = 0;                                     // topic edit, not reply
      frmParentId = 0; frmMentionSlug = ''; frmCard = null;
      frmShowTitle(true);                                     // OP edit shows the title field
      if (frmHeading) frmHeading.textContent = 'Edit post';
      if (frmCtxTitle) { frmCtxTitle.textContent = '✎ Editing your post'; frmContext.hidden = false; }
      if (frmTitleInput) frmTitleInput.value = title || '';
      if (frmTopicId) frmTopicId.value = '';
      if (frmForumId) frmForumId.value = String(frmEditTopicForumId || '');
      frmStatus.textContent = '';
      frmResetEditor();
      frmOverlay.hidden = false;
      document.body.classList.add('ntm-active');
      if (frmSubmit) frmSubmit.textContent = 'Save';
      // Load the topic's existing photos as removable thumbs (same tray/keep-set
      // machinery as the reply edit), via the endpoint that owns topic media.
      fetch('/bb-mirror-api/v0/topic-media?topic_id=' + topicId, { credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) {
          if (!d || !d.ok || !d.media || frmEditTopicId !== topicId) return;
          frmEditMediaLoaded = true;
          if (d.media.length) frmTopicHadMedia = true;        // had photos → sync removals + reload on save
          d.media.forEach(function (m) { frmKeepMedia.push(m.media_id); frmTray.addThumb(m.thumb || m.url, m.media_id, frmKeepMedia); });
        })
        .catch(function () {});
      var frmTopicSeedHtml = lgStripBodyImages(bodyHtml || '');
      var seedTries = 0;
      function frmSeedTopicBody() {
        if (frmQuill) {
          frmQuill.setContents([]);
          frmQuill.clipboard.dangerouslyPasteHTML(frmTopicSeedHtml || '');
          frmQuill.setSelection(frmQuill.getLength(), 0);
          return;
        }
        if (typeof Quill !== 'undefined' && ++seedTries < 50) { setTimeout(frmSeedTopicBody, 60); return; }
        if (frmContent) { frmContent.value = (bodyHtml || '').replace(/<img[^>]*>/gi, ''); }
      }
      if (frmState !== 'authed') frmLoadAuth(); else frmInitEditor();
      frmSeedTopicBody();
      // Title is the natural first field on an OP edit — focus it once visible.
      setTimeout(function () { if (frmTitleInput && !frmTitleWrap.hidden) frmTitleInput.focus(); }, 40);
    };

    // Reflect an OP edit across the surfaces that may be showing it WITHOUT a
    // reload (text-only): the open discussion modal's OP + every matching feed
    // card's title/excerpt. Photo changes can't be shown in place, so those callers
    // reload instead (see the submit handler).
    function frmUpdateTopicInPlace(topicId, newTitle, newHtml) {
      var dm = document.getElementById('lg-dmodal');
      if (dm && !dm.hidden) {
        var dmT = dm.querySelector('.lg-dmodal__title'); if (dmT && newTitle) dmT.textContent = newTitle;
        var dmB = dm.querySelector('.lg-dmodal__body');
        if (dmB && newHtml != null) { dmB.innerHTML = newHtml; if (window.bbProcessEmbeds) window.bbProcessEmbeds(dmB); }
      }
      Array.prototype.forEach.call(document.querySelectorAll('.feed-card[data-topic-id="' + topicId + '"]'), function (card) {
        if (newTitle) {
          var tA = card.querySelector('.fc-title a, .feed-card__title a, .fc-title, .feed-card__title');
          if (tA) tA.textContent = newTitle;
        }
        if (newHtml != null) {
          var ex = card.querySelector('.feed-card__op-excerpt, .fc-excerpt');
          if (ex) { ex.innerHTML = newHtml; if (window.bbProcessEmbeds) window.bbProcessEmbeds(ex); }
          var full = card.querySelector('.feed-card__full-body[data-loaded]');
          if (full) { full.innerHTML = newHtml; if (window.bbProcessEmbeds) window.bbProcessEmbeds(full); }
        }
      });
    }

    // Delegated so it also works on lazily-loaded / optimistically-added cards.
    document.addEventListener('click', function (e) {
      var t = e.target.closest('.feed-card__reply-cta[data-frm-open], .reply-stub__reply, .fc-composer__rich');
      if (!t) return;
      e.stopPropagation();
      frmOpen(t);
    });
    frmCancel.addEventListener('click', frmClose);
    frmBackdrop.addEventListener('click', frmClose);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !frmOverlay.hidden) frmClose();
    });

    frmForm.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!frmNonce) { frmStatus.textContent = 'Not signed in.'; return; }
      var content = frmGetContent();
      var topicId = parseInt(frmTopicId.value, 10);
      var forumId = parseInt(frmForumId.value, 10);
      // EDIT path (TOPIC/OP): the unified composer — title+body PUT the owned
      // reply.php topic-edit endpoint (author-or-mod, IDOR-proof), and photos sync
      // via topic-media.php when they changed (the SAME endpoints the new-topic
      // wizard used). Must come BEFORE the generic empty-check (which is reply copy).
      if (frmEditTopicId > 0) {
        var tEditId = frmEditTopicId;
        var tTitle  = (frmTitleInput && frmTitleInput.value.trim()) || '';
        if (!content) { frmStatus.textContent = "Post can't be empty."; frmFocus(); return; }
        if (!tTitle)  { frmStatus.textContent = 'Title is required.'; if (frmTitleInput) frmTitleInput.focus(); return; }
        frmSubmit.disabled = true; frmStatus.textContent = 'Saving…';
        var tAdded = frmMediaIds.slice();                      // new uploads added during edit
        var tKeep  = frmKeepMedia.slice();                     // existing photos to keep
        // Sync (+reload) only when the OP actually has/added photos — a text-only
        // edit of a photo-less OP updates in place. (frmEditMediaLoaded is true even
        // for a zero-photo GET, so gate on frmTopicHadMedia instead.)
        var tMediaChanged = tAdded.length > 0 || frmTopicHadMedia;
        fetch('/bb-mirror-api/v0/reply', {
          method: 'PUT', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': frmNonce },
          body: JSON.stringify({ topic_id: tEditId, title: tTitle, content: content }),
        })
          .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }, function () { return { ok: r.ok, j: {} }; }); })
          .then(function (res) {
            if (!res.ok) { frmStatus.textContent = 'Error: ' + ((res.j && (res.j.message || res.j.error)) || 'failed'); frmSubmit.disabled = false; return; }
            var freshTitle = (res.j && res.j.title) || tTitle;
            var freshHtml  = (res.j && res.j.content_html) || content;
            if (tMediaChanged) {
              // New/removed photos can't be reflected by the in-place text update,
              // so sync them then RELOAD — exactly what the wizard edit did.
              frmStatus.textContent = 'Saving photos…';
              var finishReload = function () { try { location.reload(); } catch (e) { frmSubmit.disabled = false; frmClose(); } };
              fetch('/bb-mirror-api/v0/topic-media', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': frmNonce },
                body: JSON.stringify({ topic_id: tEditId, keep_media_ids: tKeep, add_upload_ids: tAdded }),
              }).then(finishReload, finishReload);
            } else {
              frmUpdateTopicInPlace(tEditId, freshTitle, freshHtml);
              frmSubmit.disabled = false;
              frmClose();
            }
          })
          .catch(function (err) { frmStatus.textContent = 'Network error: ' + err.message; frmSubmit.disabled = false; });
        return;
      }
      if (!content && !frmMediaIds.length) { frmStatus.textContent = "Reply can't be empty."; frmFocus(); return; }
      // EDIT path: PUT the owned endpoint (author-or-mod), update the reply in place.
      if (frmEditReplyId > 0) {
        if (!content) { frmStatus.textContent = "Reply can't be empty."; frmFocus(); return; }
        frmSubmit.disabled = true; frmStatus.textContent = 'Saving…';
        var editId = frmEditReplyId;
        var editPayload = { reply_id: editId, content: content };
        var addedMedia = frmMediaIds.slice();                  // new uploads added during edit
        if (addedMedia.length) editPayload.media_ids = addedMedia;
        // If we loaded the existing photo set, send the kept ids so any the user
        // ✕'d are removed server-side (omit when we couldn't load it → keep all).
        if (frmEditMediaLoaded) editPayload.keep_media_ids = frmKeepMedia.slice();
        var mediaChanged = addedMedia.length > 0 || frmEditMediaLoaded;
        fetch('/bb-mirror-api/v0/reply', {
          method: 'PUT', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': frmNonce },
          body: JSON.stringify(editPayload),
        })
          .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }, function () { return { ok: r.ok, j: {} }; }); })
          .then(function (res) {
            if (!res.ok) { frmStatus.textContent = 'Error: ' + ((res.j && (res.j.message || res.j.error)) || 'failed'); frmSubmit.disabled = false; return; }
            var fresh = (res.j && res.j.content_html) || content;
            Array.prototype.forEach.call(document.querySelectorAll('.reply-stub[data-reply-id="' + editId + '"]'), function (st) {
              var ex = st.querySelector('.reply-stub__excerpt') || st.querySelector('.reply-stub__body');
              if (ex) ex.innerHTML = fresh;
              var eb = st.querySelector('.reply-stub__edit'); if (eb) eb.setAttribute('data-reply-raw', fresh);
            });
            // Photo add/remove changes attachments (not inline body) — the text-only
            // in-place update can't reflect them, so reload the discussion-modal thread.
            if (mediaChanged) {
              var dm = document.getElementById('lg-dmodal');
              var rs = document.getElementById('looth-rep-sheet');   // mobile sheet
              var dtid = (dm && !dm.hidden && dm.dataset.topicId) ? dm.dataset.topicId
                       : (rs && rs.classList.contains('is-open') && rs.getAttribute('data-tid')) ? rs.getAttribute('data-tid') : '';
              if (dtid) { try { document.dispatchEvent(new CustomEvent('lg:reply-posted', { detail: { topicId: parseInt(dtid, 10) } })); } catch (e) {} }
            }
            frmSubmit.disabled = false;
            frmClose();
          })
          .catch(function (err) { frmStatus.textContent = 'Network error: ' + err.message; frmSubmit.disabled = false; });
        return;
      }
      if (!topicId) { frmStatus.textContent = 'Missing topic.'; return; }
      frmSubmit.disabled = true; frmStatus.textContent = 'Posting…';
      var frmPayload = { topic_id: topicId, forum_id: forumId };
      if (content) frmPayload.content = content;
      if (frmMediaIds.length) frmPayload.bbp_media = frmMediaIds;
      if (frmParentId) frmPayload.reply_to = frmParentId;   // nested reply
      // Reply anonymously (anon-rebuild lane): server stamps _lg_anon on the reply.
      var frmAnonChk = document.getElementById('frm-anon-check');
      if (frmAnonChk && frmAnonChk.checked) frmPayload._lg_anon = 1;
      fetch(frmRestBase + '/reply', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': frmNonce },
        body: JSON.stringify(frmPayload),
      })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
          if (!res.ok) {
            frmStatus.textContent = 'Error: ' + ((res.j && (res.j.message || res.j.code)) || 'failed');
            frmSubmit.disabled = false; return;
          }
          if (frmParentId) {
            frmRefreshThread(frmCard);                      // nested: reload thread so it nests in place
          } else {
            frmAppendOptimistic(frmCard, frmName, content); // images come from frmMediaPreviews
          }
          // Announce for surfaces with no .feed-card ancestor (discussion modal §4e).
          try {
            document.dispatchEvent(new CustomEvent('lg:reply-posted', { detail: { topicId: topicId } }));
          } catch (err) {}
          frmResetEditor();
          frmSubmit.disabled = false;
          frmClose();
        })
        .catch(function (err) { frmStatus.textContent = 'Network error: ' + err.message; frmSubmit.disabled = false; });
    });

    function frmEsc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
    function frmAppendOptimistic(card, name, content) {
      if (!card) return;
      var wrapEl = card.querySelector('.feed-card__replies');
      if (!wrapEl) {
        wrapEl = document.createElement('div');
        wrapEl.className = 'feed-card__replies';
        card.insertBefore(wrapEl, card.querySelector('.feed-card__actions') || null);
      }
      var text = content.replace(/<[^>]*>/g, '').trim();
      var initial = (name || 'Y').charAt(0).toUpperCase();
      // Render the just-uploaded image(s) so the reply shows complete without a
      // refresh. upload_thumb is a cookie-gated BB preview URL; the browser holds
      // the gate cookie, so it loads. Same .reply-stub__img markup as the server.
      var imgsHtml = frmMediaPreviews.map(function (u) {
        return '<img class="reply-stub__img" src="' + frmEsc(u) + '" alt="" loading="lazy">';
      }).join('');
      var textHtml = text ? '<span class="reply-stub__excerpt">' + frmEsc(text.slice(0, 160)) + '</span>' : '';
      var stub = document.createElement('div');
      stub.className = 'reply-stub reply-stub--mine';
      stub.innerHTML =
        '<div class="reply-stub__head">' +
          '<span class="avatar-init" style="width:28px;height:28px;font-size:12px;background:#6b7c52" aria-hidden="true">' + frmEsc(initial) + '</span>' +
          '<span class="reply-stub__author">' + frmEsc(name) + '</span>' +
          '<time class="reply-stub__time">now</time>' +
        '</div>' +
        '<div class="reply-stub__body">' + textHtml + imgsHtml + '</div>';
      // Make the new reply the single teaser (drop prior teaser stubs so they
      // don't stack), then bump the count and ensure the "View N replies" button
      // so the thread stays navigable — the bug where inline replies never
      // triggered the expand button.
      Array.prototype.forEach.call(wrapEl.querySelectorAll(':scope > .reply-stub'), function (s) { s.remove(); });
      wrapEl.insertBefore(stub, wrapEl.firstChild);

      var full = wrapEl.querySelector('.feed-card__replies-full');
      if (!full) { full = document.createElement('div'); full.className = 'feed-card__replies-full'; full.hidden = true; wrapEl.appendChild(full); }
      full.dataset.loaded = ''; full.innerHTML = '';   // stale: re-fetch (incl. new reply) on next expand
      card.classList.remove('replies-expanded'); full.hidden = true;

      // Keep the thread expandable if there was already an expand button. topic
      // reply_count lags in pg, so don't trust a count — if a button exists,
      // leave it (it lazy-loads the real thread, which includes the new reply).
      if (!wrapEl.querySelector('.feed-card__expand')) {
        // none yet: the new reply may be the only one, OR pg just hasn't surfaced
        // the count. Add a generic opener — expanding fetches the true thread.
        var exp = document.createElement('button');
        exp.className = 'feed-card__expand'; exp.type = 'button';
        exp.dataset.topicId = card.dataset.topicId || '';
        exp.textContent = 'View replies \u25be';
        wrapEl.appendChild(exp);
      }
    }

    // Nested reply: the optimistic teaser path would misrepresent depth, so just
    // refresh the thread. Reload now if it's open (keeps it open, reply nested in
    // place); otherwise mark stale so the next expand re-fetches.
    function frmRefreshThread(card) {
      if (!card) return;
      var full = card.querySelector('.feed-card__replies-full');
      if (!full) return;
      full.dataset.loaded = '';
      if (card.classList.contains('replies-expanded')) {
        fetch(FORUM_BASE + '/?replies=' + card.dataset.topicId)
          .then(function (r) { return r.ok ? r.text() : Promise.reject(new Error('fetch')); })
          .then(function (html) { full.innerHTML = html; full.dataset.loaded = '1'; })
          .catch(function () { /* leave stale; next expand re-fetches */ });
      } else {
        full.innerHTML = '';
      }
    }
  }

  // ── 2e. "Load more replies" — append the next page of top-level threads ─────
  document.addEventListener('click', function (e) {
    var b = e.target.closest('.replies-loadmore');
    if (!b) return;
    var host = b.closest('.feed-card__replies-full');
    if (!host) return;
    var orig = b.textContent;
    b.disabled = true; b.textContent = 'Loading…';
    fetch(FORUM_BASE + '/?replies=' + b.dataset.topicId + '&sort=' + (b.dataset.sort || 'newest') + '&offset=' + b.dataset.offset)
      .then(function (r) { return r.ok ? r.text() : Promise.reject(new Error('fetch')); })
      .then(function (html) {
        b.insertAdjacentHTML('beforebegin', html);  // next page (carries its own load-more if more remain)
        b.remove();
      })
      .catch(function () { b.disabled = false; b.textContent = orig; });
  });

  // ── 3b. Single-topic page: reply form + mark-seen on load ───────────────
  var wrap = document.querySelector('.reply-form-wrap');
  if (!wrap) return;
  var seenTopicId = parseInt(wrap.dataset.topicId, 10);
  var topicId     = parseInt(wrap.dataset.topicId, 10);
  var forumId     = parseInt(wrap.dataset.forumId, 10);
  var restBase    = wrap.dataset.bbRestBase || '/wp-json/buddyboss/v1';

  var loading      = wrap.querySelector('[data-state="loading"]');
  var anon         = wrap.querySelector('[data-state="anon"]');
  var authed       = wrap.querySelector('[data-state="authed"]');
  var textarea     = authed.querySelector('textarea[name="content"]');
  var parentInput  = authed.querySelector('input[name="parent_reply_id"]');
  var replyingTo   = authed.querySelector('.reply-form__replying-to');
  var replyingToNm = authed.querySelector('.reply-form__replying-to-name');
  var cancelThread = authed.querySelector('.reply-form__cancel-thread');
  var submitBtn    = authed.querySelector('.reply-form__submit');
  var status       = authed.querySelector('.reply-form__status');

  // Rich-text reply editor (Quill + image upload), like the new-topic/feed-reply modals.
  var replyEditorEl = authed.querySelector('.reply-form__editor');
  var replyQuill = null, replyMediaIds = [];
  // Image button → upload to BB media → tray thumb (desktop) / inline (mobile).
  var replyTray = lgComposerTray({
    editorEl: replyEditorEl,
    mediaIds: replyMediaIds,
    statusEl: status,
    restBase: restBase,
    getNonce: function (cb) { cb(nonce); },
    insertInline: function (url) {
      var range = replyQuill.getSelection(true);
      replyQuill.insertEmbed(range ? range.index : 0, 'image', url);
    },
  });
  function replyImageHandler() { replyTray.handler(); }
  function replyInitEditor() {
    if (replyQuill || !replyEditorEl) return;
    if (typeof Quill === 'undefined') { if (textarea) textarea.hidden = false; replyEditorEl.style.display = 'none'; return; }
    replyQuill = new Quill(replyEditorEl, {
      theme: 'snow', placeholder: 'Share your build, ask a question, drop a measurement…',
      bounds: replyEditorEl,   // clamp link/format tooltip inside the editor
      modules: { toolbar: {
        container: [ [{ header: [2, 3, false] }], ['bold','italic','underline'], ['blockquote','code-block'], [{ list:'ordered' }, { list:'bullet' }], ['link','image'], ['clean'] ],
        handlers: { image: replyImageHandler },
      } } });
  }
  function replyGetContent() {
    var html = replyQuill ? replyQuill.root.innerHTML : (textarea.value || '').trim();
    if (html === '<p><br></p>') html = '';
    return html.replace(/<img[^>]*>/gi, '').replace(/<p>\s*<\/p>/gi, '').trim();
  }
  function replyFocus() { if (replyQuill) replyQuill.focus(); else if (textarea) textarea.focus(); }

  function show(el) { el.hidden = false; }
  function hide(el) { el.hidden = true; }
  function setState(stateEl) {
    [loading, anon, authed].forEach(function (s) {
      if (s === stateEl) show(s); else hide(s);
    });
    lgQuillNoAutofill(replyEditorEl);
  }

  var nonce = null;

  fetch('/bb-mirror-api/v0/auth.php', { credentials: 'same-origin' })
    .then(function (r) { return r.ok ? r.json() : Promise.reject(new Error('auth ' + r.status)); })
    .then(function (data) {
      if (!data.authenticated) { setState(anon); return; }
      nonce = data.nonce;
      setState(authed);
      replyInitEditor();
      revealReplyButtons();
      revealPostMenus(data.wp_user_id || 0, !!data.can_edit_others);
      if (seenTopicId > 0) {
        fetch('/bb-mirror-api/v0/mark-seen.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ topic_id: seenTopicId }),
        }).catch(function () { /* silent */ });
      }
    })
    .catch(function () { setState(anon); });

  function revealReplyButtons() {
    document.querySelectorAll('.post__reply-btn').forEach(function (btn) {
      btn.hidden = false;
      btn.addEventListener('click', function () {
        parentInput.value = btn.dataset.replyTo;
        replyingToNm.textContent = btn.dataset.replyToAuthor || 'a reply';
        show(replyingTo);
        replyFocus();
        authed.scrollIntoView({ behavior: 'smooth', block: 'center' });
      });
    });
    cancelThread.addEventListener('click', function () {
      parentInput.value = '';
      hide(replyingTo);
    });
  }

  // ── 3c. FB-style "⋯" post menu (own posts; admins/mods on all) ──────────────
  // One overflow trigger per post (the OP + every reply) opens an Edit / Delete
  // dropdown laid out like Facebook's post menu. The trigger is revealed only for
  // the post's author OR a moderator; the owned /bb-mirror-api/v0/reply endpoint
  // re-checks author-or-mod on every PUT/DELETE, so this gate is convenience only.
  function closeAllPostMenus(except) {
    document.querySelectorAll('.post__menu-wrap').forEach(function (w) {
      if (w === except) return;
      var menu = w.querySelector('.post__menu');
      var trig = w.querySelector('.post__menu-btn');
      if (menu) menu.hidden = true;
      if (trig) trig.setAttribute('aria-expanded', 'false');
    });
  }
  function revealPostMenus(viewerId, canEditOthers) {
    document.querySelectorAll('.post__menu-wrap').forEach(function (wrap) {
      var authorId = parseInt(wrap.dataset.authorId, 10) || 0;
      var mine = viewerId > 0 && authorId === viewerId;
      if (!mine && !canEditOthers) return;          // not allowed → stays hidden
      wrap.hidden = false;
      var trig    = wrap.querySelector('.post__menu-btn');
      var menu    = wrap.querySelector('.post__menu');
      var editBtn = wrap.querySelector('.post__edit-btn');
      var delBtn  = wrap.querySelector('.post__delete-btn');
      if (trig && menu) {
        trig.addEventListener('click', function (e) {
          e.preventDefault(); e.stopPropagation();
          var willOpen = menu.hidden;
          closeAllPostMenus(wrap);
          menu.hidden = !willOpen;
          trig.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        });
      }
      if (editBtn) editBtn.addEventListener('click', function () { closeAllPostMenus(); startEdit(editBtn); });
      if (delBtn)  delBtn.addEventListener('click', function () { closeAllPostMenus(); confirmDelete(delBtn); });
    });
  }
  // Dismiss any open ⋯ menu on outside-click or Escape (wired once for the page).
  document.addEventListener('click', function (e) {
    if (e.target.closest && e.target.closest('.post__menu-wrap')) return;
    closeAllPostMenus();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' || e.key === 'Esc') closeAllPostMenus();
  });

  function startEdit(btn) {
    var post = btn.closest('.post');
    if (!post || post.querySelector('.post-edit')) return;  // already editing
    var body  = post.querySelector('.post__body');
    var kind  = btn.dataset.editKind;                       // topic | reply
    var id    = parseInt(btn.dataset.editId, 10);
    var restBase = '/wp-json/buddyboss/v1';

    // Build editor scaffold
    var box = document.createElement('div');
    box.className = 'post-edit';
    var titleHtml = (kind === 'topic')
      ? '<input class="post-edit__title" type="text" autocomplete="off" value="">' : '';
    box.innerHTML =
      titleHtml +
      '<div class="post-edit__quill"></div>' +
      '<div class="post-edit__row">' +
        '<button type="button" class="post-edit__save">Save</button>' +
        '<button type="button" class="post-edit__cancel">Cancel</button>' +
        '<span class="post-edit__status" aria-live="polite"></span>' +
      '</div>';
    body.style.display = 'none';
    btn.hidden = true;
    body.parentNode.insertBefore(box, body.nextSibling);

    if (kind === 'topic') box.querySelector('.post-edit__title').value = btn.dataset.title || '';

    // Quill (fallback to a plain textarea if the CDN didn't load)
    var quill = null, ta = null, editMediaIds = [];
    var qEl = box.querySelector('.post-edit__quill');
    // editTray is constructed below, AFTER statusEl is declared (it's needed by
    // the tray) — this wrapper is hoisted so the Quill toolbar config can point
    // at it, and editTray is assigned before any image click can fire.
    var editTray;
    function editImageHandler() { editTray.handler(); }
    if (typeof Quill !== 'undefined') {
      quill = new Quill(qEl, { theme: 'snow', bounds: qEl, modules: { toolbar: {
        container: [
          [{ header: [2, 3, false] }], ['bold','italic','underline'],
          ['blockquote','code-block'], [{ list:'ordered' }, { list:'bullet' }],
          ['link','image'], ['clean'] ],
        handlers: { image: editImageHandler },
      } } });
      lgQuillNoAutofill(qEl);
      // Seed text/formatting only — drop attachment images from the body so they
      // don't leak inline (they live in bbp_media, not the body). (Ian 2026-06-26)
      quill.root.innerHTML = lgStripBodyImages(body.innerHTML);
    } else {
      ta = document.createElement('textarea');
      ta.className = 'post-edit__fallback'; ta.rows = 6; ta.setAttribute('autocomplete', 'off'); ta.value = lgStripBodyImages(body.innerHTML);
      qEl.replaceWith(ta);
    }

    var statusEl = box.querySelector('.post-edit__status');
    var saveBtn  = box.querySelector('.post-edit__save');

    // Image button → upload to BB media → tray thumb (desktop) / inline (mobile).
    editTray = lgComposerTray({
      editorEl: qEl,
      mediaIds: editMediaIds,
      statusEl: statusEl,
      restBase: restBase,
      getNonce: function (cb) { cb(nonce); },
      insertInline: function (url) {
        var range = quill.getSelection(true);
        quill.insertEmbed(range ? range.index : 0, 'image', url);
      },
    });

    function teardown(restoreBody) {
      box.remove();
      body.style.display = '';
      btn.hidden = false;
      if (restoreBody) { /* body already restored to new html by caller */ }
    }

    box.querySelector('.post-edit__cancel').addEventListener('click', function () { teardown(false); });

    saveBtn.addEventListener('click', function () {
      var html = quill ? quill.root.innerHTML : ta.value;
      if (html === '<p><br></p>') html = '';
      // Strip inline preview <img> — new images attach via bbp_media and are
      // rendered by the mirror (bb-mirror content images are attachments, not
      // inline <img>, so this is safe).
      html = html.replace(/<img[^>]*>/gi, '').replace(/<p>\s*<\/p>/gi, '').trim();
      if (!html) { statusEl.textContent = "Can't be empty."; return; }
      saveBtn.disabled = true; statusEl.textContent = 'Saving…';

      // Owned, author-or-mod endpoint (/bb-mirror-api/v0/reply) — the SAME gate as
      // delete, IDOR-proof + nonce-checked server-side. Title+body only; existing
      // photo attachments are preserved (wp_update_post doesn't touch bbp_media).
      // Mirrors reply.php's reply-edit / topic-edit PUT contracts.
      var url = '/bb-mirror-api/v0/reply', payload;
      if (kind === 'topic') {
        payload = { topic_id: id, title: box.querySelector('.post-edit__title').value.trim(), content: html };
      } else {
        payload = { reply_id: id, content: html };
      }

      fetch(url, {
        method: 'PUT',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
        body: JSON.stringify(payload),
      })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
          if (!res.ok) {
            statusEl.textContent = 'Error: ' + ((res.j && (res.j.message || res.j.code)) || 'failed');
            saveBtn.disabled = false; return;
          }
          // Optimistic: show edited content immediately; pg catches up via sync.
          body.innerHTML = html;
          if (kind === 'topic') {
            var newTitle = payload.title;
            var h1 = document.querySelector('.topic-header__title');
            if (h1 && newTitle) h1.textContent = newTitle;
            btn.dataset.title = newTitle;
          }
          bbProcessEmbeds(body);   // re-embed any pasted media URLs
          teardown(true);
        })
        .catch(function (err) { statusEl.textContent = 'Network error: ' + err.message; saveBtn.disabled = false; });
    });
  }

  // ── 3d. Delete a post (own; admins/mods on all) — fired from the ⋯ menu. ────
  // Hits the owned /bb-mirror-api/v0/reply endpoint (author-or-mod, IDOR-proof,
  // nonce-checked); the bb→pg sync hook drops the row (and a topic's replies)
  // from every view. The native BuddyBoss DELETE is mods-only, which is why we
  // own the policy here.
  function confirmDelete(btn) {
    var kind = btn.dataset.delKind;                  // topic | reply
    var id   = parseInt(btn.dataset.delId, 10);
    if (!id) return;
    var what = kind === 'topic' ? 'this entire topic' : 'this reply';
    if (!window.confirm('Delete ' + what + '? This can’t be undone.')) return;

    var url     = '/bb-mirror-api/v0/reply';
    var payload = kind === 'topic' ? { topic_id: id } : { reply_id: id };
    btn.disabled = true;

    fetch(url, {
      method: 'DELETE',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
      body: JSON.stringify(payload),
    })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; },
                                               function () { return { ok: r.ok, j: {} }; }); })
      .then(function (res) {
        if (!res.ok) {
          btn.disabled = false;
          alert('Could not delete: ' + ((res.j && (res.j.message || res.j.code)) || 'failed'));
          return;
        }
        if (kind === 'topic') {
          // Whole thread is gone → return to the forum (breadcrumb keeps us
          // path-correct across the /forums-poc → /forum flip).
          var fl = document.querySelector('.breadcrumbs a:nth-of-type(2)');
          window.location.href = (fl && fl.getAttribute('href')) || (FORUM_BASE + '/');
        } else {
          // Reply gone → reload so the threaded tree re-renders accurately.
          window.location.reload();
        }
      })
      .catch(function (err) {
        btn.disabled = false;
        alert('Network error: ' + err.message);
      });
  }

  authed.addEventListener('submit', function (e) {
    e.preventDefault();
    if (!nonce) { status.textContent = 'Not signed in.'; return; }
    var content = replyGetContent();
    if (!content && !replyMediaIds.length) { status.textContent = "Reply can't be empty."; replyFocus(); return; }
    submitBtn.disabled = true;
    status.textContent = 'Posting…';

    var body = { topic_id: topicId, forum_id: forumId };
    if (content) body.content = content;
    if (replyMediaIds.length) body.bbp_media = replyMediaIds;
    var parentId = parseInt(parentInput.value, 10);
    if (parentId > 0) body.reply_to = parentId;

    fetch(restBase + '/reply', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
      body: JSON.stringify(body),
    })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, code: r.status, j: j }; }); })
      .then(function (res) {
        if (res.ok) {
          status.textContent = 'Posted. Refreshing…';
          setTimeout(function () { window.location.reload(); }, 800);
          return;
        }
        var msg = (res.j && (res.j.message || res.j.code)) || ('HTTP ' + res.code);
        status.textContent = 'Error: ' + msg;
        submitBtn.disabled = false;
      })
      .catch(function (err) {
        status.textContent = 'Network error: ' + err.message;
        submitBtn.disabled = false;
      });
  });

})();

/* ─── Compact feed view toggle ──────────────────────────────────────────
   Persists to localStorage('hub-compact'). The no-flash class is applied
   pre-paint by an inline script in _chrome.php; this only handles clicks
   and keeps the button's aria-pressed in sync. */
(function () {
  var KEY = 'hub-compact';
  function syncBtn(btn) {
    btn.setAttribute('aria-pressed',
      document.documentElement.classList.contains('hub-compact') ? 'true' : 'false');
  }
  document.addEventListener('click', function (e) {
    var btn = e.target.closest && e.target.closest('.feed-compact-toggle');
    if (!btn) return;
    var on = document.documentElement.classList.toggle('hub-compact');
    try { on ? localStorage.setItem(KEY, '1') : localStorage.removeItem(KEY); } catch (_) {}
    syncBtn(btn);
  });
  document.querySelectorAll('.feed-compact-toggle').forEach(syncBtn);
})();

/* ─── Per-card expand: compact → verbose for a single card ──────────────
   The caret on a compact card toggles .is-verbose on that card only, which
   un-scopes it from the compact collapse rules (see forums.css). Lazy bits
   (Read more / View N replies) then work via their existing handlers. */
(function () {
  document.addEventListener('click', function (e) {
    var btn = e.target.closest && e.target.closest('.feed-card__compact-expand');
    if (!btn) return;
    var card = btn.closest('.feed-card');
    if (!card) return;
    var verbose = card.classList.toggle('is-verbose');
    btn.setAttribute('aria-expanded', verbose ? 'true' : 'false');
    btn.setAttribute('title', verbose ? 'Collapse' : 'Show full post');
    btn.setAttribute('aria-label', verbose ? 'Collapse post' : 'Show full post');
  });
})();

/* ─── §4c. Content comment modal (Hub content cards) ───────────────────────
   A Hub content card's comment button opens this modal; the iframe loads the
   WP-free read endpoint (archive-poc comments.php, ~30ms), which renders the
   thread + its own composer and posts its content height back. Same-origin,
   so the [data-post-type]/[data-item-id] map straight onto the query string. */
(function () {
  var modal = document.getElementById('lgc-modal'),
      frame = document.getElementById('lgc-modal-frame');
  if (!modal || !frame) return;

  var openerBtn = null;   // the card's comment button that opened the modal

  function openModal(pt, id) {
    frame.src = '/archive-api/v0/comments?post_type=' +
      encodeURIComponent(pt) + '&item_id=' + encodeURIComponent(id);
    modal.hidden = false;
    document.body.style.overflow = 'hidden';
  }

  // The engine iframe (comments.php) owns the thread + composer; it only posts its
  // height back, not a count. Rather than touch the engine, read the live thread
  // count same-origin off the iframe and reflect it on the card's comment button
  // so a freshly-posted comment shows up without a reload. Surface-only.
  function setOpenerCount(n) {
    if (!openerBtn || typeof n !== 'number') return;
    openerBtn.textContent = '💬 ' +
      (n > 0 ? n + ' ' + (n === 1 ? 'comment' : 'comments') : 'Comment');
    openerBtn.setAttribute('title', n > 0 ? 'View comments' : 'Be the first to comment');
  }
  function syncOpenerCount() {
    if (!openerBtn) return;
    var n = null;
    try {
      var doc = frame.contentDocument;
      if (doc) n = doc.querySelectorAll('.lgc-list .lgc').length;
    } catch (e) { /* cross-origin (shouldn't happen, same host) — skip */ }
    setOpenerCount(n);
  }

  function closeModal() {
    syncOpenerCount();         // pull the latest count before unloading the iframe
    modal.hidden = true;
    document.body.style.overflow = '';
    frame.src = '';            // unload the iframe so a re-open refetches fresh
    frame.style.height = '';   // reset to the CSS default for the next thread
    openerBtn = null;
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest && e.target.closest('[data-comments]');
    if (btn) {
      e.preventDefault();
      e.stopPropagation();     // don't trigger card navigation
      openerBtn = btn;
      openModal(btn.getAttribute('data-post-type'), btn.getAttribute('data-item-id'));
      return;
    }
    if (e.target.closest && e.target.closest('[data-lgc-close]')) closeModal();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !modal.hidden) closeModal();
  });

  /* Height handshake — size the iframe to the thread (same message the
     standalone page's modal listens for). Clamp to 82vh; taller scrolls. */
  window.addEventListener('message', function (e) {
    if (e.origin !== location.origin || !e.data) return;
    if (typeof e.data.lgCommentsHeight === 'number') {
      var cap = Math.round(window.innerHeight * 0.82);
      frame.style.height = Math.max(220, Math.min(e.data.lgCommentsHeight, cap)) + 'px';
    }
    // Live comment count from the engine iframe (comment posted OR soft-deleted →
    // its subtree drops, count reported). Reflect on the card's comment button now,
    // not just on modal close. Engine: comments.php reportCount() (commit 5b262c0).
    if (typeof e.data.lgCommentsCount === 'number') setOpenerCount(e.data.lgCommentsCount);
  });
})();

/* ─── §4d. Card reactions (Hub feed topics + content) ──────────────────────
   Engagement-bar reaction picker, wired to the comments+reactions engine's card
   door (/archive-api/v0/card-react). Counts are server-rendered in _feed.php
   (feed_reactions_bar → lg_card_reactions_for_items); this only adds the viewer's
   own-pick highlight + the write. Gate = WP login cookie (the GET resolves it):
   anon viewers keep read-only counts, no add/react. 'like' is just one palette
   slug (the discovery.likes fold) — one reaction per card, re-pick toggles off. */
(function () {
  var REACT = '/archive-api/v0/card-react';
  var nonce = '', authed = false;

  function esc(s) {
    return (s + '').replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }
  // Palette slug order + per-slug glyph HTML are read from the server-rendered
  // picker options, so the JS never hard-codes the palette (engine owns it).
  function paletteOpts(bar) {
    return [].slice.call(bar.querySelectorAll('.fcr-palette .fcr-opt'));
  }
  function glyphFor(bar, slug) {
    var opt = bar.querySelector('.fcr-palette .fcr-opt[data-slug="' + slug + '"]');
    return opt ? opt.innerHTML : '';
  }
  function renderChips(bar, counts, mine) {
    var chips = bar.querySelector('.fcr-chips'); if (!chips) return;
    var html = '';
    paletteOpts(bar).forEach(function (opt) {
      var slug = opt.getAttribute('data-slug'), n = counts[slug] || 0;
      if (n <= 0) return;
      html += '<button type="button" class="fcr-chip' + (mine === slug ? ' is-mine' : '') +
              '" data-slug="' + esc(slug) + '">' + glyphFor(bar, slug) +
              '<span class="fcr-n">' + n + '</span></button>';
    });
    chips.innerHTML = html;
    syncLikeBtn(bar, mine);
  }
  // Reconcile the quick-Like button (.lg-act-like, in the same .fc-actions) to the
  // store: 'like' is just a palette slug, so the button reflects whether the viewer's
  // current reaction IS 'like'. hub-polish.js gives the instant optimistic flip on
  // tap; this is the server-truth reconcile (count contract — one store, no 2nd tally).
  function syncLikeBtn(bar, mine) {
    var slot = bar.closest && bar.closest('.fc-actions'); if (!slot) return;
    var like = slot.querySelector('.lg-act-like');
    if (like) like.classList.toggle('is-on', mine === 'like');
  }
  function closePalettes(except) {
    [].forEach.call(document.querySelectorAll('.fcr-palette'), function (p) {
      if (p !== except) p.hidden = true;
    });
  }
  function doReact(bar, slug) {
    if (!authed) return;  // anon — add trigger hidden, chips read-only
    var pt = bar.getAttribute('data-post-type'), id = parseInt(bar.getAttribute('data-item-id'), 10);
    fetch(REACT, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
      body: JSON.stringify({ post_type: pt, item_id: id, slug: slug, _wpnonce: nonce })
    })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j || !j.ok) return;
        // Update EVERY bar for this target, not just the clicked one — the
        // discussion modal clones the card's topic bar (§4e), and the card
        // and modal must always show the same numbers (Ian 2026-06-11).
        var sel = '.fcr[data-post-type="' + pt + '"][data-item-id="' + id + '"]';
        [].forEach.call(document.querySelectorAll(sel), function (b) {
          renderChips(b, j.counts || {}, j.mine);
        });
      })
      .catch(function () {});
  }

  // Batch viewer-state fetch for any bars not yet synced (initial load, filter
  // swap, infinite-scroll appends). GET resolves auth + nonce + my-picks + counts.
  function sync() {
    var bars = [].slice.call(document.querySelectorAll('.fcr:not([data-fcr-synced])'));
    if (!bars.length) return;
    bars.forEach(function (b) { b.setAttribute('data-fcr-synced', '1'); });
    var items = bars.map(function (b) {
      return b.getAttribute('data-post-type') + ':' + b.getAttribute('data-item-id');
    }).join(',');
    fetch(REACT + '?items=' + encodeURIComponent(items),
          { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
      .then(function (d) {
        if (d && d.authenticated && d.nonce) {
          nonce = d.nonce; authed = true; document.body.classList.remove('fcr-anon');
        } else {
          document.body.classList.add('fcr-anon');
        }
        var counts = (d && d.counts) || {}, mine = (d && d.my_reactions) || {};
        bars.forEach(function (b) {
          var k = b.getAttribute('data-post-type') + ':' + b.getAttribute('data-item-id');
          // Re-render from the authoritative GET so a "mine" highlight lands even
          // when SSR counts were already present (SSR can't know the viewer).
          if (counts[k] || mine[k]) renderChips(b, counts[k] || {}, mine[k] || null);
        });
      })
      .catch(function () { document.body.classList.add('fcr-anon'); });
  }

  document.addEventListener('click', function (e) {
    var bar = e.target.closest && e.target.closest('.fcr'); if (!bar) return;
    e.stopPropagation();  // don't bubble to the card's open-thread handler
    if (e.target.closest('.fcr-add')) {
      var pal = bar.querySelector('.fcr-palette'); var willOpen = pal.hidden;
      closePalettes(); pal.hidden = !willOpen; return;
    }
    var opt = e.target.closest('.fcr-opt');
    if (opt) { doReact(bar, opt.getAttribute('data-slug')); closePalettes(); return; }
    var chip = e.target.closest('.fcr-chip');
    if (chip) { doReact(bar, chip.getAttribute('data-slug')); return; }
  });
  document.addEventListener('click', function (e) {
    if (!(e.target.closest && e.target.closest('.fcr'))) closePalettes();
  });

  // Quick-Like (the action-bar's .lg-act-like) is just slug='like' against the same
  // store as the picker — one tally, no separate like system. hub-polish.js already
  // flips .is-on instantly (optimistic) and stops the tap from navigating; here we
  // POST + reconcile to the server count (renderChips → syncLikeBtn corrects .is-on +
  // the 'like' chip from the response).
  document.addEventListener('click', function (e) {
    var likeBtn = e.target.closest && e.target.closest('.lg-act-like');
    if (!likeBtn) return;
    var slot = likeBtn.closest('.fc-actions');
    var bar = slot && slot.querySelector('.fcr');
    if (!bar) return;          // non-reactable card → leave hub-polish's visual toggle
    if (!authed) return;       // anon → no write (optimistic flip resets on reload)
    doReact(bar, 'like');
  });

  if (document.readyState !== 'loading') sync();
  else document.addEventListener('DOMContentLoaded', sync);
  var feed = document.getElementById('hub-feed-results') || document.querySelector('.feed');
  if (feed && window.MutationObserver) {
    var t = null;
    new MutationObserver(function () { clearTimeout(t); t = setTimeout(sync, 120); })
      .observe(feed, { childList: true, subtree: true });
  }
})();

/* ─── Save / bookmark toggle (fc-save) — binary per-card save → discovery.saved_posts
   via the WP-cookie door (/archive-api/v0/save-post, sibling of card-react). The ☆ button
   is server-rendered inert; this hydrates the viewer's saved-state (batch GET → auth +
   nonce + my_saves) and wires the optimistic toggle (POST). Anon viewers get no nonce →
   the button stays inert. Mirrors the card-react module's GET-sync + MutationObserver
   re-sync (filter swap / infinite-scroll appends). ─────────────────────────────────── */
(function () {
  var SAVE = '/archive-api/v0/save-post';
  var nonce = '', authed = false;

  function setState(btn, on) {
    btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    btn.classList.toggle('is-saved', !!on);
    var lbl = btn.querySelector('.fc-save__lbl');
    if (lbl) lbl.textContent = on ? 'Saved' : 'Save';
    btn.setAttribute('title', on ? 'Saved' : 'Save');
    btn.setAttribute('aria-label', on ? 'Saved' : 'Save');
  }

  function doSave(btn) {
    if (!authed) return;                       // anon — no write door
    var pt = btn.getAttribute('data-post-type'), id = parseInt(btn.getAttribute('data-item-id'), 10);
    var was = btn.getAttribute('aria-pressed') === 'true';
    setState(btn, !was);                       // optimistic flip
    fetch(SAVE, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
      body: JSON.stringify({ post_type: pt, item_id: id, unsave: was, _wpnonce: nonce })
    })
      .then(function (r) { return r.json(); })
      .then(function (j) { setState(btn, j && j.ok ? j.saved : was); })  // reconcile / revert
      .catch(function () { setState(btn, was); });                        // revert on failure
  }

  // Batch viewer-state fetch for any buttons not yet synced (initial load, filter swap,
  // infinite-scroll appends). GET resolves auth + nonce + my_saves; SSR can't know the viewer.
  function sync() {
    var btns = [].slice.call(document.querySelectorAll('.fc-save:not([data-save-synced])'));
    if (!btns.length) return;
    btns.forEach(function (b) { b.setAttribute('data-save-synced', '1'); });
    var items = btns.map(function (b) {
      return b.getAttribute('data-post-type') + ':' + b.getAttribute('data-item-id');
    }).join(',');
    fetch(SAVE + '?items=' + encodeURIComponent(items),
          { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
      .then(function (d) {
        if (d && d.authenticated && d.nonce) {
          nonce = d.nonce; authed = true; document.body.classList.add('fc-save-authed');
        } else {
          document.body.classList.add('fc-save-anon');
        }
        var mine = (d && d.my_saves) || {};
        btns.forEach(function (b) {
          var k = b.getAttribute('data-post-type') + ':' + b.getAttribute('data-item-id');
          if (mine[k]) setState(b, true);
        });
      })
      .catch(function () {});
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest && e.target.closest('.fc-save'); if (!btn) return;
    e.preventDefault(); e.stopPropagation();   // don't bubble to the card's open-thread handler
    doSave(btn);
  });

  if (document.readyState !== 'loading') sync();
  else document.addEventListener('DOMContentLoaded', sync);
  var saveFeed = document.getElementById('hub-feed-results') || document.querySelector('.feed');
  if (saveFeed && window.MutationObserver) {
    var st = null;
    new MutationObserver(function () { clearTimeout(st); st = setTimeout(sync, 120); })
      .observe(saveFeed, { childList: true, subtree: true });
  }
})();

/* ─── Desktop SHARE (discussion topics) — Web Share API w/ copy-link
   fallback, pointed at the canonical /hub/<forum>/<topic>/ link (data-share-url).
   Delegated on [data-share-topic] so it covers BOTH the standalone single-topic
   .thread__util button AND the #lg-dmodal modal action-row button. The mobile
   feed-card share lives in hub-polish.js (overlay). ──────────────────── */
(function () {
  'use strict';
  function toast(msg) {
    try {
      var t = document.createElement('div');
      t.className = 'lg-share-toast'; t.setAttribute('role', 'status'); t.textContent = msg;
      document.body.appendChild(t);
      requestAnimationFrame(function () { t.classList.add('is-on'); });
      setTimeout(function () {
        t.classList.remove('is-on');
        setTimeout(function () { try { t.remove(); } catch (e) {} }, 280);
      }, 2200);
    } catch (e) {}
  }
  function legacyCopy(url) {
    try {
      var ta = document.createElement('textarea');
      ta.value = url; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select(); ta.setSelectionRange(0, url.length);
      var ok = document.execCommand('copy'); document.body.removeChild(ta); return ok;
    } catch (e) { return false; }
  }
  function copyLink(url) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(url).then(
        function () { toast('Link copied'); },
        function () { toast(legacyCopy(url) ? 'Link copied' : 'Couldn’t copy link'); }
      );
    } else { toast(legacyCopy(url) ? 'Link copied' : 'Couldn’t copy link'); }
  }
  function share(url, title) {
    var abs = url;
    try { abs = new URL(url, location.href).href; } catch (e) {}   // canonical -> absolute for sharing
    try {
      if (navigator.share) {
        navigator.share({ title: title || document.title, url: abs }).catch(function (err) {
          if (err && err.name === 'AbortError') return;            // user dismissed — no toast
          copyLink(abs);
        });
      } else { copyLink(abs); }
    } catch (e) { copyLink(abs); }
  }
  window.lgShareTopic = share;
  document.addEventListener('click', function (e) {
    var btn = e.target.closest && e.target.closest('[data-share-topic]');
    if (!btn) return;
    e.preventDefault(); e.stopPropagation();
    // url/title come from the button's own attrs (single-topic + modal Share) OR,
    // for the inline feed-card Share, the closest card's data-share-url + .fc-title.
    var host = btn.getAttribute('data-share-url') ? btn : btn.closest('[data-share-url]');
    var url = host ? host.getAttribute('data-share-url') : '';
    if (!url) { var dh = btn.closest('[data-href]'); url = dh ? dh.getAttribute('data-href') : ''; }
    if (!url) return;
    var title = btn.getAttribute('data-share-title') || (host && host.getAttribute('data-share-title')) || '';
    if (!title) { var card = btn.closest('.feed-card'); var ti = card && card.querySelector('.fc-title'); title = ti ? (ti.textContent || '').trim() : ''; }
    share(url, title);
  });
})();

/* ─── Cooler card: persistent reply composer (fc-composer) + face-pile (fc-facepile)
   Always-on (NOT proto-gated). Composer posts via the existing /reply path; the
   face-pile opens the thread. Desktop-only by CSS (display:none ≤640) until Buck's
   mobile-hub.css arranges them, so this never affects mobile. ───────────────── */
(function () {
  'use strict';
  var REPLY_BASE = (document.getElementById('frm-form') || { dataset: {} }).dataset.restBase || '/wp-json/buddyboss/v1';
  var auth = null, authPending = null;
  function getAuth(cb) {
    if (auth) { cb(auth); return; }
    if (authPending) { authPending.push(cb); return; }
    authPending = [cb];
    fetch('/bb-mirror-api/v0/auth.php', { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) { auth = d || { authenticated: false }; authPending.forEach(function (f) { f(auth); }); authPending = null; })
      .catch(function () { auth = { authenticated: false }; authPending.forEach(function (f) { f(auth); }); authPending = null; });
  }
  function esc(s) { return s.replace(/[&<>]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]; }); }

  // ── Composer avatar: the SSR placeholder ('You' initials) has no viewer context
  // (bb-mirror FPM pool can't resolve the WP user). Clone the logged-in avatar that
  // the shared site-header already painted — instant, no fetch, no flash. Falls back
  // to the placeholder when no header avatar is present (anon / header absent). ──
  function viewerAvatar() {
    var sels = ['.lg-chrome__aside img[src*="/avatars/"]', '.lg-chrome__aside img[src*="/profile-media/"]',
                '#site-header img[src*="/avatars/"]', 'header img[src*="/avatars/"]'];
    for (var i = 0; i < sels.length; i++) {
      var el = document.querySelector(sels[i]);
      if (el && el.src && !/logo/i.test(el.src)) return el.src;
    }
    return null;
  }
  function hydrateComposerAvatars() {
    var src = viewerAvatar(); if (!src) return;
    var avs = document.querySelectorAll('.fc-composer__av:not([data-av-set])');
    if (!avs.length) return;
    var img = '<img class="avatar-init avatar-init--img" src="' + src.replace(/"/g, '&quot;') +
              '" width="30" height="30" alt="" decoding="async">';
    avs.forEach(function (av) { av.setAttribute('data-av-set', '1'); av.innerHTML = img; });
  }
  if (document.readyState !== 'loading') hydrateComposerAvatars();
  else document.addEventListener('DOMContentLoaded', hydrateComposerAvatars);
  var fcFeed = document.getElementById('hub-feed-results') || document.querySelector('.feed');
  if (fcFeed && window.MutationObserver) {                 // filter swap + infinite-scroll appends
    var fcT = null;
    new MutationObserver(function () { clearTimeout(fcT); fcT = setTimeout(hydrateComposerAvatars, 120); })
      .observe(fcFeed, { childList: true, subtree: true });
  }

  document.addEventListener('input', function (e) {
    var inp = e.target.closest && e.target.closest('.fc-composer__input'); if (!inp) return;
    var btn = inp.closest('.fc-composer').querySelector('.fc-composer__send');
    if (btn) btn.disabled = !inp.value.trim();
  });

  document.addEventListener('click', function (e) {
    var fp = e.target.closest && e.target.closest('.fc-facepile');
    if (fp) {                                            // face-pile → open the thread
      var fcard = fp.closest('.feed-card');
      var fex = fcard && fcard.querySelector('.feed-card__expand');
      if (fex) fex.click();
      else if (fcard && fcard.getAttribute('data-href')) location.href = fcard.getAttribute('data-href');
      return;
    }
    var send = e.target.closest && e.target.closest('.fc-composer__send');
    if (!send) return;
    var box = send.closest('.fc-composer');
    var inp = box.querySelector('.fc-composer__input');
    var status = box.querySelector('.fc-composer__status');
    var text = (inp.value || '').trim(); if (!text) return;
    var topicId = parseInt(box.getAttribute('data-topic-id'), 10);
    var forumId = parseInt(box.getAttribute('data-forum-id'), 10);
    if (!topicId) return;
    send.disabled = true; if (status) status.textContent = 'Posting…';
    getAuth(function (a) {
      if (!a || !a.authenticated) { if (status) status.textContent = 'Sign in to reply.'; send.disabled = false; return; }
      var payload = { topic_id: topicId, content: '<p>' + esc(text).replace(/\n/g, '<br>') + '</p>' };
      if (forumId) payload.forum_id = forumId;
      fetch(REPLY_BASE + '/reply', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': a.nonce },
        body: JSON.stringify(payload)
      })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
          if (!res.ok) { if (status) status.textContent = (res.j && (res.j.message || res.j.code)) || 'Could not post.'; send.disabled = false; return; }
          inp.value = ''; if (status) status.textContent = 'Posted ✓';
          var card = box.closest('.feed-card');
          var ex = card && card.querySelector('.feed-card__expand');   // reload thread to show the new reply
          if (ex) { card.classList.remove('replies-expanded'); var full = card.querySelector('.feed-card__replies-full'); if (full) full.dataset.loaded = ''; ex.click(); }
          setTimeout(function () { if (status) status.textContent = ''; }, 2600);
        })
        .catch(function () { if (status) status.textContent = 'Network error.'; send.disabled = false; });
    });
  });
})();


/* ─── §4e. Desktop discussion modal (bespoke-cutover, Ian 2026-06-10) ─────────
   "Only modals for discussions": on desktop (>=641) a tap on a discussion
   card's title / excerpt / cover / replies control opens a centered modal:
   the OP (lazy ?body= full text, excerpt as instant placeholder) + the SAME
   one-deep nested thread the in-feed expand uses (?replies= — reply reactions
   and the ↪ @parent prefix come server-rendered). Built as IN-PAGE DOM, not
   an iframe: the document-delegated handlers (reaction picker §4d, read-more,
   reply modal) keep working inside it, and there is no page chrome — so no
   breadcrumbs. Esc / ✕ / backdrop close. Mobile (<=640) keeps its sheet. */
(function () {
  var BASE = (window.LG_FORUM_BASE || '').toString().replace(/\/+$/, '');
  var modal = null;

  // ── ⋯ Edit/Delete on modal REPLIES (hub-editdel, Ian 2026-06-25) ────────────
  // The modal OP already has edit+delete (lg-dmodal__edit / __del below); replies
  // didn't (that wiring was gated behind ?proto=cards). We inject a Facebook-style
  // ⋯ menu per reply-stub in fbRows(), reusing the permalink's .post__menu* styles
  // and the SAME owned endpoints (/bb-mirror-api/v0/reply PUT/DELETE — author-or-
  // mod, IDOR-proof). Reveal rule matches the permalink: author-match OR
  // can_edit_others. Server re-checks on every write, so this gate is convenience.
  var EDIT_SVG = '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>';
  var DEL_SVG  = '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6M10 11v6M14 11v6"/></svg>';

  // Viewer auth/nonce, fetched once and cached (same endpoint the OP block uses).
  var dmAuth = null, dmAuthPending = null;
  function dmGetAuth(cb) {
    if (dmAuth) { cb(dmAuth); return; }
    if (dmAuthPending) { dmAuthPending.push(cb); return; }
    dmAuthPending = [cb];
    fetch('/bb-mirror-api/v0/auth.php', { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) { dmAuth = d || { authenticated: false }; dmAuthPending.forEach(function (f) { f(dmAuth); }); dmAuthPending = null; })
      .catch(function () { dmAuth = { authenticated: false }; dmAuthPending.forEach(function (f) { f(dmAuth); }); dmAuthPending = null; });
  }

  function dmCloseMenus() {
    if (!modal) return;
    [].forEach.call(modal.querySelectorAll('.post__menu-wrap--rs .post__menu:not([hidden])'), function (mn) { mn.hidden = true; });
    [].forEach.call(modal.querySelectorAll('.post__menu-wrap--rs .post__menu-btn[aria-expanded="true"]'), function (b) { b.setAttribute('aria-expanded', 'false'); });
  }
  // Bulletproof dismiss: any click that isn't on an open ⋯/Edit control closes the
  // menu (the trigger stops propagation, so opening doesn't self-close). Wired once
  // at document level so it fires no matter where the menu lives in the modal —
  // fixes the "stuck open" menu (Ian 2026-06-25).
  if (!window.__dmMenusDismissWired) {
    window.__dmMenusDismissWired = true;
    document.addEventListener('click', function (e) {
      if (e.target.closest && e.target.closest('.post__menu-wrap--rs')) return;
      dmCloseMenus();
    });
  }

  // Bring Edit (the Edit/Delete dropdown trigger) INTO the reply's action row, next
  // to React + Reply — three uniform buttons (Ian 2026-06-25: "react, reply and
  // edit, 3 buttons together"). Only for the reply's author OR a moderator;
  // idempotent. The dropdown (the part Ian likes) stays; it now hangs off the
  // inline Edit button and dismisses cleanly.
  function dmInjectReplyMenu(stub, auth) {
    if (!stub || stub.querySelector('.post__menu-wrap--rs')) return;
    var rid = parseInt(stub.getAttribute('data-reply-id'), 10) || 0;
    if (!rid) return;
    var authorId = parseInt(stub.getAttribute('data-author-id'), 10) || 0;
    var mine = auth && auth.wp_user_id && authorId === parseInt(auth.wp_user_id, 10);
    if (!mine && !(auth && auth.can_edit_others)) return;
    // Sit in the action row (React/Reply) so all controls group together.
    var rowEl = stub.querySelector('.lg-dmodal__acts') || stub.querySelector('.reply-stub__head') || stub;
    var wrap = document.createElement('div');
    wrap.className = 'post__menu-wrap post__menu-wrap--rs';
    // "Edit" button is a copy of the React button (.dm-uniact = same look); it
    // toggles a small Edit/Delete menu. The menu is position:FIXED, placed from the
    // button's screen rect on open — so it can't be clipped or mis-anchored, and a
    // document-level outside-click/Esc closes it (no "stuck open"). (Ian 2026-06-25)
    wrap.innerHTML =
      '<button type="button" class="post__menu-btn dm-uniact" aria-haspopup="menu" aria-expanded="false" aria-label="Edit or delete">' +
        EDIT_SVG + '<span>Edit</span>' +
      '</button>' +
      '<div class="post__menu dm-pop2" role="menu" hidden>' +
        '<button type="button" role="menuitem" class="post__menu-item dm-rs-edit">' + EDIT_SVG + '<span>Edit</span></button>' +
        '<button type="button" role="menuitem" class="post__menu-item post__menu-item--danger dm-rs-del">' + DEL_SVG + '<span>Delete</span></button>' +
      '</div>';
    rowEl.appendChild(wrap);
    var btn  = wrap.querySelector('.post__menu-btn');
    var menu = wrap.querySelector('.dm-pop2');
    function placeMenu() {
      var r = btn.getBoundingClientRect();
      var mw = menu.offsetWidth || 170;
      menu.style.top  = (r.bottom + 4) + 'px';
      menu.style.left = Math.max(8, Math.min(r.right - mw, window.innerWidth - mw - 8)) + 'px';
    }
    btn.addEventListener('click', function (e) {
      e.preventDefault(); e.stopPropagation();
      var willOpen = menu.hidden;
      dmCloseMenus();
      if (willOpen) { menu.hidden = false; placeMenu(); btn.setAttribute('aria-expanded', 'true'); }
    });
    wrap.querySelector('.dm-rs-edit').addEventListener('click', function () { dmCloseMenus(); dmReplyEdit(stub, rid); });
    wrap.querySelector('.dm-rs-del').addEventListener('click', function () { dmCloseMenus(); dmReplyDelete(stub, rid); });
  }

  // Inline reply editor — Quill when present (seeded from the full stored body so
  // formatting round-trips), plain textarea fallback. PUT /bb-mirror-api/v0/reply.
  function dmReplyEdit(stub, rid) {
    if (stub.querySelector('.dm-rs-editbox')) return;
    var excerpt = stub.querySelector('.reply-stub__excerpt');
    var bodyDiv = stub.querySelector('.reply-stub__body');
    var editBtn = stub.querySelector('.reply-stub__edit');
    var raw = (editBtn && editBtn.getAttribute('data-reply-raw')) || (excerpt ? excerpt.innerHTML : '');
    // Open the SAME Quill composer modal used to CREATE replies, pre-filled (Ian
    // 2026-06-25). The inline editor below is only a fallback if it's unavailable.
    if (typeof window.lgFrmEditReply === 'function') { window.lgFrmEditReply(rid, raw); return; }
    var box = document.createElement('div');
    box.className = 'dm-rs-editbox';
    box.innerHTML =
      '<div class="dm-rs-quill"></div>' +
      '<div class="dm-rs-row">' +
        '<button type="button" class="dm-rs-save">Save</button>' +
        '<button type="button" class="dm-rs-cancel">Cancel</button>' +
        '<span class="dm-rs-status" aria-live="polite"></span>' +
      '</div>';
    if (bodyDiv) { bodyDiv.style.display = 'none'; bodyDiv.parentNode.insertBefore(box, bodyDiv.nextSibling); }
    else { stub.appendChild(box); }
    var qEl = box.querySelector('.dm-rs-quill');
    var status = box.querySelector('.dm-rs-status');
    var quill = null, ta = null;
    if (typeof Quill !== 'undefined') {
      quill = new Quill(qEl, { theme: 'snow', bounds: qEl, modules: { toolbar: [
        ['bold', 'italic', 'underline'], ['blockquote'], [{ list: 'ordered' }, { list: 'bullet' }], ['link'], ['clean'],
      ] } });
      lgQuillNoAutofill(qEl);
      if (raw) quill.clipboard.dangerouslyPasteHTML(lgStripBodyImages(raw));
      quill.focus();
    } else {
      qEl.innerHTML = '<textarea class="dm-rs-ta" rows="4" autocomplete="off"></textarea>';
      ta = qEl.querySelector('.dm-rs-ta');
      ta.value = (excerpt ? (excerpt.innerText || excerpt.textContent || '') : '').trim();
      ta.focus();
    }
    box.querySelector('.dm-rs-cancel').addEventListener('click', function () {
      box.remove(); if (bodyDiv) bodyDiv.style.display = '';
    });
    box.querySelector('.dm-rs-save').addEventListener('click', function () {
      var html;
      if (quill) {
        html = quill.root.innerHTML;
        if (html === '<p><br></p>') html = '';
        // Strip inline preview <img> (parity with the other composers — reply
        // images attach via media, not inline body HTML).
        html = html.replace(/<img[^>]*>/gi, '').trim();
      } else {
        var txt = (ta.value || '').trim();
        html = txt ? '<p>' + txt.replace(/[&<>]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]; }).replace(/\n/g, '<br>') + '</p>' : '';
      }
      if (!html) { status.textContent = "Can't be empty."; return; }
      status.textContent = 'Saving…';
      dmGetAuth(function (a) {
        if (!a || !a.nonce) { status.textContent = 'Not signed in.'; return; }
        fetch('/bb-mirror-api/v0/reply', {
          method: 'PUT', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': a.nonce },
          body: JSON.stringify({ reply_id: rid, content: html }),
        })
          .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }, function () { return { ok: r.ok, j: {} }; }); })
          .then(function (res) {
            if (!res.ok) { status.textContent = (res.j && (res.j.message || res.j.error)) || 'Could not save.'; return; }
            var fresh = (res.j && res.j.content_html) || html;
            if (excerpt) excerpt.innerHTML = fresh;
            if (editBtn) editBtn.setAttribute('data-reply-raw', fresh);
            box.remove(); if (bodyDiv) bodyDiv.style.display = '';
            if (window.bbProcessEmbeds && bodyDiv) window.bbProcessEmbeds(bodyDiv);
          })
          .catch(function (err) { status.textContent = 'Network error: ' + err.message; });
      });
    });
  }

  // Delete a reply — confirm, DELETE /bb-mirror-api/v0/reply, drop the stub +
  // reconcile the card's reply count.
  function dmReplyDelete(stub, rid) {
    if (!window.confirm('Delete this reply? This can’t be undone.')) return;
    dmGetAuth(function (a) {
      if (!a || !a.nonce) { alert('Not signed in.'); return; }
      fetch('/bb-mirror-api/v0/reply', {
        method: 'DELETE', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': a.nonce },
        body: JSON.stringify({ reply_id: rid }),
      })
        .then(function (r) { return r.json().then(function (j) { return { s: r.status, ok: r.ok, j: j }; }, function () { return { s: r.status, ok: r.ok, j: {} }; }); })
        .then(function (res) {
          if (res.s === 403) { alert('You can only delete your own replies.'); return; }
          if (!res.ok) { alert('Could not delete: ' + ((res.j && (res.j.message || res.j.error)) || 'failed')); return; }
          var rep = modal && modal.querySelector('.lg-dmodal__replies .feed-card__replies-full');
          var tid = modal && modal.dataset ? modal.dataset.topicId : null;
          try { stub.remove(); } catch (e) {}
          if (rep && tid) reconcileCount(rep, tid);
        })
        .catch(function (err) { alert('Network error: ' + err.message); });
    });
  }

  function deskt() {
    try { return window.matchMedia('(min-width:641px)').matches; } catch (e) { return false; }
  }
  function ensure() {
    if (modal) return modal;
    modal = document.createElement('div');
    modal.id = 'lg-dmodal';
    modal.hidden = true;
    modal.innerHTML =
      '<div class="lg-dmodal__back" data-dm-close></div>' +
      '<div class="lg-dmodal__panel" role="dialog" aria-modal="true" aria-label="Discussion">' +
        '<header class="lg-dmodal__head">' +
          '<h2 class="lg-dmodal__title"></h2>' +
          '<button type="button" class="lg-dmodal__size" aria-label="Modal size" title="Modal size"></button>' +
          '<button type="button" class="lg-dmodal__x" data-dm-close aria-label="Close">&times;</button>' +
        '</header>' +
        '<div class="lg-dmodal__scroll feed-page">' +
          '<div class="lg-dmodal__op"></div>' +
          '<div class="lg-dmodal__replies"></div>' +
        '</div>' +
      '</div>';
    document.body.appendChild(modal);
    modal.addEventListener('click', function (e) {
      if (e.target.closest('[data-dm-close]')) { close(); return; }
      if (!e.target.closest('.post__menu-wrap--rs')) dmCloseMenus();   // dismiss any open reply ⋯ menu
    });
    // 3 panel sizes (Ian): S / M / L, cycled from the head, persisted per device.
    var SIZES = ['s', 'm', 'l'];
    function getSize() { try { var v = localStorage.getItem('lg-dmodal-size'); return SIZES.indexOf(v) > -1 ? v : 'm'; } catch (e) { return 'm'; } }
    function applySize(sz) {
      var p = modal.querySelector('.lg-dmodal__panel');
      SIZES.forEach(function (x) { p.classList.remove('lg-dmodal--' + x); });
      p.classList.add('lg-dmodal--' + sz);
      modal.querySelector('.lg-dmodal__size').textContent = sz.toUpperCase();
    }
    applySize(getSize());
    modal.querySelector('.lg-dmodal__size').addEventListener('click', function () {
      var next = SIZES[(SIZES.indexOf(getSize()) + 1) % SIZES.length];
      try { localStorage.setItem('lg-dmodal-size', next); } catch (e) {}
      applySize(next);
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && modal && !modal.hidden) close();
    });
    return modal;
  }
  function close() {
    if (!modal) return;
    modal.hidden = true;
    document.body.style.overflow = '';
  }
  // The ?replies= endpoint pages 5 at a time (its .replies-loadmore button
  // carries the next offset). The modal shows the WHOLE thread: walk the
  // pages server-side style, replacing the button with the fetched rows.
  function drain(t, tid, depth) {
    if (depth > 20) return;
    var btn = t.querySelector('.replies-loadmore');
    if (!btn) { reconcileCount(t, tid); return; }
    var off = btn.getAttribute('data-offset') || '';
    var srt = btn.getAttribute('data-sort') || '';
    btn.remove();
    fetch(BASE + '/?replies=' + encodeURIComponent(tid) + '&offset=' + encodeURIComponent(off) + (srt ? '&sort=' + encodeURIComponent(srt) : ''), { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.text() : ''; })
      .then(function (html) {
        if (!html) { reconcileCount(t, tid); return; }
        var tmp = document.createElement('div');
        tmp.innerHTML = html;
        while (tmp.firstChild) t.appendChild(tmp.firstChild);
        fbRows(t, tid);
        if (window.bbProcessEmbeds) window.bbProcessEmbeds(t);   // embed provider links in drained pages
        drain(t, tid, depth + 1);
      })
      .catch(function () {});
  }

  // The modal shows the WHOLE rendered thread — that count is the truth the
  // user can see. Push it back onto the card's reply-count displays so the
  // two never disagree (Ian 2026-06-11: "numbers must jive"); covers stale
  // mirrored reply_count AND replies posted from inside the modal (the
  // in-feed optimistic path can't see the modal composer).
  function reconcileCount(t, tid) {
    var n = t.querySelectorAll('.reply-stub').length;
    if (!n) return;
    var ctl = document.querySelector('.fc-facepile[data-topic-id="' + tid + '"]');
    var card = ctl && ctl.closest('.feed-card');
    if (!card) {
      var exp0 = document.querySelector('.feed-card__expand[data-topic-id="' + tid + '"]');
      card = exp0 && exp0.closest('.feed-card');
    }
    if (!card) return;
    var word = n === 1 ? 'reply' : 'replies';
    var fc = card.querySelector('.fc-facepile__count');
    if (fc) fc.textContent = n + ' ' + word;
    var exp = card.querySelector('.feed-card__expand');
    if (exp) exp.innerHTML = 'View ' + n + ' ' + word + ' ▼';
  }

  // Social-style comment rows (Ian): each reply's reactions + Reply button sit
  // together in one row UNDER the comment body. The per-reply buttons get the
  // topic/forum ids stamped on them — in the modal there is no .feed-card
  // ancestor for frmOpen to source them from.
  function fbRows(t, tid) {
    var fid = (t.closest('#lg-dmodal') && (document.querySelector('#lg-dmodal .lg-dmodal__act') || {}).getAttribute)
      ? (document.querySelector('#lg-dmodal .lg-dmodal__act').getAttribute('data-forum-id') || '') : '';
    [].forEach.call(t.querySelectorAll('.reply-stub'), function (stub) {
      if (stub.getAttribute('data-lg-fbrow')) return;
      stub.setAttribute('data-lg-fbrow', '1');
      var row = document.createElement('div');
      row.className = 'lg-dmodal__acts';
      var fcr = stub.querySelector('.fcr');
      var rb = stub.querySelector('.reply-stub__reply');
      if (fcr) row.appendChild(fcr);
      if (rb) {
        rb.setAttribute('data-topic-id', tid);
        rb.setAttribute('data-forum-id', fid);
        rb.classList.add('dm-uniact');   // same button look as React + Edit
        row.appendChild(rb);
      }
      if (!row.childNodes.length) return;
      var bodyEl = stub.querySelector('.reply-stub__body, .reply-stub__excerpt');
      var col = bodyEl ? bodyEl.parentElement : stub;
      col.appendChild(row);
    });
    // Per reply: author/mod ⋯ Edit+Delete + "no self-react" — hide the React
    // trigger on the viewer's OWN replies (Ian 2026-06-25: you don't react to
    // your own). Count chips stay (you can still SEE others' reactions). Cached
    // auth; idempotent per stub.
    dmGetAuth(function (auth) {
      if (!auth || !auth.authenticated) return;
      var myId = parseInt(auth.wp_user_id, 10) || 0;
      [].forEach.call(t.querySelectorAll('.reply-stub'), function (stub) {
        dmInjectReplyMenu(stub, auth);
        var aId = parseInt(stub.getAttribute('data-author-id'), 10) || 0;
        if (myId && aId === myId) {
          var add = stub.querySelector('.fcr-add');   // the emoji "React" trigger
          // setProperty(..., 'important') so it beats the !important row CSS that
          // forces .fcr-add display:inline-flex (Ian 2026-06-25 regression fix).
          if (add) add.style.setProperty('display', 'none', 'important');
        }
      });
    });
  }

  function open(card) {
    var tid = card.getAttribute('data-topic-id') ||
      (card.querySelector('.feed-card__expand') || { getAttribute: function () { return null; } }).getAttribute('data-topic-id');
    if (!tid) return;
    var m = ensure();
    var titleEl = card.querySelector('.fc-title, .feed-card__title');
    m.querySelector('.lg-dmodal__title').textContent = titleEl ? titleEl.textContent.trim() : 'Discussion';
    // forum_id is stamped on the card's reply CTA / composer, not the card itself.
    var fidEl = card.querySelector('[data-forum-id]');
    var fid = card.getAttribute('data-forum-id') || (fidEl && fidEl.getAttribute('data-forum-id')) || '';

    // OP: author meta cloned off the card + full body (?body=; excerpt placeholder).
    var op = m.querySelector('.lg-dmodal__op');
    op.innerHTML = '';
    var meta = document.createElement('div'); meta.className = 'lg-dmodal__meta';
    var av = card.querySelector('.fc-avatar'); if (av) meta.appendChild(av.cloneNode(true));
    var mw = document.createElement('div'); mw.className = 'lg-dmodal__meta-id';
    var au = card.querySelector('.fc-author'); if (au) mw.appendChild(au.cloneNode(true));
    var tm = card.querySelector('.fc-time'); if (tm) mw.appendChild(tm.cloneNode(true));
    meta.appendChild(mw);
    op.appendChild(meta);
    var body = document.createElement('div'); body.className = 'lg-dmodal__body';
    var ex = card.querySelector('.feed-card__op-excerpt, .fc-excerpt');
    body.innerHTML = ex ? ex.innerHTML : '';
    op.appendChild(body);
    // FB-style: the actions live UNDER the post (Ian). Reply goes through the
    // canonical composer (frm §4b, delegated on [data-frm-open]).
    var opacts = document.createElement('div');
    opacts.className = 'lg-dmodal__opacts';
    opacts.innerHTML = '<button type="button" class="lg-dmodal__act feed-card__reply-cta" data-frm-open' +
      ' data-topic-id="' + tid + '" data-forum-id="' + fid + '">&#8617; Reply</button>';
    // Share the discussion — canonical /hub/<forum>/<topic>/ link off the card
    // (data-share-url, _feed.php; data-href fallback). Web Share API w/ copy-link
    // fallback via the delegated [data-share-topic] handler (desktop SHARE module
    // below). Desktop-modal parity with the mobile sheet's Share (hub-polish.js).
    (function () {
      var shareUrl = card.getAttribute('data-share-url') || card.getAttribute('data-href') || '';
      if (!shareUrl) return;
      var sh = document.createElement('button');
      sh.type = 'button'; sh.className = 'lg-dmodal__act lg-dmodal__share';
      sh.setAttribute('data-share-topic', '');
      sh.setAttribute('data-share-url', shareUrl);
      sh.setAttribute('data-share-title', m.querySelector('.lg-dmodal__title') ? m.querySelector('.lg-dmodal__title').textContent.trim() : 'Discussion');
      sh.setAttribute('aria-label', 'Share');
      sh.innerHTML = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/></svg> Share';
      opacts.appendChild(sh);
    })();
    // React to the OP itself (Ian 2026-06-11): clone the card's topic reaction
    // bar — same target, same store; §4d's delegated handlers work on the
    // clone and doReact mirrors counts to every bar for the target, so the
    // card and the modal can never disagree.
    var cardBar = card.querySelector('.fc-actions .fcr');
    if (cardBar) {
      var opBar = cardBar.cloneNode(true);
      var opPal = opBar.querySelector('.fcr-palette');
      if (opPal) opPal.hidden = true;
      opacts.insertBefore(opBar, opacts.firstChild);
    }
    // Delete the post — author (viewer wp_user_id === card's data-author-id) OR
    // moderator (can_edit_others). Server (api/v0/reply.php DELETE {topic_id})
    // re-checks the cap. Coord 2026-06-15: desktop-modal parity with the mobile sheet.
    (function () {
      var opAuthorId = parseInt(card.getAttribute('data-author-id'), 10) || 0;
      var del = document.createElement('button');
      del.type = 'button'; del.className = 'lg-dmodal__act lg-dmodal__del'; del.hidden = true;
      del.setAttribute('aria-label', 'Delete this post');
      del.innerHTML = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6M10 11v6M14 11v6"/></svg> Delete';
      // Edit the OP via the SAME Quill composer used to edit a reply
      // (lgFrmEditTopic) — unified "new edit" (Ian 2026-06-25): the OP no longer
      // opens the new-topic wizard. The composer shows the title field, pops over
      // the still-open modal, and updates the OP in place on save. Falls back to
      // the wizard only if the unified composer isn't present. Gated to author/mod
      // by the shared auth check below.
      var edit = document.createElement('button');
      edit.type = 'button'; edit.className = 'lg-dmodal__act lg-dmodal__edit'; edit.hidden = true;
      edit.setAttribute('aria-label', 'Edit this post');
      edit.innerHTML = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg> Edit';
      opacts.appendChild(edit);
      opacts.appendChild(del);
      fetch('/bb-mirror-api/v0/auth.php', { credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (a) {
          if (!a || !a.authenticated) return;
          var mine = opAuthorId && a.wp_user_id && parseInt(a.wp_user_id, 10) === opAuthorId;
          if (!mine && !a.can_edit_others) return;
          del.hidden = false;
          edit.hidden = false;
          edit.addEventListener('click', function (ev) {
            ev.preventDefault(); ev.stopPropagation();
            var ttlEl = m.querySelector('.lg-dmodal__title');
            var ttl = ttlEl ? (ttlEl.textContent || '').trim() : '';
            // Unified composer: pop it OPEN over the modal (parity with reply edit);
            // on save it updates the modal OP + card in place. body.innerHTML is the
            // full fetched OP content.
            if (typeof window.lgFrmEditTopic === 'function') {
              window.lgFrmEditTopic(tid, fid, ttl, body.innerHTML);
              return;
            }
            // Fallback: the old new-topic wizard (close the modal first).
            if (typeof window.lgNtmEditTopic !== 'function') return;
            var cb = m.querySelector('[data-dm-close]'); if (cb) cb.click();
            window.lgNtmEditTopic(tid, fid, ttl, body.innerHTML);
          });
          del.addEventListener('click', function (ev) {
            ev.preventDefault(); ev.stopPropagation();
            if (!a.nonce) { alert('Not signed in.'); return; }
            if (!window.confirm('Delete this post? This removes the discussion and its replies and can’t be undone.')) return;
            del.disabled = true;
            fetch('/bb-mirror-api/v0/reply', {
              method: 'DELETE', credentials: 'same-origin',
              headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': a.nonce },
              body: JSON.stringify({ topic_id: parseInt(tid, 10) })
            })
              .then(function (r) { return r.json().then(function (j) { return { s: r.status, ok: r.ok, j: j }; }, function () { return { s: r.status, ok: r.ok, j: {} }; }); })
              .then(function (res) {
                if (res.s === 401) { del.disabled = false; alert('Please sign in.'); return; }
                if (res.s === 403) { del.disabled = false; alert('You can only delete your own posts.'); return; }
                if (!res.ok) { del.disabled = false; alert('Could not delete: ' + ((res.j && (res.j.message || res.j.error)) || 'failed')); return; }
                var cb = m.querySelector('[data-dm-close]'); if (cb) cb.click();
                try { card.remove(); } catch (e) {}
              })
              .catch(function (err) { del.disabled = false; alert('Network error: ' + err.message); });
          });
        }).catch(function () {});
    })();
    op.appendChild(opacts);
    fetch(BASE + '/?body=' + encodeURIComponent(tid), { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.text() : ''; })
      .then(function (html) {
        if (html && html.trim()) {
          body.innerHTML = html;
          // Embed provider URLs (YouTube/Vimeo/IG/X) in the OP body, same as the
          // single-topic page does — ?body= ships raw content_html (Ian 2026-06-11).
          if (window.bbProcessEmbeds) window.bbProcessEmbeds(body);
        }
      })
      .catch(function () {});

    // Thread: nested one-deep + reply reactions, straight off the shared endpoint.
    m.dataset.topicId = String(tid);
    var rep = m.querySelector('.lg-dmodal__replies');
    rep.innerHTML = '<div class="lg-dmodal__note">Loading replies…</div>';
    loadThread(tid);

    m.hidden = false;
    document.body.style.overflow = 'hidden';
  }

  // Thread (re)loader — used on open and to LIVE-refresh after a reply posts
  // from inside the modal (the composer has no .feed-card ancestor here, so
  // the in-feed optimistic insert can't see the modal). Keeps the current
  // thread on screen until the fresh HTML lands.
  function loadThread(tid) {
    var rep = modal && modal.querySelector('.lg-dmodal__replies');
    if (!rep) return;
    fetch(BASE + '/?replies=' + encodeURIComponent(tid), { credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw 0; return r.text(); })
      .then(function (html) {
        rep.innerHTML = '<div class="feed-card__replies-full lg-rshow lg-dmodal__thread"></div>';
        var t = rep.firstChild;
        t.innerHTML = html;
        if (!t.querySelector('.reply-stub')) {
          rep.innerHTML = '<div class="lg-dmodal__note">No replies yet. Be the first to reply.</div>';
          return;
        }
        fbRows(t, tid);
        if (window.bbProcessEmbeds) window.bbProcessEmbeds(t);   // embed provider links in replies
        drain(t, tid, 0);
      })
      .catch(function () {
        rep.innerHTML = '<div class="lg-dmodal__note">Couldn’t load replies right now.</div>';
      });
  }

  // The canonical composer announces successful posts; refresh in place when
  // the modal is open on that topic.
  document.addEventListener('lg:reply-posted', function (e) {
    if (!modal || modal.hidden) return;
    var tid = modal.dataset.topicId || '';
    var posted = e.detail && e.detail.topicId;
    if (tid && posted && String(posted) === tid) loadThread(tid);
  });

  document.addEventListener('click', function (e) {
    if (!deskt()) return;
    if (!e.target.closest) return;
    if (e.target.closest('#lg-dmodal')) return;            // clicks inside self-handle
    var card = e.target.closest('.feed-card--topic');
    if (!card || e.target.closest('.feed-card--content')) return;
    // The replies-count controls ALWAYS open the modal; for everything else,
    // real controls (reactions, read-more, composer, author links…) self-handle
    // and only title/excerpt/cover taps open it.
    // .lg-act-replies joins the always-open set (Buck 6/11): hub-polish wires it with
    // stopPropagation, so this only catches the unwired window on a fresh infinite-
    // scroll card — belt + suspenders, no double-open.
    var viaReplies = e.target.closest('.feed-card__expand, .fc-facepile, .lg-act-replies');
    if (!viaReplies) {
      // Desktop: a discussion-card click opens the MODAL, never the legacy in-place
      // expand (Ian 6/16 — no surprise card-grow). Genuine controls self-handle;
      // read-more + compact-caret + bare chrome now all route to the modal (they used
      // to fall through to the bubble-phase expanders at forums.js §224/§2b/§compact).
      if (e.target.closest('input, textarea, iframe, .fcr, .fcr-palette, .lg-card-actions, .fc-actions, [data-comments], .fc-composer, .reply-stub, .fc-cover--video, a[href*="/u/"]')) return;
    }
    e.preventDefault();
    e.stopPropagation();   // beat the legacy inline-expand handlers
    open(card);
  }, true);

  // §4f deep-link router opens the discussion modal programmatically (a real
  // in-feed card, or a synthetic card built from a cold standalone fetch). Tail
  // export keeps it out of open()'s body (hub-editdel owns that). Ian 2026-06-25.
  window.lgDmodalOpen = open;
})();

/* ── §9 Pinned mosaic columns (desktop) ─────────────────────────────────────
 * The mosaic was CSS multi-column (forums.css .feed{column-count}). Multicol
 * REBALANCES the whole flow whenever anything changes height or count — every
 * infinite-scroll append and every mid-feed insertion (sponsor cards) moved
 * cards the user had already read into other columns ("the feed reorganizes
 * and repeats itself", Ian 2026-06-11). Convert the feed to N fixed column
 * buckets instead: each card is placed ONCE into the currently-shortest
 * column and never moves again; new cards (infinite scroll) go to the
 * shortest column at arrival. The column COUNT still comes from the CSS
 * cascade (forums.css bands + any overlay override) via the computed
 * column-count, so geometry stays CSS-owned — this module only pins.
 * Stream layout (single reading column) is left on the CSS path untouched. */
(function () {
  'use strict';
  var mqDesk = window.matchMedia('(min-width: 641px)');
  var ord = 0, mo = null, bucketed = false, moving = false, rr = 0;

  function feedEl() { return document.querySelector('.feed-page .feed'); }
  function isStream() {
    return (document.documentElement.getAttribute('data-lg-hublayout') || '') === 'stream';
  }
  function colCount(feed) {
    // Read the value the CASCADE says (forums.css bands, overlay overrides).
    // Multicol props are ignored by a flex container, so the declared count
    // survives as a readable signal even while we're display:flex.
    var n = parseInt(getComputedStyle(feed).columnCount, 10);
    return (n >= 1 && n <= 8) ? n : 0;
  }

  function shortest(cols) {
    var best = cols[0];
    for (var i = 1; i < cols.length; i++) {
      if (cols[i].offsetHeight < best.offsetHeight) best = cols[i];
    }
    return best;
  }

  // Deterministic sorts (new/old/hot — anything but random) read top-down, so
  // shortest-column fill scrambles them: a load-more batch of OLDER cards pours
  // into whichever column is shortest and ends up visually above newer cards
  // next door ("Newest is out of order after load more", Ian 6/12). Round-robin
  // keeps row bands in feed order and vertical position tracking recency.
  // Random has no order to preserve — shortest-column keeps its bottoms even.
  // No data-lg-sort attr (stale HTML) → legacy shortest-column fill.
  function orderedSort(feed) {
    var s = feed.getAttribute('data-lg-sort') || '';
    return s !== '' && s !== 'random';
  }

  function place(card, cols, feed) {
    if (!card.hasAttribute('data-lg-ord')) card.setAttribute('data-lg-ord', String(ord++));
    if (orderedSort(feed)) { cols[rr % cols.length].appendChild(card); rr++; }
    else shortest(cols).appendChild(card);
  }

  function unbucket(feed) {
    if (!bucketed) return;
    moving = true;
    var cards = Array.prototype.slice.call(feed.querySelectorAll('.feed-card'));
    cards.sort(function (a, b) {
      // unstamped cards (late in-column insertions) sink to the end
      function o(el) { var n = parseInt(el.getAttribute('data-lg-ord'), 10); return isNaN(n) ? 1e9 : n; }
      return o(a) - o(b);
    });
    for (var i = 0; i < cards.length; i++) feed.appendChild(cards[i]);
    var ws = feed.querySelectorAll('.feed-colw');
    for (var j = 0; j < ws.length; j++) ws[j].remove();
    feed.classList.remove('feed--pinned');
    bucketed = false;
    moving = false;
  }

  function bucket(feed) {
    var n = colCount(feed);
    if (!n || n < 2) { unbucket(feed); return; } // single column: CSS path is fine
    moving = true;
    var cards = Array.prototype.slice.call(feed.querySelectorAll('.feed-card'));
    if (bucketed) {
      cards.sort(function (a, b) {
        // unstamped cards (late in-column insertions) sink to the end
        function o(el) { var n = parseInt(el.getAttribute('data-lg-ord'), 10); return isNaN(n) ? 1e9 : n; }
        return o(a) - o(b);
      });
      var old = feed.querySelectorAll('.feed-colw');
      for (var k = 0; k < old.length; k++) old[k].remove();
    }
    var cols = [];
    for (var i = 0; i < n; i++) {
      var d = document.createElement('div');
      d.className = 'feed-colw';
      feed.appendChild(d);
      cols.push(d);
    }
    feed.classList.add('feed--pinned');
    rr = 0;   // fresh buckets → round-robin restarts at the left column
    for (var c = 0; c < cards.length; c++) place(cards[c], cols, feed);
    bucketed = true;
    moving = false;
  }

  function liveCols(feed) {
    return Array.prototype.slice.call(feed.querySelectorAll(':scope > .feed-colw'));
  }

  function apply() {
    var feed = feedEl();
    if (!feed) return;
    if (!mqDesk.matches || isStream()) { unbucket(feed); return; }
    bucket(feed);
  }

  function watch(feed) {
    if (mo) return;
    mo = new MutationObserver(function (muts) {
      if (moving || !bucketed) return;
      // A filters-modal apply replaces the feed's children wholesale (the
      // column wrappers go with them) — rebuild the buckets from scratch.
      if (!liveCols(feed).length) { bucket(feed); return; }
      var cols = null;
      for (var i = 0; i < muts.length; i++) {
        var added = muts[i].addedNodes;
        for (var j = 0; j < added.length; j++) {
          var node = added[j];
          // Only direct-child cards (infinite-scroll appends). Cards inserted
          // INSIDE a column (sponsor afterend-insertions) are already placed.
          if (node.nodeType === 1 && node.parentNode === feed &&
              node.classList && node.classList.contains('feed-card')) {
            if (!cols) cols = liveCols(feed);
            if (cols.length) { moving = true; place(node, cols, feed); moving = false; }
          }
        }
      }
    });
    mo.observe(feed, { childList: true });
    // Empty-state transitions replace the .feed NODE itself — re-init on the
    // swap event the filters modal dispatches.
    document.addEventListener('lg:hub-feed-swapped', function () {
      var f = feedEl();
      if (!f) return;
      if (mo) { mo.disconnect(); mo = null; }
      bucketed = f.classList.contains('feed--pinned') && !!f.querySelector('.feed-colw');
      apply();
      watch(f);
    });
  }

  function boot() {
    var feed = feedEl();
    if (!feed) return;
    apply();
    watch(feed);
    var pend = null;
    function fsEl() { return document.fullscreenElement || document.webkitFullscreenElement; }
    function recheck() {
      clearTimeout(pend);
      pend = setTimeout(function () {
        // Entering video fullscreen resizes the viewport to monitor size; a
        // re-bucket then MOVES the card hosting the player, which reloads the
        // iframe and kicks the user out of fullscreen (Buck 6/11). Defer all
        // layout work until fullscreen exits, then heal at the real size.
        if (fsEl()) return;
        var f = feedEl();
        if (!f) return;
        // Re-bucket only when the cascade's column count actually changed
        // (band crossing / rail open) — a plain resize never reshuffles.
        if (!mqDesk.matches || isStream()) { unbucket(f); return; }
        var want = colCount(f);
        var have = f.querySelectorAll(':scope > .feed-colw').length;
        if (want !== have) bucket(f);
      }, 150);
    }
    window.addEventListener('resize', recheck);
    document.addEventListener('fullscreenchange', recheck);
    document.addEventListener('webkitfullscreenchange', recheck);
    // Layout toggle (gear: Mosaic <-> Stream) flips data-lg-hublayout live.
    new MutationObserver(apply).observe(document.documentElement, {
      attributes: true, attributeFilter: ['data-lg-hublayout']
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();

/* ── §10 Modal image lightbox (Ian 2026-06-11) ──────────────────────────────
 * Any CONTENT image inside the discussion modal (OP body, reply photos)
 * opens a full-screen lightbox — avatars, facepiles, reaction glyphs and
 * emoji are excluded. img.php-resized images upgrade their w= param so the
 * lightbox shows a sharper rendition than the modal's inline copy.
 * Backdrop / × / Esc close the lightbox only (the modal stays open). */
(function () {
  'use strict';
  var lb = null;

  function ensure() {
    if (lb) return lb;
    lb = document.createElement('div');
    lb.id = 'lg-imglb';
    lb.hidden = true;
    lb.innerHTML =
      '<button type="button" class="lg-imglb__x" aria-label="Close image">&times;</button>' +
      '<img class="lg-imglb__img" alt="">';
    document.body.appendChild(lb);
    lb.addEventListener('click', function () { lb.hidden = true; });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && lb && !lb.hidden) {
        e.stopImmediatePropagation();   // eat the Esc — don't also close the modal
        lb.hidden = true;
      }
    }, true);
    return lb;
  }

  function fullSrc(img) {
    var src = img.currentSrc || img.src || '';
    // img.php proxy carries an explicit width — ask for a lightbox-size one
    return src.replace(/([?&]w=)\d+/, '$11600');
  }

  document.addEventListener('click', function (e) {
    if (!e.target.closest) return;
    var img = e.target.closest('#lg-dmodal img');
    if (!img) return;
    if (img.closest('.fc-avatar, .avatar-init, .fcr, .fc-facepile, .lg-dmodal__meta')) return;
    if (img.classList.contains('emoji') || (img.width && img.width < 48)) return;
    e.preventDefault();
    e.stopPropagation();
    var box = ensure();
    box.querySelector('.lg-imglb__img').src = fullSrc(img);
    box.hidden = false;
  }, true);
})();

/* ── §4f. Topic deep-linking (hub-topic-deeplink, Ian 2026-06-25) ─────────────
   Makes the §4e discussion-modal state addressable by URL — ADDITIVE, drives the
   modal via the §4e tail export (window.lgDmodalOpen) + the existing
   [data-dm-close] idiom, never reaching into open()/close().
     1. open  → pushState /hub/?topic=<forum-slug>/<topic-slug> (copyable URL).
     2. load  → ?topic= auto-opens the modal: a loaded card if present, else a
                standalone fetch of the canonical permalink poured into a
                synthetic card (the cold deep-link path).
     3. Back  → closes the modal, returning to the feed URL.
   URL shape: a query param on the FEED route (the feed ignores unknown params),
   so the canonical permalink /hub/<forum>/<topic>/ stays the untouched
   standalone/share/no-JS page. Desktop only (§4e is >=641); on mobile a ?topic=
   deep-link redirects to that canonical permalink. History bookkeeping is
   flag-free: every decision reads the live URL (is ?topic present?), which is
   race-proof against the async MutationObserver. */
(function () {
  'use strict';
  var FORUM_BASE = (window.LG_FORUM_BASE || '/hub').toString().replace(/\/+$/, '');

  function deskt() {
    try { return window.matchMedia('(min-width:641px)').matches; } catch (e) { return false; }
  }
  function dmodal() { return document.getElementById('lg-dmodal'); }
  function hasTopicParam() { return /[?&]topic=/.test(location.search); }

  // ?topic=<forum-slug>/<topic-slug>  →  {forum, topic} | null
  function parseTopicParam() {
    var m = /[?&]topic=([^&#]+)/.exec(location.search);
    if (!m) return null;
    var v = decodeURIComponent(m[1]).replace(/^\/+|\/+$/g, '');
    var parts = v.split('/').filter(Boolean);
    return parts.length >= 2 ? { forum: parts[0], topic: parts[1] } : null;
  }
  // Canonical standalone permalink for a {forum, topic}.
  function permalink(ft) { return FORUM_BASE + '/' + ft.forum + '/' + ft.topic + '/'; }
  // The ?topic= address-bar URL, scoped to the CURRENT feed path (so Back returns
  // there) and preserving any existing query (sort/q/saved…).
  function topicUrl(ft) {
    var qs = new URLSearchParams(location.search);
    qs.set('topic', ft.forum + '/' + ft.topic);
    return location.pathname + '?' + qs.toString();
  }
  function feedUrlNoTopic() {
    var qs = new URLSearchParams(location.search);
    qs.delete('topic');
    var s = qs.toString();
    return location.pathname + (s ? '?' + s : '');
  }

  // Slug pair for a LOADED topic card (its data-href is the canonical permalink).
  function ftForLoadedTopic(tid) {
    var c = document.querySelector('.feed-card--topic[data-topic-id="' + tid + '"]');
    return c ? ftFromHref(c.getAttribute('data-href')) : null;
  }
  function ftFromHref(href) {
    if (!href) return null;
    var path = href.replace(/^https?:\/\/[^/]+/, '').replace(/[?#].*$/, '').replace(/\/+$/, '');
    if (FORUM_BASE && path.indexOf(FORUM_BASE) === 0) path = path.slice(FORUM_BASE.length);
    var parts = path.split('/').filter(Boolean);
    return parts.length >= 2 ? { forum: parts[0], topic: parts[1] } : null;
  }
  // A loaded card whose permalink matches {forum, topic} (string scan — slugs may
  // carry characters that aren't attribute-selector safe).
  function cardForFt(ft) {
    var want = '/' + ft.forum + '/' + ft.topic + '/';
    var cards = document.querySelectorAll('.feed-card--topic[data-href]');
    for (var i = 0; i < cards.length; i++) {
      var h = (cards[i].getAttribute('data-href') || '').replace(/[?#].*$/, '');
      if (h.slice(-want.length) === want) return cards[i];
    }
    return null;
  }

  // ── Open routing ───────────────────────────────────────────────────────────
  function openTopic(ft) {
    var card = cardForFt(ft);
    if (card && typeof window.lgDmodalOpen === 'function') { window.lgDmodalOpen(card); return; }
    fetchStandalone(ft);   // cold: not in the feed yet (infinite-scroll hasn't reached it)
  }

  // Cold deep-link: fetch the canonical standalone page, scrape its OP into a
  // synthetic feed-card carrying exactly the attrs/selectors §4e open() reads
  // (incl. the .fc-actions .fcr reaction bar _single-topic.php now renders), then
  // hand it to open() — which hydrates ?body=/?replies= by id identically to a
  // normal card-clone open. Hard failure falls back to the real page.
  function fetchStandalone(ft) {
    fetch(permalink(ft), { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.text() : ''; })
      .then(function (html) {
        if (!html) return fail(ft);
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var op = doc.querySelector('.post--op');
        var card = op && buildSyntheticCard(doc, op, ft);
        if (!card || typeof window.lgDmodalOpen !== 'function') return fail(ft);
        window.lgDmodalOpen(card);
      })
      .catch(function () { fail(ft); });
  }
  function fail(ft) { location.href = permalink(ft); }   // graceful: the real page

  function el(tag, cls, html) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (html != null) e.innerHTML = html;
    return e;
  }
  function buildSyntheticCard(doc, op, ft) {
    // IDs come off the OP edit button (server-rendered even when hidden), with the
    // #topic-<id> anchor as the fallback for the topic id.
    var editBtn = op.querySelector('.post__edit-btn');
    var tid = editBtn && editBtn.getAttribute('data-edit-id');
    var fid = editBtn && editBtn.getAttribute('data-forum-id');
    var aid = editBtn && editBtn.getAttribute('data-author-id');
    if (!tid) { var mm = /topic-(\d+)/.exec(op.id || ''); tid = mm && mm[1]; }
    if (!tid) return null;

    var card = document.createElement('article');
    card.className = 'feed-card feed-card--topic';
    card.setAttribute('data-topic-id', tid);
    if (fid) card.setAttribute('data-forum-id', fid);
    if (aid) card.setAttribute('data-author-id', aid);
    card.setAttribute('data-href', permalink(ft));

    var avatar = op.querySelector('.post__avatar');
    card.appendChild(el('span', 'fc-avatar lg-card-avatar', avatar ? avatar.innerHTML : ''));

    var author = op.querySelector('.post__author');
    var fcAuthor = el('div', 'fc-author', '');
    var nameWrap = el('span', 'fc-author__name lg-card-author', '');
    if (author) nameWrap.appendChild(author.cloneNode(true));
    fcAuthor.appendChild(nameWrap);
    card.appendChild(fcAuthor);

    var time = op.querySelector('.post__time');
    card.appendChild(el('time', 'fc-time lg-card-time', time ? time.innerHTML : ''));

    var titleEl = doc.querySelector('.topic-header__title');
    card.appendChild(el('h3', 'fc-title feed-card__title', titleEl ? titleEl.textContent : 'Discussion'));

    // Instant excerpt placeholder; open() replaces it with the full ?body= fetch.
    var bodyEl = op.querySelector('.post__body');
    var exc = el('div', 'fc-excerpt feed-card__op', '');
    exc.appendChild(el('p', 'feed-card__op-excerpt', bodyEl ? bodyEl.innerHTML : ''));
    card.appendChild(exc);

    // The reaction bar — same .fc-actions .fcr markup the feed card carries; open()
    // clones it into the modal for full OP reaction parity.
    var fcr = op.querySelector('.fc-actions .fcr') || doc.querySelector('.fc-actions .fcr');
    var acts = el('div', 'fc-actions', '');
    if (fcr) acts.appendChild(fcr.cloneNode(true));
    card.appendChild(acts);

    return card;
  }

  // ── Address-bar sync (modal open/close → history) ────────────────────────────
  // Watch only #lg-dmodal's own `hidden` attribute (the modal is created lazily on
  // first open, appended directly to <body>, so we wait for it via a cheap
  // body-childList observer, then narrow to its hidden attribute).
  var wasOpen = false, attrObs = null;
  function check() {
    var m = dmodal();
    var isOpen = !!(m && !m.hidden);
    if (isOpen === wasOpen) return;
    wasOpen = isOpen;
    if (isOpen) onOpened(); else onClosed();
  }
  function onOpened() {
    var m = dmodal();
    var tid = m && m.dataset.topicId;
    if (!tid) return;
    if (hasTopicParam()) return;                 // routed open (load/popstate) → URL already correct
    var ft = ftForLoadedTopic(tid);
    if (ft) history.pushState({ lgTopic: ft.forum + '/' + ft.topic }, '', topicUrl(ft));
  }
  function onClosed() {
    if (!hasTopicParam()) return;                // closed BY popstate (URL already feed) → leave history alone
    if (history.state && history.state.lgTopic) history.back();   // UI close → drop the modal entry
    else history.replaceState({}, '', feedUrlNoTopic());          // defensive: no modal entry to pop
  }
  function attachAttrObs(m) {
    attrObs = new MutationObserver(check);
    attrObs.observe(m, { attributes: true, attributeFilter: ['hidden'] });
    check();
  }
  function setupModalObserver() {
    var m = dmodal();
    if (m) { attachAttrObs(m); return; }
    var bodyObs = new MutationObserver(function () {
      var n = dmodal();
      if (n) { bodyObs.disconnect(); attachAttrObs(n); }
    });
    bodyObs.observe(document.body, { childList: true });
  }

  // ── Back / Forward ───────────────────────────────────────────────────────────
  function onPopState() {
    var ft = parseTopicParam();
    var m = dmodal();
    var open = !!(m && !m.hidden);
    if (ft && deskt()) {
      if (!open) openTopic(ft);                  // forward into a topic state → reopen
    } else if (open) {
      var cb = m.querySelector('[data-dm-close]');   // back to feed → close via the §4e idiom
      if (cb) cb.click();
    }
  }

  // ── Page-load routing ────────────────────────────────────────────────────────
  function routeFromUrl() {
    var ft = parseTopicParam();
    if (!ft) return;
    if (!deskt()) { location.replace(permalink(ft)); return; }   // mobile → canonical page
    // Seat a feed entry beneath the modal so Back returns to the feed (not off-site),
    // then layer the modal entry on top and open.
    history.replaceState({}, '', feedUrlNoTopic());
    history.pushState({ lgTopic: ft.forum + '/' + ft.topic }, '', topicUrl(ft));
    openTopic(ft);
  }

  function boot() {
    setupModalObserver();
    window.addEventListener('popstate', onPopState);
    routeFromUrl();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
