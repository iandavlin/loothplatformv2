<?php
/**
 * profile-setup — THE TRACKED CONFIG for the arrive-alive step (backlog 19).
 *
 * Ian 8/12 from the empty-directory screenshot, ruled 8/15: OPTION A APPROVED.
 *
 * ── WHAT IT SWITCHES ─────────────────────────────────────────────────────────
 * A new member's profile arrives as a bare shell. This adds ONE skippable screen
 * at /profile-setup/ asking for the three things a directory row actually shows —
 * a photo, a city, and one line about what they do.
 *
 *   OFF  nothing exists. /profile-setup/ is not registered (404, as today), and
 *        BOTH joining rails end exactly where they always did:
 *          · Patreon — lgpo-set-password.php redirects to home_url('/') on a
 *            successful set, and its skip link points at the member's profile.
 *          · Stripe  — membership-pages/web/welcome.php's CTA reads
 *            "Head to the community" and points at "/".
 *        Both are asserted byte-identical by the gate, not merely believed.
 *   ON   the step is registered, and each rail's end-of-join hands off to it.
 *
 * ── WHY IT HANGS OFF MEMBERSHIP ACTIVATION, NOT A RAIL ───────────────────────
 * Ian's standing ruling, 8/15: "Everything needs to fire for both for the
 * foreseeable future… we are dual wielding patreon and stripe for a while."
 * So the step is keyed to BEING A NEWLY ACTIVATED MEMBER, never to a Patreon
 * callback or a Stripe webhook. A Patreon joiner and a Stripe joiner get the
 * identical screen, and a third rail later inherits it for free.
 *
 * ⚠️ AND IT MUST NEVER BE WIRED INTO THE STRIPE LEG. The stripe-membership lane's
 * gate 34d refuses to let that leg mail, fire a hook, or stamp member data —
 * putting the step there would break both Ian's ruling and their gate. The two
 * rails hand off from their own end-of-join PAGES, which is why the wiring below
 * touches lgpo-set-password.php and welcome.php and nothing deeper.
 *
 * ── WHY NOT THE EXISTING WELCOME MODAL ───────────────────────────────────────
 * There IS a rail-agnostic one-shot welcome (Arbiter.php:113 stamps
 * _lg_pending_welcome; Plugin.php:657 renders it at wp_footer). It looked like a
 * free ride and it is not: it never fires for NEW members. The account is created
 * with its paid role already applied (lg-patreon-onboard.php:1553), so
 * Arbiter::sync's $oldTier already equals the winning tier and isUpgradeToPaid()
 * returns false. Measured on live 2026-08-15: 16 welcome emails EVER, newest
 * 2026-06-21, and 0 of the 33 members who joined since 1 July got either the
 * modal or the email. That gap is a real defect but it is NOT this lane's to fix
 * — so this feature carries its own signal rather than depending on a hook that
 * is currently silent for exactly its own audience.
 *
 * ── NO NUDGING. EVER. ────────────────────────────────────────────────────────
 * Ian, 8/15: "No nudging on that matter." There is no banner, no dismissible
 * card, no front-page prompt, and no "your profile is N% complete" chase
 * anywhere. This is deliberately an ABSENCE, and absences rot back in quietly,
 * so the gate asserts the nudge surface does not exist rather than trusting that
 * nobody adds one.
 *
 * ── OVERRIDES ARE FOR LANE PREVIEWS ──────────────────────────────────────────
 * getenv() is how a pool or CLI harness turns it on; $_SERVER is how a single
 * nginx location does. A fastcgi_param lands in $_SERVER but NOT reliably in the
 * process environment, so reading only getenv() would serve the OFF path on the
 * very preview URL built for Ian to click. A fastcgi_param can only be set by an
 * nginx conf, never by a query string, so this is not a visitor-facing switch.
 */

return array(

	/**
	 * OFF until Ian has looked at the running thing on the dev2 serve.
	 *
	 * This ADDS a screen to the one flow every new member walks through, so an
	 * unverified ON is felt by every single joiner immediately and by nobody
	 * else — the worst possible audience to discover a defect in. It merges OFF,
	 * gets verified on the serve, and Ian flips it.
	 */
	'enabled' => false,

	/**
	 * LIVE LIMITED TESTING — a list of WP user IDs, and nobody else.
	 *
	 * Ian, 2026-08-15: he wants to walk this on LIVE with a couple of named
	 * members before it is switched on for everyone. Three states, and the first
	 * one is the state we ship in:
	 *
	 *   enabled=false, testers=[]        TOTAL ABSENCE. The route is never
	 *                                    registered, /profile-setup/ 404s exactly
	 *                                    as it did before this feature existed,
	 *                                    and NEITHER rail's end-of-join page is
	 *                                    touched. This is today, and it is gated.
	 *   enabled=false, testers=[12,34]   The step exists for those member IDs
	 *                                    ONLY. Every other member — logged in or
	 *                                    not, on either rail — gets byte-identical
	 *                                    OFF behaviour.
	 *   enabled=true                     Everyone. testers stops mattering.
	 *
	 * ⚠️ IDENTITY IS THE WORDPRESS LOGIN AND NOTHING ELSE. No dev-gate token, no
	 * cookie of our own, no query parameter — not on live, not ever. The dev2 gate
	 * token is a mock-hosting convenience that does not exist on live, and a
	 * query-string opt-in would make this list decorative the moment anyone
	 * guessed the parameter. Both rails resolve the viewer the same way: WordPress
	 * gives the mu-plugin get_current_user_id(), and the standalone membership app
	 * reads wp_user_id out of lg_membership_header_ctx(), which is itself derived
	 * from the wordpress_logged_in_* cookie.
	 */
	'testers' => array(),

	/**
	 * The step's own URL. Root-relative and same-origin: both rails that hand off
	 * to it are on this host, and the page is a WP mu-plugin route (the same
	 * REQUEST_URI-on-init pattern /patreon-password/ has used since 6/16), so it
	 * needs no nginx location of its own and no new symlink.
	 */
	'path' => '/profile-setup/',
);
