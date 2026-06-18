<?php
declare(strict_types=1);

namespace Looth\ProfileApp;

require_once __DIR__ . '/Connections.php';
require_once __DIR__ . '/Mutes.php';

/**
 * Social — the on-profile Connect / Message / more-menu widget for /u/ (and /p/).
 *
 * This is the ONE SLOT profile-2.0 consumes: it drops
 *   echo Social::renderProfileActions($viewer['uuid'] ?? null, $row['uuid']);
 * into the /u/ header card. The widget is purely SERVER-RENDERED off social state
 * (the "dumb host" pattern) — profile-2.0 owns the page, this lane owns the buttons.
 *
 * Actions are progressive: buttons carry data-* attributes and a one-time inline
 * script fetches the connection/mute endpoints and reloads. NO SPA. The Message
 * button dispatches a `lg:open-dm` DOM event (detail.uuid) that the shared DM modal
 * hooks; if nothing listens it is a harmless no-op.
 *
 * The 3-dot ("more") menu shows for any logged-in non-owner viewer: Mute / Unmute
 * (one-directional, silent — see Mutes), plus Remove connection when accepted.
 *
 * Header-ceiling note: the buttons live INSIDE the header block, so the page's
 * effective-visibility gate already controls whether this widget renders at all.
 */
final class Social
{
    /** Returns the actions HTML (may be ''), self-contained incl. its one-time CSS+JS. */
    public static function renderProfileActions(?string $viewerUuid, string $profileUuid): string
    {
        // Own page → no buttons. Logged-out → an auth-gated Connect CTA.
        if ($viewerUuid !== null && $viewerUuid === $profileUuid) return '';

        if ($viewerUuid === null) {
            return self::wrap(
                self::btn('Connect', ['data-lg-social' => 'connect', 'data-requires-auth' => '1']),
                ''
            );
        }

        $edge   = Connections::stateWithId($viewerUuid, $profileUuid);
        $state  = $edge['state'];
        $cid    = $edge['id'];
        $target = htmlspecialchars($profileUuid, ENT_QUOTES);

        // Blocked → show nothing at all.
        if ($state === 'blocked') return '';

        switch ($state) {
            case 'accepted':
                $html = self::btn('Connected', ['disabled' => '1', 'data-lg-social' => 'connected'])
                      . self::btn('Message', ['data-lg-social' => 'message', 'data-to-uuid' => $target]);
                break;

            case 'pending_out':
                $html = self::btn('Requested', ['disabled' => '1', 'data-lg-social' => 'requested'])
                      . self::btn('Cancel', ['data-lg-social' => 'cancel', 'data-cid' => (string)$cid]);
                break;

            case 'pending_in':
                $html = self::btn('Accept', ['data-lg-social' => 'accept', 'data-cid' => (string)$cid])
                      . self::btn('Decline', ['data-lg-social' => 'decline', 'data-cid' => (string)$cid]);
                break;

            case 'none':
            default:
                $html = self::btn('Connect', ['data-lg-social' => 'connect', 'data-to-uuid' => $target]);
                break;
        }

        return self::wrap($html, self::moreMenu($viewerUuid, $profileUuid, $target, $state, $cid));
    }

    /**
     * The 3-dot menu (logged-in non-owner): Mute/Unmute always; Remove connection if
     * accepted. Mute is independent of connection status — you can mute anyone.
     */
    private static function moreMenu(string $viewerUuid, string $profileUuid, string $target, string $state, $cid): string
    {
        $items = Mutes::isMuted($viewerUuid, $profileUuid)
            ? '<button type="button" role="menuitem" class="lg-social-menu__item" data-lg-social="unmute" data-to-uuid="' . $target . '">Unmute</button>'
            : '<button type="button" role="menuitem" class="lg-social-menu__item" data-lg-social="mute" data-to-uuid="' . $target . '">Mute</button>';

        if ($state === 'accepted' && $cid) {
            $items .= '<button type="button" role="menuitem" class="lg-social-menu__item lg-social-menu__item--danger"'
                    . ' data-lg-social="disconnect" data-cid="' . htmlspecialchars((string)$cid, ENT_QUOTES) . '">Remove connection</button>';
        }

        if ($items === '') return '';
        return '<div class="lg-social-more" data-lg-more>'
             . '<button type="button" class="lg-social-morebtn" aria-haspopup="true" aria-expanded="false" aria-label="More options">'
             . '<span aria-hidden="true">&#8943;</span></button>'
             . '<div class="lg-social-menu" role="menu" hidden>' . $items . '</div>'
             . '</div>';
    }

    private static function wrap(string $inner, string $more = ''): string
    {
        return self::styles()
             . '<div class="lg-social-actions" data-lg-social-actions>' . $inner . $more . '</div>'
             . self::script();
    }

    private static function btn(string $label, array $attrs): string
    {
        $a = '';
        foreach ($attrs as $k => $v) {
            if ($k === 'disabled') { $a .= ' disabled'; continue; }
            $a .= ' ' . $k . '="' . htmlspecialchars((string)$v, ENT_QUOTES) . '"';
        }
        return '<button type="button" class="lg-btn lg-social-btn"' . $a . '>'
             . htmlspecialchars($label, ENT_QUOTES) . '</button>';
    }

    /** One-time inline CSS (brand tokens with fallbacks; widget is portable to /p/). */
    private static function styles(): string
    {
        static $printed = false;
        if ($printed) return '';
        $printed = true;

        return <<<'CSS'
<style>
.lg-social-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:12px}
.lg-social-btn{font:600 13px/1 var(--lg-font-sans,system-ui);padding:9px 16px;border-radius:999px;border:1px solid var(--lg-sage-d,#6b7c52);background:var(--lg-sage,#87986a);color:#fff;cursor:pointer}
.lg-social-btn:hover{filter:brightness(.96)}
.lg-social-btn[disabled]{background:var(--lg-sage-tint,#eef2e3);color:var(--lg-sage-d,#6b7c52);border-color:var(--lg-sage-3,#d4e0b8);cursor:default}
.lg-social-btn[data-lg-social="message"],.lg-social-btn[data-lg-social="decline"],.lg-social-btn[data-lg-social="cancel"]{background:#fff;color:var(--lg-ink,#323532);border-color:var(--lg-line,#e3ddd0)}
.lg-social-more{position:relative;display:inline-flex}
.lg-social-morebtn{display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:999px;border:1px solid var(--lg-line,#e3ddd0);background:#fff;color:var(--lg-ink,#323532);font-size:18px;line-height:1;cursor:pointer}
.lg-social-morebtn:hover{background:var(--lg-sage-tint,#eef2e3)}
.lg-social-menu{position:absolute;top:42px;right:0;min-width:184px;background:#fff;border:1px solid var(--lg-line,#e3ddd0);border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.14);padding:6px;z-index:1000}
.lg-social-menu[hidden]{display:none}
.lg-social-menu__item{display:block;width:100%;text-align:left;border:0;background:none;font:500 13px/1.3 var(--lg-font-sans,system-ui);color:var(--lg-ink,#323532);padding:9px 10px;border-radius:8px;cursor:pointer}
.lg-social-menu__item:hover{background:var(--lg-sage-tint,#eef2e3)}
.lg-social-menu__item--danger{color:var(--lg-rust,#c66845)}
</style>
CSS;
    }

    /** One-time inline wiring (guarded so it prints once even with many widgets). */
    private static function script(): string
    {
        static $printed = false;
        if ($printed) return '';
        $printed = true;

        return <<<'JS'
<script>
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

    var p;
    if (act === 'connect')          { b.disabled = true; p = post(API + '/connections', { addressee_uuid: to }); }
    else if (act === 'accept')      { b.disabled = true; p = post(API + '/connections/' + cid, { action: 'accept' }, 'PATCH'); }
    else if (act === 'decline')     { b.disabled = true; p = post(API + '/connections/' + cid, { action: 'decline' }, 'PATCH'); }
    else if (act === 'cancel')      { b.disabled = true; p = post(API + '/connections/' + cid, { action: 'cancel' }, 'PATCH'); }
    else if (act === 'disconnect')  { p = post(API + '/connections/' + cid, { action: 'disconnect' }, 'PATCH'); }
    else if (act === 'mute')        { p = post(API + '/me/mutes', { uuid: to }); }
    else if (act === 'unmute')      { p = post(API + '/me/mutes/' + encodeURIComponent(to), null, 'DELETE'); }
    else { return; }
    p.then(function () { location.reload(); }).catch(function () { b.disabled = false; });
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeMenus(); });
})();
</script>
JS;
    }
}
