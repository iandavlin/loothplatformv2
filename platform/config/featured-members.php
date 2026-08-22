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
 * ── OVERRIDES: THREE LAYERS, AND THE MIDDLE ONE IS NEW ───────────────────────
 * Resolution order, in every reader (index.php, u.php, me-featured.php):
 *
 *   1. this tracked file                         — what live gets on a pull
 *   2. featured-members.local.php beside it      — gitignored, PER BOX
 *   3. getenv() and $_SERVER                     — a gate or one nginx location
 *
 * Layer 2 is how dev2 keeps this ON while the tracked default stays false, so
 * a live pull cannot re-arm the feature by surprise: live is protected by the
 * file being ABSENT, not by a check in the code. Same shape as back-pill.php,
 * weekly-front.php and front-signup-banner-retire.php.
 *
 * ⚠️ LAYER 2 DID NOT EXIST UNTIL 2026-08-22 (#200), ON ANY OF THE THREE
 * READERS. That is not a tidy-up: keeper handed Ian a live stopgap that
 * consisted of placing this exact file, and it would have done nothing at all.
 * A flag whose documented override is not wired is worse than a flag with no
 * override, because the register says it has one. Gate 94 §D now executes all
 * three readers against the same inputs and fails if they disagree.
 *
 * Layer 3 reads BOTH sources because a fastcgi_param lands in $_SERVER but not
 * reliably in the environment (trap: fastcgi_param is NOT in getenv()), so
 * reading only one would serve the OFF path on the very preview URL built for
 * Ian to click. It sits LAST so a gate forcing a state still wins over the box.
 *
 * ⚠️ `php -l` THE .local.php BEFORE PLACING IT. A parse error in an @include
 * yields false, the reader falls back to the tracked value, and the box serves
 * the opposite of what the file says — silently.
 */

return array(

	/**
	 * BACK TO FALSE, 2026-08-22 (#200) — AND THIS IS THE WHOLE REASON THE
	 * FLAG-LAW SAYS MEMBER-FACING DEFAULTS ARE OFF.
	 *
	 * The 8/15 flip to `true` below was made for Ian's look on dev2. It was
	 * never a decision about live — but a tracked default is not box-local, and
	 * a routine `lg-deploy` on 2026-08-21 therefore switched this feature ON in
	 * production, where the featured band promptly rendered NOTHING at all.
	 * Ian, the next morning, verbatim: "The changes made to featured member has
	 * removed members from the front page."
	 *
	 * MEASURED on live the same hour, because "no member passes the criteria"
	 * turned out to be only half of it — there were two causes, either one
	 * sufficient on its own:
	 *
	 *   1. tools/cut/featured-member-grants.sql had never been APPLIED to live.
	 *      `has_column_privilege('archive-poc','users','featured_opt_in_at')`
	 *      was false (and profile_sections likewise), so the resolver's SELECT
	 *      raised "permission denied for table users", the caller's try/catch
	 *      swallowed it, and the band vanished for every visitor and for every
	 *      pick, however perfect. This file's own sibling — featured-consent.php
	 *      — warns about exactly that in capitals. The warning was right and the
	 *      grant was simply never run.
	 *   2. Even with the grant, live's selected member resolves to role '' (a
	 *      members-only header, an empty one-liner, and a business_name that is
	 *      a tail of their display name), so the card-ready guard returns null.
	 *      Only 2 of live's 6 opted-in members render at all.
	 *
	 * So the default goes back where the flag-law says it belongs, and live is
	 * protected by the tracked value rather than by anyone remembering. dev2
	 * keeps its ON through the gitignored .local.php beside this file — a layer
	 * that, until #200, DID NOT EXIST on any of this flag's three readers,
	 * which is why the stopgap handed to Ian ("place a .local.php on live")
	 * could never have worked. It exists now; see the OVERRIDES note above.
	 *
	 * ⚠️ Flipping this back to true is a LIVE DEPLOY DECISION, not a look-at-it
	 * decision, and it needs BOTH causes closed first: the grant applied, and
	 * a pick that actually resolves.
	 */
	'enabled' => false,
);
