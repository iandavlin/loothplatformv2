<?php
/**
 * header-join-stripe — THE TRACKED CONFIG for where the anon header's "Join"
 * button goes. Issue #165, asked for by Ian 2026-08-20.
 *
 * ── WHAT IT SWITCHES ─────────────────────────────────────────────────────────
 * Ian 8/20: "can you Wire the header on Dev2 to have the stripe menuing that a
 * logged out user would see?"
 *
 *   OFF  Join → https://www.patreon.com/c/theloothgroup/membership, opened in a
 *        new tab. His own ruling of 2026-06-12, and correct for a Patreon-only
 *        world: joining and connecting are different things, so Join skipped the
 *        site entirely and went where the money was taken.
 *   ON   Join → /lgjoin/, in the SAME tab. Our own two-tier join page, which
 *        offers both worlds, so the dual-rail founding law is served by the
 *        destination rather than by the button.
 *
 * Nothing else moves in either state. "Connect Patreon" is untouched at every
 * width — it is the anon door to /connect-your-patreon/ and a patron linking an
 * existing pledge is not joining.
 *
 * ── WHY A FLAG AT ALL: THE BLAST RADIUS ──────────────────────────────────────
 * lg-shared/site-header.php renders on EVERY page of EVERY strangler surface.
 * Measured on dev2 the day this was written — seven independent apps emit the
 * one anchor this flag addresses:
 *
 *   /  /hub/  /events/  /sponsors/  /connect-your-patreon/
 *   /shop-layout-planner/  /directory/members/  /lgjoin/
 *
 * bb-mirror, archive-poc, events, profile-app, membership-pages, lg-layout-v2
 * and a docroot script. There is no smaller place to make this change and no
 * page it does not reach, which is exactly the shape that has to arrive OFF.
 *
 * ── WHY OFF IS BYTE-IDENTICAL, AND WHY THAT IS GATED ─────────────────────────
 * OFF emits the historical anchor byte-for-byte, `target="_blank" rel="noopener"`
 * included. That is not asserted by argument: gate 79 renders the partial from
 * this branch AND from `git show origin/main:lg-shared/site-header.php` with the
 * same anonymous ctx and compares the bytes. "OFF is a no-op" going unasserted
 * and then drifting is the recurring failure class in this repo, so here it is a
 * gated fact rather than a claim in a docblock.
 *
 * ⚠️ ── THE COUPLING: THIS FLAG IS NOT SUFFICIENT ON ITS OWN ─────────────────
 * /lgjoin/ decides its own audience, and it does NOT admit anonymous visitors by
 * default. membership-pages/web/router.php lists it as
 *
 *     'lgjoin' => ['lgjoin.php', 'testgroup', 'public']
 *
 * and the wp_option `lgms_stripe_pages_live` picks which column applies. While
 * that option is off, the PRE-LAUNCH column ('testgroup') applies, and an anon
 * visitor — not an admin, not on the test-group list, holding no invite — is
 * handed lg_membership_admin_gate_or_exit()'s stub: "This page isn't available
 * yet". Measured on dev2 2026-08-20: anon GET /lgjoin/ returns exactly that.
 *
 * So turning this flag ON while that option is off produces a Join button that
 * is wired correctly and lands nowhere — which reads as a broken feature when
 * every line of it is right. THE TWO FLIP TOGETHER, in one window:
 *
 *     this flag                  header-join-stripe.local.php on the box
 *     lgms_stripe_pages_live     WP admin, Settings → LG Member Sync
 *
 * Gate 79 leg E holds that shut: while this flag is ON it ASSERTS that anon
 * GET /lgjoin/ is not the stub. While it is OFF that leg reports and does not
 * assert, so it never reddens a lane that has nothing to do with it.
 *
 * ── OVERRIDES ARE FOR LANE PREVIEWS AND RED-FIRST LEGS ───────────────────────
 * LG_HEADER_JOIN_STRIPE is read from getenv() AND $_SERVER. A fastcgi_param
 * lands in $_SERVER but not reliably in the environment, so a getenv()-only
 * reader would serve the OFF path on the very preview URL built for Ian to
 * click. Never a deploy mechanism — the deploy mechanism is the .local.php
 * beside this file, because dev2's FPM pool files are symlinks into the serving
 * checkout and an env[] flip dirties a tracked file in the one checkout that
 * must only ever pull.
 */

return array(

	/**
	 * OFF until Ian has clicked the real thing on the dev2 serve, logged out.
	 *
	 * The house rule, for the ordinary reason: the serve runs main, so nothing
	 * is verifiable there until it is merged, and OFF is what lets this arrive
	 * harmlessly first. This one has an unusually strong claim to it — the
	 * header is on every page, and the ON destination refuses anonymous
	 * visitors until a SECOND switch is thrown (see the coupling above).
	 *
	 * Low blast radius when it does go on: it changes one href and drops one
	 * target attribute. It writes nothing, deletes nothing, and grants nothing.
	 */
	'enabled' => false,
);
