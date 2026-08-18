<?php
/**
 * location-address — THE TRACKED CONFIG for WHICH STORED COLUMN the profile's
 * Location block prints as a member's street address.
 *
 * Consumed by `Looth\ProfileApp\Block::locationDisplay()` (street precision only)
 * and by `profile-app/api/v0/me-location.php` (the save path); asserted by
 * `tools/gates/location-address-gate.py`. It arrives on a box by `git pull` and
 * nothing else. A gitignored `location-address.local.php` beside this file wins
 * per-key (Flags::all()), which is how dev2 flips without a tracked-file edit.
 *
 * ── WHY A PHP FILE AND NOT AN ENV VAR ────────────────────────────────────────
 * Same three measured traps as config/notifications.php and
 * config/directory-location.php: `fastcgi_param` never lands in getenv(), WP cron
 * carries no environment at all, and live's /etc/looth/env says LG_ENV=dev2 so
 * nothing may branch on the environment name. One value, the same on every box.
 *
 * ── WHAT THE DEFECT IS (member report, 47 days old at time of writing) ────────
 * John Wilmink (Thomas Muse Guitars) retyped his profile location to his shop and
 * dragged the map pin. The pin moved and is correct. The address TEXT under it
 * kept showing his HOME address.
 *
 * His typed address was saved correctly the whole time — it is in
 * `users.location_text`. The block prints a DIFFERENT column: at street precision
 * locationDisplay() reads `users.location_address` first and only falls back to
 * location_text when that is empty.
 *
 * `users.location_address` has exactly ONE writer in the entire repo: the one-time
 * BuddyBoss import, `profile-app/bin/snapshot-location-from-bb.php`. No editor
 * endpoint maintains it — me-location.php did not mention it once. So for any
 * member who has edited their location since the import it is FROZEN on the old
 * address, and being first it wins over the value they just typed. The map is
 * unaffected because it reads lat/lng and the reverse-geocoded city/region, which
 * is exactly why the pin was right and only the text was wrong.
 *
 * Measured by running the real render function against the real DB, user 190:
 *   location_text    '5425 Warner Rd. #4 Valley View, Ohio 44125'   (what he typed)
 *   location_address '4706 Pershing Ave, Parma, OH 44134, USA'      (what printed)
 * Same split on live. Four live members were printing a wrong address under OFF:
 * ids 190, 590, 598, 1323. Twelve more rows have diverged columns but sit at
 * City/State precision, where this column is never read.
 *
 * ⚠️ THIS IS NOT A PRIVACY DIAL AND MUST NOT BECOME ONE. It only chooses between
 * two columns AT STREET PRECISION — the precision the member themselves selected.
 * The City and State branches are untouched in both states: they keep printing the
 * STRUCTURED labels and never reach for the typed line. Gate 73 asserts that in
 * all three flag states, because "print what the member typed" is one careless
 * edit away from leaking a street address to a City-precision audience.
 */

return array(

	/**
	 * OFF: today's behaviour, byte for byte. locationDisplay() prefers
	 *      `location_address` at street precision, and the save path does not
	 *      write that column at all — so a box that has not flipped this stores
	 *      and renders precisely what it stored and rendered before.
	 * ON:  the member's own typed address wins. locationDisplay() prefers
	 *      `location_text` at street precision, AND the save path writes
	 *      `location_address` in step whenever it writes `location_text`, so the
	 *      column stops being a fossil for the OTHER readers that trust it
	 *      (Profile.php's exact tier, api/v0/directory-members.php).
	 *
	 * The write half is gated too, deliberately. If ON wrote the column and OFF
	 * only changed the read, flipping back would leave members who saved during ON
	 * carrying data the OFF path never intended — an off-switch that cannot fully
	 * un-ring its own bell. Gated both halves, OFF is a true no-op on disk as well
	 * as on screen.
	 *
	 * A pleasant consequence of the write half: once a member saves under ON the
	 * two columns AGREE, so that member renders correctly even if this is later
	 * switched OFF. The fix is self-healing per member, not a permanent crutch.
	 *
	 * ⚠️ FOR WHOEVER LANDS THE `directory-location` BRANCH: its new
	 * listLocationText() prints `place['address']` at street level — this same
	 * column. Under OFF that is the frozen import value, so those four members
	 * would get their old HOME address printed into directory rows and map-pin
	 * popups. Flagged to Ian 2026-08-18; not changed here, because this lane is
	 * bugs-only and that branch is not merged.
	 */
	'prefer_typed_address' => true,

);
