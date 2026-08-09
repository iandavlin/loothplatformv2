/* lg-social-actions.js — behaviour for the on-profile social widget.
 *
 * ONE COPY OF THIS CODE, LOADED BY BOTH ENTRY PATHS. That is the entire point of
 * the file, so read this before "tidying" it back into the page.
 *
 * profile-app/src/Social.php server-renders the widget (Connect / Message /
 * Accept / Decline / Cancel / the "..." Mute-Unmute-Remove menu). Its behaviour
 * used to ship as an inline <script> inside that markup, which works on the full
 * /u/ page and fails everywhere the markup is MOVED:
 *
 *   webroot/profile-sheet.js fetches /u/<slug>?view=member, lifts .lg-profile out
 *   with DOMParser and injects it into whatever page you were on. DOMParser scripts
 *   are inert, and profile-sheet.js strips <script> anyway — so the mobile profile
 *   tray got all seven controls and none of their behaviour. Ian, 8/8: DMs from the
 *   profile tray, and the 3-dots menu, both dead. Backlog 4.4 and 4.3, one cause.
 *
 * Because the handler is a DELEGATED document listener, loading this file ONCE on
 * the host page fixes every copy of the widget on it, including one injected later.
 * window.__lgSocialWired makes a second load a no-op, so the full page and the tray
 * can both ask for it without coordinating.
 *
 * Kept byte-identical to the inline fallback still in Social.php while the flag
 * lives (platform/config/social-actions.php). Gate 19 compares the two and turns
 * RED if they drift, which is the only thing making two copies safe.
 */
(function () {
  if (window.__lgSocialWired) return; window.__lgSocialWired = true;
  var API = '/profile-api/v0';
  function post(url, body, method) {
    return fetch(url, {
      method: method || 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: body ? JSON.stringify(body) : null
    });
  }
  function closeMenus() {
    Array.prototype.forEach.call(document.querySelectorAll('.lg-social-menu:not([hidden])'), function (m) { m.hidden = true; });
    Array.prototype.forEach.call(document.querySelectorAll('.lg-social-morebtn[aria-expanded="true"]'), function (b) { b.setAttribute('aria-expanded', 'false'); });
  }
  // Remove connection DELETES the edge with no undo, so it must be confirmed.
  // Cancel is the safe default: it is auto-focused, Esc and backdrop-click cancel,
  // and Enter (on the focused Cancel) cancels — nothing removes without an explicit
  // click on the danger button.
  var confirmEl = null;
  function hideConfirm() { if (confirmEl) confirmEl.hidden = true; }
  function confirmDisconnect(cid) {
    if (!confirmEl) {
      confirmEl = document.createElement('div');
      confirmEl.className = 'lg-social-confirm';
      confirmEl.hidden = true;
      confirmEl.setAttribute('role', 'dialog');
      confirmEl.setAttribute('aria-modal', 'true');
      confirmEl.setAttribute('aria-labelledby', 'lg-social-confirm-title');
      confirmEl.innerHTML =
        '<div class="lg-social-confirm__backdrop" data-confirm-cancel></div>' +
        '<div class="lg-social-confirm__box">' +
          '<h2 class="lg-social-confirm__title" id="lg-social-confirm-title">Remove connection?</h2>' +
          '<p class="lg-social-confirm__body">This can\'t be undone. Reconnecting means sending a new request they\'ll have to accept again.</p>' +
          '<div class="lg-social-confirm__actions">' +
            '<button type="button" class="lg-social-confirm__btn lg-social-confirm__btn--cancel" data-confirm-cancel>Cancel</button>' +
            '<button type="button" class="lg-social-confirm__btn lg-social-confirm__btn--danger" data-confirm-ok>Remove connection</button>' +
          '</div>' +
        '</div>';
      document.body.appendChild(confirmEl);
      confirmEl.addEventListener('click', function (e) {
        if (e.target.closest('[data-confirm-cancel]')) { hideConfirm(); return; }
        var ok = e.target.closest('[data-confirm-ok]');
        if (ok) {
          ok.disabled = true;
          post(API + '/connections/' + confirmEl.__cid, { action: 'disconnect' }, 'PATCH')
            .then(function () { location.reload(); })
            .catch(function () { ok.disabled = false; hideConfirm(); });
        }
      });
    }
    confirmEl.__cid = cid;
    var ok = confirmEl.querySelector('[data-confirm-ok]');
    if (ok) ok.disabled = false;
    confirmEl.hidden = false;
    var cancel = confirmEl.querySelector('.lg-social-confirm__btn--cancel');
    if (cancel) cancel.focus();
  }
  document.addEventListener('click', function (e) {
    // 3-dot toggle
    var more = e.target.closest('.lg-social-morebtn');
    if (more) {
      var menu = more.parentNode.querySelector('.lg-social-menu');
      var willOpen = menu && menu.hidden;
      closeMenus();
      if (menu && willOpen) { menu.hidden = false; more.setAttribute('aria-expanded', 'true'); }
      return;
    }
    var b = e.target.closest('[data-lg-social]');
    if (!b) { closeMenus(); return; }
    var act = b.getAttribute('data-lg-social');
    var cid = b.getAttribute('data-cid');
    var to  = b.getAttribute('data-to-uuid');

    if (act === 'message') {
      document.dispatchEvent(new CustomEvent('lg:open-dm', { detail: { uuid: to } }));
      closeMenus();
      return;
    }
    if (b.getAttribute('data-requires-auth')) {
      document.dispatchEvent(new CustomEvent('lg:require-auth', { detail: { reason: 'connect' } }));
      return;
    }
    // Remove connection: confirm before deleting the edge (no undo). The actual
    // disconnect fetch fires from the dialog's danger button, never from here.
    if (act === 'disconnect') { closeMenus(); confirmDisconnect(cid); return; }

    var p;
    if (act === 'connect')          { b.disabled = true; p = post(API + '/connections', { addressee_uuid: to }); }
    else if (act === 'accept')      { b.disabled = true; p = post(API + '/connections/' + cid, { action: 'accept' }, 'PATCH'); }
    else if (act === 'decline')     { b.disabled = true; p = post(API + '/connections/' + cid, { action: 'decline' }, 'PATCH'); }
    else if (act === 'cancel')      { b.disabled = true; p = post(API + '/connections/' + cid, { action: 'cancel' }, 'PATCH'); }
    else if (act === 'mute')        { p = post(API + '/me/mutes', { uuid: to }); }
    else if (act === 'unmute')      { p = post(API + '/me/mutes/' + encodeURIComponent(to), null, 'DELETE'); }
    else { return; }
    p.then(function () { location.reload(); }).catch(function () { b.disabled = false; });
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeMenus(); hideConfirm(); } });
})();
