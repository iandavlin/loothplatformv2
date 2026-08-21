<?php
/**
 * comp-expiry — THE TRACKED CONFIG for re-arming the looth4 comp timer (#183).
 *
 * ── WHAT IT SWITCHES ─────────────────────────────────────────────────────────
 * Ian, 2026-08-21: *"comp timers need to work."* They have not, for at least
 * 41 days, and the reason is not a bug — it is an absence. The plugin that
 * enforced them, `lg-looth4-expiry 1.0.0`, belonged to the PRE-CUTOVER platform
 * and did not survive the cut: it is not in live's plugin dir, not in
 * mu-plugins, not in `active_plugins`, `recently_activated` is `a:0:{}`, and
 * the 13,182-byte `cron` option names no looth4 or expiry event. Measured both
 * sides on 2026-08-21 — keeper on the filesystem, this lane on the database.
 *
 *   OFF  nothing runs. No query, no option write, no log line, and
 *        `Arbiter::sync`'s looth4 early-return behaves EXACTLY as it does
 *        today: every comp holder is protected, expired or not.
 *   ON   the tick sweeps comp holders whose timer has run out, and the Arbiter
 *        lets those members fall through to normal arbitration.
 *
 * ⚠️ THIS IS THE ONE FLAG ON THIS RAIL THAT CAN TAKE ACCESS AWAY FROM A REAL
 * PERSON. Fourteen members hold looth4 on live and staff are among them. It
 * merges OFF for that reason, and both fences below fail CLOSED.
 *
 * ── WHY A TRACKED FILE AND NOT AN ENV VAR ────────────────────────────────────
 * The sweep runs inside the 5-minute WP-Cron tick, and lg-wp-cron.service
 * carries no `Environment=` at all, so an FPM pool variable would arm a flag
 * that then no-ops forever in the one context that matters most. Read through
 * `__DIR__`, which resolves through the mu-plugin symlink into the serving
 * checkout — the same pattern `welcome-activation.php` already proves.
 *
 * ── THE TIMEZONE IS UTC, AND IT IS A MEASUREMENT, NOT A PREFERENCE ───────────
 * `looth4_expires_at` holds a bare `Y-m-d H:i:s` with no offset, so somebody
 * has to decide what zone it is in, and the wrong answer expires people hours
 * early or late. It is UTC, on two independent proofs:
 *
 *   1. The old plugin's own source, captured at cutover
 *      (cutover/batch-output/BATCH-04-results.md:158):
 *          define( 'LG_L4E_META_EXPIRES', 'looth4_expires_at' );
 *                                      // stored as Y-m-d H:i:s UTC
 *   2. The data agrees. `wp_users.user_registered` is UTC. User 1829 registered
 *      2026-04-21 21:11:27 and their expiry reads 2026-07-28 21:11:00 — the
 *      SAME minute-of-day. User 1865: registered 15:26:04, expiry 15:25:00.
 *      Two for two. Both boxes run timezone_string = America/New_York, so a
 *      locally-computed expiry would have read 17:11 and 11:26.
 *
 * Consequence worth stating plainly: `CompStanding` read these in the SITE's
 * zone until #183, which made it **four hours late on every comp**. Harmless
 * while nothing enforced; a real defect the moment something demotes.
 *
 * ── WHAT AN EXPIRED COMP BECOMES ─────────────────────────────────────────────
 * Whatever their payment sources already say — NOT a flat looth1. The comp role
 * comes off and `Arbiter::sync` re-arbitrates over `lg_role_sources` exactly as
 * it does for anyone else: a comp who also pays on Patreon lands on looth3, a
 * Stripe member on their own tier, and a member with no paying source at all
 * lands on **looth1**, the starter tier — never on no tier at all. That is the
 * difference between an expiry and a punishment.
 *
 * ⚠️ THE ARBITER REMAINS THE ONLY WRITER OF wp_capabilities. Lane 181 proved
 * it and gate 86 §I asserts it. The sweep NEVER calls add_role/remove_role
 * itself; it decides who has lapsed and hands each member to the Arbiter. This
 * is also why the old plugin is not being resurrected even if it were
 * recovered: it wrote roles directly, and a second writer is how two systems
 * end up disagreeing about one member's tier. The Arbiter's own looth4 comment
 * records the bill for that already — the old comp timer "stripped looth4 and
 * left looth1 behind (and a later Patreon sub then added looth3 on top) — the
 * root of the double-role bug".
 */

return array(

	/**
	 * OFF until Ian has seen the Comp Timers tab on the dev2 serve and said go.
	 * This one takes access away from real people, so it merges off and the
	 * merge itself moves nobody.
	 */
	'enabled' => false,

	/**
	 * THE FENCE THAT PROTECTS THE ALREADY-OVERDUE. Only a timer that runs out
	 * AT OR AFTER this date (UTC, Y-m-d) is ever enforced. Anything earlier is
	 * detected, logged and shown in the dash — and never acted on.
	 *
	 * Ian, 2026-08-21, on the two accounts whose timers are already past —
	 * 1829 sethleejones (2026-07-28) and 1865 Yuexin Chen (2026-07-11): LEFT
	 * ALONE. No demotion, no extension. They keep access until he decides case
	 * by case; he is mid-launch and these are real people, one possibly staff.
	 *
	 * ⚠️ A DATE, DELIBERATELY, RATHER THAN A LIST OF USER IDs. A skip-list is
	 * defeated by one mistyped id and protects only the accounts somebody
	 * remembered to enumerate. This fence protects by the property that
	 * actually matters — "your timer ran out before we turned enforcement on" —
	 * so it holds EVERY already-overdue account on EVERY box, including any
	 * nobody has measured. It is the same shape as the Arbiter's own
	 * `registeredAfterCutover` fence, for the same reason.
	 *
	 * EMPTY OR UNPARSEABLE FAILS CLOSED: nothing is ever enforced. That makes
	 * `enabled => true` with an empty cutover a genuine detect-and-report mode
	 * — the sweep runs, journals and logs every lapsed comp, and demotes
	 * nobody — with no third knob to get wrong. Arm it that way first.
	 */
	'effective_from' => '',
);
