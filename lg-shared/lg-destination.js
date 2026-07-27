/* /srv/lg-shared/lg-destination.js
 *
 * JS twin of lg-shared/lg-destination.php — the same three functions, the same
 * rules, for the doors that are built client-side (the hub sign-in sheet, the
 * front-page discussion modal, the archive map teaser).
 *
 *   window.lgDest.capture(raw)        -> '' | '/path?query'
 *   window.lgDest.here()              -> the current request as a bindable path
 *   window.lgDest.loginUrl(dest,base) -> sign-in href ('' dest => bare base)
 *
 * Loaded for every surface by lg-shared/site-header.php (deferred), so any page
 * that renders the shared chrome has it. Doors still fall back to their own
 * previous behaviour if it is somehow absent — a missing helper must never cost
 * someone the ability to sign in.
 *
 * Keep in step with the PHP core. The hostile-value table that covers both is
 * tools/gates/dest-capture-test.php (PHP) / dest-capture-test.js (JS).
 */
(function (w) {
  'use strict';
  if (w.lgDest) return;

  var MAX_LEN = 512;
  var AUTH_PATHS = ['/wp-login.php', '/patreon-connect', '/patreon-password'];

  // Control chars, newlines, and ANY raw backslash (browsers fold '\' to '/',
  // so /\evil.example is scheme-relative — an off-host URL wearing a path's
  // clothes). Mirrors the PHP core's single regex.
  var BAD_CHARS = /[\x00-\x1F\x7F\\]/;

  function bareHost(h) {
    h = String(h || '').toLowerCase().trim();
    if (!h) return '';
    if (h.charAt(0) === '[') {                 // IPv6 literal — inner colons aren't a port
      var end = h.indexOf(']');
      return end === -1 ? h : h.slice(0, end + 1);
    }
    var colon = h.lastIndexOf(':');
    return colon === -1 ? h : h.slice(0, colon);
  }

  function isAuthPath(candidate) {
    var q = candidate.indexOf('?');
    var path = (q === -1 ? candidate : candidate.slice(0, q)).toLowerCase().replace(/\/+$/, '');
    if (!path) return false;
    for (var i = 0; i < AUTH_PATHS.length; i++) {
      var a = AUTH_PATHS[i].toLowerCase().replace(/\/+$/, '');
      if (path === a || path.indexOf(a + '/') === 0) return true;
    }
    return false;
  }

  function capture(raw) {
    if (typeof raw !== 'string' || !raw || raw.length > MAX_LEN) return '';
    if (BAD_CHARS.test(raw)) return '';

    var path, query;

    if (raw.charAt(0) === '/') {
      if (raw.slice(0, 2) === '//') return '';   // scheme-relative = off-host
      var hash = raw.indexOf('#');
      var noFrag = hash === -1 ? raw : raw.slice(0, hash);   // fragments never reach the server
      var qi = noFrag.indexOf('?');
      path = qi === -1 ? noFrag : noFrag.slice(0, qi);
      query = qi === -1 ? '' : noFrag.slice(qi + 1);
      // A path must not carry a scheme; ':' before the first '/' would be one.
      if (/^[a-z][a-z0-9+.-]*:/i.test(path)) return '';
    } else {
      var u;
      try { u = new URL(raw); } catch (e) { return ''; }
      var scheme = String(u.protocol || '').toLowerCase();
      if (scheme !== 'http:' && scheme !== 'https:') return '';
      if (u.username || u.password) return '';   // https://ourhost@evil.example/
      if (!bareHost(u.hostname) || bareHost(u.hostname) !== bareHost(w.location.hostname)) return '';
      path = u.pathname;
      query = u.search.replace(/^\?/, '');
    }

    var candidate = path + (query ? '?' + query : '');
    if (!candidate || candidate.charAt(0) !== '/' || candidate.slice(0, 2) === '//') return '';
    if (candidate.length > MAX_LEN) return '';
    if (isAuthPath(candidate)) return '';
    return candidate;
  }

  function here() {
    return capture(w.location.pathname + w.location.search);
  }

  function loginUrl(dest, base) {
    base = base || '/wp-login.php';
    var d = capture(dest);
    if (!d) return base;
    return base + (base.indexOf('?') === -1 ? '?' : '&') + 'redirect_to=' + encodeURIComponent(d);
  }

  w.lgDest = { capture: capture, here: here, loginUrl: loginUrl, MAX_LEN: MAX_LEN };
})(window);
