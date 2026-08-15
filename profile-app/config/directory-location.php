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
 * ⚠️ THIS IS NOT PURELY A SAFETY DIAL. 6 of those 7 rows have a business_name
 * set: they are guitar shops that turned street precision on so customers could
 * find them, and this takes the street line out of their directory row. That is
 * the charter's ruling, not an accident — but it is a PRODUCT decision and it is
 * flagged here so it can be reversed in one line if Ian wants shops exempt.
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
	 * The map PIN is not moved by this flag. Pin precision is its own dial
	 * (users.location_pin_precision) plus the anon coarsening in
	 * Profile::renderLocation(); moving it from here would silently override a
	 * control the member set somewhere else, and would break the shop pins that
	 * are the whole point of the map view.
	 */
	'coarsen_list_location' => false,

);
