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
 *          last_message_at,last_snippet,last_sender,peers:[{uuid,name,slug,avatar_url}]}]}
 *          (timestamps are Postgres "YYYY-MM-DD HH:MM:SS.ffffff+00" — see parseTs)
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
  var curPeers = [];                            // EVERY peer of the open thread (never peers[0])
  var curMeta = null;                           // {is_group,can_manage,created_by,members,meUuid} of the open thread
  var pendingGroup = null;                      // selected uuids for a not-yet-created group (first send → to_uuids[])
  var lpAt = 0;                                 // timestamp of the last long-press (suppresses the trailing tap)
  var threadsCache = [];
  var pendingFile = null;                       // staged image attachment for the next send
  var ATTACH_MAX = 5 * 1024 * 1024;
  var ATTACH_TYPES = { 'image/jpeg': 1, 'image/png': 1, 'image/webp': 1 };

  // The 8s poll re-renders the whole thread. It used to re-scroll to the bottom every
  // time, so scrolling up to read history got you yanked back down within 8s. The render
  // now follows the reader: pinned to the newest message, or parked in the history.
  var BOTTOM_EPS = 40;                          // px of slack that still counts as "at the bottom"
  var stickBottom = true;                       // is the reader pinned to the newest message?
  var lastMsgHtml = '';                         // last markup written into #mg-msgs

  // true when the conversation is the ROOT view of this messenger session — i.e. it was
  // opened straight from a profile / member card and no Chats list was ever shown.
  var chatIsRoot = false;

  function esc(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  /* Postgres hands us "2026-07-09 00:18:07.410418+00". That bare two-digit "+00" offset
     is not ISO-8601 — Date.parse only tolerates it while the date and time are still
     SPACE-separated, so replacing the space with "T" (as rel() used to) turned a
     parseable stamp into NaN. Normalise the offset instead of relying on the quirk. */
  function parseTs(ts) {
    var s = String(ts == null ? '' : ts).trim();
    if (!s) return NaN;
    s = s.replace(' ', 'T');
    if (s.indexOf('T') > 0) {
      if (/[+-]\d{2}$/.test(s)) s += ':00';                     // "+00" → "+00:00"
      else if (!/(Z|[+-]\d{2}:?\d{2})$/.test(s)) s += 'Z';      // offset-less: server time is UTC
    }
    return Date.parse(s);
  }
  function rel(ts) {
    var d = parseTs(ts);
    if (isNaN(d)) return '';
    var s = (Date.now() - d) / 1000;
    if (s < 60) return 'now';
    if (s < 3600) return Math.floor(s / 60) + 'm';
    if (s < 86400) return Math.floor(s / 3600) + 'h';
    if (s < 604800) return Math.floor(s / 86400) + 'd';
    return Math.floor(s / 604800) + 'w';
  }
  function atBottom(box) {
    return (box.scrollHeight - box.scrollTop - box.clientHeight) <= BOTTOM_EPS;
  }

  /* Day dividers. The old code sliced the raw UTC stamp, so it both LOOKED like a
     database row ("2026-07-08") and bucketed by UTC rather than by the reader's day —
     a message sent at 8pm in New York landed under tomorrow's heading. */
  function dayKey(ms) {
    var d = new Date(ms);
    return d.getFullYear() + '-' + (d.getMonth() + 1) + '-' + d.getDate();
  }
  function dayLabel(ms) {
    var d = new Date(ms), today = new Date();
    var yest = new Date(today.getFullYear(), today.getMonth(), today.getDate() - 1);
    if (dayKey(ms) === dayKey(today)) return 'Today';
    if (dayKey(ms) === dayKey(yest))  return 'Yesterday';
    var opts = { month: 'long', day: 'numeric' };
    if (d.getFullYear() !== today.getFullYear()) opts.year = 'numeric';
    try { return d.toLocaleDateString(undefined, opts); } catch (e) { return dayKey(ms); }
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
      '#looth-msgr .mg-chat{position:absolute;inset:0;z-index:1;display:none;flex-direction:column;background:var(--lg-cream,#fbfbf8);border-radius:18px 18px 0 0}',
      '#looth-msgr .mg-chat.is-on{display:flex}',
      '#looth-msgr .mg-chd{flex:0 0 auto;display:flex;align-items:center;gap:10px;padding:14px 12px 10px;border-bottom:1px solid var(--lg-line,#e3ddd0)}',
      '#looth-msgr .mg-backbtn{flex:0 0 auto;width:34px;height:34px;border:0;border-radius:50%;background:none;color:var(--lg-sage-d,#6b7c52);' +
        'font-size:21px;line-height:34px;text-align:center;cursor:pointer}',
      '#looth-msgr .mg-backbtn[hidden]{display:none}',   // defensive, per the .mg-attach-prev trap above
      '#looth-msgr .mg-chd .mg-avi{width:36px;height:36px;font-size:14px}',
      // The chat header is now a two-line block: WHO (every peer) + the group note.
      '#looth-msgr .mg-chname{flex:1 1 auto;min-width:0;display:flex;flex-direction:column;justify-content:center;gap:1px}',
      '#looth-msgr .mg-chnames{font:700 15.5px/1.25 var(--lg-font-sans,system-ui,sans-serif);color:var(--lg-charcoal,#1a1d1a);' +
        'display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;overflow-wrap:anywhere}',
      // "everyone here sees your reply" — a group must never read as a private chat, so this
      // line WRAPS rather than ellipsizing. A truncated privacy notice is not a privacy notice.
      '#looth-msgr .mg-chsub{font:11.5px/1.3 var(--lg-font-sans,system-ui,sans-serif);color:var(--lg-mute,#6b6f6b)}',
      // group avatar STACK — overlapping faces, never one arbitrary member's photo
      '#looth-msgr .mg-avi--stack{background:none}',
      // z-index:0 = own stacking context. Without it the .mg-stack-i children (z-index 1-3)
      // join the ANCESTOR context and the thread-list row's avatars paint straight THROUGH
      // the open chat panel, over the message bubbles.
      '#looth-msgr .mg-stack{position:relative;z-index:0;width:100%;height:100%;display:block}',
      '#looth-msgr .mg-stack-i{position:absolute;border-radius:50%;overflow:hidden;background:var(--lg-sage-3,#d4e0b8);' +
        'display:flex;align-items:center;justify-content:center;border:2px solid var(--lg-cream,#fbfbf8);' +
        'font:700 12px/1 var(--lg-font-serif,Georgia,serif);color:#fff}',
      '#looth-msgr .mg-stack-i img{width:100%;height:100%;object-fit:cover;display:block}',
      '#looth-msgr .mg-stack-i:nth-child(1){width:66%;height:66%;top:0;left:0;z-index:3}',
      '#looth-msgr .mg-stack-i:nth-child(2){width:66%;height:66%;bottom:0;right:0;z-index:2}',
      '#looth-msgr .mg-stack-i:nth-child(3){width:54%;height:54%;top:0;right:0;z-index:1;font-size:9px}',
      '#looth-msgr .mg-nameline{display:flex;align-items:center;gap:6px;min-width:0}',
      '#looth-msgr .mg-nameline .mg-name{flex:1 1 auto;min-width:0}',
      '#looth-msgr .mg-grouptag{flex:0 0 auto;display:inline-block;padding:1px 6px;border-radius:999px;' +
        'background:var(--lg-sage-tint,#eef2e3);color:var(--lg-sage-d,#6b7c52);' +
        'font:600 10.5px/1.5 var(--lg-font-sans,system-ui,sans-serif);vertical-align:middle}',
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
      D + ' #looth-msgr .mg-t,' + D + ' #looth-msgr .mg-name,' + D + ' #looth-msgr .mg-chnames{color:#f2f4ee}',
      D + ' #looth-msgr .mg-chsub{color:#9aa097}',
      D + ' #looth-msgr .mg-stack-i{border-color:#1b1e21}',
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
      D + ' #looth-msgr .mg-in{color:#e5e7e1}',

      // ── group management (lane: messages-manage) ──
      // compose button (Chats home) + members button (chat header)
      '#looth-msgr .mg-newbtn,#looth-msgr .mg-chmenu{flex:0 0 auto;width:34px;height:34px;border:0;border-radius:50%;' +
        'background:var(--lg-sage-tint,#eef2e3);color:var(--lg-sage-d,#6b7c52);font-size:19px;line-height:34px;text-align:center;cursor:pointer;padding:0}',
      // system (membership) line — centered pill, never a bubble
      '#looth-msgr .mg-sys{align-self:center;text-align:center;max-width:86%;font:600 11.5px/1.35 var(--lg-font-sans,system-ui);' +
        'color:var(--lg-mute,#6b6f6b);background:var(--lg-paper,#f3f1ea);border:1px solid var(--lg-line,#e3ddd0);border-radius:999px;padding:4px 12px;margin:3px 0}',
      // who-said-it label above a peer run in a group
      '#looth-msgr .mg-author{align-self:flex-start;font:600 11px/1 var(--lg-font-sans,system-ui);color:var(--lg-sage-d,#6b7c52);margin:4px 0 -1px 3px}',
      '#looth-msgr .mg-edited{font-size:10.5px;opacity:.72;margin-left:6px}',
      '#looth-msgr .mg-b--tomb{background:transparent;border:1px dashed var(--lg-line2,#d8d2c4);color:var(--lg-mute,#6b6f6b);font-style:italic}',
      // secondary panel (picker + member manager) slides over the chat/home
      '#looth-msgr .mg-p2{position:absolute;inset:0;z-index:2;display:none;flex-direction:column;background:var(--lg-cream,#fbfbf8);border-radius:18px 18px 0 0}',
      '#looth-msgr .mg-p2.is-on{display:flex}',
      '#looth-msgr .mg-p2hd{flex:0 0 auto;display:flex;align-items:center;gap:10px;padding:14px 12px 10px;border-bottom:1px solid var(--lg-line,#e3ddd0)}',
      '#looth-msgr .mg-p2t{flex:1;font:700 17px/1.2 var(--lg-font-serif,Georgia,serif);color:var(--lg-charcoal,#1a1d1a)}',
      '#looth-msgr .mg-p2body{flex:1 1 auto;overflow-y:auto;-webkit-overflow-scrolling:touch;padding:14px}',
      '#looth-msgr .mg-p2hint{font:13px/1.4 var(--lg-font-sans,system-ui);color:var(--lg-mute,#6b6f6b);margin:0 0 12px}',
      // pick field: chips + search
      '#looth-msgr .mg-pkfield{display:flex;flex-wrap:wrap;gap:6px;align-items:center;border:1px solid var(--lg-line,#e3ddd0);border-radius:12px;padding:8px 10px;background:#fff;min-height:46px}',
      '#looth-msgr .mg-chip{display:inline-flex;align-items:center;gap:6px;background:var(--lg-sage-tint,#eef2e3);color:var(--lg-sage-d,#6b7c52);border-radius:999px;padding:3px 6px 3px 3px;font:600 12.5px/1 var(--lg-font-sans,system-ui)}',
      '#looth-msgr .mg-chip-av{width:22px;height:22px;border-radius:50%;object-fit:cover;flex:0 0 22px;display:inline-flex;align-items:center;justify-content:center;background:var(--lg-sage,#87986a);color:#fff;font:700 10px/1 var(--lg-font-sans,system-ui);overflow:hidden}',
      '#looth-msgr .mg-chip-av img{width:100%;height:100%;object-fit:cover}',
      '#looth-msgr .mg-chip-x{border:0;background:rgba(107,124,82,.24);color:var(--lg-sage-d,#6b7c52);width:16px;height:16px;border-radius:50%;font:700 10px/1 system-ui;cursor:pointer;padding:0;display:inline-flex;align-items:center;justify-content:center}',
      '#looth-msgr .mg-pksearch{flex:1;min-width:90px;border:0;outline:0;background:none;font:14px/1.3 var(--lg-font-sans,system-ui);color:var(--lg-ink,#323532)}',
      '#looth-msgr .mg-pklist{margin-top:10px;border:1px solid var(--lg-line,#e3ddd0);border-radius:12px;overflow:hidden}',
      '#looth-msgr .mg-pi{display:flex;align-items:center;gap:11px;padding:10px 12px;cursor:pointer;background:none;border:0;width:100%;text-align:left}',
      '#looth-msgr .mg-pi + .mg-pi{border-top:1px solid var(--lg-line,#e3ddd0)}',
      '#looth-msgr .mg-pi:active{background:var(--lg-sage-tint,#eef2e3)}',
      '#looth-msgr .mg-pi .mg-avi{width:38px;height:38px;font-size:14px}',
      '#looth-msgr .mg-pi-col{flex:1;min-width:0}',
      '#looth-msgr .mg-pi-nm{font:600 14.5px/1.2 var(--lg-font-sans,system-ui);color:var(--lg-charcoal,#1a1d1a)}',
      '#looth-msgr .mg-pi-sub{font:12px/1.2 var(--lg-font-sans,system-ui);color:var(--lg-mute,#6b6f6b)}',
      '#looth-msgr .mg-pi-add{flex:0 0 auto;font:600 12.5px/1 var(--lg-font-sans,system-ui);color:var(--lg-sage-d,#6b7c52);border:1px solid var(--lg-sage-3,#d4e0b8);border-radius:999px;padding:6px 12px}',
      '#looth-msgr .mg-p2foot{flex:0 0 auto;display:flex;gap:10px;padding:12px 14px calc(12px + env(safe-area-inset-bottom,0px));border-top:1px solid var(--lg-line,#e3ddd0)}',
      // display:flex above beats the UA [hidden] rule (the trap this file documents) — counter it
      '#looth-msgr .mg-p2foot[hidden]{display:none}',
      '#looth-msgr .mg-gobtn{flex:1;border:0;border-radius:12px;background:var(--lg-sage,#87986a);color:#fff;font:600 15px/1 var(--lg-font-sans,system-ui);padding:13px;cursor:pointer}',
      '#looth-msgr .mg-gobtn:disabled{background:var(--lg-line,#e3ddd0);color:var(--lg-mute,#6b6f6b)}',
      // member manager list
      '#looth-msgr .mg-mmi{display:flex;align-items:center;gap:11px;padding:11px 4px;border-bottom:1px solid var(--lg-line,#e3ddd0)}',
      '#looth-msgr .mg-mmi .mg-avi{width:40px;height:40px;font-size:15px}',
      '#looth-msgr .mg-mmi-col{flex:1;min-width:0}',
      '#looth-msgr .mg-mmi-nm{font:600 14.5px/1.2 var(--lg-font-sans,system-ui);color:var(--lg-charcoal,#1a1d1a)}',
      '#looth-msgr .mg-mmi-sub{font:12px/1.2 var(--lg-font-sans,system-ui);color:var(--lg-mute,#6b6f6b)}',
      '#looth-msgr .mg-you{flex:0 0 auto;font:600 11px/1 var(--lg-font-sans,system-ui);color:var(--lg-sage-d,#6b7c52);background:var(--lg-sage-tint,#eef2e3);border-radius:999px;padding:4px 9px}',
      '#looth-msgr .mg-rm{flex:0 0 auto;border:1px solid #e7c4c0;background:none;color:var(--lg-error,#b3261e);border-radius:999px;font:600 12px/1 var(--lg-font-sans,system-ui);padding:6px 11px;cursor:pointer}',
      // custom group name + ownership (Ian 7/12 v1.1)
      '#looth-msgr .mg-mmi-actions{flex:0 0 auto;display:flex;align-items:center;gap:7px}',
      '#looth-msgr .mg-owner-chip{display:inline-block;margin-left:8px;vertical-align:1px;font:700 10px/1 var(--lg-font-sans,system-ui);letter-spacing:.04em;text-transform:uppercase;color:#8a6d1f;background:#f4ecd4;border-radius:999px;padding:3px 7px}',
      '#looth-msgr .mg-mkowner{flex:0 0 auto;border:1px solid var(--lg-sage-3,#d4e0b8);background:none;color:var(--lg-sage-d,#6b7c52);border-radius:999px;font:600 12px/1 var(--lg-font-sans,system-ui);padding:6px 11px;cursor:pointer}',
      '#looth-msgr .mg-mm-name{margin:0 0 14px}',
      '#looth-msgr .mg-mm-name-lbl{display:block;font:600 12px/1 var(--lg-font-sans,system-ui);color:var(--lg-mute,#6b6f6b);margin-bottom:6px}',
      '#looth-msgr .mg-mm-name-row{display:flex;gap:8px}',
      '#looth-msgr .mg-mm-name-in{flex:1;min-width:0;font:400 15px/1.3 var(--lg-font-sans,system-ui);color:var(--lg-charcoal,#1a1d1a);border:1px solid var(--lg-line,#e3ddd0);border-radius:10px;padding:10px 12px;background:#fff}',
      '#looth-msgr .mg-mm-name-save{flex:0 0 auto;border:0;background:var(--lg-sage-d,#6b7c52);color:#fff;border-radius:10px;font:600 14px/1 var(--lg-font-sans,system-ui);padding:0 16px;cursor:pointer}',
      '#looth-msgr .mg-mm-name-hint{display:block;font:400 11.5px/1.35 var(--lg-font-sans,system-ui);color:var(--lg-mute,#6b6f6b);margin:7px 2px 0}',
      '#looth-msgr .mg-addrow{width:100%;margin-top:14px;border:1px dashed var(--lg-sage-3,#d4e0b8);background:none;color:var(--lg-sage-d,#6b7c52);border-radius:12px;font:600 14px/1 var(--lg-font-sans,system-ui);padding:12px;cursor:pointer}',
      '#looth-msgr .mg-leavebtn{width:100%;margin-top:10px;border:1px solid #e7c4c0;background:none;color:var(--lg-error,#b3261e);border-radius:12px;font:600 14px/1 var(--lg-font-sans,system-ui);padding:12px;cursor:pointer}',
      // long-press action sheet (edit/delete/copy)
      '#looth-msgr .mg-acts{position:absolute;inset:0;z-index:5;display:none;align-items:flex-end;background:rgba(15,16,12,.42)}',
      '#looth-msgr .mg-acts.is-on{display:flex}',
      '#looth-msgr .mg-acts-in{width:100%;background:var(--lg-cream,#fbfbf8);border-radius:16px 16px 0 0;padding:8px 10px calc(10px + env(safe-area-inset-bottom,0px));box-shadow:0 -8px 26px rgba(0,0,0,.22)}',
      '#looth-msgr .mg-actbtn{width:100%;border:0;background:none;text-align:center;font:600 16px/1 var(--lg-font-sans,system-ui);color:var(--lg-ink,#323532);padding:15px;cursor:pointer;border-radius:12px}',
      '#looth-msgr .mg-actbtn:active{background:var(--lg-sage-tint,#eef2e3)}',
      '#looth-msgr .mg-actbtn--del{color:var(--lg-error,#b3261e)}',
      '#looth-msgr .mg-actbtn--cancel{color:var(--lg-mute,#6b6f6b);margin-top:4px;border-top:1px solid var(--lg-line,#e3ddd0);border-radius:0}',
      // dark overrides
      D + ' #looth-msgr .mg-p2,' + D + ' #looth-msgr .mg-acts-in{background:#1b1e21}',
      D + ' #looth-msgr .mg-p2hd,' + D + ' #looth-msgr .mg-mmi{border-color:#2c312d}',
      D + ' #looth-msgr .mg-p2t,' + D + ' #looth-msgr .mg-pi-nm,' + D + ' #looth-msgr .mg-mmi-nm,' + D + ' #looth-msgr .mg-actbtn{color:#f2f4ee}',
      D + ' #looth-msgr .mg-p2hint,' + D + ' #looth-msgr .mg-pi-sub,' + D + ' #looth-msgr .mg-mmi-sub{color:#9aa097}',
      D + ' #looth-msgr .mg-newbtn,' + D + ' #looth-msgr .mg-chmenu,' + D + ' #looth-msgr .mg-you{background:#262b30;color:#9cb37d}',
      D + ' #looth-msgr .mg-pkfield{background:#262b30;border-color:#2c312d}',
      D + ' #looth-msgr .mg-mm-name-in{background:#262b30;border-color:#2c312d;color:#f2f4ee}',
      D + ' #looth-msgr .mg-mm-name-lbl,' + D + ' #looth-msgr .mg-mm-name-hint{color:#9aa097}',
      D + ' #looth-msgr .mg-pi:active,' + D + ' #looth-msgr .mg-actbtn:active{background:#262b30}',

      // ── image lightbox (P4.5 — Ian scope-add 7/12). SAME /message-media/ URL, no new
      // exposure. Appended to <body> (outside #looth-msgr), so these rules are unscoped. ──
      '#looth-msgr .mg-img{position:relative;border:0;padding:0;background:none;cursor:zoom-in}',
      '#looth-msgr .mg-zoomdot{position:absolute;right:7px;bottom:7px;width:24px;height:24px;border-radius:50%;' +
        'background:rgba(0,0,0,.5);color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;pointer-events:none}',
      '.mg-lb{position:fixed;inset:0;z-index:2147483600;background:rgba(13,14,11,.95);display:flex;align-items:center;justify-content:center;overflow:hidden;touch-action:none}',
      '.mg-lb-img{max-width:100vw;max-height:100vh;display:block;user-select:none;-webkit-user-select:none;will-change:transform;touch-action:none;transition:transform .05s linear}',
      '.mg-lb-x{position:absolute;top:calc(12px + env(safe-area-inset-top,0px));right:14px;width:40px;height:40px;border-radius:50%;border:0;' +
        'background:rgba(255,255,255,.16);color:#fff;font:400 19px/1 system-ui;display:flex;align-items:center;justify-content:center;cursor:pointer;z-index:1}',
      '.mg-lb-hint{position:absolute;bottom:calc(16px + env(safe-area-inset-bottom,0px));left:0;right:0;text-align:center;' +
        'color:rgba(255,255,255,.7);font:600 11px/1.3 var(--lg-font-sans,system-ui);letter-spacing:.03em;padding:0 20px}'
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
        '<div class="mg-hd"><span class="mg-t">Chats</span>' +
          '<button class="mg-newbtn" type="button" data-mg-new aria-label="New message" title="New message">＋</button>' +
          '<button class="mg-x" type="button" data-mg-close aria-label="Close">✕</button></div>' +
        '<label class="mg-search"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.6" y2="16.6"/></svg>' +
          '<input type="search" placeholder="Search chats" autocomplete="off" aria-label="Search chats"></label>' +
        '<div class="mg-list" id="mg-list"><div class="mg-empty">Loading…</div></div>' +
        '<div class="mg-chat" id="mg-chat">' +
          '<div class="mg-chd"><button class="mg-backbtn" type="button" data-mg-home aria-label="Back to chats">‹</button>' +
            '<span class="mg-avi" id="mg-chavi"></span><span class="mg-chname" id="mg-chname"></span>' +
            '<button class="mg-chmenu" type="button" data-mg-members aria-label="Members" title="Members">⋯</button>' +
            '<button class="mg-x" type="button" data-mg-close aria-label="Close messages">✕</button></div>' +
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
        // secondary panel: compose picker + member manager (slides over home/chat)
        '<div class="mg-p2" id="mg-p2">' +
          '<div class="mg-p2hd"><button class="mg-backbtn" type="button" data-mg-p2back aria-label="Back">‹</button>' +
            '<span class="mg-p2t" id="mg-p2t">Members</span>' +
            '<button class="mg-x" type="button" data-mg-close aria-label="Close">✕</button></div>' +
          '<div class="mg-p2body" id="mg-p2body"></div>' +
          '<div class="mg-p2foot" id="mg-p2foot" hidden></div>' +
        '</div>' +
        // long-press action sheet (edit / delete / copy)
        '<div class="mg-acts" id="mg-acts"><div class="mg-acts-in" id="mg-acts-in"></div></div>' +
      '</div>';
    (document.body || document.documentElement).appendChild(sheet);
    sheet.addEventListener('click', function (e) {
      var C = function (sel) { return e.target.closest && e.target.closest(sel); };
      if (C('[data-mg-close]')) { closeMessenger(); return; }
      // Back to a list you never saw is disorienting. When the conversation IS the
      // root view (opened from a profile), dismiss the sheet and land back on the
      // page underneath instead (HK-018).
      if (C('[data-mg-home]'))   { chatIsRoot ? closeMessenger() : showHome(); return; }
      // ── group management ──
      if (e.target.id === 'mg-acts') { closeActs(); return; }
      // a long-press just fired the action sheet on this same image → swallow the tap
      if (lpAt && Date.now() - lpAt < 600) { lpAt = 0; return; }
      var lbx = C('[data-mg-lightbox]'); if (lbx) { openLightboxMobile(lbx.getAttribute('data-mg-lightbox')); return; }
      if (C('[data-mg-new]'))     { openPickerMobile('new'); return; }
      if (C('[data-mg-members]')) { openMemberManagerMobile(); return; }
      if (C('[data-mg-p2back]'))  { closeP2(); return; }
      var pa = C('[data-mg-pick-add]');    if (pa) { mpickAdd(pa.getAttribute('data-mg-pick-add')); return; }
      var px = C('[data-mg-pick-remove]'); if (px) { mpickRemove(px.getAttribute('data-mg-pick-remove')); return; }
      if (C('[data-mg-pick-go]'))  { mpickGo(); return; }
      var mr = C('[data-mg-mm-remove]');   if (mr) { mmRemoveMobile(mr.getAttribute('data-mg-mm-remove')); return; }
      var mo = C('[data-mg-mm-owner]');    if (mo) { mmMakeOwnerMobile(mo.getAttribute('data-mg-mm-owner')); return; }
      if (C('[data-mg-mm-rename]')) { mmRenameMobile(); return; }
      if (C('[data-mg-mm-add]'))   { openPickerMobile('add'); return; }
      if (C('[data-mg-mm-leave]')) { mmLeaveMobile(); return; }
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
    // follow the reader: any scroll (theirs or ours) re-decides whether we are pinned
    var msgBox = sheet.querySelector('#mg-msgs');
    msgBox.addEventListener('scroll', function () { stickBottom = atBottom(msgBox); }, { passive: true });
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

    // picker search (delegated — the input is re-rendered inside #mg-p2body)
    sheet.addEventListener('input', function (e) {
      if (e.target && e.target.id === 'mg-pksearch') mrenderPickList(e.target.value);
    });
    // long-press an own message bubble → the edit/delete/copy action sheet
    (function () {
      var box = sheet.querySelector('#mg-msgs');
      var t = null, target = null, sx = 0, sy = 0;
      var clear = function () { if (t) { clearTimeout(t); t = null; } };
      box.addEventListener('touchstart', function (e) {
        var b = e.target.closest && e.target.closest('[data-mg-msg-id]');
        if (!b) return;
        target = b; sx = e.touches[0].clientX; sy = e.touches[0].clientY;
        clear(); t = setTimeout(function () { t = null; lpAt = Date.now(); openActs(target); }, 480);
      }, { passive: true });
      box.addEventListener('touchmove', function (e) {
        if (t && (Math.abs(e.touches[0].clientX - sx) > 10 || Math.abs(e.touches[0].clientY - sy) > 10)) clear();
      }, { passive: true });
      box.addEventListener('touchend', clear);
      box.addEventListener('touchcancel', clear);
      // right-click / trackpad long-press fallback (also lets CDP verify without touch)
      box.addEventListener('contextmenu', function (e) {
        var b = e.target.closest && e.target.closest('[data-mg-msg-id]');
        if (b) { e.preventDefault(); openActs(b); }
      });
    })();
    return sheet;
  }

  function avi(p) {
    if (p && p.avatar_url) return '<img src="' + esc(p.avatar_url) + '" alt="">';
    var n = (p && p.name || '?').trim();
    return esc(n.split(/\s+/).map(function (w) { return w[0] || ''; }).join('').slice(0, 2).toUpperCase());
  }

  /* ── peers: 0, 1, or MANY ──
     A thread can have any number of peers — 0 (a thread whose counterpart recipient
     row is missing), 1 (a normal DM), or many (group threads: BuddyBoss-migrated ones
     exist today, group chats are the product direction). Both surfaces used to render
     peers[0] as "the" recipient, so a GROUP thread read as a private 1:1 and a reply
     reached people the header never named. peersByThread() now returns a deterministic
     order (server), and nothing below may fall back to peers[0]: the row and the chat
     header name EVERY peer, and say a reply goes to all of them. Desktop parity:
     lg-shared/social-modals.js peerLabel()/avatarStack(). */
  function peerLabel(peers, max) {
    var ps = peers || [];
    if (!ps.length) return 'Unknown member';    /* never inherit the last thread's name */
    var names = ps.map(function (p) { return p.name || 'Member'; });
    max = max || 2;
    if (names.length <= max) return names.join(', ');
    return names.slice(0, max).join(', ') + ' +' + (names.length - max);
  }
  function peerTotal(peers) { return ((peers || []).length) + 1; }   /* + you */
  /* inner HTML for a .mg-avi span; overlapping faces when it is a group */
  function aviStack(peers) {
    var ps = (peers || []).slice(0, 3);
    if (!ps.length) return '?';
    if (ps.length === 1) return avi(ps[0]);
    return '<span class="mg-stack">' + ps.map(function (p) {
      return '<span class="mg-stack-i">' + avi(p) + '</span>';
    }).join('') + '</span>';
  }
  /* The chat header + the composer placeholder are the two places a group can still be
     mistaken for a private chat, so both of them say it. */
  function setChatHeader(peers) {
    if (!sheet) return;
    var ps    = peers || [];
    var group = ps.length > 1;
    var av    = sheet.querySelector('#mg-chavi');
    var nm    = sheet.querySelector('#mg-chname');
    var ta    = sheet.querySelector('#mg-in');
    if (av) {
      av.className = 'mg-avi' + (group ? ' mg-avi--stack' : '');
      av.innerHTML = aviStack(ps);
    }
    if (nm) {
      /* <=3 peers: every name, wrapped over up to 3 lines. More than that and the
         header would swallow the screen, so the label says "A, B +N" — still true,
         never a silent clip that hides a participant. */
      /* A custom group name (subject) wins as the header title; the member names then drop to
         the subline (Ian 7/12). No subject → member names stay the title, as before. */
      var subject = curMeta && curMeta.subject;
      var namesLabel = esc(peerLabel(ps, ps.length <= 3 ? ps.length : 2));
      var groupNote = group ? 'Group · ' + peerTotal(ps) + ' people · everyone here sees your reply' : '';
      nm.innerHTML = subject
        ? '<span class="mg-chnames">' + esc(subject) + '</span>'
          + '<span class="mg-chsub">' + namesLabel + (groupNote ? ' · ' + groupNote : '') + '</span>'
        : '<span class="mg-chnames">' + namesLabel + '</span>' +
          (group ? '<span class="mg-chsub">' + groupNote + '</span>'
                 : (!ps.length ? '<span class="mg-chsub">This chat has no other members.</span>' : ''));
    }
    if (ta) ta.placeholder = group ? 'Message all ' + peerTotal(ps) + ' people…' : 'Message…';
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
      /* search EVERY peer — in a group thread the person you remember may not be first */
      var names = (t.peers || []).map(function (p) { return p.name || ''; }).join(' ');
      return (names + ' ' + (t.last_snippet || '')).toLowerCase().indexOf(ql) > -1;
    });
    if (!items.length) {
      list.innerHTML = '<div class="mg-empty">' + (ql ? 'No chats match.' : 'No messages yet. Find a member and tap Message to start a chat.') + '</div>';
      return;
    }
    list.innerHTML = items.map(function (t) {
      var ps = t.peers || [];
      var unread = (parseInt(t.unread_count, 10) || 0) > 0;
      var group = ps.length > 1;
      /* A custom group name (subject) wins over the member-name label; empty/absent → label. */
      var title = (t.subject && String(t.subject).length) ? String(t.subject) : peerLabel(ps, 2);
      return '<button type="button" class="mg-row' + (unread ? ' is-unread' : '') + '" data-mg-thread="' + esc(t.uuid) + '">' +
        '<span class="mg-avi' + (group ? ' mg-avi--stack' : '') + '">' + aviStack(ps) + '</span>' +
        '<span class="mg-col">' +
          '<span class="mg-nameline"><span class="mg-name">' + esc(title) + '</span>' +
          (group ? '<span class="mg-grouptag">Group · ' + peerTotal(ps) + '</span>' : '') + '</span>' +
        '<span class="mg-snip">' + esc(t.last_snippet || '') + '</span></span>' +
        '<span class="mg-meta"><span class="mg-time">' + rel(t.last_message_at) + '</span>' +
        (unread ? '<span class="mg-dot"></span>' : '') + '</span></button>';
    }).join('');
    [].forEach.call(list.querySelectorAll('[data-mg-thread]'), function (b) {
      b.addEventListener('click', function () {
        var t = threadsCache.filter(function (x) { return x.uuid === b.getAttribute('data-mg-thread'); })[0];
        chatIsRoot = false;                       // reached from the list — "back" returns to it
        openThread(b.getAttribute('data-mg-thread'), (t && t.peers) || []);   // ALL peers, not peers[0]
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
    curThread = null; curPeer = null; curPeers = [];
    curMeta = null; pendingGroup = null;
    lastMsgHtml = ''; stickBottom = true;
    chatIsRoot = false;
    closeP2(); closeActs();
    sheet.querySelector('#mg-chat').classList.remove('is-on');
    loadThreads();
  }

  /* A chevron pointing at a list you never opened is a dead control (and the same
     defect as the desktop thread-list back arrow). Show it only when it goes somewhere. */
  function syncChatChrome() {
    var back = sheet.querySelector('.mg-backbtn');
    if (back) back.hidden = chatIsRoot;
  }

  /* force = this render was asked for by the user (first open, or their own send), so it
     always lands at the newest message. Otherwise the 8s poll must not move them. */
  function renderMessages(msgs, peers, force, members) {
    var box = sheet.querySelector('#mg-msgs');
    var peerSet = {};
    (peers || []).forEach(function (p) { peerSet[p.uuid] = 1; });
    var group = (peers || []).length > 1;
    var nameBy = {};
    (members || []).forEach(function (m) { nameBy[m.uuid] = m.name || m.display_name || 'Member'; });
    var lastDay = '', lastSender = null;
    var html = (msgs || []).map(function (m) {
      var ms = parseTs(m.created_at);
      var unparseable = isNaN(ms);
      var day = unparseable ? String(m.created_at || '').slice(0, 10) : dayKey(ms);
      var h = '';
      if (day && day !== lastDay) {
        lastDay = day;
        h += '<div class="mg-day">' + esc(unparseable ? day : dayLabel(ms)) + '</div>';
      }
      // membership / transparency line — centered pill, never a bubble, never owned
      if (m.kind === 'system') { lastSender = null; return h + '<div class="mg-sys">' + esc(m.body) + '</div>'; }
      var mine = !peerSet[m.sender_uuid];                     // mine = sender not among peers
      // who-said-it label above a peer's run of messages in a GROUP (never for you)
      if (group && !mine && m.sender_uuid !== lastSender) {
        h += '<span class="mg-author">' + esc(nameBy[m.sender_uuid] || 'Member') + '</span>';
      }
      lastSender = m.sender_uuid;
      // soft-deleted → tombstone (body + media already withheld server-side)
      if (m.deleted) {
        return h + '<div class="mg-b mg-b--tomb ' + (mine ? 'mg-b--me' : 'mg-b--them') + '">Message deleted</div>';
      }
      // image attachment (access-controlled URL) → in-app lightbox with pinch-zoom.
      // An OWN caption-less image also carries the msg id so long-press can delete it
      // (a captioned image is deleted via its caption bubble instead).
      if (m.media_url) {
        h += '<button type="button" class="mg-img" style="align-self:' + (mine ? 'flex-end' : 'flex-start') +
             '" data-mg-lightbox="' + esc(m.media_url) + '"' +
             (mine && !m.body ? ' data-mg-msg-id="' + esc(m.id) + '"' : '') + '><img src="' + esc(m.media_url) +
             '" alt="Photo" loading="lazy"><span class="mg-zoomdot" aria-hidden="true">⤢</span></button>';
      }
      // body optional when an image is present (image-only message).
      // own text bubble carries id + raw body for the long-press edit/delete sheet.
      if (m.body) {
        h += '<div class="mg-b ' + (mine ? 'mg-b--me' : 'mg-b--them') + '"' +
             (mine ? ' data-mg-msg-id="' + esc(m.id) + '" data-mg-body="' + esc(m.body) + '"' : '') + '>' +
             esc(m.body) + (m.edited ? '<span class="mg-edited">(edited)</span>' : '') + '</div>';
      }
      return h;
    }).join('');

    // Nothing changed since the last render (the common case for a poll): leave the DOM
    // alone entirely. No reflow, no lost text selection, no scroll jump.
    if (!force && html === lastMsgHtml) return;

    var stick = force || stickBottom;
    var prevTop = box.scrollTop;
    lastMsgHtml = html;
    box.innerHTML = html;
    if (stick) { box.scrollTop = box.scrollHeight; stickBottom = true; }
    else       { box.scrollTop = prevTop; }      // messages only append, so prevTop holds the view

    // Images decode after innerHTML lands and grow the box under us. Hold the pin if we
    // had it; never take it back from a reader who has scrolled away.
    [].forEach.call(box.querySelectorAll('img'), function (im) {
      if (im.complete) return;
      im.addEventListener('load', function () {
        if (stickBottom) box.scrollTop = box.scrollHeight;
      }, { once: true });
    });
  }

  function loadThread(uuid, quiet) {
    fetch(API + '/me/messages/' + encodeURIComponent(uuid), { credentials: 'include' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        /* stale-response guard: this thread is no longer the open one (the user went
           back, or opened another). Writing now would paint THIS thread's messages and
           header over the one they are actually reading. */
        if (!d || curThread !== uuid) return;
        if (!quiet) {
          /* authoritative identity + management context for this thread */
          curPeers = d.peers || [];
          curMeta  = metaFrom(d);
          setChatHeader(curPeers);
        }
        renderMessages(d.messages || [], d.peers || [], !quiet, d.members || []);
      })
      .catch(function () {});
  }

  /* peers = the WHOLE peers[] carried from the row we came from (hint, so the header
     paints immediately); loadThread() then re-affirms it from the server. Handing this
     a single peer is what let a 4-person thread open under one person's name. */
  function openThread(uuid, peers) {
    ensureSheet();
    curThread = uuid; curPeer = null; curPeers = peers || [];
    curMeta = null; pendingGroup = null;
    closeP2(); closeActs();
    setChatHeader(curPeers);
    sheet.querySelector('#mg-msgs').innerHTML = '<div class="mg-empty">Loading…</div>';
    lastMsgHtml = ''; stickBottom = true;        // a freshly opened thread starts at the newest message
    sheet.querySelector('#mg-chat').classList.add('is-on');
    syncChatChrome();
    var ta = sheet.querySelector('#mg-in'); ta.value = ''; ta.style.height = 'auto';
    clearFile();
    sheet.querySelector('#mg-send').disabled = true;
    loadThread(uuid);
    if (pollT) clearInterval(pollT);
    pollT = setInterval(function () { if (curThread === uuid) loadThread(uuid, true); }, 8000);
  }

  /* Name the recipient of a not-yet-existing chat. GET /users?uuids= is the only
     place a lone uuid resolves to an identity; guarded on curPeer so a slow reply
     never relabels a thread the user has since navigated away from. */
  function fillPeerFromApi(userUuid) {
    fetch(API + '/users?uuids=' + encodeURIComponent(userUuid), { credentials: 'include' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        var u = d && d.items && d.items[0];
        if (!u || !curPeer || curPeer.uuid !== userUuid || curThread) return;
        curPeer = { uuid: u.uuid, name: u.display_name || 'Member', avatar_url: u.avatar_url || '' };
        curPeers = [curPeer];                      // a new DM is 1:1 by construction
        setChatHeader(curPeers);
      })
      .catch(function () {});
  }

  // chat with a USER (member card "Message") — resolve their uuid to a thread,
  // or open a fresh chat whose first send creates the thread.
  function openChatWith(userUuid, name, avatarUrl) {
    ensureSheet();
    openMessenger();
    chatIsRoot = true;                            // set AFTER openMessenger() → showHome() clears it
    fetch(API + '/me/messages/', { credentials: 'include' })
      .then(function (r) { return r.ok ? r.json() : { threads: [] }; })
      .then(function (d) {
        // ONLY a true 1:1 thread with this person. A GROUP thread that happens to contain
        // them is not "your chat with them" — reusing it would aim a private-intent DM at
        // everyone in the group. Server-side findPairThread() enforces the same rule on
        // the send path, so the UI and the delivery cannot diverge.
        var hit = ((d && d.threads) || []).filter(function (t) {
          var ps = t.peers || [];
          return ps.length === 1 && ps[0].uuid === userUuid;
        })[0];
        if (hit) { openThread(hit.uuid, hit.peers || []); return; }
        // no thread yet — fresh chat, first send POSTs {to_uuid}
        curThread = null; curPeer = { uuid: userUuid, name: name || 'Member', avatar_url: avatarUrl || '' };
        curPeers = [curPeer];
        clearFile();
        setChatHeader(curPeers);
        // lg:open-dm carries only a uuid, so a chat opened from a profile named the
        // recipient "Member" with a "?" avatar. No thread exists yet to carry peers[] —
        // resolve them, so you can see who you are about to message (HK-019, mobile half).
        if (!name || !avatarUrl) fillPeerFromApi(userUuid);
        sheet.querySelector('#mg-msgs').innerHTML = '<div class="mg-empty">Say hi — this starts your chat.</div>';
        lastMsgHtml = ''; stickBottom = true;
        sheet.querySelector('#mg-chat').classList.add('is-on');
    syncChatChrome();
        try { sheet.querySelector('#mg-in').focus({ preventScroll: true }); } catch (e) {}
      })
      .catch(function () {});
  }

  function doSend() {
    var ta = sheet.querySelector('#mg-in'), send = sheet.querySelector('#mg-send');
    var text = (ta.value || '').trim();
    if (!text && !pendingFile) return;                         // need text OR an image
    if (!curThread && !(curPeer && curPeer.uuid) && !pendingGroup) return;
    send.disabled = true;

    // A group is created by its FIRST message (POST to_uuids), which has no image variant —
    // so the first line of a new group must be text. Photos work once the group exists.
    if (pendingGroup && pendingFile) {
      setSendError('Send a message to start the group first — you can add photos once it exists.');
      send.disabled = false; return;
    }
    if (pendingFile) { sendWithFile(text, ta, send); return; }

    var url, body;
    if (curThread) { url = API + '/me/messages/' + encodeURIComponent(curThread); body = { body: text }; }
    else if (pendingGroup) { url = API + '/me/messages/'; body = { to_uuids: pendingGroup, body: text }; }
    else if (curPeer && curPeer.uuid) { url = API + '/me/messages/'; body = { to_uuid: curPeer.uuid, body: text }; }
    else { return; }
    // optimistic bubble
    var box = sheet.querySelector('#mg-msgs');
    if (box.querySelector('.mg-empty')) box.innerHTML = '';
    var b = document.createElement('div'); b.className = 'mg-b mg-b--me'; b.textContent = text;
    box.appendChild(b); box.scrollTop = box.scrollHeight; stickBottom = true;   // your own send always follows you down
    ta.value = ''; ta.style.height = 'auto';
    fetch(url, {
      method: 'POST', credentials: 'include',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body)
    })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }, function () { return { ok: r.ok, j: {} }; }); })
      .then(function (res) {
        if (!res.ok) { b.style.opacity = '.4'; b.textContent += ' (failed — tap Send to retry)'; ta.value = text; send.disabled = false; return; }
        // first message created the thread (DM or group) → adopt its uuid and start polling
        var newUuid = res.j && (res.j.thread_uuid || (res.j.thread && res.j.thread.uuid) || res.j.uuid);
        if (!curThread && newUuid) {
          curThread = newUuid; pendingGroup = null;
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
    box.scrollTop = box.scrollHeight; stickBottom = true;
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

  /* ══ group management (lane: messages-manage) — parity with desktop social-modals.js ══
     Every rule is ALSO enforced server-side; nothing here is the only guard. */

  // viewer = the member NOT among the peers (peers is everyone but you)
  function metaFrom(d) {
    var peers = (d && d.peers) || [], members = (d && d.members) || [];
    var pset = {}; peers.forEach(function (p) { pset[p.uuid] = 1; });
    var me = members.filter(function (m) { return !pset[m.uuid]; })[0];
    return {
      is_group: !!(d && d.is_group), can_manage: !!(d && d.can_manage),
      created_by: d && d.created_by, members: members, meUuid: me ? me.uuid : null,
      subject: (d && d.thread && d.thread.subject) || null,   // custom group name, or null
    };
  }

  // ── secondary panel (compose picker + member manager) ──
  function openP2()  { if (sheet) sheet.querySelector('#mg-p2').classList.add('is-on'); }
  function closeP2() {
    if (!sheet) return;
    var p2 = sheet.querySelector('#mg-p2'); if (p2) p2.classList.remove('is-on');
    var foot = sheet.querySelector('#mg-p2foot'); if (foot) { foot.hidden = true; foot.innerHTML = ''; }
  }
  function closeActs() { if (sheet) { var a = sheet.querySelector('#mg-acts'); if (a) a.classList.remove('is-on'); } }

  // ── long-press action sheet: edit / delete / copy ──
  function openActs(bubble) {
    var id  = bubble.getAttribute('data-mg-msg-id');
    var raw = bubble.getAttribute('data-mg-body') || '';
    var acts = sheet.querySelector('#mg-acts'), inn = sheet.querySelector('#mg-acts-in');
    var html = '';
    if (raw) html += '<button class="mg-actbtn" type="button" data-act="edit">Edit message</button>';
    html += '<button class="mg-actbtn mg-actbtn--del" type="button" data-act="delete">Delete message</button>';
    if (raw) html += '<button class="mg-actbtn" type="button" data-act="copy">Copy text</button>';
    html += '<button class="mg-actbtn mg-actbtn--cancel" type="button" data-act="cancel">Cancel</button>';
    inn.innerHTML = html;
    var ed = inn.querySelector('[data-act="edit"]');
    if (ed) ed.addEventListener('click', function () { closeActs(); editMobile(id, raw); });
    inn.querySelector('[data-act="delete"]').addEventListener('click', function () { closeActs(); deleteMobile(id); });
    var cp = inn.querySelector('[data-act="copy"]');
    if (cp) cp.addEventListener('click', function () { closeActs(); try { navigator.clipboard.writeText(raw); } catch (e) {} });
    inn.querySelector('[data-act="cancel"]').addEventListener('click', closeActs);
    acts.classList.add('is-on');
  }
  // edit uses the action-sheet container as an overlay editor → the 8s poll re-rendering
  // the messages underneath can't clobber an in-progress edit.
  function editMobile(id, raw) {
    var acts = sheet.querySelector('#mg-acts'), inn = sheet.querySelector('#mg-acts-in');
    inn.innerHTML = '<div style="padding:10px 10px 4px">'
      + '<textarea id="mg-editta" rows="3" style="width:100%;box-sizing:border-box;border:1px solid var(--lg-sage,#87986a);'
      + 'border-radius:12px;padding:10px;font:15px/1.4 var(--lg-font-sans,system-ui);max-height:170px;background:#fff;color:var(--lg-ink,#1a1d1a)"></textarea>'
      + '<div style="display:flex;gap:10px;margin-top:10px">'
      + '<button class="mg-leavebtn" type="button" id="mg-editcancel" style="margin-top:0;border-color:var(--lg-line,#e3ddd0);color:var(--lg-mute,#6b6f6b)">Cancel</button>'
      + '<button class="mg-gobtn" type="button" id="mg-editsave">Save</button></div></div>';
    var t2 = inn.querySelector('#mg-editta'); t2.value = raw;
    acts.classList.add('is-on');
    setTimeout(function () { try { t2.focus(); } catch (e) {} }, 60);
    inn.querySelector('#mg-editcancel').addEventListener('click', closeActs);
    inn.querySelector('#mg-editsave').addEventListener('click', function () {
      var v = (t2.value || '').trim(); if (!v || !curThread) return;
      fetch(API + '/me/messages/' + encodeURIComponent(curThread) + '/entries/' + encodeURIComponent(id), {
        method: 'PATCH', credentials: 'include',
        headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ body: v }),
      }).then(function (r) { closeActs(); if (r.ok) loadThread(curThread, true); else alert('Could not edit the message.'); })
        .catch(function () { closeActs(); });
    });
  }
  function deleteMobile(id) {
    if (!curThread || !confirm('Delete this message? This can’t be undone.')) return;
    fetch(API + '/me/messages/' + encodeURIComponent(curThread) + '/entries/' + encodeURIComponent(id), {
      method: 'DELETE', credentials: 'include',
    }).then(function (r) { if (r.ok) loadThread(curThread, true); else alert('Could not delete the message.'); })
      .catch(function () {});
  }

  // ── compose / add picker ──
  var mpickMode = 'new', mpickSel = [], mpickConns = [], mmMembersM = [];
  function mchipAv(u) {
    if (u.avatar_url) return '<span class="mg-chip-av"><img src="' + esc(u.avatar_url) + '" alt=""></span>';
    var n = (u.display_name || u.name || '?');
    return '<span class="mg-chip-av">' + esc(n.charAt(0).toUpperCase()) + '</span>';
  }
  function msearchVal() { var s = sheet.querySelector('#mg-pksearch'); return s ? s.value : ''; }
  function mrenderChips() {
    var box = sheet.querySelector('#mg-pkchips'); if (!box) return;
    box.innerHTML = mpickSel.map(function (u) {
      return '<span class="mg-chip">' + mchipAv(u) + esc(u.display_name || u.name || 'Member')
        + '<button type="button" class="mg-chip-x" data-mg-pick-remove="' + esc(u.uuid) + '" aria-label="Remove">✕</button></span>';
    }).join('');
  }
  function mrenderPickList(filter) {
    var list = sheet.querySelector('#mg-pklist'); if (!list) return;
    var f = (filter || '').trim().toLowerCase();
    var chosen = {}; mpickSel.forEach(function (u) { chosen[u.uuid] = 1; });
    var excl = {}; if (mpickMode === 'add') mmMembersM.forEach(function (uu) { excl[uu] = 1; });
    var rows = mpickConns.filter(function (u) {
      if (chosen[u.uuid] || excl[u.uuid]) return false;
      if (!f) return true;
      return String(u.display_name || '').toLowerCase().indexOf(f) > -1
          || String(u.slug || '').toLowerCase().indexOf(f) > -1;
    });
    if (!rows.length) {
      list.innerHTML = '<div class="mg-empty">' + (mpickConns.length ? 'No connections match.' : 'No connections yet.') + '</div>';
      return;
    }
    list.innerHTML = rows.map(function (u) {
      return '<button type="button" class="mg-pi" data-mg-pick-add="' + esc(u.uuid) + '">'
        + '<span class="mg-avi">' + avi({ avatar_url: u.avatar_url, name: u.display_name || u.name }) + '</span>'
        + '<span class="mg-pi-col"><span class="mg-pi-nm">' + esc(u.display_name || 'Member') + '</span>'
        + (u.slug ? '<span class="mg-pi-sub">@' + esc(u.slug) + '</span>' : '') + '</span>'
        + '<span class="mg-pi-add">Add</span></button>';
    }).join('');
  }
  function mupdateGo() {
    var go = sheet.querySelector('#mg-pickgo'); if (!go) return;
    var n = mpickSel.length; go.disabled = n < 1;
    go.textContent = mpickMode === 'add' ? 'Add' : (n >= 2 ? 'Start group' : 'Message');
  }
  function mloadConns() {
    fetch(API + '/me/connections/', { credentials: 'include' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) { mpickConns = (d && d.accepted) || []; mrenderPickList(msearchVal()); })
      .catch(function () { var l = sheet.querySelector('#mg-pklist'); if (l) l.innerHTML = '<div class="mg-empty">Couldn’t load connections.</div>'; });
  }
  function openPickerMobile(mode) {
    if (mode === 'add' && !curThread) return;
    ensureSheet();
    mpickMode = mode; mpickSel = [];
    sheet.querySelector('#mg-p2t').textContent = mode === 'add' ? 'Add people' : 'New message';
    sheet.querySelector('#mg-p2body').innerHTML =
      '<p class="mg-p2hint">' + (mode === 'add' ? 'You can only add people you’re connected to.' : 'Add two or more people to start a group.') + '</p>'
      + '<div class="mg-pkfield"><span id="mg-pkchips"></span>'
      + '<input id="mg-pksearch" class="mg-pksearch" placeholder="Search connections…" autocomplete="off"></div>'
      + '<div class="mg-pklist" id="mg-pklist"><div class="mg-empty">Loading…</div></div>';
    var foot = sheet.querySelector('#mg-p2foot');
    foot.hidden = false;
    foot.innerHTML = '<button class="mg-gobtn" type="button" id="mg-pickgo" data-mg-pick-go disabled>' + (mode === 'add' ? 'Add' : 'Start group') + '</button>';
    openP2(); mrenderChips(); mupdateGo(); mloadConns();
    var s = sheet.querySelector('#mg-pksearch'); if (s) setTimeout(function () { try { s.focus(); } catch (e) {} }, 80);
  }
  function mpickAdd(uuid) {
    var u = mpickConns.filter(function (c) { return c.uuid === uuid; })[0]; if (!u) return;
    mpickSel.push(u); mrenderChips(); mrenderPickList(msearchVal()); mupdateGo();
  }
  function mpickRemove(uuid) {
    mpickSel = mpickSel.filter(function (u) { return u.uuid !== uuid; });
    mrenderChips(); mrenderPickList(msearchVal()); mupdateGo();
  }
  function mpickGo() {
    if (!mpickSel.length) return;
    var uuids = mpickSel.map(function (u) { return u.uuid; });
    if (mpickMode === 'add') { mmAddConfirmMobile(uuids); return; }
    if (uuids.length === 1) {
      var u = mpickSel[0]; closeP2();
      openChatWith(u.uuid, u.display_name, u.avatar_url); chatIsRoot = false;   // came from the list
      return;
    }
    enterGroupComposeMobile(mpickSel.slice());
  }
  // ≥2 selected → a not-yet-created group. Hold the uuids; the first text message
  // POSTs {to_uuids} and creates the thread (see doSend). Mirrors the new-DM compose.
  function enterGroupComposeMobile(sel) {
    closeP2();
    curThread = null; curPeer = null; curMeta = null;
    pendingGroup = sel.map(function (u) { return u.uuid; });
    curPeers = sel.map(function (u) { return { uuid: u.uuid, name: u.display_name || u.name, slug: u.slug, avatar_url: u.avatar_url }; });
    chatIsRoot = false;
    clearFile();
    setChatHeader(curPeers);
    sheet.querySelector('#mg-msgs').innerHTML = '<div class="mg-empty">Say hi — this starts your group.</div>';
    lastMsgHtml = ''; stickBottom = true;
    sheet.querySelector('#mg-chat').classList.add('is-on');
    syncChatChrome();
    var ta = sheet.querySelector('#mg-in'); ta.value = ''; ta.style.height = 'auto';
    sheet.querySelector('#mg-send').disabled = true;
    try { ta.focus({ preventScroll: true }); } catch (e) {}
  }

  // ── member manager ──
  function openMemberManagerMobile() {
    if (!curThread) return;
    ensureSheet();
    sheet.querySelector('#mg-p2t').textContent = 'Members';
    sheet.querySelector('#mg-p2body').innerHTML = '<div class="mg-empty">Loading…</div>';
    sheet.querySelector('#mg-p2foot').hidden = true;
    openP2();
    var tu = curThread;
    fetch(API + '/me/messages/' + encodeURIComponent(tu), { credentials: 'include' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) { if (d && curThread === tu) renderMemberManagerMobile(d); })
      .catch(function () { var b = sheet.querySelector('#mg-p2body'); if (b) b.innerHTML = '<div class="mg-empty">Couldn’t load members.</div>'; });
  }
  function renderMemberManagerMobile(d) {
    var peers = (d && d.peers) || [], members = (d && d.members) || [];
    var canManage = !!(d && d.can_manage), isGroup = !!(d && d.is_group), createdBy = d && d.created_by;
    var pset = {}; peers.forEach(function (p) { pset[p.uuid] = 1; });
    var me = members.filter(function (m) { return !pset[m.uuid]; })[0];
    var meUuid = me ? me.uuid : null;
    mmMembersM = members.map(function (m) { return m.uuid; });
    var hint = canManage
      ? 'You can remove anyone in this ' + (isGroup ? 'group' : 'conversation') + '.'
      : (isGroup ? 'Only the group’s owner or a site admin can remove others. You can always leave.'
                 : 'Add people to start a group — this private chat stays as it is.');
    var rows = members.map(function (m) {
      var isMe = m.uuid === meUuid, isOwner = createdBy && m.uuid === createdBy;   // created_by = current owner (mutable)
      var sub = isMe ? 'You' : (m.slug ? '@' + m.slug : '');
      /* Owner chip on the owner's row — all members see it; only when an owner is recorded. */
      var chip = isOwner ? '<span class="mg-owner-chip">Owner</span>' : '';
      var right;
      if (isMe) { right = '<span class="mg-you">You</span>'; }
      else {
        right = '';
        /* Transfer: owner OR site admin (canManage) hands ownership to a NON-owner member. */
        if (canManage && isGroup && !isOwner) right += '<button type="button" class="mg-mkowner" data-mg-mm-owner="' + esc(m.uuid) + '">Make owner</button>';
        if (canManage) right += '<button type="button" class="mg-rm" data-mg-mm-remove="' + esc(m.uuid) + '">Remove</button>';
      }
      return '<div class="mg-mmi"><span class="mg-avi">' + avi({ avatar_url: m.avatar_url, name: m.name || m.display_name }) + '</span>'
        + '<span class="mg-mmi-col"><span class="mg-mmi-nm">' + esc(m.name || m.display_name || 'Member') + chip + '</span>'
        + '<span class="mg-mmi-sub">' + esc(sub) + '</span></span>'
        + '<span class="mg-mmi-actions">' + right + '</span></div>';
    }).join('');
    /* Group-name field — ANY member may set/clear it (groups only); esc() keeps the value inert. */
    var subject = (d && d.thread && d.thread.subject) || '';
    var nameField = isGroup
      ? '<div class="mg-mm-name">'
        + '<label class="mg-mm-name-lbl" for="mg-mm-name-in">Group name</label>'
        + '<div class="mg-mm-name-row">'
        + '<input type="text" id="mg-mm-name-in" class="mg-mm-name-in" maxlength="60" '
        +   'placeholder="Add a name (optional)" value="' + esc(subject) + '">'
        + '<button type="button" class="mg-mm-name-save" data-mg-mm-rename>Save</button>'
        + '</div>'
        + '<div class="mg-mm-name-hint">Anyone here can rename the group. Clear the box to remove the name.</div>'
        + '</div>'
      : '';
    sheet.querySelector('#mg-p2t').textContent = 'Members · ' + members.length;
    sheet.querySelector('#mg-p2body').innerHTML = '<p class="mg-p2hint">' + esc(hint) + '</p>' + nameField + rows
      + '<button type="button" class="mg-addrow" data-mg-mm-add>＋ Add people</button>'
      + '<button type="button" class="mg-leavebtn" data-mg-mm-leave>' + (isGroup ? 'Leave group' : 'Leave') + '</button>';
    sheet.querySelector('#mg-p2foot').hidden = true;
  }
  function mpostMembers(body) {
    if (!curThread) return Promise.resolve(null);
    return fetch(API + '/me/messages/' + encodeURIComponent(curThread) + '/members', {
      method: 'POST', credentials: 'include',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
    }).then(function (r) {
      return r.json().then(
        function (j) { j = j || {}; j._status = r.status; j._ok = r.ok && j.ok !== false; return j; },
        function ()  { return { _status: r.status, _ok: r.ok }; }
      );
    }).catch(function () { return null; });
  }
  function mmRemoveMobile(uuid) {
    if (!confirm('Remove this person from the group?')) return;
    mpostMembers({ remove: uuid }).then(function (res) {
      if (res && res._ok) openMemberManagerMobile();
      else if (res && res._status === 403) alert('Only the group’s owner or a site admin can remove members.');
      else alert('Could not remove that member.');
    });
  }
  /* Rename (any member): re-open the thread so the new title + the "named the group" system
     line both land live; an empty box clears the name and reverts to the member-name label. */
  function mmRenameMobile() {
    var inp = sheet && sheet.querySelector('#mg-mm-name-in');
    if (!inp) return;
    mpostMembers({ rename: inp.value }).then(function (res) {
      if (res && res._ok) { closeP2(); openThread(curThread, curPeers); }
      else alert('Could not rename the group.');
    });
  }
  /* Transfer ownership (owner or site admin): server 403s anyone else. */
  function mmMakeOwnerMobile(uuid) {
    if (!confirm('Make this person the group owner?')) return;
    mpostMembers({ transfer: uuid }).then(function (res) {
      if (res && res._ok) openMemberManagerMobile();
      else if (res && res._status === 403) alert('Only the current owner or a site admin can pass ownership.');
      else alert('Could not transfer ownership.');
    });
  }
  function mmLeaveMobile() {
    if (!confirm('Leave this conversation? You’ll lose access to it.')) return;
    mpostMembers({ leave: true }).then(function (res) {
      if (res && res._ok) { closeP2(); showHome(); }
      else alert('Could not leave the conversation.');
    });
  }
  function mmAddConfirmMobile(uuids) {
    mpostMembers({ add: uuids }).then(function (res) {
      if (res && res._ok) {
        /* adding to a 1:1 forks a NEW group (the DM is never converted) → open the new one */
        if (res.forked && res.thread_uuid) { closeP2(); chatIsRoot = false; openThread(res.thread_uuid, null); }
        else openMemberManagerMobile();
      } else if (res && res._status === 403) { alert('You can only add people you’re connected to.'); }
      else alert('Could not add those people.');
    });
  }

  // ── image lightbox (pinch-zoom on touch; double-tap / ✕ / backdrop; Esc closes) ──
  var mgLightbox = null;
  function closeLightboxMobile() {
    if (mgLightbox) { mgLightbox.remove(); mgLightbox = null; }
  }
  function openLightboxMobile(url) {
    closeLightboxMobile();
    var lb = document.createElement('div');
    lb.className = 'mg-lb';
    lb.setAttribute('role', 'dialog'); lb.setAttribute('aria-label', 'Photo');
    lb.innerHTML = '<button type="button" class="mg-lb-x" aria-label="Close">✕</button>'
      + '<img class="mg-lb-img" src="' + esc(url) + '" alt="Photo" draggable="false">'
      + '<div class="mg-lb-hint">Pinch to zoom · double-tap to reset · tap outside to close</div>';
    (document.body || document.documentElement).appendChild(lb);
    mgLightbox = lb;
    var img = lb.querySelector('.mg-lb-img');
    var scale = 1, tx = 0, ty = 0, startDist = 0, startScale = 1, psx = 0, psy = 0, mode = '', lastTap = 0;
    function apply() { img.style.transform = 'translate(' + tx + 'px,' + ty + 'px) scale(' + scale + ')'; }
    function dist(t) { return Math.hypot(t[0].clientX - t[1].clientX, t[0].clientY - t[1].clientY); }
    function reset() { scale = 1; tx = 0; ty = 0; apply(); }
    img.addEventListener('touchstart', function (e) {
      if (e.touches.length === 2) { mode = 'pinch'; startDist = dist(e.touches); startScale = scale; }
      else if (e.touches.length === 1 && scale > 1) { mode = 'pan'; psx = e.touches[0].clientX - tx; psy = e.touches[0].clientY - ty; }
      else { mode = ''; }
    }, { passive: true });
    img.addEventListener('touchmove', function (e) {
      if (mode === 'pinch' && e.touches.length === 2) {
        if (e.cancelable) e.preventDefault();
        scale = Math.min(5, Math.max(1, startScale * dist(e.touches) / (startDist || 1)));
        if (scale === 1) { tx = 0; ty = 0; } apply();
      } else if (mode === 'pan' && e.touches.length === 1) {
        if (e.cancelable) e.preventDefault();
        tx = e.touches[0].clientX - psx; ty = e.touches[0].clientY - psy; apply();
      }
    }, { passive: false });
    img.addEventListener('touchend', function () {
      var now = Date.now();
      if (now - lastTap < 300) { scale > 1 ? reset() : (scale = 2.5, apply()); lastTap = 0; }
      else lastTap = now;
    });
    // non-touch (CDP / trackpad) affordances: double-click toggles, wheel zooms
    img.addEventListener('dblclick', function (e) { e.preventDefault(); scale > 1 ? reset() : (scale = 2.5, apply()); });
    lb.addEventListener('wheel', function (e) { e.preventDefault(); scale = Math.min(5, Math.max(1, scale + (e.deltaY < 0 ? 0.25 : -0.25))); if (scale === 1) { tx = 0; ty = 0; } apply(); }, { passive: false });
    lb.querySelector('.mg-lb-x').addEventListener('click', function (e) { e.stopPropagation(); closeLightboxMobile(); });
    lb.addEventListener('click', function (e) { if (e.target === lb) closeLightboxMobile(); });
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
    // an action sheet or the compose/member panel is a sub-screen — back dismisses it first
    var acts = sheet.querySelector('#mg-acts'), p2 = sheet.querySelector('#mg-p2');
    if (acts && acts.classList.contains('is-on')) { closeActs(); try { history.pushState({ lgMg: 1 }, ''); } catch (e) {} return; }
    if (p2 && p2.classList.contains('is-on'))     { closeP2();   try { history.pushState({ lgMg: 1 }, ''); } catch (e) {} return; }
    var chat = sheet.querySelector('#mg-chat');
    // a chat opened straight from a profile has no list behind it — dismiss, don't invent one
    if (chat && chat.classList.contains('is-on') && !chatIsRoot) {   // back from a chat → home
      showHome();
      try { history.pushState({ lgMg: 1 }, ''); } catch (e) {}
      return;
    }
    closeMessenger(true);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (mgLightbox) { e.stopImmediatePropagation(); closeLightboxMobile(); return; }
    if (!sheet || !sheet.classList.contains('is-open')) return;
    var acts = sheet.querySelector('#mg-acts'), p2 = sheet.querySelector('#mg-p2');
    if (acts && acts.classList.contains('is-on')) { closeActs(); return; }
    if (p2 && p2.classList.contains('is-on'))     { closeP2(); return; }
    closeMessenger();
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
