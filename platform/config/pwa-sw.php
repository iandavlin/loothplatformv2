<?php
/**
 * pwa-sw — THE TRACKED CONFIG for the service worker's fetch behaviour.
 * Backlog 3.10 (Ian hit it twice on 2026-08-09 viewing dev mocks).
 * Audit and measurements: docs/PWA-SW-AUDIT.md.
 *
 * ── WHAT IT SWITCHES ─────────────────────────────────────────────────────────
 * `webroot/sw.js`'s navigation handler is `fetch(req)` with NO DEADLINE. A fetch that
 * neither resolves nor rejects leaves `respondWith` pending forever, so the tab spins
 * and nothing appears in the access log — measured as STRANDED at the 4s budget by
 * tools/gates/lib/sw-handler-harness.js against the shipped file. The existing retry
 * is `.catch`-guarded, so it cannot see a hang at all.
 *
 * Four measured defects, all OFF by default, all switched by this one flag:
 *   1. no deadline        -> a hung navigation strands the user indefinitely
 *   2. addAll(SHELL)      -> all-or-nothing; ONE 403 shell asset kills the whole
 *                            install, and skipWaiting() sits in the same chain
 *   3. dev paths          -> the SW intercepts /footer-mockups/ etc, so a dev preview
 *                            lands in the app cache and mediates Ian's mock viewing
 *   4. a gate 403         -> handed to the user raw, when /claim is RIGHT THERE and
 *                            ungated (the installed app's cookie jar has no dev cookie)
 *
 *   OFF  every one of those behaves exactly as it does today. sw.js takes its current
 *        code path verbatim; pwa.js registers '/sw.js' with no query, as today; and
 *        pwa-loader.php emits NOTHING extra, so the served bytes are unchanged.
 *   ON   deadline + per-asset install + dev-path bypass + claim prompt.
 *
 * ── HOW THE BIT REACHES A STATIC SERVICE WORKER ─────────────────────────────
 * sw.js is a static docroot file; it cannot read PHP. But `/pwa.js` IS PHP-served
 * (nginx `location = /pwa.js` fastcgi-passes to pwa-loader.php), and pwa.js is what
 * calls `navigator.serviceWorker.register()`. So the flag rides the REGISTRATION URL:
 *
 *   pwa-loader.php  emits `window.LG_SW="resilient"`   (ONLY when ON)
 *   pwa.js          register('/sw.js?f=' + LG_SW)      (plain '/sw.js' when absent)
 *   sw.js           reads self.location.search
 *
 * Same shape as platform/config/sheet-embeds.php: one flag, read in one runtime, with
 * the client's behaviour following from what the server actually SENT rather than from
 * a second copy of the flag that can drift. And as there, OFF emits no global at all —
 * not even a false one — so OFF is byte-identical rather than merely behaviourally
 * equivalent.
 *
 * ⚠️ TURNING THIS ON REPLACES THE REGISTRATION. A different script URL is a different
 * worker: '/sw.js?f=resilient' installs alongside and supersedes '/sw.js' at the same
 * scope. That is the normal update path and it is what makes the flag reach existing
 * installs at all, but it does mean flipping this flag re-runs install for every
 * client. Flipping it BACK likewise. Expect one extra install per client per flip, and
 * nothing worse: the cache name is unchanged, so no member loses cached shell assets.
 *
 * ── ⚠️ DO NOT KEY ANY OF THIS ON LG_ENV ─────────────────────────────────────
 * LIVE'S /etc/looth/env SAYS `LG_ENV=dev2` (verified 2026-07-31, re-confirmed while
 * auditing 3.10). The dev-path bypass and the claim prompt are dev2-only behaviours,
 * and sw.js decides that from `self.location.hostname` — an explicit allowlist of
 * 'dev2.loothgroup.com', never a negation of 'loothgroup.com' — so live can never
 * render a "claim this device" page to a paying member.
 *
 * ── OVERRIDES ARE FOR LANE PREVIEWS ─────────────────────────────────────────
 * getenv() for a pool or a CLI harness, $_SERVER for a single nginx location — a
 * fastcgi_param lands in $_SERVER but NOT reliably in the environment, so reading only
 * getenv() would serve the OFF path on the very preview URL built for Ian to click.
 */

return array(

	/**
	 * OFF until Ian has seen it on the serve. This is member-visible on LIVE — the
	 * same sw.js serves live members, byte-identical, and the deadline changes what a
	 * hung navigation does for them — so it merges OFF per CLAUDE.md and is flipped
	 * when he has looked at the running thing, not when the gates are green.
	 */
	'resilient_fetch' => false,

	/**
	 * How long a navigation may wait on the network before the SW stops waiting and
	 * serves the offline page. NOT a performance knob: it is the difference between a
	 * bounded failure and an indefinite one.
	 *
	 * 8000ms deliberately: long enough that a slow-but-alive mobile radio still wins
	 * (a cold /hub/ on a bad connection is seconds, not milliseconds), short enough
	 * that a human reads it as "that failed" rather than "this app is broken". The
	 * retry the current file already performs is preserved and happens INSIDE this
	 * budget, so a transient blip still resolves to the real page.
	 */
	'nav_timeout_ms' => 8000,

	/**
	 * Path prefixes the service worker must keep its hands off entirely — no
	 * interception, no caching, no offline fallback. The browser fetches them itself.
	 *
	 * /footer-mockups/ is the measured one: nginx's sub_filter injects /pwa.js into
	 * EVERY text/html response including static mock HTML, so merely viewing a mock
	 * registered a scope-'/' worker and pulled the preview into the app cache. Ian's
	 * own access-log lines are in the audit doc.
	 *
	 * These are dev surfaces. The list is consulted ONLY on dev2 (see the hostname
	 * note above), so adding a prefix here cannot change live behaviour.
	 */
	'sw_bypass_prefixes' => array(
		'/footer-mockups/',
		'/claim',
		'/gatetest',
		'/vscode/',
	),
);
