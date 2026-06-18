/* lg-link-edit — pencil-icon next to every editable link.
 *
 * Loaded for admins + the post's author whenever they're viewing a v2
 * post (not gated on ?lg_edit=1). Scans for <a> inside the editable
 * block bodies and appends a small pencil. Click the pencil → small
 * popover → enter new URL → REST update.
 *
 * To know which block a link belongs to, we walk up to the nearest
 * <lg-edit> marker (the Renderer emits these when ctx['can_edit']) and
 * read its data-lg-block-path. Saving sends the block's full updated
 * html prop to the REST update endpoint.
 */

(function () {
  'use strict';
  var CFG = window.LG_LINK_EDIT;
  if (!CFG || !CFG.rest_root) return;

  /* Which block-body containers carry editable links. */
  var EDITABLE_SELECTORS = '.lg-callout__body, .lg-wysiwyg';

  /* Pencil SVG — kept small. */
  var PENCIL_SVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>';

  function inject() {
    document.querySelectorAll(EDITABLE_SELECTORS).forEach(function (body) {
      body.querySelectorAll('a').forEach(function (a) {
        if (a._lgPencilWired) return;
        if (a.classList.contains('lg-edit-link-pencil')) return;
        a._lgPencilWired = true;
        var pencil = document.createElement('a');
        pencil.className = 'lg-edit-link-pencil';
        pencil.href = '#';
        pencil.title = 'Edit link';
        pencil.setAttribute('aria-label', 'Edit link');
        pencil.innerHTML = PENCIL_SVG;
        pencil.addEventListener('click', function (e) {
          e.preventDefault();
          e.stopPropagation();
          openPopover(a, pencil);
        });
        a.insertAdjacentElement('afterend', pencil);
      });
    });
  }

  function openPopover(linkEl, anchorEl) {
    closeAllPops();
    var pop = document.createElement('div');
    pop.className = 'lg-link-edit-pop';
    pop.innerHTML =
      '<label class="lg-link-edit-pop__label">Link URL</label>' +
      '<input type="url" class="lg-link-edit-pop__input" />' +
      '<div class="lg-link-edit-pop__bar">' +
        '<button type="button" class="lg-link-edit-pop__btn lg-link-edit-pop__btn--remove">Remove</button>' +
        '<button type="button" class="lg-link-edit-pop__btn lg-link-edit-pop__btn--cancel">Cancel</button>' +
        '<button type="button" class="lg-link-edit-pop__btn lg-link-edit-pop__btn--save">Save</button>' +
      '</div>';
    document.body.appendChild(pop);

    /* Position below the anchor element. */
    var rect = anchorEl.getBoundingClientRect();
    pop.style.top  = (window.scrollY + rect.bottom + 6) + 'px';
    pop.style.left = Math.max(8, Math.min(window.innerWidth - pop.offsetWidth - 8, window.scrollX + rect.left - 80)) + 'px';

    var input = pop.querySelector('input');
    input.value = linkEl.getAttribute('href') || '';
    setTimeout(function () { input.focus(); input.select(); }, 0);

    function close() { pop.remove(); document.removeEventListener('click', outside, true); document.removeEventListener('keydown', escKey, true); }
    function outside(e) { if (!pop.contains(e.target)) close(); }
    function escKey(e)  { if (e.key === 'Escape') close(); }
    setTimeout(function () {
      document.addEventListener('click', outside, true);
      document.addEventListener('keydown', escKey, true);
    }, 0);

    pop.querySelector('.lg-link-edit-pop__btn--cancel').addEventListener('click', close);
    pop.querySelector('.lg-link-edit-pop__btn--save').addEventListener('click', function () {
      saveLink(linkEl, (input.value || '').trim(), close);
    });
    pop.querySelector('.lg-link-edit-pop__btn--remove').addEventListener('click', function () {
      if (!window.confirm('Remove this link (keep the text)?')) return;
      removeLink(linkEl, close);
    });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        saveLink(linkEl, (input.value || '').trim(), close);
      }
    });
  }

  function closeAllPops() {
    document.querySelectorAll('.lg-link-edit-pop').forEach(function (n) { n.remove(); });
  }

  /* Locate the block hosting this link, return { path, host, body } or null. */
  function locateBlock(linkEl) {
    var body = linkEl.closest(EDITABLE_SELECTORS);
    if (!body) return null;
    /* The data-lg-edit-prop="html" element is the body; the host element
       wraps it. The <lg-edit> marker (when can_edit) sits BEFORE the host
       as its previous sibling. */
    var host = body.closest('.lg-callout, .lg-wysiwyg');
    if (!host) host = body.parentElement;
    var marker = host && host.previousElementSibling;
    while (marker && marker.tagName !== 'LG-EDIT') {
      marker = marker.previousElementSibling;
    }
    if (!marker) return null;
    var pathStr = marker.getAttribute('data-lg-block-path');
    if (!pathStr) return null;
    var path;
    try { path = JSON.parse(pathStr); } catch (e) { return null; }
    return { path: path, host: host, body: body };
  }

  function saveLink(linkEl, newUrl, doneCb) {
    if (!newUrl) return;
    var loc = locateBlock(linkEl);
    if (!loc) { window.alert('Could not locate this block to save.'); return; }
    /* Mutate the body's DOM, then read its innerHTML — that's the new
       html prop for this block. Chrome-stripping is unnecessary here:
       lg-edit-link-pencil elements get removed by the clone walker. */
    linkEl.setAttribute('href', newUrl);
    var newHtml = chromeFreeHtml(loc.body);
    restUpdate(loc.path, { html: newHtml }, function (err) {
      if (err) { window.alert('Save failed: ' + err); return; }
      doneCb && doneCb();
    });
  }

  function removeLink(linkEl, doneCb) {
    var loc = locateBlock(linkEl);
    if (!loc) return;
    /* Replace the link element with its text content. */
    var txt = document.createTextNode(linkEl.textContent);
    linkEl.parentNode.replaceChild(txt, linkEl);
    var newHtml = chromeFreeHtml(loc.body);
    restUpdate(loc.path, { html: newHtml }, function (err) {
      if (err) { window.alert('Save failed: ' + err); return; }
      doneCb && doneCb();
    });
  }

  /* Strip editor chrome (pencils, FE-editor pills if any) before serializing. */
  function chromeFreeHtml(el) {
    var clone = el.cloneNode(true);
    clone.querySelectorAll('.lg-edit-link-pencil, .lg-edit-pill, .lg-edit-img-overlay, .lg-add-zone, .lg-tier-pop, .lg-add-pop, .lg-change-pop, .lg-ratio-pop, .lg-variant-pop').forEach(function (n) { n.remove(); });
    return clone.innerHTML;
  }

  function restUpdate(path, props, cb) {
    fetch(CFG.rest_root.replace(/\/$/, '') + '/blocks/update', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
      body: JSON.stringify({ post_id: CFG.post_id, path: path, props: props }),
    }).then(function (r) {
      return r.json().then(function (data) {
        if (!r.ok) throw new Error((data && data.message) || ('rest error ' + r.status));
        cb(null, data);
      });
    }).catch(function (e) { cb(e.message); });
  }

  /* ── Header social-icons pencil ──────────────────────────────────
     Same modal as the footer author-info pencil. Hiding a slot per-post
     is achieved by blanking its URL globally — explicit, no per-post
     state to manage. */
  function wireHeaderEdit() {
    document.querySelectorAll('[data-lg-header-links-edit]').forEach(function (btn) {
      if (btn._lgWired) return;
      btn._lgWired = true;
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        openAuthorModal(btn);
      });
    });
  }

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  /* ── Author-bio pencil on the post-footer ────────────────────────
     Opens a modal with a single big textarea pre-filled with the user's
     `author_about` ACF meta. Save → REST user-meta/update → reload so
     the rendered HTML reflects the new bio. */
  function wireAuthorEdit() {
    document.querySelectorAll('[data-lg-author-edit]').forEach(function (btn) {
      if (btn._lgWired) return;
      btn._lgWired = true;
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        openAuthorModal(btn);
      });
    });
  }

  /* Fields the author modal exposes. Order = render order. The data-meta-*
     attrs (with hyphens) are emitted by post-footer/render.php on the
     author card; we read them to pre-fill each input. */
  var AUTHOR_FIELDS = [
    { key: 'author_about',                attr: 'meta-author-about',                label: 'Bio (author_about)',                    type: 'textarea', placeholder: 'A few sentences about this author. Plain text + basic formatting.' },
    { key: 'author_looth_group_profile',  attr: 'meta-author-looth-group-profile',  label: 'Looth Group profile',                    type: 'url',      placeholder: 'https://…' },
    { key: 'author_website',              attr: 'meta-author-website',              label: 'Website',                                type: 'url',      placeholder: 'https://…' },
    { key: 'author_instagram',            attr: 'meta-author-instagram',            label: 'Instagram',                              type: 'url',      placeholder: 'https://instagram.com/…' },
    { key: 'author_facebook',             attr: 'meta-author-facebook',             label: 'Facebook',                               type: 'url',      placeholder: 'https://facebook.com/…' },
    { key: 'author_youtube',              attr: 'meta-author-youtube',              label: 'YouTube',                                type: 'url',      placeholder: 'https://youtube.com/@…' },
    { key: 'author_linktree',             attr: 'meta-author-linktree',             label: 'Linktree',                               type: 'url',      placeholder: 'https://linktr.ee/…' },
  ];

  function openAuthorModal(btn) {
    /* Source = any nearby element carrying `data-author-id` + the
       `data-meta-*` snapshot. The post-footer puts these on the author
       card; the post-header puts them on the social-row wrapper. Walk
       up from the clicked pencil to find one. */
    var card = btn.closest('[data-author-id][data-meta-author-about], [data-author-id][data-meta-author-website], [data-author-id]');
    if (!card) return;
    var userId = parseInt(card.getAttribute('data-author-id') || '0', 10);
    if (!userId) return;

    var rowsHtml = AUTHOR_FIELDS.map(function (f) {
      var val = card.getAttribute('data-' + f.attr) || '';
      var field;
      if (f.type === 'textarea') {
        field = '<textarea name="' + f.key + '" rows="6" placeholder="' + escapeHtml(f.placeholder) + '" style="width:100%;padding:10px;border:1px solid #d0d5dd;border-radius:6px;font:400 14px/1.5 system-ui;resize:vertical">' + escapeHtml(val) + '</textarea>';
      } else {
        field = '<input type="url" name="' + f.key + '" value="' + escapeHtml(val) + '" placeholder="' + escapeHtml(f.placeholder) + '" style="width:100%;padding:8px 10px;border:1px solid #d0d5dd;border-radius:6px;font:400 13px/1.3 monospace" />';
      }
      return (
        '<div style="margin-bottom:14px">' +
          '<label style="display:block;font:600 11px/1 system-ui;text-transform:uppercase;letter-spacing:0.10em;color:#6b6f6b;margin-bottom:6px">' + escapeHtml(f.label) + '</label>' +
          field +
        '</div>'
      );
    }).join('');

    var modal = document.createElement('div');
    modal.className = 'lg-manage-modal';
    modal.innerHTML =
      '<div class="lg-manage-modal__panel" role="dialog" aria-modal="true" style="width:min(620px,100%);max-height:calc(100vh - 48px)">' +
        '<h3 class="lg-manage-modal__title">Author info</h3>' +
        '<div class="lg-manage-modal__body" style="overflow-y:auto">' +
          '<p style="margin:0 0 14px;font:400 12px/1.4 system-ui;color:#6b6f6b">Stored on this user\'s profile. URLs render as the social icons on the post header/footer. Blank = hide that icon.</p>' +
          rowsHtml +
        '</div>' +
        '<div class="lg-manage-modal__bar">' +
          '<button type="button" class="lg-manage-modal__btn lg-manage-modal__btn--cancel">Cancel</button>' +
          '<button type="button" class="lg-manage-modal__btn lg-manage-modal__btn--save">Save</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(modal);
    setTimeout(function () {
      var first = modal.querySelector('textarea, input');
      if (first) first.focus();
    }, 0);

    function close() { modal.remove(); }
    modal.querySelector('.lg-manage-modal__btn--cancel').addEventListener('click', close);
    modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
    modal.querySelector('.lg-manage-modal__btn--save').addEventListener('click', function () {
      /* Submit one POST per field that CHANGED. Skipping no-ops keeps the
         REST log clean and avoids touching meta we didn't intend to. */
      var changes = [];
      AUTHOR_FIELDS.forEach(function (f) {
        var orig  = card.getAttribute('data-' + f.attr) || '';
        var el    = modal.querySelector('[name="' + f.key + '"]');
        var value = el ? el.value : '';
        if (value !== orig) changes.push({ key: f.key, value: value });
      });
      if (!changes.length) { close(); return; }

      Promise.all(changes.map(function (c) {
        return fetch(CFG.rest_root.replace(/\/$/, '') + '/user-meta/update', {
          method: 'POST',
          credentials: 'include',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
          body: JSON.stringify({ user_id: userId, meta_key: c.key, value: c.value }),
        }).then(function (r) {
          return r.json().then(function (data) {
            if (!r.ok) throw new Error((data && data.message) || ('rest error on ' + c.key));
            return data;
          });
        });
      })).then(function () {
        close();
        location.reload();
      }).catch(function (e) {
        window.alert('Save failed: ' + e.message);
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { inject(); wireHeaderEdit(); wireAuthorEdit(); });
  } else {
    inject();
    wireHeaderEdit();
    wireAuthorEdit();
  }
})();
