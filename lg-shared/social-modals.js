/* /srv/lg-shared/social-modals.js — P9 social modals
 * Notifications bell, messages, connections/friends.
 * Deferred by site-header.php on authenticated pages only.
 *
 * API base: /profile-api/v0   Auth: credentials:'include' (same-origin)
 *
 * Contract reconciled against the LIVE backend 2026-05-31 (profile-app api/v0/*
 * + src/{Connections,Notifications,Messaging}.php). Routes are under /me/… ; the
 * earlier /me-* paths + guessed shapes were stale and failed silently as "empty".
 * When a shape is unknown: hit the endpoint logged-in and read the JSON — do NOT guess.
 *
 * GET   /me/social-counts/        {messages_unread:int, requests_pending:int, notifications_unread:int}
 * GET   /me/notifications/        {items:[{id,type,is_read,created_at,ref{kind,id},
 *                                  actor{uuid,name,slug,avatar_url}}], unread:int}
 *                                 (type ∈ message|connection_request|connection_accept; NO text field — build it)
 * POST  /me/notifications/        body:{action:'read_all'} | {action:'read', id:int}
 * GET   /me/messages/             {threads:[{id,uuid,unread_count,last_snippet,last_sender,
 *                                  peers:[{uuid,name,slug,avatar_url}]}]}      (key by thread uuid)
 * GET   /me/messages/<thread-uuid> {ok,thread,peers:[{uuid,name,…}],messages:[{id,sender_uuid,body,created_at}]}
 *                                 (marks read; "mine" = sender_uuid NOT among peers)
 * POST  /me/messages/<thread-uuid> reply        body:{body:string}
 * POST  /me/messages/             new DM         body:{to_uuid:string, body:string}
 * GET   /me/connections/          {accepted[], pending_in[], pending_out[]}    (items: {id,uuid,display_name,slug,avatar_url})
 * PATCH /connections/<id>         body:{action:'accept'|'decline'|'cancel'|'block'}   (id = connection id)
 *
 * lg:open-dm  detail:{uuid}  (TARGET USER uuid — resolve to a thread, see openDmWithUser)
 */
(function () {
'use strict';

var API = '/profile-api/v0';
var currentThreadUuid = null;  // opaque thread uuid → replies POST /me/messages/<uuid>
var pendingPeerUuid   = null;  // target USER uuid for a not-yet-existing thread (new DM)
var pendingAttachFile = null;  // staged image File for the next send (image attachment)

/* image attachment limits — mirror the upload endpoint (jpeg/png/webp ≤5MB) */
var ATTACH_MAX   = 5 * 1024 * 1024;
var ATTACH_TYPES = { 'image/jpeg': 1, 'image/png': 1, 'image/webp': 1 };

/* ── helpers ── */
function esc(s) {
  return String(s == null ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

/* Linkify http/https URLs in user text. XSS-safe: ESCAPE first (esc), then wrap
   bare http(s) URL substrings in anchors. No unescaped input is ever injected;
   no bare-domain autolink (http/https only). Render-only - storage stays raw. */
function linkifyText(s) {
  return esc(s).replace(/https?:\/\/[^\s<]+/g, function (url) {
    var trail = '';
    var m = url.match(/[)\].,;:!?'&]+$/);   /* drop trailing punctuation / dangling entities */
    if (m) { trail = url.slice(url.length - m[0].length); url = url.slice(0, url.length - m[0].length); }
    if (!url) { return trail; }
    return '<a href="' + url + '" target="_blank" rel="noopener noreferrer nofollow ugc">' + url + '</a>' + trail;
  });
}
function capCount(n) {
  n = parseInt(n, 10) || 0;
  return n > 9 ? '9+' : (n > 0 ? String(n) : '');
}
function setBadge(sel, n) {
  var el = document.querySelector(sel);
  if (!el) return;
  var lbl = capCount(n);
  el.textContent = lbl;
  el.hidden = !lbl;
}
function relTime(ts) {
  if (!ts) return '';
  var d = new Date(typeof ts === 'number' ? ts * 1000 : ts);
  var diff = (Date.now() - d.getTime()) / 1000;
  if (isNaN(diff) || diff < 0) return '';
  if (diff < 60)    return 'just now';
  if (diff < 3600)  return Math.floor(diff / 60) + 'm ago';
  if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
  return Math.floor(diff / 86400) + 'd ago';
}
function avatarEl(u, sz) {
  sz = sz || 32; u = u || {};
  var name = u.display_name || u.name || '?';
  var url  = u.avatar_url   || u.avatar || '';
  var init = name.charAt(0).toUpperCase();
  if (url) {
    return '<img class="lg-sm__avatar" src="' + esc(url) + '" alt="' + esc(name) +
           '" width="' + sz + '" height="' + sz + '" loading="lazy">';
  }
  return '<span class="lg-sm__avatar lg-sm__avatar--initial">' + esc(init) + '</span>';
}

/* ── modal open/close ── */
function openModal(id) {
  closeAllModals();
  var m = document.getElementById(id);
  if (!m) return;
  m.hidden = false;
  m.setAttribute('aria-hidden', 'false');
  document.body.classList.add('lg-sm-open');
  var cb = m.querySelector('[data-lg-modal-close]');
  if (cb) cb.focus();
}
function closeAllModals() {
  document.querySelectorAll('.lg-social-modal').forEach(function (m) {
    m.hidden = true;
    m.setAttribute('aria-hidden', 'true');
  });
  document.body.classList.remove('lg-sm-open');
}
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') closeAllModals();
});
document.addEventListener('click', function (e) {
  var t = e.target;
  if (t.hasAttribute('data-lg-modal-close') ||
      (t.closest && t.closest('[data-lg-modal-close]'))) {
    closeAllModals();
  } else if (t.classList && t.classList.contains('lg-social-modal__backdrop')) {
    closeAllModals();
  }
});

/* ── social counts (all three badges, raw ints → "9+" cap in capCount) ── */
function refreshCounts() {
  fetch(API + '/me/social-counts/', { credentials: 'include' })
    .then(function (r) { return r.ok ? r.json() : null; })
    .then(function (d) {
      if (!d) return;
      setBadge('[data-lg-notif-count]', d.notifications_unread || 0);
      setBadge('[data-lg-msg-count]',   d.messages_unread      || 0);
      setBadge('[data-lg-conn-count]',  d.requests_pending     || 0);
    })
    .catch(function () {});
}

/* ── notifications ── */
/* No text field on the wire — compose the sentence from type + actor.name. */
function notifText(n) {
  var who = (n.actor && n.actor.name) || 'Someone';
  switch (n.type) {
    case 'connection_accept':  return esc(who) + ' accepted your connection request';
    case 'connection_request': return esc(who) + ' sent you a connection request';
    case 'message':            return 'New message from ' + esc(who);
    default:                   return esc(who);
  }
}
var CHECK_SVG = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor"'
  + ' stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
  + '<polyline points="20 6 9 17 4 12"/></svg>';

function renderNotifItem(n) {
  var unread = !n.is_read;
  return '<div class="lg-notif__item' + (unread ? ' lg-notif__item--unread' : '') +
    '" data-notif-id="' + esc(n.id) + '">'
    + '<div class="lg-notif__body">'
      + '<p class="lg-notif__text">' + notifText(n) + '</p>'
      + '<span class="lg-notif__time">' + relTime(n.created_at) + '</span>'
    + '</div>'
    + (unread
        ? '<button class="lg-notif__clear" data-notif-clear="' + esc(n.id) +
          '" title="Mark as read" aria-label="Mark as read">' + CHECK_SVG + '</button>'
        : '')
    + '</div>';
}
function updateReadAllBtn(show) {
  var b = document.querySelector('[data-lg-notif-readall]');
  if (b) b.hidden = !show;
}
function loadNotifications() {
  var list = document.getElementById('lg-notif-list');
  if (!list) return;
  list.innerHTML = '<p class="lg-sm__status">Loading...</p>';
  fetch(API + '/me/notifications/', { credentials: 'include' })
    .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
    .then(function (d) {
      /* Bell shows CONNECTION events only — skip message-type defensively
         (the social lane is removing message notifications backend-side). */
      var items = ((d && d.items) || []).filter(function (n) { return n.type !== 'message'; });
      if (!items.length) {
        list.innerHTML = '<p class="lg-sm__empty">No notifications yet.</p>';
        updateReadAllBtn(false);
        return;
      }
      list.innerHTML = items.map(renderNotifItem).join('');
      /* NO auto-mark-read — the user controls it (per-item ✓ or "Mark all read"). */
      updateReadAllBtn(items.some(function (n) { return !n.is_read; }));
      list.querySelectorAll('[data-notif-clear]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.stopPropagation();
          markNotifRead(btn.getAttribute('data-notif-clear'));
        });
      });
    })
    .catch(function () {
      list.innerHTML = '<p class="lg-sm__error">Could not load notifications.</p>';
    });
}
/* Per-item clear = mark-read (v1): row de-emphasized in place, stays in the list. */
function markNotifRead(id) {
  fetch(API + '/me/notifications/', {
    method:      'POST',
    credentials: 'include',
    headers:     { 'Content-Type': 'application/json' },
    body:        JSON.stringify({ action: 'read', id: parseInt(id, 10) }),
  })
    .then(function (r) {
      if (!r.ok) return;
      var row = document.querySelector('.lg-notif__item[data-notif-id="' + id + '"]');
      if (row) {
        row.classList.remove('lg-notif__item--unread');
        var btn = row.querySelector('.lg-notif__clear');
        if (btn) btn.remove();
      }
      updateReadAllBtn(!!document.querySelector('.lg-notif__item--unread'));
      refreshCounts();
    })
    .catch(function () {});
}
function markAllNotifsRead() {
  fetch(API + '/me/notifications/', {
    method:      'POST',
    credentials: 'include',
    headers:     { 'Content-Type': 'application/json' },
    body:        JSON.stringify({ action: 'read_all' }),
  })
    .then(function (r) {
      if (!r.ok) return;
      document.querySelectorAll('.lg-notif__item--unread').forEach(function (row) {
        row.classList.remove('lg-notif__item--unread');
        var btn = row.querySelector('.lg-notif__clear');
        if (btn) btn.remove();
      });
      updateReadAllBtn(false);
      refreshCounts();
    })
    .catch(function () {});
}

/* ── messages: thread list ── */
function loadThreadList() {
  var list   = document.getElementById('lg-msg-list');
  var detail = document.getElementById('lg-msg-detail');
  if (!list) return;
  currentThreadUuid = null;
  pendingPeerUuid   = null;
  clearAttach();
  list.hidden = false;
  if (detail) detail.hidden = true;
  /* show back button only when in detail */
  var backBtn = document.querySelector('[data-lg-thread-back]');
  if (backBtn) backBtn.hidden = true;
  list.innerHTML = '<p class="lg-sm__status">Loading...</p>';
  fetch(API + '/me/messages/', { credentials: 'include' })
    .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
    .then(function (d) {
      var threads = (d && d.threads) || [];
      if (!threads.length) {
        list.innerHTML = '<p class="lg-sm__empty">No messages yet.</p>';
        return;
      }
      list.innerHTML = threads.map(function (t) {
        var p      = (t.peers && t.peers[0]) || {};   /* peersByThread → {uuid,name,slug,avatar_url} */
        var unread = t.unread_count || 0;
        var prev   = t.last_snippet || '';
        return '<div class="lg-msg__thread' + (unread ? ' lg-msg__thread--unread' : '') +
          '" data-thread-uuid="' + esc(t.uuid) + '" tabindex="0" role="button">'
          + '<div class="lg-msg__av">' + avatarEl(p, 36) + '</div>'
          + '<div class="lg-msg__meta">'
            + '<div class="lg-msg__name">' + esc(p.name || p.display_name || 'Unknown') + '</div>'
            + '<div class="lg-msg__preview">' + esc(prev) + '</div>'
          + '</div>'
          + (unread ? '<span class="lg-sm__badge">' + capCount(unread) + '</span>' : '')
          + '</div>';
      }).join('');
      list.querySelectorAll('[data-thread-uuid]').forEach(function (el) {
        el.addEventListener('click', function () { openThread(el.dataset.threadUuid); });
        el.addEventListener('keydown', function (e) {
          if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openThread(el.dataset.threadUuid); }
        });
      });
    })
    .catch(function () {
      list.innerHTML = '<p class="lg-sm__error">Could not load messages.</p>';
    });
}

/* ── messages: thread detail (keyed by opaque thread uuid) ── */
function showDetailPane() {
  var list   = document.getElementById('lg-msg-list');
  var detail = document.getElementById('lg-msg-detail');
  var backBtn = document.querySelector('[data-lg-thread-back]');
  if (list)    list.hidden    = true;
  if (detail)  detail.hidden  = false;
  if (backBtn) backBtn.hidden = false;
}
function openThread(threadUuid) {
  var detail  = document.getElementById('lg-msg-detail');
  var msgs    = document.getElementById('lg-msg-messages');
  var compose = document.getElementById('lg-msg-compose');
  if (!detail || !msgs) return;
  currentThreadUuid = threadUuid;
  pendingPeerUuid   = null;
  clearAttach();
  showDetailPane();
  msgs.innerHTML = '<p class="lg-sm__status">Loading...</p>';

  fetch(API + '/me/messages/' + encodeURIComponent(threadUuid), { credentials: 'include' })
    .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
    .then(function (d) {
      /* "mine" = sender is NOT one of the peers (peers = everyone but the viewer) */
      var peerSet  = {};
      ((d && d.peers) || []).forEach(function (p) { peerSet[p.uuid] = true; });
      var messages = (d && d.messages) || [];

      if (!messages.length) {
        msgs.innerHTML = '<p class="lg-sm__empty">No messages yet. Send the first one!</p>';
      } else {
        msgs.innerHTML = messages.map(function (m) {
          var mine = !peerSet[m.sender_uuid];
          var h = '<div class="lg-msg__msg' + (mine ? ' lg-msg__msg--mine' : '') + '">';
          /* image attachment (access-controlled URL): tap to open full size */
          if (m.media_url) {
            h += '<a class="lg-msg__msg-media" href="' + esc(m.media_url) + '" target="_blank" rel="noopener noreferrer">'
               + '<img src="' + esc(m.media_url) + '" alt="Photo" loading="lazy"></a>';
          }
          /* body is optional when an image is present (image-only message) */
          if (m.body) h += '<p class="lg-msg__msg-text">' + linkifyText(m.body) + '</p>';
          h += '<span class="lg-msg__msg-time">' + relTime(m.created_at) + '</span></div>';
          return h;
        }).join('');
        msgs.scrollTop = msgs.scrollHeight;
      }

      if (compose) compose.hidden = false;
      setTimeout(refreshCounts, 400);  /* GET thread marks read server-side */
    })
    .catch(function () {
      msgs.innerHTML = '<p class="lg-sm__error">Could not load thread.</p>';
    });
}

/* Open (or start) a DM with a specific USER uuid — used by the lg:open-dm event.
   Resolve the user to an existing 1:1 thread; if none, set up a new-thread compose. */
function openDmWithUser(peerUuid) {
  var msgs    = document.getElementById('lg-msg-messages');
  var compose = document.getElementById('lg-msg-compose');
  if (!msgs) return;
  clearAttach();
  showDetailPane();
  msgs.innerHTML = '<p class="lg-sm__status">Loading...</p>';
  fetch(API + '/me/messages/', { credentials: 'include' })
    .then(function (r) { return r.ok ? r.json() : { threads: [] }; })
    .then(function (d) {
      var threads = (d && d.threads) || [];
      var match = null;
      threads.forEach(function (t) {
        if ((t.peers || []).some(function (p) { return p.uuid === peerUuid; })) match = t;
      });
      if (match) { openThread(match.uuid); return; }
      /* no thread yet — first message creates it via POST /me/messages/ {to_uuid} */
      currentThreadUuid = null;
      pendingPeerUuid   = peerUuid;
      msgs.innerHTML = '<p class="lg-sm__empty">No messages yet. Send the first one!</p>';
      if (compose) compose.hidden = false;
      var input = document.getElementById('lg-msg-reply-input');
      if (input) input.focus();
    })
    .catch(function () {
      msgs.innerHTML = '<p class="lg-sm__error">Could not open conversation.</p>';
    });
}

/* ── image attachment (compose) ── */
/* Visible failure state for the attach send — created on demand, cleared on any
   success / re-stage / navigation. Silent-catch left users blind (Ian 7/06: hammered
   Send into repeated failing POSTs with zero feedback). */
function setAttachError(msg) {
  var compose = document.getElementById('lg-msg-compose');
  if (!compose) return;
  var el = compose.querySelector('.lg-msg__send-error');
  if (!msg) { if (el) el.remove(); return; }
  if (!el) {
    el = document.createElement('p');
    el.className = 'lg-msg__send-error';
    el.setAttribute('role', 'alert');
    compose.insertBefore(el, compose.firstChild);
  }
  el.textContent = msg;
}
function clearAttach() {
  pendingAttachFile = null;
  setAttachError(null);
  var inp = document.getElementById('lg-msg-attach-input');
  if (inp) inp.value = '';
  var img = document.getElementById('lg-msg-attach-img');
  if (img) {
    if (img.src && img.src.indexOf('blob:') === 0) URL.revokeObjectURL(img.src);
    img.removeAttribute('src');
  }
  var prev = document.getElementById('lg-msg-attach-preview');
  if (prev) prev.hidden = true;
}
function stageAttach(file) {
  if (!file) return;
  if (!ATTACH_TYPES[file.type]) { alert('Please choose a JPEG, PNG, or WebP image.'); return; }
  if (file.size > ATTACH_MAX)   { alert('That image is larger than 5 MB — please choose a smaller one.'); return; }
  setAttachError(null);
  pendingAttachFile = file;
  var img = document.getElementById('lg-msg-attach-img');
  if (img) {
    if (img.src && img.src.indexOf('blob:') === 0) URL.revokeObjectURL(img.src);
    img.src = URL.createObjectURL(file);
  }
  var prev = document.getElementById('lg-msg-attach-preview');
  if (prev) prev.hidden = false;
}

function sendReply() {
  if (!currentThreadUuid && !pendingPeerUuid) return;
  var input = document.getElementById('lg-msg-reply-input');
  var text  = input ? input.value.trim() : '';
  /* require text OR an image (image-only messages are allowed) */
  if (!text && !pendingAttachFile) return;

  if (pendingAttachFile) { sendWithAttachment(text); return; }

  var saved = text;
  input.value    = '';
  input.disabled = true;

  var url, payload;
  if (currentThreadUuid) {                       /* reply in existing thread */
    url     = API + '/me/messages/' + encodeURIComponent(currentThreadUuid);
    payload = { body: text };
  } else {                                       /* first message → creates the thread */
    url     = API + '/me/messages/';
    payload = { to_uuid: pendingPeerUuid, body: text };
  }
  var newDmPeer = pendingPeerUuid;

  fetch(url, {
    method:      'POST',
    credentials: 'include',
    headers:     { 'Content-Type': 'application/json' },
    body:        JSON.stringify(payload),
  })
    .then(function (r) {
      if (!r.ok) throw new Error(r.status);
      if (currentThreadUuid) return openThread(currentThreadUuid);
      pendingPeerUuid = null;                     /* thread now exists — re-resolve + open it */
      return openDmWithUser(newDmPeer);
    })
    .catch(function () { if (input) input.value = saved; })
    .then(function () { if (input) { input.disabled = false; input.focus(); } });
}

/* Multipart send when a photo is staged. Reply → /me/messages/<uuid>/image;
   first message → /me/messages/image with to_uuid (creates the thread). Body is
   the optional caption. On failure, the staged file + text are kept for retry.
   In-flight lockout: without it every extra Send click fired ANOTHER POST of the
   same staged file (5 clicks = 5 uploads — Ian's repeated-404 storm on 7/06). */
var attachSending = false;
function sendWithAttachment(text) {
  if (attachSending) return;
  var input   = document.getElementById('lg-msg-reply-input');
  var sendBtn = document.querySelector('[data-lg-send-reply]');
  var file    = pendingAttachFile;
  if (!file) return;

  var fd = new FormData();
  fd.append('image', file);
  if (text) fd.append('body', text);

  var url, newDmPeer = pendingPeerUuid;
  if (currentThreadUuid) {
    url = API + '/me/messages/' + encodeURIComponent(currentThreadUuid) + '/image';
  } else {
    url = API + '/me/messages/image';
    fd.append('to_uuid', pendingPeerUuid);
  }

  attachSending = true;
  setAttachError(null);
  if (input)   input.disabled   = true;
  if (sendBtn) sendBtn.disabled = true;
  /* NB: no Content-Type header — the browser sets the multipart boundary. */
  fetch(url, { method: 'POST', credentials: 'include', body: fd })
    .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json().catch(function () { return {}; }); })
    .then(function () {
      if (input) input.value = '';
      clearAttach();
      if (currentThreadUuid) return openThread(currentThreadUuid);
      pendingPeerUuid = null;
      return openDmWithUser(newDmPeer);
    })
    .catch(function () {
      /* keep staged file + text for retry, but SAY it failed */
      setAttachError("Couldn't send your photo — nothing was posted. Tap Send to retry.");
    })
    .then(function () {
      attachSending = false;
      if (sendBtn) sendBtn.disabled = false;
      if (input) { input.disabled = false; input.focus(); }
    });
}

/* lg:open-dm dispatched by Social::renderProfileActions() on /u/ pages AND by the
   per-connection Message button below. detail.uuid = TARGET USER uuid (Social.php:117).
   Open the unified modal on the Messages tab and resolve to that thread. */
document.addEventListener('lg:open-dm', function (e) {
  var peerUuid = e && e.detail && e.detail.uuid;
  openSocialModal('messages', peerUuid ? { dmUuid: String(peerUuid) } : null);
});

/* ── connections / friends ── */
var acceptedConns = [];   /* cached accepted[] for client-side search */

/* Render the accepted list, optionally filtered (client-side) by display_name. */
function renderAccepted(filter) {
  var accepted = document.getElementById('lg-conn-accepted');
  if (!accepted) return;
  if (!acceptedConns.length) {
    accepted.innerHTML = '<p class="lg-sm__empty">No connections yet.</p>';
    return;
  }
  var f = (filter || '').trim().toLowerCase();
  var list = f
    ? acceptedConns.filter(function (u) {
        return String(u.display_name || '').toLowerCase().indexOf(f) > -1;
      })
    : acceptedConns;
  if (!list.length) {
    accepted.innerHTML = '<p class="lg-sm__empty">No connections match “' + esc(filter) + '”.</p>';
    return;
  }
  accepted.innerHTML = list.map(function (u) {
    return '<div class="lg-conn__item">'
      + avatarEl(u, 36)
      + '<a class="lg-conn__name" href="/u/' + esc(u.slug || '') + '">'
        + esc(u.display_name || '')
      + '</a>'
      + '<div class="lg-conn__actions">'
        + '<button class="lg-conn__msg" data-conn-msg="' + esc(u.uuid) + '">Message</button>'
      + '</div></div>';
  }).join('');
  accepted.querySelectorAll('[data-conn-msg]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var uuid = btn.getAttribute('data-conn-msg');
      if (uuid) document.dispatchEvent(new CustomEvent('lg:open-dm', { detail: { uuid: uuid } }));
    });
  });
}

function loadConnections() {
  var accepted       = document.getElementById('lg-conn-accepted');
  var pending        = document.getElementById('lg-conn-pending');
  var pendingSection = document.getElementById('lg-conn-pending-section');
  var search         = document.getElementById('lg-conn-search');
  if (!accepted) return;
  if (search) search.value = '';
  accepted.innerHTML = '<p class="lg-sm__status">Loading...</p>';

  /* one call: /me/connections/ → {accepted[], pending_in[], pending_out[]}.
     items are flat: {id (=connection id), uuid, display_name, slug, avatar_url}. */
  fetch(API + '/me/connections/', { credentials: 'include' })
    .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
    .then(function (d) {
      acceptedConns = (d && d.accepted) || [];
      var reqs      = (d && d.pending_in) || [];

      renderAccepted('');

      if (reqs.length && pendingSection) {
        pendingSection.hidden = false;
        pending.innerHTML = reqs.map(function (u) {
          return '<div class="lg-conn__item lg-conn__item--pending" data-conn-id="' + esc(u.id) + '">'
            + avatarEl(u, 36)
            + '<span class="lg-conn__name">' + esc(u.display_name || '') + '</span>'
            + '<div class="lg-conn__actions">'
              + '<button class="lg-conn__accept"  data-conn-id="' + esc(u.id) + '">Accept</button>'
              + '<button class="lg-conn__decline" data-conn-id="' + esc(u.id) + '">Decline</button>'
            + '</div></div>';
        }).join('');
        pending.querySelectorAll('.lg-conn__accept').forEach(function (btn) {
          btn.addEventListener('click', function () { respondToRequest(btn.dataset.connId, 'accept'); });
        });
        pending.querySelectorAll('.lg-conn__decline').forEach(function (btn) {
          btn.addEventListener('click', function () { respondToRequest(btn.dataset.connId, 'decline'); });
        });
        setBadge('[data-lg-conn-count]', reqs.length);
      } else if (pendingSection) {
        pendingSection.hidden = true;
        setBadge('[data-lg-conn-count]', 0);
      }
    })
    .catch(function () {
      accepted.innerHTML = '<p class="lg-sm__error">Could not load connections.</p>';
    });
}

function respondToRequest(id, action) {
  /* PATCH /connections/<id> — action ('accept'|'decline') in the BODY, id in the path */
  fetch(API + '/connections/' + encodeURIComponent(id), {
    method:      'PATCH',
    credentials: 'include',
    headers:     { 'Content-Type': 'application/json' },
    body:        JSON.stringify({ action: action }),
  })
    .then(function (r) { if (r.ok) { loadConnections(); refreshCounts(); } })
    .catch(function () {});
}

/* ── unified social modal: Messages + Connections tabs ── */
var loadedTabs = { messages: false, connections: false };

/* Show one pane, mark its tab active, lazy-load it (once per open session).
   opts.dmUuid → jump straight into that thread on the Messages tab. */
function activateTab(tab, opts) {
  opts = opts || {};
  document.querySelectorAll('[data-lg-pane]').forEach(function (p) {
    p.hidden = p.getAttribute('data-lg-pane') !== tab;
  });
  document.querySelectorAll('[data-lg-tab]').forEach(function (t) {
    var on = t.getAttribute('data-lg-tab') === tab;
    t.classList.toggle('is-active', on);
    t.setAttribute('aria-selected', on ? 'true' : 'false');
  });

  if (tab === 'messages') {
    if (opts.dmUuid) {
      loadedTabs.messages = true;
      openDmWithUser(opts.dmUuid);          /* opens the thread detail directly */
    } else if (!loadedTabs.messages) {
      loadedTabs.messages = true;
      loadThreadList();
    }
  } else if (tab === 'connections' && !loadedTabs.connections) {
    loadedTabs.connections = true;
    loadConnections();
  }

  /* back button only when the Messages pane is showing thread detail */
  var back   = document.querySelector('[data-lg-thread-back]');
  var detail = document.getElementById('lg-msg-detail');
  if (back) back.hidden = !(tab === 'messages' && detail && !detail.hidden);
}

/* Open the modal fresh on a given tab (header icons + lg:open-dm). */
function openSocialModal(tab, opts) {
  loadedTabs = { messages: false, connections: false };   /* fresh data each open */
  openModal('lg-social-modal');
  activateTab(tab || 'messages', opts);
}

document.querySelectorAll('[data-lg-tab]').forEach(function (t) {
  t.addEventListener('click', function () { activateTab(t.getAttribute('data-lg-tab')); });
});

/* ── button hookup ── */
var notifBtn = document.querySelector('[data-lg-notif-link]');
var msgBtn   = document.querySelector('[data-lg-msg-link]');
var connBtn  = document.querySelector('[data-lg-conn-link]');

if (notifBtn) notifBtn.addEventListener('click', function (e) { e.preventDefault(); openModal('lg-notif-modal'); loadNotifications(); });
if (msgBtn)   msgBtn.addEventListener  ('click', function (e) { e.preventDefault(); openSocialModal('messages');    });
if (connBtn)  connBtn.addEventListener ('click', function (e) { e.preventDefault(); openSocialModal('connections'); });

/* "Mark all read" (notifications) */
var notifReadAllBtn = document.querySelector('[data-lg-notif-readall]');
if (notifReadAllBtn) notifReadAllBtn.addEventListener('click', markAllNotifsRead);

/* connections search — client-side filter of the loaded accepted[] */
var connSearch = document.getElementById('lg-conn-search');
if (connSearch) connSearch.addEventListener('input', function () { renderAccepted(connSearch.value); });

/* image attachment: paperclip opens the file picker; change stages a preview */
var attachBtn   = document.querySelector('[data-lg-attach]');
var attachInput = document.getElementById('lg-msg-attach-input');
if (attachBtn && attachInput) {
  attachBtn.addEventListener('click', function () { attachInput.click(); });
  attachInput.addEventListener('change', function () { stageAttach(attachInput.files && attachInput.files[0]); });
}

document.addEventListener('click', function (e) {
  var t = e.target;
  if ((t.hasAttribute && t.hasAttribute('data-lg-thread-back')) ||
      (t.closest && t.closest('[data-lg-thread-back]'))) { loadThreadList(); }
  if ((t.hasAttribute && t.hasAttribute('data-lg-send-reply')) ||
      (t.closest && t.closest('[data-lg-send-reply]')))  { sendReply(); }
  if ((t.hasAttribute && t.hasAttribute('data-lg-attach-remove')) ||
      (t.closest && t.closest('[data-lg-attach-remove]'))) { clearAttach(); }
});
document.addEventListener('keydown', function (e) {
  if (e.key === 'Enter' && !e.shiftKey &&
      e.target && e.target.id === 'lg-msg-reply-input') {
    e.preventDefault(); sendReply();
  }
});

/* ── init ── */
refreshCounts();   /* one call now sets msg + notif + conn badges */
setInterval(refreshCounts, 60000);

})();
