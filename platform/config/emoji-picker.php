<?php
/**
 * emoji-picker — THE TRACKED CONFIG for the DM composer's emoji picker.
 *
 * Ruling: docs/IAN-RULINGS-2026-08-03.md §2 — "DM emoji picker — Variant 1".
 * Mock: /footer-mockups/emoji-picker/ (branch emoji-picker, ae29c0e).
 *
 * SCOPE IS THE COMPOSER, NOT REACTIONS, AND NOT EMAIL.
 *   · Reactions stay the FIXED SIX, server-validated against
 *     Messaging::REACTION_EMOJI. Ian ruled against a picker there on 2026-07-13
 *     ("no full picker, no keyboard — this is a phone-first surface") and that
 *     ruling is untouched. Composer emoji are ordinary text inside the message
 *     body; reactions are structured rows in message_reactions. One emoji
 *     vocabulary, two jobs.
 *   · LG_WD_Email_Builder::strip_emoji strips emoji from some EMAIL surfaces ON
 *     PURPOSE. That is email, not messages. Do not "fix" it while working here.
 *
 * ── WHY A CONFIG FILE RATHER THAN A CONSTANT ─────────────────────────────────
 * The picker straddles runtimes that do not share a config, and the UI is in
 * STATIC JS that never executes PHP at all:
 *
 *   · desktop modal + side dock — lg-shared/social-modals.js, loaded by TWO
 *     separate PHP loaders (lg-shared/site-header.php on WP pages, and
 *     profile-app/web/_chrome.php on /u/ profile pages — see that file's note
 *     about being "the only loader" there)
 *   · phone pull-up sheet      — webroot/messenger-sheet.js, not loaded by PHP
 *     at all: webroot/pwa.js idle-loads it after boot
 *
 * Three constants in three runtimes is a drift bug waiting to happen, and the
 * failure is the one Ian has ruled against repeatedly — a control that renders
 * and silently does nothing, or a composer that ships the button on the phone
 * and not the desktop. One tracked file, read by every loader, makes them unable
 * to disagree. Same reasoning as platform/config/post-follow.php and
 * platform/config/follow-digest.php; read post-follow.php's header for the
 * longer argument about why an env var is the wrong home for this.
 *
 * The value reaches the browser through webroot/pwa-loader.php, which already
 * injects window.LG_V into /pwa.js and is sub_filter-injected into every page's
 * </head>. It is served Cache-Control: no-cache with an ETag over the whole
 * body, so flipping this file propagates on the next request — no hand-bumped
 * counter, no conf edit, no waiting out a 1y immutable asset cache.
 *
 * ── THE OVERRIDES ARE FOR LANE PREVIEWS, AND THERE ARE TWO ON PURPOSE ────────
 * getenv() is how a pool or a CLI harness turns it on; $_SERVER is how a single
 * nginx location does — tools/preview/lane-preview.sh gives a branch a URL by
 * setting fastcgi_param, and a fastcgi_param lands in $_SERVER but NOT reliably
 * in the process environment. Reading only getenv() would serve the OFF path on
 * the very preview URL built for Ian to click (that exact defect has been paid
 * for once already). A fastcgi_param can only be set by an nginx conf, never by
 * a query string, so this is not a way for a visitor to switch the feature on.
 */

return array(

	/**
	 * OFF until Ian has looked at the running thing.
	 *
	 * Member-facing feature, so the house rule applies with no argument to make:
	 * it merges OFF, reaches the dev2 serve harmlessly, gets verified there for
	 * real, and only then is it switched on. The dev2 serve serves main, so this
	 * flag is the only thing that makes "verified on the serve before Ian sees
	 * it" satisfiable at all.
	 *
	 * ⚠️ WHAT "OFF IS A NO-OP" MEANS HERE, PRECISELY — the honest version, because
	 * this feature cannot make the weaker claim true and pretending otherwise is
	 * how a gate goes green on something it never checked:
	 *
	 *   · The RENDERED COMPOSER is byte-identical to the pre-feature markup. No
	 *     ☺ button, no panel, no strip, no new listeners, no new nodes. This is
	 *     the claim that matters and gate 19 proves it by DOM comparison.
	 *   · The SERVED /pwa.js is byte-identical, because pwa-loader.php emits the
	 *     global ONLY when the flag is on. OFF adds not one byte to that asset.
	 *   · social-modals.js and messenger-sheet.js DO change bytes — they carry
	 *     the dormant code. No path in it executes while this is false. A static
	 *     asset cannot be conditionally compiled, so this is unavoidable, and
	 *     saying "byte-identical" about those two files would be a lie.
	 *
	 * Fail-closed: every reader treats absent/unset as OFF, so a loader that
	 * fails to run cannot accidentally expose the feature.
	 */
	'enabled' => false,
);
