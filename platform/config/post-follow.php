<?php
/**
 * post-follow — THE TRACKED CONFIG for ruling 6's follow controls.
 *
 * ── WHY THIS FILE EXISTS RATHER THAN TWO CONSTANTS ───────────────────────────
 * This feature straddles two runtimes that do not share a config:
 *
 *   · the WRITE  — platform/mu-plugins/lg-post-follow-controls.php, in WordPress
 *   · the UI     — bb-mirror's forums app, whose flags live in bb-mirror/config.php
 *
 * The obvious thing is a constant in each. That is a drift bug waiting to happen, and
 * a nasty one in both directions: UI on / write off renders a control that silently
 * does nothing (the "UI lies" class Ian has ruled against repeatedly), and write on /
 * UI off means a param nobody sends. One file, read by both, makes the two states
 * unable to disagree.
 *
 * A tracked PHP file read relative to __DIR__ is visible in EVERY context — FPM,
 * WP-CLI, cron, the bb-mirror app — because it is the same file on disk, and it lands
 * with the pull. Same reasoning as platform/config/follow-digest.php; read that file's
 * header for the longer argument about why an env var is the wrong home.
 *
 * ── THE OVERRIDES ARE FOR LANE PREVIEWS, AND THERE ARE TWO ON PURPOSE ────────
 * getenv() is how a pool or a CLI harness turns it on; $_SERVER is how a single nginx
 * location does — tools/preview/lane-preview.sh gives a branch a URL by setting
 * fastcgi_param, and a fastcgi_param lands in $_SERVER but NOT reliably in the process
 * environment. Reading only getenv() would serve the OFF path on the very preview URL
 * built for Ian to click. A fastcgi_param can only be set by an nginx conf, never by a
 * query string, so this is not a way for a visitor to switch the feature on.
 */

return array(

	/**
	 * OFF until Ian has looked at the running thing.
	 *
	 * This is a member-facing FEATURE, so the plain house rule applies and there is no
	 * argument to make: OFF must be a byte-identical no-op, and gate 18 proves it by
	 * comparing the OFF state against the mu-plugin not being loaded at all rather than
	 * against the weaker "nothing was written".
	 *
	 * ⚠️ Distinct from LG_PRESERVE_FORUM_SUBSCRIPTION, which defaults ON and says why:
	 * that one is a repair for ongoing data destruction, not a feature. Do not "make
	 * them consistent" — they are different kinds of change and the difference is the
	 * whole argument.
	 */
	'enabled' => false,
);
