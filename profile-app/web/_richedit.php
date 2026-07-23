<?php
/**
 * profile-app/web/_richedit.php — the About rich-text editor (owner-only).
 *
 * Shared by /u/ (profile About) and /p/ (practice About): both render the About
 * body as a `.lg-richedit[data-edit-type="richtext"]` div carrying its own
 * data-edit-url / data-edit-method. This partial wires ONE document-level click
 * delegate that lazy-mounts Quill 2.0.3 (the composer stack's version) on owner
 * INTENT — never eagerly, never for a visitor (a visitor's About renders as a
 * plain <div> with no .lg-richedit, so nothing binds and Quill is never fetched;
 * CRAFT gate: editors load on intent, never eagerly for anon).
 *
 * The server is the source of truth: the API re-sanitizes the posted html to the
 * strict allowlist and returns the stored {html,text}; we paint back exactly that.
 *
 * Include once near the foot of an owner-rendered profile/practice template:
 *   <?php if ($isOwner) require __DIR__ . '/_richedit.php'; ?>
 */
declare(strict_types=1);
?>
<style>
/* Rich-text About editor (owner). Quill is lazy-loaded, so these style the panel
   we build + a couple of Quill overrides to match the profile card look. */
.lg-richedit{cursor:text}
.lg-richedit--loading{opacity:.6;cursor:progress}
.lg-rte{margin:2px 0 4px}
.lg-rte__editor{background:var(--lg-card-bg,#fff);border-radius:8px}
.lg-rte .ql-toolbar.ql-snow{border:1px solid var(--lg-line,#d7ddcf);border-bottom:0;border-radius:8px 8px 0 0;background:var(--lg-sage-tint,#f2f5ee)}
.lg-rte .ql-container.ql-snow{border:1px solid var(--lg-line,#d7ddcf);border-radius:0 0 8px 8px;font:inherit}
.lg-rte .ql-editor{min-height:120px;font-size:calc(14.5px*var(--lg-read-scale,1));line-height:1.6;color:var(--lg-ink,#2a2f26)}
.lg-rte .ql-editor.ql-blank::before{color:var(--lg-mute,#8a9282);font-style:normal}
.lg-rte__bar{display:flex;gap:8px;margin-top:8px}
.lg-rte__save,.lg-rte__cancel{border:0;border-radius:999px;font:700 calc(12.5px*var(--lg-read-scale,1))/1 var(--lg-font-sans,sans-serif);padding:9px 18px;cursor:pointer;min-height:38px}
.lg-rte__save{background:var(--lg-sage,#6f8a63);color:#fff}
.lg-rte__save:hover{filter:brightness(1.06)}
.lg-rte__save:disabled{opacity:.6;cursor:progress}
.lg-rte__cancel{background:var(--lg-sage-tint,#eef1e8);color:var(--lg-ink,#2a2f26)}
.lg-rte__cancel:hover{background:var(--lg-line,#d7ddcf)}
</style>
<script>
/* About rich-text editor — lazy Quill mount on owner intent (see _richedit.php). */
(function () {
  var QVER = '2.0.3';
  var QCSS = 'https://cdn.jsdelivr.net/npm/quill@' + QVER + '/dist/quill.snow.css';
  var QJS  = 'https://cdn.jsdelivr.net/npm/quill@' + QVER + '/dist/quill.js';
  var loadingPromise = null;
  var active = null;   // { el, panel, q }

  function loadQuill() {
    if (window.Quill) return Promise.resolve();
    if (loadingPromise) return loadingPromise;
    loadingPromise = new Promise(function (resolve, reject) {
      if (!document.querySelector('link[data-lg-quill]')) {
        var l = document.createElement('link');
        l.rel = 'stylesheet'; l.href = QCSS; l.setAttribute('data-lg-quill', '1');
        document.head.appendChild(l);
      }
      var s = document.createElement('script');
      s.src = QJS; s.async = true;
      s.onload = function () { resolve(); };
      s.onerror = function () { loadingPromise = null; reject(new Error('quill load failed')); };
      document.head.appendChild(s);
    });
    return loadingPromise;
  }

  // Restricted toolbar (Ian): B/I/U/strike, blockquote, ordered/bullet lists, link,
  // clear-format. NO image button — the About body is text + links only.
  var TOOLBAR = [
    ['bold', 'italic', 'underline', 'strike'],
    ['blockquote'],
    [{ list: 'ordered' }, { list: 'bullet' }],
    ['link'],
    ['clean']
  ];

  function openEditor(el) {
    if (active) return;
    var url    = el.getAttribute('data-edit-url');
    var method = el.getAttribute('data-edit-method') || 'PATCH';
    if (!url) return;
    var wasEmpty  = el.classList.contains('lg-edit--empty');
    var startHtml = wasEmpty ? '' : el.innerHTML;
    el.classList.add('lg-richedit--loading');
    loadQuill().then(function () {
      el.classList.remove('lg-richedit--loading');
      mount(el, url, method, startHtml);
    }).catch(function () {
      el.classList.remove('lg-richedit--loading');
      alert('Could not load the editor — check your connection and try again.');
    });
  }

  function mount(el, url, method, startHtml) {
    var panel  = document.createElement('div'); panel.className = 'lg-rte';
    var edHost = document.createElement('div'); edHost.className = 'lg-rte__editor';
    var bar    = document.createElement('div'); bar.className = 'lg-rte__bar';
    var save   = document.createElement('button'); save.type = 'button'; save.className = 'lg-rte__save';   save.textContent = 'Save';
    var cancel = document.createElement('button'); cancel.type = 'button'; cancel.className = 'lg-rte__cancel'; cancel.textContent = 'Cancel';
    bar.appendChild(save); bar.appendChild(cancel);
    panel.appendChild(edHost); panel.appendChild(bar);
    el.style.display = 'none';
    el.parentNode.insertBefore(panel, el.nextSibling);

    var q = new window.Quill(edHost, {
      theme: 'snow',
      placeholder: el.getAttribute('data-edit-placeholder') || '',
      modules: { toolbar: TOOLBAR }
    });
    if (startHtml) { q.clipboard.dangerouslyPasteHTML(startHtml); }
    q.focus();
    active = { el: el, panel: panel, q: q };

    function teardown() { panel.remove(); el.style.display = ''; active = null; }

    cancel.addEventListener('click', teardown);

    save.addEventListener('click', function () {
      // Quill 2 renders bullet lists as <ol data-list="bullet">; getSemanticHTML()
      // emits proper <ul>/<ol> (which our allowlist keeps). Fall back to raw innerHTML.
      var html = (typeof q.getSemanticHTML === 'function') ? q.getSemanticHTML() : q.root.innerHTML;
      save.disabled = true; save.textContent = 'Saving…';
      fetch(url, {
        method: method, credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ html: html })
      })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
          if (!res.ok) { save.disabled = false; save.textContent = 'Save'; alert('Save failed: ' + ((res.j && res.j.error) || '?')); return; }
          // Paint back exactly what the server stored (already re-sanitized there).
          var d = (res.j && res.j.data) || {};
          var ph = el.getAttribute('data-edit-placeholder') || '';
          if (d.html) { el.innerHTML = d.html; el.classList.remove('lg-edit--empty'); }
          else if (d.text) { el.textContent = d.text; el.classList.remove('lg-edit--empty'); }
          else { el.textContent = ph; el.classList.add('lg-edit--empty'); }
          el.classList.add('saved'); setTimeout(function () { el.classList.remove('saved'); }, 900);
          teardown();
        })
        .catch(function () { save.disabled = false; save.textContent = 'Save'; alert('Network error.'); });
    });
  }

  document.addEventListener('click', function (e) {
    var el = e.target.closest('.lg-richedit[data-edit-type="richtext"]');
    if (!el || active) return;
    e.preventDefault();
    openEditor(el);
  }, false);
})();
</script>
