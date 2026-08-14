<?php
/**
 * license-block — THE TRACKED CONFIG for routing the loothprint page's licence
 * through the `license` block instead of a prose callout.
 *
 * ── WHY THIS FILE LIVES INSIDE THE PLUGIN ───────────────────────────────────
 * The rest of the platform keeps its flags in platform/config/. This one cannot:
 * LIVE-DEPLOY.md rsyncs `lg-layout-v2/` to live as a STANDALONE plugin directory,
 * so a sibling path up into platform/ resolves to nothing on live and the flag
 * would read as missing — i.e. the feature's state would depend on which box it
 * was on. A file inside the plugin ships with the plugin.
 *
 * It is still the same principle as platform/config/frontend-compose.php: a
 * tracked PHP file read relative to __DIR__ is visible in EVERY context — FPM,
 * WP-CLI, cron, the standalone renderer — because it is the same file on disk.
 * An env var is the wrong home on this box twice over: WP cron carries no
 * Environment= at all, and an fpm fastcgi_param never reaches getenv().
 *
 * ── WHAT IS AND IS NOT BEHIND THIS FLAG ─────────────────────────────────────
 * The `license` block itself is NOT flagged, because it is inert: adding a block
 * nothing emits changes no page. What is flagged is the one member-facing half —
 * `default_loothprint_layout()` emitting `license` in place of the prose callout,
 * which changes what every synthesized loothprint page renders.
 *
 * OFF must be a byte-identical no-op: the synthesizer emits exactly the callout
 * it emitted before this block existed. That is the state gate 35 asserts, and
 * an absence assertion alone would be vacuous — it is paired with a liveness
 * check that the licence renders at all.
 */

return array(

	/**
	 * OFF until Ian has looked at the running thing.
	 *
	 * Flipping this changes the licence on ~257 loothprint pages at once, so it
	 * is his call and not a lane's. The block is already correct with it OFF —
	 * nothing renders it, and nothing about the stored layouts changes either
	 * way, so turning it on and back off is symmetric.
	 */
	'enabled' => false,
);
