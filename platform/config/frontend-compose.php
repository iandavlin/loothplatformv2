<?php
/**
 * frontend-compose — THE TRACKED CONFIG for the front-end compose/edit surface.
 *
 * ── WHY A FILE AND NOT THE CONSTANT IT REPLACES ──────────────────────────────
 * This feature straddles two runtimes that do not share a config, and it acquired
 * the second one the moment Ian ruled the entry point is a TYPE TOGGLE inside the
 * hub composer:
 *
 *   · the FORM   — platform/mu-plugins/lg-frontend-compose.php, in WordPress
 *   · the TOGGLE — bb-mirror's hub app, a different FPM pool with no WP loaded
 *
 * It was a single `define('LG_FRONTEND_COMPOSE', …)` while only WordPress cared.
 * With the toggle it cannot stay that way: bb-mirror cannot see a WP constant, so
 * a second flag would appear, and then the two can disagree in both directions —
 * toggle on / form off renders a control whose iframe 404s (the "UI lies" class
 * Ian has ruled against repeatedly), form on / toggle off means a route nobody
 * can reach. One file, read by both, makes both states unreachable.
 *
 * Straight copy of the reasoning in platform/config/post-follow.php, which solved
 * the identical split for ruling 6's follow controls. Read that header too.
 *
 * A tracked PHP file read relative to __DIR__ is visible in EVERY context — FPM,
 * WP-CLI, cron, the bb-mirror app — because it is the same file on disk, and it
 * lands with the pull. An env var is the wrong home on this box twice over: WP
 * cron carries no Environment= at all, and an fpm fastcgi_param never reaches
 * getenv().
 *
 * ── THE OVERRIDES ARE FOR LANE PREVIEWS, AND THERE ARE TWO ON PURPOSE ────────
 * getenv() is how a pool or a CLI harness turns it on; $_SERVER is how a single
 * nginx location does — tools/preview/lane-preview.sh gives a branch a URL by
 * setting fastcgi_param, and a fastcgi_param lands in $_SERVER but NOT reliably
 * in the process environment. Reading only getenv() would serve the OFF path on
 * the very preview URL built for Ian to click. A fastcgi_param can only be set by
 * an nginx conf, never by a query string, so this is not a way for a visitor to
 * switch the feature on.
 */

return array(

	/**
	 * OFF until Ian has looked at the running thing.
	 *
	 * ⚠️ AND THE FLAG IS NOW THE ONLY SAFETY. Ian ruled ALL MEMBERS on 2026-08-09
	 * and the allow-list and gated tier were deleted rather than disabled, so
	 * nothing else narrows who gets this once it is on. That makes OFF-by-default
	 * load-bearing rather than ceremonial, and flipping it his call, not a lane's.
	 *
	 * OFF is a byte-identical no-op, asserted by gate 19 against a fingerprint of
	 * /compose/ recorded BEFORE the feature existed — not against the weaker
	 * "nothing was rendered".
	 */
	'enabled' => false,
);
