<?php
/**
 * directory-location-cap — backlog 20 (Ian 8/15, via keeper): a member's
 * street address must never appear in a LIST surface, no matter what the
 * viewer is or what the member's own precision dial says.
 *
 * ── THE DEFECT ───────────────────────────────────────────────────────────
 * profile-app/api/v0/directory-members.php's dir_member_display() calls
 * Visibility::locationPrecision(), which — correctly, for a SINGLE profile
 * page — grants the 'admin' and 'owner' audiences 'street' precision (full
 * address text). But dir_member_display() is also the shared coarsening
 * point for the paginated directory LIST and the map-PIN feed (every row,
 * not one page). An admin (Ian) browsing either therefore sees every
 * member's raw street address on every row, not just their own profile —
 * found live: member Luke (WP 2091, profile_app id 2131), row real, address
 * real ("232 Lee st N , Lewisburg, WV 24901").
 *
 * ── WHAT IT SWITCHES ─────────────────────────────────────────────────────
 *   OFF  dir_member_display() is unchanged: 'street' precision (from
 *        Visibility::locationPrecision, for owner/admin, or any member whose
 *        OWN public-precision dial says 'street') flows straight through to
 *        every list row and map pin, exactly as it has all along.
 *   ON   dir_member_display() downgrades a 'street' precision result to
 *        'city' BEFORE calling Block::locationDisplay() — list rows and pins
 *        never render more than "City, Region", regardless of audience or
 *        dial. The member's own /u/<slug> profile page is untouched by this
 *        flag; it does not call dir_member_display() and keeps showing
 *        whatever precision the viewer/dial combination already resolves to.
 *
 * ── WHY OFF IS BYTE-IDENTICAL ────────────────────────────────────────────
 * The cap is a single `if` wrapped around one line in dir_member_display();
 * off, that branch never executes and precision passes through unchanged —
 * same output as before this file existed.
 */

return array(
	/**
	 * OFF until Ian has looked at the dev2 directory list as an admin and
	 * confirmed street addresses no longer show on list rows — same house
	 * rule as every other member-facing flag: nothing here is verifiable on
	 * the serve until merged, and OFF is what lets it arrive harmlessly
	 * first.
	 */
	'enabled' => false,
);
