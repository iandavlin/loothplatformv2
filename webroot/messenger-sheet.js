/* messenger-sheet.js — Facebook-Messenger-style DM pull-up (mobile).
 *
 * Vanessa + Buck 2026-06-11: "I tried to send a message to Ian but it does not
 * feel natural what's popping up — give us a pull-up Messenger: home screen with
 * the chats, tap one to open the conversation, works like FB Messenger."
 *
 * Buck-owned client layer, loaded via /pwa.js, ≤640 only. Desktop keeps the
 * shared social modal (/srv/lg-shared/social-modals.js), which this layer
 * suppresses on mobile via CSS and supersedes for every entry point:
 *   • lg:open-dm {uuid} events (member cards / profile "Message" buttons)
 *   • the header messages icon ([data-lg-msg-link])
 *   • window.openMessenger() — the You-sheet "Messages" row (bottom-nav v20)
 *
 * API (canonical profile-app, contract from social-modals.js header):
 *   GET  /profile-api/v0/me/messages/          → {threads:[{id,uuid,unread_count,
 *          last_snippet,last_sender,peers:[{uuid,name,slug,avatar_url}]}]}
 *   GET  /profile-api/v0/me/messages/<uuid>    → {thread,peers,messages:[{id,
 *          sender_uuid,body,created_at}]}  (marks read; mine = sender not in peers)
 *   POST /profile-api/v0/me/messages/<uuid>    {body}            (reply)
 *   POST /profile-api/v0/me/messages/          {to_uuid, body}   (first message)
 */
(function () {
  'use strict';
  if (window.__loothMessenger) return;
  if (!window.matchMedia('(max-width:640px)').matches) return;
  try { if (window.top !== window.self) return; } catch (e) {}
  window.__loothMessenger = true;

  var API = '/profile-api/v0';
  var sheet = null, curThread = null, curPeer = null, pollT = null, listPollT = null;
  var threadsCache = [];
  var pendingFile = null;                       // staged image attachment for the next send
  var ATTACH_MAX = 5 * 1024 * 1024;
  var ATTACH_TYPES = { 'image/jpeg': 1, 'image/png': 1, 'image/webp': 1 };

  function esc(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function rel(ts) {
    try {
      var d = Date.parse(String(ts || '').replace(' ', 'T'));
      if (!d) return '';
      var s = (Date.now() - d) / 1000;
      if (s < 60) return 'now';
      if (s < 3600) return Math.floor(s / 60) + 'm';
      if (s < 86400) return Math.floor(s / 3600) + 'h';
      if (s < 604800) return Math.floor(s / 86400) + 'd';
      return Math.floor(s / 604800) + 'w';
    } catch (e) { return ''; }
  }

  function ensureCss() {
    if (document.getElementById('lg-msgr-css')) return;
    var D = 'html[data-lguser-theme="dark"]';
    var st = document.createElement('style'); st.id = 'lg-msgr-css';
    st.textContent = [
      // suppress the shared social modal on mobile — this sheet replaces it
      '@media (max-width:640px){#lg-social-modal{display:none!important}}',
      '#looth-msgr{position:fixed;inset:0;z-index:2147483570;display:none}',
      '#looth-msgr.is-open{display:block}',
      '#looth-msgr .mg-back{position:absolute;inset:0;background:rgba(15,16,12,.5);opacity:0;transition:opacity .26s ease}',
      '#looth-msgr.is-up .mg-back{opacity:1}',
      '#looth-msgr .mg-panel{position:absolute;left:0;right:0;bottom:0;top:max(4vh,env(safe-area-inset-top,0px));display:flex;flex-direction:column;' +
        'background:var(--lg-cream,#fbfbf8);border-radius:18px 18px 0 0;overflow:hidden;box-shadow:0 -10px 36px rgba(0,0,0,.28);' +
        'transform:translateY(100%);transition:transform .3s cubic-bezier(.32,.72,0,1);will-change:transform}',
      '#looth-msgr.is-up .mg-panel{transform:translateY(0)}',
      '#looth-msgr .mg-grab{flex:0 0 auto;height:20px;display:flex;align-items:center;justify-content:center;touch-action:none;cursor:grab}',
      '#looth-msgr .mg-grab::before{content:"";width:40px;height:5px;border-radius:3px;background:var(--lg-line,#d8d2c4)}',
      // home: header + search + thread list
      '#looth-msgr .mg-hd{flex:0 0 auto;display:flex;align-items:center;gap:10px;padding:0 14px 10px}',
      '#looth-msgr .mg-t{flex:1 1 auto;font:700 21px/1.2 var(--lg-font-serif,Georgia,serif);color:var(--lg-charcoal,#1a1d1a)}',
      '#looth-msgr .mg-x{flex:0 0 auto;width:34px;height:34px;border:0;border-radius:50%;background:var(--lg-sage-tint,#eef2e3);' +
        'color:var(--lg-sage-d,#6b7c52);font-size:19px;line-height:34px;text-align:center;cursor:pointer}',
      '#looth-msgr .mg-search{flex:0 0 auto;margin:0 14px 10px;display:flex;align-items:center;gap:8px;background:var(--lguser-bubble,#eceff3);' +
        'border-radius:999px;padding:9px 14px}',
      '#looth-msgr .mg-search svg{width:16px;height:16px;color:var(--lg-mute,#6b6f6b);flex:0 0 auto}',
      '#looth-msgr .mg-search input{flex:1 1 auto;min-width:0;border:0;background:none;outline:none;' +
        'font:15px/1.2 var(--lg-font-sans,system-ui,sans-serif);color:var(--lg-ink,#323532)}',
      '#looth-msgr .mg-list{flex:1 1 auto;overflow-y:auto;-webkit-overflow-scrolling:touch;padding:0 8px calc(20px + env(safe-area-inset-bottom,0px))}',
      '#looth-msgr .mg-row{display:flex;align-items:center;gap:12px;width:100%;text-align:left;border:0;background:none;' +
        'padding:9px 10px;border-radius:14px;cursor:pointer}',
      '#looth-msgr .mg-row:active{background:var(--lg-sage-tint,#eef2e3)}',
      '#looth-msgr .mg-avi{flex:0 0 auto;width:52px;height:52px;border-radius:50%;overflow:hidden;background:var(--lg-sage-3,#d4e0b8);' +
        'display:flex;align-items:center;justify-content:center;font:700 19px/1 var(--lg-font-serif,Georgia,serif);color:#fff}',
      '#looth-msgr .mg-avi img{width:100%;height:100%;object-fit:cover;display:block}',
      '#looth-msgr .mg-col{flex:1 1 auto;min-width:0;display:flex;flex-direction:column;gap:2px}',
      '#looth-msgr .mg-name{font:600 15.5px/1.25 var(--lg-font-sans,system-ui,sans-serif);color:var(--lg-charcoal,#1a1d1a);' +
        'white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
      '#looth-msgr .mg-snip{font:13.5px/1.3 var(--lg-font-sans,system-ui,sans-serif);color:var(--lg-mute,#6b6f6b);' +
        'white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
      '#looth-msgr .mg-row.is-unread .mg-name,#looth-msgr .mg-row.is-unread .mg-snip{font-weight:700;color:var(--lg-charcoal,#1a1d1a)}',
      '#looth-msgr .mg-meta{flex:0 0 auto;display:flex;flex-direction:column;align-items:flex-end;gap:5px}',
      '#looth-msgr .mg-time{font:12px/1 var(--lg-font-sans,system-ui,sans-serif);color:var(--lg-mute,#6b6f6b)}',
      '#looth-msgr .mg-dot{width:10px;height:10px;border-radius:50%;background:var(--lg-sage,#87986a)}',
      '#looth-msgr .mg-empty{padding:40px 18px;text-align:center;color:var(--lg-mute,#6b6f6b);font:14px/1.5 var(--lg-font-sans,system-ui,sans-serif)}',
      // chat view (slides over the home)
      '#looth-msgr .mg-chat{position:absolute;inset:0;display:none;flex-direction:column;background:var(--lg-cream,#fbfbf8);border-radius:18px 18px 0 0}',
      '#looth-msgr .mg-chat.is-on{display:flex}',
      '#looth-msgr .mg-chd{flex:0 0 auto;display:flex;align-items:center;gap:10px;padding:14px 12px 10px;border-bottom:1px solid var(--lg-line,#e3ddd0)}',
      '#looth-msgr .mg-backbtn{flex:0 0 auto;width:34px;height:34px;border:0;border-radius:50%;background:none;color:var(--lg-sage-d,#6b7c52);' +
        'font-size:21px;line-height:34px;text-align:center;cursor:pointer}',
      '#looth-msgr .mg-chd .mg-avi{width:36px;height:36px;font-size:14px}',
      '#looth-msgr .mg-chname{flex:1 1 auto;min-width:0;font:700 15.5px/1.2 var(--lg-font-sans,system-ui,sans-serif);color:var(--lg-charcoal,#1a1d1a);' +
        'white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
      '#looth-msgr .mg-msgs{flex:1 1 auto;overflow-y:auto;-webkit-overflow-scrolling:touch;padding:14px 12px;display:flex;flex-direction:column;gap:3px}',
      '#looth-msgr .mg-b{max-width:78%;padding:9px 13px;border-radius:18px;font:15px/1.4 var(--lg-font-sans,system-ui,sans-serif);' +
        'overflow-wrap:break-word;white-space:pre-wrap}',
      '#looth-msgr .mg-b--them{align-self:flex-start;background:var(--lguser-bubble,#eceff3);color:var(--lg-ink,#1a1d1a);border-bottom-left-radius:6px}',
      '#looth-msgr .mg-b--me{align-self:flex-end;background:var(--lg-sage,#87986a);color:#fff;border-bottom-right-radius:6px}',
      '#looth-msgr .mg-day{align-self:center;font:600 11px/1 var(--lg-font-sans,system-ui,sans-serif);color:var(--lg-mute,#6b6f6b);padding:10px 0 6px}',
      // composer (keyboard-aware)
      '#looth-msgr .mg-comp{flex:0 0 auto;display:flex;flex-direction:column;gap:8px;padding:9px 12px calc(9px + env(safe-area-inset-bottom,0px));' +
        'border-top:1px solid var(--lg-line,#e3ddd0);background:var(--lg-cream,#fbfbf8);will-change:transform;transition:transform .18s ease}',
      '#looth-msgr .mg-comprow{display:flex;align-items:flex-end;gap:8px}',
      '#looth-msgr .mg-attach-btn{flex:0 0 auto;border:0;background:none;cursor:pointer;color:var(--lg-sage-d,#52613d);padding:6px;display:inline-flex;align-items:center;justify-content:center}',
      '#looth-msgr .mg-attach-prev{display:flex;padding:2px 2px 0}',
      // display:flex beats the UA [hidden] rule — without the counter-rule the empty
      // strip (src-less thumb + stray ✕) renders permanently above the composer.
      '#looth-msgr .mg-attach-prev[hidden]{display:none}',
      '#looth-msgr .mg-send-error{color:var(--lg-error,#b3261e);font:12px/1.4 var(--lg-font-sans,system-ui,sans-serif);padding:0 4px}',
      '#looth-msgr .mg-attach-thumb{position:relative;width:72px;height:72px;border-radius:12px;overflow:hidden}',
      '#looth-msgr .mg-attach-thumb img{width:100%;height:100%;object-fit:cover;display:block}',
      '#looth-msgr .mg-attach-x{position:absolute;top:3px;right:3px;width:22px;height:22px;border:0;border-radius:50%;background:rgba(0,0,0,.6);color:#fff;font:700 14px/1 system-ui;cursor:pointer;padding:0;display:inline-flex;align-items:center;justify-content:center}',
      '#looth-msgr .mg-img{display:block;max-width:200px;border-radius:16px;overflow:hidden}',
      '#looth-msgr .mg-img img{display:block;width:100%;height:auto;max-height:260px;object-fit:cover;border-radius:16px;background:var(--lguser-bubble,#eceff3)}',
      '#looth-msgr .mg-compwrap{flex:1 1 auto;min-width:0;display:flex;align-items:flex-end;background:var(--lguser-bubble,#eceff3);border-radius:20px;padding:6px 8px 6px 14px}',
      '#looth-msgr .mg-in{flex:1 1 auto;min-width:0;border:0;background:none;outline:none;resize:none;' +
        'font:15px/1.4 var(--lg-font-sans,system-ui,sans-serif);color:var(--lg-ink,#1a1d1a);max-height:110px;padding:4px 0}',
      '#looth-msgr .mg-send{flex:0 0 auto;border:0;background:none;cursor:pointer;color:var(--lg-sage-d,#52613d);' +
        'font:700 14px/1 var(--lg-font-sans,system-ui,sans-serif);padding:8px 9px}',
      '#looth-msgr .mg-send:disabled{color:#b0b3b8}',
      // dark
      D + ' #looth-msgr .mg-panel,' + D + ' #looth-msgr .mg-chat,' + D + ' #looth-msgr .mg-comp{background:#1b1e21}',
      D + ' #looth-msgr .mg-grab::before{background:#3a403a}',
      D + ' #looth-msgr .mg-t,' + D + ' #looth-msgr .mg-name,' + D + ' #looth-msgr .mg-chname{color:#f2f4ee}',
      D + ' #looth-msgr .mg-x{background:#262b30;color:#9cb37d}',
      D + ' #looth-msgr .mg-search{background:#262b30}',
      D + ' #looth-msgr .mg-search input{color:#e5e7e1}',
      D + ' #looth-msgr .mg-snip,' + D + ' #looth-msgr .mg-time,' + D + ' #looth-msgr .mg-empty,' + D + ' #looth-msgr .mg-day{color:#9aa097}',
      D + ' #looth-msgr .mg-row.is-unread .mg-name,' + D + ' #looth-msgr .mg-row.is-unread .mg-snip{color:#f2f4ee}',
      D + ' #looth-msgr .mg-row:active{background:#262b30}',
      D + ' #looth-msgr .mg-chd,' + D + ' #looth-msgr .mg-comp{border-color:#2c312d}',
      D + ' #looth-msgr .mg-b--them{background:#262b30;color:#e5e7e1}',
      D + ' #looth-msgr .mg-b--me{background:var(--lg-sage-d,#6b7c52)}',
      D + ' #looth-msgr .mg-compwrap{background:#262b30}',
      D + ' #looth-msgr .mg-in{color:#e5e7e1}'
    ].join('\n');
    (document.head || document.documentElement).appendChild(st);
  }

  function ensureSheet() {
    if (sheet) return sheet;
    ensureCss();
    sheet = document.createElement('div');
    sheet.id = 'looth-msgr';
    sheet.setAttribute('role', 'dialog'); sheet.setAttribute('aria-modal', 'true'); sheet.setAttribute('aria-label', 'Messages');
    sheet.innerHTML =
      '<div class="mg-back" data-mg-close></div>' +
      '<div class="mg-panel">' +
        '<div class="mg-grab" aria-hidden="true"></div>' +
        '<div class="mg-hd"><span class="mg-t">Chats</span><button class="mg-x" type="button" data-mg-close aria-label="Close">✕</button></div>' +
        '<label class="mg-search"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.6" y2="16.6"/></svg>' +
          '<input type="search" placeholder="Search chats" autocomplete="off" aria-label="Search chats"></label>' +
        '<div class="mg-list" id="mg-list"><div class="mg-empty">Loading…</div></div>' +
        '<div class="mg-chat" id="mg-chat">' +
          '<div class="mg-chd"><button class="mg-backbtn" type="button" data-mg-home aria-label="Back">‹</button>' +
            '<span class="mg-avi" id="mg-chavi"></span><span class="mg-chname" id="mg-chname"></span></div>' +
          '<div class="mg-msgs" id="mg-msgs"></div>' +
          '<div class="mg-comp">' +
            '<div class="mg-attach-prev" id="mg-attach-prev" hidden><div class="mg-attach-thumb">' +
              '<img id="mg-attach-img" alt=""><button type="button" class="mg-attach-x" id="mg-attach-x" aria-label="Remove photo">✕</button></div></div>' +
            '<div class="mg-comprow">' +
              '<input type="file" id="mg-attach-in" accept="image/jpeg,image/png,image/webp" hidden>' +
              '<button class="mg-attach-btn" id="mg-attach-btn" type="button" aria-label="Attach photo">' +
                '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
                '<path d="M21.4 11.05 12.25 20.2a5 5 0 0 1-7.07-7.07l9.19-9.19a3 3 0 0 1 4.24 4.24l-9.2 9.19a1 1 0 0 1-1.41-1.41l8.49-8.49"/></svg></button>' +
              '<div class="mg-compwrap">' +
                '<textarea class="mg-in" id="mg-in" rows="1" placeholder="Message…"></textarea>' +
                '<button class="mg-send" id="mg-send" type="button" disabled>Send</button></div>' +
            '</div>' +
          '</div>' +
        '</div>' +
      '</div>';
    (document.body || document.documentElement).appendChild(sheet);
    sheet.addEventListener('click', function (e) {
      if (e.target.closest('[data-mg-close]')) closeMessenger();
      if (e.target.closest('[data-mg-home]')) showHome();
    });
    // drag the grab down to dismiss (design-system gesture)
    (function () {
      var panel = sheet.querySelector('.mg-panel'), grab = sheet.querySelector('.mg-grab');
      var sy = 0, dy = 0, on = false;
      grab.addEventListener('touchstart', function (e) { sy = e.touches[0].clientY; dy = 0; on = true; panel.style.transition = 'none'; }, { passive: true });
      grab.addEventListener('touchmove', function (e) {
        if (!on) return; dy = Math.max(0, e.touches[0].clientY - sy);
        panel.style.transform = 'translateY(' + dy + 'px)'; if (e.cancelable) e.preventDefault();
      }, { passive: false });
      grab.addEventListener('touchend', function () {
        if (!on) return; on = false; panel.style.transition = ''; panel.style.transform = '';
        if (dy > 110) closeMessenger();
      });
    })();
    // search filters the loaded threads
    sheet.querySelector('.mg-search input').addEventListener('input', function () { renderThreads(this.value); });
    // composer: grow + send
    var ta = sheet.querySelector('#mg-in'), send = sheet.querySelector('#mg-send');
    ta.addEventListener('input', function () {
      send.disabled = !ta.value.trim() && !pendingFile;   // text OR a staged image enables send
      ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight, 110) + 'px';
    });
    ta.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); doSend(); } });
    send.addEventListener('click', doSend);
    // image attachment: paperclip opens picker, change stages a preview, ✕ clears
    var attBtn = sheet.querySelector('#mg-attach-btn'), attIn = sheet.querySelector('#mg-attach-in'), attX = sheet.querySelector('#mg-attach-x');
    if (attBtn && attIn) {
      attBtn.addEventListener('click', function () { attIn.click(); });
      attIn.addEventListener('change', function () { stageFile(attIn.files && attIn.files[0]); });
    }
    if (attX) attX.addEventListener('click', clearFile);
    // keyboard-aware composer lift
    function kb() {
      var comp = sheet.querySelector('.mg-comp');
      if (!sheet.classList.contains('is-open') || !window.visualViewport) { comp.style.transform = ''; return; }
      var vv = window.visualViewport;
      var k = Math.max(0, Math.round(window.innerHeight - vv.height - vv.offsetTop));
      comp.style.transform = k > 1 ? ('translateY(-' + k + 'px)') : '';
      if (k > 1) { var m = sheet.querySelector('#mg-msgs'); if (m) m.scrollTop = m.scrollHeight; }
    }
    if (window.visualViewport) {
      window.visualViewport.addEventListener('resize', kb);
      window.visualViewport.addEventListener('scroll', kb);
    }
    ta.addEventListener('focus', function () { setTimeout(kb, 120); setTimeout(kb, 330); });
    ta.addEventListener('blur', function () { setTimeout(kb, 80); });
    return sheet;
  }

  function avi(p) {
    if (p && p.avatar_url) return '<img src="' + esc(p.avatar_url) + '" alt="">';
    var n = (p && p.name || '?').trim();
    return esc(n.split(/\s+/).map(function (w) { return w[0] || ''; }).join('').slice(0, 2).toUpperCase());
  }

  /* visible failure state for the attach send — silent dimming alone left users
     blind to failures (parity with the desktop modal's .lg-msg__send-error) */
  function setSendError(msg) {
    if (!sheet) return;
    var comp = sheet.querySelector('.mg-comp');
    if (!comp) return;
    var el = comp.querySelector('.mg-send-error');
    if (!msg) { if (el) el.remove(); return; }
    if (!el) {
      el = document.createElement('div');
      el.className = 'mg-send-error';
      el.setAttribute('role', 'alert');
      comp.insertBefore(el, comp.firstChild);
    }
    el.textContent = msg;
  }
  function clearFile() {
    pendingFile = null;
    setSendError(null);
    if (!sheet) return;
    var inp = sheet.querySelector('#mg-attach-in'); if (inp) inp.value = '';
    var img = sheet.querySelector('#mg-attach-img');
    if (img) { if (img.src && img.src.indexOf('blob:') === 0) URL.revokeObjectURL(img.src); img.removeAttribute('src'); }
    var prev = sheet.querySelector('#mg-attach-prev'); if (prev) prev.hidden = true;
    var ta = sheet.querySelector('#mg-in'), send = sheet.querySelector('#mg-send');
    if (send) send.disabled = !(ta && ta.value.trim());
  }
  function stageFile(file) {
    if (!file) return;
    if (!ATTACH_TYPES[file.type]) { alert('Please choose a JPEG, PNG, or WebP image.'); return; }
    if (file.size > ATTACH_MAX)   { alert('That image is larger than 5 MB — please choose a smaller one.'); return; }
    setSendError(null);
    pendingFile = file;
    var img = sheet.querySelector('#mg-attach-img');
    if (img) { if (img.src && img.src.indexOf('blob:') === 0) URL.revokeObjectURL(img.src); img.src = URL.createObjectURL(file); }
    var prev = sheet.querySelector('#mg-attach-prev'); if (prev) prev.hidden = false;
    var send = sheet.querySelector('#mg-send'); if (send) send.disabled = false;
  }

  function renderThreads(q) {
    var list = sheet.querySelector('#mg-list');
    var ql = (q || '').trim().toLowerCase();
    var items = threadsCache.filter(function (t) {
      if (!ql) return true;
      var p = (t.peers && t.peers[0]) || {};
      return ((p.name || '') + ' ' + (t.last_snippet || '')).toLowerCase().indexOf(ql) > -1;
    });
    if (!items.length) {
      list.innerHTML = '<div class="mg-empty">' + (ql ? 'No chats match.' : 'No messages yet. Find a member and tap Message to start a chat.') + '</div>';
      return;
    }
    list.innerHTML = items.map(function (t) {
      var p = (t.peers && t.peers[0]) || {};
      var unread = (parseInt(t.unread_count, 10) || 0) > 0;
      return '<button type="button" class="mg-row' + (unread ? ' is-unread' : '') + '" data-mg-thread="' + esc(t.uuid) + '">' +
        '<span class="mg-avi">' + avi(p) + '</span>' +
        '<span class="mg-col"><span class="mg-name">' + esc(p.name || 'Member') + '</span>' +
        '<span class="mg-snip">' + esc(t.last_snippet || '') + '</span></span>' +
        '<span class="mg-meta"><span class="mg-time">' + rel(t.last_at || t.updated_at || '') + '</span>' +
        (unread ? '<span class="mg-dot"></span>' : '') + '</span></button>';
    }).join('');
    [].forEach.call(list.querySelectorAll('[data-mg-thread]'), function (b) {
      b.addEventListener('click', function () {
        var t = threadsCache.filter(function (x) { return x.uuid === b.getAttribute('data-mg-thread'); })[0];
        openThread(b.getAttribute('data-mg-thread'), (t && t.peers && t.peers[0]) || null);
      });
    });
  }

  function loadThreads() {
    fetch(API + '/me/messages/', { credentials: 'include' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        threadsCache = (d && d.threads) || [];
        var q = sheet.querySelector('.mg-search input').value;
        renderThreads(q);
      })
      .catch(function () {
        sheet.querySelector('#mg-list').innerHTML = '<div class="mg-empty">Couldn’t load your chats right now.</div>';
      });
  }

  function showHome() {
    if (pollT) { clearInterval(pollT); pollT = null; }
    curThread = null; curPeer = null;
    sheet.querySelector('#mg-chat').classList.remove('is-on');
    loadThreads();
  }

  function renderMessages(msgs, peers) {
    var box = sheet.querySelector('#mg-msgs');
    var peerSet = {};
    (peers || []).forEach(function (p) { peerSet[p.uuid] = 1; });
    var lastDay = '';
    box.innerHTML = (msgs || []).map(function (m) {
      var mine = !peerSet[m.sender_uuid];                     // mine = sender not among peers
      var day = String(m.created_at || '').slice(0, 10);
      var h = '';
      if (day && day !== lastDay) { lastDay = day; h += '<div class="mg-day">' + esc(day) + '</div>'; }
      // image attachment (access-controlled URL) — tap to open full size
      if (m.media_url) {
        h += '<a class="mg-img" style="align-self:' + (mine ? 'flex-end' : 'flex-start') + '" href="' +
             esc(m.media_url) + '" target="_blank" rel="noopener noreferrer"><img src="' +
             esc(m.media_url) + '" alt="Photo" loading="lazy"></a>';
      }
      // body optional when an image is present (image-only message)
      if (m.body) h += '<div class="mg-b ' + (mine ? 'mg-b--me' : 'mg-b--them') + '">' + esc(m.body) + '</div>';
      return h;
    }).join('');
    box.scrollTop = box.scrollHeight;
  }

  function loadThread(uuid, quiet) {
    fetch(API + '/me/messages/' + encodeURIComponent(uuid), { credentials: 'include' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d || curThread !== uuid) return;
        renderMessages(d.messages || [], d.peers || []);
        if (!quiet && d.peers && d.peers[0]) {
          sheet.querySelector('#mg-chname').textContent = d.peers[0].name || 'Member';
          sheet.querySelector('#mg-chavi').innerHTML = avi(d.peers[0]);
        }
      })
      .catch(function () {});
  }

  function openThread(uuid, peer) {
    ensureSheet();
    curThread = uuid; curPeer = peer || null;
    sheet.querySelector('#mg-chname').textContent = (peer && peer.name) || '…';
    sheet.querySelector('#mg-chavi').innerHTML = avi(peer);
    sheet.querySelector('#mg-msgs').innerHTML = '<div class="mg-empty">Loading…</div>';
    sheet.querySelector('#mg-chat').classList.add('is-on');
    var ta = sheet.querySelector('#mg-in'); ta.value = ''; ta.style.height = 'auto';
    clearFile();
    sheet.querySelector('#mg-send').disabled = true;
    loadThread(uuid);
    if (pollT) clearInterval(pollT);
    pollT = setInterval(function () { if (curThread === uuid) loadThread(uuid, true); }, 8000);
  }

  // chat with a USER (member card "Message") — resolve their uuid to a thread,
  // or open a fresh chat whose first send creates the thread.
  function openChatWith(userUuid, name, avatarUrl) {
    ensureSheet();
    openMessenger();
    fetch(API + '/me/messages/', { credentials: 'include' })
      .then(function (r) { return r.ok ? r.json() : { threads: [] }; })
      .then(function (d) {
        var hit = ((d && d.threads) || []).filter(function (t) {
          return (t.peers || []).some(function (p) { return p.uuid === userUuid; });
        })[0];
        if (hit) { openThread(hit.uuid, (hit.peers || [])[0]); return; }
        // no thread yet — fresh chat, first send POSTs {to_uuid}
        curThread = null; curPeer = { uuid: userUuid, name: name || 'Member', avatar_url: avatarUrl || '' };
        clearFile();
        sheet.querySelector('#mg-chname').textContent = curPeer.name;
        sheet.querySelector('#mg-chavi').innerHTML = avi(curPeer);
        sheet.querySelector('#mg-msgs').innerHTML = '<div class="mg-empty">Say hi — this starts your chat.</div>';
        sheet.querySelector('#mg-chat').classList.add('is-on');
        try { sheet.querySelector('#mg-in').focus({ preventScroll: true }); } catch (e) {}
      })
      .catch(function () {});
  }

  function doSend() {
    var ta = sheet.querySelector('#mg-in'), send = sheet.querySelector('#mg-send');
    var text = (ta.value || '').trim();
    if (!text && !pendingFile) return;                         // need text OR an image
    if (!curThread && !(curPeer && curPeer.uuid)) return;
    send.disabled = true;

    if (pendingFile) { sendWithFile(text, ta, send); return; }

    var url, body;
    if (curThread) { url = API + '/me/messages/' + encodeURIComponent(curThread); body = { body: text }; }
    else if (curPeer && curPeer.uuid) { url = API + '/me/messages/'; body = { to_uuid: curPeer.uuid, body: text }; }
    else { return; }
    // optimistic bubble
    var box = sheet.querySelector('#mg-msgs');
    if (box.querySelector('.mg-empty')) box.innerHTML = '';
    var b = document.createElement('div'); b.className = 'mg-b mg-b--me'; b.textContent = text;
    box.appendChild(b); box.scrollTop = box.scrollHeight;
    ta.value = ''; ta.style.height = 'auto';
    fetch(url, {
      method: 'POST', credentials: 'include',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body)
    })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }, function () { return { ok: r.ok, j: {} }; }); })
      .then(function (res) {
        if (!res.ok) { b.style.opacity = '.4'; b.textContent += ' (failed — tap Send to retry)'; ta.value = text; send.disabled = false; return; }
        // first message created the thread → adopt its uuid and start polling
        var newUuid = res.j && (res.j.thread_uuid || (res.j.thread && res.j.thread.uuid) || res.j.uuid);
        if (!curThread && newUuid) {
          curThread = newUuid;
          if (pollT) clearInterval(pollT);
          pollT = setInterval(function () { if (curThread === newUuid) loadThread(newUuid, true); }, 8000);
        }
        if (curThread) loadThread(curThread, true);
      })
      .catch(function () { b.style.opacity = '.4'; send.disabled = false; });
  }

  /* multipart send when a photo is staged. Reply → /me/messages/<uuid>/image;
     first message → /me/messages/image with to_uuid. Optimistic image bubble; the
     staged file is kept on failure so Send retries it. */
  function sendWithFile(text, ta, send) {
    var file = pendingFile;
    var fd = new FormData();
    fd.append('image', file);
    if (text) fd.append('body', text);
    var url;
    if (curThread) { url = API + '/me/messages/' + encodeURIComponent(curThread) + '/image'; }
    else { url = API + '/me/messages/image'; fd.append('to_uuid', curPeer.uuid); }

    var box = sheet.querySelector('#mg-msgs');
    if (box.querySelector('.mg-empty')) box.innerHTML = '';
    var a = document.createElement('a'); a.className = 'mg-img'; a.style.alignSelf = 'flex-end';
    var im = document.createElement('img'); im.src = URL.createObjectURL(file); a.appendChild(im);
    box.appendChild(a);
    var tb = null;
    if (text) { tb = document.createElement('div'); tb.className = 'mg-b mg-b--me'; tb.textContent = text; box.appendChild(tb); }
    box.scrollTop = box.scrollHeight;
    var savedText = text;
    ta.value = ''; ta.style.height = 'auto';
    setSendError(null);
    var prev = sheet.querySelector('#mg-attach-prev'); if (prev) prev.hidden = true;  // hide strip in-flight
    // visible in-flight state (Ian 7/06): a disabled "Send" alone reads as
    // "nothing happened" during a slow upload
    send.textContent = 'Sending…'; send.setAttribute('aria-busy', 'true');
    var settle = function () { send.textContent = 'Send'; send.removeAttribute('aria-busy'); };

    var failed = function () {                          // keep staged file + text for retry, but SAY it failed
      settle();
      a.style.opacity = '.4'; if (tb) tb.style.opacity = '.4';
      ta.value = savedText; if (prev) prev.hidden = false; send.disabled = false;
      setSendError("Couldn't send your photo — nothing was posted. Tap Send to retry.");
    };
    fetch(url, { method: 'POST', credentials: 'include', body: fd })   // no Content-Type → browser sets boundary
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }, function () { return { ok: r.ok, j: {} }; }); })
      .then(function (res) {
        if (!res.ok) { failed(); return; }
        settle();
        clearFile();                                    // success — drop staged file + preview
        var newUuid = res.j && (res.j.thread_uuid || (res.j.thread && res.j.thread.uuid) || res.j.uuid);
        if (!curThread && newUuid) {
          curThread = newUuid;
          if (pollT) clearInterval(pollT);
          pollT = setInterval(function () { if (curThread === newUuid) loadThread(newUuid, true); }, 8000);
        }
        if (curThread) loadThread(curThread, true);
      })
      .catch(failed);
  }

  var msgrHist = false;
  function openMessenger() {
    ensureSheet();
    sheet.classList.add('is-open');
    requestAnimationFrame(function () { requestAnimationFrame(function () { sheet.classList.add('is-up'); }); });
    document.body.style.overflow = 'hidden';
    if (!msgrHist) { try { history.pushState({ lgMg: 1 }, ''); msgrHist = true; } catch (e) {} }
    showHome();
    if (listPollT) clearInterval(listPollT);
    listPollT = setInterval(function () { if (!curThread && sheet.classList.contains('is-open')) loadThreads(); }, 30000);
  }
  function closeMessenger(fromPop) {
    if (!sheet || !sheet.classList.contains('is-open')) return;
    sheet.classList.remove('is-up');
    setTimeout(function () { if (sheet && !sheet.classList.contains('is-up')) sheet.classList.remove('is-open'); }, 320);
    document.body.style.overflow = '';
    if (pollT) { clearInterval(pollT); pollT = null; }
    if (listPollT) { clearInterval(listPollT); listPollT = null; }
    if (msgrHist && !fromPop) { msgrHist = false; try { history.back(); } catch (e) {} }
    else { msgrHist = false; }
  }
  window.addEventListener('popstate', function () {
    if (!sheet || !sheet.classList.contains('is-open')) return;
    var chat = sheet.querySelector('#mg-chat');
    if (chat && chat.classList.contains('is-on')) {           // back from a chat → home
      showHome();
      try { history.pushState({ lgMg: 1 }, ''); } catch (e) {}
      return;
    }
    closeMessenger(true);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && sheet && sheet.classList.contains('is-open')) closeMessenger();
  });

  window.openMessenger = openMessenger;
  window.openChatWith = openChatWith;

  // ── entry points ────────────────────────────────────────────────────────────
  // 1) lg:open-dm {uuid} — the documented event member cards / profiles dispatch.
  //    social-modals also listens; its modal is display:none'd on mobile (CSS above).
  window.addEventListener('lg:open-dm', function (e) {
    var uuid = e && e.detail && e.detail.uuid;
    if (uuid) openChatWith(uuid);
  });
  document.addEventListener('lg:open-dm', function (e) {
    var uuid = e && e.detail && e.detail.uuid;
    if (uuid) openChatWith(uuid);
  });
  // 2) the header messages icon — claim it before social-modals' handler runs.
  document.addEventListener('click', function (e) {
    if (!e.target.closest) return;
    var b = e.target.closest('[data-lg-msg-link]');
    if (!b) return;
    e.preventDefault(); e.stopImmediatePropagation();
    openMessenger();
  }, true);
})();
