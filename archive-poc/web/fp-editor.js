/**
 * fp-editor.js — front-page editor. ADMIN ONLY, LOADED ON INTENT.
 *
 * The page never links this file. index.php emits a small launcher button for
 * verified admins only; the launcher injects fp-editor.css + this script on the
 * first click, and Quill is fetched only when a rich-text region is opened.
 * Anon and members ship none of it (craft law: editors load on intent).
 *
 * TRUST MODEL — this file is a convenience, never an authority:
 *   - The UI gate is index.php's server-side manage_options check.
 *   - The WRITE gate is api/v0/fp-save.php: WP login cookie ->
 *     current_user_can('manage_options') inside a booted WP + a WP nonce.
 *   - HTML is sanitized server-side at api/v0/_config.php, the single write
 *     boundary. Nothing here is trusted to clean anything.
 * A non-admin who forces this script to run gets a 403 from the save proxy.
 *
 * WHAT IT EDITS (all pre-existing config — no new sources of truth):
 *   What's new copy   -> rows[<video-promo row>].query.html   (rich text)
 *   Featured video    -> rows[<same row>].query.video_id/.aspect + row title
 *   Featured member   -> featured_member{}
 *   Greeting line     -> member_greeting.body
 *   Rails             -> sponsors[] / local_looths[] / cta_member[] / cta_public[]
 */

(function () {
  'use strict';

  var API = '/archive-api/v0/fp-save';
  var QUILL_VER = '2.0.3';   // pinned to composer-v2's version (hub-polish.js)

  var S = {
    nonce: '',
    config: null,
    patch: {},        // pending top-level key edits
    rowPatch: null,   // pending { id, title?, query? }
    undo: null,       // last published payload's "before" snapshot
    saving: false,
    bar: null,
    panel: null
  };

  /* ── tiny helpers ─────────────────────────────────────────────────────── */
  function el(tag, cls, html) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (html != null) n.innerHTML = html;
    return n;
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function qs(sel, root) { return (root || document).querySelector(sel); }
  function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function dirty() { return Object.keys(S.patch).length > 0 || S.rowPatch !== null; }

  /* ── the row that carries the What's-new copy + featured video ────────── */
  function promoSection() { return qs('.row--video-promo'); }
  function promoRowId() {
    var sec = promoSection();
    var host = sec && sec.closest('[data-row-id]');
    return host ? host.getAttribute('data-row-id') : '';
  }
  /** Current query for that row, straight from the effective config. */
  function promoQuery() {
    var id = promoRowId(), rows = (S.config && S.config.rows) || [];
    for (var i = 0; i < rows.length; i++) {
      if (rows[i] && rows[i].id === id) return rows[i].query || {};
    }
    return {};
  }
  function promoTitle() {
    var id = promoRowId(), rows = (S.config && S.config.rows) || [];
    for (var i = 0; i < rows.length; i++) if (rows[i] && rows[i].id === id) return rows[i].title || '';
    return '';
  }

  /* ── region table ─────────────────────────────────────────────────────── */
  var REGIONS = [
    { key: 'whats-new', label: "What’s new", kind: 'rich',
      find: function () { return qsa('.row--video-promo .vpromo__copy'); } },
    { key: 'video', label: 'Featured video', kind: 'panel',
      find: function () { return qsa('.row--video-promo .vpromo__video'); } },
    { key: 'member', label: 'Featured member', kind: 'panel',
      find: function () { return qsa('.row--featured-member'); } },
    { key: 'greeting', label: 'Greeting', kind: 'panel',
      find: function () { return qsa('.signup-banner--member .signup-banner__body'); } },
    { key: 'sponsors', label: 'Sponsors', kind: 'list',
      find: function () { return qsa('.side-row--sponsors'); } },
    { key: 'looths', label: 'Local looths', kind: 'list',
      find: function () { return qsa('.side-row--looths'); } },
    { key: 'cta', label: 'Buttons', kind: 'list',
      find: function () { return qsa('.side-row--cta'); } }
  ];
  function region(key) {
    for (var i = 0; i < REGIONS.length; i++) if (REGIONS[i].key === key) return REGIONS[i];
    return null;
  }

  /* ── pencils ──────────────────────────────────────────────────────────── */
  var PENCIL_SVG =
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" ' +
    'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
    '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>';

  function attachPencils() {
    REGIONS.forEach(function (r) {
      r.find().forEach(function (host) {
        if (!host || host.querySelector(':scope > .fpe-pencil')) return;
        host.setAttribute('data-fpe', r.key);
        var b = el('button', 'fpe-pencil', PENCIL_SVG + '<span>Edit</span>');
        b.type = 'button';
        b.setAttribute('aria-label', 'Edit ' + r.label);
        b.addEventListener('click', function (e) {
          e.preventDefault(); e.stopPropagation();
          if (r.kind === 'rich') openRich(host);
          else openPanel(r.key);
        });
        host.appendChild(b);
      });
    });
  }
  function removePencils() {
    qsa('.fpe-pencil').forEach(function (n) { n.remove(); });
    qsa('[data-fpe]').forEach(function (n) { n.removeAttribute('data-fpe'); });
  }

  /* ── Quill, on intent ─────────────────────────────────────────────────── */
  var quillCbs = null;
  function quillReady(cb) {
    if (window.Quill) { registerFormats(); cb(); return; }
    if (quillCbs) { quillCbs.push(cb); return; }
    quillCbs = [cb];
    var l = el('link'); l.rel = 'stylesheet';
    l.href = 'https://cdn.jsdelivr.net/npm/quill@' + QUILL_VER + '/dist/quill.core.css';
    document.head.appendChild(l);
    var s = el('script');
    s.src = 'https://cdn.jsdelivr.net/npm/quill@' + QUILL_VER + '/dist/quill.js';
    s.onload = function () {
      registerFormats();
      var cbs = quillCbs; quillCbs = null;
      cbs.forEach(function (f) { try { f(); } catch (e) { console.error('[fp-editor]', e); } });
    };
    s.onerror = function () {
      quillCbs = null;
      say('Could not load the text editor (CDN blocked?).', 'err');
    };
    document.head.appendChild(s);
  }

  /**
   * The authored front-page copy leans on classes Quill knows nothing about —
   * p.vp-eyebrow, a.vp-cta, hr.vp-divider — plus a[data-feedback]. Vanilla Quill
   * would silently drop all of it on the first round-trip, quietly flattening a
   * designed block into plain paragraphs. The server-side whitelist KEEPS those
   * (see _html-sanitize.php), so the only thing that has to be taught is Quill.
   */
  var formatsDone = false;
  function registerFormats() {
    if (formatsDone || !window.Quill) return;
    formatsDone = true;
    try {
      var Parchment = window.Quill.import('parchment');

      // class on block elements (p.vp-eyebrow and friends)
      var BlockClass = new Parchment.ClassAttributor('fpeclass', '', { scope: Parchment.Scope.BLOCK });
      // Parchment's ClassAttributor prefixes values; we want the class verbatim,
      // so override add/value/remove to pass classes straight through.
      BlockClass.add = function (node, value) {
        if (!value) return false;
        String(value).split(/\s+/).forEach(function (c) { if (c) node.classList.add(c); });
        return true;
      };
      BlockClass.value = function (node) {
        var keep = [];
        node.classList.forEach(function (c) { if (c.indexOf('ql-') !== 0) keep.push(c); });
        return keep.join(' ');
      };
      BlockClass.remove = function (node) {
        node.classList.forEach(function (c) { if (c.indexOf('ql-') !== 0) node.classList.remove(c); });
      };
      window.Quill.register({ 'formats/fpeclass': BlockClass }, true);

      // <hr class="vp-divider"> — a block embed so it survives untouched
      var BlockEmbed = window.Quill.import('blots/block/embed');
      var RuleBlot = class extends BlockEmbed {
        static create(value) {
          var node = super.create();
          if (value && value.cls) node.setAttribute('class', value.cls);
          return node;
        }
        static value(node) { return { cls: node.getAttribute('class') || '' }; }
      };
      RuleBlot.blotName = 'fpehr';
      RuleBlot.tagName = 'HR';
      window.Quill.register(RuleBlot, true);

      // links keep class + data-feedback alongside href
      var Link = window.Quill.import('formats/link');
      var RichLink = class extends Link {
        static create(value) {
          var v = (value && typeof value === 'object') ? value : { href: value };
          var node = super.create(v.href);
          if (v.cls) node.setAttribute('class', v.cls);
          if (v.feedback != null) node.setAttribute('data-feedback', v.feedback);
          return node;
        }
        static formats(node) {
          return {
            href: node.getAttribute('href') || '',
            cls: node.getAttribute('class') || '',
            feedback: node.hasAttribute('data-feedback') ? (node.getAttribute('data-feedback') || '') : null
          };
        }
      };
      RichLink.blotName = 'link';
      window.Quill.register(RichLink, true);
    } catch (e) {
      // Never fatal: worst case is a plainer round-trip, and the admin is told.
      console.error('[fp-editor] format registration failed', e);
      say('Heads-up: rich formatting may simplify on save.', 'err');
    }
  }

  /* ── in-place rich text ───────────────────────────────────────────────── */
  var live = null;   // { host, before, cls, quill, tools, acts }
  function openRich(host) {
    if (live) return;
    var pencil = host.querySelector(':scope > .fpe-pencil');
    if (pencil) pencil.remove();

    var before = host.innerHTML;
    var cls = host.className;
    host.classList.add('fpe-live');

    var tools = el('div', 'fpe-tools');
    tools.innerHTML =
      '<button type="button" data-f="bold" title="Bold" aria-label="Bold"><b>B</b></button>' +
      '<button type="button" data-f="italic" title="Italic" aria-label="Italic"><span class="fpe-i">I</span></button>' +
      '<button type="button" data-f="strike" title="Strikethrough" aria-label="Strikethrough"><span class="fpe-s">S</span></button>' +
      '<button type="button" data-f="link" title="Link" aria-label="Link">&#128279;</button>' +
      '<button type="button" data-f="bullet" title="Bulleted list" aria-label="Bulleted list">&bull;&#8213;</button>' +
      '<button type="button" data-f="ordered" title="Numbered list" aria-label="Numbered list">1.</button>';
    host.parentNode.insertBefore(tools, host);

    var acts = el('div', 'fpe-act');
    acts.innerHTML =
      '<button type="button" class="fpe-btn fpe-btn--go" data-a="ok">Save</button>' +
      '<button type="button" class="fpe-btn fpe-btn--off" data-a="no">Cancel</button>' +
      '<span class="fpe-act__note">Formatting is cleaned on the server when you publish.</span>';
    host.parentNode.insertBefore(acts, host.nextSibling);

    live = { host: host, before: before, cls: cls, tools: tools, acts: acts, quill: null };

    quillReady(function () {
      if (!live) return;
      try {
        live.quill = new window.Quill(host, { modules: { toolbar: false } });
      } catch (e) {
        console.error('[fp-editor] Quill mount failed', e);
        say('Could not open the text editor.', 'err');
        teardownRich(true);
        return;
      }
      live.quill.focus();
      syncToolbar();
      live.quill.on('selection-change', syncToolbar);
      live.quill.on('text-change', syncToolbar);
    });

    tools.addEventListener('click', function (e) {
      var btn = e.target.closest('button[data-f]');
      if (!btn || !live || !live.quill) return;
      e.preventDefault();
      var q = live.quill, f = btn.getAttribute('data-f');
      var sel = q.getSelection(true);
      var now = q.getFormat(sel || undefined);
      if (f === 'link') {
        var cur = now.link && now.link.href ? now.link.href : '';
        var url = window.prompt('Link URL (leave blank to remove)', cur);
        if (url === null) return;
        if (url === '') q.format('link', false);
        else q.format('link', { href: url, cls: (now.link && now.link.cls) || '' });
      } else if (f === 'bullet' || f === 'ordered') {
        q.format('list', now.list === f ? false : f);
      } else {
        q.format(f, !now[f]);
      }
      syncToolbar();
    });

    acts.addEventListener('click', function (e) {
      var btn = e.target.closest('button[data-a]');
      if (!btn) return;
      e.preventDefault();
      if (btn.getAttribute('data-a') === 'no') { teardownRich(true); return; }
      var html = '';
      try {
        html = live.quill ? live.quill.getSemanticHTML() : live.before;
      } catch (err) {
        console.error('[fp-editor]', err);
        say('Could not read the edited text.', 'err');
        return;
      }
      // Quill escapes non-breaking spaces as &nbsp; entities; harmless, but keep
      // the payload tidy so the stored HTML stays diff-friendly.
      html = html.replace(/&nbsp;/g, ' ').trim();
      stageRow({ query: { html: html } });
      teardownRich(false, html);
      say('“What’s new” edited — publish to make it live.');
    });
  }

  function syncToolbar() {
    if (!live || !live.quill) return;
    var now = live.quill.getFormat(live.quill.getSelection() || undefined);
    qsa('button[data-f]', live.tools).forEach(function (b) {
      var f = b.getAttribute('data-f'), on = false;
      if (f === 'bullet' || f === 'ordered') on = now.list === f;
      else if (f === 'link') on = !!now.link;
      else on = !!now[f];
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
  }

  function teardownRich(restore, savedHtml) {
    if (!live) return;
    var L = live; live = null;
    try { if (L.quill) L.quill.off('selection-change', syncToolbar); } catch (e) {}
    L.tools.remove(); L.acts.remove();
    // Quill turns the host into .ql-container with .ql-editor/.ql-clipboard
    // children; replacing innerHTML + restoring the class list undoes all of it.
    L.host.className = L.cls;
    L.host.removeAttribute('contenteditable');
    L.host.innerHTML = restore ? L.before : (savedHtml != null ? savedHtml : L.before);
    attachPencils();
  }

  /* ── staging ──────────────────────────────────────────────────────────── */
  function stageRow(part) {
    var id = promoRowId();
    if (!id) { say('Could not identify the row to save.', 'err'); return; }
    S.rowPatch = S.rowPatch || { id: id };
    if (part.title != null) S.rowPatch.title = part.title;
    if (part.query) {
      S.rowPatch.query = S.rowPatch.query || {};
      Object.keys(part.query).forEach(function (k) { S.rowPatch.query[k] = part.query[k]; });
    }
    renderBar();
  }
  function stageKey(key, val) { S.patch[key] = val; renderBar(); }

  /* ── panels ───────────────────────────────────────────────────────────── */
  function field(label, value, hint, type) {
    return '<div class="fpe-f"><label>' + esc(label) + '</label>' +
      (type === 'textarea'
        ? '<textarea data-v>' + esc(value) + '</textarea>'
        : '<input type="text" data-v value="' + esc(value) + '">') +
      (hint ? '<span class="fpe-f__hint">' + hint + '</span>' : '') + '</div>';
  }

  var PANELS = {
    video: {
      title: 'Featured video',
      body: function () {
        var q = promoQuery();
        var vid = q.video_id || '';
        return '' +
          '<div class="fpe-f"><label>YouTube link or ID</label>' +
          '<input type="text" data-k="video_id" value="' + esc(vid) + '">' +
          '<span class="fpe-f__hint">Paste a watch, youtu.be, shorts or embed link — ' +
          'the ID is pulled out on the server.</span></div>' +
          '<div class="fpe-f"><label>Row heading</label>' +
          '<input type="text" data-k="title" value="' + esc(promoTitle()) + '"></div>' +
          '<div class="fpe-f"><label>Shape</label><select data-k="aspect">' +
          ['16x9', '4x3', '1x1', '9x16'].map(function (a) {
            return '<option value="' + a + '"' + ((q.aspect || '16x9') === a ? ' selected' : '') + '>' +
              a + (a === '16x9' ? ' (widescreen)' : a === '9x16' ? ' (vertical / shorts)' : '') + '</option>';
          }).join('') + '</select></div>' +
          (vid ? '<div class="fpe-f"><label>Current</label><img class="fpe-thumb" alt="" ' +
            'width="320" height="180" src="https://i.ytimg.com/vi/' + esc(vid) + '/mqdefault.jpg"></div>' : '');
      },
      apply: function (root) {
        var vid = qs('[data-k="video_id"]', root).value.trim();
        var ttl = qs('[data-k="title"]', root).value.trim();
        var asp = qs('[data-k="aspect"]', root).value;
        stageRow({ title: ttl, query: { video_id: vid, aspect: asp } });
        return 'Featured video edited';
      }
    },

    member: {
      title: 'Featured member',
      body: function () {
        var m = (S.config && S.config.featured_member) || {};
        return '' +
          '<div class="fpe-f fpe-f--check"><input type="checkbox" id="fpe-fm-on" data-k="enabled"' +
          (m.enabled === false ? '' : ' checked') + '>' +
          '<label for="fpe-fm-on">Show the featured-member band</label></div>' +
          '<div class="fpe-f"><label>Name</label><input type="text" data-k="name" value="' + esc(m.name || '') + '"></div>' +
          '<div class="fpe-f"><label>Role / shop</label><input type="text" data-k="role" value="' + esc(m.role || '') + '"></div>' +
          '<div class="fpe-f"><label>Where</label><input type="text" data-k="where" value="' + esc(m.where || '') + '"></div>' +
          '<div class="fpe-f"><label>Blurb</label><textarea data-k="bio">' + esc(m.bio || '') + '</textarea></div>' +
          '<div class="fpe-f"><label>Avatar URL</label><input type="text" data-k="avatar" value="' + esc(m.avatar || '') + '">' +
          '<span class="fpe-f__hint">A profile-media or uploads URL.</span></div>' +
          '<div class="fpe-f"><label>Button label</label><input type="text" data-k="cta_label" value="' + esc(m.cta_label || '') + '"></div>' +
          '<div class="fpe-f"><label>Button link</label><input type="text" data-k="cta_href" value="' + esc(m.cta_href || '') + '"></div>';
      },
      apply: function (root) {
        var m = {};
        // Start from the saved object so fields the panel doesn't show survive.
        var cur = (S.config && S.config.featured_member) || {};
        Object.keys(cur).forEach(function (k) { m[k] = cur[k]; });
        qsa('[data-k]', root).forEach(function (i) {
          m[i.getAttribute('data-k')] = i.type === 'checkbox' ? i.checked : i.value.trim();
        });
        stageKey('featured_member', m);
        return 'Featured member edited';
      }
    },

    greeting: {
      title: 'Member greeting',
      body: function () {
        var g = (S.config && S.config.member_greeting) || {};
        return field('Greeting line', g.body || '',
          'Sits under “Welcome back, ‹first name›.” Plain text.');
      },
      apply: function (root) {
        stageKey('member_greeting', { body: qs('[data-v]', root).value.trim() });
        return 'Greeting edited';
      }
    },

    sponsors: listPanel('Sponsors', 'sponsors',
      [['name', 'Name'], ['url', 'Link'], ['logo', 'Logo URL'], ['bg', 'Tile background']], 'logo'),
    looths: listPanel('Local looths', 'local_looths',
      [['name', 'Name'], ['url', 'Link'], ['avatar', 'Avatar URL']], 'avatar'),
    cta: null   // built at open time — member vs public
  };

  /**
   * A reorderable list of flat records. Up/down rather than drag: keyboard- and
   * touch-reachable, and there is no drag library on this page to borrow.
   */
  function listPanel(title, key, fields, imgField) {
    return {
      title: title,
      key: key,
      body: function () {
        var rows = (S.config && S.config[key]) || [];
        return '<p class="fpe-f__hint">' + rows.length + ' item' + (rows.length === 1 ? '' : 's') +
          '. Use ↑ ↓ to reorder.</p><div class="fpe-list" data-list>' +
          rows.map(function (r, i) { return listItem(r, i, fields, imgField); }).join('') +
          '</div><button type="button" class="fpe-btn fpe-btn--off" data-add ' +
          'style="justify-self:start">+ Add item</button>';
      },
      apply: function (root) {
        var out = [];
        qsa('.fpe-item', root).forEach(function (it) {
          var rec = {};
          try { rec = JSON.parse(it.getAttribute('data-rec') || '{}'); } catch (e) { rec = {}; }
          qsa('[data-f]', it).forEach(function (inp) { rec[inp.getAttribute('data-f')] = inp.value.trim(); });
          var any = false;
          Object.keys(rec).forEach(function (k) { if (String(rec[k] || '') !== '') any = true; });
          if (any) out.push(rec);
        });
        stageKey(key, out);
        return title + ' edited';
      },
      fields: fields,
      imgField: imgField
    };
  }

  function listItem(rec, i, fields, imgField) {
    var img = imgField && rec[imgField]
      ? '<img class="fpe-item__img" src="' + esc(rec[imgField]) + '" alt="" loading="lazy">'
      : '<span class="fpe-item__img"></span>';
    return '<div class="fpe-item" data-rec="' + esc(JSON.stringify(rec)) + '">' +
      img +
      '<span class="fpe-item__name">' + esc(rec.name || rec.label || '(untitled)') + '</span>' +
      '<span class="fpe-item__acts">' +
        '<button type="button" data-mv="-1" aria-label="Move up">&#9650;</button>' +
        '<button type="button" data-mv="1" aria-label="Move down">&#9660;</button>' +
        '<button type="button" data-ed aria-label="Edit fields">&#9998;</button>' +
        '<button type="button" data-rm aria-label="Remove">&#10005;</button>' +
      '</span>' +
      '<div class="fpe-item__form" hidden>' +
        fields.map(function (f) {
          return '<div class="fpe-f"><label>' + esc(f[1]) + '</label>' +
            '<input type="text" data-f="' + f[0] + '" value="' + esc(rec[f[0]] || '') + '"></div>';
        }).join('') +
      '</div></div>';
  }

  function openPanel(key) {
    var spec = PANELS[key];
    if (key === 'cta') {
      // The rail renders member buttons to members and public buttons to anon.
      // An admin is a member, so the visible rail is cta_member; offer both.
      spec = listPanel('Member buttons', 'cta_member',
        [['label', 'Label'], ['url', 'Link'], ['style', 'Style (primary/secondary/ghost)']], null);
      spec.alt = 'cta_public';
    }
    if (!spec) return;

    var p = S.panel;
    qs('.fpe-panel__hd span', p).textContent = spec.title;
    var body = qs('.fpe-panel__bd', p);
    body.innerHTML = spec.body();

    // list behaviours
    var list = qs('[data-list]', body);
    if (list) {
      renumber(list);   // set the end-stop arrows before the first interaction
      list.addEventListener('click', function (e) {
        var it = e.target.closest('.fpe-item'); if (!it) return;
        if (e.target.closest('[data-rm]')) { it.remove(); renumber(list); return; }
        if (e.target.closest('[data-ed]')) {
          var f = qs('.fpe-item__form', it);
          f.hidden = !f.hidden; it.classList.toggle('is-open', !f.hidden);
          return;
        }
        var mv = e.target.closest('[data-mv]');
        if (mv) {
          var dir = parseInt(mv.getAttribute('data-mv'), 10);
          if (dir < 0 && it.previousElementSibling) list.insertBefore(it, it.previousElementSibling);
          if (dir > 0 && it.nextElementSibling) list.insertBefore(it.nextElementSibling, it);
          renumber(list);
        }
      });
      var add = qs('[data-add]', body);
      if (add) add.addEventListener('click', function () {
        list.insertAdjacentHTML('beforeend', listItem({}, 0, spec.fields, spec.imgField));
        var last = list.lastElementChild;
        var f = qs('.fpe-item__form', last); f.hidden = false; last.classList.add('is-open');
        renumber(list);
      });
    }

    var ft = qs('.fpe-panel__ft', p);
    ft.innerHTML = '<button type="button" class="fpe-btn fpe-btn--off" data-x>Cancel</button>' +
                   '<button type="button" class="fpe-btn fpe-btn--go" data-ok>Apply</button>';
    qs('[data-x]', ft).onclick = closePanel;
    qs('[data-ok]', ft).onclick = function () {
      var msg;
      try { msg = spec.apply(body); }
      catch (e) { console.error('[fp-editor]', e); say('Could not apply those changes.', 'err'); return; }
      closePanel();
      say(msg + ' — publish to make it live.');
    };

    p.classList.add('is-open');
    p.setAttribute('aria-hidden', 'false');
    var first = qs('input,textarea,select', body);
    if (first) first.focus();
  }
  function renumber(list) {
    qsa('.fpe-item', list).forEach(function (it, i) {
      var up = qs('[data-mv="-1"]', it), dn = qs('[data-mv="1"]', it);
      up.disabled = i === 0;
      dn.disabled = i === qsa('.fpe-item', list).length - 1;
    });
  }
  function closePanel() {
    S.panel.classList.remove('is-open');
    S.panel.setAttribute('aria-hidden', 'true');
  }

  /* ── status bar / publish ─────────────────────────────────────────────── */
  function say(msg, kind) {
    var m = qs('.fpe-bar__msg', S.bar);
    m.textContent = msg || '';
    m.className = 'fpe-bar__msg' + (kind ? ' fpe-bar__msg--' + kind : '');
    renderBar(true);
  }
  function renderBar(keepMsg) {
    if (!S.bar) return;
    var n = Object.keys(S.patch).length + (S.rowPatch ? 1 : 0);
    qs('[data-pub]', S.bar).disabled = S.saving || n === 0;
    qs('[data-disc]', S.bar).disabled = S.saving || n === 0;
    var c = qs('.fpe-bar__count', S.bar);
    c.textContent = n === 0 ? 'No unsaved changes' : n + ' unsaved change' + (n > 1 ? 's' : '');
    if (!keepMsg) qs('.fpe-bar__msg', S.bar).textContent = '';
  }

  function publish() {
    if (S.saving || !dirty()) return;
    S.saving = true; renderBar(true);
    say('Publishing…');

    var payload = {};
    Object.keys(S.patch).forEach(function (k) { payload[k] = S.patch[k]; });
    if (S.rowPatch) payload.row_patch = S.rowPatch;

    fetch(API, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': S.nonce },
      body: JSON.stringify(payload)
    })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        S.saving = false;
        if (!res.ok || !res.j || res.j.ok !== true) {
          var why = (res.j && res.j.error) || 'unknown';
          say('Not published (' + why + '). Nothing was changed.', 'err');
          renderBar(true);
          return;
        }
        S.patch = {}; S.rowPatch = null;
        // Re-read the effective config so later edits start from what is stored.
        loadConfig(function () {
          say('Published. Reload to see the page exactly as visitors will.', 'ok');
        });
      })
      .catch(function (e) {
        S.saving = false;
        console.error('[fp-editor]', e);
        say('Network error — nothing was published.', 'err');
        renderBar(true);
      });
  }

  function discard() {
    if (!dirty()) return;
    if (!window.confirm('Discard your unsaved changes to this page?')) return;
    S.patch = {}; S.rowPatch = null;
    location.reload();
  }

  /* ── boot ─────────────────────────────────────────────────────────────── */
  function loadConfig(done) {
    fetch(API, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j || j.is_admin !== true) {
          say('The server did not confirm you as an admin — editing is off.', 'err');
          return;
        }
        S.nonce = j.nonce || '';
        S.config = j.config || {};
        if (done) done();
      })
      .catch(function (e) {
        console.error('[fp-editor]', e);
        say('Could not load the page settings.', 'err');
      });
  }

  function buildChrome() {
    S.bar = el('div', 'fpe-bar');
    S.bar.innerHTML =
      '<span class="fpe-bar__count">No unsaved changes</span>' +
      '<span class="fpe-bar__msg"></span>' +
      '<span class="fpe-bar__sp"></span>' +
      '<button type="button" class="fpe-btn fpe-btn--off" data-done>Done</button>' +
      '<button type="button" class="fpe-btn fpe-btn--off" data-disc disabled>Discard</button>' +
      '<button type="button" class="fpe-btn fpe-btn--go" data-pub disabled>Publish changes</button>';
    document.body.appendChild(S.bar);
    qs('[data-pub]', S.bar).addEventListener('click', publish);
    qs('[data-disc]', S.bar).addEventListener('click', discard);
    qs('[data-done]', S.bar).addEventListener('click', stop);

    S.panel = el('aside', 'fpe-panel');
    S.panel.setAttribute('aria-hidden', 'true');
    S.panel.innerHTML =
      '<div class="fpe-panel__hd"><span>Edit</span>' +
      '<button type="button" aria-label="Close">&times;</button></div>' +
      '<div class="fpe-panel__bd"></div><div class="fpe-panel__ft"></div>';
    document.body.appendChild(S.panel);
    qs('.fpe-panel__hd button', S.panel).addEventListener('click', closePanel);

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && S.panel.classList.contains('is-open')) closePanel();
    });
    window.addEventListener('beforeunload', function (e) {
      if (dirty()) { e.preventDefault(); e.returnValue = ''; }
    });
  }

  function start() {
    document.documentElement.classList.add('fpe-on');
    if (!S.bar) buildChrome();
    attachPencils();
    loadConfig(function () { say('Editing on — hover a section and click Edit.'); });
  }

  function stop() {
    if (dirty() && !window.confirm('You have unsaved changes. Leave editing without publishing?')) return;
    if (live) teardownRich(true);
    document.documentElement.classList.remove('fpe-on');
    removePencils();
    closePanel();
    S.patch = {}; S.rowPatch = null;
    if (S.bar) { S.bar.remove(); S.bar = null; }
    if (S.panel) { S.panel.remove(); S.panel = null; }
    var l = qs('.fpe-launch');
    if (l) { l.hidden = false; l.disabled = false; }
  }

  // index.php's inline launcher injects this file, then calls this.
  window.lgFpEditor = { start: start, stop: stop };
  if (window.__lgFpAutostart) start();
})();
