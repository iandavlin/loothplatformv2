<?php
/**
 * featured-members — THE TRACKED CONFIG for backlog item 18 (Ian 8/11, "fairly
 * soon type of thing"; all four design rulings 2026-08-14,
 * docs/IAN-RULINGS-2026-08-14.md item 6, after decide.html).
 *
 * ── WHAT IT SWITCHES ─────────────────────────────────────────────────────────
 *   OFF  the profile-editor tickbox block does not render (member-facing;
 *        Variant B, its own card, per Ian's ruling); the front-page band
 *        never resolves a real member — it only ever renders the hand-typed
 *        LG_FEATURED_MEMBER config, EXACTLY as it has since June.
 *   ON   a member can tick "include me as a possible featured member"; an
 *        admin picks one from that pool in the dash; the front-page band
 *        reads and renders that real member.
 *
 * ── WHY OFF IS BYTE-IDENTICAL ────────────────────────────────────────────────
 * profile-app/web/u.php emits the tickbox block ONLY when on, never as a
 * disabled/hidden variant — same shape as platform/config/back-pill.php.
 * archive-poc/web/index.php's $lg_fm resolution ignores any `member_uuid` in
 * the saved config entirely while off, so the front page cannot drift into
 * "resolved from profile-app" mode even if a stale value sits in config.json
 * from a previous ON state. The admin dash (admin-only, not member-facing —
 * scope note from the charter) is NOT gated by this flag; it may exist and
 * show its correct day-one-empty state while off, since nobody could have
 * opted in with the tickbox unrendered. Writing a member_uuid from the dash
 * while off is inert (the front page will not read it) but is not blocked
 * outright — there is nothing to protect against, an admin is a trusted actor.
 *
 * ── OVERRIDES ARE FOR LANE PREVIEWS ──────────────────────────────────────────
 * getenv() for a CLI harness/gate, $_SERVER for a single nginx location — a
 * fastcgi_param lands in $_SERVER but not reliably in the environment (trap:
 * fastcgi_param is NOT in getenv()), so reading only one would serve the OFF
 * path on the very preview URL built for Ian to click.
 */

return array(

	/**
	 * OFF until Ian has ticked the real thing on the dev2 serve and watched
	 * an admin feature him from the real dash — same house rule as every
	 * other member-facing flag: the serve runs main, so nothing here is
	 * verifiable there until merged, and OFF is what lets it arrive
	 * harmlessly first.
	 */
	'enabled' => false,
);
