<?php
/**
 * directory-location — THE TRACKED CONFIG for how much of a member's location the
 * DIRECTORY LIST SURFACES are allowed to print.
 *
 * Consumed by `profile-app/api/v0/directory-members.php` (dir_member_display());
 * asserted by `tools/gates/directory-location-gate.py`. It arrives on a box by
 * `git pull` and nothing else.
 *
 * ── WHY A PHP FILE AND NOT AN ENV VAR ────────────────────────────────────────
 * Same three measured traps as config/notifications.php: `fastcgi_param` never
 * lands in getenv(), WP cron carries no environment at all, and live's
 * /etc/looth/env says LG_ENV=dev2 so nothing may branch on the environment name.
 * One value, the same on every box. `/srv/profile-app` is a symlink into the
 * monorepo, so `__DIR__` resolves here in every context and this needs no symlink
 * of its own and no reload.
 *
 * ── WHAT THE DEFECT WAS (Ian, backlog 20, member safety) ─────────────────────
 * The directory printed whatever precision the member's own dial allowed, and a
 * member who set "street" got their full street address plus postcode rendered
 * into a directory ROW, a MAP PIN POPUP and the click-through STUB — to every
 * anonymous visitor. Measured on live 2026-08-15: 7 of the 37 anon-visible rows
 * were full street addresses; 14 members publish street precision to logged-in
 * members. A list is an index you scan, not a detail view, and it is read by
 * people the member never chose. The member's OWN PROFILE PAGE is unchanged and
 * still shows exactly what they chose — this governs list surfaces only.
 *
 * ⚠️ IT IS NOT PURELY A SAFETY DIAL, AND IAN RULED ON THAT. 6 of those 7 rows
 * have a business_name set: they are guitar shops that turned street precision
 * on so customers could find them, and this takes the street line out of their
 * directory row (their profile page keeps it). Raised with him because the
 * codebase already carves out storefront drop-off addresses as deliberately
 * published; his ruling, 2026-08-15, was NO EXEMPTION — truncate everyone
 * uniformly — reasoning "I'm going to trust that they can fix their position as
 * it suits them", i.e. members can adjust their own location text, and a proper
 * BUSINESS PROFILE is coming later as its own feature. So there is deliberately
 * no business_name branch anywhere in this feature: the simplest rule, applied
 * to everyone. Do not add one without a new ruling.
 */

return array(

	/**
	 * OFF: every list surface prints exactly what the member's precision dial
	 *      allows, including a verbatim street address (today's behaviour,
	 *      unchanged — dir_member_display() returns Block::locationDisplay()
	 *      untouched, so OFF is a proven byte-identical no-op).
	 * ON:  list surfaces print the STRUCTURED "City, Region" label and nothing
	 *      else. A row storing a street address renders "Bedford Hills, New York".
	 *      A row with no structured labels renders NO text rather than falling
	 *      back to its verbatim string — on a list, "regardless of what the field
	 *      holds" has to mean the verbatim string can never be reached.
	 *
	 * Coarser-than-city precisions are deliberately NOT touched: 'state' already
	 * prints "Region, Country", and clamping it to "City, Region" would make a
	 * deliberately-vague row MORE precise. A privacy clamp must only ever subtract.
	 *
	 * THE MAP PIN COARSENS TOO, and that is deliberate. ON downgrades a 'street'
	 * result to 'city' BEFORE rendering, so the pin re-renders through the same
	 * city branch as the text (~1.1km, zoom 11, a circle instead of an exact
	 * marker). An earlier draft of this capped only the text and left a zoom-15
	 * exact marker sitting on the house — which is the address, drawn instead of
	 * spelled. Gate 44 asserts every exact pin coarsens under ON.
	 *
	 * (This paragraph previously claimed the pin was NOT moved. It was written
	 * for that earlier text-only draft and was left behind when the approach
	 * changed — corrected 2026-08-15. A comment that contradicts the code is
	 * worse than none.)
	 */
	'coarsen_list_location' => false,

);
