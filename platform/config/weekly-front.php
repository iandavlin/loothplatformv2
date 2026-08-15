<?php
/**
 * weekly-front — THE TRACKED CONFIG for backlog item 8 (Ian 2026-07-30:
 * "surface the most-recent weekly email on the FRONT PAGE for logged-out
 * visitors"; ruled 2026-08-15 after the mock — "build it and let me see it on
 * dev2", Option A, this placement, member-only cards shown with their padlock).
 *
 * ── WHAT IT SWITCHES ─────────────────────────────────────────────────────────
 *   OFF  the front page emits nothing new — no block, no markup, no fetch, and
 *        the WordPress feed endpoint answers 404 as though it did not exist.
 *   ON   a LOGGED-OUT visitor gets a "This week's issue" block directly under
 *        the welcome video and above the featured-member band, built from the
 *        latest SENT issue's own stored sections.
 *
 * ── WHY OFF IS BYTE-IDENTICAL, AND WHERE IT IS ASSERTED ──────────────────────
 * There is exactly ONE read site on each side, and both are early returns:
 *
 *   archive-poc/web/index.php   `lg_weekly_front_enabled()` gates the whole
 *                               block — no loopback fetch is made, no cache
 *                               file is touched, and no markup is emitted. A
 *                               logged-in viewer never reaches it either, so
 *                               the member front page is untouched in BOTH
 *                               states.
 *   LG_WD_Front_Feed::serve()   returns 404 before reading an issue, so OFF
 *                               adds no public surface at all — not an empty
 *                               one, none.
 *
 * Gate 54 asserts all three states off the SERVED page (absent / OFF / ON)
 * rather than assuming today's default, so flipping this file needs no gate
 * edit. Asserting only the ON state would have been a gate that reddens the
 * moment the flag ships OFF, which is how this rule keeps getting downgraded.
 *
 * ── OVERRIDES ARE FOR LANE PREVIEWS AND GATES ────────────────────────────────
 * getenv() for a CLI harness or a gate run, $_SERVER for a single nginx
 * location — a fastcgi_param lands in $_SERVER but NOT reliably in the
 * environment, so reading only one serves the OFF path on the very preview URL
 * built for Ian to click. Both are read, on both sides.
 *
 * A tracked PHP file rather than an FPM `env[]`, deliberately: WP cron carries
 * no environment at all, so an env-only flag arms nothing there — and this
 * plugin is cron-driven elsewhere. __DIR__-relative reads work from both the
 * serving checkout and a lane worktree.
 */

return array(

	/**
	 * OFF in the repo. Ian's ruling was "build it and let me see it on dev2",
	 * so it is flipped ON *on dev2* for his look — that flip is a dev2-only
	 * change, and live gets it only when he says so after seeing the real page.
	 * The repo default staying false is what lets this merge without changing
	 * anything for a member.
	 */
	'enabled' => false,
);
