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
 * GET   /me/messages/             {threads:[{id,uuid,unread_count,last_message_at,last_snippet,
 *                                  last_sender,peers:[{uuid,name,slug,avatar_url}]}]}  (key by thread uuid)
 * GET   /users?uuids=<uuid>       {items:[{uuid,slug,display_name,avatar_url,…}]}  (members only;
 *                                  names the recipient of a DM that has no thread yet)
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
var currentPeers      = [];    // EVERY peer of the open thread (see renderPeerHeader)
/* Group-management state for the OPEN thread. Set from the thread() payload; drives the
   member manager, author lines, and the own-message edit/delete affordance. */
var currentThreadMeta = null;  // {is_group, created_by, can_manage, members:[…], meUuid}
var pendingGroupUuids = null;  // selected uuids for a not-yet-created group (first send → to_uuids[])
/* Bumped by every navigation. A thread/list response that resolves after you have
   navigated elsewhere belongs to a screen that no longer exists and must not write:
   a 6s-delayed response for thread A was proven to repaint thread B's header AND its
   messages (2026-07-10). Guards live on .then AND .catch — an error from the old
   screen would otherwise paint "Could not load thread" over the new one. */
var navSeq            = 0;

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
/* No text field on the wire — compose the sentence from type + actor.name.
   Hub events (notifications lane) carry actor_count: the backend coalesces two
   reactors on one card into ONE row, so the sentence has to say so. */
function notifActors(n) {
  var who   = esc((n.actor && n.actor.name) || 'Someone');
  var extra = (n.actor_count || 1) - 1;
  if (extra === 1) return who + ' and 1 other';
  if (extra > 1)   return who + ' and ' + extra + ' others';
  return who;
}
function notifText(n) {
  var who = (n.actor && n.actor.name) || 'Someone';
  switch (n.type) {
    case 'connection_accept':  return esc(who) + ' accepted your connection request';
    case 'connection_request': return esc(who) + ' sent you a connection request';
    case 'message':            return 'New message from ' + esc(who);
    /* Hub events — all deep-link into the §4e discussion modal on the exact item. */
    case 'forum.reply_to_topic': return notifActors(n) + ' replied to your post';
    case 'forum.reply_to_reply': return notifActors(n) + ' replied to your comment';
    case 'forum.mention':        return notifActors(n) + ' mentioned you in a discussion';
    case 'reaction.on_post':     return notifActors(n) + ' reacted to your post';
    default:                     return esc(who);
  }
}
var X_SVG = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor"'
  + ' stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
  + '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';

/* A row that HAS somewhere to land renders as a real <a> — the whole row is the
   click target, middle-click/⌘-click open a tab, and the status bar shows where it
   goes. Rows with no link (the legacy connection events) stay <div>s, so a row can
   never navigate somewhere wrong. The × (delete) button keeps its own
   stopPropagation so removing a row never navigates it. The × is REAL delete now
   (v2): every row carries one — read or unread — because you can delete either. */
function renderNotifItem(n) {
  var unread = !n.is_read;
  var link   = n.link || '';
  var tag    = link ? 'a' : 'div';
  var attrs  = 'class="lg-notif__item' + (unread ? ' lg-notif__item--unread' : '') +
               (link ? ' lg-notif__item--link' : '') + '" data-notif-id="' + esc(n.id) + '"';
  if (link) attrs += ' href="' + esc(link) + '" data-notif-link';
  return '<' + tag + ' ' + attrs + '>'
    + '<div class="lg-notif__body">'
      + '<p class="lg-notif__text">' + notifText(n) + '</p>'
      + '<span class="lg-notif__time">' + relTime(n.created_at) + '</span>'
    + '</div>'
    + '<button class="lg-notif__clear" data-notif-del="' + esc(n.id) +
      '" title="Delete" aria-label="Delete notification">' + X_SVG + '</button>'
    + '</' + tag + '>';
}
/* Read-on-clickthrough: opening the thing marks that ONE notification read.
   keepalive lets the POST survive the navigation we are NOT preventing — the link
   navigates natively (so modified clicks still work) while the mark-read flies. */
function markNotifReadOnNav(id) {
  try {
    fetch(API + '/me/notifications/', {
      method:      'POST',
      credentials: 'include',
      keepalive:   true,
      headers:     { 'Content-Type': 'application/json' },
      body:        JSON.stringify({ action: 'read', id: parseInt(id, 10) }),
    });
  } catch (e) {}
}
function updateClearAllBtn(show) {
  var b = document.querySelector('[data-lg-notif-clearall]');
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
        updateClearAllBtn(false);
        return;
      }
      list.innerHTML = items.map(renderNotifItem).join('');
      /* NO auto-mark-read — the user controls it (per-item × delete, or click-through
         marks the one read). Clear-all is offered whenever the list is non-empty. */
      updateClearAllBtn(items.length > 0);
      list.querySelectorAll('[data-notif-del]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.preventDefault();          /* the × sits inside an <a> — don't navigate */
          e.stopPropagation();
          deleteNotif(btn.getAttribute('data-notif-del'));
        });
      });
      /* Click-through: mark read, then let the browser follow the href into the
         discussion modal (forums.js §4f routes ?topic=&reply= on both surfaces). */
      list.querySelectorAll('[data-notif-link]').forEach(function (row) {
        row.addEventListener('click', function (e) {
          if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey) return;
          markNotifReadOnNav(row.getAttribute('data-notif-id'));
        });
      });
    })
    .catch(function () {
      list.innerHTML = '<p class="lg-sm__error">Could not load notifications.</p>';
    });
}
/* Per-item × = REAL delete (v2): the row is removed here AND server-side via an
   owner-scoped DELETE, so it's gone on every device. A 404 (someone else's id /
   already gone) leaves the row untouched — never a silent success. Replaces the
   v1 "mark-read in place" fudge; click-through still marks the one read. */
function deleteNotif(id) {
  fetch(API + '/me/notifications/?id=' + encodeURIComponent(id), {
    method:      'DELETE',
    credentials: 'include',
  })
    .then(function (r) {
      if (!r.ok) return;                 /* 404 = not yours / gone → leave the row */
      var row = document.querySelector('.lg-notif__item[data-notif-id="' + id + '"]');
      if (row) row.remove();
      var list = document.getElementById('lg-notif-list');
      var any  = !!(list && list.querySelector('.lg-notif__item'));
      if (list && !any) list.innerHTML = '<p class="lg-sm__empty">No notifications yet.</p>';
      updateClearAllBtn(any);
      refreshCounts();
    })
    .catch(function () {});
}
/* "Clear all" = DELETE every notification server-side (the mobile watermark's real
   twin, now that clear actually deletes). Gone everywhere, every device. */
function deleteAllNotifs() {
  fetch(API + '/me/notifications/?all=1', {   /* query, not body — DELETE bodies get stripped by some proxies */
    method:      'DELETE',
    credentials: 'include',
  })
    .then(function (r) {
      if (!r.ok) return;
      var list = document.getElementById('lg-notif-list');
      if (list) list.innerHTML = '<p class="lg-sm__empty">No notifications yet.</p>';
      updateClearAllBtn(false);
      refreshCounts();
    })
    .catch(function () {});
}

/* ── messages: peer identity ──
   A thread has ANY number of peers — 0 (a thread whose counterpart recipient row is
   missing: 38 of them on dev2), 1 (a normal DM), or many (group threads). Every
   surface used to render peers[0] as "the" recipient, which presented a group thread
   as a private 1:1: you could reply into what read as a private chat with Doug and
   Sharon + John also received it. peersByThread() now returns a deterministic order,
   so the helpers below name the SAME people in the list and in the open header — but
   the fix is that they name ALL of them. Nothing here may ever fall back to peers[0]. */

/* "Doug Proper" · "Doug Proper, John Lehmann" · "Doug Proper, John Lehmann +2" */
function peerLabel(peers, max) {
  var ps = peers || [];
  if (!ps.length) return 'Unknown member';   /* never inherit the previous thread's name */
  var names = ps.map(function (p) { return p.name || p.display_name || 'Member'; });
  max = max || 2;
  if (names.length <= max) return names.join(', ');
  return names.slice(0, max).join(', ') + ' +' + (names.length - max);
}
/* Overlapping faces, so a group is legible as a group before you read a word. */
function avatarStack(peers, sz) {
  var ps = (peers || []).slice(0, 3);
  if (!ps.length) return '<span class="lg-sm__avatar lg-sm__avatar--initial">?</span>';
  if (ps.length === 1) return avatarEl(ps[0], sz);
  return '<span class="lg-msg__avstack">' + ps.map(function (p) {
    return avatarEl(p, sz);
  }).join('') + '</span>';
}
/* Total humans in the thread, counting the viewer — how a person counts a group chat. */
function peerTotal(peers) { return ((peers || []).length) + 1; }

/* ── messages: thread list ── */
function loadThreadList() {
  var list   = document.getElementById('lg-msg-list');
  var detail = document.getElementById('lg-msg-detail');
  if (!list) return;
  var seq = ++navSeq;                 /* going back to the list is a navigation too */
  currentThreadUuid = null;
  pendingPeerUuid   = null;
  pendingGroupUuids = null;
  currentThreadMeta = null;
  clearAttach();
  clearPeerHeader();
  hideMsgPanel();
  showListChrome(true);
  list.hidden = false;
  if (detail) detail.hidden = true;
  /* show back button only when in detail */
  var backBtn = document.querySelector('[data-lg-thread-back]');
  if (backBtn) backBtn.hidden = true;
  list.innerHTML = '<p class="lg-sm__status">Loading...</p>';
  fetch(API + '/me/messages/', { credentials: 'include' })
    .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
    .then(function (d) {
      if (seq !== navSeq) return;     /* a thread is open now — do not paint the list over it */
      var threads = (d && d.threads) || [];
      if (!threads.length) {
        list.innerHTML = '<p class="lg-sm__empty">No messages yet.</p>';
        return;
      }
      list.innerHTML = threads.map(function (t) {
        var ps     = (t.peers) || [];   /* peersByThread → [{uuid,name,slug,avatar_url}, …] */
        var unread = t.unread_count || 0;
        var prev   = t.last_snippet || '';
        var group  = ps.length > 1;
        /* A custom group name (subject) wins over the member-name label; empty/absent → label. */
        var title  = (t.subject && String(t.subject).length) ? String(t.subject) : peerLabel(ps, 2);
        return '<div class="lg-msg__thread' + (unread ? ' lg-msg__thread--unread' : '') +
          '" data-thread-uuid="' + esc(t.uuid) + '" tabindex="0" role="button">'
          + '<div class="lg-msg__av">' + avatarStack(ps, 36) + '</div>'
          + '<div class="lg-msg__meta">'
            + '<div class="lg-msg__nameline">'
              + '<span class="lg-msg__name">' + esc(title) + '</span>'
              + (group ? '<span class="lg-msg__group-tag">Group · ' + peerTotal(ps) + '</span>' : '')
            + '</div>'
            + '<div class="lg-msg__preview">' + esc(prev) + '</div>'
          + '</div>'
          + (unread ? '<span class="lg-sm__badge">' + capCount(unread) + '</span>' : '')
          + '</div>';
      }).join('');
      /* carry the WHOLE peers array into the thread, not one member of it */
      var peersByThread = {};
      threads.forEach(function (t) { peersByThread[t.uuid] = t.peers || []; });
      list.querySelectorAll('[data-thread-uuid]').forEach(function (el) {
        var open = function () { openThread(el.dataset.threadUuid, peersByThread[el.dataset.threadUuid]); };
        el.addEventListener('click', open);
        el.addEventListener('keydown', function (e) {
          if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); }
        });
      });
    })
    .catch(function () {
      if (seq !== navSeq) return;
      list.innerHTML = '<p class="lg-sm__error">Could not load messages.</p>';
    });
}

/* ── messages: who you are talking to ──
   The thread list names the counterpart, and it is display:none the moment a thread
   opens, so the open conversation showed no name, no avatar, nothing (HK-019).
   EVERY peer is named here — a reply goes to the whole thread, so the header has to
   say who the whole thread is. */
var REPLY_PLACEHOLDER = 'Message… (Enter to send, Shift+Enter for newline)';
function renderPeerHeader(peers) {
  var el = document.getElementById('lg-msg-peer');
  if (!el) return;
  var ps = peers || [];
  currentPeers = ps;             /* so a send can re-open without re-deriving them */
  if (!ps.length) {
    /* Zero-peer thread. Honest copy and NO /u/ link — there is nobody to link to, and
       the old code fell through to peers[0] of whatever was rendered last. */
    el.innerHTML = '<span class="lg-sm__avatar lg-sm__avatar--initial">?</span>'
      + '<span class="lg-msg__peer-names"><span class="lg-msg__peer-name">Unknown member</span>'
      + '<span class="lg-msg__peer-note">This conversation has no other members.</span></span>';
    el.hidden = false;
    setReplyPlaceholder(ps);
    return;
  }
  var names = ps.map(function (p) {
    var name = p.name || p.display_name || 'Member';
    return p.slug
      ? '<a class="lg-msg__peer-name" href="/u/' + esc(p.slug) + '">' + esc(name) + '</a>'
      : '<span class="lg-msg__peer-name">' + esc(name) + '</span>';
  }).join('<span class="lg-msg__peer-sep">, </span>');
  /* A custom group name (subject) wins as the header title; the member names then drop to the
     subline (Ian 7/12). No subject → the member names stay the title, as before. */
  var subject  = currentThreadMeta && currentThreadMeta.subject;
  var groupNote = ps.length > 1
    ? 'Group · ' + peerTotal(ps) + ' people · everyone here sees your reply' : '';
  /* Members / manage — only on a real (already-created) thread. A not-yet-created DM or new-
     group compose has no thread to manage yet. Opens the member manager panel. */
  var manageBtn = currentThreadUuid
    ? '<button type="button" class="lg-msg__manage" data-lg-manage aria-label="' +
      (ps.length > 1 ? 'Manage members' : 'Members and add people') + '" title="' +
      (ps.length > 1 ? 'Manage members' : 'Add people') + '">'
      + '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"'
      + ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
      + '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>'
      + '<path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>'
      + '</button>'
    : '';
  el.innerHTML = avatarStack(ps, 36)
    + '<span class="lg-msg__peer-names">'
    + (subject
        ? '<span class="lg-msg__peer-name lg-msg__peer-title">' + esc(subject) + '</span>'
          + '<span class="lg-msg__peer-note">' + names
            + (groupNote ? '<span class="lg-msg__peer-sep"> · </span>' + groupNote : '') + '</span>'
        : names
          + (groupNote ? '<span class="lg-msg__peer-note">' + groupNote + '</span>' : ''))
    + '</span>'
    + manageBtn;
  el.hidden = false;
  setReplyPlaceholder(ps);
}
/* The last place a reply can be misread as private is the box you type into, so the
   composer says where it is going. */
function setReplyPlaceholder(peers) {
  var input = document.getElementById('lg-msg-reply-input');
  if (!input) return;
  input.placeholder = (peers || []).length > 1
    ? 'Message all ' + peerTotal(peers) + ' people…'
    : REPLY_PLACEHOLDER;
}
function clearPeerHeader() {
  var el = document.getElementById('lg-msg-peer');
  currentPeers = [];
  if (el) { el.innerHTML = ''; el.hidden = true; }
  setReplyPlaceholder([]);
}
/* A brand-new DM has no thread yet, so nothing carries peers[] — resolve the single
   uuid we were handed. Without this, "Message" from a profile opens a conversation
   with an unnamed stranger, which is the exact case HK-019 calls out. */
function fetchPeer(uuid) {
  return fetch(API + '/users?uuids=' + encodeURIComponent(uuid), { credentials: 'include' })
    .then(function (r) { return r.ok ? r.json() : null; })
    .then(function (d) { return (d && d.items && d.items[0]) || null; })
    .catch(function () { return null; });
}

/* ── messages: thread detail (keyed by opaque thread uuid) ── */
function showDetailPane() {
  var list   = document.getElementById('lg-msg-list');
  var detail = document.getElementById('lg-msg-detail');
  var backBtn = document.querySelector('[data-lg-thread-back]');
  if (list)    list.hidden    = true;
  if (detail)  detail.hidden  = false;
  if (backBtn) backBtn.hidden = false;
  hideMsgPanel();
  showListChrome(false);
}
/* The "＋ New message" bar belongs to the thread-list view only — hidden whenever a
   thread, the compose picker, or the member manager is showing. */
function showListChrome(on) {
  var bar = document.getElementById('lg-msg-newbar');
  if (bar) bar.hidden = !on;
}
/* The shared panel hosts the compose picker AND the member manager. */
function showMsgPanel(html) {
  var list   = document.getElementById('lg-msg-list');
  var detail = document.getElementById('lg-msg-detail');
  var panel  = document.getElementById('lg-msg-panel');
  var back   = document.querySelector('[data-lg-thread-back]');
  if (!panel) return;
  if (list)   list.hidden   = true;
  if (detail) detail.hidden = true;
  showListChrome(false);
  panel.innerHTML = html;
  panel.hidden = false;
  if (back) back.hidden = false;   /* panel is a sub-screen — back returns to the list */
}
function hideMsgPanel() {
  var panel = document.getElementById('lg-msg-panel');
  if (panel) { panel.hidden = true; panel.innerHTML = ''; }
}
/* peersHint = the peers[] we already hold from the thread row, so the header paints
   with the messages rather than a beat later, once the thread fetch resolves. It is
   the WHOLE array — handing openThread a single peer is what let a group thread open
   under one person's name. */
function openThread(threadUuid, peersHint) {
  var detail  = document.getElementById('lg-msg-detail');
  var msgs    = document.getElementById('lg-msg-messages');
  var compose = document.getElementById('lg-msg-compose');
  if (!detail || !msgs) return;
  var seq = ++navSeq;
  currentThreadUuid = threadUuid;
  pendingPeerUuid   = null;
  pendingGroupUuids = null;
  currentThreadMeta = null;
  clearAttach();
  showDetailPane();
  /* Clear on ENTRY. Every caller happens to clear today, so the previous thread's
     header is not actually reachable on main — but openThread being correct only
     because all four of its callers remembered is a trap, and the next caller that
     forgets ships "you are chatting with <the last person you looked at>". */
  clearPeerHeader();
  if (peersHint && peersHint.length) renderPeerHeader(peersHint);
  msgs.innerHTML = '<p class="lg-sm__status">Loading...</p>';

  fetch(API + '/me/messages/' + encodeURIComponent(threadUuid), { credentials: 'include' })
    .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
    .then(function (d) {
      /* This response is for a screen the user has left (they went back, or opened
         another thread). Writing now would repaint their CURRENT thread with THIS
         thread's header and messages. */
      if (seq !== navSeq || currentThreadUuid !== threadUuid) return;
      var peers = (d && d.peers) || [];
      /* Capture group-management context for this thread: who can manage, the full member
         list, and the viewer's own uuid (members MINUS peers — peers is everyone but you). */
      var members = (d && d.members) || [];
      var peerUuids = {};
      peers.forEach(function (p) { peerUuids[p.uuid] = true; });
      var meMember = members.filter(function (mm) { return !peerUuids[mm.uuid]; })[0];
      currentThreadMeta = {
        is_group:   !!(d && d.is_group),
        created_by: d && d.created_by,
        can_manage: !!(d && d.can_manage),
        members:    members,
        meUuid:     meMember ? meMember.uuid : null,
        subject:    (d && d.thread && d.thread.subject) || null,   /* custom group name, or null */
      };
      renderPeerHeader(peers);   /* authoritative identity for this thread */
      renderThreadMessages(msgs, (d && d.messages) || [], peers, members);

      if (compose) compose.hidden = false;
      setTimeout(refreshCounts, 400);  /* GET thread marks read server-side */
    })
    .catch(function () {
      /* Same guard: a failure belonging to the thread you already left must not put
         an error over the thread you are reading now. */
      if (seq !== navSeq || currentThreadUuid !== threadUuid) return;
      msgs.innerHTML = '<p class="lg-sm__error">Could not load thread.</p>';
    });
}

/* Open (or start) a DM with a specific USER uuid — used by the lg:open-dm event.
   Resolve the user to an existing 1:1 thread; if none, set up a new-thread compose. */
function openDmWithUser(peerUuid) {
  var msgs    = document.getElementById('lg-msg-messages');
  var compose = document.getElementById('lg-msg-compose');
  if (!msgs) return;
  var seq = ++navSeq;
  clearAttach();
  clearPeerHeader();
  showDetailPane();
  msgs.innerHTML = '<p class="lg-sm__status">Loading...</p>';
  fetch(API + '/me/messages/', { credentials: 'include' })
    .then(function (r) { return r.ok ? r.json() : { threads: [] }; })
    .then(function (d) {
      if (seq !== navSeq) return;
      var threads = (d && d.threads) || [];
      var match = null;
      threads.forEach(function (t) {
        /* ONLY a true 1:1 thread. A GROUP thread that happens to contain this person is
           not "your conversation with them" — opening it would aim a private-intent DM
           at everyone in the group. The server's findPairThread() enforces the same rule
           on the send path, so the two cannot diverge. */
        var ps = t.peers || [];
        if (ps.length === 1 && ps[0].uuid === peerUuid) match = t;
      });
      if (match) {
        openThread(match.uuid, match.peers || []);
        return;
      }
      /* no thread yet — first message creates it via POST /me/messages/ {to_uuid} */
      currentThreadUuid = null;
      pendingPeerUuid   = peerUuid;
      msgs.innerHTML = '<p class="lg-sm__empty">No messages yet. Send the first one!</p>';
      if (compose) compose.hidden = false;
      /* no thread means no peers[] on the wire — resolve the recipient so they are
         named before the first message is sent, not after */
      fetchPeer(peerUuid).then(function (u) {
        /* …and only if we are still on THAT new-DM screen: no thread has since been
           opened, and no later navigation has happened. */
        if (u && seq === navSeq && !currentThreadUuid && pendingPeerUuid === peerUuid) renderPeerHeader([u]);
      });
      var input = document.getElementById('lg-msg-reply-input');
      if (input) input.focus();
    })
    .catch(function () {
      if (seq !== navSeq) return;
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
  if (!currentThreadUuid && !pendingPeerUuid && !pendingGroupUuids) return;
  var input = document.getElementById('lg-msg-reply-input');
  var text  = input ? input.value.trim() : '';
  /* require text OR an image (image-only messages are allowed) */
  if (!text && !pendingAttachFile) return;

  /* A group is created by its FIRST message (POST to_uuids), and that path has no image
     variant — so the first line of a new group must be text. Photos work once it exists. */
  if (pendingGroupUuids && pendingAttachFile) {
    setAttachError('Send a message to start the group first — you can add photos once it exists.');
    return;
  }
  if (pendingAttachFile) { sendWithAttachment(text); return; }

  var saved = text;
  input.value    = '';
  input.disabled = true;

  var url, payload, groupUuids = pendingGroupUuids, groupPeers = currentPeers;
  if (currentThreadUuid) {                       /* reply in existing thread */
    url     = API + '/me/messages/' + encodeURIComponent(currentThreadUuid);
    payload = { body: text };
  } else if (pendingGroupUuids) {                /* first message → creates the GROUP thread */
    url     = API + '/me/messages/';
    payload = { to_uuids: pendingGroupUuids, body: text };
  } else {                                       /* first message → creates the 1:1 thread */
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
      /* carry the peers forward: openThread() now clears the header on entry, and
         sendReply re-opens the SAME thread — without the hint the header would blink
         empty on every single send. */
      if (currentThreadUuid) return openThread(currentThreadUuid, currentPeers);
      if (groupUuids) {                           /* group now exists — open it by its new uuid */
        return r.json().then(function (j) {
          pendingGroupUuids = null;
          if (j && j.thread_uuid) openThread(j.thread_uuid, groupPeers);
          else loadThreadList();
        });
      }
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
  if (sendBtn) {
    /* visible in-flight state (Ian 7/06): spinner replaces the send icon —
       a disabled button alone reads as "nothing happened" during a slow upload */
    sendBtn.disabled = true;
    sendBtn.classList.add('lg-msg__send-btn--sending');
    sendBtn.setAttribute('aria-busy', 'true');
  }
  /* NB: no Content-Type header — the browser sets the multipart boundary. */
  fetch(url, { method: 'POST', credentials: 'include', body: fd })
    .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json().catch(function () { return {}; }); })
    .then(function () {
      if (input) input.value = '';
      clearAttach();
      if (currentThreadUuid) return openThread(currentThreadUuid, currentPeers);   /* peers forward — no header blink */
      pendingPeerUuid = null;
      return openDmWithUser(newDmPeer);
    })
    .catch(function () {
      /* keep staged file + text for retry, but SAY it failed */
      setAttachError("Couldn't send your photo — nothing was posted. Tap Send to retry.");
    })
    .then(function () {
      attachSending = false;
      if (sendBtn) {
        sendBtn.disabled = false;
        sendBtn.classList.remove('lg-msg__send-btn--sending');
        sendBtn.removeAttribute('aria-busy');
      }
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

/* "Clear all" (notifications) — DELETEs every row server-side */
var notifClearAllBtn = document.querySelector('[data-lg-notif-clearall]');
if (notifClearAllBtn) notifClearAllBtn.addEventListener('click', deleteAllNotifs);

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

/* ══ group messaging management (lane: messages-manage) ══════════════════════════
   Compose a group, manage membership, edit/delete own messages, image lightbox.
   Every rule is ALSO enforced server-side (src/Messaging.php + the two endpoints);
   nothing here is the only guard — a hidden button never gates access. */

/* ── thread render: system lines · author lines · (edited) · tombstone · own-msg menu ── */
function renderThreadMessages(msgs, messages, peers, members) {
  var peerSet = {};
  (peers || []).forEach(function (p) { peerSet[p.uuid] = true; });
  var group = (peers || []).length > 1;
  var nameBy = {};
  (members || []).forEach(function (m) { nameBy[m.uuid] = m.name || m.display_name || 'Member'; });

  if (!messages.length) {
    msgs.innerHTML = '<p class="lg-sm__empty">No messages yet. Send the first one!</p>';
    return;
  }
  var lastSender = null;
  msgs.innerHTML = messages.map(function (m) {
    /* membership / transparency line — centered pill, never a bubble, never owned */
    if (m.kind === 'system') {
      lastSender = null;
      return '<div class="lg-msg__sys">' + esc(m.body) + '</div>';
    }
    var mine = !peerSet[m.sender_uuid];   /* mine = sender is NOT among the peers */
    var h = '';
    /* who-said-it label above a run of a peer's messages in a GROUP (never for you) */
    if (group && !mine && m.sender_uuid !== lastSender) {
      h += '<span class="lg-msg__author">' + esc(nameBy[m.sender_uuid] || 'Member') + '</span>';
    }
    lastSender = m.sender_uuid;
    /* soft-deleted → tombstone; body + media were already withheld server-side */
    if (m.deleted) {
      return h + '<div class="lg-msg__msg' + (mine ? ' lg-msg__msg--mine' : '') + '">'
        + '<p class="lg-msg__msg-text lg-msg__msg-text--tomb">Message deleted</p></div>';
    }
    h += '<div class="lg-msg__msg' + (mine ? ' lg-msg__msg--mine' : '') + '"'
       + (mine ? ' data-lg-msg-id="' + esc(m.id) + '"' + (m.body ? ' data-lg-body="' + esc(m.body) + '"' : '') : '')
       + '>';
    if (m.media_url) {
      /* image attachment → in-app lightbox (SAME access-controlled /message-media/ URL) */
      h += '<button type="button" class="lg-msg__msg-media" data-lg-msg-lightbox="' + esc(m.media_url) + '">'
         + '<img src="' + esc(m.media_url) + '" alt="Photo" loading="lazy">'
         + '<span class="lg-msg__zoomdot" aria-hidden="true">⤢</span></button>';
    }
    if (m.body) {
      h += '<p class="lg-msg__msg-text">' + linkifyText(m.body)
         + (m.edited ? '<span class="lg-msg__edited">(edited)</span>' : '') + '</p>';
    }
    /* own-message hover menu: Edit (text only) + Delete */
    if (mine) {
      h += '<span class="lg-msg__acts">'
         + (m.body ? '<button type="button" class="lg-msg__act" data-lg-edit>Edit</button>' : '')
         + '<button type="button" class="lg-msg__act lg-msg__act--del" data-lg-del>Delete</button></span>';
    }
    h += '<span class="lg-msg__msg-time">' + relTime(m.created_at) + '</span></div>';
    return h;
  }).join('');
  msgs.scrollTop = msgs.scrollHeight;
}

/* ── compose / add picker (multi-select from accepted connections) ── */
var pickerMode     = 'new';   /* 'new' = compose · 'add' = add to the open thread */
var pickerSelected = [];      /* [{uuid, display_name, slug, avatar_url}, …] */
var pickerConns    = [];      /* accepted connections for the search list */
var mmMembers      = [];      /* member uuids of the thread the add-picker feeds (exclude them) */

function chipAvatar(u) {
  var name = u.display_name || u.name || '?';
  var url  = u.avatar_url || '';
  if (url) return '<img class="lg-msg__chip-av" src="' + esc(url) + '" alt="">';
  return '<span class="lg-msg__chip-av lg-msg__chip-av--i">' + esc(name.charAt(0).toUpperCase()) + '</span>';
}
function pickerCurrentSearch() {
  var s = document.getElementById('lg-pick-search');
  return s ? s.value : '';
}
function renderPickChips() {
  var box = document.getElementById('lg-pick-chips');
  if (!box) return;
  box.innerHTML = pickerSelected.map(function (u) {
    return '<span class="lg-msg__chip">' + chipAvatar(u)
      + esc(u.display_name || u.name || 'Member')
      + '<button type="button" class="lg-msg__chip-x" data-lg-pick-remove="' + esc(u.uuid) + '" aria-label="Remove">✕</button></span>';
  }).join('');
}
function renderPickList(filter) {
  var list = document.getElementById('lg-pick-list');
  if (!list) return;
  var f = (filter || '').trim().toLowerCase();
  var chosen = {}; pickerSelected.forEach(function (u) { chosen[u.uuid] = true; });
  var excl   = {}; if (pickerMode === 'add') mmMembers.forEach(function (uu) { excl[uu] = true; });
  var rows = pickerConns.filter(function (u) {
    if (chosen[u.uuid] || excl[u.uuid]) return false;
    if (!f) return true;
    return String(u.display_name || '').toLowerCase().indexOf(f) > -1
        || String(u.slug || '').toLowerCase().indexOf(f) > -1;
  });
  if (!rows.length) {
    list.innerHTML = '<p class="lg-sm__empty">' +
      (pickerConns.length ? 'No connections match.' : 'No connections yet.') + '</p>';
    return;
  }
  list.innerHTML = rows.map(function (u) {
    return '<div class="lg-msg__pi" data-lg-pick-add="' + esc(u.uuid) + '" role="button" tabindex="0">'
      + avatarEl(u, 36)
      + '<div class="lg-msg__pi-col"><div class="lg-msg__pi-name">' + esc(u.display_name || 'Member') + '</div>'
      + (u.slug ? '<div class="lg-msg__pi-sub">@' + esc(u.slug) + '</div>' : '') + '</div>'
      + '<span class="lg-msg__pi-add">Add</span></div>';
  }).join('');
}
function updatePickGo() {
  var go = document.getElementById('lg-pick-go');
  if (!go) return;
  var n = pickerSelected.length;
  go.disabled = n < 1;
  go.textContent = pickerMode === 'add' ? 'Add' : (n >= 2 ? 'Start group' : 'Message');
}
function pickerShellHtml(title, hint, ctaLabel) {
  return '<div class="lg-msg__pk">'
    + '<h4 class="lg-msg__pk-title">' + esc(title) + '</h4>'
    + '<p class="lg-msg__pk-hint">' + esc(hint) + '</p>'
    + '<div class="lg-msg__pk-field">'
      + '<span id="lg-pick-chips"></span>'
      + '<input id="lg-pick-search" class="lg-msg__pk-search" placeholder="Search connections…" autocomplete="off" aria-label="Search connections">'
    + '</div>'
    + '<div class="lg-msg__pk-list" id="lg-pick-list"><p class="lg-sm__status">Loading…</p></div>'
    + '<div class="lg-msg__pk-cta">'
      + '<button type="button" class="lg-msg__btn lg-msg__btn--ghost" data-lg-pick-cancel>Cancel</button>'
      + '<button type="button" class="lg-msg__btn lg-msg__btn--primary" id="lg-pick-go" data-lg-pick-go disabled>' + esc(ctaLabel) + '</button>'
    + '</div></div>';
}
function loadPickerConns() {
  fetch(API + '/me/connections/', { credentials: 'include' })
    .then(function (r) { return r.ok ? r.json() : null; })
    .then(function (d) { pickerConns = (d && d.accepted) || []; renderPickList(pickerCurrentSearch()); })
    .catch(function () {
      var list = document.getElementById('lg-pick-list');
      if (list) list.innerHTML = '<p class="lg-sm__error">Could not load connections.</p>';
    });
}
function openComposePicker() {
  pickerMode = 'new'; pickerSelected = [];
  showMsgPanel(pickerShellHtml('New message', 'Add two or more people to start a group.', 'Start group'));
  renderPickChips(); updatePickGo(); loadPickerConns();
  var s = document.getElementById('lg-pick-search'); if (s) s.focus();
}
function openAddPicker() {
  if (!currentThreadUuid) return;
  pickerMode = 'add'; pickerSelected = [];
  showMsgPanel(pickerShellHtml('Add people', 'You can only add people you’re connected to.', 'Add'));
  renderPickChips(); updatePickGo(); loadPickerConns();
  var s = document.getElementById('lg-pick-search'); if (s) s.focus();
}
function pickerAdd(uuid) {
  var u = pickerConns.filter(function (c) { return c.uuid === uuid; })[0];
  if (!u) return;
  pickerSelected.push(u);
  renderPickChips(); renderPickList(pickerCurrentSearch()); updatePickGo();
}
function pickerRemove(uuid) {
  pickerSelected = pickerSelected.filter(function (u) { return u.uuid !== uuid; });
  renderPickChips(); renderPickList(pickerCurrentSearch()); updatePickGo();
}
function pickerGo() {
  if (!pickerSelected.length) return;
  var uuids = pickerSelected.map(function (u) { return u.uuid; });
  if (pickerMode === 'add') { mmAddConfirm(uuids); return; }
  if (uuids.length === 1) { openDmWithUser(uuids[0]); return; }
  enterGroupCompose(pickerSelected.slice());
}
/* ≥2 selected → a not-yet-created group. Mirror the new-DM compose: hold the uuids,
   the first text message POSTs {to_uuids} and creates the thread (see sendReply). */
function enterGroupCompose(sel) {
  ++navSeq;
  currentThreadUuid = null;
  pendingPeerUuid   = null;
  currentThreadMeta = null;
  pendingGroupUuids = sel.map(function (u) { return u.uuid; });
  clearAttach();
  hideMsgPanel();
  showDetailPane();
  renderPeerHeader(sel.map(function (u) {
    return { uuid: u.uuid, name: u.display_name || u.name, slug: u.slug, avatar_url: u.avatar_url };
  }));
  var msgs = document.getElementById('lg-msg-messages');
  if (msgs) msgs.innerHTML = '<p class="lg-sm__empty">No messages yet. Send the first one to start the group.</p>';
  var compose = document.getElementById('lg-msg-compose');
  if (compose) compose.hidden = false;
  var input = document.getElementById('lg-msg-reply-input');
  if (input) input.focus();
}

/* ── member manager (opened from the thread header) ── */
function openMemberManager() {
  if (!currentThreadUuid) return;
  var tu = currentThreadUuid;
  showMsgPanel('<div class="lg-msg__mm"><p class="lg-sm__status">Loading…</p></div>');
  fetch(API + '/me/messages/' + encodeURIComponent(tu), { credentials: 'include' })
    .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
    .then(function (d) { if (currentThreadUuid === tu) renderMemberManager(d); })
    .catch(function () {
      var p = document.getElementById('lg-msg-panel');
      if (p) p.innerHTML = '<div class="lg-msg__mm"><p class="lg-sm__error">Could not load members.</p></div>';
    });
}
function renderMemberManager(d) {
  var peers = (d && d.peers) || [], members = (d && d.members) || [];
  var canManage = !!(d && d.can_manage), isGroup = !!(d && d.is_group), createdBy = d && d.created_by;
  var peerUuids = {}; peers.forEach(function (p) { peerUuids[p.uuid] = true; });
  var me = members.filter(function (m) { return !peerUuids[m.uuid]; })[0];
  var meUuid = me ? me.uuid : null;
  mmMembers = members.map(function (m) { return m.uuid; });   /* add-picker excludes them */

  var hint = canManage
    ? 'You can remove anyone in this ' + (isGroup ? 'group' : 'conversation') + '.'
    : (isGroup ? 'Only the group’s owner or a site admin can remove others. You can always leave.'
               : 'Add people to start a group — this private chat stays as it is.');

  var rows = members.map(function (m) {
    var isMe    = m.uuid === meUuid;
    var isOwner = createdBy && m.uuid === createdBy;   /* created_by = current owner (mutable) */
    var sub = isMe ? 'You' : (m.slug ? '@' + m.slug : '');
    /* Owner chip on the owner's row — visible to ALL members; only when an owner is recorded
       (legacy NULL-owner threads show no badge, never a guess). */
    var chip = isOwner ? '<span class="lg-msg__owner-chip">Owner</span>' : '';
    var actions;
    if (isMe) {
      actions = '<span class="lg-msg__you">You</span>';
    } else {
      actions = '';
      /* Transfer: the current owner OR a site admin (canManage) may hand ownership to any
         NON-owner member. Server re-checks and 403s anyone else. */
      if (canManage && isGroup && !isOwner) {
        actions += '<button type="button" class="lg-msg__mkowner" data-lg-mm-owner="' + esc(m.uuid) + '">Make owner</button>';
      }
      if (canManage) {
        actions += '<button type="button" class="lg-msg__rm" data-lg-mm-remove="' + esc(m.uuid) + '">Remove</button>';
      }
    }
    return '<div class="lg-msg__mmi">'
      + avatarEl(m, 36)
      + '<div class="lg-msg__mmi-col"><div class="lg-msg__mmi-name">' + esc(m.name || m.display_name || 'Member') + chip + '</div>'
      + '<div class="lg-msg__mmi-sub">' + esc(sub) + '</div></div>'
      + '<div class="lg-msg__mmi-actions">' + actions + '</div></div>';
  }).join('');

  /* Group-name field — ANY member may set/clear it (Ian 7/12). Groups only; a 1:1 has no
     custom title. Pre-filled with the current name; esc() makes the value attribute inert. */
  var subject = (d && d.thread && d.thread.subject) || '';
  var nameField = isGroup
    ? '<div class="lg-msg__mm-name">'
      + '<label class="lg-msg__mm-name-lbl" for="lg-mm-name">Group name</label>'
      + '<div class="lg-msg__mm-name-row">'
      + '<input type="text" id="lg-mm-name" class="lg-msg__mm-name-in" maxlength="60" '
      +   'placeholder="Add a name (optional)" value="' + esc(subject) + '">'
      + '<button type="button" class="lg-msg__mm-name-save" data-lg-mm-rename>Save</button>'
      + '</div>'
      + '<p class="lg-msg__mm-name-hint">Anyone here can rename the group. Clear the box to remove the name.</p>'
      + '</div>'
    : '';

  showMsgPanel('<div class="lg-msg__mm">'
    + '<h4 class="lg-msg__pk-title">Members · ' + members.length + '</h4>'
    + '<p class="lg-msg__pk-hint">' + esc(hint) + '</p>'
    + nameField
    + '<div class="lg-msg__mm-list">' + rows + '</div>'
    + '<div class="lg-msg__mm-foot">'
      + '<button type="button" class="lg-msg__addrow" data-lg-mm-add>＋ Add people</button>'
      + '<button type="button" class="lg-msg__leave" data-lg-mm-leave>' + (isGroup ? 'Leave group' : 'Leave') + '</button>'
    + '</div></div>');
}
function postMembers(body) {
  if (!currentThreadUuid) return Promise.resolve(null);
  return fetch(API + '/me/messages/' + encodeURIComponent(currentThreadUuid) + '/members', {
    method: 'POST', credentials: 'include',
    headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
  }).then(function (r) {
    return r.json().then(
      function (j) { j = j || {}; j._status = r.status; j._ok = r.ok && j.ok !== false; return j; },
      function ()  { return { _status: r.status, _ok: r.ok }; }
    );
  }).catch(function () { return null; });
}
function mmRemove(uuid) {
  if (!confirm('Remove this person from the group?')) return;
  postMembers({ remove: uuid }).then(function (res) {
    if (res && res._ok) { openMemberManager(); refreshCounts(); }
    else if (res && res._status === 403) alert('Only the group’s owner or a site admin can remove members.');
    else alert('Could not remove that member.');
  });
}
function mmLeave() {
  /* The owner must hand off before leaving while others remain (Ian 7/12 23:2x) — steer them to
     the transfer flow instead of a silent failure. Server re-enforces (400 transfer_required). */
  var m = currentThreadMeta || {};
  if (m.created_by && m.created_by === m.meUuid && (m.members || []).length > 1) {
    alert('You’re the group owner. Make someone else the owner first (tap “Make owner”), then you can leave.');
    return;
  }
  if (!confirm('Leave this conversation? You’ll lose access to it.')) return;
  postMembers({ leave: true }).then(function (res) {
    if (res && res._ok) { loadThreadList(); refreshCounts(); }
    else if (res && res._status === 400 && res.error === 'transfer_required') alert('Pass ownership to another member before you can leave.');
    else alert('Could not leave the conversation.');
  });
}
/* Rename (any member): re-open the thread so the new title + the "named the group" system
   line both land live; an empty box clears the name and reverts to the member-name label. */
function mmRename() {
  var inp = document.getElementById('lg-mm-name');
  if (!inp) return;
  postMembers({ rename: inp.value }).then(function (res) {
    if (res && res._ok) { openThread(currentThreadUuid, currentPeers); refreshCounts(); }
    else alert('Could not rename the group.');
  });
}
/* Transfer ownership (owner or site admin): server 403s anyone else. */
function mmMakeOwner(uuid) {
  if (!confirm('Make this person the group owner?')) return;
  postMembers({ transfer: uuid }).then(function (res) {
    if (res && res._ok) { openMemberManager(); refreshCounts(); }
    else if (res && res._status === 403) alert('Only the current owner or a site admin can pass ownership.');
    else alert('Could not transfer ownership.');
  });
}
function mmAddConfirm(uuids) {
  postMembers({ add: uuids }).then(function (res) {
    if (res && res._ok) {
      /* adding to a 1:1 forks a NEW group (the DM is never converted) → open the new one */
      if (res.forked && res.thread_uuid) openThread(res.thread_uuid, null);
      else openMemberManager();
      refreshCounts();
    } else if (res && res._status === 403) {
      alert('You can only add people you’re connected to.');
    } else {
      alert('Could not add those people.');
    }
  });
}

/* ── edit / delete own message ── */
function beginEdit(bubble) {
  var id = bubble.getAttribute('data-lg-msg-id');
  if (!id || bubble.querySelector('.lg-msg__edit')) return;
  var raw    = bubble.getAttribute('data-lg-body') || '';
  var textEl = bubble.querySelector('.lg-msg__msg-text');
  var acts   = bubble.querySelector('.lg-msg__acts');
  if (!textEl) return;
  if (acts) acts.style.display = 'none';
  textEl.style.display = 'none';
  var ed = document.createElement('div');
  ed.className = 'lg-msg__edit';
  ed.innerHTML = '<textarea class="lg-msg__edit-input" rows="2"></textarea>'
    + '<div class="lg-msg__edit-row">'
      + '<button type="button" class="lg-msg__edit-cancel">Cancel</button>'
      + '<button type="button" class="lg-msg__edit-save">Save</button></div>';
  bubble.appendChild(ed);
  var ta = ed.querySelector('textarea');
  ta.value = raw; ta.focus(); ta.setSelectionRange(raw.length, raw.length);
  ed.querySelector('.lg-msg__edit-cancel').addEventListener('click', function () { cancelEdit(bubble); });
  ed.querySelector('.lg-msg__edit-save').addEventListener('click', function () { saveEdit(id, ta.value); });
  ta.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); saveEdit(id, ta.value); }
    else if (e.key === 'Escape') { e.preventDefault(); cancelEdit(bubble); }
  });
}
function cancelEdit(bubble) {
  var ed = bubble.querySelector('.lg-msg__edit'); if (ed) ed.remove();
  var textEl = bubble.querySelector('.lg-msg__msg-text'); if (textEl) textEl.style.display = '';
  var acts = bubble.querySelector('.lg-msg__acts'); if (acts) acts.style.display = '';
}
function saveEdit(id, val) {
  val = (val || '').trim();
  if (!val || !currentThreadUuid) return;
  fetch(API + '/me/messages/' + encodeURIComponent(currentThreadUuid) + '/entries/' + encodeURIComponent(id), {
    method: 'PATCH', credentials: 'include',
    headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ body: val }),
  }).then(function (r) {
    if (r.ok) openThread(currentThreadUuid, currentPeers);   /* re-render with "(edited)" */
    else alert('Could not edit the message.');
  }).catch(function () {});
}
function deleteMsg(id) {
  if (!currentThreadUuid || !confirm('Delete this message? This can’t be undone.')) return;
  fetch(API + '/me/messages/' + encodeURIComponent(currentThreadUuid) + '/entries/' + encodeURIComponent(id), {
    method: 'DELETE', credentials: 'include',
  }).then(function (r) {
    if (r.ok) openThread(currentThreadUuid, currentPeers);   /* re-render with tombstone */
    else alert('Could not delete the message.');
  }).catch(function () {});
}

/* ── image lightbox (scroll / ＋ − to zoom, drag to pan; Esc closes) ── */
var lightboxEl = null;
function openLightbox(url) {
  closeLightbox();
  var lb = document.createElement('div');
  lb.className = 'lg-msg-lightbox';
  lb.setAttribute('role', 'dialog');
  lb.setAttribute('aria-label', 'Photo');
  lb.innerHTML = '<div class="lg-msg-lightbox__bar">'
    + '<button type="button" class="lg-msg-lightbox__btn" data-lb-out aria-label="Zoom out">－</button>'
    + '<button type="button" class="lg-msg-lightbox__btn" data-lb-in aria-label="Zoom in">＋</button>'
    + '<button type="button" class="lg-msg-lightbox__btn" data-lb-close aria-label="Close">✕</button></div>'
    + '<img class="lg-msg-lightbox__img" src="' + esc(url) + '" alt="Photo" draggable="false">'
    + '<div class="lg-msg-lightbox__hint">Scroll or ＋ / － to zoom · Esc to close</div>';
  document.body.appendChild(lb);
  lightboxEl = lb;
  document.body.classList.add('lg-msg-lightbox-open');
  var img = lb.querySelector('.lg-msg-lightbox__img');
  var scale = 1, tx = 0, ty = 0;
  function apply() { img.style.transform = 'translate(' + tx + 'px,' + ty + 'px) scale(' + scale + ')'; img.style.cursor = scale > 1 ? 'grab' : 'zoom-in'; }
  function zoom(d) { scale = Math.min(5, Math.max(1, scale + d)); if (scale === 1) { tx = 0; ty = 0; } apply(); }
  lb.querySelector('[data-lb-in]').addEventListener('click',  function (e) { e.stopPropagation(); zoom(0.4); });
  lb.querySelector('[data-lb-out]').addEventListener('click', function (e) { e.stopPropagation(); zoom(-0.4); });
  lb.querySelector('[data-lb-close]').addEventListener('click', function (e) { e.stopPropagation(); closeLightbox(); });
  lb.addEventListener('click', function (e) { if (e.target === lb) closeLightbox(); });
  img.addEventListener('click', function (e) { e.stopPropagation(); if (scale === 1) zoom(0.9); });
  lb.addEventListener('wheel', function (e) { e.preventDefault(); zoom(e.deltaY < 0 ? 0.25 : -0.25); }, { passive: false });
  var drag = false, sx = 0, sy = 0;
  img.addEventListener('pointerdown', function (e) { if (scale <= 1) return; drag = true; sx = e.clientX - tx; sy = e.clientY - ty; try { img.setPointerCapture(e.pointerId); } catch (x) {} });
  img.addEventListener('pointermove', function (e) { if (!drag) return; tx = e.clientX - sx; ty = e.clientY - sy; apply(); });
  img.addEventListener('pointerup',   function () { drag = false; });
}
function closeLightbox() {
  if (!lightboxEl) return;
  lightboxEl.remove(); lightboxEl = null;
  document.body.classList.remove('lg-msg-lightbox-open');
}
/* Esc closes the lightbox FIRST (capture) so it does not also close the whole modal. */
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape' && lightboxEl) { e.stopImmediatePropagation(); closeLightbox(); }
}, true);

/* ── delegated wiring for all of the above ── */
document.addEventListener('click', function (e) {
  var t = e.target;
  var hit = function (sel) { return t.closest && t.closest(sel); };
  if (hit('[data-lg-new-msg]'))     { openComposePicker(); return; }
  if (hit('[data-lg-manage]'))      { openMemberManager(); return; }
  if (hit('[data-lg-msg-lightbox]'))    { openLightbox(hit('[data-lg-msg-lightbox]').getAttribute('data-lg-msg-lightbox')); return; }
  var pa = hit('[data-lg-pick-add]');    if (pa) { pickerAdd(pa.getAttribute('data-lg-pick-add')); return; }
  var px = hit('[data-lg-pick-remove]'); if (px) { pickerRemove(px.getAttribute('data-lg-pick-remove')); return; }
  if (hit('[data-lg-pick-go]'))     { pickerGo(); return; }
  if (hit('[data-lg-pick-cancel]')) { loadThreadList(); return; }
  var mr = hit('[data-lg-mm-remove]');   if (mr) { mmRemove(mr.getAttribute('data-lg-mm-remove')); return; }
  var mo = hit('[data-lg-mm-owner]');    if (mo) { mmMakeOwner(mo.getAttribute('data-lg-mm-owner')); return; }
  if (hit('[data-lg-mm-rename]'))   { mmRename(); return; }
  if (hit('[data-lg-mm-add]'))      { openAddPicker(); return; }
  if (hit('[data-lg-mm-leave]'))    { mmLeave(); return; }
  if (hit('[data-lg-edit]'))        { var be = hit('[data-lg-msg-id]'); if (be) beginEdit(be); return; }
  if (hit('[data-lg-del]'))         { var bd = hit('[data-lg-msg-id]'); if (bd) deleteMsg(bd.getAttribute('data-lg-msg-id')); return; }
});
document.addEventListener('input', function (e) {
  if (e.target && e.target.id === 'lg-pick-search') renderPickList(e.target.value);
});
document.addEventListener('keydown', function (e) {
  /* Enter on a highlighted picker row adds it */
  if (e.key === 'Enter' && e.target && e.target.classList && e.target.classList.contains('lg-msg__pi')) {
    e.preventDefault(); pickerAdd(e.target.getAttribute('data-lg-pick-add'));
  }
});

/* ── init ── */
refreshCounts();   /* one call now sets msg + notif + conn badges */
setInterval(refreshCounts, 60000);

})();
