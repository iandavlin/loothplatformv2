<?php
/**
 * featured-consent — THE TICK IS CONSENT, SAID OUT LOUD (#107, Ian 2026-08-20,
 * decision box on the issue, verbatim):
 *
 *   "the tick is consent — a member ticking 'include me as a possible featured
 *    member' consents to their one-line 'what you do' appearing on the public
 *    featured card."
 *
 * This is a SECOND flag, deliberately not a key inside featured-members.php:
 * that one is already ON (dev2, since 8/15) and switches whether the feature
 * exists at all. This one switches only the consent rule below, and must be
 * able to be OFF while the feature itself is on.
 *
 * ── WHAT IT SWITCHES ─────────────────────────────────────────────────────────
 *   OFF  exactly today's behaviour, byte for byte. The front-page card uses a
 *        member's one-line `at_a_glance` ONLY when their profile header block
 *        is public; otherwise it falls back to business_name and, failing that,
 *        draws no band at all. The profile tickbox says nothing about the
 *        one-liner, because under OFF the one-liner is not republished.
 *   ON   the featured card — and ONLY the featured card — may print the
 *        one-liner of an OPTED-IN member whose header is members-only, and the
 *        tickbox copy says so before they tick. Every other surface keeps the
 *        8/16 never-republish rule untouched.
 *
 * ── WHY THIS NEEDED A FLAG AT ALL ────────────────────────────────────────────
 * The platform default header visibility is 'members' (Block::HEADER_DEFAULT),
 * and 1,917 of 1,933 public members have never set a header row. So this rule
 * is not an edge case: it decides what happens to essentially every member who
 * ever ticks the box. Member-facing ⇒ flag, defaulted OFF, OFF byte-identical
 * and gated (gate 39 §G asserts all three states: absent / OFF / ON).
 *
 * ── informed_copy_since — THE CUTOVER, AND WHY IT IS NOT OPTIONAL ────────────
 * Consent that does not say what it covers is not consent. Eight members ticked
 * under the OLD copy, which never mentioned the one-liner; four of them are the
 * very members this rule exists to unblock. Their consent may not be silently
 * upgraded (#107 follow-up plan, item 3).
 *
 * So a tick counts as INFORMED only if it was made at or after this moment —
 * the moment the new copy actually reached members, i.e. the moment `enabled`
 * was flipped true on that box. `featured_opt_in_at` is stamped on every real
 * false->true transition (me-featured.php), and unticking nulls it, so a member
 * "re-confirming" under the new copy re-stamps it and becomes informed with no
 * extra plumbing.
 *
 * ⚠️ SET IT IN THE SAME EDIT AS `enabled => true`. An ON with this still null
 * would mean "nobody is informed", which reads as a working flag that quietly
 * does nothing for every member — the failure class that is hardest to see.
 * Gate 39 §G1 goes RED on exactly that pairing, so it cannot ship unnoticed.
 *
 * ── OVERRIDES ARE FOR LANE PREVIEWS ──────────────────────────────────────────
 * getenv() for a CLI harness/gate, $_SERVER for a single nginx location — a
 * fastcgi_param lands in $_SERVER but not reliably in the environment (trap:
 * fastcgi_param is NOT in getenv()), so reading only one would serve the OFF
 * path on the very preview URL built for Ian to click. Same two-source shape
 * as featured-members.php's LG_FEATURED_MEMBERS.
 *
 * ⚠️ DEPLOY COUPLING — A GRANT MUST LAND BEFORE THIS FLAG GOES ON.
 * The front-page resolver runs as the Postgres role "archive-poc" against the
 * separate profile_app DB under COLUMN-SCOPED grants, and it must now read
 * `users.featured_opt_in_at` to tell informed consent from old. That column was
 * not granted — measured on dev2 2026-08-20, `SELECT featured_opt_in_at` as
 * that role returns "permission denied for table users" while the rest of the
 * card's columns read fine. The resolver's own try/catch would turn that into
 * "no band" for EVERY visitor, with nothing in the UI to say why.
 *
 *   sudo -u postgres psql profile_app -f tools/cut/featured-member-grants.sql
 *
 * Re-apply after any profile_app restore (grants do not survive one). Gate 39
 * §G2 asserts the role can actually read the column, so a missing grant is a
 * RED rather than a silently blank front page.
 */

return array(

	/**
	 * OFF until Ian has read the new tickbox wording on the dev2 serve and
	 * said yes to it. This one is not merely a look-at-it flag: flipping it
	 * changes what a member's tick MEANS, so the copy has to be approved
	 * before it can be true anywhere.
	 */
	'enabled' => false,

	/**
	 * ISO-8601, box-local. Null until the flip. See the warning above — set
	 * this to the flip moment in the SAME commit that sets enabled => true.
	 */
	'informed_copy_since' => null,
);
