<?php
/**
 * header-join-stripe — THE TRACKED CONFIG for where the anon header's "Join"
 * button goes. Issue #165, asked for by Ian 2026-08-20.
 *
 * ── WHAT IT SWITCHES: THREE STATES (#170) ────────────────────────────────────
 * Ian 8/20 on #165: "can you Wire the header on Dev2 to have the stripe menuing
 * that a logged out user would see?"  Then, on #170, once that pointed at live:
 *
 *     "We need the join button in the header to still go to patreon unless a
 *      test user is there on live."
 *
 * So the flag stopped being a switch and became an AUDIENCE:
 *
 *   'off'        Nobody gets /lgjoin/. Join → patreon.com, opened in a new tab.
 *                His own ruling of 2026-06-12, and correct for a Patreon-only
 *                world: joining and connecting are different things, so Join
 *                skipped the site entirely and went where the money was taken.
 *                THE TRACKED DEFAULT, and what live serves.
 *
 *   'allowlist'  The Stripe soft-launch cohort, and ONLY while they are signed
 *                in. Everyone else — every anonymous visitor, and every
 *                signed-in member not on the list — still goes to patreon.com.
 *                This is the state live is meant to sit in during the soft
 *                launch.
 *
 *   'on'         Everyone. Join → /lgjoin/, in the SAME tab. Our own two-tier
 *                join page, which offers both worlds, so the dual-rail founding
 *                law is served by the destination rather than by the button.
 *                dev2 runs this today; live gets it at go-live.
 *
 * Nothing else moves in any state. "Connect Patreon" is untouched at every
 * width — it is the anon door to /connect-your-patreon/ and a patron linking an
 * existing pledge is not joining.
 *
 * ── 'allowlist' REUSES THE ONE COHORT LIST. THERE IS NO SECOND LIST ──────────
 * "A test user" is already a solved question, and solving it twice is how the
 * two ends of a fence drift apart. The cohort is the wp_option
 * `lgms_stripe_lifecycle_allowlist` (WP user ids), written by LGMS\CohortAllowlist
 * in the poller's admin dash. The header does not read it, and needs no user id:
 * the poller already computes `$caps['stripe_testgroup'] = manage_options ||
 * inCohort($uid)` (InternalRestController), it rides whoami to every consumer,
 * and site-header.php has been reading it since the Test Group menu shipped.
 * This flag simply gives that same capability a second job.
 *
 * Administrators are in the cohort by construction, so Ian can click the real
 * button on live without being added to a list.
 *
 * ── WHY 'allowlist' IS SAFE ON A CACHED SITE ─────────────────────────────────
 * It rides a per-viewer capability that an anonymous ctx never carries and which
 * fails closed to false. The logged-out page therefore cannot differ by one byte
 * between 'off' and 'allowlist' — not by intent, but because no anonymous input
 * reaches the branch. Gate 79 proves it with cmp rather than by repeating this
 * paragraph.
 *
 * ── WHAT 'allowlist' ADDS TO THE SIGNED-IN HEADER, AND WHY IT HAD TO ─────────
 * Measured on main before #170 was designed: the Join pill renders for ANON
 * ONLY — a signed-in viewer, tester or not, admin or not, gets no Join pill at
 * any width. So "still go to patreon unless a test user is there" could not be
 * delivered by swapping an href on a control the test user cannot see; in
 * 'allowlist' the aside grows a Join pill for the cohort. Deliberately confined
 * to that one state: in 'off' and 'on' the signed-in header stays byte-for-byte
 * what #165 proved, so those proofs survive untouched.
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
 * ⚠️ ── `php -l` THE .local.php BEFORE YOU PLACE IT ──────────────────────────
 * The reader is defensive about every shape a wrong config can take — empty,
 * non-array, missing key, returning nothing, unreadable — and gate 79 asserts
 * each one falls back to today's behaviour. There is exactly ONE it cannot
 * defend against: a PHP SYNTAX ERROR. `@` suppresses warnings, not parse
 * errors, so a half-typed file is a hard fatal for the include.
 *
 * That is the house pattern's property, not this flag's defect — back-pill,
 * frontend-compose and weekly-front all @include the same way, and inventing a
 * bespoke guard here would make a third pattern where two already exist. But
 * this partial renders on EVERY page of EVERY surface, so a typo in this
 * particular override is a site-wide 500 rather than one feature going quiet.
 * The mitigation is operational and takes two seconds:
 *
 *     php -l platform/config/header-join-stripe.local.php
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
	 * 'off' until Ian has clicked the real thing on the dev2 serve, logged out.
	 *
	 * The house rule, for the ordinary reason: the serve runs main, so nothing
	 * is verifiable there until it is merged, and 'off' is what lets this
	 * arrive harmlessly first. This one has an unusually strong claim to it —
	 * the header is on every page, and the /lgjoin/ destination refuses
	 * anonymous visitors until a SECOND switch is thrown (see the coupling
	 * above).
	 *
	 * Low blast radius when it does move: 'allowlist' changes nothing for
	 * anyone not on a hand-picked list, 'on' changes one href and drops one
	 * target attribute. Neither writes anything, deletes anything, or grants
	 * anything.
	 *
	 * ⚠️ `enabled => true` IS STILL READ, AND STILL MEANS 'on'. dev2's
	 * hand-placed .local.php says exactly that and lives in the serving
	 * checkout, which no lane may edit. The old key is permanent
	 * compatibility, not leftovers — see lg_shared_header_join_stripe_state().
	 */
	'state' => 'off',
);
