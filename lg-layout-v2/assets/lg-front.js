/* lg-layout-v2 — front-end behaviors.
 *
 * Currently a single feature: click-to-lightbox on .lg-image__img elements
 * tagged with [data-lg-lightbox]. The hover affordance (cursor + slight
 * zoom) and the slide-out popout are pure CSS in blocks/image/shell.css.
 *
 * Lightbox is lazy-created on first open and reused. Closes on ESC,
 * backdrop click, or close-button click. Body scroll is locked while
 * open so the page underneath can't shift around.
 *
 * No build step, no dependencies — vanilla. Loaded on every v2-managed
 * post via WpAssets::enqueue_front. */

(function () {
    'use strict';

    var lightbox = null;
    var gallery  = [];   /* every [data-lg-lightbox] on the page, in DOM order */
    var current  = 0;

    /* Zoom/pan state for the open lightbox image. Reset on every showAt(). */
    var zoom    = 1;     /* current scale */
    var panX    = 0;     /* translate in CSS px, applied after scale */
    var panY    = 0;
    var ZOOM_MIN  = 1;
    var ZOOM_MAX  = 5;
    var ZOOM_STEP = 0.18; /* per wheel tick — felt right at default mouse settings */

    function applyTransform(el) {
        el.style.transform = 'translate(' + panX + 'px,' + panY + 'px) scale(' + zoom + ')';
        if (zoom > 1.001) el.classList.add('is-zoomed'); else el.classList.remove('is-zoomed');
    }
    function resetZoom(el) { zoom = 1; panX = 0; panY = 0; applyTransform(el); }

    function ensureLightbox() {
        if (lightbox) return lightbox;
        lightbox = document.createElement('div');
        lightbox.className = 'lg-lightbox';
        lightbox.setAttribute('role', 'dialog');
        lightbox.setAttribute('aria-modal', 'true');
        lightbox.setAttribute('aria-hidden', 'true');
        lightbox.innerHTML =
            '<button type="button" class="lg-lightbox__close" aria-label="Close">×</button>' +
            '<button type="button" class="lg-lightbox__nav lg-lightbox__nav--prev" aria-label="Previous">‹</button>' +
            '<button type="button" class="lg-lightbox__nav lg-lightbox__nav--next" aria-label="Next">›</button>' +
            '<div class="lg-lightbox__hint" aria-hidden="true">Scroll to zoom · drag or arrows to pan · double-click to reset</div>' +
            '<div class="lg-lightbox__inner">' +
              '<figure class="lg-lightbox__figure">' +
                '<img class="lg-lightbox__img" alt="" />' +
                '<figcaption class="lg-lightbox__caption"></figcaption>' +
                '<span class="lg-lightbox__counter" aria-hidden="true"></span>' +
              '</figure>' +
            '</div>';
        document.body.appendChild(lightbox);

        /* Click handling:
           - close button or any blank area (anything that isn't the img, a nav
             arrow, or the close button itself) closes the lightbox
           - nav arrows step prev/next
           The image swallows its own click via the img element check below. */
        lightbox.addEventListener('click', function (e) {
            if (lightbox.dataset.suppressNextClick === '1') {
                delete lightbox.dataset.suppressNextClick;
                return;
            }
            if (e.target.closest('.lg-lightbox__nav--prev')) { e.stopPropagation(); step(-1); return; }
            if (e.target.closest('.lg-lightbox__nav--next')) { e.stopPropagation(); step( 1); return; }
            if (e.target.closest('.lg-lightbox__close'))     { close(); return; }
            if (e.target.closest('.lg-lightbox__img'))       return; /* don't close on image click */
            close();
        });
        document.addEventListener('keydown', function (e) {
            if (!lightbox.classList.contains('is-open')) return;
            if (e.key === 'Escape') { close(); return; }
            /* When zoomed: arrows pan the image. Otherwise: arrows step the
               gallery prev/next. Shift = bigger nudge. */
            if (zoom > 1.001 && (e.key === 'ArrowLeft' || e.key === 'ArrowRight' ||
                                 e.key === 'ArrowUp'   || e.key === 'ArrowDown')) {
                e.preventDefault();
                var step_px = e.shiftKey ? 120 : 40;
                if (e.key === 'ArrowLeft')  panX += step_px;
                if (e.key === 'ArrowRight') panX -= step_px;
                if (e.key === 'ArrowUp')    panY += step_px;
                if (e.key === 'ArrowDown')  panY -= step_px;
                var lbImg = lightbox.querySelector('.lg-lightbox__img');
                if (lbImg) applyTransform(lbImg);
                return;
            }
            if (e.key === 'ArrowLeft')  step(-1);
            if (e.key === 'ArrowRight') step( 1);
        });

        /* Wheel-to-zoom on the image. Origin tracks the cursor so the pixel
           under the pointer stays put — feels natural. preventDefault so the
           page beneath doesn't scroll while the lightbox is open. */
        var img = lightbox.querySelector('.lg-lightbox__img');
        /* Suppress the browser's native HTML5 image drag — otherwise dragging
           the image starts a "drag this image to another app" gesture that
           preempts our pointer-based pan. */
        img.setAttribute('draggable', 'false');
        img.addEventListener('dragstart', function (e) { e.preventDefault(); });
        img.addEventListener('wheel', function (e) {
            e.preventDefault();
            /* Dismiss the help hint on first zoom interaction. */
            var hint = lightbox.querySelector('.lg-lightbox__hint');
            if (hint) hint.classList.add('is-dismissed');
            /* cx/cy: cursor relative to the visual rect's top-left. With
               transform-origin 0 0, visual rect.left = untransformed_left + panX,
               so cx = cursor_X - (untransformed_left + panX). The image-local
               x-coord under the cursor is cx / zoom. To keep that same pixel
               under the cursor after scaling: new_panX = panX + cx * (1 - ratio). */
            var rect = img.getBoundingClientRect();
            var cx = e.clientX - rect.left;
            var cy = e.clientY - rect.top;
            var prev = zoom;
            zoom = Math.max(ZOOM_MIN, Math.min(ZOOM_MAX,
                zoom * (e.deltaY < 0 ? 1 + ZOOM_STEP : 1 / (1 + ZOOM_STEP))));
            var ratio = zoom / prev;
            panX = panX + cx * (1 - ratio);
            panY = panY + cy * (1 - ratio);
            if (zoom <= ZOOM_MIN + 0.001) { panX = 0; panY = 0; zoom = ZOOM_MIN; }
            applyTransform(img);
        }, { passive: false });

        /* Click-and-drag pan when zoomed in. Pointer events handle mouse +
           touch uniformly; pointer capture keeps move/up events flowing even
           when the cursor leaves the img bounds while panning. The
           draggedMeaningfully flag is checked by the lightbox click handler
           to suppress the close-on-backdrop-click that would otherwise fire
           when a drag ends with the cursor over the dim backdrop. */
        var dragging = false, dragSX = 0, dragSY = 0, dragOX = 0, dragOY = 0;
        img.addEventListener('pointerdown', function (e) {
            if (zoom <= 1.001) return;
            dragging = true;
            dragSX = e.clientX; dragSY = e.clientY;
            dragOX = panX;      dragOY = panY;
            img.setPointerCapture(e.pointerId);
            img.classList.add('is-panning');
        });
        img.addEventListener('pointermove', function (e) {
            if (!dragging) return;
            panX = dragOX + (e.clientX - dragSX);
            panY = dragOY + (e.clientY - dragSY);
            applyTransform(img);
        });
        var endDrag = function (e) {
            if (!dragging) return;
            var moved = Math.hypot(e.clientX - dragSX, e.clientY - dragSY) > 3;
            dragging = false;
            img.classList.remove('is-panning');
            try { img.releasePointerCapture(e.pointerId); } catch (_) {}
            if (moved) {
                /* Swallow the synthetic click that follows pointerup so the
                   lightbox's "click outside img = close" handler doesn't
                   misfire when the drag ends with the cursor on the backdrop. */
                lightbox.dataset.suppressNextClick = '1';
            }
        };
        img.addEventListener('pointerup', endDrag);
        img.addEventListener('pointercancel', endDrag);

        /* Double-click toggles between fit and 2x at cursor. Same math as wheel. */
        img.addEventListener('dblclick', function (e) {
            if (zoom > 1.001) { resetZoom(img); return; }
            var rect = img.getBoundingClientRect();
            var cx = e.clientX - rect.left;
            var cy = e.clientY - rect.top;
            var prev = zoom; zoom = 2;
            var ratio = zoom / prev;
            panX = panX + cx * (1 - ratio);
            panY = panY + cy * (1 - ratio);
            applyTransform(img);
        });

        return lightbox;
    }

    function step(delta) {
        if (!gallery.length) return;
        current = (current + delta + gallery.length) % gallery.length;
        showAt(current);
    }

    function showAt(i) {
        var lb = ensureLightbox();
        var img = gallery[i];
        if (!img) return;

        var bigImg = lb.querySelector('.lg-lightbox__img');
        /* Prefer the full-size source when present (server-rendered images
           ship a light `large` variant inline + a `data-lg-fullsize-src`
           pointing at the original — so the page is light but the lightbox
           gets the detail for zoom). Falls back to whatever's already loaded
           when no fullsize is declared (wysiwyg / callout inline imgs). */
        bigImg.src = img.dataset.lgFullsizeSrc || img.currentSrc || img.src;
        bigImg.alt = img.alt || '';
        resetZoom(bigImg);

        var cap = (img.dataset.lgCaption || '').trim();
        var capEl = lb.querySelector('.lg-lightbox__caption');
        capEl.textContent = cap;
        capEl.style.display = cap ? '' : 'none';

        /* Counter + nav visibility — hide nav entirely if there's only one. */
        var counter = lb.querySelector('.lg-lightbox__counter');
        counter.textContent = (gallery.length > 1) ? (i + 1) + ' / ' + gallery.length : '';
        var multi = gallery.length > 1;
        lb.querySelector('.lg-lightbox__nav--prev').style.display = multi ? '' : 'none';
        lb.querySelector('.lg-lightbox__nav--next').style.display = multi ? '' : 'none';
    }

    /* Selector that defines what counts as a gallery-eligible image. Image
       block tags get `[data-lg-lightbox]` server-side; wysiwyg + callout
       images come from TinyMCE and don't carry the attribute, so we include
       them by parent class. Keep this in sync with the click delegate below. */
    var LIGHTBOX_SEL = '[data-lg-lightbox], .lg-wysiwyg img, .lg-callout__body img';

    function open(img) {
        /* Rebuild the gallery on each open so DOM changes (edits, deletes)
           are picked up. */
        gallery = Array.prototype.slice.call(document.querySelectorAll(LIGHTBOX_SEL));
        current = Math.max(0, gallery.indexOf(img));
        var lb = ensureLightbox();
        showAt(current);

        lb.classList.add('is-open');
        lb.setAttribute('aria-hidden', 'false');
        document.body.classList.add('lg-lightbox-open');

        /* Reveal the hint, auto-dismiss after 5s if the user hasn't zoomed yet. */
        var hint = lb.querySelector('.lg-lightbox__hint');
        if (hint) {
            hint.classList.remove('is-dismissed');
            setTimeout(function () { hint.classList.add('is-dismissed'); }, 5000);
        }
    }

    function close() {
        if (!lightbox) return;
        lightbox.classList.remove('is-open');
        lightbox.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('lg-lightbox-open');
    }

    /* Event delegation — survives post-load DOM mutations (e.g., the
       eventual editor mode inserting/removing image blocks live). Matches
       the LIGHTBOX_SEL union so wysiwyg/callout images open too. */
    /* Lightbox disabled on phone — the modal didn't size to mobile
       viewports and was obstructed by the page chrome. The figcaption
       below the image already conveys the same context on small screens. */
    var MOBILE_MQ = (typeof window !== 'undefined' && window.matchMedia)
        ? window.matchMedia('(max-width: 767px)')
        : null;

    document.addEventListener('click', function (e) {
        if (!e.target.closest) return;
        if (MOBILE_MQ && MOBILE_MQ.matches) return;
        var img = e.target.closest(LIGHTBOX_SEL);
        if (!img || img.tagName !== 'IMG') return;
        e.preventDefault();
        open(img);
    });

    /* ── Missing-file placeholder ───────────────────────────────────────
       When an image block or gallery tile's <img> fails to load (404 on
       disk, broken referrer, etc.), tag the surrounding wrap so CSS can
       paint the same sage-dashed placeholder we use for empty gallery
       slots. The img stays in the DOM (so right-click → reload still
       works if the file gets uploaded), but visually it reads as "image
       slot, waiting on a file" instead of an ambiguous blank rect. The
       'error' event doesn't bubble — capture phase is required. */
    function markBroken(img) {
        var wrap = img.closest('.lg-image__frame, .lg-gallery__tile');
        if (wrap) wrap.classList.add('lg-img-missing');
    }
    document.addEventListener('error', function (e) {
        var t = e.target;
        if (!t || t.tagName !== 'IMG') return;
        if (!t.matches('.lg-image__img, .lg-gallery__img')) return;
        markBroken(t);
    }, true);
    /* Catch images that completed loading before the listener was wired
       (cached 404s, fast paint). naturalWidth === 0 means the img
       attempted load and failed — vs not-yet-attempted, which is
       complete=false. */
    function scanForBroken() {
        document.querySelectorAll('.lg-image__img, .lg-gallery__img').forEach(function (img) {
            if (img.complete && img.naturalWidth === 0) markBroken(img);
        });
    }
    /* Stuck-load fallback. Some pipelines (e.g., the dev box's auth-redirect
       through wp-login when the file is missing on disk) cause an <img> to
       sit at complete=false indefinitely — neither the load nor the error
       event fires, so the frame collapses to 0 height with nothing to look
       at. After a few seconds, treat any img that hasn't successfully
       loaded as missing and show the placeholder. The img element stays in
       the DOM so a real file later replaces the placeholder via the load
       event automatically. */
    function scanForStuck() {
        document.querySelectorAll('.lg-image__img, .lg-gallery__img').forEach(function (img) {
            if (img.naturalWidth > 0) return;                 /* loaded fine */
            /* loading="lazy" images don't start fetching until they near the
               viewport — at 2.5s a below-the-fold lazy image still legitimately
               has naturalWidth=0 because no request has been made yet. Skip it;
               the load/error event will fire when the browser actually fetches.
               Without this guard, long posts (many lazy images below fold) all
               render as missing-placeholder. */
            if (img.loading === 'lazy' && !img.complete) return;
            markBroken(img);
        });
    }
    /* If a previously-stuck image eventually loads (lazy fetch fires after the
       2.5s scanForStuck timer; file gets uploaded later; etc.), clear the
       placeholder class so the real image takes over visually. */
    document.addEventListener('load', function (e) {
        var t = e.target;
        if (!t || t.tagName !== 'IMG') return;
        if (!t.matches('.lg-image__img, .lg-gallery__img')) return;
        if (t.naturalWidth === 0) return;
        var wrap = t.closest('.lg-image__frame, .lg-gallery__tile');
        if (wrap) wrap.classList.remove('lg-img-missing');
    }, true);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scanForBroken);
    } else {
        scanForBroken();
    }
    /* Wait long enough to give real images a chance to load over the
       network, but short enough that the placeholder appears before the
       reader notices the empty slot. 2.5s feels right on a slow connection;
       cached fast-paint cases are caught by scanForBroken on DOMContentLoaded. */
    setTimeout(scanForStuck, 2500);

    /* ── Gallery carousel ───────────────────────────────────────────────
       The gallery block in carousel layout emits a [data-lg-carousel] wrap
       with prev/next buttons + a [data-lg-carousel-track] scroller. We:
         - delegate click on the prev/next buttons to scroll one tile width
         - update is-at-start / is-at-end on the wrap based on scroll position
       Tile-click → lightbox still goes through the LIGHTBOX_SEL handler above. */
    function getTileStep(track) {
        /* Prefer gallery's known tile class; fall back to the track's first
           element child so this works for any carousel (post-footer cards,
           generic content). Last-resort 80% of track width. */
        var tile = track.querySelector('.lg-gallery__tile') || track.firstElementChild;
        if (!tile) return Math.max(280, track.clientWidth * 0.8);
        var gap = parseFloat(getComputedStyle(track).columnGap || getComputedStyle(track).gap) || 12;
        return tile.getBoundingClientRect().width + gap;
    }
    function updateCarouselEdges(wrap) {
        var track = wrap.querySelector('[data-lg-carousel-track]');
        if (!track) return;
        var atStart = track.scrollLeft <= 1;
        var atEnd   = track.scrollLeft + track.clientWidth >= track.scrollWidth - 1;
        wrap.classList.toggle('is-at-start', atStart);
        wrap.classList.toggle('is-at-end',   atEnd);
    }
    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('[data-lg-carousel-prev], [data-lg-carousel-next]');
        if (!btn) return;
        var wrap  = btn.closest('[data-lg-carousel]');
        var track = wrap && wrap.querySelector('[data-lg-carousel-track]');
        if (!track) return;
        e.preventDefault();
        var dir = btn.matches('[data-lg-carousel-next]') ? 1 : -1;
        track.scrollBy({ left: dir * getTileStep(track), behavior: 'smooth' });
    });
    /* Boot each carousel on load: wire scroll listener for edge state, init
       once so the first frame has the correct disabled-arrow styling. */
    function bootCarousels() {
        document.querySelectorAll('[data-lg-carousel]').forEach(function (wrap) {
            if (wrap._lgCarouselBooted) return;
            wrap._lgCarouselBooted = true;
            var track = wrap.querySelector('[data-lg-carousel-track]');
            if (!track) return;
            track.addEventListener('scroll', function () { updateCarouselEdges(wrap); }, { passive: true });
            updateCarouselEdges(wrap);
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootCarousels);
    } else {
        bootCarousels();
    }
    /* Also re-scan after editor reloads — the FE editor triggers a full
       page reload on structural changes, so a one-shot at DOMContentLoaded
       is enough in practice. The booted flag guards against double-wiring. */

    /* ── Embed facade (YouTube / Vimeo): click → real iframe ─────────
       The embed block renders a lite-facade for YT/Vimeo: a poster img
       + play button. No iframe loads until the viewer clicks. On click,
       swap the facade's contents for a real iframe pointed at the
       provider's nocookie endpoint with autoplay=1. */
    function buildYouTubeSrc(id, start) {
        var qs = 'autoplay=1&rel=0&modestbranding=1&playsinline=1';
        if (start && +start > 0) qs += '&start=' + (+start);
        return 'https://www.youtube-nocookie.com/embed/' + encodeURIComponent(id) + '?' + qs;
    }
    function buildVimeoSrc(id) {
        return 'https://player.vimeo.com/video/' + encodeURIComponent(id)
             + '?autoplay=1&dnt=1&pip=1';
    }
    document.addEventListener('click', function (e) {
        var facade = e.target.closest && e.target.closest('.lg-embed__facade');
        if (!facade || facade.classList.contains('is-playing')) return;
        var src = '';
        var yt = facade.getAttribute('data-yt-id');
        var vm = facade.getAttribute('data-vimeo-id');
        if (yt)      src = buildYouTubeSrc(yt, facade.getAttribute('data-yt-start'));
        else if (vm) src = buildVimeoSrc(vm);
        if (!src) return;
        e.preventDefault();
        var iframe = document.createElement('iframe');
        iframe.src = src;
        iframe.setAttribute('allow', 'autoplay; encrypted-media; picture-in-picture; web-share');
        iframe.setAttribute('allowfullscreen', '');
        iframe.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
        iframe.setAttribute('frameborder', '0');
        /* Keep the inline aspect-ratio + dimensions on the parent — iframe
           fills the frame via .lg-embed__frame iframe { inset: 0; ... }. */
        facade.classList.add('is-playing');
        facade.appendChild(iframe);
    });

    /* ── Share row: copy-link button ─────────────────────────────
       Each post-footer share row has a copy-link button. Click reads its
       data-lg-share-url and pushes to the clipboard, briefly flashing the
       button green via the .is-copied class. Falls back to a hidden
       textarea + execCommand on browsers without async clipboard API.   */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('[data-lg-share-copy]');
        if (!btn) return;
        e.preventDefault();
        var url = btn.getAttribute('data-lg-share-url') || window.location.href;

        var flash = function () {
            btn.classList.add('is-copied');
            setTimeout(function () { btn.classList.remove('is-copied'); }, 1200);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(flash, function () {
                /* fall through to legacy path */
                fallback(url, flash);
            });
        } else {
            fallback(url, flash);
        }
        function fallback(text, done) {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'absolute';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); done(); } catch (_) {}
            document.body.removeChild(ta);
        }
    });

})();
