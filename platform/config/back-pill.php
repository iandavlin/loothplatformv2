<?php
/**
 * back-pill — THE TRACKED CONFIG for the mobile/PWA "← Hub" sticky pill.
 * Backlog 3.8, RULED by Ian 2026-08-09 (option D of four mockups).
 *
 * ── WHAT IT SWITCHES ─────────────────────────────────────────────────────────
 * Ian 8/9: "on mobile and pwa we need some kind of back nav to the hub once you
 * click through to the post. there is one in the nav tab but it should be
 * exposed." Then, after seeing four options: "Is there any other lowprofile way
 * to go back that is sticky so it's always available?" — and he picked D.
 *
 *   OFF  nothing is emitted and webroot/bottom-nav.js adds nothing.
 *   ON   a slim 32px "← Hub" pill, pinned top-centre, that slides away while you
 *        scroll DOWN and returns the moment you scroll UP.
 *
 * WHY THIS EXISTS AT ALL, measured rather than assumed: webroot/manifest.json is
 * display:standalone with start_url:/hub/, so the installed app has NO browser
 * chrome and NO back button. Once a member taps through to a post the only way
 * back is bottom-nav.js's Back, which lives INSIDE the Nav tray — two taps, and
 * invisible until the first one.
 *
 * ── WHY OFF IS BYTE-IDENTICAL ────────────────────────────────────────────────
 * bb-mirror/web/_chrome.php emits the client bit ONLY when on, never as
 * `= false`. With the flag off the served page is byte-for-byte what it was, and
 * bottom-nav.js's new branch reads `undefined` and returns before touching the
 * DOM. A `= false` would be a behavioural no-op but not a byte-identical one.
 * Same shape as platform/config/sheet-embeds.php, deliberately.
 *
 * ── NO NEW WEBROOT FILE, ON PURPOSE ──────────────────────────────────────────
 * The pill is built inside webroot/bottom-nav.js rather than a new layer. That
 * file is already loaded on every page, already self-gates to <=640px, and
 * already owns the buried Nav-tray Back this pill is promoting — so the two
 * cannot drift apart. It also avoids the deploy coupling a NEW webroot file
 * carries (the symlink set is not in the repo; lg-deploy's
 * install-symlinks.sh --new-only handles it on live, a hand-run pull does not).
 *
 * ── OVERRIDES ARE FOR LANE PREVIEWS ──────────────────────────────────────────
 * getenv() for a pool or CLI harness, $_SERVER for a single nginx location — a
 * fastcgi_param lands in $_SERVER but not reliably in the environment, so
 * reading only getenv() would serve the OFF path on the very preview URL built
 * for Ian to click.
 */

return array(

	/**
	 * OFF until Ian has tapped the real thing on the dev2 serve.
	 *
	 * He has already ruled on the DESIGN from mockups, which is not the same as
	 * having used it — the mock is a still frame and this control's whole point is
	 * how it behaves while your thumb is moving. The house rule stands for the
	 * ordinary reason: the serve runs main, so nothing is verifiable there until
	 * it is merged, and OFF is what lets it arrive harmlessly first.
	 *
	 * Low blast radius when it does go on: it adds one link and navigates to
	 * /hub/. It writes nothing and deletes nothing — unlike social-actions, whose
	 * OFF-by-default was really about not waking "Remove connection" unverified.
	 */
	'enabled' => false,
);
