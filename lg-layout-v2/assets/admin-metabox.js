/* lg-layout-v2 metabox — wires custom pickers + slot controls on the post-edit screen.
 *
 * Picker UIs (image, rich-text) get behaviors attached based on data-picker.
 * Slot controls (move up / down) reorder slots by bumping the __pos hidden
 * input — server sorts by __pos on save so the visual order persists.
 * The 'Remove' button is a real submit (form posts with action=remove_block_<i>),
 * which routes through MetaBox::save's remove path; no client-only delete. */

(function () {
    'use strict';

    /* ── scroll position restore across save round-trip ───────────────
       WP's classic editor reloads the page after Update, so the browser
       scrolls back to the top. Authors working inside the metabox lose
       their place. Snapshot scroll on form submit; restore on next load
       if we returned to the same post-edit screen. Keyed by post ID so
       opening a different post doesn't pop to a stale offset. */
    var SCROLL_KEY = 'lg_v2_mb_scroll';
    function currentPostKey() {
        var m = location.search.match(/[?&]post=(\d+)/);
        return m ? m[1] : '';
    }
    var postKey = currentPostKey();
    if (postKey) {
        try {
            var stored = JSON.parse(sessionStorage.getItem(SCROLL_KEY) || 'null');
            if (stored && stored.post === postKey && typeof stored.y === 'number') {
                /* Defer until after WP's admin scripts finish layout shifts
                   (auto-show "Post updated" notice, sticky toolbar, etc.). */
                window.requestAnimationFrame(function () {
                    window.scrollTo(0, stored.y);
                });
                sessionStorage.removeItem(SCROLL_KEY);
            }
        } catch (e) {}
        /* Only capture submits from the post-edit form, not unrelated forms. */
        document.querySelectorAll('form#post').forEach(function (form) {
            form.addEventListener('submit', function () {
                try {
                    sessionStorage.setItem(SCROLL_KEY, JSON.stringify({
                        post: postKey,
                        y:    window.scrollY || window.pageYOffset || 0,
                    }));
                } catch (e) {}
            });
        });
    }

    /* ── nested wp_editor → wpActiveEditor tracking ───────────────────
       The classic media library's "Insert into post" button writes to
       window.wpActiveEditor. With multiple wp_editor() instances on the
       same page (one per wysiwyg block × per nesting level), TinyMCE
       only auto-sets wpActiveEditor for the FIRST editor it boots. The
       second+ editor loses media insertion until something else focuses
       it through a path TinyMCE notices. We force it: on focus of any
       lg-v2 editor (textarea or its TinyMCE iframe body), set
       wpActiveEditor to that editor's id. */
    function isLgEditorId(id) {
        return typeof id === 'string' && id.indexOf('lg_v2_block_html_') === 0;
    }
    document.addEventListener('focusin', function (e) {
        var t = e.target;
        if (t && t.tagName === 'TEXTAREA' && isLgEditorId(t.id)) {
            window.wpActiveEditor = t.id;
        }
    });
    /* TinyMCE focus comes from inside an iframe — not catchable via
       document focusin. Hook tinymce directly when available. The hook
       is idempotent so it survives repeated boots.

       Once an editor's iframe initializes, install a MutationObserver
       on its body that watches for newly inserted <img> elements. When
       one lands that hasn't finished loading, flip the TinyMCE progress
       overlay on; flip it off when all pending images settle. This is
       the robust catch-all for "image inserted, waiting for asset to
       arrive" — it works regardless of how the image got there (media
       library Insert, drag-drop, paste, programmatic). */
    function watchImagesInEditor(ed) {
        var doc = ed.getDoc();
        if (!doc || !doc.body) return;
        var pending = 0;
        function recount() {
            try {
                if (pending > 0) ed.setProgressState(true);
                else             ed.setProgressState(false);
            } catch (e) {}
        }
        function watchImg(img) {
            if (img.complete && img.naturalWidth > 0) return;
            pending++;
            recount();
            /* Safety: don't hang on a genuinely dead image. 15s upper bound. */
            var safety = setTimeout(off, 15000);
            function off() {
                clearTimeout(safety);
                img.removeEventListener('load',  off);
                img.removeEventListener('error', off);
                pending--;
                recount();
            }
            img.addEventListener('load',  off, { once: true });
            img.addEventListener('error', off, { once: true });
        }
        /* Existing images at boot — most are already cached/inline, but
           pick up anything still loading. */
        doc.querySelectorAll('img').forEach(watchImg);

        var observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (m) {
                m.addedNodes.forEach(function (node) {
                    if (node.nodeType !== 1) return;
                    if (node.tagName === 'IMG') {
                        watchImg(node);
                    } else if (node.querySelectorAll) {
                        node.querySelectorAll('img').forEach(watchImg);
                    }
                });
            });
        });
        observer.observe(doc.body, { childList: true, subtree: true });
    }

    function wireTinyMce() {
        if (!window.tinymce || wireTinyMce.done) return;
        wireTinyMce.done = true;
        window.tinymce.on('AddEditor', function (e) {
            if (!isLgEditorId(e.editor.id)) return;
            var ed = e.editor;
            ed.on('focus', function () { window.wpActiveEditor = ed.id; });
            /* `init` fires after the iframe's doc + body exist. */
            ed.on('init', function () { watchImagesInEditor(ed); });
        });
    }

    /* tinymce may load after this script. Poll briefly. */
    var tries = 0;
    var iv = setInterval(function () {
        wireTinyMce();
        if (wireTinyMce.done || ++tries > 40) clearInterval(iv);   /* ~4s */
    }, 100);

    /* ── pickers ────────────────────────────────────────────────────── */

    document.querySelectorAll('.lg-v2-mb-picker').forEach(function (root) {
        var kind = root.getAttribute('data-picker');
        if (kind === 'image') attachImagePicker(root);
        /* rich-text needs no JS — wp_editor() boots TinyMCE on render. */
    });

    function attachImagePicker(root) {
        if (!window.wp || !window.wp.media) return;
        var idInput  = root.querySelector('[data-lg-image-id]');
        var preview  = root.querySelector('[data-lg-image-preview]');
        var pickBtn  = root.querySelector('[data-lg-image-pick]');
        var clearBtn = root.querySelector('[data-lg-image-clear]');
        if (!idInput || !preview || !pickBtn) return;

        var frame = null;

        pickBtn.addEventListener('click', function (e) {
            e.preventDefault();
            if (frame) { frame.open(); return; }
            frame = window.wp.media({
                title: 'Select image',
                button: { text: 'Use this image' },
                library: { type: 'image' },
                multiple: false,
            });
            frame.on('select', function () {
                var att = frame.state().get('selection').first().toJSON();
                idInput.value = String(att.id);
                var url = (att.sizes && att.sizes.medium && att.sizes.medium.url) || att.url;
                preview.innerHTML = '';
                var img = document.createElement('img');
                img.src = url;
                img.alt = att.alt || '';
                preview.appendChild(img);
                pickBtn.textContent = 'Change image';
                if (clearBtn) clearBtn.style.display = '';
            });
            frame.open();
        });

        if (clearBtn) {
            clearBtn.addEventListener('click', function (e) {
                e.preventDefault();
                idInput.value = '0';
                preview.innerHTML = '<span class="lg-v2-mb-thumb__empty">No image selected.</span>';
                pickBtn.textContent = 'Choose image';
                clearBtn.style.display = 'none';
            });
        }
    }

    /* ── slot move up / down ────────────────────────────────────────── */

    /* Each slot is a <section.lg-v2-mb-slot> with a hidden .lg-v2-mb-pos
       <input> inside its header. On reorder we:
         1. find the neighbor in the requested direction
         2. swap their __pos values
         3. swap the DOM nodes so the author sees the new order immediately
       Server sorts by __pos at save time. */

    /* Sibling slots = slots that share the same .lg-v2-mb-slots container.
       This scopes reordering to "the column wrapper I'm in" or "the root
       layout" without bleeding across nesting levels. */
    function siblingSlots(slot) {
        var container = slot.parentNode;
        if (!container) return [];
        return Array.prototype.slice.call(container.children).filter(function (el) {
            return el.classList && el.classList.contains('lg-v2-mb-slot');
        });
    }

    /* This slot's *own* __pos input — the first .lg-v2-mb-pos under its
       direct header, not any nested child slot's. */
    function posInput(slot) {
        return slot.querySelector(':scope > .lg-v2-mb-slot__hdr .lg-v2-mb-pos');
    }

    /* Move `lower` to appear above `upper` and swap their __pos values. */
    function swapAdjacent(upper, lower) {
        var u = posInput(upper), l = posInput(lower);
        if (!u || !l) return;
        var tmp = u.value;
        u.value = l.value;
        l.value = tmp;
        upper.parentNode.insertBefore(lower, upper);
    }

    /* Event delegation rather than per-button binding — survives any future
       JS-driven slot insertion (Add Block doesn't reload the page in that
       case). */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('.lg-v2-mb-move-up, .lg-v2-mb-move-down');
        if (!btn) return;
        e.preventDefault();
        var slot = btn.closest('.lg-v2-mb-slot');
        if (!slot) return;
        var siblings = siblingSlots(slot);
        var idx = siblings.indexOf(slot);
        if (btn.classList.contains('lg-v2-mb-move-up')) {
            if (idx > 0) swapAdjacent(siblings[idx - 1], slot);
        } else {
            if (idx < siblings.length - 1) swapAdjacent(slot, siblings[idx + 1]);
        }
    });
})();
