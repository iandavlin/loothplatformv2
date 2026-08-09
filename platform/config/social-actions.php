<?php
/**
 * social-actions — THE TRACKED CONFIG for where the profile social widget's
 * behaviour comes from. Backlog 4.4 + 4.3 (Ian 8/8).
 *
 * ── WHAT IT SWITCHES ─────────────────────────────────────────────────────────
 * profile-app/src/Social.php renders Connect / Message / Accept / Decline /
 * Cancel and the "..." Mute-Unmute-Remove menu, and has always shipped their
 * behaviour as an inline <script> in the same markup.
 *
 *   OFF  the inline <script>, byte-for-byte as it has always been.
 *   ON   <script src="/lg-social-actions.js">, plus a data-lg-social-src stamp on
 *        the widget so a client that RELOCATES this markup knows where the
 *        behaviour lives and can load it onto the host page.
 *
 * The mobile profile tray (webroot/profile-sheet.js) is exactly such a client: it
 * fetches /u/<slug>?view=member, lifts .lg-profile out with DOMParser and injects
 * it into whatever page you were on. DOMParser scripts are inert and the sheet
 * strips <script> regardless, so with the flag OFF the tray shows all seven
 * controls and every one of them does nothing. That is the reported bug.
 *
 * ── WHY ONE FLAG AND NOT TWO ─────────────────────────────────────────────────
 * The obvious shape is a PHP flag for the server and a JS flag for the docroot
 * layer. That is the drift bug platform/config/post-follow.php's header argues
 * against, and here it is avoidable outright: the client is not told to go
 * looking, it is told BY THE MARKUP IT ALREADY FETCHED. No attribute, no load.
 * So this flag is read in ONE runtime (profile-app), and the client behaviour
 * cannot disagree with it because it IS the server's answer.
 *
 * That is also what makes OFF a real no-op rather than a promised one: with the
 * flag off, /u/ emits the same bytes it always did, the stamp is absent, and
 * profile-sheet.js's new branch is unreachable. Gate 19 asserts all three states
 * — attribute absent, flag OFF, flag ON — off the rendered widget, so flipping
 * the default later needs no gate edit.
 *
 * ── OVERRIDES ARE FOR LANE PREVIEWS, AND THERE ARE TWO ON PURPOSE ────────────
 * getenv() is how a pool or a CLI harness turns it on; $_SERVER is how a single
 * nginx location does. A fastcgi_param lands in $_SERVER but NOT reliably in the
 * process environment, so reading only getenv() would serve the OFF path on the
 * very preview URL built for Ian to click. A fastcgi_param can only be set by an
 * nginx conf, never by a query string, so this is not a visitor-facing switch.
 */

return array(

	/**
	 * OFF until Ian has looked at the running thing.
	 *
	 * This repairs controls that are already dead rather than adding anything, so
	 * there is an argument for defaulting it ON — the same argument
	 * LG_PRESERVE_FORUM_SUBSCRIPTION won on. It does not win here, and the
	 * difference is worth stating rather than leaving to be re-litigated:
	 * that flag stops ONGOING DATA DESTRUCTION (a member silently unsubscribed),
	 * where every hour off is unrecoverable. This one wakes up seven controls that
	 * currently no-op. A dead button is inert; a button that has just started
	 * working can Remove connection, and that one DELETES an edge with no undo.
	 * Waking it unverified is the larger risk, so it merged OFF, and Ian switched
	 * it ON 2026-08-09 (decision box: "Flip ON") after hitting the dead controls
	 * on his phone — his real-device tap on the dev2 serve is the verification
	 * gate; reverts if that fails.
	 */
	'enabled' => true,

	/**
	 * The docroot URL the widget points relocating clients at. Same-origin and
	 * root-relative because both entry paths that need it — profile-app's /u/ and
	 * any page hosting the mobile tray — are the same host.
	 *
	 * ⚠️ DEPLOY COUPLING: this is a NEW webroot file, and the symlink SET is not in
	 * the repo, so a plain pull does not create it. live's lg-deploy runs
	 * `webroot/install-symlinks.sh --new-only` after every pull and handles it; a
	 * manual dev2 pull needs that command in the same window, or this URL 404s and
	 * the flag-ON path is worse than OFF.
	 */
	'src' => '/lg-social-actions.js',
);
