/**
 * lg-fe-editor.js — front-end inline editor for lg-layout-v2.
 *
 * Loaded only when FeEditor::is_active() AND ?lg_edit=1. Walks every
 * <lg-edit> marker the Renderer emitted, pairs each with its host block,
 * builds a pill bar from the manifest's editor.pill_buttons, wires
 * contenteditable on inline_editable_props, and dispatches custom pickers.
 *
 * Save path: every mutation is a REST call to /lg-layout-v2/v1/blocks/*.
 * The X-WP-Nonce header is injected from the localized window.LG_FE_EDITOR
 * config. Successful save reloads the page so the server-rendered HTML
 * stays the source of truth — no client-side rendering / re-rendering.
 */

(function () {
  'use strict';

  var CFG = window.LG_FE_EDITOR;
  if (!CFG || !CFG.rest_root) return;

  var MANIFESTS = CFG.manifests || {};
  var REST_ROOT = CFG.rest_root.replace(/\/$/, '') + '/';

  function rest(op, body) {
    spinnerInc();
    return fetch(REST_ROOT + 'blocks/' + op, {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': CFG.nonce,
      },
      body: JSON.stringify(Object.assign({ post_id: CFG.post_id }, body)),
    }).then(function (r) {
      return r.json().then(function (data) {
        if (!r.ok) throw new Error((data && data.message) || ('rest error ' + r.status));
        return data;
      });
    }).finally(spinnerDec);
  }

  /* ── Loading spinner (refcounted; safe under concurrent saves) ───── */
  var _spinnerEl = null;
  var _spinnerCount = 0;
  function spinnerInc() {
    if (!_spinnerEl) {
      _spinnerEl = document.createElement('div');
      _spinnerEl.className = 'lg-fe-spinner';
      _spinnerEl.setAttribute('aria-live', 'polite');
      _spinnerEl.innerHTML = '<span class="lg-fe-spinner__dot"></span><span class="lg-fe-spinner__text">Saving…</span>';
      document.body.appendChild(_spinnerEl);
    }
    _spinnerCount++;
    _spinnerEl.classList.add('is-on');
  }
  function spinnerDec() {
    _spinnerCount = Math.max(0, _spinnerCount - 1);
    if (_spinnerCount === 0 && _spinnerEl) _spinnerEl.classList.remove('is-on');
  }

  function reload() { window.location.reload(); }

  /* ── Exit handoff ────────────────────────────────────────────────────
     "Exit editor" links to the bare permalink (FeEditor drops ?lg_edit),
     which nginx serves from the standalone renderer. But the save-hook
     re-bake (materialize) is async — it trails the REST save by ~0.5s — so
     a naive navigation can land on the *previous* blob. Hold a beat: drain
     any in-flight save (the blur-save fired by this very click counts), then
     wait out the materialize before navigating, so standalone shows fresh. */
  var REBAKE_SETTLE_MS = 600;   // async save-hook materialize lag cushion
  function exitToBarePermalink(href) {
    spinnerInc();   // our own count (+1) keeps the "Saving…" pill up while we wait
    if (_spinnerEl) {
      var t = _spinnerEl.querySelector('.lg-fe-spinner__text');
      if (t) t.textContent = 'Finishing…';
    }
    var started = Date.now();
    (function waitDrain() {
      // Drained once our exit count is the only one left (cap so we never hang).
      if (_spinnerCount > 1 && Date.now() - started < 8000) {
        setTimeout(waitDrain, 80);
        return;
      }
      setTimeout(function () { window.location.assign(href); }, REBAKE_SETTLE_MS);
    })();
  }

  function flashError(msg) { console.error('[lg-fe-editor]', msg); window.alert(msg); }

  /* ── Marker wiring ───────────────────────────────────────────────── */

  function wireMarkers(root) {
    var scope = root || document;
    var markers = scope.querySelectorAll('lg-edit');
    markers.forEach(function (marker) {
      if (marker._lgWired) return;
      var type = marker.getAttribute('data-lg-block-type');
      var path;
      try { path = JSON.parse(marker.getAttribute('data-lg-block-path') || '[]'); }
      catch (e) { path = []; }
      var host = marker.nextElementSibling;
      if (!host) { marker.remove(); return; }

      /* Hosts that can't carry a pill child:
           - <h1>–<h6>: a <div> child is invalid HTML, and the heading's
             typography cascades into the pill.
           - <hr>: void element; literally cannot have children.
           - Anything too short to hover (divider) needs a real hit area.
         Wrap such hosts in a block-level div and treat the wrapper as
         the host. The original element stays inside, untouched;
         findEditable() still locates [data-lg-edit-prop] via querySelector. */
      if (/^(H[1-6]|HR)$/.test(host.tagName)) {
        var wrap = document.createElement('div');
        wrap.className = 'lg-edit-headingwrap';
        if (host.tagName === 'HR') wrap.classList.add('lg-edit-dividerwrap');
        host.parentNode.insertBefore(wrap, host);
        wrap.appendChild(host);
        host = wrap;
      }

      host._lgPath = path;
      host._lgType = type;
      host._lgTier = marker.getAttribute('data-lg-block-gated-tier') || '';

      attachPill(host, type, path);
      wireInlineEditable(host, type, path);

      marker._lgWired = true;
      marker.remove();
    });
    wireAddZones();
    consumePendingFocus();
  }

  /* ── Pending-focus handoff across reloads ─────────────────────────
     Insert / swap / zone-pick all reload the page (structural changes
     are server-rendered). We stash the just-inserted block's path in
     sessionStorage so wireMarkers can find it on the way back in and
     drop the author straight into edit mode for the new block. */
  var PENDING_KEY = 'lg_fe_pending_focus';
  function markPendingEdit(path) {
    try { sessionStorage.setItem(PENDING_KEY, JSON.stringify(path)); } catch (e) {}
  }
  function consumePendingFocus() {
    var raw;
    try { raw = sessionStorage.getItem(PENDING_KEY); } catch (e) { return; }
    if (!raw) return;
    try { sessionStorage.removeItem(PENDING_KEY); } catch (e) {}
    var path;
    try { path = JSON.parse(raw); } catch (e) { return; }
    var host = hostAtPath(path);
    if (!host) return;
    doEdit(host._lgType, path, host);
  }
  function hostAtPath(path) {
    var ps = JSON.stringify(path);
    var hosts = document.querySelectorAll('.lg-edit-host');
    for (var i = 0; i < hosts.length; i++) {
      if (JSON.stringify(hosts[i]._lgPath) === ps) return hosts[i];
    }
    return null;
  }

  /* Resulting child path for an insert at (parent_path, index). Mirrors
     the server's children_bucket logic: empty parent → root path [i];
     non-empty parent (which always ends [..., 'columns', c]) → child path
     [...parent, 'blocks', j]. */
  function childPath(parentPath, index) {
    return parentPath.length === 0 ? [index] : parentPath.concat(['blocks', index]);
  }

  /* ── Insert zones between blocks ─────────────────────────────────── */

  /**
   * Insert a clickable "+" zone before every host in each container
   * (the root <article> and each .lg-columns__col), plus one at the end.
   * Click opens the block-type picker; while the picker is open, the
   * zone expands and pushes its neighbors apart so authors can see
   * exactly where the new block will land.
   */
  function wireAddZones() {
    document.querySelectorAll('.lg-add-zone').forEach(function (z) { z.remove(); });

    /* Root container */
    var article = document.querySelector('.lg-article');
    if (article) addZonesIn(article, []);

    /* Each column inside every columns block */
    document.querySelectorAll('.lg-columns__col').forEach(function (col) {
      var columnsHost = col.parentElement;          /* .lg-columns (the host) */
      if (!columnsHost || !columnsHost._lgPath) return;
      var cols = columnsHost.querySelectorAll(':scope > .lg-columns__col');
      var colIdx = Array.prototype.indexOf.call(cols, col);
      if (colIdx < 0) return;
      addZonesIn(col, columnsHost._lgPath.concat(['columns', colIdx]));
    });
  }

  function addZonesIn(container, parentPath) {
    var hosts = Array.prototype.filter.call(container.children, function (c) {
      return c.classList && c.classList.contains('lg-edit-host');
    });
    hosts.forEach(function (host, idx) {
      container.insertBefore(makeAddZone(parentPath, idx), host);
    });
    container.appendChild(makeAddZone(parentPath, hosts.length));
  }

  function makeAddZone(parentPath, index) {
    var zone = document.createElement('div');
    zone.className = 'lg-add-zone';
    zone.contentEditable = 'false';
    zone.innerHTML = '<button type="button" class="lg-add-zone__btn" aria-label="Add block here">+</button>';
    zone.querySelector('.lg-add-zone__btn').addEventListener('click', function (e) {
      e.preventDefault(); e.stopPropagation();
      openZonePicker(zone, parentPath, index);
    });
    return zone;
  }

  function openZonePicker(zone, parentPath, index) {
    /* Close any other open zone-pickers first. */
    document.querySelectorAll('.lg-add-zone--open').forEach(function (z) {
      z.classList.remove('lg-add-zone--open');
      var p = z.querySelector('.lg-add-pop'); if (p) p.remove();
    });
    zone.classList.add('lg-add-zone--open');

    var inColumn = parentPath.length > 0;   /* anything other than root is inside a column */

    var pop = document.createElement('div');
    pop.className = 'lg-tier-pop lg-add-pop';
    pop.contentEditable = 'false';
    pop.innerHTML = '<div class="lg-tier-pop__title">Insert block</div>';
    INSERT_OPTIONS.forEach(function (opt) {
      if (inColumn && opt.value === 'columns') return;
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'lg-tier-pop__opt';
      b.textContent = opt.label;
      b.addEventListener('click', function (e) {
        e.preventDefault(); e.stopPropagation();
        markPendingEdit(childPath(parentPath, index));
        rest('insert', { parent_path: parentPath, index: index, block: { type: opt.value } })
          .then(reload, function (err) { flashError('Insert failed: ' + err.message); });
      });
      pop.appendChild(b);
    });
    zone.appendChild(pop);

    setTimeout(function () {
      function dismiss(e) {
        if (e.type === 'keydown' && e.key !== 'Escape') return;
        if (e.type === 'click' && zone.contains(e.target)) return;
        zone.classList.remove('lg-add-zone--open');
        pop.remove();
        document.removeEventListener('click', dismiss, true);
        document.removeEventListener('keydown', dismiss, true);
      }
      document.addEventListener('click', dismiss, true);
      document.addEventListener('keydown', dismiss, true);
    }, 0);
  }

  /* ── Pills ───────────────────────────────────────────────────────── */

  var PILL_LABELS = {
    edit: 'Edit', change: 'Change', delete: 'Delete', tier: 'Tier', ratio: 'Ratio',
    variant: 'Variant',
    add: '+ Add',
    up: '▲', down: '▼', left: '◀', right: '▶',
  };
  var TIER_SHORT  = { '': 'public', 'looth-lite': 'lite', 'looth-pro': 'pro', 'admin': 'admin' };

  function attachPill(host, type, path) {
    var manifest = MANIFESTS[type] || {};
    var buttons = ((manifest.editor && manifest.editor.pill_buttons) || []).slice();

    /* Column children get left/right buttons inserted after 'down', so
       sideways column swap shares the same compact arrow group. */
    if (isColumnChild(path)) {
      var afterDown = buttons.indexOf('down') + 1;
      if (afterDown > 0) buttons.splice(afterDown, 0, 'left', 'right');
      else { buttons.unshift('left'); buttons.unshift('right'); }
    }
    if (!buttons.length) return;

    var pill = document.createElement('div');
    pill.className = 'lg-edit-pill';
    pill.contentEditable = 'false';
    buttons.forEach(function (name) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'lg-edit-pill__btn lg-edit-pill__btn--' + name;
      if (name === 'tier') {
        btn.textContent = 'Tier: ' + (TIER_SHORT[host._lgTier] || 'public');
      } else {
        btn.textContent = PILL_LABELS[name] || name;
      }
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        pillAction(name, type, path, host);
      });
      pill.appendChild(btn);
    });

    /* Hover-reveal: pill is opacity 0 until host is hovered. CSS handles
       transitions. position:relative on host so absolute pill anchors. */
    if (getComputedStyle(host).position === 'static') host.style.position = 'relative';
    host.classList.add('lg-edit-host');
    host.appendChild(pill);
  }

  function pillAction(name, type, path, host) {
    if (name === 'delete') return doDelete(path);
    if (name === 'edit')   return doEdit(type, path, host);
    if (name === 'change') return doChange(type, path, host);
    if (name === 'tier')    return doTier(path, host);
    if (name === 'ratio')   return doRatio(path, host);
    if (name === 'variant') return doVariant(type, path, host);
    if (name === 'up')     return doMove(path, -1);
    if (name === 'down')   return doMove(path,  1);
    if (name === 'left')   return doMoveCol(path, -1);
    if (name === 'right')  return doMoveCol(path,  1);
    if (name === 'add')    return doAdd(path, host);
    flashError('Unknown pill action: ' + name);
  }

  /* ── Reorder (up / down within container) ────────────────────────── */

  function isColumnChild(path) {
    return path.length >= 5 && path[path.length - 2] === 'blocks';
  }

  /**
   * Same-container reorder. `delta` is +1 (down) or -1 (up). Walks to the
   * sibling slot in the same parent and asks the server to move.
   *
   * Path conventions (mirrors EditorRest):
   *   - Root block at index i:                 path = [i]                                → parent = []
   *   - Column child at row r, col c, slot j:  path = [r, "columns", c, "blocks", j]    → parent = [r, "columns", c]
   */
  function doMove(path, delta) {
    if (!path.length) return;
    var leaf = path[path.length - 1];
    var parent = (path[path.length - 2] === 'blocks')
      ? path.slice(0, -2)
      : path.slice(0, -1);
    var target = leaf + delta;
    if (target < 0) return;   /* already at top, no-op */
    rest('move', { from: path, to_parent: parent, to_index: target })
      .then(reload, function (err) { flashError('Move failed: ' + err.message); });
  }

  /** Column children only: shift into the adjacent column at the same index. */
  function doMoveCol(path, delta) {
    if (!isColumnChild(path)) return;
    var rowIdx = path[0];
    var colIdx = path[2];
    var slot   = path[4];
    var newCol = colIdx + delta;
    if (newCol < 0) return;
    rest('move', {
      from:      path,
      to_parent: [rowIdx, 'columns', newCol],
      to_index:  slot,
    }).then(reload, function (err) { flashError('Move failed: ' + err.message); });
  }

  /* ── Add block below ─────────────────────────────────────────────── */

  /* Block-type display labels. Fallback to title-case-from-slug when a
     block isn't listed here, so newly-added blocks at least appear in the
     picker even before someone touches this file. */
  var BLOCK_LABELS = {
    'section-heading': 'Section heading',
    'wysiwyg':         'Text (rich)',
    'image':           'Image',
    'embed':           'Embed',
    'gallery':         'Gallery',
    'callout':         'Callout',
    'divider':         'Divider',
    'columns':         'Columns',
  };
  function labelFor(type) {
    if (BLOCK_LABELS[type]) return BLOCK_LABELS[type];
    return type.replace(/-/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
  }

  /* Build the insert-picker list dynamically from the server-supplied
     MANIFESTS. Includes every block whose manifest declares
     editor.insertable, MINUS singletons (post-header, post-footer) that
     can't appear more than once in a layout. Columns gets a contextual
     filter applied at render time (no nested columns). */
  function buildInsertOptions() {
    var exclude = { 'post-header': true, 'post-footer': true };
    return Object.keys(MANIFESTS)
      .filter(function (name) {
        var m = MANIFESTS[name];
        return m && m.editor && m.editor.insertable && !exclude[name];
      })
      .sort()
      .map(function (name) { return { value: name, label: labelFor(name) }; });
  }
  var INSERT_OPTIONS = buildInsertOptions();

  function doAdd(path, host) {
    document.querySelectorAll('.lg-add-pop').forEach(function (n) { n.remove(); });

    var inColumn = isColumnChild(path);

    var pop = document.createElement('div');
    pop.className = 'lg-tier-pop lg-add-pop';   /* reuse tier-pop chrome */
    pop.contentEditable = 'false';
    pop.innerHTML = '<div class="lg-tier-pop__title">Insert below</div>';

    INSERT_OPTIONS.forEach(function (opt) {
      if (inColumn && opt.value === 'columns') return;
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'lg-tier-pop__opt';
      b.textContent = opt.label;
      b.addEventListener('click', function (e) {
        e.preventDefault(); e.stopPropagation();
        pop.remove();
        insertAfter(path, opt.value);
      });
      pop.appendChild(b);
    });

    var pill = host.querySelector('.lg-edit-pill');
    if (pill) pill.parentNode.insertBefore(pop, pill.nextSibling);
    else host.appendChild(pop);

    setTimeout(function () {
      function dismiss(e) {
        if (e.type === 'keydown' && e.key !== 'Escape') return;
        if (e.type === 'click' && pop.contains(e.target)) return;
        pop.remove();
        document.removeEventListener('click', dismiss, true);
        document.removeEventListener('keydown', dismiss, true);
      }
      document.addEventListener('click', dismiss, true);
      document.addEventListener('keydown', dismiss, true);
    }, 0);
  }

  function insertAfter(path, newType) {
    if (!path.length) return;
    var index = path[path.length - 1] + 1;     /* slot just after the current block */
    var parent = (path[path.length - 2] === 'blocks')
      ? path.slice(0, -2)
      : path.slice(0, -1);
    markPendingEdit(childPath(parent, index));
    rest('insert', { parent_path: parent, index: index, block: { type: newType } })
      .then(reload, function (err) { flashError('Insert failed: ' + err.message); });
  }

  /* ── Variant picker ──────────────────────────────────────────────── */

  /**
   * Variant switcher — popover lists the keys of manifest.variants for
   * this block type. Picking a variant fires an optimistic class swap on
   * the host (lg-<type>--old → lg-<type>--new) so the visual flips
   * instantly, then persists the new value to the prop named by
   * editor.variant_prop. On error we revert the class.
   */
  function doVariant(type, path, host) {
    var manifest = MANIFESTS[type] || {};
    var prop     = manifest.editor && manifest.editor.variant_prop;
    var options  = manifest.variants || [];
    if (!prop || !options.length) { flashError('No variants defined for ' + type); return; }

    /* Find the element that carries the lg-<type>--<variant> modifier
       class. By convention it's the block's selector element. */
    var visual = findEditable(host, prop) || host.querySelector('.lg-' + type) || host;
    var current = '';
    visual.classList.forEach(function (c) {
      var m = c.match(new RegExp('^lg-' + type + '--(.+)$'));
      if (m) current = m[1];
    });

    document.querySelectorAll('.lg-variant-pop').forEach(function (n) { n.remove(); });
    var pop = document.createElement('div');
    pop.className = 'lg-tier-pop lg-variant-pop';
    pop.contentEditable = 'false';
    pop.innerHTML = '<div class="lg-tier-pop__title">Variant</div>';

    var labels = manifest.variant_labels || {};
    options.forEach(function (val) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'lg-tier-pop__opt' + (val === current ? ' is-current' : '');
      b.textContent = labels[val] || val;
      b.addEventListener('click', function (e) {
        e.preventDefault(); e.stopPropagation();
        if (val === current) { pop.remove(); return; }
        /* Optimistic class swap so the change registers visually before
           the reload kicks in, then re-render server-side so any
           variant-specific HTML (not just CSS class) is correct. */
        var prevClass = 'lg-' + type + '--' + current;
        var nextClass = 'lg-' + type + '--' + val;
        visual.classList.remove(prevClass);
        visual.classList.add(nextClass);
        pop.remove();
        var payload = {}; payload[prop] = val;
        rest('update', { path: path, props: payload })
          .then(reload, function (err) {
            visual.classList.remove(nextClass);
            visual.classList.add(prevClass);
            flashError('Variant save failed: ' + err.message);
          });
      });
      pop.appendChild(b);
    });

    var pill = host.querySelector('.lg-edit-pill');
    if (pill) pill.parentNode.insertBefore(pop, pill.nextSibling);
    else host.appendChild(pop);

    setTimeout(function () {
      function dismiss(e) {
        if (e.type === 'keydown' && e.key !== 'Escape') return;
        if (e.type === 'click' && pop.contains(e.target)) return;
        pop.remove();
        document.removeEventListener('click', dismiss, true);
        document.removeEventListener('keydown', dismiss, true);
      }
      document.addEventListener('click', dismiss, true);
      document.addEventListener('keydown', dismiss, true);
    }, 0);
  }

  /* ── Change block type ───────────────────────────────────────────── */

  /* Types the picker offers. Columns is excluded — nesting columns is
     forbidden by the validator, and changing a columns block to another
     type would silently drop its child blocks. */
  /* Change-this-block-to picker — similar set as insert, but excludes
     containers (columns would orphan children) on top of the singleton
     exclusions. */
  function buildChangeOptions() {
    var exclude = { 'post-header': true, 'post-footer': true, 'columns': true };
    return Object.keys(MANIFESTS)
      .filter(function (name) {
        var m = MANIFESTS[name];
        return m && m.editor && m.editor.insertable && !exclude[name];
      })
      .sort()
      .map(function (name) { return { value: name, label: labelFor(name) }; });
  }
  var CHANGE_OPTIONS = buildChangeOptions();

  function doChange(currentType, path, host) {
    document.querySelectorAll('.lg-change-pop').forEach(function (n) { n.remove(); });

    var pop = document.createElement('div');
    pop.className = 'lg-tier-pop lg-change-pop';   /* reuse tier-pop chrome */
    pop.contentEditable = 'false';
    pop.innerHTML = '<div class="lg-tier-pop__title">Change to</div>';

    CHANGE_OPTIONS.forEach(function (opt) {
      if (opt.value === currentType) return;   /* hide the current type */
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'lg-tier-pop__opt';
      b.textContent = opt.label;
      b.addEventListener('click', function (e) {
        e.preventDefault(); e.stopPropagation();
        pop.remove();
        swapBlockType(path, opt.value);
      });
      pop.appendChild(b);
    });

    var pill = host.querySelector('.lg-edit-pill');
    if (pill) pill.parentNode.insertBefore(pop, pill.nextSibling);
    else host.appendChild(pop);

    setTimeout(function () {
      function dismiss(e) {
        if (e.type === 'keydown' && e.key !== 'Escape') return;
        if (e.type === 'click' && pop.contains(e.target)) return;
        pop.remove();
        document.removeEventListener('click', dismiss, true);
        document.removeEventListener('keydown', dismiss, true);
      }
      document.addEventListener('click', dismiss, true);
      document.addEventListener('keydown', dismiss, true);
    }, 0);
  }

  /**
   * Replace the block at `path` with a freshly-inserted block of `newType`
   * at the same position. Two REST calls (delete + insert) because we don't
   * have a single "replace" endpoint; structure changed so reload after.
   *
   * Path conventions reminder (mirrors EditorRest::children_bucket):
   *   - Root child:      path = [i]                     → insert parent = []
   *   - Column child:    path = [r, "columns", c, "blocks", j]
   *                          → insert parent = [r, "columns", c]
   */
  function swapBlockType(path, newType) {
    if (!path.length) return;
    var index = path[path.length - 1];
    var parent = (path[path.length - 2] === 'blocks')
      ? path.slice(0, -2)
      : path.slice(0, -1);

    markPendingEdit(childPath(parent, index));
    rest('delete', { path: path })
      .then(function () {
        return rest('insert', { parent_path: parent, index: index, block: { type: newType } });
      })
      .then(reload, function (err) {
        flashError('Change failed: ' + err.message);
      });
  }

  function doDelete(path) {
    if (!window.confirm('Delete this block?')) return;
    rest('delete', { path: path }).then(reload, function (e) { flashError(e.message); });
  }

  /* Find an inline-editable element. Heading's data-lg-edit-prop is on
     the host itself (the <h2>), so querySelector — which only looks at
     descendants — misses it. Check the host too. */
  function findEditable(host, prop) {
    var sel = prop ? '[data-lg-edit-prop="' + prop + '"]' : '[data-lg-edit-prop]';
    if (host.matches && host.matches(sel)) return host;
    return host.querySelector(sel);
  }

  /**
   * Strip editor chrome (pills, overlays, popovers, add-zones) from a
   * clone of `el` and return its innerHTML. Used wherever we serialize
   * an editable block for save or for handing off to TinyMCE — the
   * chrome must never end up in the persisted content.
   */
  var CHROME_SELECTOR = '.lg-edit-pill, .lg-edit-img-overlay, .lg-add-zone, .lg-tier-pop, .lg-add-pop, .lg-change-pop, .lg-ratio-pop';
  function chromeFreeHtml(el) {
    var clone = el.cloneNode(true);
    clone.querySelectorAll(CHROME_SELECTOR).forEach(function (n) { n.remove(); });
    return clone.innerHTML;
  }

  function doEdit(type, path, host) {
    /* Edit enters a per-block "edit mode" that surfaces every editable
       affordance at once:
         - image block: grey-out + overlay "Edit Image" button that opens
           the picker, AND focus the first inline-editable caption.
         - callout block: list-style variants open the items modal;
           prose variants (note/quote) drop into the inline body editor.
         - other custom-picker blocks (embed): open the picker directly.
         - text-only blocks (heading, wysiwyg): focus the editable text.
       Reload after a save returns the page to its non-edit state. */
    host.classList.add('lg-edit-active');

    var manifest = MANIFESTS[type] || {};
    var picker = manifest.editor && manifest.editor.custom_picker;
    var focusTarget = findEditable(host);

    if (picker === 'image') {
      ensureImageOverlay(host, path);
      if (focusTarget) { focusTarget.focus(); placeCaretAtEnd(focusTarget); }
      return;
    }
    if (type === 'callout') {
      var state = readCalloutState(host);
      var listVariants = { links: 1, files: 1, people: 1, data: 1 };
      if (listVariants[state.variant]) {
        openItemsModal(host, path, type, state.items || []);
      }
      /* Always also focus title (or body on prose variants) so the author
         can hit "edit" and immediately type. The modal sits on top until
         dismissed; closing it returns focus naturally. */
      if (focusTarget) { focusTarget.focus(); placeCaretAtEnd(focusTarget); }
      return;
    }
    if (picker) return runCustomPicker(picker, type, path, host);
    if (focusTarget) { focusTarget.focus(); placeCaretAtEnd(focusTarget); return; }
    flashError('No editor wired for ' + type);
  }

  /** Read the editor-mode-only state JSON the callout render.php emitted
   *  inside the host. Falls back to empty defaults if missing/malformed. */
  function readCalloutState(host) {
    var node = host.querySelector('script[data-lg-callout-state]');
    if (!node) return { variant: 'links', items: [] };
    try { return JSON.parse(node.textContent || '{}'); }
    catch (e) { return { variant: 'links', items: [] }; }
  }

  /* Inject the overlay once per image host; clicking it opens wp.media. */
  function ensureImageOverlay(host, path) {
    if (host.querySelector('.lg-edit-img-overlay')) return;
    var img = host.querySelector('.lg-image__frame') || host.querySelector('.lg-image__image') || host;
    if (getComputedStyle(img).position === 'static') img.style.position = 'relative';
    /* Kill native HTML5 drag on the underlying <img>. Without this, a real
       mousedown on the focal dot followed by even one pixel of movement onto
       the image triggers browser drag-and-drop, which suppresses the
       document-level pointermove/pointerup listeners — the dot visibly
       follows the cursor's drag ghost but no REST save ever fires. */
    var innerImg = host.querySelector('img');
    if (innerImg) {
      innerImg.draggable = false;
      innerImg.setAttribute('draggable', 'false');
      /* Kill native HTML5 drag at every reachable layer. preventDefault on
         pointerdown is NOT sufficient — the underlying mousedown still
         initiates a drag-and-drop on <img>, which preempts our pan
         pointermove/pointerup at the document level. */
      innerImg.addEventListener('mousedown',  function (e) { e.preventDefault(); });
      innerImg.addEventListener('dragstart',  function (e) { e.preventDefault(); return false; });
      innerImg.ondragstart = function () { return false; };
    }
    host.addEventListener('dragstart', function (e) { e.preventDefault(); });
    var overlay = document.createElement('div');
    overlay.className = 'lg-edit-img-overlay';
    overlay.contentEditable = 'false';

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'lg-edit-img-overlay__btn';
    btn.textContent = 'Edit Image';
    btn.addEventListener('click', function (e) {
      e.preventDefault(); e.stopPropagation();
      openMediaPicker(path);
    });
    overlay.appendChild(btn);

    /* Crop tools — aspect-ratio dropdown + draggable focal-point dot.
       Each change PUTs through the existing update REST so it's the same
       data path as anything else on the block. Page reloads on success
       so the renderer's `aspect-ratio` + `object-position` rendering is
       always the source of truth. */
    var aspectPick = document.createElement('select');
    aspectPick.className = 'lg-edit-img-overlay__aspect';
    aspectPick.title = 'Crop aspect ratio';
    var ASPECTS = [
      ['',     'No crop'],
      ['1/1',  'Square 1:1'],
      ['4/3',  'Photo 4:3'],
      ['3/2',  'Photo 3:2'],
      ['16/9', 'Wide 16:9'],
      ['21/9', 'Ultrawide 21:9'],
      ['3/4',  'Portrait 3:4'],
      ['2/3',  'Portrait 2:3'],
      ['9/16', 'Phone 9:16'],
    ];
    var currentAspect = host.getAttribute('data-lg-aspect') || '';
    ASPECTS.forEach(function (a) {
      var opt = document.createElement('option');
      opt.value = a[0]; opt.textContent = a[1];
      if (a[0] === currentAspect) opt.selected = true;
      aspectPick.appendChild(opt);
    });
    aspectPick.addEventListener('click', function (e) { e.stopPropagation(); });
    aspectPick.addEventListener('change', function () {
      rest('update', { path: path, props: { aspect: aspectPick.value } })
        .then(reload, function (err) { flashError('Aspect save failed: ' + err.message); });
    });
    overlay.appendChild(aspectPick);

    /* Direct image-pan drag: when aspect crop is on, the source image
       overflows the frame in at least one axis. Author grabs the image and
       drags it around within the frame — like Instagram crop. Live preview
       updates object-position as the cursor moves; release commits the
       computed focal_x/y via REST.

       Math: object-position X% means the source's X% point aligns with
       the frame's X% point. With overflow OX = renderedW - frameW, a
       drag of Δx pixels (to the right) shifts the source right by Δx,
       which equates to a focal_x change of -100 * Δx / OX. Drag down
       likewise decreases focal_y. Clamped to [0,100]. */
    var fx = parseInt(host.getAttribute('data-lg-focal-x') || '50', 10);
    var fy = parseInt(host.getAttribute('data-lg-focal-y') || '50', 10);
    if (currentAspect !== '' && innerImg) {
      innerImg.classList.add('lg-edit-img-pannable');
      var panState = null; /* { startX, startY, startFx, startFy, ox, oy, moved } */
      var suppressNextClick = false;
      var zoom = parseInt(host.getAttribute('data-lg-zoom') || '100', 10);
      var dirty = false;
      function applyPos() {
        innerImg.style.objectPosition = fx + '% ' + fy + '%';
        if (zoom !== 100) {
          innerImg.style.transform = 'scale(' + (zoom/100) + ')';
          innerImg.style.transformOrigin = fx + '% ' + fy + '%';
        } else {
          innerImg.style.transform = '';
          innerImg.style.transformOrigin = '';
        }
        if (saveBtn) saveBtn.classList.toggle('is-dirty', dirty);
      }
      function commitSave() {
        if (!dirty) return;
        rest('update', { path: path, props: { focal_x: fx, focal_y: fy, zoom: zoom } })
          .then(reload, function (err) { flashError('Save failed: ' + err.message); });
      }
      /* Compute the visible overflow of the source within the frame. With
         object-fit: cover the natural image scales to cover the frame; the
         zoom transform multiplies that further. Returned overflow drives
         pan-drag sensitivity (1px of drag = 100/overflow % of focal). */
      function computeOverflow() {
        var frame = host.querySelector('.lg-image__frame');
        var fr = frame.getBoundingClientRect();
        var nw = innerImg.naturalWidth  || 1;
        var nh = innerImg.naturalHeight || 1;
        var scale = Math.max(fr.width / nw, fr.height / nh) * (zoom / 100);
        return {
          ox: Math.max(0, nw * scale - fr.width),
          oy: Math.max(0, nh * scale - fr.height),
        };
      }
      function onPanMove(ev) {
        if (!panState) return;
        ev.preventDefault();
        var dx = ev.clientX - panState.startX;
        var dy = ev.clientY - panState.startY;
        if (Math.abs(dx) + Math.abs(dy) > 3) panState.moved = true;
        var nfx = panState.ox > 0 ? panState.startFx - (100 * dx / panState.ox) : panState.startFx;
        var nfy = panState.oy > 0 ? panState.startFy - (100 * dy / panState.oy) : panState.startFy;
        fx = Math.max(0, Math.min(100, Math.round(nfx)));
        fy = Math.max(0, Math.min(100, Math.round(nfy)));
        applyPos();
      }
      function onPanUp() {
        if (!panState) return;
        var moved = panState.moved;
        panState = null;
        document.removeEventListener('pointermove', onPanMove);
        document.removeEventListener('pointerup',   onPanUp);
        document.removeEventListener('pointercancel', onPanUp);
        if (!moved) return; /* tap with no drag — let click pass through normally */
        suppressNextClick = true;
        dirty = true;
        applyPos(); /* repaint to update the Save button's dirty indicator */
      }
      innerImg.addEventListener('pointerdown', function (e) {
        /* Only left-button pan. Don't interfere with right-click / multi-touch. */
        if (e.button !== 0) return;
        e.preventDefault(); e.stopPropagation();
        innerImg.focus();
        var ov = computeOverflow();
        panState = {
          startX: e.clientX, startY: e.clientY,
          startFx: fx, startFy: fy,
          ox: ov.ox, oy: ov.oy, moved: false,
        };
        document.addEventListener('pointermove',   onPanMove);
        document.addEventListener('pointerup',     onPanUp);
        document.addEventListener('pointercancel', onPanUp);
      });
      /* Suppress the click that follows a real pan, so the lightbox doesn't
         open on release. Capture-phase + stopImmediatePropagation beats the
         lg-front.js click handler regardless of attach order. */
      innerImg.addEventListener('click', function (e) {
        if (suppressNextClick) {
          suppressNextClick = false;
          e.preventDefault(); e.stopImmediatePropagation();
        }
      }, true);

      /* Arrow-key panning: click the image to focus, then arrows nudge
         focal_x/y by 2% per press (shift = 10% for coarse moves).
         Updates local state only; user clicks Save to commit. */
      innerImg.setAttribute('tabindex', '0');
      innerImg.addEventListener('keydown', function (e) {
        /* Arrow direction mirrors mouse-drag: arrow-down = image moves down
           = source's top portion comes into view = focal_y decreases. */
        var arrows = { ArrowLeft:[ 1,0 ], ArrowRight:[ -1,0 ], ArrowUp:[ 0,1 ], ArrowDown:[ 0,-1 ] };
        var d = arrows[e.key];
        if (!d) return;
        e.preventDefault();
        var step = e.shiftKey ? 10 : 2;
        fx = Math.max(0, Math.min(100, fx + d[0] * step));
        fy = Math.max(0, Math.min(100, fy + d[1] * step));
        dirty = true;
        applyPos();
      });

      /* Zoom +/- buttons — increments of 25% from 100% to 300%. */
      var zoomIn = document.createElement('button');
      zoomIn.type = 'button';
      zoomIn.className = 'lg-edit-img-overlay__zoom lg-edit-img-overlay__zoom--in';
      zoomIn.title = 'Zoom in';
      zoomIn.textContent = '+';
      var zoomOut = document.createElement('button');
      zoomOut.type = 'button';
      zoomOut.className = 'lg-edit-img-overlay__zoom lg-edit-img-overlay__zoom--out';
      zoomOut.title = 'Zoom out';
      zoomOut.textContent = '−';
      function adjustZoom(delta) {
        var nz = Math.max(100, Math.min(300, zoom + delta));
        if (nz === zoom) return;
        zoom = nz;
        dirty = true;
        applyPos();
      }
      zoomIn.addEventListener('click',  function (e) { e.stopPropagation(); adjustZoom(25); });
      zoomOut.addEventListener('click', function (e) { e.stopPropagation(); adjustZoom(-25); });
      overlay.appendChild(zoomIn);
      overlay.appendChild(zoomOut);

      /* Save button — explicit commit so author can zoom+pan freely without
         each interaction round-tripping to the server. Visible "is-dirty"
         marker shows when there are unsaved changes. */
      var saveBtn = document.createElement('button');
      saveBtn.type = 'button';
      saveBtn.className = 'lg-edit-img-overlay__save';
      saveBtn.title = 'Save crop';
      saveBtn.textContent = 'Save';
      saveBtn.addEventListener('click', function (e) { e.stopPropagation(); commitSave(); });
      overlay.appendChild(saveBtn);
    }

    img.appendChild(overlay);
  }

  /* Refresh the tier-pill button's text from the host's current tier. */
  function updateTierPillLabel(host) {
    var btn = host.querySelector('.lg-edit-pill__btn--tier');
    if (btn) btn.textContent = 'Tier: ' + (TIER_SHORT[host._lgTier] || 'public');
  }

  /* Tier picker — inline popover next to the pill, no native confirm. */
  var TIER_OPTIONS = [
    { value: '',           label: 'Public'    },
    { value: 'looth-lite', label: 'Lite'      },
    { value: 'looth-pro',  label: 'Pro'       },
    { value: 'admin',      label: 'Admin only'},
  ];
  function doTier(path, host) {
    /* Close any existing picker — only one at a time. */
    document.querySelectorAll('.lg-tier-pop').forEach(function (n) { n.remove(); });

    var pop = document.createElement('div');
    pop.className = 'lg-tier-pop';
    pop.contentEditable = 'false';
    pop.innerHTML = '<div class="lg-tier-pop__title">Visible to</div>';

    var current = host._lgTier || '';
    TIER_OPTIONS.forEach(function (opt) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'lg-tier-pop__opt' + (opt.value === current ? ' is-current' : '');
      b.textContent = opt.label;
      b.addEventListener('click', function (e) {
        e.preventDefault(); e.stopPropagation();
        if (opt.value === current) { pop.remove(); return; }

        /* Optimistic: update local state + pill label immediately, fire REST
           in the background. Tier changes don't alter what admins see (they
           bypass gates), so reloading the whole page is wasted bandwidth +
           wasted time. On error we revert and surface the failure. */
        var prev = host._lgTier || '';
        host._lgTier = opt.value;
        updateTierPillLabel(host);
        pop.remove();

        rest('update', { path: path, props: {}, gated_tier: opt.value })
          .catch(function (err) {
            host._lgTier = prev;
            updateTierPillLabel(host);
            flashError('Tier save failed: ' + err.message);
          });
      });
      pop.appendChild(b);
    });

    /* Anchor under the pill — pill is `position:absolute` already, the
       popover is its sibling so it inherits the same positioning context. */
    var pill = host.querySelector('.lg-edit-pill');
    if (pill) pill.parentNode.insertBefore(pop, pill.nextSibling);
    else host.appendChild(pop);

    /* Dismiss on next outside click / Escape. */
    setTimeout(function () {
      function dismiss(e) {
        if (e.type === 'keydown' && e.key !== 'Escape') return;
        if (e.type === 'click' && pop.contains(e.target)) return;
        pop.remove();
        document.removeEventListener('click', dismiss, true);
        document.removeEventListener('keydown', dismiss, true);
      }
      document.addEventListener('click', dismiss, true);
      document.addEventListener('keydown', dismiss, true);
    }, 0);
  }

  /* Ratio (embed): popover picker, optimistic local apply. The embed
     block sets aspect-ratio inline on .lg-embed__frame, so we mirror
     the value there immediately and persist in the background. */
  var RATIO_OPTIONS = [
    { value: '16/9', label: '16 : 9 (widescreen)' },
    { value: '4/3',  label: '4 : 3' },
    { value: '1/1',  label: '1 : 1 (square)' },
    { value: '9/16', label: '9 : 16 (vertical)' },
    { value: '21/9', label: '21 : 9 (cinematic)' },
  ];
  function currentRatio(host) {
    var frame = host.querySelector('.lg-embed__frame');
    if (!frame) return '';
    var v = (frame.style.aspectRatio || '').replace(/\s+/g, '');
    if (v) return v;
    /* aspectRatio shorthand serializes as "16 / 9" sometimes; also try the
       computed style as a fallback. */
    var cs = getComputedStyle(frame).aspectRatio || '';
    return cs.replace(/\s+/g, '');
  }

  function doRatio(path, host) {
    document.querySelectorAll('.lg-ratio-pop').forEach(function (n) { n.remove(); });

    var pop = document.createElement('div');
    pop.className = 'lg-tier-pop lg-ratio-pop';   /* reuse tier-pop chrome */
    pop.contentEditable = 'false';
    pop.innerHTML = '<div class="lg-tier-pop__title">Aspect ratio</div>';

    var current = currentRatio(host);
    RATIO_OPTIONS.forEach(function (opt) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'lg-tier-pop__opt' + (opt.value === current ? ' is-current' : '');
      b.textContent = opt.label;
      b.addEventListener('click', function (e) {
        e.preventDefault(); e.stopPropagation();
        if (opt.value === current) { pop.remove(); return; }

        var frame = host.querySelector('.lg-embed__frame');
        var prev = frame ? frame.style.aspectRatio : '';
        if (frame) frame.style.aspectRatio = opt.value;
        pop.remove();

        rest('update', { path: path, props: { aspect_ratio: opt.value } })
          .catch(function (err) {
            if (frame) frame.style.aspectRatio = prev;
            flashError('Ratio save failed: ' + err.message);
          });
      });
      pop.appendChild(b);
    });

    var pill = host.querySelector('.lg-edit-pill');
    if (pill) pill.parentNode.insertBefore(pop, pill.nextSibling);
    else host.appendChild(pop);

    setTimeout(function () {
      function dismiss(e) {
        if (e.type === 'keydown' && e.key !== 'Escape') return;
        if (e.type === 'click' && pop.contains(e.target)) return;
        pop.remove();
        document.removeEventListener('click', dismiss, true);
        document.removeEventListener('keydown', dismiss, true);
      }
      document.addEventListener('click', dismiss, true);
      document.addEventListener('keydown', dismiss, true);
    }, 0);
  }

  /* ── Custom pickers ──────────────────────────────────────────────── */

  function runCustomPicker(picker, type, path, host) {
    if (picker === 'embed-url') {
      var url = window.prompt('Embed URL:');
      if (url) rest('update', { path: path, props: { url: url } }).then(reload, function (e) { flashError(e.message); });
      return;
    }
    if (picker === 'image') {
      openMediaPicker(path);
      return;
    }
    if (picker === 'gallery') {
      openGalleryPicker(path);
      return;
    }
    if (picker === 'rich-text') {
      openRichTextModal(host, path);
      return;
    }
    flashError('Unknown picker: ' + picker);
  }

  /* ── Gallery picker (wp.media multi-select) ──────────────────────────
     Opens wp.media in gallery-edit mode so the author sees the current
     image_ids preselected, can reorder via drag, add new ones from the
     library, or drop ones they no longer want. Updates image_ids in one
     REST round-trip; reloads so the gallery block re-renders with the new
     attachment URLs + captions. */
  var _galleryFrame = null;
  function openGalleryPicker(path) {
    if (!window.wp || !window.wp.media) { flashError('wp.media not loaded'); return; }

    /* Read the current image_ids off the DOM so wp.media can preselect them.
       The gallery block emits <div class="lg-gallery__tile"><img …> per real
       tile; placeholders have no <img>. Attachment IDs aren't in the markup
       directly, so we re-fetch from the REST block payload (simpler than
       carrying ids in data-attrs and double-emitting them).

       For the first cut: open the picker with NO preselection. After picking,
       we send the full new list. Authors lose the "reorder existing" UX in
       round one but get the more important "pick / replace" flow. */
    var frame = window.wp.media({
      title:    'Pick gallery images',
      button:   { text: 'Use these images' },
      library:  { type: 'image' },
      multiple: 'add',
    });
    _galleryFrame = frame;

    frame.on('select', function () {
      var sel = frame.state().get('selection').toJSON();
      if (!sel.length) return;
      var image_ids = sel.map(function (a) { return a.id; });
      rest('update', { path: path, props: { image_ids: image_ids } })
        .then(reload, function (e) { flashError(e.message); });
    });
    frame.open();
  }

  /* ── Media picker (wp.media) ─────────────────────────────────────── */

  /**
   * Reuse one frame instance per call site so the same modal opens on
   * subsequent clicks (cheaper, plus preserves the selection state).
   * Library is scoped to attachments uploaded_to this post — exactly what
   * the author needs for "edit the image" UX. They can still browse the
   * whole library via the toolbar.
   */
  var _mediaFrame = null;
  function openMediaPicker(path) {
    if (!window.wp || !window.wp.media) { flashError('wp.media not loaded'); return; }
    if (!_mediaFrame) {
      _mediaFrame = window.wp.media({
        title: 'Pick image',
        button: { text: 'Use this image' },
        library: { type: 'image', uploaded_to_id: CFG.post_id },
        multiple: false,
      });
    }
    /* The path the picker should save into changes per-click; rebind the
       select handler each open to capture the current path. */
    _mediaFrame.off('select');
    _mediaFrame.on('select', function () {
      var att = _mediaFrame.state().get('selection').first();
      if (!att) return;
      var data = att.toJSON();

      /* Optimistic: swap the on-page <img> immediately, then persist in
         the background. The wp.media modal closes itself on select; no
         reload, no waiting for the round-trip. On error we revert. */
      var imgEl = findImgElement(path);
      var prevSrc = imgEl ? imgEl.src : null;
      var prevAlt = imgEl ? imgEl.alt : '';
      var prevCap = imgEl ? imgEl.dataset.lgCaption : '';
      if (imgEl) {
        imgEl.src = data.url;
        imgEl.alt = data.alt || '';
      }

      rest('update', { path: path, props: { image_id: data.id, url: data.url, alt: data.alt || '' } })
        .then(function () {
          /* Exit edit mode so the grey overlay + "Edit Image" button
             clear once the new image is in place. */
          var host = imgEl && imgEl.closest('.lg-edit-host');
          if (host) {
            host.classList.remove('lg-edit-active');
            var ov = host.querySelector('.lg-edit-img-overlay');
            if (ov) ov.remove();
          }
        })
        .catch(function (e) {
          flashError('Image save failed: ' + e.message);
          if (imgEl && prevSrc !== null) {
            imgEl.src = prevSrc;
            imgEl.alt = prevAlt;
            imgEl.dataset.lgCaption = prevCap || '';
          }
        });
    });
    _mediaFrame.open();
  }

  /* Locate the <img> element of the block at `path` by walking up from
     the marker that originally carried that path (we stashed it on the
     host as _lgPath). */
  function findImgElement(path) {
    var hosts = document.querySelectorAll('.lg-edit-host');
    for (var i = 0; i < hosts.length; i++) {
      if (JSON.stringify(hosts[i]._lgPath) === JSON.stringify(path)) {
        return hosts[i].querySelector('.lg-image__img');
      }
    }
    return null;
  }

  /* ── Rich-text (TinyMCE) modal ───────────────────────────────────── */

  /**
   * Open a modal hosting the full WP classic editor (TinyMCE + Quicktags).
   * Pre-fills with the block's current html, saves through the REST update
   * endpoint, then reloads the page so the server-rendered HTML is the
   * single source of truth.
   */
  var _rtTextareaId = 'lg-fe-rt-' + Math.floor(Math.random() * 1e9);
  function openRichTextModal(host, path) {
    if (!window.wp || !window.wp.editor) { flashError('wp.editor not loaded'); return; }
    var current = findEditable(host, 'html');
    var html = current ? chromeFreeHtml(current) : '';

    /* Build the modal shell once per click. Always teardown on close so
       a stale TinyMCE instance can't trap focus. */
    var backdrop = document.createElement('div');
    backdrop.className = 'lg-rt-modal';
    backdrop.innerHTML =
      '<div class="lg-rt-modal__panel" role="dialog" aria-modal="true">' +
        '<div class="lg-rt-modal__title">Edit rich text</div>' +
        '<textarea id="' + _rtTextareaId + '" class="lg-rt-modal__ta"></textarea>' +
        '<div class="lg-rt-modal__bar">' +
          '<button type="button" class="lg-rt-modal__btn lg-rt-modal__btn--cancel">Cancel</button>' +
          '<button type="button" class="lg-rt-modal__btn lg-rt-modal__btn--save">Save</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(backdrop);

    var ta = document.getElementById(_rtTextareaId);
    ta.value = html;

    /* Initialize the editor on the textarea. mediaButtons:true gives the
       "Add Media" toolbar button, reusing the same wp.media stack we
       already enqueue for the image picker. */
    wp.editor.initialize(_rtTextareaId, {
      tinymce: {
        wpautop: true,
        plugins: 'charmap colorpicker hr lists media paste tabfocus textcolor fullscreen wordpress wpautoresize wpeditimage wpemoji wpgallery wplink wptextpattern',
        toolbar1: 'formatselect bold italic bullist numlist blockquote alignleft aligncenter alignright link unlink wp_more spellchecker fullscreen wp_adv',
        toolbar2: 'strikethrough hr forecolor pastetext removeformat charmap outdent indent undo redo wp_help',
      },
      quicktags: true,
      mediaButtons: true,
    });

    function teardown() {
      try { wp.editor.remove(_rtTextareaId); } catch (e) {}
      backdrop.remove();
    }

    backdrop.querySelector('.lg-rt-modal__btn--cancel').addEventListener('click', teardown);
    backdrop.addEventListener('click', function (e) {
      if (e.target === backdrop) teardown();
    });
    backdrop.querySelector('.lg-rt-modal__btn--save').addEventListener('click', function () {
      var next = wp.editor.getContent(_rtTextareaId);
      rest('update', { path: path, props: { html: next } })
        .then(function () { teardown(); reload(); }, function (err) { flashError('Save failed: ' + err.message); });
    });
  }

  /* ── Items repeater modal (callout list variants) ───────────────── */

  /* Build a modal with a row-per-item editor over the callout's items
     array. State lives in the DOM (form-control values + row order);
     Save serializes back to an array and PUTs via the existing update
     endpoint. Reload on success — server-rendered HTML stays the source
     of truth. */
  function openItemsModal(host, path, type, items) {
    var manifest = MANIFESTS[type] || {};
    var itemSchema = (((manifest.schema || {}).props || {}).items || {}).items || {};
    var itemProps  = itemSchema.props || {};
    var primary = ['icon', 'label', 'url', 'description'].filter(function (k) { return itemProps[k]; });
    var extras  = Object.keys(itemProps).filter(function (k) { return primary.indexOf(k) === -1; });
    var ICONS   = (window.LG_FE_EDITOR && LG_FE_EDITOR.icons) || {};
    var iconKeys = Object.keys(ICONS).sort();

    var backdrop = document.createElement('div');
    backdrop.className = 'lg-items-modal';
    backdrop.innerHTML =
      '<div class="lg-items-modal__panel" role="dialog" aria-modal="true">' +
        '<div class="lg-items-modal__title">Edit items</div>' +
        '<div class="lg-items-modal__rows" data-rows></div>' +
        '<button type="button" class="lg-items-modal__add" data-add>+ Add row</button>' +
        '<div class="lg-items-modal__bar">' +
          '<button type="button" class="lg-items-modal__btn lg-items-modal__btn--cancel" data-cancel>Cancel</button>' +
          '<button type="button" class="lg-items-modal__btn lg-items-modal__btn--save" data-save>Save</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(backdrop);

    var rowsEl = backdrop.querySelector('[data-rows]');
    (items || []).forEach(function (row) { rowsEl.appendChild(buildRow(row || {})); });
    if (!(items || []).length) rowsEl.appendChild(buildRow({}));

    backdrop.querySelector('[data-add]').addEventListener('click', function () {
      var row = buildRow({});
      rowsEl.appendChild(row);
      var firstInput = row.querySelector('input[data-prop="label"]');
      if (firstInput) firstInput.focus();
    });

    function teardown() { backdrop.remove(); }
    backdrop.querySelector('[data-cancel]').addEventListener('click', teardown);
    backdrop.addEventListener('click', function (e) { if (e.target === backdrop) teardown(); });

    /* Esc to cancel — standard modal UX. */
    function onKey(e) { if (e.key === 'Escape') { teardown(); document.removeEventListener('keydown', onKey); } }
    document.addEventListener('keydown', onKey);

    backdrop.querySelector('[data-save]').addEventListener('click', function () {
      var nextItems = serializeRows();
      rest('update', { path: path, props: { items: nextItems } }).then(
        function () { teardown(); reload(); },
        function (err) { flashError('Save failed: ' + err.message); }
      );
    });

    function buildRow(row) {
      var el = document.createElement('div');
      el.className = 'lg-items-row';
      el.setAttribute('data-row', '');

      var ctrls = document.createElement('div');
      ctrls.className = 'lg-items-row__ctrls';
      ctrls.innerHTML =
        '<button type="button" class="lg-items-row__btn" data-up    title="Move up">↑</button>' +
        '<button type="button" class="lg-items-row__btn" data-down  title="Move down">↓</button>' +
        '<button type="button" class="lg-items-row__btn lg-items-row__btn--del" data-del title="Remove row">×</button>';
      el.appendChild(ctrls);

      var fields = document.createElement('div');
      fields.className = 'lg-items-row__fields';
      primary.forEach(function (pk) { fields.appendChild(buildField(pk, row[pk])); });

      if (extras.length) {
        var det = document.createElement('details');
        det.className = 'lg-items-row__extras';
        /* Auto-open if any extras carry a value. */
        for (var i = 0; i < extras.length; i++) {
          if (row[extras[i]]) { det.open = true; break; }
        }
        var sum = document.createElement('summary');
        sum.textContent = 'More fields (' + extras.join(', ') + ')';
        det.appendChild(sum);
        var extraFields = document.createElement('div');
        extraFields.className = 'lg-items-row__fields';
        extras.forEach(function (ek) { extraFields.appendChild(buildField(ek, row[ek])); });
        det.appendChild(extraFields);
        fields.appendChild(det);
      }
      el.appendChild(fields);

      ctrls.querySelector('[data-up]').addEventListener('click', function () {
        var prev = el.previousElementSibling;
        if (prev) rowsEl.insertBefore(el, prev);
      });
      ctrls.querySelector('[data-down]').addEventListener('click', function () {
        var next = el.nextElementSibling;
        if (next) rowsEl.insertBefore(next, el);
      });
      ctrls.querySelector('[data-del]').addEventListener('click', function () { el.remove(); });
      return el;
    }

    function buildField(prop, value) {
      var lab = document.createElement('label');
      lab.className = 'lg-items-row__field lg-items-row__field--' + prop;
      var sp = document.createElement('span');
      sp.textContent = prop;
      lab.appendChild(sp);
      if (prop === 'icon') {
        var wrap = document.createElement('span');
        wrap.className = 'lg-items-row__icon-wrap';
        var sel = document.createElement('select');
        sel.setAttribute('data-prop', 'icon');
        iconKeys.forEach(function (k) {
          var opt = document.createElement('option');
          opt.value = k; opt.textContent = k;
          if (k === (value || 'link')) opt.selected = true;
          sel.appendChild(opt);
        });
        wrap.appendChild(sel);
        var prev = document.createElement('span');
        prev.className = 'lg-items-row__icon-preview';
        prev.innerHTML = ICONS[sel.value] || ICONS['link'] || '';
        sel.addEventListener('change', function () {
          prev.innerHTML = ICONS[sel.value] || ICONS['link'] || '';
        });
        wrap.appendChild(prev);
        lab.appendChild(wrap);
      } else {
        var inp = document.createElement('input');
        inp.type = 'text';
        inp.setAttribute('data-prop', prop);
        inp.value = value == null ? '' : String(value);
        lab.appendChild(inp);
      }
      return lab;
    }

    function serializeRows() {
      var out = [];
      var allProps = primary.concat(extras);
      rowsEl.querySelectorAll('[data-row]').forEach(function (row) {
        var obj = {};
        var hasContent = false;
        allProps.forEach(function (p) {
          var el = row.querySelector('[data-prop="' + p + '"]');
          if (!el) return;
          var v = (el.value == null ? '' : String(el.value)).trim();
          if (v) { obj[p] = v; }
          /* Defaults: icon's default is 'link'. A row with ONLY icon=link
             and no other content is treated as empty (matches the metabox
             parser). */
          if (p === 'icon') { if (v && v !== 'link') hasContent = true; }
          else if (v) { hasContent = true; }
        });
        if (hasContent) out.push(obj);
      });
      return out;
    }
  }

  /* ── Inline contenteditable ──────────────────────────────────────── */

  function wireInlineEditable(host, type, path) {
    var manifest = MANIFESTS[type] || {};
    var props = (manifest.editor && manifest.editor.inline_editable_props) || [];
    var schemaProps = (manifest.schema && manifest.schema.props) || {};
    /* HTML-mode props: prop literally named 'html' (legacy), or anything
       the manifest tagged as format=html (e.g. callout.body). Those round-trip
       innerHTML; everything else round-trips innerText. */
    function isHtmlProp(p) {
      return p === 'html' || (schemaProps[p] && schemaProps[p].format === 'html');
    }
    props.forEach(function (prop) {
      var el = findEditable(host, prop);
      if (!el || el._lgEditable) return;
      el._lgEditable = true;
      el.contentEditable = 'true';
      el.spellcheck = true;
      el.dataset.lgOriginal = isHtmlProp(prop) ? chromeFreeHtml(el) : el.innerText;

      el.addEventListener('blur', function () {
        var next = isHtmlProp(prop) ? chromeFreeHtml(el) : el.innerText;
        if (next === el.dataset.lgOriginal) return;
        var props_payload = {};
        props_payload[prop] = next;
        rest('update', { path: path, props: props_payload }).then(function () {
          el.dataset.lgOriginal = next;
        }, function (e) {
          flashError(e.message);
          if (isHtmlProp(prop)) el.innerHTML = el.dataset.lgOriginal;
          else                  el.innerText = el.dataset.lgOriginal;
        });
      });

      /* Enter on a heading commits + moves on; Shift+Enter inserts a break
         (default browser behavior). Keeps the heading single-line UX. */
      if (prop === 'text') {
        el.addEventListener('keydown', function (e) {
          if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            el.blur();
          }
        });
      }
    });
  }

  function placeCaretAtEnd(el) {
    var range = document.createRange();
    range.selectNodeContents(el);
    range.collapse(false);
    var sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
  }

  /* ── Boot ───────────────────────────────────────────────────────── */

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { wireMarkers(); });
  } else {
    wireMarkers();
  }

  /* Intercept the "Exit editor" link so the async re-bake settles before we
     land on the standalone view (see exitToBarePermalink). Capture phase so
     we beat the default navigation. */
  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a.lg-fe-edit-btn.is-active');
    if (!a) return;
    var href = a.getAttribute('href');
    if (!href) return;
    e.preventDefault();
    exitToBarePermalink(href);
  }, true);

  /* Expose for future use (e.g. drop-zone inserts that need to re-wire). */
  window.LG_FE_EDITOR_API = { wireMarkers: wireMarkers, rest: rest };
})();
